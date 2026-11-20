<?php
/** Shared logger for CrewPulse / AlarmPulse signup and login history. */

function app_activity_ensure(PDO $pdo): void {
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_activity (
        id INT AUTO_INCREMENT PRIMARY KEY,
        app VARCHAR(32) NOT NULL,
        event VARCHAR(16) NOT NULL,
        user_key VARCHAR(64) NULL,
        name VARCHAR(150) NULL,
        email VARCHAR(150) NULL,
        source VARCHAR(40) NULL,
        occurred_at DATETIME NOT NULL,
        INDEX idx_occurred (occurred_at),
        INDEX idx_app_event (app, event)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ready = true;
}

function app_activity_log(PDO $pdo, string $app, string $event, $userId, $name, $email, string $source = 'app'): void {
    try {
        app_activity_ensure($pdo);
        $stmt = $pdo->prepare("INSERT INTO app_activity (app, event, user_key, name, email, source, occurred_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $app,
            $event,
            $userId === null || $userId === '' ? null : (string)$userId,
            $name ? (string)$name : null,
            $email ? (string)$email : null,
            $source,
        ]);
    } catch (Throwable $e) {
        // Never block sign-in if history write fails.
    }
}

function app_activity_backfill(PDO $pdo): void {
    app_activity_ensure($pdo);
    $done = $pdo->query("SELECT COUNT(*) FROM app_activity WHERE app='_meta' AND event='backfill'")->fetchColumn();
    if ((int)$done > 0) {
        return;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO app_activity (app, event, user_key, name, email, source, occurred_at)
            SELECT 'alarmpulse', 'signup', CAST(user_id AS CHAR), name, email, 'backfill', created_at
            FROM alarm_users
            WHERE created_at IS NOT NULL");
        $stmt->execute();
    } catch (Throwable $e) {
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO app_activity (app, event, user_key, name, email, source, occurred_at)
            SELECT 'crewpulse', 'login', CAST(a.user_id AS CHAR), u.Name, u.email, 'clock-in', MIN(a.clock_in)
            FROM attendance a
            LEFT JOIN users u ON u.id = a.user_id
            WHERE a.clock_in IS NOT NULL AND a.clock_in <= NOW()
            GROUP BY a.user_id, DATE(a.clock_in)");
        $stmt->execute();
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("INSERT INTO app_activity (app, event, user_key, name, email, source, occurred_at)
            VALUES ('_meta', 'backfill', NULL, NULL, NULL, 'system', NOW())");
    } catch (Throwable $e) {
    }
}

function app_activity_backfill_signups(PDO $pdo): void {
    app_activity_ensure($pdo);
    $done = $pdo->query("SELECT COUNT(*) FROM app_activity WHERE app='_meta' AND event='backfill_signups'")->fetchColumn();
    if ((int)$done > 0) {
        return;
    }
    try {
        $pdo->exec("INSERT INTO app_activity (app, event, user_key, name, email, source, occurred_at)
            SELECT 'crewpulse', 'signup', CAST(id AS CHAR), Name, email, 'start-date', CONCAT(StartDate, ' 00:00:00')
            FROM users
            WHERE StartDate IS NOT NULL AND StartDate <> '0000-00-00' AND StartDate <> ''");
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec("INSERT INTO app_activity (app, event, user_key, name, email, source, occurred_at)
            SELECT 'crewpulse', 'signup', CAST(u.id AS CHAR), u.Name, u.email, 'first-clock-in', MIN(a.clock_in)
            FROM users u
            JOIN attendance a ON a.user_id = u.id AND a.clock_in IS NOT NULL AND a.clock_in <= NOW()
            WHERE u.StartDate IS NULL OR u.StartDate = '0000-00-00' OR u.StartDate = ''
            GROUP BY u.id");
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec("INSERT INTO app_activity (app, event, user_key, name, email, source, occurred_at)
            VALUES ('_meta', 'backfill_signups', NULL, NULL, NULL, 'system', NOW())");
    } catch (Throwable $e) {
    }
}

function app_activity_in_view(string $when, string $view): bool {
    $whenLocal = str_replace('T', ' ', substr($when, 0, 19));
    if ($whenLocal === '' || $whenLocal > date('Y-m-d H:i:s')) {
        return false;
    }
    $cutoff = (new DateTime('-30 days'))->format('Y-m-d H:i:s');
    $isOld = ($whenLocal < $cutoff);
    return ($view === 'archive') === $isOld;
}

function app_activity_merge_sqlite(array &$rows, string $path, string $app, string $view, string $appFilter, string $eventFilter, string $source = 'website'): void {
    if ($appFilter !== 'all' && $appFilter !== $app) {
        return;
    }
    if (!is_readable($path)) {
        return;
    }
    try {
        $sq = new PDO('sqlite:' . $path);
        $sq->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ($eventFilter === 'all' || $eventFilter === 'signup') {
            $q = $sq->query("SELECT id, name, email, created_at FROM users WHERE created_at IS NOT NULL");
            foreach ($q as $u) {
                $when = (string)$u['created_at'];
                if (!app_activity_in_view($when, $view)) {
                    continue;
                }
                $rows[] = [
                    'app' => $app,
                    'event' => 'signup',
                    'user_key' => (string)$u['id'],
                    'name' => $u['name'],
                    'email' => $u['email'],
                    'source' => $source,
                    'occurred_at' => str_replace('T', ' ', substr($when, 0, 19)),
                ];
            }
        }
        if ($eventFilter === 'all' || $eventFilter === 'login') {
            try {
                $q = $sq->query("SELECT l.id, l.occurred_at, u.name, u.email, u.id AS user_id
                    FROM logins l JOIN users u ON u.id = l.user_id");
                foreach ($q as $u) {
                    $when = (string)$u['occurred_at'];
                    if (!app_activity_in_view($when, $view)) {
                        continue;
                    }
                    $rows[] = [
                        'app' => $app,
                        'event' => 'login',
                        'user_key' => (string)$u['user_id'],
                        'name' => $u['name'],
                        'email' => $u['email'],
                        'source' => $source,
                        'occurred_at' => str_replace('T', ' ', substr($when, 0, 19)),
                    ];
                }
            } catch (Throwable $e) {
            }
        }
    } catch (Throwable $e) {
    }
}
