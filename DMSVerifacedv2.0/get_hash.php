<?php
$password = 'admin12345'; // Or 'tenant12345' for a tenant
$hashed_password = password_hash($password, PASSWORD_DEFAULT);
echo $hashed_password;
?>