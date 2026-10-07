<?php

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = '127.0.0.1';
$db   = 'wa_reminder';
$user = 'wa_reminder';
$pass = 'WAREMINDER_2026';
$port = 3306;

try {

    $conn = new mysqli(
        $host,
        $user,
        $pass,
        $db,
        $port
    );

    $conn->set_charset('utf8mb4');

} catch (mysqli_sql_exception $e) {

    die(
        'Database connection failed: ' .
        htmlspecialchars($e->getMessage())
    );

}