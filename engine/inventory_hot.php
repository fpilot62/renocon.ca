<?php
/**
 * Path: /engine/inventory_hot.php
 * Original master SKUs (ids 1–12) are hot items. Red pepper on Orders / Master Items.
 */

function inv_ensure_hot_column(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $chk = $pdo->query("SHOW COLUMNS FROM Gem_inventory_items LIKE 'is_hot'");
    if ($chk && $chk->fetch()) {
        return;
    }
    $pdo->exec("ALTER TABLE Gem_inventory_items ADD COLUMN is_hot TINYINT(1) NOT NULL DEFAULT 0");
    $pdo->exec("UPDATE Gem_inventory_items SET is_hot = 1 WHERE Id BETWEEN 1 AND 12");
}

function inv_pepper_html($hot): string {
    if (!$hot) {
        return '';
    }
    return '<span class="inv-hot" title="Hot item — original master list">&#127798;&#65039;</span> ';
}
