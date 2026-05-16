<?php
// Copy this file to db_config.php and fill in your local or hosting database details.
// Never commit db_config.php.

$host = "localhost";
$db_name = "your_database_name";
$username = "your_database_user";
$password = "your_database_password";

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$db_name;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed.");
}
