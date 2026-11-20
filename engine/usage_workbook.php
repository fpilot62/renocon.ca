<?php
/**
 * Path: /engine/usage_workbook.php
 * Official Usage vs Play copy, plus Production / Stock / Combined views.
 */

function uw_ensure_draft_table(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS Gem_inventory_usage_draft (
            id INT NOT NULL AUTO_INCREMENT,
            org_id INT NOT NULL,
            site_id INT NOT NULL,
            item_id INT NOT NULL,
            sold_qty DECIMAL(10,2) DEFAULT 0.00,
            waste_qty DECIMAL(10,2) DEFAULT 0.00,
            actual_qty_pcs DECIMAL(10,2) DEFAULT 0.00,
            usage_date DATE DEFAULT NULL,
            cloned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY site_date (site_id, usage_date),
            KEY item_id (item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3"
    );
}

function uw_copy_ok(string $copy): string {
    return $copy === 'play' ? 'play' : 'original';
}

function uw_view_ok(string $view): string {
    return in_array($view, ['production', 'stock', 'combined'], true) ? $view : 'production';
}

function uw_table(string $copy): string {
    return $copy === 'play' ? 'Gem_inventory_usage_draft' : 'Gem_inventory_usage';
}

function uw_has_play(PDO $pdo, int $rid, string $date): bool {
    uw_ensure_draft_table($pdo);
    $st = $pdo->prepare('SELECT 1 FROM Gem_inventory_usage_draft WHERE site_id = ? AND usage_date = ? LIMIT 1');
    $st->execute([$rid, $date]);
    return (bool)$st->fetchColumn();
}

function uw_load_qty(PDO $pdo, int $rid, string $date, string $copy): array {
    uw_ensure_draft_table($pdo);
    $table = uw_table($copy);
    $st = $pdo->prepare(
        "SELECT item_id, SUM(sold_qty) AS sold, SUM(waste_qty) AS waste
         FROM {$table}
         WHERE site_id = ? AND usage_date = ?
         GROUP BY item_id"
    );
    $st->execute([$rid, $date]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['item_id']] = $row;
    }
    return $out;
}

function uw_item_id_by_name(PDO $pdo, string $name): ?int {
    static $cache = [];
    $key = strtolower($name);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $st = $pdo->prepare('SELECT Id FROM Gem_inventory_items WHERE name = ? LIMIT 1');
    $st->execute([$name]);
    $id = $st->fetchColumn();
    $cache[$key] = $id !== false ? (int)$id : null;
    return $cache[$key];
}

function uw_combined_groups(PDO $pdo): array {
    return [
        'fillet' => [
            'label' => 'Chicken Fillet',
            'stock' => 'CHICKEN BRST FILLET BNLS SKLS',
            'unit' => 'Pieces',
            'ids' => array_values(array_filter([
                uw_item_id_by_name($pdo, 'NEW Chicken Fillet'),
                uw_item_id_by_name($pdo, 'Blackened Chicken Fillet'),
            ])),
        ],
        'boneless' => [
            'label' => 'Boneless Wings',
            'stock' => 'Boneless Wings',
            'unit' => 'Pieces',
            'ids' => array_values(array_filter([
                uw_item_id_by_name($pdo, 'Classic Boneless pc'),
                uw_item_id_by_name($pdo, 'Garlic Parm Boneless pc'),
                uw_item_id_by_name($pdo, 'Honey BBQ Boneless pc'),
                uw_item_id_by_name($pdo, 'Honey Garlic Boneless pc'),
                uw_item_id_by_name($pdo, 'Signature Hot Boneless pc'),
            ])),
        ],
        'wings' => [
            'label' => 'Split Wings',
            'stock' => 'SPLIT WINGS',
            'unit' => 'Pieces',
            'ids' => array_values(array_filter([
                uw_item_id_by_name($pdo, 'Wing pc'),
                uw_item_id_by_name($pdo, 'Garlic Parm Wing pc'),
                uw_item_id_by_name($pdo, 'Ghost Pepper Wing pc'),
                uw_item_id_by_name($pdo, 'Honey BBQ Wing pc'),
                uw_item_id_by_name($pdo, 'Honey Garlic Wing pc'),
                uw_item_id_by_name($pdo, 'Signature Hot Wing pc'),
            ])),
        ],
    ];
}

function uw_grouped_ids(array $groups): array {
    $ids = [];
    foreach ($groups as $g) {
        foreach ($g['ids'] as $id) {
            $ids[(int)$id] = true;
        }
    }
    return $ids;
}

function uw_clone_from_original(PDO $pdo, int $rid, string $date): void {
    uw_ensure_draft_table($pdo);
    require_once __DIR__ . '/production_usage_import.php';
    $org = pui_site_org($pdo, $rid);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM Gem_inventory_usage_draft WHERE site_id = ? AND usage_date = ?')->execute([$rid, $date]);
        $src = $pdo->prepare(
            'SELECT item_id, SUM(sold_qty) sold, SUM(waste_qty) waste
             FROM Gem_inventory_usage
             WHERE site_id = ? AND usage_date = ?
             GROUP BY item_id'
        );
        $src->execute([$rid, $date]);
        $ins = $pdo->prepare(
            'INSERT INTO Gem_inventory_usage_draft
                (org_id, site_id, item_id, sold_qty, waste_qty, actual_qty_pcs, usage_date)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($src->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sold = (float)$row['sold'];
            $waste = (float)$row['waste'];
            $ins->execute([$org, $rid, (int)$row['item_id'], $sold, $waste, $sold + $waste, $date]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function uw_copy_play_to_original(PDO $pdo, int $rid, string $date): int {
    uw_ensure_draft_table($pdo);
    require_once __DIR__ . '/production_usage_import.php';
    $org = pui_site_org($pdo, $rid);
    $play = uw_load_qty($pdo, $rid, $date, 'play');
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM Gem_inventory_usage WHERE site_id = ? AND usage_date = ?')->execute([$rid, $date]);
        $ins = $pdo->prepare(
            'INSERT INTO Gem_inventory_usage
                (org_id, site_id, item_id, sold_qty, waste_qty, actual_qty_pcs, usage_date)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $n = 0;
        foreach ($play as $itemId => $row) {
            $sold = (float)$row['sold'];
            $waste = (float)$row['waste'];
            if ($sold + $waste <= 0) {
                continue;
            }
            $ins->execute([$org, $rid, (int)$itemId, $sold, $waste, $sold + $waste, $date]);
            $n++;
        }
        $pdo->commit();
        return $n;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function uw_discard_play(PDO $pdo, int $rid, string $date): void {
    uw_ensure_draft_table($pdo);
    $pdo->prepare('DELETE FROM Gem_inventory_usage_draft WHERE site_id = ? AND usage_date = ?')->execute([$rid, $date]);
}

function uw_qs(int $rid, string $date, string $copy, string $view, array $extra = []): string {
    return 'usage.php?' . http_build_query(array_merge([
        'rid' => $rid,
        'usage_date' => $date,
        'copy' => $copy,
        'view' => $view,
    ], $extra));
}
