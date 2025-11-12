<?php
// Database connection
$db_host = 'localhost';
$db_user = 'lakebanc_Offihito';
$db_pass = 'P4QG(m2jkWXN';
$db_name = 'lakebanc_Database';


ini_set('upload_max_filesize', '8M');
ini_set('post_max_size', '8M');
ini_set('upload_max_filesize', '8M');
ini_set('post_max_size', '8M');
ini_set('upload_max_filesize', '8M');
ini_set('post_max_size', '8M');


$conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}
?>