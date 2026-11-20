<?php
/** Path: payroll.renocon.ca/process_apply.php */

// Allow data to come in from Crewpulse
header("Access-Control-Allow-Origin: https://crewpulse.ca");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company = trim($_POST['company_name']);
    $owner   = trim($_POST['owner_name']);
    $email   = trim($_POST['email']);
    $package = $_POST['package'];

    try {
        $stmt = $pdo->prepare("INSERT INTO subscription_requests (company_name, owner_name, email, package_requested) VALUES (?, ?, ?, ?)");
        $stmt->execute([$company, $owner, $email, $package]);
        
        // After success, we redirect back to Crewpulse to a 'Thank You' page
        header("Location: https://crewpulse.ca/thank-you.php?msg=success");
        exit;
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            header("Location: https://crewpulse.ca/apply.php?err=exists");
        } else {
            die("System Error: " . $e->getMessage());
        }
    }
} else {
    header("Location: https://crewpulse.ca/apply.php");
}