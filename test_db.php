<?php
// test_db.php
$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'dorm_management';

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
} else {
    echo "Database connected successfully!";
    
    // Test creating tables
    $sql = "CREATE TABLE IF NOT EXISTS test_table (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255))";
    if ($conn->query($sql) === TRUE) {
        echo "<br>Table created successfully!";
    } else {
        echo "<br>Error creating table: " . $conn->error;
    }
}
?>