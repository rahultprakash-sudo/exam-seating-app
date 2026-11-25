<?php
$host    = getenv('DB_HOST') ?: 'gateway01.ap-southeast-1.prod.aws.tidbcloud.com';
$port    = getenv('DB_PORT') ?: '4000';
$db      = getenv('DB_NAME') ?: 'exam_seating';
$user    = getenv('DB_USER') ?: 'LJ4JbKGYLujwpXW.root';
$pass    = getenv('DB_PASS') ?: '1NIctAJh7FDcyyBI';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";

// Locate Linux system CA bundle on Vercel (fallback to TiDB ISRG Root X1 if needed)
$sslCa = '/etc/ssl/certs/ca-certificates.crt';
if (!file_exists($sslCa)) {
    $sslCa = '/etc/pki/tls/certs/ca-bundle.crt';
}

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

if (file_exists($sslCa)) {
    $options[Pdo\Mysql::ATTR_SSL_CA] = $sslCa;
    $options[Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT] = false;
}

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die("Database connection failed: " . htmlspecialchars($e->getMessage()));
}