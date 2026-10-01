<?php
declare(strict_types=1);

function admin_data_dir(): string
{
    return dirname(__DIR__) . '/data';
}

function admin_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dir = admin_data_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }

    $pdo = new PDO('sqlite:' . $dir . '/admin.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    admin_migrate($pdo);
    return $pdo;
}

function admin_migrate(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT "admin",
            created_at TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS apps (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            url TEXT NOT NULL,
            status TEXT NOT NULL,
            notes TEXT NOT NULL DEFAULT ""
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            app_name TEXT NOT NULL,
            title TEXT NOT NULL,
            track TEXT NOT NULL,
            detail TEXT NOT NULL DEFAULT "",
            recorded_on TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS prices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            service TEXT NOT NULL,
            channel TEXT NOT NULL,
            amount TEXT NOT NULL,
            unit TEXT NOT NULL,
            notes TEXT NOT NULL DEFAULT ""
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS vault (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            label TEXT NOT NULL,
            username TEXT NOT NULL DEFAULT "",
            secret TEXT NOT NULL DEFAULT "",
            url TEXT NOT NULL DEFAULT "",
            notes TEXT NOT NULL DEFAULT "",
            created_at TEXT NOT NULL
        )'
    );

    $count = (int) $pdo->query('SELECT COUNT(*) FROM apps')->fetchColumn();
    if ($count === 0) {
        $seed = $pdo->prepare('INSERT INTO apps (name, url, status, notes) VALUES (?, ?, ?, ?)');
        $seed->execute(['Renocon website', 'https://renocon.ca', 'Live', 'Public marketing site']);
        $seed->execute(['Renocon payroll', 'https://payroll.renocon.ca', 'Live', 'Staff payroll and kiosk login']);
        $seed->execute(['Penticton operations', 'HostGator MySQL fpilot_penticton', 'Legacy DB', 'Inventory, partners, Password0']);
        $seed->execute(['Ecommi admin', 'https://ecommi.com', 'In progress', 'Internal administration hub']);
    }

    $prices = (int) $pdo->query('SELECT COUNT(*) FROM prices')->fetchColumn();
    if ($prices === 0) {
        $seed = $pdo->prepare('INSERT INTO prices (service, channel, amount, unit, notes) VALUES (?, ?, ?, ?, ?)');
        $seed->execute(['Restaurant construction package', 'Direct', 'Quote', 'project', 'Turnkey or cost-plus']);
        $seed->execute(['Project management', 'Direct', 'Quote', 'month', 'Utilities and equipment scheduling']);
        $seed->execute(['Marketing landing page', 'Portal', 'TBD', 'campaign', 'Replace with live portal rates']);
        $seed->execute(['Payroll seat', 'Internal', 'TBD', 'staff / month', 'Replace with your payroll rate']);
    }
}

function vault_key_path(): string
{
    return admin_data_dir() . '/vault.key';
}

function vault_key(): string
{
    $path = vault_key_path();
    if (!is_file($path)) {
        $key = random_bytes(32);
        file_put_contents($path, base64_encode($key));
        chmod($path, 0600);
        return $key;
    }
    return base64_decode((string) file_get_contents($path)) ?: random_bytes(32);
}

function vault_encrypt(string $plain): string
{
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'aes-256-cbc', vault_key(), OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $cipher);
}

function vault_decrypt(string $payload): string
{
    $raw = base64_decode($payload, true);
    if ($raw === false || strlen($raw) < 17) {
        return '';
    }
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $plain = openssl_decrypt($cipher, 'aes-256-cbc', vault_key(), OPENSSL_RAW_DATA, $iv);
    return $plain === false ? '' : $plain;
}
