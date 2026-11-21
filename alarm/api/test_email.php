<?php
require_once __DIR__ . '/../../engine/staging.php';
if (payroll_staging_outbound_blocked('mail')) {
    echo "Staging does not send mail.\n";
    exit;
}
$result = mail('fpilot@renocon.ca', 'Test from AlarmPulse', 'This is a test email', 'From: noreply@renocon.ca');
echo $result ? 'Email sent!' : 'Email failed!';
