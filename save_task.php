<?php
include 'db_config.php'; // Your database connection file

$creator = $_POST['creator'];
$title = $_POST['title'];
$location = $_POST['location'];
$date = $_POST['date'];
$time = $_POST['time'];
$sound = $_POST['sound'];
$assignees = $_POST['assignees']; // This is a string of emails

// 1. SAVE THE TASK
$sql = "INSERT INTO Gem_Tasks (creator_email, task_title, site_location, task_date, task_time, alarm_sound, assigned_to, task_type) 
        VALUES ('$creator', '$title', '$location', '$date', '$time', '$sound', '$assignees', 'work')";

if ($conn->query($sql) === TRUE) {
    // 2. LOG THE CONTACTS (The "Prompt" Memory)
    $email_list = explode(',', $assignees);
    foreach ($email_list as $email) {
        $email = trim($email);
        $contact_sql = "INSERT IGNORE INTO Gem_Contacts (user_email, contact_email) VALUES ('$creator', '$email')";
        $conn->query($contact_sql);
        
        // 3. SEND INVITATION EMAIL (Optional logic here)
        // mail($email, "New Task Assigned", "You have a new task on CrewPulse: $title");
    }
    echo json_encode(["status" => "success"]);
} else {
    echo json_encode(["status" => "error", "message" => $conn->error]);
}
?>