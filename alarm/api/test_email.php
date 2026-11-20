<?php
$result = mail('fpilot@renocon.ca', 'Test from AlarmPulse', 'This is a test email', 'From: noreply@renocon.ca');
echo $result ? 'Email sent!' : 'Email failed!';
?>