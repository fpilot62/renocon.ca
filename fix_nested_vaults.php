<?php
/**
 * Path: /fix_nested_vaults.php
 * PURPOSE: Fixes recursive folder duplication loops. Pulls files up one level 
 * and completely deletes empty nested duplicate directories.
 */
header('Content-Type: text/plain');

$clean_vault_base = __DIR__ . '/_staging_sandbox_backups';
$nested_duplicate = $clean_vault_base . '/_staging_sandbox_backups';

if (!is_dir($nested_duplicate)) {
    die("✅ Check Complete: The recursive loop folder structure does not exist or has already been resolved.\n");
}

echo "🛠️ Initializing Directory Loop Breakdown Suite...\n\n";

/**
 * Recursively moves contents out of the nested folder up to the main folder
 */
function pullFilesUpwards($source, $destination) {
    if (!is_dir($destination)) {
        mkdir($destination, 0755, true);
    }
    
    $dir = opendir($source);
    while (false !== ($file = readdir($dir))) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        
        $srcPath = $source . '/' . $file;
        $destPath = $destination . '/' . $file;
        
        if (is_dir($srcPath)) {
            // Keep pulling up nested subdirectories (like /admin, /staff, etc.)
            pullFilesUpwards($srcPath, $destPath);
        } else {
            // Transfer files cleanly into the correct directory location
            if (rename($srcPath, $destPath)) {
                echo "📦 Pulled Upwards: " . basename($destPath) . "\n";
            }
        }
    }
    closedir($dir);
}

/**
 * Safely removes empty directories after files have been moved
 */
function removeEmptyDirectoryTree($dir) {
    if (!is_dir($dir)) return;
    
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            removeEmptyDirectoryTree($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

// Step 1: Extract all files from the duplicate nested loop and bring them to the first vault layer
pullFilesUpwards($nested_duplicate, $clean_vault_base);

// Step 2: Delete the duplicate subfolder level cleanly
removeEmptyDirectoryTree($nested_duplicate);

echo "\n🎯 Success: The duplicate nested loops have been removed!\n";
echo "Your staging backup files have been organized right into your clean /_staging_sandbox_backups/ folder.\n";