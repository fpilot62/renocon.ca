<?php
// Change 'newpassword123' to whatever you want
$new_pass = 'newpassword123'; 

// This creates the long $2y$10$... string
$hashed_pass = password_hash($new_pass, PASSWORD_DEFAULT);

echo "New Hash for $new_pass is: <br><b>" . $hashed_pass . "</b>";
echo "<br><br>Copy this into your SQL UPDATE command.";
?>