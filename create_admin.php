<?php
/**
 * Buat / reset akun admin. Jalankan dari terminal (bukan lewat browser):
 *
 *   php create_admin.php admin "Nama Lengkap" PasswordBaru123
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Hanya bisa dijalankan dari CLI.');
}

if ($argc < 4) {
    exit("Pemakaian: php create_admin.php <username> \"<nama lengkap>\" <password>\n");
}

require __DIR__ . '/config/database.php';

[, $username, $fullName, $password] = $argv;

if (strlen($password) < 8) {
    exit("Password minimal 8 karakter.\n");
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $conn->prepare("
    INSERT INTO admins (username, password, full_name)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE password = VALUES(password), full_name = VALUES(full_name)
");
$stmt->bind_param('sss', $username, $hash, $fullName);
$stmt->execute();

echo "Admin '{$username}' siap dipakai.\n";
