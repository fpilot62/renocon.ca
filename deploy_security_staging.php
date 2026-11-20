<?php
/**
 * Path: /deploy_security_staging.php
 * PURPOSE: Mass-injects Multi-Tenant Security by creating a "2.php" duplicate of every file.
 * SAFETY: Leaves original files 100% untouched. Creates a safe testing sandbox.
 */
header('Content-Type: text/plain');

// Configuration: Files to completely ignore during mass replication
$ignored_files = [
    'db.php', 
    'login_kiosk.php', 
    'deploy_security_staging.php'
];

$security_payload = '
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION["user_id"]) || empty($_SESSION["user_id"])) {
    header("Location: login_kiosk.php?err=session_expired");
    exit;
}
$sess_user_level = isset($_SESSION["user_level"]) ? (int)$_SESSION["user_level"] : 99;
if ($sess_user_level >= 30) {
    header("Location: login_kiosk.php?err=access_denied_clearance");
    exit;
}
';

// Normalize formatting
$security_payload = trim($security_payload) . "\n";

// Scan target directory
$directory = new RecursiveDirectoryIterator(__DIR__);
$iterator = new RecursiveIteratorIterator($directory);
$count = 0;

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $filename = $file->getFilename();
        $filepath = $file->getRealPath();
        
        // Skip ignored assets and skip files that are already staging duplicates
        if (in_array($filename, $ignored_files) || str_ends_with($filename, '2.php')) {
            continue;
        }
        
        $content = file_get_contents($filepath);
        
        // Target the opening PHP token tag
        if (strpos($content, '<?php') === 0) {
            // Inject security code immediately after the opening tag
            $updated_content = substr_replace($content, "<?php\n" . $security_payload, 0, 5);
            
            // Generate the new staging path name (e.g., /staff_audit.php -> /staff_audit2.php)
            $directory_path = dirname($filepath);
            $base_name = pathinfo($filepath, PATHINFO_FILENAME);
            $new_filepath = $directory_path . '/' . $base_name . '2.php';
            
            // Save out as the duplicate staging file
            file_put_contents($new_filepath, $updated_content);
            
            echo "🔒 Sandbox Clone Created: {$base_name}.php ➔ " . basename($new_filepath) . "\n";
            $count++;
        }
    }
}

echo "\n🎯 Staging Deployment Complete! Total clone files generated: $count\n";
echo "💡 Test the sandbox files now by visiting your URL with '2.php' (e.g., staff_audit2.php)\n";