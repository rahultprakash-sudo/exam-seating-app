<?php
$host    = getenv('DB_HOST') ?: 'gateway01.ap-southeast-1.prod.aws.tidbcloud.com';
$port    = getenv('DB_PORT') ?: '4000';
$db      = getenv('DB_NAME') ?: 'exam_seating';
$user    = getenv('DB_USER') ?: 'LJ4JbKGYLujwpXW.root';
$pass    = getenv('DB_PASS') ?: '1NIctAJh7FDcyyBI';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE                      => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE           => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES             => false,
    PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die("Database connection failed: " . htmlspecialchars($e->getMessage()));
}