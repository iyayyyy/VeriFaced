<?php
session_start(); // Start the session
session_unset(); // Unset all session variables
session_destroy(); // Destroy the session

header("Location: adminlogin.php"); // Redirect to your admin login page
exit();
?>