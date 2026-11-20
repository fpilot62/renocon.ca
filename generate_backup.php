<?php
/**
 * Path: /generate_backup.php
 * PURPOSE: Standalone Live Script Directory Archiver & Database Table Exporter
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db.php'; // Uses your active database credentials

$backup_dir = __DIR__ . '/server_backups';
if (!file_exists($backup_dir)) { mkdir($backup_dir, 0755, true); }

$date_suffix = date('Y-m-d_H-i-s');
$db_backup_file = $backup_dir . "/database_dump_{$date_suffix}.sql";
$zip_backup_file = $backup_dir . "/full_system_backup_{$date_suffix}.zip";

echo "<h3>Starting Enterprise System Backup Engine...</h3>";

// =========================================================================
// 🗄️ SECTION 1: DATABASE TABLES EXPORT RUN
// =========================================================================
try {
    $sql_dump = "-- RENOCON SYSTEMS COMPLETE DATABASE BACKUP\n";
    $sql_dump .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
    
    // Fetch all operational database tables dynamically
    $tables_query = $pdo->query("SHOW TABLES");
    $tables = $tables_query->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($tables as $table) {
        $sql_dump .= "DROP TABLE IF EXISTS `$table`;\n";
        
        // Capture Schema Structural Architecture Rules
        $create_query = $pdo->query("SHOW CREATE TABLE `$table`");
        $create_row = $create_query->fetch(PDO::FETCH_ASSOC);
        $sql_dump .= $create_row['Create Table'] . ";\n\n";
        
        // Extract raw data values
        $data_query = $pdo->query("SELECT * FROM `$table`");
        while ($row = $data_query->fetch(PDO::FETCH_ASSOC)) {
            $keys = array_map(function($k) { return "`$k`"; }, array_keys($row));
            $values = array_map(function($v) use ($pdo) { 
                return ($v === null) ? "NULL" : $pdo->quote($v); 
            }, array_values($row));
            
            $sql_dump .= "INSERT INTO `$table` (" . implode(', ', $keys) . ") VALUES (" . implode(', ', $values) . ");\n";
        }
        $sql_dump .= "\n\n";
    }
    
    file_put_contents($db_backup_file, $sql_dump);
    echo "<p class='text-success'>✅ Database tables dumped successfully! ($db_backup_file)</p>";
} catch (Exception $e) {
    die("Database Dump Failure Exception: " . $e->getMessage());
}

// =========================================================================
// 📦 SECTION 2: ACTIVE SCRIPTS DIRECTORY ARCHIVER (ZIP ENGINE)
// =========================================================================
if (class_exists('ZipArchive')) {
    $zip = new ZipArchive();
    if ($zip->open($zip_backup_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen(__DIR__) + 1);

                // Prevent the script from backing up the backups folder recursively
                if (strpos($relativePath, 'server_backups/') === 0 || $relativePath === basename(__FILE__)) {
                    continue;
                }

                $zip->addFile($filePath, $relativePath);
            }
        }
        $zip->close();
        echo "<p style='color:green;'>✅ Active operational PHP script layouts compressed successfully! ($zip_backup_file)</p>";
        echo "<h4>🔥 Backup Process Complete. You can find your download links inside your server files root path /server_backups/ folder!</h4>";
    } else {
        echo "<p style='color:red;'>❌ Failed to initialize Zip archive matrix compilation engine.</p>";
    }
} else {
    echo "<p style='color:red;'>❌ ZipArchive class extension missing on this host environment runtime layout.</p>";
}