<?php
declare(strict_types=1);

/*
  Создание/обновление пользователя сервиса.
  CLI: php create_user.php admin strong-password
  Web: /create_user.php?login=admin&password=strong-password
*/
const DB_HOST = 'localhost';
const DB_NAME = 'anketaai';
const DB_USER = 'anketaai';
const DB_PASS = 'password';
const DB_CHARSET = 'utf8mb4';

function create_user_db(): PDO {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function ensure_users_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_users (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        login VARCHAR(191) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

$isCli = PHP_SAPI === 'cli';
$login = $isCli ? ($argv[1] ?? '') : ($_GET['login'] ?? $_POST['login'] ?? '');
$password = $isCli ? ($argv[2] ?? '') : ($_GET['password'] ?? $_POST['password'] ?? '');
$login = trim((string)$login);
$password = (string)$password;

if ($login === '' || $password === '') {
    $message = "Укажите логин и пароль. Пример: php create_user.php admin strong-password\n";
    if ($isCli) {
        fwrite(STDERR, $message);
        exit(1);
    }
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

$pdo = create_user_db();
ensure_users_table($pdo);
$now = date('Y-m-d H:i:s');
$stmt = $pdo->prepare('INSERT INTO app_users (login, password_hash, created_at, updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), updated_at=VALUES(updated_at)');
$stmt->execute([$login, password_hash($password, PASSWORD_DEFAULT), $now, $now]);

$message = "Пользователь '{$login}' создан или обновлён.\n";
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}
echo $message;
