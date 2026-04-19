<?php
$host = "localhost";
$username = "root";
$password = ""; // default in XAMPP
$database = "sell_management";

$conn = new mysqli($host, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "Connected successfully";
?>