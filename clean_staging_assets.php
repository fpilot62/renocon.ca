<?php
/**
 * Path: /clean_staging_assets.php
 * PURPOSE: Automatically moves all root and subfolder "2.php" staging files 
 *          into your new nested folder structure.
 */
header('Content-Type: text/plain');

// Targets your 3-level folder structure as seen in image_926dab.png
$target_vault_dir = __DIR__ . '/_staging_sandbox_backups/_staging_sandbox_backups/_staging_sandbox_backups';

if (!is_dir($target_vault_dir)) {
    // Fallback safely to the top available container if deep structure paths vary during a live run
    $target_vault_dir = __DIR__ . '/_staging_sandbox_backups';
}

$directory = new RecursiveDirectoryIterator(__DIR__);
$iterator = new RecursiveIteratorIterator($directory);
$moved_count = 0;

echo "🧹 Executing Multi-Level Nested Archival Suite...\n\n";

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $filename = $file->getFilename();
        $filepath = $file->getRealPath();
        
        // Target only files ending in 2.php
        if (str_ends_with($filename, '2.php')) {
            $dir_path = dirname($filepath);
            
            // Skip files that are already inside your backup folders
            if (str_contains($filepath, '_staging_sandbox_backups')) {
                continue;
            }
            
            // Determine relative positioning to match the folder hierarchy
            $relative_folder = ltrim(str_replace(__DIR__, '', $dir_path), '/\\');
            $final_destination_dir = $target_vault_dir;
            
            if (!empty($relative_folder)) {
                $final_destination_dir .= '/' . $relative_folder;
            }
            
            // Build sub-directories if they are missing
            if (!is_dir($final_destination_dir)) {
                mkdir($final_destination_dir, 0755, true);
            }
            
            $destination_filepath = $final_destination_dir . '/' . $filename;
            
            // Safely transfer the staging copies into the backup vault
            if (rename($filepath, $destination_filepath)) {
                echo "📦 Transferred: " . ($relative_folder ? $relative_folder . '/' : '') . "$filename\n";
                $moved_count++;
            }
        }
    }
}

echo "\n🎯 Execution complete. $moved_count staging sandboxes neatly filed away.\n";