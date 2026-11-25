<?php
$host = 'gateway01.ap-southeast-1.prod.aws.tidbcloud.com';//localhost
$db   = 'exam_seating';//exam_seating
$user = 'LJ4JbKGYLujwpXW.root';//root
$pass = '1NIctAJh7FDcyyBI'; // Default XAMPP MySQL password is blank. Change if you set one.
$charset = 'utf8mb4';
$port="4000";

$dsn = "mysql://LJ4JbKGYLujwpXW.root:1NIctAJh7FDcyyBI@gateway01.ap-southeast-1.prod.aws.tidbcloud.com:4000/sys";//mysql:host=$host;dbname=$db;charset=$charset
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

// try {
//     $pdo = new PDO($dsn, $user, $pass, $options);
// } catch (PDOException $e) {
//     die("Database connection failed. Check config.php and make sure MySQL is running. Details: " .
//         htmlspecialchars($e->getMessage()));
// }
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die("Database connection failed: " . htmlspecialchars($e->getMessage()));
}
