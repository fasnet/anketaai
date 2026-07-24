<?php
declare(strict_types=1);

const AUTH_SESSION_TTL = 86400;

ini_set('session.gc_maxlifetime', (string)AUTH_SESSION_TTL);
session_set_cookie_params([
    'lifetime' => AUTH_SESSION_TTL,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

/*
  Настройки
*/
const VSEGPT_API_KEY = '...';
const VSEGPT_API_URL = 'https://api.vsegpt.ru:7090/v1/chat/completions';
const VSEGPT_MODEL   = 'gpt-4o-mini';
const DB_HOST = 'localhost';
const DB_NAME = 'anketaai';
const DB_USER = 'anketaai';
const DB_PASS = 'password';
const DB_CHARSET = 'utf8mb4';
const RNOVA_API_URL = 'https://app.rnova.org/api/public/';
const RNOVA_API_TOKEN = ''; // fallback; prefer RNOVA_API_TOKEN environment variable
const RNOVA_ADMIN_ROLE_ID = 12460;
const RNOVA_EMPLOYEE_ID = 50256;

/*
  Настройки оплаты Prodamus
*/
const PRODAMUS_SECRET_KEY = 'secretKey';
const PRODAMUS_FORM_URL = 'https://adaptogenzzclinic.payform.ru/';
const PRODAMUS_SHOP_ID = 'adaptogenzzclinic';
const PRODAMUS_PRODUCT_NAME = 'Анкета здоровья';
const PRODAMUS_PRODUCT_PRICE = 3000; // fallback default; editable in admin settings
const PRODAMUS_PRODUCT_QUANTITY = 1;

/*
  Helpers
*/
function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function postv($key, $default = '') {
    return $_POST[$key] ?? $default;
}

function smtp_read_response($socket): string {
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') break;
    }
    return $response;
}

function smtp_command($socket, string $command, array $expectedCodes, string $stage, ?string &$error): bool {
    if ($command !== '') fwrite($socket, $command . "\r\n");
    $response = smtp_read_response($socket);
    if (in_array((int)substr($response, 0, 3), $expectedCodes, true)) return true;
    $error = 'Ошибка SMTP на этапе «' . $stage . '»: ' . (trim($response) ?: 'сервер не ответил');
    return false;
}

function send_via_yandex_smtp(string $to, string $subject, string $message): array {
    $username = trim((string)getenv('YANDEX_SMTP_USERNAME'));
    $password = (string)getenv('YANDEX_SMTP_PASSWORD');
    $from = trim((string)(getenv('YANDEX_SMTP_FROM') ?: $username));
    if (!filter_var($username, FILTER_VALIDATE_EMAIL) || $password === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        error_log('Yandex SMTP is not configured: set YANDEX_SMTP_USERNAME, YANDEX_SMTP_PASSWORD and optionally YANDEX_SMTP_FROM.');
        return ['sent' => false, 'status' => 'error', 'error' => 'SMTP Яндекса не настроен на сервере.'];
    }

    $errorNumber = 0;
    $errorMessage = '';
    $socket = @stream_socket_client('ssl://smtp.yandex.ru:465', $errorNumber, $errorMessage, 15);
    if (!is_resource($socket)) {
        error_log('Yandex SMTP connection failed: ' . $errorMessage . ' (' . $errorNumber . ')');
        return ['sent' => false, 'status' => 'error', 'error' => 'Не удалось подключиться к SMTP Яндекса: ' . ($errorMessage ?: 'ошибка соединения') . '.'];
    }
    stream_set_timeout($socket, 15);

    $subjectHeader = function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n")
        : '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: Adaptogenzz Clinic <' . $from . '>',
        'To: <' . $to . '>',
        'Subject: ' . $subjectHeader,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    $body = str_replace(["\r\n", "\r"], "\n", $message);
    $body = str_replace("\n.", "\n..", $body);
    $data = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", $body) . "\r\n.";

    $hostname = gethostname() ?: 'localhost';
    $smtpError = null;
    $ok = smtp_command($socket, '', [220], 'подключение', $smtpError)
        && smtp_command($socket, 'EHLO ' . $hostname, [250], 'приветствие', $smtpError)
        && smtp_command($socket, 'AUTH LOGIN', [334], 'начало авторизации', $smtpError)
        && smtp_command($socket, base64_encode($username), [334], 'отправка логина', $smtpError)
        && smtp_command($socket, base64_encode($password), [235], 'авторизация', $smtpError)
        && smtp_command($socket, 'MAIL FROM:<' . $from . '>', [250], 'адрес отправителя', $smtpError)
        && smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251], 'адрес получателя', $smtpError)
        && smtp_command($socket, 'DATA', [354], 'начало передачи письма', $smtpError)
        && smtp_command($socket, $data, [250], 'отправка письма', $smtpError);
    if ($ok) smtp_command($socket, 'QUIT', [221], 'завершение соединения', $smtpError);
    fclose($socket);
    return [
        'sent' => $ok,
        'status' => $ok ? 'sent' : 'error',
        'error' => $ok ? null : ($smtpError ?: 'Неизвестная ошибка SMTP.'),
    ];
}

function send_patient_questionnaire_confirmation(array $patient): array {
    $email = trim((string)($patient['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['sent' => false, 'status' => 'error', 'error' => 'Некорректный адрес электронной почты.'];
    }

    $recipientName = trim(implode(' ', array_filter([
        trim((string)($patient['name'] ?? '')),
        trim((string)($patient['patronymic'] ?? '')),
    ], static fn($part) => $part !== '')));
    $message = $recipientName . ', Ваша анкета зарегистрирована в нашей системе. '
        . 'Наши врачи внимательно изучат предоставленную информацию и перечень анализов будет направлен Вам '
        . 'на электронную почту не позднее чем через 3 дня.';
    return send_via_yandex_smtp($email, 'Анкета зарегистрирована', $message);
}

function skipped_email_result(): array {
    return ['sent' => false, 'status' => 'skipped', 'error' => 'Письмо не отправлялось, потому что ответ анкеты не был сохранён.'];
}


function db() {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    db_init($pdo);
    return $pdo;
}

function db_init(PDO $pdo) {
    static $done = false;
    if ($done) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS questionnaires (
        id VARCHAR(64) NOT NULL PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        deleted_at DATETIME NULL
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS questionnaire_sections (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        questionnaire_id VARCHAR(64) NOT NULL,
        position INT NOT NULL DEFAULT 0,
        title VARCHAR(255) NOT NULL,
        sex VARCHAR(16) NULL,
        INDEX questionnaire_idx (questionnaire_id)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS questionnaire_questions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        section_id INT UNSIGNED NOT NULL,
        position INT NOT NULL DEFAULT 0,
        name VARCHAR(128) NOT NULL,
        type VARCHAR(32) NOT NULL,
        label TEXT NOT NULL,
        other_name VARCHAR(128) NULL,
        min_value VARCHAR(32) NULL,
        max_value VARCHAR(32) NULL,
        step_value VARCHAR(32) NULL,
        INDEX section_idx (section_id)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS questionnaire_question_options (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        question_id INT UNSIGNED NOT NULL,
        position INT NOT NULL DEFAULT 0,
        option_value TEXT NOT NULL,
        INDEX question_idx (question_id)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS questionnaire_question_hints (
        questionnaire_id VARCHAR(64) NOT NULL,
        question_name VARCHAR(128) NOT NULL,
        hint TEXT NULL,
        PRIMARY KEY (questionnaire_id, question_name)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS questionnaire_option_hints (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        questionnaire_id VARCHAR(64) NOT NULL,
        question_name VARCHAR(128) NOT NULL,
        option_value VARCHAR(255) NOT NULL,
        hint TEXT NULL,
        UNIQUE KEY questionnaire_option_unique (questionnaire_id, question_name, option_value)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS patient_responses (
        id VARCHAR(64) NOT NULL PRIMARY KEY,
        questionnaire_id VARCHAR(64) NULL,
        survey VARCHAR(255) NULL,
        category VARCHAR(255) NULL,
        status VARCHAR(32) NULL,
        progress INT NOT NULL DEFAULT 0,
        filled_answers INT NOT NULL DEFAULT 0,
        total_answers INT NOT NULL DEFAULT 0,
        patient_surname VARCHAR(255) NULL,
        patient_name VARCHAR(255) NULL,
        patient_patronymic VARCHAR(255) NULL,
        patient_dob VARCHAR(32) NULL,
        patient_phone VARCHAR(64) NULL,
        patient_email VARCHAR(255) NULL,
        patient_sex VARCHAR(32) NULL,
        patient_height VARCHAR(32) NULL,
        patient_weight VARCHAR(32) NULL,
        patient_waist VARCHAR(32) NULL,
        patient_filled_at VARCHAR(32) NULL,
        analysis_raw LONGTEXT NULL,
        ai_answer_html LONGTEXT NULL,
        mis_sent_at VARCHAR(64) NULL,
        mis_patient_id VARCHAR(64) NULL,
        mis_task_id VARCHAR(64) NULL,
        mis_task_json LONGTEXT NULL,
        mis_file_json LONGTEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX questionnaire_id_idx (questionnaire_id),
        INDEX status_idx (status)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    db_drop_columns($pdo, 'questionnaires', ['sections_json', 'hints_json']);
    db_drop_columns($pdo, 'patient_responses', ['response_json']);
    db_ensure_columns($pdo, 'patient_responses', [
        'survey' => 'VARCHAR(255) NULL', 'category' => 'VARCHAR(255) NULL', 'progress' => 'INT NOT NULL DEFAULT 0',
        'filled_answers' => 'INT NOT NULL DEFAULT 0', 'total_answers' => 'INT NOT NULL DEFAULT 0',
        'patient_surname' => 'VARCHAR(255) NULL', 'patient_name' => 'VARCHAR(255) NULL', 'patient_patronymic' => 'VARCHAR(255) NULL',
        'patient_dob' => 'VARCHAR(32) NULL', 'patient_phone' => 'VARCHAR(64) NULL', 'patient_email' => 'VARCHAR(255) NULL',
        'patient_sex' => 'VARCHAR(32) NULL', 'patient_height' => 'VARCHAR(32) NULL', 'patient_weight' => 'VARCHAR(32) NULL',
        'patient_waist' => 'VARCHAR(32) NULL', 'patient_filled_at' => 'VARCHAR(32) NULL', 'analysis_raw' => 'LONGTEXT NULL',
        'ai_answer_html' => 'LONGTEXT NULL', 'vsegpt_cost' => 'DECIMAL(12,6) NOT NULL DEFAULT 0', 'billing_amount' => 'DECIMAL(12,6) NOT NULL DEFAULT 0', 'vsegpt_usage_json' => 'LONGTEXT NULL', 'mis_sent_at' => 'VARCHAR(64) NULL', 'mis_patient_id' => 'VARCHAR(64) NULL', 'mis_task_id' => 'VARCHAR(64) NULL', 'mis_task_json' => 'LONGTEXT NULL', 'mis_file_json' => 'LONGTEXT NULL'
    ]);
    $pdo->exec("CREATE TABLE IF NOT EXISTS patient_response_sections (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        response_id VARCHAR(64) NOT NULL,
        position INT NOT NULL DEFAULT 0,
        title VARCHAR(255) NOT NULL,
        INDEX response_idx (response_id)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS patient_response_answers (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        response_section_id INT UNSIGNED NOT NULL,
        position INT NOT NULL DEFAULT 0,
        question TEXT NOT NULL,
        answer_text LONGTEXT NULL,
        INDEX section_idx (response_section_id)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS patient_response_hints (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        response_id VARCHAR(64) NOT NULL,
        position INT NOT NULL DEFAULT 0,
        hint TEXT NOT NULL,
        INDEX response_hint_idx (response_id)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS patient_response_history (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        response_id VARCHAR(64) NOT NULL,
        position INT NOT NULL DEFAULT 0,
        event_date VARCHAR(64) NOT NULL,
        event_text TEXT NOT NULL,
        INDEX response_history_idx (response_id)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_users (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        login VARCHAR(191) NOT NULL UNIQUE,
        full_name VARCHAR(255) NULL,
        email VARCHAR(191) NULL,
        password_hash VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key VARCHAR(191) NOT NULL PRIMARY KEY,
        setting_value TEXT NULL,
        updated_at DATETIME NOT NULL
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS billing_history (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        response_id VARCHAR(64) NOT NULL,
        survey VARCHAR(255) NULL,
        patient_surname VARCHAR(255) NULL,
        patient_name VARCHAR(255) NULL,
        patient_patronymic VARCHAR(255) NULL,
        vsegpt_cost DECIMAL(12,6) NOT NULL DEFAULT 0,
        billing_amount DECIMAL(12,6) NOT NULL DEFAULT 0,
        vsegpt_usage_json LONGTEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY response_unique (response_id),
        INDEX billing_updated_idx (updated_at)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    db_ensure_columns($pdo, 'billing_history', [
        'survey' => 'VARCHAR(255) NULL',
        'patient_surname' => 'VARCHAR(255) NULL', 'patient_name' => 'VARCHAR(255) NULL', 'patient_patronymic' => 'VARCHAR(255) NULL',
        'vsegpt_cost' => 'DECIMAL(12,6) NOT NULL DEFAULT 0', 'billing_amount' => 'DECIMAL(12,6) NOT NULL DEFAULT 0',
        'vsegpt_usage_json' => 'LONGTEXT NULL'
    ]);
    $pdo->exec("INSERT INTO billing_history (response_id, survey, patient_surname, patient_name, patient_patronymic, vsegpt_cost, billing_amount, vsegpt_usage_json, created_at, updated_at)
        SELECT id, survey, patient_surname, patient_name, patient_patronymic, vsegpt_cost, billing_amount, vsegpt_usage_json, created_at, updated_at
        FROM patient_responses
        WHERE billing_amount > 0
        ON DUPLICATE KEY UPDATE survey=VALUES(survey), patient_surname=VALUES(patient_surname), patient_name=VALUES(patient_name), patient_patronymic=VALUES(patient_patronymic), vsegpt_cost=VALUES(vsegpt_cost), billing_amount=VALUES(billing_amount), vsegpt_usage_json=VALUES(vsegpt_usage_json), updated_at=VALUES(updated_at)");

    db_ensure_columns($pdo, 'app_users', [
        'full_name' => 'VARCHAR(255) NULL',
        'email' => 'VARCHAR(191) NULL'
    ]);

    $done = true;
}



function app_setting($key, $default = '') {
    $stmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1');
    $stmt->execute([(string)$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string)$value;
}

function save_app_setting($key, $value) {
    $stmt = db()->prepare('INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=VALUES(updated_at)');
    return $stmt->execute([(string)$key, (string)$value, date('Y-m-d H:i:s')]);
}

function questionnaire_price() {
    $price = (float)str_replace(',', '.', app_setting('questionnaire_price', (string)PRODAMUS_PRODUCT_PRICE));
    return max(0.0, $price);
}

function money_amount_label($amount) {
    $amount = (float)$amount;
    return rtrim(rtrim(number_format($amount, 2, '.', ' '), '0'), '.');
}

function money_amount_input_value($amount) {
    $amount = (float)$amount;
    return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
}

function current_user() {
    $id = $_SESSION['user_id'] ?? null;
    $expiresAt = (int)($_SESSION['auth_expires_at'] ?? 0);
    if ($id && ($expiresAt === 0 || $expiresAt <= time())) {
        logout_user();
        return null;
    }
    if (!$id) return null;
    $stmt = db()->prepare('SELECT id, login, full_name, email, created_at, updated_at FROM app_users WHERE id=?');
    $stmt->execute([(int)$id]);
    return $stmt->fetch() ?: null;
}

function is_authenticated() {
    return (bool)current_user();
}

function login_user($login, $password) {
    $stmt = db()->prepare('SELECT * FROM app_users WHERE login=? LIMIT 1');
    $stmt->execute([trim((string)$login)]);
    $user = $stmt->fetch();
    if (!$user || !password_verify((string)$password, (string)$user['password_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['auth_expires_at'] = time() + AUTH_SESSION_TTL;
    return true;
}

function logout_user() {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
}


function app_users_all() {
    return db()->query('SELECT id, login, full_name, email, created_at, updated_at FROM app_users ORDER BY id DESC')->fetchAll();
}

function app_user_find($id) {
    $stmt = db()->prepare('SELECT id, login, full_name, email, created_at, updated_at FROM app_users WHERE id=?');
    $stmt->execute([(int)$id]);
    return $stmt->fetch() ?: null;
}

function save_app_user($id, $fullName, $email, $password) {
    $pdo = db();
    $email = trim((string)$email);
    $fullName = trim((string)$fullName);
    if ($email === '') return false;
    $now = date('Y-m-d H:i:s');
    if ((int)$id > 0) {
        if (trim((string)$password) !== '') {
            $stmt = $pdo->prepare('UPDATE app_users SET login=?, email=?, full_name=?, password_hash=?, updated_at=? WHERE id=?');
            return $stmt->execute([$email, $email, $fullName, password_hash((string)$password, PASSWORD_DEFAULT), $now, (int)$id]);
        }
        $stmt = $pdo->prepare('UPDATE app_users SET login=?, email=?, full_name=?, updated_at=? WHERE id=?');
        return $stmt->execute([$email, $email, $fullName, $now, (int)$id]);
    }
    if (trim((string)$password) === '') return false;
    $stmt = $pdo->prepare('INSERT INTO app_users (login, email, full_name, password_hash, created_at, updated_at) VALUES (?,?,?,?,?,?)');
    return $stmt->execute([$email, $email, $fullName, password_hash((string)$password, PASSWORD_DEFAULT), $now, $now]);
}

function delete_app_user($id) {
    $id = (int)$id;
    if ($id <= 0 || $id === (int)($_SESSION['user_id'] ?? 0)) return false;
    $stmt = db()->prepare('DELETE FROM app_users WHERE id=?');
    return $stmt->execute([$id]);
}

function redirect_to_login() {
    $target = $_SERVER['REQUEST_URI'] ?? '?page=questionnaires';
    header('Location: ?page=login&next=' . rawurlencode($target));
    exit;
}

function require_auth_for_page($page) {
    if ($page === 'form' || $page === 'login') return;
    if (!is_authenticated()) redirect_to_login();
}

function require_auth_for_action($action) {
    if ($action === 'analyze' || $action === 'login') return;
    if (!is_authenticated()) {
        if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Требуется авторизация.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        redirect_to_login();
    }
}

function db_identifier($name) {
    return '`' . str_replace('`', '', (string)$name) . '`';
}

function db_column_exists(PDO $pdo, $table, $column) {
    $sql = 'SHOW COLUMNS FROM ' . db_identifier($table) . ' LIKE ' . $pdo->quote((string)$column);
    $stmt = $pdo->query($sql);
    return (bool)$stmt->fetch();
}

function db_drop_columns(PDO $pdo, $table, $columns) {
    foreach ($columns as $name) {
        if (db_column_exists($pdo, $table, $name)) {
            $pdo->exec('ALTER TABLE ' . db_identifier($table) . ' DROP COLUMN ' . db_identifier($name));
        }
    }
}

function db_ensure_columns(PDO $pdo, $table, $columns) {
    foreach ($columns as $name => $definition) {
        if (!db_column_exists($pdo, $table, $name)) {
            $pdo->exec('ALTER TABLE ' . db_identifier($table) . ' ADD COLUMN ' . db_identifier($name) . ' ' . $definition);
        }
    }
}

function db_json_decode($raw, $default = []) {
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : $default;
}

function db_date($iso = null) {
    if (!$iso) return date('Y-m-d H:i:s');
    try { return (new DateTimeImmutable((string)$iso))->format('Y-m-d H:i:s'); } catch (Throwable $e) { return date('Y-m-d H:i:s'); }
}

function questionnaire_id_from_title($title) {
    $latin = function_exists('transliterator_transliterate') ? (transliterator_transliterate('Any-Latin; Latin-ASCII', (string)$title) ?: (string)$title) : (string)$title;
    $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/u', '-', $latin), '-'));
    return ($slug ?: 'questionnaire') . '-' . bin2hex(random_bytes(3));
}

function questionnaire_save_structure($questionnaireId, $sections) {
    $pdo = db();
    $sectionIds = $pdo->prepare('SELECT id FROM questionnaire_sections WHERE questionnaire_id=?');
    $sectionIds->execute([(string)$questionnaireId]);
    $ids = $sectionIds->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $questionIds = $pdo->prepare("SELECT id FROM questionnaire_questions WHERE section_id IN ($in)");
        $questionIds->execute($ids);
        $qids = $questionIds->fetchAll(PDO::FETCH_COLUMN);
        if ($qids) {
            $qin = implode(',', array_fill(0, count($qids), '?'));
            $pdo->prepare("DELETE FROM questionnaire_question_options WHERE question_id IN ($qin)")->execute($qids);
        }
        $pdo->prepare("DELETE FROM questionnaire_questions WHERE section_id IN ($in)")->execute($ids);
        $pdo->prepare('DELETE FROM questionnaire_sections WHERE questionnaire_id=?')->execute([(string)$questionnaireId]);
    }

    $insertSection = $pdo->prepare('INSERT INTO questionnaire_sections (questionnaire_id, position, title, sex) VALUES (?,?,?,?)');
    $insertQuestion = $pdo->prepare('INSERT INTO questionnaire_questions (section_id, position, name, type, label, other_name, min_value, max_value, step_value) VALUES (?,?,?,?,?,?,?,?,?)');
    $insertOption = $pdo->prepare('INSERT INTO questionnaire_question_options (question_id, position, option_value) VALUES (?,?,?)');
    foreach (array_values($sections) as $si => $section) {
        $insertSection->execute([(string)$questionnaireId, $si, (string)($section['title'] ?? 'Раздел'), $section['sex'] ?? null]);
        $sectionId = (int)$pdo->lastInsertId();
        foreach (array_values($section['questions'] ?? []) as $qi => $q) {
            $insertQuestion->execute([$sectionId, $qi, (string)($q['name'] ?? ('q_' . $qi)), (string)($q['type'] ?? 'text'), (string)($q['label'] ?? 'Вопрос'), $q['other_name'] ?? null, $q['min'] ?? null, $q['max'] ?? null, $q['step'] ?? null]);
            $questionId = (int)$pdo->lastInsertId();
            foreach (array_values($q['options'] ?? []) as $oi => $option) {
                $insertOption->execute([$questionId, $oi, is_array($option) ? (string)($option['label'] ?? '') : (string)$option]);
            }
        }
    }
}

function questionnaire_load_sections($questionnaireId) {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM questionnaire_sections WHERE questionnaire_id=? ORDER BY position, id');
    $stmt->execute([(string)$questionnaireId]);
    $sections = [];
    foreach ($stmt->fetchAll() as $sectionRow) {
        $qstmt = $pdo->prepare('SELECT * FROM questionnaire_questions WHERE section_id=? ORDER BY position, id');
        $qstmt->execute([(int)$sectionRow['id']]);
        $questions = [];
        foreach ($qstmt->fetchAll() as $qrow) {
            $ostmt = $pdo->prepare('SELECT option_value FROM questionnaire_question_options WHERE question_id=? ORDER BY position, id');
            $ostmt->execute([(int)$qrow['id']]);
            $q = ['name' => (string)$qrow['name'], 'type' => (string)$qrow['type'], 'label' => (string)$qrow['label']];
            $options = array_map('strval', $ostmt->fetchAll(PDO::FETCH_COLUMN));
            if ($options) $q['options'] = $options;
            foreach (['other_name' => 'other_name', 'min_value' => 'min', 'max_value' => 'max', 'step_value' => 'step'] as $col => $key) {
                if (($qrow[$col] ?? '') !== '') $q[$key] = $qrow[$col];
            }
            $questions[] = $q;
        }
        $section = ['title' => (string)$sectionRow['title'], 'questions' => $questions];
        if (!empty($sectionRow['sex'])) $section['sex'] = (string)$sectionRow['sex'];
        $sections[] = $section;
    }
    return $sections;
}

function questionnaire_from_row($row) {
    $sections = questionnaire_load_sections($row['id']);
    if (!$sections && (string)$row['id'] === 'health') {
        $sections = questionnaire_sections_static();
        questionnaire_save_structure('health', $sections);
        save_hint_config(default_hint_config($sections), 'health');
    }
    return [
        'id' => (string)$row['id'],
        'title' => (string)$row['title'],
        'sections' => $sections,
        'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at'],
    ];
}

function ensure_default_questionnaire() {
    $pdo = db();
    $exists = $pdo->query("SELECT COUNT(*) FROM questionnaires WHERE deleted_at IS NULL")->fetchColumn();
    if ((int)$exists > 0) return;
    $sections = questionnaire_sections_static();
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare('INSERT INTO questionnaires (id,title,created_at,updated_at) VALUES (?,?,?,?)');
    $stmt->execute(['health', 'Анкета здоровья', $now, $now]);
    questionnaire_save_structure('health', $sections);
    save_hint_config(default_hint_config($sections), 'health');
}

function questionnaires_all() {
    ensure_default_questionnaire();
    $rows = db()->query('SELECT * FROM questionnaires WHERE deleted_at IS NULL ORDER BY updated_at DESC')->fetchAll();
    return array_map('questionnaire_from_row', $rows);
}

function questionnaire_find($id = null, $fallback = true) {
    ensure_default_questionnaire();
    if ($id) {
        $stmt = db()->prepare('SELECT * FROM questionnaires WHERE id=? AND deleted_at IS NULL');
        $stmt->execute([(string)$id]);
        $row = $stmt->fetch();
        if ($row) return questionnaire_from_row($row);
        if (!$fallback) return null;
    } elseif (!$fallback) {
        return null;
    }
    $row = db()->query('SELECT * FROM questionnaires WHERE deleted_at IS NULL ORDER BY created_at ASC LIMIT 1')->fetch();
    return $row ? questionnaire_from_row($row) : null;
}

function db_answer_to_string($answer) {
    return is_array($answer) ? implode("\n", array_map('strval', $answer)) : (string)$answer;
}

function db_answer_from_string($answer) {
    $answer = (string)$answer;
    return strpos($answer, "\n") !== false ? explode("\n", $answer) : $answer;
}

function response_from_row($row) {
    $patient = [
        'surname' => $row['patient_surname'] ?? '', 'name' => $row['patient_name'] ?? '', 'patronymic' => $row['patient_patronymic'] ?? '',
        'dob' => $row['patient_dob'] ?? '', 'phone' => $row['patient_phone'] ?? '', 'email' => $row['patient_email'] ?? '',
        'sex' => $row['patient_sex'] ?? '', 'height' => $row['patient_height'] ?? '', 'weight' => $row['patient_weight'] ?? '',
        'waist' => $row['patient_waist'] ?? '', 'filled_at' => $row['patient_filled_at'] ?? '',
    ];
    $sections = [];
    $sstmt = db()->prepare('SELECT * FROM patient_response_sections WHERE response_id=? ORDER BY position, id');
    $sstmt->execute([(string)$row['id']]);
    foreach ($sstmt->fetchAll() as $srow) {
        $astmt = db()->prepare('SELECT * FROM patient_response_answers WHERE response_section_id=? ORDER BY position, id');
        $astmt->execute([(int)$srow['id']]);
        $answers = [];
        foreach ($astmt->fetchAll() as $arow) {
            $answers[] = ['question' => (string)$arow['question'], 'answer' => db_answer_from_string($arow['answer_text'] ?? '')];
        }
        $sections[] = ['section' => (string)$srow['title'], 'answers' => $answers];
    }
    $hstmt = db()->prepare('SELECT hint FROM patient_response_hints WHERE response_id=? ORDER BY position, id');
    $hstmt->execute([(string)$row['id']]);
    $historyStmt = db()->prepare('SELECT event_date, event_text FROM patient_response_history WHERE response_id=? ORDER BY position, id');
    $historyStmt->execute([(string)$row['id']]);
    $history = [];
    foreach ($historyStmt->fetchAll() as $event) $history[] = ['date' => $event['event_date'], 'event' => $event['event_text']];
    return [
        'id' => (string)$row['id'], 'questionnaire_id' => $row['questionnaire_id'] ?? null, 'patient' => $patient,
        'survey' => $row['survey'] ?? 'Анкета здоровья', 'category' => $row['category'] ?? '',
        'status' => $row['status'] ?? 'draft', 'progress' => (int)($row['progress'] ?? 0), 'filled_answers' => (int)($row['filled_answers'] ?? 0), 'total_answers' => (int)($row['total_answers'] ?? 0),
        'created_at' => $row['created_at'] ?? '', 'updated_at' => $row['updated_at'] ?? '', 'answers' => $sections,
        'hints' => array_map('strval', $hstmt->fetchAll(PDO::FETCH_COLUMN)), 'analysis' => null, 'analysis_raw' => $row['analysis_raw'] ?? '',
        'ai_answer_html' => $row['ai_answer_html'] ?: '<p>ИИ-анализ пока не сформирован.</p>', 'vsegpt_cost' => (float)($row['vsegpt_cost'] ?? 0), 'billing_amount' => (float)($row['billing_amount'] ?? 0), 'vsegpt_usage_json' => $row['vsegpt_usage_json'] ?? '', 'mis_sent_at' => $row['mis_sent_at'] ?? null, 'mis_patient_id' => $row['mis_patient_id'] ?? null, 'mis_task_id' => $row['mis_task_id'] ?? null, 'mis_task' => json_decode((string)($row['mis_task_json'] ?? ''), true) ?: null, 'mis_file' => json_decode((string)($row['mis_file_json'] ?? ''), true) ?: null, 'history' => $history,
    ];
}

function load_patient_responses() {
    $rows = db()->query('SELECT * FROM patient_responses ORDER BY created_at DESC')->fetchAll();
    return ['responses' => array_map('response_from_row', $rows)];
}

function save_patient_responses($data) {
    if (empty($data['responses']) || !is_array($data['responses'])) return true;
    foreach ($data['responses'] as $response) {
        if (is_array($response) && !empty($response['id'])) upsert_patient_response($response);
    }
    return true;
}

function upsert_patient_response($record) {
    $stmt = db()->prepare('INSERT INTO patient_responses (id, questionnaire_id, survey, category, status, progress, filled_answers, total_answers, patient_surname, patient_name, patient_patronymic, patient_dob, patient_phone, patient_email, patient_sex, patient_height, patient_weight, patient_waist, patient_filled_at, analysis_raw, ai_answer_html, vsegpt_cost, billing_amount, vsegpt_usage_json, mis_sent_at, mis_patient_id, mis_task_id, mis_task_json, mis_file_json, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE questionnaire_id=VALUES(questionnaire_id), survey=VALUES(survey), category=VALUES(category), status=VALUES(status), progress=VALUES(progress), filled_answers=VALUES(filled_answers), total_answers=VALUES(total_answers), patient_surname=VALUES(patient_surname), patient_name=VALUES(patient_name), patient_patronymic=VALUES(patient_patronymic), patient_dob=VALUES(patient_dob), patient_phone=VALUES(patient_phone), patient_email=VALUES(patient_email), patient_sex=VALUES(patient_sex), patient_height=VALUES(patient_height), patient_weight=VALUES(patient_weight), patient_waist=VALUES(patient_waist), patient_filled_at=VALUES(patient_filled_at), analysis_raw=VALUES(analysis_raw), ai_answer_html=VALUES(ai_answer_html), vsegpt_cost=VALUES(vsegpt_cost), billing_amount=VALUES(billing_amount), vsegpt_usage_json=VALUES(vsegpt_usage_json), mis_sent_at=VALUES(mis_sent_at), mis_patient_id=VALUES(mis_patient_id), mis_task_id=VALUES(mis_task_id), mis_task_json=VALUES(mis_task_json), mis_file_json=VALUES(mis_file_json), updated_at=VALUES(updated_at)');
    $patient = is_array($record['patient'] ?? null) ? $record['patient'] : [];
    $ok = $stmt->execute([(string)$record['id'], $record['questionnaire_id'] ?? null, $record['survey'] ?? null, $record['category'] ?? null, $record['status'] ?? null, (int)($record['progress'] ?? 0), (int)($record['filled_answers'] ?? 0), (int)($record['total_answers'] ?? 0), $patient['surname'] ?? '', $patient['name'] ?? '', $patient['patronymic'] ?? '', $patient['dob'] ?? '', $patient['phone'] ?? '', $patient['email'] ?? '', $patient['sex'] ?? '', $patient['height'] ?? '', $patient['weight'] ?? '', $patient['waist'] ?? '', $patient['filled_at'] ?? '', $record['analysis_raw'] ?? '', $record['ai_answer_html'] ?? '', (float)($record['vsegpt_cost'] ?? 0), (float)($record['billing_amount'] ?? 0), $record['vsegpt_usage_json'] ?? '', $record['mis_sent_at'] ?? null, $record['mis_patient_id'] ?? null, $record['mis_task_id'] ?? null, json_encode($record['mis_task'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), json_encode($record['mis_file'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), db_date($record['created_at'] ?? null), db_date($record['updated_at'] ?? null)]);
    if (!$ok) return false;
    upsert_billing_history_for_response($record);
    $id = (string)$record['id'];
    db()->prepare('DELETE FROM patient_response_hints WHERE response_id=?')->execute([$id]);
    db()->prepare('DELETE FROM patient_response_history WHERE response_id=?')->execute([$id]);
    $oldSections = db()->prepare('SELECT id FROM patient_response_sections WHERE response_id=?');
    $oldSections->execute([$id]);
    $sectionIds = $oldSections->fetchAll(PDO::FETCH_COLUMN);
    if ($sectionIds) {
        db()->prepare('DELETE FROM patient_response_answers WHERE response_section_id IN (' . implode(',', array_fill(0, count($sectionIds), '?')) . ')')->execute($sectionIds);
    }
    db()->prepare('DELETE FROM patient_response_sections WHERE response_id=?')->execute([$id]);
    $insertSection = db()->prepare('INSERT INTO patient_response_sections (response_id, position, title) VALUES (?,?,?)');
    $insertAnswer = db()->prepare('INSERT INTO patient_response_answers (response_section_id, position, question, answer_text) VALUES (?,?,?,?)');
    foreach (array_values($record['answers'] ?? []) as $si => $section) {
        $insertSection->execute([$id, $si, (string)($section['section'] ?? '')]);
        $sectionId = (int)db()->lastInsertId();
        foreach (array_values($section['answers'] ?? []) as $ai => $answer) {
            $insertAnswer->execute([$sectionId, $ai, (string)($answer['question'] ?? ''), db_answer_to_string($answer['answer'] ?? '')]);
        }
    }
    $hintStmt = db()->prepare('INSERT INTO patient_response_hints (response_id, position, hint) VALUES (?,?,?)');
    foreach (array_values($record['hints'] ?? []) as $i => $hint) $hintStmt->execute([$id, $i, (string)$hint]);
    $historyStmt = db()->prepare('INSERT INTO patient_response_history (response_id, position, event_date, event_text) VALUES (?,?,?,?)');
    foreach (array_values($record['history'] ?? []) as $i => $event) $historyStmt->execute([$id, $i, (string)($event['date'] ?? ''), (string)($event['event'] ?? '')]);
    return true;
}

function app_base_url() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = strtok((string)($_SERVER['REQUEST_URI'] ?? '/index.php'), '?');
    return $scheme . '://' . $host . $path;
}

function prodamus_stringify_values($value) {
    if (is_array($value)) {
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = prodamus_stringify_values($item);
        }
        return $value;
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    return (string)$value;
}

function prodamus_signature($data, $secretKey) {
    $prepared = prodamus_stringify_values($data);
    $json = json_encode($prepared, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return '';
    }
    $json = str_replace('/', '\\/', $json);
    return base64_encode(hash_hmac('sha256', $json, (string)$secretKey, true));
}


function normalize_russian_phone($phone) {
    $raw = trim((string)$phone);
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if (strlen($digits) === 11 && $digits[0] === '8') {
        $digits = '7' . substr($digits, 1);
    } elseif (strlen($digits) === 10) {
        $digits = '7' . $digits;
    } elseif (strlen($digits) > 11 && str_starts_with($digits, '7')) {
        $digits = substr($digits, 0, 11);
    }
    return (strlen($digits) === 11 && $digits[0] === '7') ? ('+' . $digits) : $raw;
}

function is_valid_russian_phone($phone) {
    $digits = preg_replace('/\D+/', '', normalize_russian_phone($phone)) ?? '';
    return strlen($digits) === 11 && $digits[0] === '7';
}

function rnova_phone($phone) {
    $normalized = normalize_russian_phone($phone);
    return is_valid_russian_phone($normalized) ? $normalized : '+71111111111';
}

function is_payment_required() {
    return questionnaire_price() > 0;
}

function prodamus_payment_url($orderId, $patient) {
    $price = questionnaire_price();
    if ($price <= 0) return '';
    $baseUrl = app_base_url();
    $questionnaireId = trim((string)($patient['questionnaire_id'] ?? ($_POST['questionnaire_id'] ?? ($_GET['qid'] ?? ''))));
    $formUrl = $baseUrl . '?page=form' . ($questionnaireId !== '' ? '&qid=' . rawurlencode($questionnaireId) : '');
    $data = [
        'order_id' => (string)$orderId,
        'customer_phone' => normalize_russian_phone($patient['phone'] ?? ''),
        'customer_email' => trim((string)($patient['email'] ?? '')),
        'customer_extra' => 'Оплата анализа анкеты здоровья',
        'do' => 'pay',
        'urlReturn' => $formUrl . '&payment=error',
        'urlSuccess' => $formUrl . '&payment=success',
        'currency' => 'rub',
        'order_sum' => (string)($price * PRODAMUS_PRODUCT_QUANTITY),
        'products' => [
            [
                'name' => PRODAMUS_PRODUCT_NAME,
                'price' => (string)$price,
                'quantity' => (string)PRODAMUS_PRODUCT_QUANTITY,
            ],
        ],
        '_param_shop_id' => PRODAMUS_SHOP_ID,
        '_param_response_id' => (string)$orderId,
    ];

    return rtrim(PRODAMUS_FORM_URL, '/') . '/?' . http_build_query($data);
}

function response_full_name($patient) {
    $parts = array_filter([
        trim((string)($patient['surname'] ?? '')),
        trim((string)($patient['name'] ?? '')),
        trim((string)($patient['patronymic'] ?? '')),
    ], 'strlen');
    return $parts ? implode(' ', $parts) : 'Пациент без имени';
}



function patient_field_label($key) {
    $labels = [
        'surname' => 'Фамилия',
        'name' => 'Имя',
        'patronymic' => 'Отчество',
        'dob' => 'Дата рождения',
        'phone' => 'Телефон',
        'email' => 'E-mail',
        'sex' => 'Пол',
        'height' => 'Рост, см',
        'weight' => 'Вес, кг',
        'waist' => 'Обхват талии, см',
        'filled_at' => 'Дата заполнения',
    ];

    return $labels[(string)$key] ?? (string)$key;
}

function normalize_decimal_value($value) {
    $value = trim(str_replace(',', '.', (string)$value));
    if ($value === '' || !is_numeric($value)) {
        return null;
    }
    return (float)$value;
}

function patient_bmi($patient) {
    if (!is_array($patient)) {
        return null;
    }

    $heightCm = normalize_decimal_value($patient['height'] ?? '');
    $weightKg = normalize_decimal_value($patient['weight'] ?? '');

    if ($heightCm === null || $weightKg === null || $heightCm <= 0 || $weightKg <= 0) {
        return null;
    }

    $heightM = $heightCm / 100;
    return $weightKg / ($heightM * $heightM);
}

function bmi_category($bmi) {
    if ($bmi === null) {
        return '';
    }
    if ($bmi < 18.5) {
        return 'Дефицит массы тела';
    }
    if ($bmi <= 24.9) {
        return 'Нормальная масса тела';
    }
    if ($bmi <= 29.9) {
        return 'Избыточная масса тела («предожирение»)';
    }
    if ($bmi <= 34.9) {
        return 'Ожирение I степени';
    }
    if ($bmi <= 39.9) {
        return 'Ожирение II степени';
    }
    return 'Ожирение III степени (морбидное)';
}

function format_bmi($bmi) {
    if ($bmi === null) {
        return '—';
    }
    return number_format((float)$bmi, 1, ',', '');
}


function patient_waist_risk($patient) {
    if (!is_array($patient)) {
        return null;
    }

    $waistCm = normalize_decimal_value($patient['waist'] ?? '');
    $sex = trim((string)($patient['sex'] ?? ''));

    if ($waistCm === null || $waistCm <= 0 || $sex === '') {
        return null;
    }

    $threshold = null;
    if ($sex === 'Мужчина') {
        $threshold = 94.0;
    } elseif ($sex === 'Женщина') {
        $threshold = 80.0;
    }

    if ($threshold === null) {
        return null;
    }

    return [
        'is_high' => $waistCm > $threshold,
        'threshold' => $threshold,
    ];
}

function format_waist_risk($risk) {
    if (!is_array($risk)) {
        return '—';
    }

    $threshold = number_format((float)($risk['threshold'] ?? 0), 0, ',', '');
    if (!empty($risk['is_high'])) {
        return 'Высокий риск преждевременной смерти от болезней сердца, нарушений углеводного обмена и некоторых видов рака (порог > ' . $threshold . ' см)';
    }

    return 'Порог высокого риска не превышен (порог > ' . $threshold . ' см)';
}

function patient_age($dob) {
    $dob = trim((string)$dob);
    if ($dob === '') {
        return null;
    }
    try {
        $birth = new DateTimeImmutable($dob);
        $now = new DateTimeImmutable('today');
        return $birth->diff($now)->y;
    } catch (Throwable $e) {
        return null;
    }
}

function format_response_date($date) {
    $date = trim((string)$date);
    if ($date === '') {
        return '—';
    }
    try {
        return (new DateTimeImmutable($date))->format('d.m.Y, H:i');
    } catch (Throwable $e) {
        return $date;
    }
}


function format_response_date_only($date) {
    $date = trim((string)$date);
    if ($date === '') {
        return '—';
    }
    try {
        return (new DateTimeImmutable($date))->format('d.m.Y');
    } catch (Throwable $e) {
        return preg_replace('/[\sT].*$/', '', $date) ?: $date;
    }
}

function response_questionnaire_title($response) {
    $title = trim((string)($response['survey'] ?? ''));
    if ($title !== '') {
        return $title;
    }
    $questionnaireId = trim((string)($response['questionnaire_id'] ?? ''));
    if ($questionnaireId !== '') {
        $questionnaire = questionnaire_find($questionnaireId, false);
        if ($questionnaire && trim((string)($questionnaire['title'] ?? '')) !== '') {
            return (string)$questionnaire['title'];
        }
    }
    return 'Анкета здоровья';
}

function initials_for_patient($patient) {
    if (function_exists('mb_substr')) {
        $first = mb_substr((string)($patient['surname'] ?? ''), 0, 1, 'UTF-8');
        $second = mb_substr((string)($patient['name'] ?? ''), 0, 1, 'UTF-8');
        $initials = trim($first . $second);
        return $initials !== '' ? mb_strtoupper($initials, 'UTF-8') : 'П';
    }
    $initials = trim(substr((string)($patient['surname'] ?? ''), 0, 1) . substr((string)($patient['name'] ?? ''), 0, 1));
    return $initials !== '' ? strtoupper($initials) : 'П';
}

function ai_analysis_to_html($analysis) {
    if (!is_array($analysis)) {
        return '<p>ИИ-анализ пока не сформирован.</p>';
    }

    $html = '';
    $lists = [
        'likely_issues' => 'Ключевые наблюдения',
        'recommended_tests' => 'Рекомендуемые анализы и обследования',
        'specialists' => 'К кому обратиться',
        'red_flags' => 'Красные флаги',
        'next_steps' => 'Следующие шаги',
    ];
    foreach ($lists as $key => $title) {
        $items = $analysis[$key] ?? [];
        if (!is_array($items) || !$items) {
            continue;
        }
        $html .= '<h3>'.e($title).'</h3><ul>';
        foreach ($items as $item) {
            $html .= '<li>'.e($item).'</li>';
        }
        $html .= '</ul>';
    }
    return $html !== '' ? $html : '<p>ИИ-анализ пока не содержит данных.</p>';
}


function answer_has_value($value) {
    if (is_array($value)) {
        foreach ($value as $item) {
            if (answer_has_value($item)) {
                return true;
            }
        }
        return false;
    }
    return trim((string)$value) !== '';
}


function display_answer_value($value) {
    if (is_array($value)) {
        $parts = [];
        foreach ($value as $item) {
            $text = display_answer_value($item);
            if ($text !== '—') {
                $parts[] = $text;
            }
        }
        return $parts ? implode(', ', $parts) : '—';
    }

    $value = trim((string)$value);
    return $value !== '' ? $value : '—';
}

function response_has_medical_answers($answers) {
    if (!is_array($answers)) {
        return false;
    }
    foreach ($answers as $section) {
        if (!is_array($section)) {
            continue;
        }
        foreach (($section['answers'] ?? []) as $answer) {
            if (is_array($answer) && answer_has_value($answer['answer'] ?? '')) {
                return true;
            }
        }
    }
    return false;
}

function response_completion_stats($answers) {
    if (!is_array($answers)) {
        return ['filled' => 0, 'total' => 0, 'percent' => 0];
    }

    $filled = 0;
    $total = 0;
    foreach ($answers as $section) {
        if (!is_array($section)) {
            continue;
        }
        $sectionAnswers = $section['answers'] ?? [];
        if (!is_array($sectionAnswers)) {
            continue;
        }
        foreach ($sectionAnswers as $answer) {
            if (!is_array($answer)) {
                continue;
            }
            $total++;
            if (answer_has_value($answer['answer'] ?? '')) {
                $filled++;
            }
        }
    }

    return [
        'filled' => $filled,
        'total' => $total,
        'percent' => $total > 0 ? (int)round(($filled / $total) * 100) : 0,
    ];
}

function build_response_record($sections, $patient, $readableAnswers, $hints, $analysis = null, $analysisRaw = '') {
    $now = date('c');
    $completion = response_completion_stats($readableAnswers);
    return [
        'id' => date('YmdHis') . '-' . bin2hex(random_bytes(3)),
        'patient' => $patient,
        'survey' => $GLOBALS['current_response_survey_title'] ?? 'Анкета здоровья',
        'questionnaire_id' => $GLOBALS['current_response_questionnaire_id'] ?? null,
        'category' => '',
        'status' => $completion['filled'] > 0 ? 'in_work' : 'draft',
        'progress' => $completion['percent'],
        'filled_answers' => $completion['filled'],
        'total_answers' => $completion['total'],
        'created_at' => $now,
        'updated_at' => $now,
        'answers' => $readableAnswers,
        'hints' => $hints,
        'analysis' => $analysis,
        'analysis_raw' => $analysisRaw,
        'ai_answer_html' => is_array($analysis) ? ai_analysis_to_html($analysis) : '<p>ИИ-анализ пока не сформирован.</p>',
        'history' => [
            ['date' => $now, 'event' => 'Ответ пациента сохранён'],
        ],
    ];
}

function add_patient_response($record) {
    return upsert_patient_response($record) ? $record['id'] : false;
}

function update_patient_response($id, $callback) {
    $stmt = db()->prepare('SELECT * FROM patient_responses WHERE id=?');
    $stmt->execute([(string)$id]);
    $row = $stmt->fetch();
    if (!$row) return false;
    $response = response_from_row($row);
    $response = $callback($response);
    $response['updated_at'] = date('c');
    return upsert_patient_response($response);
}

function delete_patient_response($id) {
    $stmt = db()->prepare('DELETE FROM patient_responses WHERE id=?');
    $stmt->execute([(string)$id]);
    return $stmt->rowCount() > 0;
}

function upsert_billing_history_for_response($record) {
    $amount = (float)($record['billing_amount'] ?? 0);
    if ($amount <= 0) {
        return false;
    }
    $patient = is_array($record['patient'] ?? null) ? $record['patient'] : [];
    $stmt = db()->prepare('INSERT INTO billing_history (response_id, survey, patient_surname, patient_name, patient_patronymic, vsegpt_cost, billing_amount, vsegpt_usage_json, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE survey=VALUES(survey), patient_surname=VALUES(patient_surname), patient_name=VALUES(patient_name), patient_patronymic=VALUES(patient_patronymic), vsegpt_cost=VALUES(vsegpt_cost), billing_amount=VALUES(billing_amount), vsegpt_usage_json=VALUES(vsegpt_usage_json), updated_at=VALUES(updated_at)');
    return $stmt->execute([
        (string)($record['id'] ?? ''),
        $record['survey'] ?? null,
        $patient['surname'] ?? '',
        $patient['name'] ?? '',
        $patient['patronymic'] ?? '',
        (float)($record['vsegpt_cost'] ?? 0),
        $amount,
        $record['vsegpt_usage_json'] ?? '',
        db_date($record['created_at'] ?? null),
        db_date($record['updated_at'] ?? null),
    ]);
}

function find_patient_response($id = null) {
    $items = patient_response_items();
    if (!$items) {
        return null;
    }
    if ($id === null || $id === '') {
        return $items[0];
    }
    foreach ($items as $item) {
        if ((string)($item['id'] ?? '') === (string)$id) {
            return $item;
        }
    }
    return $items[0];
}

function sanitize_editor_html($html) {
    $html = (string)$html;
    $allowed = '<p><br><b><strong><i><em><u><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><div><span><table><thead><tbody><tfoot><tr><td><th>';
    return trim(strip_tags($html, $allowed));
}


function add_hint(&$hints, $hint) {
    $hint = trim((string)$hint);
    if ($hint !== '' && !in_array($hint, $hints, true)) {
        $hints[] = $hint;
    }
}

function q_text($name, $label, $extra = []) {
    return array_merge(['name' => $name, 'type' => 'text', 'label' => $label], $extra);
}

function q_textarea($name, $label, $extra = []) {
    return array_merge(['name' => $name, 'type' => 'textarea', 'label' => $label], $extra);
}

function q_number($name, $label, $extra = []) {
    return array_merge(['name' => $name, 'type' => 'number', 'label' => $label], $extra);
}

function q_date($name, $label, $extra = []) {
    return array_merge(['name' => $name, 'type' => 'date', 'label' => $label], $extra);
}

function q_radio($name, $label, $options, $extra = []) {
    return array_merge(['name' => $name, 'type' => 'radio', 'label' => $label, 'options' => $options], $extra);
}

function q_yesno($name, $label, $extra = []) {
    return array_merge(['name' => $name, 'type' => 'yesno', 'label' => $label], $extra);
}

function q_checklist($name, $label, $options, $extra = []) {
    return array_merge(['name' => $name, 'type' => 'checklist', 'label' => $label, 'options' => $options], $extra);
}


function normalize_hint_config($config) {
    if (!is_array($config)) {
        return ['questions' => []];
    }
    if (!isset($config['questions']) || !is_array($config['questions'])) {
        $config['questions'] = [];
    }
    foreach ($config['questions'] as $qName => $qConfig) {
        if (!is_array($qConfig)) {
            $qConfig = [];
        }
        $qConfig['question_hint'] = trim((string)($qConfig['question_hint'] ?? ''));
        if (!isset($qConfig['options']) || !is_array($qConfig['options'])) {
            $qConfig['options'] = [];
        }
        foreach ($qConfig['options'] as $option => $hint) {
            $qConfig['options'][$option] = trim((string)$hint);
        }
        $config['questions'][$qName] = $qConfig;
    }
    return $config;
}

function get_question_options($q) {
    $type = $q['type'] ?? 'text';
    if ($type === 'yesno') {
        return ['Да', 'Нет'];
    }
    if ($type === 'radio' || $type === 'checklist') {
        $options = [];
        foreach (($q['options'] ?? []) as $opt) {
            $options[] = is_array($opt) ? (string)($opt['label'] ?? '') : (string)$opt;
        }
        return array_values(array_filter($options, 'strlen'));
    }
    return [];
}

function default_hint_config($sections) {
    $config = ['questions' => []];

    foreach ($sections as $section) {
        foreach ($section['questions'] as $q) {
            $name = (string)$q['name'];
            $qConfig = [
                'question_hint' => trim((string)($q['hint'] ?? '')),
                'options' => [],
            ];

            $type = $q['type'] ?? 'text';
            if ($type === 'yesno') {
                $qConfig['options']['Да'] = trim((string)($q['hint_yes'] ?? ''));
                $qConfig['options']['Нет'] = trim((string)($q['hint_no'] ?? ''));
            }

            if (($type === 'radio' || $type === 'checklist') && !empty($q['option_hints']) && is_array($q['option_hints'])) {
                foreach ($q['option_hints'] as $option => $hint) {
                    $qConfig['options'][(string)$option] = trim((string)$hint);
                }
            }

            foreach (get_question_options($q) as $option) {
                if (!array_key_exists($option, $qConfig['options'])) {
                    $qConfig['options'][$option] = '';
                }
            }

            $config['questions'][$name] = $qConfig;
        }
    }

    return $config;
}

function load_hint_config($sections, $questionnaireId = null) {
    $config = default_hint_config($sections);
    if (!$questionnaireId) {
        return $config;
    }

    $stmt = db()->prepare('SELECT question_name, hint FROM questionnaire_question_hints WHERE questionnaire_id=?');
    $stmt->execute([(string)$questionnaireId]);
    foreach ($stmt->fetchAll() as $row) {
        $name = (string)$row['question_name'];
        if (isset($config['questions'][$name])) {
            $config['questions'][$name]['question_hint'] = trim((string)($row['hint'] ?? ''));
        }
    }

    $stmt = db()->prepare('SELECT question_name, option_value, hint FROM questionnaire_option_hints WHERE questionnaire_id=?');
    $stmt->execute([(string)$questionnaireId]);
    foreach ($stmt->fetchAll() as $row) {
        $name = (string)$row['question_name'];
        $option = (string)$row['option_value'];
        if (isset($config['questions'][$name]['options'][$option])) {
            $config['questions'][$name]['options'][$option] = trim((string)($row['hint'] ?? ''));
        }
    }

    return $config;
}

function save_hint_config($config, $questionnaireId = null) {
    if (!$questionnaireId) {
        return false;
    }
    $config = normalize_hint_config($config);
    $pdo = db();
    $pdo->prepare('DELETE FROM questionnaire_question_hints WHERE questionnaire_id=?')->execute([(string)$questionnaireId]);
    $pdo->prepare('DELETE FROM questionnaire_option_hints WHERE questionnaire_id=?')->execute([(string)$questionnaireId]);
    $insertQuestion = $pdo->prepare('INSERT INTO questionnaire_question_hints (questionnaire_id, question_name, hint) VALUES (?,?,?)');
    $insertOption = $pdo->prepare('INSERT INTO questionnaire_option_hints (questionnaire_id, question_name, option_value, hint) VALUES (?,?,?,?)');
    foreach ($config['questions'] as $name => $qConfig) {
        $insertQuestion->execute([(string)$questionnaireId, (string)$name, trim((string)($qConfig['question_hint'] ?? ''))]);
        foreach (($qConfig['options'] ?? []) as $option => $hint) {
            $insertOption->execute([(string)$questionnaireId, (string)$name, (string)$option, trim((string)$hint)]);
        }
    }
    $stmt = $pdo->prepare('UPDATE questionnaires SET updated_at=? WHERE id=? AND deleted_at IS NULL');
    return $stmt->execute([date('Y-m-d H:i:s'), (string)$questionnaireId]);
}

function hints_config_from_post($sections, $post) {
    $config = default_hint_config($sections);

    foreach ($config['questions'] as $name => $qConfig) {
        $config['questions'][$name]['question_hint'] = trim((string)($post['hint_question'][$name] ?? ''));
        foreach ($qConfig['options'] as $option => $_) {
            $config['questions'][$name]['options'][$option] = trim((string)($post['hint_option'][$name][$option] ?? ''));
        }
    }

    return $config;
}

function apply_hint_config($sections, $hintConfig) {
    $hintConfig = normalize_hint_config($hintConfig);

    foreach ($sections as &$section) {
        foreach ($section['questions'] as &$q) {
            $name = (string)$q['name'];
            if (empty($hintConfig['questions'][$name])) {
                continue;
            }

            $qConfig = $hintConfig['questions'][$name];
            $q['hint'] = trim((string)($qConfig['question_hint'] ?? ''));
            $type = $q['type'] ?? 'text';

            if ($type === 'yesno') {
                $q['hint_yes'] = trim((string)($qConfig['options']['Да'] ?? ''));
                $q['hint_no'] = trim((string)($qConfig['options']['Нет'] ?? ''));
            }

            if ($type === 'radio' || $type === 'checklist') {
                $q['option_hints'] = [];
                foreach (($qConfig['options'] ?? []) as $option => $hint) {
                    $hint = trim((string)$hint);
                    if ($hint !== '') {
                        $q['option_hints'][(string)$option] = $hint;
                    }
                }
            }
        }
        unset($q);
    }
    unset($section);

    return $sections;
}


function hint_text_length($text) {
    return function_exists('mb_strlen') ? mb_strlen((string)$text, 'UTF-8') : strlen((string)$text);
}

function section_clean_title($title) {
    $title = preg_replace('/^БЛОК\s+\d+\.\s*/u', '', (string)$title);
    return trim((string)$title);
}

function section_meta($index, $section) {
    return [
        'title' => section_clean_title($section['title'] ?? ('Блок '.$index)),
        'icon' => section_icon_svg($index),
    ];
}

function icon_svg($paths, $class = 'icon-svg') {
    return '<svg class="'.e($class).'" viewBox="0 0 24 24" fill="none" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">'.$paths.'</svg>';
}

function section_icon_svg($index) {
    $icons = [
        1 => '<path d="M12 21s7-4.7 7-11a7 7 0 1 0-14 0c0 6.3 7 11 7 11Z"/><path d="M12 13a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/>',
        2 => '<path d="M20.8 5.9a5.1 5.1 0 0 0-7.2 0L12 7.5l-1.6-1.6a5.1 5.1 0 0 0-7.2 7.2L12 22l8.8-8.9a5.1 5.1 0 0 0 0-7.2Z"/>',
        3 => '<path d="M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z"/><path d="M12 17a5 5 0 1 0 0-10 5 5 0 0 0 0 10Z"/><path d="m15.5 8.5 3-3M18.5 5.5h-3v3"/>',
        4 => '<path d="M8 4h8l1.5 2H20v16H4V6h2.5L8 4Z"/><path d="M8 11h8M8 15h8M8 19h5"/>',
        5 => '<path d="M9 4a4 4 0 0 0-4 4v8a4 4 0 0 0 4 4M15 4a4 4 0 0 1 4 4v8a4 4 0 0 1-4 4"/><path d="M9 4v16M15 4v16M9 9H6M18 9h-3M9 14H6M18 14h-3M12 7v10"/>',
        6 => '<path d="M8 3c5 2 8 7 8 12a6 6 0 0 1-12 0c0-4 4-5 4-12Z"/><path d="M12 4c-1 4 3 5 3 10a4 4 0 0 1-4 4"/>',
        7 => '<path d="M11 12V4a3 3 0 0 0-6 0v10a5 5 0 0 0 5 5h1"/><path d="M13 12V4a3 3 0 0 1 6 0v10a5 5 0 0 1-5 5h-1"/><path d="M11 12c-3 0-5 2-5 5M13 12c3 0 5 2 5 5"/>',
        8 => '<path d="M12 22a7 7 0 0 0 7-7c0-5-7-13-7-13S5 10 5 15a7 7 0 0 0 7 7Z"/><path d="M9 15a3 3 0 0 0 3 3"/>',
        9 => '<path d="M20 15.5A8.5 8.5 0 0 1 8.5 4 8.5 8.5 0 1 0 20 15.5Z"/>',
        10 => '<path d="M12 21s-7-4-7-10a7 7 0 0 1 14 0c0 6-7 10-7 10Z"/><path d="M8 11c2-3 6-3 8 0M9 15h6"/>',
        11 => '<path d="M12 2v20M5 9h14M7 16h10"/><path d="M6 6l12 12M18 6 6 18"/>',
        12 => '<path d="M14 2 4 14l6 1-1 7 11-13-6-1 0-6Z"/>',
        13 => '<path d="M12 3v18M5 8c5 0 7 4 7 8-5 0-7-4-7-8ZM19 8c-5 0-7 4-7 8 5 0 7-4 7-8Z"/>',
        14 => '<path d="M10 21H7a4 4 0 0 1-4-4V7a4 4 0 0 1 4-4h3"/><path d="M14 3h3a4 4 0 0 1 4 4v10a4 4 0 0 1-4 4h-3"/><path d="M8 12h8M12 8v8"/>',
        15 => '<path d="M4 7h16v13H4z"/><path d="M8 7V4h8v3M8 12h8M8 16h5"/>',
        16 => '<path d="M12 5a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z"/><path d="M5 21a7 7 0 0 1 14 0M4 8a2 2 0 1 0 0 4M20 8a2 2 0 1 1 0 4"/>',
        17 => '<path d="M3 21h18M5 21V8l7-5 7 5v13M9 21v-7h6v7"/>',
        18 => '<path d="M12 13a5 5 0 1 0 0-10 5 5 0 0 0 0 10ZM12 13v8M8 17h8"/>',
        19 => '<path d="M10 14a6 6 0 1 1 4 0v7h-4v-7Z"/><path d="M8 21h8"/>',
    ];

    return icon_svg($icons[$index] ?? '<path d="M12 5v14M5 12h14"/>');
}

function hero_logo_svg() {
    return '<img class="app-logo-img" src="logo11.png" alt="">';
}

function render_section_card($section, $index) {
    $meta = section_meta($index, $section);
    $sexAttrs = '';
    $classes = 'section-card';
    if (!empty($section['sex'])) {
        $classes .= ' sex-hide sex-section';
        $sexAttrs = ' data-sex-section="'.e($section['sex']).'"';
    }

    $html = '<section class="'.e($classes).'"'.$sexAttrs.'>';
    $html .= '<aside class="section-aside">';
    $html .= '<div class="section-number">'.e($index).'</div>';
    $html .= '<div><h2>'.e($meta['title']).'</h2></div>';
    $html .= '</aside>';
    $html .= '<div class="section-content">';
    foreach ($section['questions'] as $q) {
        $html .= render_question($q);
    }
    $html .= '</div>';
    $html .= '</section>';

    return $html;
}

function render_hints_modal($sections, $hintConfig) {
    $hintConfig = normalize_hint_config($hintConfig);
    $html = '<div class="modal-backdrop" id="aiHintsModal" aria-hidden="true">';
    $html .= '<div class="modal" role="dialog" aria-modal="true" aria-labelledby="aiHintsTitle">';
    $html .= '<div class="modal-head"><div><h2 id="aiHintsTitle">Редактирование ИИ-подсказок (hints)</h2><p>Укажите, что должен учитывать ИИ при анализе вопросов и ответов анкеты.</p></div><button type="button" class="modal-close" id="closeAiHints" aria-label="Закрыть">×</button></div>';
    $html .= '<form id="aiHintsForm" class="modal-form">';
    $html .= '<input type="hidden" name="action" value="save_hints">';
    $html .= '<div class="modal-layout">';
    $html .= '<nav class="hint-nav" aria-label="Разделы и вопросы подсказок ИИ">';
    $html .= '<div class="hint-nav-title"><span>Оглавление</span><strong>Разделы и вопросы</strong></div>';

    $sectionNumber = 2;
    foreach ($sections as $section) {
        $meta = section_meta($sectionNumber, $section);
        $active = $sectionNumber === 2 ? ' is-active' : '';
        $html .= '<div class="hint-nav-block'.$active.'">';
        $html .= '<a class="hint-nav-item" href="#hint-section-'.$sectionNumber.'"><span>'.e($sectionNumber).'</span><b>'.e($meta['title']).'</b></a>';
        if (!empty($section['questions']) && is_array($section['questions'])) {
            $html .= '<div class="hint-nav-questions">';
            foreach ($section['questions'] as $navQuestion) {
                $questionName = (string)($navQuestion['name'] ?? '');
                $questionLabel = (string)($navQuestion['label'] ?? 'Вопрос');
                $html .= '<a href="#hint-question-'.e($questionName).'">'.e($questionLabel).'</a>';
            }
            $html .= '</div>';
        }
        $html .= '</div>';
        $sectionNumber++;
    }
    $html .= '</nav>';
    $html .= '<div class="hint-editor">';

    $sectionNumber = 2;
    foreach ($sections as $section) {
        $meta = section_meta($sectionNumber, $section);
        $html .= '<section class="hint-section" id="hint-section-'.$sectionNumber.'">';
        $html .= '<h3>'.e($sectionNumber).'. '.e($meta['title']).'</h3>';
        foreach ($section['questions'] as $q) {
            $name = (string)$q['name'];
            $qConfig = $hintConfig['questions'][$name] ?? ['question_hint' => '', 'options' => []];
            $questionHint = (string)($qConfig['question_hint'] ?? '');
            $html .= '<article class="hint-question" id="hint-question-'.e($name).'">';
            $html .= '<div class="hint-question-title"><span>Вопрос</span><h4>'.e((string)($q['label'] ?? 'Вопрос')).'</h4></div>';
            $html .= '<div class="hint-panel hint-panel-question">';
            $html .= '<div class="hint-panel-title">Подсказка для ИИ по вопросу</div>';
            $html .= '<div class="hint-help">Что важно учитывать ИИ при анализе этого вопроса в целом</div>';
            $html .= '<div class="hint-textarea-wrap">';
            $html .= '<textarea class="field hint-field" maxlength="500" name="hint_question['.e($name).']" rows="4" placeholder="Например: какие обследования или правила добавить, если на вопрос есть ответ">'.e($questionHint).'</textarea>';
            $html .= '<span class="hint-counter">'.hint_text_length($questionHint).'/500</span>';
            $html .= '</div></div>';

            $options = get_question_options($q);
            if ($options) {
                $html .= '<div class="hint-panel">';
                $html .= '<div class="hint-panel-title">Варианты ответа и подсказки для ИИ</div>';
                $html .= '<div class="hint-help">Укажите, как ИИ должен интерпретировать каждый выбранный вариант ответа</div>';
                $html .= '<div class="hint-option-list">';
                foreach ($options as $option) {
                    $value = (string)($qConfig['options'][$option] ?? '');
                    $html .= '<div class="hint-option-row">';
                    $html .= '<span class="hint-radio" aria-hidden="true"></span>';
                    $html .= '<input class="hint-option-name" type="text" value="'.e($option).'" readonly>';
                    $html .= '<div class="hint-textarea-wrap">';
                    $html .= '<textarea class="field hint-field" maxlength="500" name="hint_option['.e($name).']['.e($option).']" rows="3" placeholder="Что ИИ должен сделать при выборе этого варианта">'.e($value).'</textarea>';
                    $html .= '<span class="hint-counter">'.hint_text_length($value).'/500</span>';
                    $html .= '</div>';
                    $html .= '</div>';
                }
                $html .= '</div></div>';
            }
            $html .= '</article>';
        }
        $html .= '</section>';
        $sectionNumber++;
    }

    $html .= '</div></div>';
    $html .= '<div class="modal-actions"><button type="reset" class="btn btn-reset">↶ Сбросить изменения</button><span id="aiHintsStatus" class="hint-status" aria-live="polite"></span><button type="button" class="btn btn-secondary" id="cancelAiHints">Отмена</button><button type="submit" class="btn" id="saveAiHints">Сохранить</button></div>';
    $html .= '</form></div></div>';
    return $html;
}


function render_hints_page($sections, $hintConfig) {
    $html = render_hints_modal($sections, $hintConfig);
    $html = str_replace('<div class="modal-backdrop" id="aiHintsModal" aria-hidden="true">', '<div class="settings-panel" id="aiHintsModal" aria-hidden="false">', $html);
    return $html;
}

function questionnaire_sections_static() {
    return [
        [
            'title' => 'БЛОК 2. САМООЦЕНКА ЗДОРОВЬЯ',
            'questions' => [
                q_radio('health_state', 'Как вы оцениваете состояние своего здоровья?', [
                    'Хорошее', 'Удовлетворительное', 'Плохое'
                ]),
                q_radio('illness_frequency', 'Как часто вы болеете?', [
                    '1 раз в месяц',
                    '1 раз в 3 месяца',
                    '1 раз в 6 месяцев',
                    '1 раз в год',
                    'Другой вариант',
                ], [
                    'other_name' => 'illness_frequency_other',
                    'option_hints' => [
                        '1 раз в месяц' => 'Клинический анализ крови.',
                        '1 раз в 3 месяца' => 'Клинический анализ крови.',
                    ],
                ]),
                q_textarea('healthy_meaning', 'Что для вас значит "быть здоровым"?'),
            ],
        ],
        [
            'title' => 'БЛОК 3. ЦЕЛЬ ОБРАЩЕНИЯ',
            'questions' => [
                q_radio('primary_goal', 'Какова основная цель вашего визита?', [
                    'Повысить энергию и работоспособность',
                    'Снизить вес / скорректировать состав тела',
                    'Улучшить пищеварение',
                    'Нормализовать сон',
                    'Подготовка к беременности',
                    'Anti-age / продление молодости',
                    'Снизить уровень стресса и тревоги',
                    'Разобраться с хроническими симптомами',
                    'Другое',
                ], [
                    'other_name' => 'primary_goal_other',
                    'option_hints' => [
                        'Повысить энергию и работоспособность' => 'Функция щитовидной железы, железо, ферритин, ОАК.',
                        'Снизить вес / скорректировать состав тела' => 'ОАК, ИР-комплекс, БХ, функция ЩЖ, холестерин + фракции, гомоцистеин, ИМХ.',
                        'Улучшить пищеварение' => 'ОАК, копрограмма, УЗИ ОБП, ИМХ.',
                        'Нормализовать сон' => 'Кортизол, ОАК.',
                        'Подготовка к беременности' => 'Гормоны женские (26), фолиевая, железо, ферритин, В12, БХ, ЩЖ.',
                        'Anti-age / продление молодости' => 'Гормоны женские (47), гомоцистеин.',
                        'Снизить уровень стресса и тревоги' => 'Кортизол.',
                    ],
                ]),
            ],
        ],
        [
            'title' => 'БЛОК 4. ЖАЛОБЫ (ОБЩИЕ)',
            'questions' => [
                q_yesno('fatigue', 'Слабость, быстрая утомляемость', [
                    'hint_yes' => 'ЩЖ, железо, ферритин, ОАК, БХ.'
                ]),
                q_yesno('headache', 'Головная боль', [
                    'hint_yes' => 'ОАК, железо, ферритин, БХ, УЗИ БЦА, невролог.'
                ]),
                q_yesno('dizziness', 'Головокружения', [
                    'hint_yes' => 'ОАК, железо, ферритин, БХ, УЗИ БЦА, невролог.'
                ]),
                q_yesno('temp', 'Беспричинное повышение температуры', [
                    'hint_yes' => 'ОАК, СРБ.'
                ]),
                q_yesno('chest_pain', 'Ощущение давления или боли в грудной клетке', [
                    'hint_yes' => 'ОАК, ЭКГ, КФК-МВ, консультация кардиолога, гемостаз, гомоцистеин, холестерин + фракции.'
                ]),
                q_radio('headache_frequency', 'Уточнение по головной боли: частота', [
                    'редко', '1-2 раза в неделю', 'почти каждый день'
                ]),
                q_radio('headache_character', 'Уточнение по головной боли: характер', [
                    'давящая', 'пульсирующая', 'стягивающая'
                ]),
            ],
        ],
        [
            'title' => 'БЛОК 5. КОГНИТИВНЫЕ И СЕНСОРНЫЕ НАРУШЕНИЯ',
            'questions' => [
                q_yesno('memory', 'Ухудшение памяти', [
                    'hint_yes' => 'ОАК, железо, ферритин, общий белок, В9, В12.'
                ]),
                q_yesno('vision', 'Снижение зрения', [
                    'hint_yes' => 'Консультация офтальмолога.'
                ]),
                q_yesno('hearing', 'Снижение слуха'),
                q_yesno('concentration', 'Снижение концентрации внимания', [
                    'hint_yes' => 'ОАК, железо, ферритин, общий белок, В9, В12.'
                ]),
                q_text('other_sensory', 'Другое'),
            ],
        ],
        [
            'title' => 'БЛОК 6. ПИЩЕВАРИТЕЛЬНАЯ СИСТЕМА',
            'questions' => [
                q_radio('appetite', 'Аппетит', ['Снижен', 'Повышен', 'Норма']),
                q_yesno('heaviness_after_meal', 'Ощущение тяжести в верхней части живота после еды', [
                    'hint_yes' => 'УЗИ ОБП, ОАК, гастропанель.'
                ]),
                q_radio('abdominal_pain', 'Боли в животе', ['До еды', 'После еды', 'Не бывают'], [
                    'option_hints' => [
                        'До еды' => 'УЗИ ОБП, ОАК, гастропанель.',
                        'После еды' => 'УЗИ ОБП, ОАК, гастропанель.',
                    ],
                ]),
                q_radio('digestive_symptom', 'Симптомы', [
                    'Тошнота', 'Рвота', 'Изжога', 'Затруднение при глотании', 'Нет'
                ], [
                    'option_hints' => [
                        'Тошнота' => 'УЗИ ОБП, ОАК, гастропанель.',
                        'Рвота' => 'УЗИ ОБП, ОАК, гастропанель.',
                        'Изжога' => 'УЗИ ОБП, ОАК, гастропанель.',
                    ],
                ]),
                q_radio('stool_issue', 'Расстройство стула', [
                    'Запор', 'Жидкий стул', 'Чередование запора и поноса', 'Нет'
                ], [
                    'option_hints' => [
                        'Запор' => 'Копрограмма, УЗИ ОБП, ХМС по Осипову, кал на ув.',
                        'Жидкий стул' => 'Копрограмма, УЗИ ОБП, ХМС по Осипову, кал на ув.',
                        'Чередование запора и поноса' => 'Копрограмма, УЗИ ОБП, ХМС по Осипову, кал на ув.',
                    ],
                ]),
                q_yesno('bloating', 'Метеоризм / вздутие', [
                    'hint_yes' => 'Копрограмма, УЗИ ОБП, ХМС по Осипову, кал на ув.'
                ]),
            ],
        ],
        [
            'title' => 'БЛОК 7. ДЫХАТЕЛЬНАЯ СИСТЕМА',
            'questions' => [
                q_yesno('nasal_breathing', 'Затруднённое дыхание через нос, насморк', [
                    'hint_yes' => 'ОАК, YgE общ, ЭКБ, мазок из носа и зева на микрофлору, риноцитограмма.'
                ]),
                q_yesno('asthma_attack', 'Приступы удушья (затруднён вдох / выдох)', [
                    'hint_yes' => 'ОАК, YgE общ, ЭКБ.'
                ]),
                q_yesno('chronic_cough', 'Хронический кашель', [
                    'hint_yes' => 'ОАК, YgE общ, ЭКБ.'
                ]),
            ],
        ],
        [
            'title' => 'БЛОК 8. ПИТЬЕВОЙ РЕЖИМ И КОЖА',
            'questions' => [
                q_radio('water_volume', 'Объём жидкости в день', [
                    '2 стакана воды', '1 литр', 'Больше 1 литра', 'Не знаю'
                ]),
                q_yesno('dry_mouth', 'Постоянная сухость во рту', [
                    'hint_yes' => 'Комплекс ИР, ОАК.'
                ]),
                q_yesno('thirst', 'Беспричинная жажда', [
                    'hint_yes' => 'Комплекс ИР, ОАК.'
                ]),
                q_yesno('itchy_skin', 'Зуд кожи', [
                    'hint_yes' => 'ОАК, БХ, УЗИ ОБП.'
                ]),
                q_yesno('dry_skin', 'Сухость кожи / шелушение', [
                    'hint_yes' => 'ОАК, БХ, УЗИ ОБП, комплекс ЩЖ.'
                ]),
            ],
        ],
        [
            'title' => 'БЛОК 9. СОН',
            'questions' => [
                q_radio('sleep_character', 'Характеристика сна', [
                    'Сплю 8 часов, не просыпаюсь',
                    'Просыпаюсь 2-3 раза за ночь',
                    'Просыпаюсь 1 раз за ночь',
                    'Сон спокойный, без сновидений',
                    'Сон беспокойный',
                    'Лучше сплю в дневные часы, чем ночью',
                    'Долго не могу заснуть',
                    'Засыпаю быстро',
                ], [
                    'option_hints' => [
                        'Просыпаюсь 2-3 раза за ночь' => 'Кортизол.',
                        'Сон беспокойный' => 'Кортизол.',
                        'Лучше сплю в дневные часы, чем ночью' => 'Кортизол.',
                        'Долго не могу заснуть' => 'Кортизол.',
                    ],
                ]),
                q_radio('rested_after_sleep', 'Чувствуете ли вы себя отдохнувшим после сна?', [
                    'Да', 'Нет', 'Иногда'
                ]),
            ],
        ],
        [
            'title' => 'БЛОК 10. ЭМОЦИОНАЛЬНОЕ СОСТОЯНИЕ И ПРИВЫЧКИ',
            'questions' => [
                q_yesno('irritability', 'Раздражительность, плохое настроение'),
                q_checklist('stress_coping', 'Как справляетесь со стрессом', [
                    'Курю', 'Пью алкоголь', 'Иду есть', 'Физическая активность', 'Другое'
                ], [
                    'other_name' => 'stress_coping_other',
                ]),
                q_yesno('sport', 'Занимаетесь спортом'),
                q_number('anxiety_score', 'Оцените уровень тревоги за последние 2 недели (0-10)', [
                    'min' => 0,
                    'max' => 10,
                ]),
            ],
        ],
        [
            'title' => 'БЛОК 11. БОЛИ',
            'questions' => [
                q_yesno('urination_pain', 'Боли при мочеиспускании', [
                    'hint_yes' => 'ОАМ, посев мочи на м/фл, ОАК, УЗИ почек, надпочечников, мочеточников, мочевого пузыря.'
                ]),
                q_yesno('joint_pain', 'Болезненные ощущения в суставах', [
                    'hint_yes' => 'ОАК, БХ, комплекс ДСТ.'
                ]),
                q_yesno('back_pain', 'Боли в спине', [
                    'hint_yes' => 'ОАК, БХ, комплекс ДСТ.'
                ]),
                q_text('pain_location', 'Если да, локализация (шея / грудной отдел / поясница / крестец)'),
                q_text('pain_character', 'Если да, характер (колющая / тянущая / острая / ноющая)'),
                q_text('pain_intensity', 'Если да, интенсивность (сильная / терпимая / слабая)'),
            ],
        ],
        [
            'title' => 'БЛОК 12. ТРАВМЫ И ОПЕРАЦИИ',
            'questions' => [
                q_textarea('traumas', 'Были ли травмы в течение жизни (начиная с раннего возраста)?'),
                q_yesno('operations', 'Были ли операции?'),
                q_textarea('operations_which', 'Если да, какие'),
            ],
        ],
        [
            'title' => 'БЛОК 13. АЛЛЕРГИИ',
            'questions' => [
                q_yesno('allergies', 'Есть ли аллергические реакции?', [
                    'hint_yes' => 'ОАК, ЭКБ, YgE общ, риноцитограмма.'
                ]),
                q_textarea('allergies_which', 'Если да, на что'),
            ],
        ],
        [
            'title' => 'БЛОК 14. ПРИЁМ ЛЕКАРСТВ И БАДОВ',
            'questions' => [
                q_textarea('meds_constant', 'Какие лекарственные препараты вы принимаете на постоянной основе?'),
                q_textarea('meds_courses', 'Какие лекарственные препараты вы принимаете курсами?'),
                q_yesno('supplements', 'Принимаете ли БАДы?'),
                q_textarea('supplements_which', 'Если да, какие'),
            ],
        ],
        [
            'title' => 'БЛОК 15. ИСТОРИЯ НАБЛЮДЕНИЯ У ВРАЧЕЙ',
            'questions' => [
                q_checklist('doctors_seen', 'Находитесь или находились на обследовании / диспансерном наблюдении / лечении у врачей следующих специальностей', [
                    'Аллерголог',
                    'Эндокринолог',
                    'Невролог',
                    'Гастроэнтеролог',
                    'Кардиолог',
                    'Гинеколог / уролог',
                    'Дерматолог',
                    'Психотерапевт / психиатр',
                    'Другое',
                ], [
                    'other_name' => 'doctors_seen_other',
                ]),
                q_textarea('diagnoses', 'Установленные диагнозы'),
            ],
        ],
        [
            'title' => 'БЛОК 16. НАСЛЕДСТВЕННОСТЬ',
            'questions' => [
                q_checklist('heredity', 'Были ли у кровных родственников (родители, бабушки, дедушки) следующие заболевания', [
                    'Гипертония / инсульт / инфаркт',
                    'Сахарный диабет',
                    'Онкология',
                    'Заболевания щитовидной железы',
                    'Аутоиммунные заболевания',
                    'Болезнь Альцгеймера / деменция',
                    'Не знаю',
                    'Другое',
                ], [
                    'other_name' => 'heredity_other',
                    'option_hints' => [
                        'Гипертония / инсульт / инфаркт' => 'ОАК, ЭКГ, КФК-МВ, гемостаз, холестерин + фракции, БХ, гомоцистеин.',
                        'Сахарный диабет' => 'Комплекс ИР + БХ.',
                        'Заболевания щитовидной железы' => 'Комплекс ЩЖ, ОАК.',
                        'Аутоиммунные заболевания' => 'Комплекс ЩЖ, ревматоидный фактор.',
                        'Болезнь Альцгеймера / деменция' => 'Комплекс ИР.',
                    ],
                ]),
            ],
        ],
        [
            'title' => 'БЛОК 17. ЖИЗНЕННЫЕ ФАКТОРЫ',
            'questions' => [
                q_radio('occupation', 'Род деятельности', [
                    'Сидячая работа',
                    'Физическая работа',
                    'Работа с химикатами / вредное производство',
                    'Медик / педагог / сфера обслуживания (высокий контакт с людьми)',
                    'Другое',
                ], [
                    'other_name' => 'occupation_other',
                ]),
                q_radio('living', 'Проживание', [
                    'Город', 'Пригород', 'Село', 'Экологически неблагоприятный район'
                ]),
            ],
        ],
        [
            'title' => 'БЛОК 18. ДЛЯ ЖЕНЩИН',
            'sex' => 'female',
            'questions' => [
                q_yesno('menstrual_issue', 'Нарушение менструального цикла', [
                    'hint_yes' => 'ОАК, гормональный комплекс, консультация гинеколога, УЗИ ОМТ.'
                ]),
                q_yesno('pregnancy_history', 'Беременность в анамнезе'),
                q_yesno('pregnancy_planning', 'Планирование беременности', [
                    'hint_yes' => 'ОАК, гормоны женские (26), фолиевая, железо, ферритин, В12, БХ, ЩЖ.'
                ]),
            ],
        ],
        [
            'title' => 'БЛОК 19. ДЛЯ МУЖЧИН',
            'sex' => 'male',
            'questions' => [
                q_yesno('libido_drop', 'Снижение либидо', [
                    'hint_yes' => 'Гормоны ст. муж, ОАК.'
                ]),
                q_yesno('erection_issue', 'Проблемы с эрекцией / мочеиспусканием', [
                    'hint_yes' => 'Гормоны ст. муж, ОАК.'
                ]),
            ],
        ],
    ];
}

function render_question($q) {
    $name = e($q['name']);
    $label = e($q['label']);
    $type = $q['type'] ?? 'text';
    $html = '<div class="question">';

    if ($type === 'yesno') {
        $html .= '<div class="question-label">'.$label.'</div>';
        $html .= '<div class="inline-options">';
        $html .= '<label><input type="radio" name="'.$name.'" value="Да"> Да</label>';
        $html .= '<label><input type="radio" name="'.$name.'" value="Нет"> Нет</label>';
        $html .= '</div>';
    } elseif ($type === 'radio') {
        $html .= '<div class="question-label">'.$label.'</div>';
        $html .= '<div class="inline-options">';
        foreach (($q['options'] ?? []) as $opt) {
            $optLabel = is_array($opt) ? ($opt['label'] ?? '') : $opt;
            $html .= '<label><input type="radio" name="'.$name.'" value="'.e($optLabel).'"> '.e($optLabel).'</label>';
        }
        $html .= '</div>';
        if (!empty($q['other_name'])) {
            $html .= '<input type="text" name="'.e($q['other_name']).'" class="field" placeholder="Если другой вариант — уточните">';
        }
    } elseif ($type === 'checklist') {
        $html .= '<div class="question-label">'.$label.'</div>';
        $html .= '<div class="checklist">';
        foreach (($q['options'] ?? []) as $opt) {
            $optLabel = is_array($opt) ? ($opt['label'] ?? '') : $opt;
            $html .= '<label><input type="checkbox" name="'.$name.'[]" value="'.e($optLabel).'"> '.e($optLabel).'</label>';
        }
        $html .= '</div>';
        if (!empty($q['other_name'])) {
            $html .= '<input type="text" name="'.e($q['other_name']).'" class="field" placeholder="Если другое — уточните">';
        }
    } elseif ($type === 'select') {
        $html .= '<div class="question-label">'.$label.'</div>';
        $html .= '<select name="'.$name.'" class="field"><option value="">Выберите вариант</option>';
        foreach (($q['options'] ?? []) as $opt) { $optLabel = is_array($opt) ? ($opt['label'] ?? '') : $opt; $html .= '<option value="'.e($optLabel).'">'.e($optLabel).'</option>'; }
        $html .= '</select>';
    } elseif ($type === 'textarea') {
        $html .= '<div class="question-label">'.$label.'</div>';
        $html .= '<textarea name="'.$name.'" class="field" rows="3" placeholder="Введите ответ"></textarea>';
    } elseif ($type === 'number') {
        $html .= '<div class="question-label">'.$label.'</div>';
        $min = isset($q['min']) ? ' min="'.e($q['min']).'"' : '';
        $max = isset($q['max']) ? ' max="'.e($q['max']).'"' : '';
        $step = isset($q['step']) ? ' step="'.e($q['step']).'"' : '';
        $html .= '<input type="number" name="'.$name.'" class="field"'.$min.$max.$step.' placeholder="0-10">';
    } elseif ($type === 'date') {
        $html .= '<div class="question-label">'.$label.'</div>';
        $html .= '<input type="date" name="'.$name.'" class="field">';
    } else {
        $html .= '<div class="question-label">'.$label.'</div>';
        $html .= '<input type="text" name="'.$name.'" class="field" placeholder="Введите ответ">';
    }

    $html .= '</div>';
    return $html;
}

function read_question_answer($q, $post, &$hints) {
    $name = $q['name'];
    $type = $q['type'] ?? 'text';
    $answer = '';

    if ($type === 'checklist') {
        $vals = $post[$name] ?? [];
        if (!is_array($vals)) {
            $vals = [];
        }
        $vals = array_values(array_filter(array_map('trim', $vals), 'strlen'));
        $answer = $vals;

        if ($vals && !empty($q['hint'])) {
            add_hint($hints, $q['hint']);
        }

        if (!empty($q['option_hints']) && is_array($q['option_hints'])) {
            foreach ($vals as $v) {
                if (isset($q['option_hints'][$v])) {
                    add_hint($hints, $q['option_hints'][$v]);
                }
            }
        }
    } else {
        $answer = trim((string)($post[$name] ?? ''));

        if ($answer !== '' && !empty($q['hint'])) {
            add_hint($hints, $q['hint']);
        }

        if ($type === 'yesno') {
            if ($answer === 'Да' && !empty($q['hint_yes'])) {
                add_hint($hints, $q['hint_yes']);
            }
            if ($answer === 'Нет' && !empty($q['hint_no'])) {
                add_hint($hints, $q['hint_no']);
            }
        }

        if ($type === 'radio' && !empty($q['option_hints']) && isset($q['option_hints'][$answer])) {
            add_hint($hints, $q['option_hints'][$answer]);
        }

        if (!empty($q['other_name'])) {
            $other = trim((string)($post[$q['other_name']] ?? ''));
            if ($other !== '') {
                $answer = $answer !== '' ? ($answer . ' | ' . $other) : $other;
            }
        }
    }

    return $answer;
}

function build_ai_payload($sections, $post) {
    $readable = [];
    $hints = [];

    foreach ($sections as $section) {
        if (!empty($section['sex']) && !empty($post['sex'])) {
            $sex = $post['sex'] === 'Женщина' ? 'female' : 'male';
            if ($section['sex'] !== $sex) {
                continue;
            }
        }

        $sectionData = [
            'section' => $section['title'],
            'answers' => [],
        ];

        foreach ($section['questions'] as $q) {
            $answer = read_question_answer($q, $post, $hints);
            $sectionData['answers'][] = [
                'question' => $q['label'],
                'answer' => $answer,
            ];
        }

        $readable[] = $sectionData;
    }

    $hints = array_values(array_unique($hints));

    return [$readable, $hints];
}

function call_vsegpt($messages) {
    if (trim(VSEGPT_API_KEY) === '' || VSEGPT_API_KEY === 'PUT_YOUR_VSEGPT_API_KEY_HERE') {
        return ['ok' => false, 'error' => 'Укажите VSEGPT_API_KEY в файле `index.php`.'];
    }

    $payload = [
        'model' => VSEGPT_MODEL,
        'messages' => $messages,
        'temperature' => 0.2,
        'max_tokens' => 1400,
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (function_exists('curl_init')) {
        $ch = curl_init(VSEGPT_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . VSEGPT_API_KEY,
            ],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_TIMEOUT => 90,
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'error' => 'Ошибка cURL: ' . $err];
        }

        return ['ok' => true, 'http' => $http, 'raw' => $raw];
    }

    $opts = [
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Content-Type: application/json',
                'Authorization: Bearer ' . VSEGPT_API_KEY,
            ]),
            'content' => $json,
            'timeout' => 90,
        ],
    ];

    $context = stream_context_create($opts);
    $raw = @file_get_contents(VSEGPT_API_URL, false, $context);

    if ($raw === false) {
        return ['ok' => false, 'error' => 'Не удалось выполнить запрос к VSEGPT.'];
    }

    return ['ok' => true, 'http' => 200, 'raw' => $raw];
}

function vsegpt_cost_from_response($decoded) {
    if (!is_array($decoded)) return 0.0;
    $paths = [
        ['usage', 'cost'], ['usage', 'total_cost'], ['usage', 'price'], ['usage', 'total_price'],
        ['cost'], ['total_cost'], ['price'], ['billing', 'cost'], ['billing', 'amount'],
    ];
    foreach ($paths as $path) {
        $value = $decoded;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                $value = null;
                break;
            }
            $value = $value[$key];
        }
        if (is_numeric($value)) return (float)$value;
    }
    return 0.0;
}

function format_money($value) {
    return number_format((float)$value, 4, '.', ' ') . ' ₽';
}

function billing_period_bounds() {
    $from = trim((string)($_GET['from'] ?? date('Y-m-01')));
    $to = trim((string)($_GET['to'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = date('Y-m-d');
    if ($from > $to) { $tmp = $from; $from = $to; $to = $tmp; }
    return [$from, $to, $from . ' 00:00:00', $to . ' 23:59:59'];
}

function billing_items($fromDateTime, $toDateTime) {
    $stmt = db()->prepare('SELECT bh.response_id AS id, bh.survey, bh.patient_surname, bh.patient_name, bh.patient_patronymic, bh.created_at, bh.updated_at, bh.vsegpt_cost, bh.billing_amount, bh.vsegpt_usage_json, pr.id AS response_exists FROM billing_history bh LEFT JOIN patient_responses pr ON pr.id = bh.response_id WHERE bh.billing_amount > 0 AND bh.updated_at BETWEEN ? AND ? ORDER BY bh.updated_at DESC');
    $stmt->execute([$fromDateTime, $toDateTime]);
    return $stmt->fetchAll();
}

function extract_json($text) {
    $text = trim((string)$text);
    $text = preg_replace('/^```(?:json)?/i', '', $text);
    $text = preg_replace('/```$/', '', $text);
    $text = trim($text);

    $start = strpos($text, '{');
    $end = strrpos($text, '}');

    if ($start !== false && $end !== false && $end > $start) {
        $text = substr($text, $start, $end - $start + 1);
    }

    return trim($text);
}


function response_plain_text($response) {
    $patient = is_array($response['patient'] ?? null) ? $response['patient'] : [];
    $lines = ['Анкета: ' . ($response['survey'] ?? 'Анкета здоровья'), 'Пациент: ' . response_full_name($patient), ''];
    foreach ($patient as $key => $value) {
        if (answer_has_value($value)) {
            $lines[] = patient_field_label($key) . ': ' . display_answer_value($value);
        }
    }
    foreach (($response['answers'] ?? []) as $section) {
        if (!is_array($section)) continue;
        $lines[] = '';
        $lines[] = section_clean_title($section['section'] ?? 'Раздел');
        foreach (($section['answers'] ?? []) as $answer) {
            if (!is_array($answer)) continue;
            $lines[] = ($answer['question'] ?? 'Вопрос') . ': ' . display_answer_value($answer['answer'] ?? '');
        }
    }
    return implode("\n", $lines);
}


function response_html_to_text($html) {
    $html = (string)$html;
    $html = preg_replace('/<\s*br\s*\/?>/iu', "\n", $html);
    $html = preg_replace('/<\s*\/\s*(p|div|li|h[1-6]|tr)\s*>/iu', "\n", $html);
    $text = html_entity_decode(trim(strip_tags($html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/\n{3,}/u", "\n\n", $text);
    return trim($text);
}

function response_pdf_node_text($node) {
    $parts = [];
    foreach ($node->childNodes ?? [] as $child) {
        if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
            $parts[] = $child->nodeValue;
            continue;
        }
        if ($child->nodeType !== XML_ELEMENT_NODE) continue;
        $tag = mb_strtolower($child->nodeName, 'UTF-8');
        if (in_array($tag, ['ul', 'ol'], true)) continue;
        if ($tag === 'br') {
            $parts[] = "\n";
            continue;
        }
        $childText = response_pdf_node_text($child);
        if (in_array($tag, ['p', 'div', 'section', 'article', 'blockquote', 'tr', 'table', 'thead', 'tbody', 'tfoot', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
            $parts[] = "\n" . $childText . "\n";
        } elseif (in_array($tag, ['td', 'th'], true)) {
            $parts[] = $childText . ' ';
        } else {
            $parts[] = $childText;
        }
    }
    $text = html_entity_decode(implode('', $parts), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t]+/u', ' ', $text);
    $text = preg_replace('/\h*\R\h*/u', "\n", $text);
    $text = preg_replace('/\n{3,}/u', "\n\n", $text);
    return trim($text);
}

function response_pdf_list_blocks($listNode, $level = 0) {
    $blocks = [];
    $tag = mb_strtolower($listNode->nodeName, 'UTF-8');
    $isOrdered = $tag === 'ol';
    $number = 1;
    foreach ($listNode->childNodes ?? [] as $item) {
        if ($item->nodeType !== XML_ELEMENT_NODE || mb_strtolower($item->nodeName, 'UTF-8') !== 'li') continue;
        $text = response_pdf_node_text($item);
        if ($text !== '') {
            $prefix = $isOrdered ? ($number . '. ') : '• ';
            $blocks[] = ['text' => str_repeat('  ', max(0, (int)$level)) . $prefix . $text, 'style' => 'paragraph'];
        }
        foreach ($item->childNodes ?? [] as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && in_array(mb_strtolower($child->nodeName, 'UTF-8'), ['ul', 'ol'], true)) {
                $blocks = array_merge($blocks, response_pdf_list_blocks($child, $level + 1));
            }
        }
        $number++;
    }
    return $blocks;
}

function response_pdf_dom_blocks($node) {
    $blocks = [];
    foreach ($node->childNodes ?? [] as $child) {
        if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
            $text = trim(preg_replace('/\s+/u', ' ', $child->nodeValue));
            if ($text !== '') $blocks[] = ['text' => $text, 'style' => 'paragraph'];
            continue;
        }
        if ($child->nodeType !== XML_ELEMENT_NODE) continue;
        $tag = mb_strtolower($child->nodeName, 'UTF-8');
        if (preg_match('/^h[1-6]$/u', $tag)) {
            $text = response_pdf_node_text($child);
            if ($text !== '') $blocks[] = ['text' => $text, 'style' => 'heading'];
            continue;
        }
        if (in_array($tag, ['ul', 'ol'], true)) {
            $blocks = array_merge($blocks, response_pdf_list_blocks($child));
            continue;
        }
        if (in_array($tag, ['p', 'div', 'section', 'article', 'blockquote', 'table', 'thead', 'tbody', 'tfoot', 'tr'], true)) {
            $text = response_pdf_node_text($child);
            if ($text !== '') $blocks[] = ['text' => $text, 'style' => 'paragraph'];
            foreach ($child->childNodes ?? [] as $nested) {
                if ($nested->nodeType === XML_ELEMENT_NODE && in_array(mb_strtolower($nested->nodeName, 'UTF-8'), ['ul', 'ol'], true)) {
                    $blocks = array_merge($blocks, response_pdf_list_blocks($nested));
                }
            }
            continue;
        }
        $blocks = array_merge($blocks, response_pdf_dom_blocks($child));
    }
    return $blocks;
}

function response_pdf_filter_ai_blocks($blocks) {
    $filtered = [];
    $skipNextParagraph = false;
    foreach ($blocks as $block) {
        $style = $block['style'] ?? '';
        $text = trim((string)($block['text'] ?? ''));
        $normalized = mb_strtolower($text, 'UTF-8');
        if ($style === 'heading' && in_array($normalized, ['общий вывод', 'общий вывод тест', 'приоритет'], true)) {
            $skipNextParagraph = true;
            continue;
        }
        if ($skipNextParagraph && $style === 'paragraph') {
            $skipNextParagraph = false;
            continue;
        }
        $skipNextParagraph = false;
        $filtered[] = $block;
    }
    return $filtered;
}

function response_pdf_patient_name($response) {
    $patient = is_array($response) ? ($response['patient'] ?? []) : [];
    $parts = [
        trim((string)($patient['surname'] ?? '')),
        trim((string)($patient['name'] ?? '')),
        trim((string)($patient['patronymic'] ?? '')),
    ];
    $name = trim(implode(' ', array_filter($parts, fn($part) => $part !== '')));
    return $name !== '' ? $name : '________________________';
}

function response_pdf_blocks_from_html($html, $plainText = '') {
    $aiHtml = sanitize_editor_html((string)$html);
    $plainText = trim((string)$plainText);
    if (trim(strip_tags($aiHtml)) === '' && $plainText === '') {
        return [['text' => 'ИИ-анализ пока не сформирован.', 'style' => 'paragraph']];
    }
    if (class_exists('DOMDocument') && trim(strip_tags($aiHtml)) !== '') {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="pdf-root">' . $aiHtml . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $dom->getElementById('pdf-root');
        if ($root) {
            $blocks = response_pdf_dom_blocks($root);
            if ($blocks) return response_pdf_filter_ai_blocks($blocks);
        }
    }
    $text = response_html_to_text($aiHtml);
    if ($text === '') $text = $plainText;
    return response_pdf_filter_ai_blocks([['text' => $text !== '' ? $text : 'ИИ-анализ пока не сформирован.', 'style' => 'paragraph']]);
}

function response_pdf_blocks($response) {
    return response_pdf_blocks_from_html($response['ai_answer_html'] ?? ai_analysis_to_html($response['analysis'] ?? null));
}

function response_pdf_text($response) {
    $blocks = response_pdf_blocks($response);
    return trim(implode("\n\n", array_map(fn($block) => $block['text'], $blocks)));
}

function simple_pdf_text_width($text, $fontSize) {
    $wide = preg_match_all('/[^\x00-\x7F]/u', (string)$text);
    $chars = mb_strlen((string)$text, 'UTF-8');
    return ($chars - $wide) * $fontSize * 0.52 + $wide * $fontSize * 0.62;
}

function simple_pdf_ttf_text_width($text, $fontSize, $fontPath = '') {
    $text = (string)$text;
    if ($fontPath !== '' && function_exists('imagettfbbox') && is_readable($fontPath)) {
        $box = @imagettfbbox($fontSize, 0, $fontPath, $text);
        if (is_array($box) && count($box) >= 8) {
            $xs = [$box[0], $box[2], $box[4], $box[6]];
            return max($xs) - min($xs);
        }
    }
    return simple_pdf_text_width($text, $fontSize);
}

function simple_pdf_wrap_text($text, $fontSize, $maxWidth, $fontPath = '') {
    $lines = [];
    foreach (preg_split('/\R/u', (string)$text) ?: [''] as $paragraph) {
        $words = preg_split('/\s+/u', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY);
        $line = '';
        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            if ($line !== '' && simple_pdf_ttf_text_width($candidate, $fontSize, $fontPath) > $maxWidth) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
            while (simple_pdf_ttf_text_width($line, $fontSize, $fontPath) > $maxWidth && mb_strlen($line, 'UTF-8') > 1) {
                $chunk = '';
                $rest = $line;
                while ($rest !== '') {
                    $char = mb_substr($rest, 0, 1, 'UTF-8');
                    if ($chunk !== '' && simple_pdf_ttf_text_width($chunk . $char, $fontSize, $fontPath) > $maxWidth) break;
                    $chunk .= $char;
                    $rest = mb_substr($rest, 1, null, 'UTF-8');
                }
                if ($chunk === '' || $rest === '') break;
                $lines[] = $chunk;
                $line = $rest;
            }
        }
        if ($line !== '') $lines[] = $line;
    }
    return $lines ?: [''];
}

function simple_pdf_u16($data, $offset) {
    $value = unpack('n', substr($data, $offset, 2));
    return (int)($value[1] ?? 0);
}

function simple_pdf_u32($data, $offset) {
    $value = unpack('N', substr($data, $offset, 4));
    return (int)($value[1] ?? 0);
}

function simple_pdf_font_cid_to_gid_map($fontData) {
    $numTables = simple_pdf_u16($fontData, 4);
    $cmapOffset = 0;
    for ($i = 0; $i < $numTables; $i++) {
        $entry = 12 + $i * 16;
        if (substr($fontData, $entry, 4) === 'cmap') {
            $cmapOffset = simple_pdf_u32($fontData, $entry + 8);
            break;
        }
    }
    if ($cmapOffset <= 0) return '';
    $numSubtables = simple_pdf_u16($fontData, $cmapOffset + 2);
    $format4Offset = 0;
    for ($i = 0; $i < $numSubtables; $i++) {
        $record = $cmapOffset + 4 + $i * 8;
        $platform = simple_pdf_u16($fontData, $record);
        $encoding = simple_pdf_u16($fontData, $record + 2);
        $offset = simple_pdf_u32($fontData, $record + 4);
        $format = simple_pdf_u16($fontData, $cmapOffset + $offset);
        if ($format === 4 && (($platform === 3 && in_array($encoding, [1, 10], true)) || $platform === 0)) {
            $format4Offset = $cmapOffset + $offset;
            break;
        }
    }
    if ($format4Offset <= 0) return '';
    $segCount = intdiv(simple_pdf_u16($fontData, $format4Offset + 6), 2);
    $endCodes = $format4Offset + 14;
    $startCodes = $endCodes + 2 * $segCount + 2;
    $idDeltas = $startCodes + 2 * $segCount;
    $idRangeOffsets = $idDeltas + 2 * $segCount;
    $gids = array_fill(0, 65536, 0);
    for ($i = 0; $i < $segCount; $i++) {
        $end = simple_pdf_u16($fontData, $endCodes + 2 * $i);
        $start = simple_pdf_u16($fontData, $startCodes + 2 * $i);
        $deltaRaw = simple_pdf_u16($fontData, $idDeltas + 2 * $i);
        $delta = $deltaRaw >= 0x8000 ? $deltaRaw - 0x10000 : $deltaRaw;
        $rangeOffsetPos = $idRangeOffsets + 2 * $i;
        $rangeOffset = simple_pdf_u16($fontData, $rangeOffsetPos);
        if ($start === 0xFFFF && $end === 0xFFFF) continue;
        for ($code = $start; $code <= $end && $code < 65536; $code++) {
            if ($rangeOffset === 0) {
                $gid = ($code + $delta) & 0xFFFF;
            } else {
                $glyphOffset = $rangeOffsetPos + $rangeOffset + 2 * ($code - $start);
                $gid = simple_pdf_u16($fontData, $glyphOffset);
                if ($gid !== 0) $gid = ($gid + $delta) & 0xFFFF;
            }
            $gids[$code] = $gid;
        }
    }
    $map = '';
    foreach ($gids as $gid) $map .= pack('n', $gid);
    return $map;
}

function simple_pdf_hex_text($text) {
    return strtoupper(bin2hex(mb_convert_encoding((string)$text, 'UTF-16BE', 'UTF-8')));
}

function simple_pdf_stream_object($id, $dict, $data, $compress = false) {
    $streamData = (string)$data;
    $dict = trim((string)$dict);
    if ($compress && $streamData !== '') {
        $compressed = gzcompress($streamData);
        if ($compressed !== false) {
            $streamData = $compressed;
            $dict .= ' /Filter /FlateDecode';
        }
    }
    return $id . ' 0 obj << ' . $dict . ' /Length ' . strlen($streamData) . ' >> stream' . "\n" . $streamData . "\nendstream endobj";
}

function simple_pdf_png_info($path) {
    if (!is_readable($path)) return null;
    if (function_exists('imagecreatefrompng')) {
        $image = @imagecreatefrompng($path);
        if ($image) {
            if (!imageistruecolor($image)) {
                imagepalettetotruecolor($image);
            }
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $width = imagesx($image);
            $height = imagesy($image);
            $rgb = '';
            $alpha = '';
            for ($y = 0; $y < $height; $y++) {
                for ($x = 0; $x < $width; $x++) {
                    $color = imagecolorat($image, $x, $y);
                    $rgb .= chr(($color >> 16) & 0xFF) . chr(($color >> 8) & 0xFF) . chr($color & 0xFF);
                    $alpha .= chr((int)round((127 - (($color >> 24) & 0x7F)) * 255 / 127));
                }
            }
            imagedestroy($image);
            return ['width' => $width, 'height' => $height, 'colorspace' => '/DeviceRGB', 'colors' => 3, 'bits' => 8, 'data' => gzcompress($rgb), 'smask' => gzcompress($alpha)];
        }
    }
    $data = file_get_contents($path);
    if (substr($data, 0, 8) !== "\x89PNG\r\n\x1a\n") return null;
    $width = simple_pdf_u32($data, 16);
    $height = simple_pdf_u32($data, 20);
    $bitDepth = ord($data[24] ?? "\0");
    $colorType = ord($data[25] ?? "\0");
    $pos = 8; $idat = ''; $palette = '';
    while ($pos + 8 <= strlen($data)) {
        $len = simple_pdf_u32($data, $pos);
        $type = substr($data, $pos + 4, 4);
        $chunk = substr($data, $pos + 8, $len);
        if ($type === 'PLTE') $palette = $chunk;
        if ($type === 'IDAT') $idat .= $chunk;
        if ($type === 'IEND') break;
        $pos += 12 + $len;
    }
    if ($colorType === 6 && $bitDepth === 8 && ord($data[28] ?? "\0") === 0) {
        // logo33.png is an RGBA PNG. Decode its scanlines when GD is not
        // available, so the vector-PDF fallback can still embed the logo.
        $raw = @gzuncompress($idat);
        $rowBytes = $width * 4;
        if ($raw === false || strlen($raw) < ($rowBytes + 1) * $height) return null;
        $rgb = ''; $alpha = ''; $offset = 0; $previousRow = str_repeat("\0", $rowBytes);
        for ($rowIndex = 0; $rowIndex < $height; $rowIndex++) {
            $filter = ord($raw[$offset++]); $row = '';
            for ($column = 0; $column < $rowBytes; $column++) {
                $value = ord($raw[$offset++]);
                $left = $column >= 4 ? ord($row[$column - 4]) : 0;
                $up = ord($previousRow[$column]);
                $upLeft = $column >= 4 ? ord($previousRow[$column - 4]) : 0;
                if ($filter === 1) $value += $left;
                elseif ($filter === 2) $value += $up;
                elseif ($filter === 3) $value += intdiv($left + $up, 2);
                elseif ($filter === 4) {
                    $estimate = $left + $up - $upLeft;
                    $leftDistance = abs($estimate - $left); $upDistance = abs($estimate - $up); $upLeftDistance = abs($estimate - $upLeft);
                    $value += $leftDistance <= $upDistance && $leftDistance <= $upLeftDistance ? $left : ($upDistance <= $upLeftDistance ? $up : $upLeft);
                } elseif ($filter !== 0) return null;
                $row .= chr($value & 0xFF);
            }
            for ($column = 0; $column < $rowBytes; $column += 4) {
                $rgb .= $row[$column] . $row[$column + 1] . $row[$column + 2];
                $alpha .= $row[$column + 3];
            }
            $previousRow = $row;
        }
        return ['width' => $width, 'height' => $height, 'colorspace' => '/DeviceRGB', 'colors' => 3, 'bits' => 8, 'data' => gzcompress($rgb), 'smask' => gzcompress($alpha)];
    }
    if ($colorType === 2) {
        $colorspace = '/DeviceRGB';
        $colors = 3;
    } elseif ($colorType === 0) {
        $colorspace = '/DeviceGray';
        $colors = 1;
    } elseif ($colorType === 3 && $palette !== '') {
        $maxIndex = max(0, intdiv(strlen($palette), 3) - 1);
        $colorspace = '[/Indexed /DeviceRGB ' . $maxIndex . ' <' . strtoupper(bin2hex($palette)) . '>]';
        $colors = 1;
    } else {
        return null;
    }
    return ['width' => $width, 'height' => $height, 'colorspace' => $colorspace, 'colors' => $colors, 'bits' => $bitDepth, 'data' => $idat, 'smask' => ''];
}

function simple_pdf_jpeg_info($path) {
    if (!is_readable($path)) return null;
    $size = @getimagesize($path);
    $data = @file_get_contents($path);
    if (!is_array($size) || $data === false || ($size[2] ?? null) !== IMAGETYPE_JPEG) return null;
    $channels = (int)($size['channels'] ?? 3);
    return [
        'width' => (int)$size[0],
        'height' => (int)$size[1],
        'colorspace' => $channels === 1 ? '/DeviceGray' : '/DeviceRGB',
        'bits' => (int)($size['bits'] ?? 8),
        'data' => $data,
        'smask' => '',
        'filter' => '/DCTDecode',
    ];
}


function simple_pdf_raster_image_stream($image) {
    $width = imagesx($image);
    $height = imagesy($image);
    $rgb = '';
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $color = imagecolorat($image, $x, $y);
            $rgb .= chr(($color >> 16) & 0xFF) . chr(($color >> 8) & 0xFF) . chr($color & 0xFF);
        }
    }
    return ['width' => $width, 'height' => $height, 'data' => gzcompress($rgb)];
}

function simple_pdf_draw_ttf_text($image, $fontPath, $fontSize, $x, $y, $text, $scale = 2, $rgb = [20, 31, 45]) {
    $color = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
    imagettftext($image, (int)round($fontSize * $scale), 0, (int)round($x * $scale), (int)round($y * $scale), $color, $fontPath, (string)$text);
}

function simple_pdf_raster_document($blocks, $fontPath, $boldFontPath, $logoPath = '', $patientName = '', $patientSex = '') {
    // The PDF is intentionally rendered as a raster document: it makes the
    // layout and Cyrillic typography deterministic on servers without a PDF
    // library or installed fonts. Coordinates below are A4 points at 72 dpi.
    $scale = 2; $pageWidth = 595; $pageHeight = 842;
    $cyan = [0, 174, 211]; $ink = [20, 31, 45]; $muted = [81, 97, 110];
    $pages = [];
    $logoPath = is_readable($logoPath) ? $logoPath : '';
    $newPage = function () use (&$pages, $scale, $pageWidth, $pageHeight) {
        $image = imagecreatetruecolor($pageWidth * $scale, $pageHeight * $scale);
        imagefilledrectangle($image, 0, 0, imagesx($image), imagesy($image), imagecolorallocate($image, 255, 255, 255));
        $pages[] = $image;
        return $image;
    };
    $rect = static function ($image, $x, $y, $width, $height, $rgb) use ($scale) {
        imagefilledrectangle($image, $x * $scale, $y * $scale, ($x + $width) * $scale, ($y + $height) * $scale, imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]));
    };
    $line = static function ($image, $x1, $y1, $x2, $y2, $rgb, $thickness = 1) use ($scale) {
        $color = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]); imagesetthickness($image, $thickness * $scale);
        imageline($image, $x1 * $scale, $y1 * $scale, $x2 * $scale, $y2 * $scale, $color);
    };
    $header = function ($image, $pageNo, $first = false) use ($scale, $logoPath, $boldFontPath, $fontPath, $cyan, $muted, $line) {
        if ($first) {
            // The first-page header is composed from the repository logo and
            // the two contact lines shown in the approved layout.
            if ($logoPath !== '' && function_exists('imagecreatefromjpeg')) {
                $logo = @imagecreatefromjpeg($logoPath);
                if ($logo) { imagealphablending($logo, true); $w = 176; $h = max(1, (int)round($w * imagesy($logo) / max(1, imagesx($logo)))); imagecopyresampled($image, $logo, 41*$scale, 24*$scale, 0, 0, $w*$scale, $h*$scale, imagesx($logo), imagesy($logo)); imagedestroy($logo); }
            }
            // Keep the clinic name and contact line aligned to the same right edge.
            $headerRight = 554;
            $clinicName = 'СЕТЬ КЛИНИК ADAPTOGENZZ';
            $contactLine = '+7 (495) 642-49-26  ·  clinic@adaptogenzz.pro';
            $clinicNameX = $headerRight - simple_pdf_ttf_text_width($clinicName, 7 * $scale, $boldFontPath) / $scale;
            $contactLineX = $headerRight - simple_pdf_ttf_text_width($contactLine, 6 * $scale, $fontPath) / $scale;
            simple_pdf_draw_ttf_text($image, $boldFontPath, 7, $clinicNameX, 38, $clinicName, $scale, $cyan);
            simple_pdf_draw_ttf_text($image, $fontPath, 6, $contactLineX, 50, $contactLine, $scale, $muted);
            $line($image, 41, 73, 554, 73, $cyan, 2);
        } else {
            simple_pdf_draw_ttf_text($image, $boldFontPath, 7, 45, 26, 'ADAPTOGENZZ  ·  ПЕРСОНАЛЬНЫЙ ИНФОРМАЦИОННЫЙ ОБЗОР', $scale, $cyan);
            $line($image, 41, 36, 554, 36, $cyan, 2);
        }
        $line($image, 41, 809, 554, 809, [208, 222, 227]);
        simple_pdf_draw_ttf_text($image, $boldFontPath, 5.5, 320, 824, 'ADAPTOGENZZ  ·  ИНФОРМАЦИОННЫЙ ОБЗОР', $scale, $muted);
        simple_pdf_draw_ttf_text($image, $fontPath, 5.5, 514, 824, 'Страница ' . $pageNo, $scale, $muted);
    };
    $drawWrapped = function ($image, $text, $x, $y, $fontSize, $maxWidth, $font, $color, $leading = null) use ($scale) {
        $leading = $leading ?? ($fontSize + 3);
        foreach (simple_pdf_wrap_text($text, $fontSize, $maxWidth, $font) as $textLine) { simple_pdf_draw_ttf_text($image, $font, $fontSize, $x, $y, $textLine, $scale, $color); $y += $leading; }
        return $y;
    };
    $groups = ['observations' => [], 'recommendations' => [], 'steps' => []]; $group = 'observations';
    foreach ($blocks as $block) {
        $text = trim((string)($block['text'] ?? '')); if ($text === '') continue;
        $style = $block['style'] ?? 'paragraph'; $lower = mb_strtolower($text, 'UTF-8');
        $isRecommendationHeading = $style === 'heading' && str_contains($lower, 'рекомендуем');
        $isStepsHeading = $style === 'heading' && str_contains($lower, 'следующ');
        if ($isRecommendationHeading) $group = 'recommendations';
        if ($isStepsHeading) $group = 'steps';
        // Section names are rendered below, so omit matching AI headings.
        if ($isRecommendationHeading || $isStepsHeading) continue;
        $groups[$group][] = ['text' => $text, 'style' => $style];
    }
    $pageNo = 1; $page = $newPage(); $header($page, $pageNo, true); $y = 88;
    simple_pdf_draw_ttf_text($page, $boldFontPath, 7, 42, $y, 'ПЕРСОНАЛЬНЫЙ ИНФОРМАЦИОННЫЙ ОБЗОР', $scale, $cyan); $y += 31;
    $y = $drawWrapped($page, "Лабораторные исследования\nи следующие шаги", 42, $y, 23, 475, $boldFontPath, $ink, 27); $y += 7;
    $line($page, 41, $y, 554, $y, $cyan, 2); $y += 27;
    $y = $drawWrapped($page, response_pdf_greeting_word($patientSex) . ' ' . ($patientName !== '' ? $patientName : '________________________') . '!', 42, $y, 9, 500, $boldFontPath, $ink, 12); $y += 7;
    $y = $drawWrapped($page, 'Благодарим вас за заполнение анкеты. На основе предоставленных вами сведений мы подготовили информационный обзор лабораторных и инструментальных методов исследования, которые могут быть актуальны при ваших симптомах и особенностях здоровья.', 42, $y, 8, 500, $fontPath, $ink, 10); $y += 6;
    $rect($page, 41, $y, 513, 28, [230, 245, 249]); $rect($page, 41, $y, 3, 28, $cyan);
    simple_pdf_draw_ttf_text($page, $boldFontPath, 7, 51, $y + 10, 'ОБРАТИТЕ ВНИМАНИЕ', $scale, $cyan);
    simple_pdf_draw_ttf_text($page, $fontPath, 7, 51, $y + 21, 'Настоящий обзор не является медицинским заключением, диагнозом или назначением.', $scale, $ink); $y += 43;
    $y = $drawWrapped($page, 'Ниже приведён перечень анализов и диагностических процедур, о которых врачи часто упоминают в подобных ситуациях.', 42, $y, 8, 500, $fontPath, $ink, 10); $y += 8;
    if ($groups['observations']) {
        simple_pdf_draw_ttf_text($page, $boldFontPath, 11, 42, $y, 'Ключевые наблюдения', $scale, $cyan); $y += 10;
        $rect($page, 41, $y, 3, 48, $cyan); $rect($page, 44, $y, 510, 48, [247, 247, 247]); $textY = $y + 11;
        foreach ($groups['observations'] as $block) { if ($block['style'] === 'heading') continue; $textY = $drawWrapped($page, $block['text'], 52, $textY, 8, 490, $boldFontPath, $ink, 10); }
    }
    $renderGroup = function ($items, $heading, $special = false) use (&$pageNo, &$page, &$y, $newPage, $header, $drawWrapped, $boldFontPath, $fontPath, $cyan, $ink, $muted, $rect, $line, $scale) {
        if (!$items && !$special) return; $pageNo++; $page = $newPage(); $header($page, $pageNo); $y = 63;
        simple_pdf_draw_ttf_text($page, $boldFontPath, 12, 45, $y, $heading, $scale, $cyan); $y += 21;
        foreach ($items as $block) {
            if ($y > 720) { $pageNo++; $page = $newPage(); $header($page, $pageNo); $y = 62; }
            // Preserve headings entered by the doctor as distinct PDF sections.
            // The additional spacing keeps a heading visually separated from the
            // preceding recommendation, including after a page break.
            if ($block['style'] === 'heading') { $y += 8; $y = $drawWrapped($page, $block['text'], 45, $y, 9, 500, $boldFontPath, $ink, 12); $y += 5; continue; }
            $y = $drawWrapped($page, $block['text'], 55, $y, 8, 488, $fontPath, $ink, 11); $y += 1;
        }
        if (!$special) return;
        $y += 9; $rect($page, 41, $y, 3, 27, [238, 101, 45]); $rect($page, 44, $y, 510, 27, [232, 235, 240]);
        simple_pdf_draw_ttf_text($page, $boldFontPath, 7, 52, $y + 10, 'ВАЖНО', $scale, [211, 78, 28]);
        simple_pdf_draw_ttf_text($page, $fontPath, 7, 52, $y + 21, 'Перечень составлен как справочный материал на основании ваших ответов.', $scale, $ink); $y += 42;
        $y = $drawWrapped($page, 'После того как вы получите результаты анализов (в любой лаборатории), вы можете записаться на приём к терапевту в нашу клинику для интерпретации данных и получения медицинских рекомендаций.', 45, $y, 8, 500, $fontPath, $ink, 10); $y += 9;
        $offerTitle = 'СПЕЦИАЛЬНОЕ ПРЕДЛОЖЕНИЕ';
        $offerText = 'Скидка 10% на приём врача-терапевта или врача общей практики, если все исследования были проведены в наших клиниках.';
        $offerQualification = 'Скидка 10% предоставляется, если исследования из рекомендаций, которые входят в перечень наших услуг, были сданы именно в наших клиниках.';
        $offerTitleLines = simple_pdf_wrap_text($offerTitle, 8, 485, $boldFontPath);
        $offerTextLines = simple_pdf_wrap_text($offerText, 8, 485, $fontPath);
        $offerQualificationLines = simple_pdf_wrap_text($offerQualification, 8, 485, $fontPath);
        $offerHeight = 12 + count($offerTitleLines) * 10 + 3 + count($offerTextLines) * 10 + 30 + count($offerQualificationLines) * 10 + 12;
        $rect($page, 41, $y, 513, $offerHeight, $cyan); $textY = $y + 12;
        $textY = $drawWrapped($page, $offerTitle, 53, $textY, 8, 485, $boldFontPath, [255,255,255], 10);
        $textY = $drawWrapped($page, $offerText, 53, $textY + 3, 8, 485, $fontPath, [255,255,255], 10);
        $drawWrapped($page, $offerQualification, 53, $textY + 30, 8, 485, $fontPath, [255,255,255], 10);
        $y += $offerHeight + 22;
        simple_pdf_draw_ttf_text($page, $fontPath, 8, 45, $y, 'С уважением,', $scale, $ink); simple_pdf_draw_ttf_text($page, $boldFontPath, 8, 45, $y + 12, 'Команда специалистов Adaptogenzz', $scale, $cyan); simple_pdf_draw_ttf_text($page, $fontPath, 7, 45, $y + 24, '+7 (495) 642-49-26  ·  clinic@adaptogenzz.pro', $scale, $muted);
    };
    $renderGroup($groups['recommendations'], 'Рекомендуемые анализы и обследования');
    $renderGroup($groups['steps'], 'Следующие шаги', true);
    if (!$groups['steps']) $renderGroup([], 'Следующие шаги', true);

    $pageCount = count($pages); $pageIds = []; $contentIds = []; $imageIds = [];
    for ($i = 0; $i < $pageCount; $i++) { $pageIds[] = 3 + $i * 3; $contentIds[] = 4 + $i * 3; $imageIds[] = 5 + $i * 3; }
    $objects = [1 => '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj', 2 => '2 0 obj << /Type /Pages /Kids [' . implode(' ', array_map(fn($id) => $id . ' 0 R', $pageIds)) . '] /Count ' . $pageCount . ' >> endobj'];
    foreach ($pages as $i => $image) { $name = '/Pg' . ($i + 1); $objects[$pageIds[$i]] = $pageIds[$i] . ' 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /XObject << ' . $name . ' ' . $imageIds[$i] . ' 0 R >> >> /Contents ' . $contentIds[$i] . ' 0 R >> endobj'; $objects[$contentIds[$i]] = simple_pdf_stream_object($contentIds[$i], '', 'q 595 0 0 842 0 0 cm ' . $name . ' Do Q'); $stream = simple_pdf_raster_image_stream($image); imagedestroy($image); $objects[$imageIds[$i]] = simple_pdf_stream_object($imageIds[$i], '/Type /XObject /Subtype /Image /Width ' . $stream['width'] . ' /Height ' . $stream['height'] . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode', $stream['data']); }
    ksort($objects); $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offsets = [0]; foreach ($objects as $id => $object) { $offsets[$id] = strlen($pdf); $pdf .= $object . "\n"; } $xref = strlen($pdf); $pdf .= "xref\n0 " . (max(array_keys($objects)) + 1) . "\n0000000000 65535 f \n"; for ($i = 1; $i <= max(array_keys($objects)); $i++) $pdf .= isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 65535 f \n"; return $pdf . "trailer << /Size " . (max(array_keys($objects)) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
}

function response_pdf_greeting_word($sex) {
    $sex = mb_strtolower(trim((string)$sex), 'UTF-8');
    if (in_array($sex, ['женщина', 'ж', 'жен', 'женский', 'female', '2'], true)) return 'Уважаемая';
    if (in_array($sex, ['мужчина', 'м', 'муж', 'мужской', 'male', '1'], true)) return 'Уважаемый';
    return 'Уважаемый(ая)';
}

function simple_pdf_document($content, $title = 'Расшифровка анкеты', $patientName = '', $patientSex = '') {
    // Prefer the bundled Liberation Sans font for generated PDFs so Cyrillic
    // text does not depend on fonts installed on the server. System fonts remain
    // as fallbacks for deployments where the bundled file is missing.
    $bundledLiberationSans = __DIR__ . '/LiberationSans-Regular.ttf';
    $fontCandidates = [
        ['LiberationSans', 'LiberationSans', $bundledLiberationSans, $bundledLiberationSans],
        ['LiberationSans', 'LiberationSans-Bold', '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf', '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf'],
        ['NotoSans', 'NotoSans-Bold', '/usr/share/fonts/truetype/noto/NotoSans-Regular.ttf', '/usr/share/fonts/truetype/noto/NotoSans-Bold.ttf'],
        ['NotoSerif', 'NotoSerif-Bold', '/usr/share/fonts/truetype/noto/NotoSerif-Regular.ttf', '/usr/share/fonts/truetype/noto/NotoSerif-Bold.ttf'],
        ['DejaVuSans', 'DejaVuSans-Bold', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'],
    ];
    $fontBaseName = 'LiberationSans';
    $boldFontBaseName = 'LiberationSans-Bold';
    $fontPath = '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf';
    $boldFontPath = '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf';
    foreach ($fontCandidates as [$regularName, $boldName, $regularPath, $boldPath]) {
        if (!is_readable($regularPath)) continue;
        $fontBaseName = $regularName;
        $boldFontBaseName = is_readable($boldPath) ? $boldName : $regularName;
        $fontPath = $regularPath;
        $boldFontPath = is_readable($boldPath) ? $boldPath : $regularPath;
        break;
    }
    $fontData = is_readable($fontPath) ? file_get_contents($fontPath) : '';
    $boldFontData = is_readable($boldFontPath) ? file_get_contents($boldFontPath) : $fontData;
    $cidToGidMap = $fontData !== '' ? simple_pdf_font_cid_to_gid_map($fontData) : '';
    $boldCidToGidMap = $boldFontData !== '' ? simple_pdf_font_cid_to_gid_map($boldFontData) : $cidToGidMap;
    $logo = simple_pdf_jpeg_info(__DIR__ . '/logo44.jpg');
    $aiBlocks = is_array($content) ? $content : [['text' => (string)$content, 'style' => 'paragraph']];
    $aiBlocks = array_map(function ($block) {
        if (!is_array($block)) $block = ['text' => (string)$block, 'style' => 'paragraph'];
        $block['compact'] = true;
        return $block;
    }, $aiBlocks);
    $patientName = trim((string)$patientName);
    if ($patientName === '') $patientName = '________________________';
    $blocks = array_merge([
        ['text' => response_pdf_greeting_word($patientSex) . ' ' . $patientName . '!', 'style' => 'greeting'],
        ['text' => 'Благодарим вас за заполнение анкеты. На основе предоставленных вами сведений мы подготовили информационный обзор лабораторных и инструментальных методов исследования, которые могут быть актуальны при ваших симптомах и особенностях здоровья.', 'style' => 'paragraph'],
        ['text' => 'Настоящий обзор не является медицинским заключением, диагнозом или назначением.', 'style' => 'paragraph'],
        ['text' => 'Ниже приведён перечень анализов и диагностических процедур, о которых врачи часто упоминают в подобных ситуациях.', 'style' => 'paragraph'],
    ], $aiBlocks, [
        ['text' => 'Важно!', 'style' => 'heading'],
        ['text' => 'Перечень составлен как справочный материал на основании ваших ответов.', 'style' => 'paragraph'],
        ['text' => 'После того как вы получите результаты анализов (в любой лаборатории), вы можете записаться на приём к терапевту в нашу клинику для интерпретации данных и получения медицинских рекомендаций.', 'style' => 'paragraph'],
        ['text' => 'В сети наших клиник действует специальное предложение: скидка 10% на приём врача-терапевта или врача общей практики, если все исследования были проведены в наших клиниках', 'style' => 'paragraph'],
        ['text' => 'С уважением,', 'style' => 'paragraph_spaced'],
        ['text' => 'Команда специалистов Adaptogenzz', 'style' => 'paragraph'],
    ]);

    if (function_exists('imagettftext') && is_readable($fontPath)) {
        return simple_pdf_raster_document(
            $aiBlocks,
            $fontPath,
            is_readable($boldFontPath) ? $boldFontPath : $fontPath,
            __DIR__ . '/logo44.jpg',
            $patientName,
            $patientSex
        );
    }

    $pageStreams = [];
    $pdfLines = [];
    $startPage = function ($showHeader = true) use (&$pdfLines, $logo) {
        $pdfLines = [];
        if ($showHeader && $logo) {
            $logoWidth = 72;
            $logoHeight = max(1, (int)round($logoWidth * ((float)$logo['height'] / max(1, (float)$logo['width']))));
            $logoY = 800 - $logoHeight;
            $pdfLines[] = 'q ' . $logoWidth . ' 0 0 ' . $logoHeight . ' 95 ' . $logoY . ' cm /Im1 Do Q';
        }
        $pdfLines[] = 'BT';
        if ($showHeader) {
            $contactLines = ['Сеть клиник Adaptogenzz', 'Телефон: +7 (495) 642-49-26,', 'Почта: clinic@adaptogenzz.pro'];
            $contactY = 792;
            foreach ($contactLines as $line) {
                $pdfLines[] = '/F2 10 Tf';
                $pdfLines[] = '1 0 0 1 375 ' . $contactY . ' Tm';
                $pdfLines[] = '<' . simple_pdf_hex_text($line) . '> Tj';
                $contactY -= 14;
            }
            return 660;
        }
        return 800;
    };
    $finishPage = function () use (&$pdfLines, &$pageStreams) {
        $pdfLines[] = 'ET';
        $pageStreams[] = implode("\n", $pdfLines);
    };

    $y = $startPage(true);
    $x = 40;
    $maxWidth = 500;
    foreach ($blocks as $blockIndex => $block) {
        $style = $block['style'] ?? '';
        $isHeading = $style === 'heading';
        $isGreeting = $style === 'greeting';
        $fontSize = $isHeading ? 10 : 8;
        $isCompact = !empty($block['compact']);
        $leading = $isHeading || $isGreeting ? 13 : ($isCompact ? 11 : 12);
        $blockGap = 15;
        $before = $blockIndex === 0 ? 0 : $blockGap;
        $after = 0;
        $y -= $before;
        foreach (simple_pdf_wrap_text($block['text'] ?? '', $fontSize, $maxWidth, $isHeading ? $boldFontPath : $fontPath) as $line) {
            if ($y < 42) {
                $finishPage();
                $y = $startPage(false);
            }
            $pdfLines[] = ($isHeading ? '/F2 ' : '/F1 ') . $fontSize . ' Tf';
            $lineX = $isGreeting ? max(40, (int)round((595 - simple_pdf_text_width($line, $fontSize)) / 2)) : $x;
            $pdfLines[] = '1 0 0 1 ' . $lineX . ' ' . $y . ' Tm';
            $pdfLines[] = '<' . simple_pdf_hex_text($line) . '> Tj';
            $y -= $leading;
        }
        $y -= $after;
    }
    $finishPage();

    $toUnicode = "/CIDInit /ProcSet findresource begin 12 dict begin begincmap /CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def /CMapName /Adobe-Identity-UCS def /CMapType 2 def 1 begincodespacerange <0000> <FFFF> endcodespacerange 1 beginbfrange <0000> <FFFF> <0000> endbfrange endcmap CMapName currentdict /CMap defineresource pop end end";
    $pageCount = count($pageStreams);
    $pageIds = [];
    $contentIds = [];
    for ($i = 0; $i < $pageCount; $i++) {
        $pageIds[] = 3 + ($i * 2);
        $contentIds[] = 4 + ($i * 2);
    }
    $nextId = 3 + ($pageCount * 2);
    $f1Id = $nextId++; $cidFont1Id = $nextId++; $fontDescriptor1Id = $nextId++; $toUnicodeId = $nextId++;
    $fontFile1Id = $nextId++; $cidMap1Id = $nextId++; $imageId = $nextId++; $f2Id = $nextId++;
    $cidFont2Id = $nextId++; $smaskId = $nextId++; $fontDescriptor2Id = $nextId++; $cidMap2Id = $nextId++; $fontFile2Id = $nextId++;

    $resource = '/Resources << /Font << /F1 ' . $f1Id . ' 0 R /F2 ' . $f2Id . ' 0 R >>' . ($logo ? ' /XObject << /Im1 ' . $imageId . ' 0 R >>' : '') . ' >>';
    $objects = [
        1 => '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj',
        2 => '2 0 obj << /Type /Pages /Kids [' . implode(' ', array_map(fn($id) => $id . ' 0 R', $pageIds)) . '] /Count ' . $pageCount . ' >> endobj',
    ];
    foreach ($pageStreams as $i => $stream) {
        $objects[$pageIds[$i]] = $pageIds[$i] . ' 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] ' . $resource . ' /Contents ' . $contentIds[$i] . ' 0 R >> endobj';
        $objects[$contentIds[$i]] = simple_pdf_stream_object($contentIds[$i], '', $stream);
    }
    $objects[$f1Id] = $f1Id . ' 0 obj << /Type /Font /Subtype /Type0 /BaseFont /' . $fontBaseName . ' /Encoding /Identity-H /DescendantFonts [' . $cidFont1Id . ' 0 R] /ToUnicode ' . $toUnicodeId . ' 0 R >> endobj';
    $objects[$cidFont1Id] = $cidFont1Id . ' 0 obj << /Type /Font /Subtype /CIDFontType2 /BaseFont /' . $fontBaseName . ' /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor ' . $fontDescriptor1Id . ' 0 R /CIDToGIDMap ' . $cidMap1Id . ' 0 R /DW 600 >> endobj';
    $objects[$fontDescriptor1Id] = $fontDescriptor1Id . ' 0 obj << /Type /FontDescriptor /FontName /' . $fontBaseName . ' /Flags 4 /Ascent 928 /Descent -236 /CapHeight 729 /ItalicAngle 0 /StemV 80 /FontBBox [-1021 -463 1794 1232] >> endobj';
    $objects[$toUnicodeId] = simple_pdf_stream_object($toUnicodeId, '', $toUnicode);
    // Do not embed FontFile2 in the vector fallback: Acrobat can reject the
    // hand-built embedded font streams. The normal GD raster path above avoids
    // PDF fonts entirely; this fallback keeps ToUnicode/CID maps for text data.
    $objects[$cidMap1Id] = simple_pdf_stream_object($cidMap1Id, '', $cidToGidMap, true);
    $smaskRef = ($logo && !empty($logo['smask'])) ? ' /SMask ' . $smaskId . ' 0 R' : '';
    $objects[$imageId] = $logo ? simple_pdf_stream_object($imageId, '/Type /XObject /Subtype /Image /Width ' . (int)$logo['width'] . ' /Height ' . (int)$logo['height'] . ' /ColorSpace ' . $logo['colorspace'] . ' /BitsPerComponent ' . (int)$logo['bits'] . ' /Filter ' . ($logo['filter'] ?? '/FlateDecode') . $smaskRef, $logo['data']) : ($imageId . ' 0 obj << >> endobj');
    $objects[$f2Id] = $f2Id . ' 0 obj << /Type /Font /Subtype /Type0 /BaseFont /' . $boldFontBaseName . ' /Encoding /Identity-H /DescendantFonts [' . $cidFont2Id . ' 0 R] /ToUnicode ' . $toUnicodeId . ' 0 R >> endobj';
    $objects[$cidFont2Id] = $cidFont2Id . ' 0 obj << /Type /Font /Subtype /CIDFontType2 /BaseFont /' . $boldFontBaseName . ' /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor ' . $fontDescriptor2Id . ' 0 R /CIDToGIDMap ' . $cidMap2Id . ' 0 R /DW 600 >> endobj';
    $objects[$smaskId] = ($logo && !empty($logo['smask'])) ? simple_pdf_stream_object($smaskId, '/Type /XObject /Subtype /Image /Width ' . (int)$logo['width'] . ' /Height ' . (int)$logo['height'] . ' /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode', $logo['smask']) : ($smaskId . ' 0 obj << >> endobj');
    $objects[$fontDescriptor2Id] = $fontDescriptor2Id . ' 0 obj << /Type /FontDescriptor /FontName /' . $boldFontBaseName . ' /Flags 4 /Ascent 928 /Descent -236 /CapHeight 729 /ItalicAngle 0 /StemV 120 /FontBBox [-1021 -463 1794 1232] >> endobj';
    $objects[$cidMap2Id] = simple_pdf_stream_object($cidMap2Id, '', $boldCidToGidMap, true);

    ksort($objects);
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0];
    foreach ($objects as $id => $object) { $offsets[$id] = strlen($pdf); $pdf .= $object . "\n"; }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (max(array_keys($objects)) + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= max(array_keys($objects)); $i++) $pdf .= isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 65535 f \n";
    return $pdf . "trailer << /Size " . (max(array_keys($objects)) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
}

function rnova_config($key, $fallback) {
    $value = getenv($key);
    if ($value === false || trim((string)$value) === '') {
        return $fallback;
    }
    return trim((string)$value);
}

function rnova_response_id($data) {
    if (is_numeric($data) || (is_string($data) && trim($data) !== '')) {
        return $data;
    }
    if (!is_array($data)) {
        return null;
    }
    foreach (['patient_id', 'task_id', 'taskId', 'file_id', 'fileId', 'id', 'ID'] as $key) {
        if (isset($data[$key]) && (is_numeric($data[$key]) || trim((string)$data[$key]) !== '')) {
            return $data[$key];
        }
    }
    foreach (['data', 'patient', 'result'] as $key) {
        $id = rnova_response_id($data[$key] ?? null);
        if ($id) {
            return $id;
        }
    }
    foreach (['items', 'patients'] as $key) {
        if (isset($data[$key]) && is_array($data[$key])) {
            $id = rnova_response_id(reset($data[$key]));
            if ($id) {
                return $id;
            }
        }
    }
    if (array_is_list($data) && count($data) > 0) {
        return rnova_response_id($data[0]);
    }
    return null;
}

function rnova_execute_request($url, $form, $asMultipart = false) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_POSTFIELDS => $asMultipart ? $form : http_build_query($form),
        CURLOPT_TIMEOUT => 60,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$raw, $err, $http];
}

function rnova_response_error_desc($data) {
    return is_array($data) ? ($data['data']['desc'] ?? $data['desc'] ?? null) : null;
}

function rnova_result_has_required_error($data, $http) {
    $apiError = is_array($data) ? (int)($data['error'] ?? 0) : 0;
    if ($http < 200 || $http >= 300 || $apiError !== 0) {
        return mb_stripos((string)rnova_response_error_desc($data), 'обязательн', 0, 'UTF-8') !== false;
    }
    return false;
}

function rnova_request($method, $path, $payload = null) {
    $apiUrl = rnova_config('RNOVA_API_URL', RNOVA_API_URL);
    $apiToken = rnova_config('RNOVA_API_TOKEN', RNOVA_API_TOKEN);
    if (trim($apiUrl) === '' || trim($apiToken) === '') {
        return ['ok' => false, 'error' => 'Не настроен реальный доступ к RNOVA: задайте RNOVA_API_URL и RNOVA_API_TOKEN в окружении.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'Для интеграции RNOVA требуется расширение cURL.'];
    }

    $methodName = trim((string)$path, '/');
    $query = [];
    if (str_contains($methodName, '?')) {
        [$methodName, $queryString] = explode('?', $methodName, 2);
        parse_str($queryString, $query);
    }
    $payload = is_array($payload) ? $payload : [];
    $form = array_merge($query, $payload, ['api_key' => $apiToken]);
    $url = rtrim($apiUrl, '/') . '/' . ltrim($methodName, '/');

    $transport = 'application/x-www-form-urlencoded';
    [$raw, $err, $http] = rnova_execute_request($url, $form, false);
    if ($raw === false) return ['ok' => false, 'error' => 'Ошибка RNOVA: ' . $err];
    $data = json_decode((string)$raw, true);
    $originalRnovaError = rnova_response_error_desc($data);

    // Some RNOVA installations do not parse application/x-www-form-urlencoded
    // bodies consistently and answer that required fields are missing although
    // they were sent. Retry once as multipart/form-data before surfacing the
    // error to the user.
    if (rnova_result_has_required_error($data, $http) && !rnova_payload_missing_fields($path, $payload)) {
        [$retryRaw, $retryErr, $retryHttp] = rnova_execute_request($url, $form, true);
        if ($retryRaw === false) {
            $retryError = 'Ошибка RNOVA: ' . $retryErr;
            if ($originalRnovaError) {
                $retryError .= '. Оригинальная ошибка RNOVA: ' . $originalRnovaError . '.';
            }
            return [
                'ok' => false,
                'error' => $retryError,
                'rnova_error' => null,
                'original_rnova_error' => $originalRnovaError,
            ];
        }
        $raw = $retryRaw;
        $http = $retryHttp;
        $data = json_decode((string)$raw, true);
        $transport = 'multipart/form-data (повтор после ошибки обязательных параметров при application/x-www-form-urlencoded)';
    }

    $apiError = is_array($data) ? (int)($data['error'] ?? 0) : 0;
    $ok = $http >= 200 && $http < 300 && $apiError === 0;
    $error = null;
    if (!$ok) {
        $desc = rnova_response_error_desc($data);
        $error = $desc ? ('RNOVA: ' . $desc) : ('RNOVA вернула HTTP ' . $http);
        if (mb_stripos((string)$error, 'обязательн', 0, 'UTF-8') !== false) {
            $missing = rnova_payload_missing_fields($path, $payload);
            if ($missing) {
                $error .= '. Не указаны обязательные параметры: ' . implode(', ', $missing) . '. ' . rnova_payload_debug_summary($path, $payload, $transport);
            } else {
                $required = rnova_required_payload_field_labels($path);
                if ($required) {
                    $error .= '. Параметры были отправлены приложением; RNOVA не распознала их. ' . rnova_payload_debug_summary($path, $payload, $transport);
                }
            }
        }
        if ($originalRnovaError) {
            $error .= ' Оригинальная ошибка RNOVA: ' . $originalRnovaError . '.';
        }
    }
    return [
        'ok' => $ok,
        'http' => $http,
        'data' => is_array($data) ? ($data['data'] ?? $data) : [],
        'raw' => $raw,
        'error' => $error,
        'rnova_error' => rnova_response_error_desc($data),
        'original_rnova_error' => $originalRnovaError,
    ];
}

function rnova_date($date) {
    $date = trim((string)$date);
    if ($date === '') return '';
    foreach (['Y-m-d', DateTimeInterface::ATOM, 'd.m.Y'] as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $date);
        if ($dt instanceof DateTimeImmutable) return $dt->format('d.m.Y');
    }
    try {
        return (new DateTimeImmutable($date))->format('d.m.Y');
    } catch (Throwable $e) {
        return $date;
    }
}

function rnova_gender($sex) {
    $sex = mb_strtolower(trim((string)$sex), 'UTF-8');
    if (in_array($sex, ['м', 'муж', 'мужской', 'male', '1'], true)) return '1';
    if (in_array($sex, ['ж', 'жен', 'женский', 'female', '2'], true)) return '2';
    return '';
}

function rnova_filter_payload($payload) {
    return array_filter($payload, static fn($value) => trim((string)$value) !== '');
}


function rnova_payload_debug_summary($path, $payload, $transport = null) {
    $methodName = trim((string)$path, '/');
    if (str_contains($methodName, '?')) {
        [$methodName] = explode('?', $methodName, 2);
    }
    $payload = is_array($payload) ? $payload : [];
    $parts = [];
    foreach ($payload as $key => $value) {
        if ($key === 'api_key') continue;
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $value = trim((string)$value);
        if ($value === '') {
            $value = '(пусто)';
        }
        $parts[] = $key . '=' . $value;
    }
    $summary = 'Метод RNOVA: ' . ($methodName !== '' ? $methodName : trim((string)$path, '/')) . '.';
    $required = rnova_required_payload_field_labels($path);
    if ($required) {
        $summary .= ' Обязательные параметры метода: ' . implode(', ', $required) . '.';
    }
    if ($transport) {
        $summary .= ' Как отправляли: POST, ' . $transport . '.';
    }
    $summary .= ' Отправленные параметры: ' . ($parts ? implode(', ', $parts) : 'нет параметров') . '.';
    return $summary;
}

function rnova_required_payload_fields($path) {
    $methodName = trim((string)$path, '/');
    if (str_contains($methodName, '?')) {
        [$methodName] = explode('?', $methodName, 2);
    }
    $requiredByMethod = [
        'createPatient' => [
            'last_name' => 'фамилия (last_name)',
            'first_name' => 'имя (first_name)',
            'third_name' => 'отчество (third_name)',
            'birth_date' => 'дата рождения (birth_date)',
        ],
        'createTask' => [
            'role_id' => 'роль исполнителя (role_id)',
            'user_id' => 'сотрудник-исполнитель (user_id)',
            'title' => 'заголовок задачи (title)',
            'desc' => 'описание задачи (desc)',
            'patient_id' => 'ID пациента (patient_id)',
            'due_date' => 'срок задачи (due_date)',
        ],
        'uploadFile' => [
            'content' => 'содержимое файла (content)',
            'title' => 'название файла (title)',
            'type' => 'тип файла (type)',
            'patient_id' => 'ID пациента (patient_id)',
        ],
    ];
    return $requiredByMethod[$methodName] ?? [];
}

function rnova_required_payload_field_labels($path) {
    return array_values(rnova_required_payload_fields($path));
}

function rnova_payload_missing_fields($path, $payload) {
    $required = rnova_required_payload_fields($path);
    $missing = [];
    foreach ($required as $key => $label) {
        $value = $payload[$key] ?? '';
        if (trim((string)$value) === '' || (in_array($key, ['role_id', 'user_id', 'patient_id'], true) && (int)$value <= 0)) {
            $missing[] = $label;
        }
    }
    return $missing;
}

function rnova_required_patient_fields_missing($patient) {
    $required = [
        'last_name' => ['label' => 'фамилия', 'value' => trim((string)($patient['surname'] ?? ''))],
        'first_name' => ['label' => 'имя', 'value' => trim((string)($patient['name'] ?? ''))],
        'birth_date' => ['label' => 'дата рождения', 'value' => rnova_date($patient['dob'] ?? '')],
    ];
    $missing = [];
    foreach ($required as $field) {
        if ($field['value'] === '') {
            $missing[] = $field['label'];
        }
    }
    return $missing;
}

function rnova_patient_payload($patient) {
    $firstName = trim((string)($patient['name'] ?? ''));
    $thirdName = trim((string)($patient['patronymic'] ?? ''));
    if ($thirdName === '') {
        // RNOVA marks third_name as a required createPatient parameter.
        // Use a neutral placeholder when the анкета has no patronymic.
        $thirdName = '-';
    }

    return rnova_filter_payload([
        'last_name' => trim((string)($patient['surname'] ?? '')),
        'first_name' => $firstName,
        'third_name' => $thirdName,
        'birth_date' => rnova_date($patient['dob'] ?? ''),
        'mobile' => rnova_phone($patient['phone'] ?? ''),
        'email' => trim((string)($patient['email'] ?? '')),
        'gender' => rnova_gender($patient['sex'] ?? ''),
    ]);
}

function rnova_error_contains($result, $needle) {
    return mb_stripos((string)($result['error'] ?? ''), $needle, 0, 'UTF-8') !== false;
}

function rnova_find_patient_id($patient) {
    $payload = rnova_patient_payload($patient);
    $queries = [];
    $fullNameQuery = rnova_filter_payload([
        'last_name' => $payload['last_name'] ?? '',
        'first_name' => $payload['first_name'] ?? '',
        'third_name' => $payload['third_name'] ?? '',
    ]);
    if (!empty($payload['email'])) {
        $queries[] = ['email' => $payload['email']];
    }
    if (count($fullNameQuery) >= 3) {
        $queries[] = $fullNameQuery;
    }

    foreach ($queries as $query) {
        $found = rnova_request('POST', 'getPatient', $query);
        if (!$found['ok']) {
            if ((int)($found['http'] ?? 0) >= 500) return $found;
            continue;
        }
        $patientId = rnova_response_id($found['data'] ?? []);
        if ($patientId) return ['ok' => true, 'patient_id' => $patientId];
    }
    return ['ok' => true, 'patient_id' => null];
}

function rnova_ensure_patient($response) {
    $patient = is_array($response['patient'] ?? null) ? $response['patient'] : [];
    $missing = rnova_required_patient_fields_missing($patient);
    if ($missing) {
        return ['ok' => false, 'error' => 'Для создания пациента RNOVA не заполнены обязательные поля: ' . implode(', ', $missing) . '. Обязательные параметры RNOVA: фамилия, имя, дата рождения.'];
    }

    $found = rnova_find_patient_id($patient);
    if (!$found['ok']) return $found;
    if (!empty($found['patient_id'])) return $found;

    $created = rnova_request('POST', 'createPatient', rnova_patient_payload($patient));
    if (!$created['ok'] && rnova_error_contains($created, 'Такой пациент уже существует')) {
        $found = rnova_find_patient_id($patient);
        if (!$found['ok']) return $found;
        if (!empty($found['patient_id'])) return $found;
    }
    if (!$created['ok']) return $created;
    $patientId = rnova_response_id($created['data'] ?? []);
    if (!$patientId) return ['ok' => false, 'error' => 'RNOVA не вернула ID пациента.'];

    return ['ok' => true, 'patient_id' => $patientId];
}

function create_rnova_task_for_response($response) {
    if (trim((string)($response['mis_task_id'] ?? '')) !== '') {
        return ['ok' => true, 'patient_id' => $response['mis_patient_id'] ?? null, 'task' => $response['mis_task'] ?? null, 'task_id' => $response['mis_task_id'], 'skipped' => true];
    }
    $patientResult = rnova_ensure_patient($response);
    if (!$patientResult['ok']) return $patientResult;
    $patientId = $patientResult['patient_id'];
    $responseUrl = app_base_url() . '?page=response-view&id=' . rawurlencode((string)($response['id'] ?? ''));
    $due = (new DateTimeImmutable('+2 days'))->format('Y-m-d');
    $taskPayload = [
        'role_id' => (int)rnova_config('RNOVA_ADMIN_ROLE_ID', (string)RNOVA_ADMIN_ROLE_ID),
        'user_id' => (int)rnova_config('RNOVA_EMPLOYEE_ID', (string)RNOVA_EMPLOYEE_ID),
        'title' => 'Анализ анкеты ' . ($response['survey'] ?? ''),
        'desc' => $responseUrl,
        'patient_id' => $patientId,
        'due_date' => rnova_date($due),
    ];
    $missingTaskFields = rnova_payload_missing_fields('createTask', $taskPayload);
    if ($missingTaskFields) {
        return ['ok' => false, 'error' => 'Для создания задачи RNOVA не заполнены обязательные параметры: ' . implode(', ', $missingTaskFields) . '.'];
    }
    $task = rnova_request('POST', 'createTask', $taskPayload);
    if (!$task['ok']) return $task;
    $taskId = rnova_response_id($task['data'] ?? []);
    if (!$taskId) {
        return ['ok' => false, 'error' => 'RNOVA создала задачу, но не вернула ID задачи.', 'patient_id' => $patientId, 'task' => $task['data']];
    }
    return ['ok' => true, 'patient_id' => $patientId, 'task' => $task['data'], 'task_id' => $taskId];
}

function rnova_task_record($data, $taskId) {
    if (!is_array($data)) return null;
    $recordId = trim((string)($data['id'] ?? $data['task_id'] ?? ''));
    if ($recordId !== '' && $recordId === trim((string)$taskId) && trim((string)($data['patient_id'] ?? '')) !== '') {
        return $data;
    }
    foreach ($data as $value) {
        if (!is_array($value)) continue;
        $record = rnova_task_record($value, $taskId);
        if ($record) return $record;
    }
    return null;
}

function rnova_task_patient($taskId) {
    $task = rnova_request('POST', 'getTasks', ['task_id' => $taskId]);
    if (!$task['ok']) return $task;
    $record = rnova_task_record($task['data'] ?? [], $taskId);
    if (!$record) {
        return ['ok' => false, 'error' => 'RNOVA не вернула задачу #' . $taskId . ' с привязанным пациентом.'];
    }
    return ['ok' => true, 'patient_id' => $record['patient_id'], 'task' => $record];
}

function attach_response_pdf_to_rnova_task($response) {
    $taskId = trim((string)($response['mis_task_id'] ?? ''));
    if ($taskId === '') {
        return ['ok' => false, 'error' => 'Для прикрепления PDF не найден ID текущей задачи RNOVA. Сначала создайте активную задачу.'];
    }
    // Always resolve the patient from the current RNOVA task. Do not trust a
    // locally stored patient ID and do not search for or create patients here.
    $taskPatient = rnova_task_patient($taskId);
    if (!$taskPatient['ok']) return $taskPatient;
    $patientId = trim((string)$taskPatient['patient_id']);
    $pdf = simple_pdf_document(response_pdf_blocks($response), 'Расшифровка анкеты', response_pdf_patient_name($response), $response['patient']['sex'] ?? '');
    if (strlen($pdf) > 10 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'PDF превышает максимальный размер файла RNOVA (10 МБ).'];
    }
    $filePayload = rnova_filter_payload([
        'patient_id' => $patientId,
        'title' => 'Ответы анкеты ' . ($response['id'] ?? '') . '.pdf',
        'type' => 'pdf',
        'content' => base64_encode($pdf),
        'is_api_on' => '1',
        'source' => 'Задача RNOVA #' . $taskId . ': ' . ($response['survey'] ?? 'Анкета'),
        'document_type' => 'doctor',
    ]);
    $missingFileFields = rnova_payload_missing_fields('uploadFile', $filePayload);
    if ($missingFileFields) {
        return ['ok' => false, 'error' => 'Для загрузки PDF в карточку пациента RNOVA не заполнены обязательные параметры: ' . implode(', ', $missingFileFields) . '.'];
    }
    // uploadFile stores the document in the patient's MIS card. RNOVA's method
    // has no task_id parameter, so the source identifies the questionnaire task;
    // mis_task_id remains the authoritative local link to that active task.
    $file = rnova_request('POST', 'uploadFile', $filePayload);
    if (!$file['ok']) return $file;
    return ['ok' => true, 'patient_id' => $patientId, 'task_id' => $taskId, 'file' => $file['data']];
}

function send_response_to_rnova($response) {
    $task = create_rnova_task_for_response($response);
    if (!$task['ok']) return $task;
    $response['mis_patient_id'] = $task['patient_id'] ?? ($response['mis_patient_id'] ?? null);
    $response['mis_task_id'] = $task['task_id'] ?? ($response['mis_task_id'] ?? null);
    $file = attach_response_pdf_to_rnova_task($response);
    if (!$file['ok']) return $file;
    return $task + ['file' => $file['file'] ?? null];
}

function queue_rnova_task_for_response_id($responseId) {
    $response = $responseId ? find_patient_response($responseId) : null;
    if (!$response) return ['ok' => false, 'error' => 'Ответ пациента не найден.'];
    $task = create_rnova_task_for_response($response);
    if ($task['ok'] && empty($task['skipped'])) {
        update_patient_response($responseId, function ($item) use ($task) {
            $item['mis_sent_at'] = date('c');
            $item['mis_patient_id'] = $task['patient_id'] ?? null;
            $item['mis_task_id'] = $task['task_id'] ?? null;
            $item['mis_task'] = $task['task'] ?? null;
            $item['history'][] = ['date' => date('c'), 'event' => 'В RNOVA создана активная задача без PDF'];
            return $item;
        });
    }
    return $task;
}


function generate_ai_analysis_for_response($response) {
    if (!is_array($response)) {
        return ['ok' => false, 'error' => 'Ответ пациента не найден.'];
    }
    $readableAnswers = is_array($response['answers'] ?? null) ? $response['answers'] : [];
    $hints = is_array($response['hints'] ?? null) ? $response['hints'] : [];
    if (!response_has_medical_answers($readableAnswers)) {
        return ['ok' => false, 'error' => 'Нельзя создать ИИ-ответ: медицинские блоки анкеты пустые.'];
    }

$system = <<<SYS
Ты лучший врач мира, эксперт по превентивной, интегративной и доказательной медицине.
Твоя задача — внимательно проанализировать анкету пациента и дать медицински грамотные рекомендации по обследованиям.

ВАЖНЫЕ ПРАВИЛА:

1. Анализируй только ответы анкеты из блоков 2-19.
2. Блок 1 с персональными данными пациента не используй для медицинского анализа.
3. Не ставь окончательный диагноз. Формулируй выводы как возможные причины, риски или направления для проверки.
4. Ты обязан рекомендовать анализы и обследования, которые подходят под жалобы, цели визита, симптомы, анамнез и наследственность пациента.
5. Если в анкете/PDF напротив выбранного пациентом ответа были указаны анализы, обследования или консультации врача, эти рекомендации считаются обязательными.
6. Все обязательные рекомендации из поля internal_hints должны быть включены в recommended_tests или specialists.
7. Не удаляй и не игнорируй анализы из internal_hints, даже если считаешь их второстепенными.
8. Если один и тот же анализ встречается несколько раз, объедини дубли.
9. Разделяй лабораторные анализы, инструментальные обследования и консультации специалистов логично, но в JSON используй заданные поля.
10. При наличии потенциально опасных симптомов добавляй их в red_flags.
11. Ответ должен быть понятным для врача и администратора клиники.
12. Ответ должен быть на русском языке.
13. Верни только валидный JSON без markdown, без поясняющего текста и без code fences.

Формат ответа строго такой:
{
  "summary": "Краткая оценка состояния пациента",
  "priority": "низкий|средний|высокий",
  "likely_issues": ["возможная причина или направление проверки"],
  "recommended_tests": ["анализ или обследование"],
  "specialists": ["специалист"],
  "red_flags": ["симптом или ситуация, требующая внимания"],
  "next_steps": ["следующий шаг"]
}

Если данных недостаточно, всё равно сформируй предварительные рекомендации на основании выбранных ответов и internal_hints.
SYS;

    $userPayload = [
        'questionnaire' => $readableAnswers,
        'internal_hints' => $hints,
        'mandatory_recommendations_from_pdf' => $hints,
        'rules' => [
            'block1_excluded_from_ai' => true,
            'language' => 'ru',
            'must_include_pdf_recommendations' => true,
            'deduplicate_tests' => true,
        ],
    ];
    $api = call_vsegpt([
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => json_encode($userPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
    ]);
    if (!$api['ok']) return $api;
    $decoded = json_decode($api['raw'], true);
    if (!is_array($decoded)) return ['ok' => false, 'error' => 'VSEGPT вернул невалидный JSON.', 'raw' => $api['raw']];
    $content = $decoded['choices'][0]['message']['content'] ?? '';
    $analysis = json_decode(extract_json($content), true);
    $html = is_array($analysis) ? ai_analysis_to_html($analysis) : '<p>ИИ-анализ пока не сформирован.</p>';
    return [
        'ok' => true,
        'analysis' => is_array($analysis) ? $analysis : null,
        'analysis_raw' => $content,
        'ai_answer_html' => $html,
        'vsegpt_cost' => vsegpt_cost_from_response($decoded),
        'vsegpt_usage_json' => json_encode($decoded['usage'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
    ];
}

/*
  API endpoint
*/

if (in_array(($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['GET', 'POST'], true) && ((($_GET['action'] ?? '') === 'download_response_pdf') || (postv('action') === 'download_response_pdf'))) {
    require_auth_for_action('download_response_pdf');
    $id = trim((string)(($_GET['id'] ?? '') ?: postv('id')));
    $response = $id !== '' ? find_patient_response($id) : null;
    if (!$response) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Ответ пациента не найден.';
        exit;
    }
    $postedHtml = trim((string)postv('ai_answer_html'));
    $postedText = trim((string)postv('ai_answer_text'));
    $pdfBlocks = $postedHtml !== '' || $postedText !== ''
        ? response_pdf_blocks_from_html($postedHtml !== '' ? $postedHtml : nl2br(e($postedText)), $postedText)
        : response_pdf_blocks($response);
    $filename = 'response-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$id) . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo simple_pdf_document($pdfBlocks, 'Расшифровка анкеты', response_pdf_patient_name($response), $response['patient']['sex'] ?? '');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_auth_for_action((string)postv('action'));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && postv('action') === 'login') {
    $next = trim((string)postv('next', '?page=questionnaires'));
    if (login_user(postv('login'), postv('password'))) {
        header('Location: ' . ($next !== '' ? $next : '?page=questionnaires'));
        exit;
    }
    header('Location: ?page=login&error=1&next=' . rawurlencode($next));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && postv('action') === 'save_settings') {
    $rawPrice = str_replace(',', '.', trim((string)postv('questionnaire_price', '0')));
    $price = is_numeric($rawPrice) ? max(0, (float)$rawPrice) : 0;
    save_app_setting('questionnaire_price', (string)$price);
    header('Location: ?page=settings&saved=1');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && postv('action') === 'update_profile') {
    $user = current_user();
    if (!$user) redirect_to_login();
    $login = trim((string)postv('login'));
    $password = (string)postv('password');
    if ($login === '') {
        header('Location: ?page=profile&error=empty');
        exit;
    }
    $params = [$login];
    $sql = 'UPDATE app_users SET login=?, updated_at=?';
    $params[] = date('Y-m-d H:i:s');
    if ($password !== '') {
        $sql .= ', password_hash=?';
        $params[] = password_hash($password, PASSWORD_DEFAULT);
    }
    $sql .= ' WHERE id=?';
    $params[] = (int)$user['id'];
    try {
        db()->prepare($sql)->execute($params);
        header('Location: ?page=profile&saved=1');
    } catch (Throwable $e) {
        header('Location: ?page=profile&error=duplicate');
    }
    exit;
}


if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && postv('action') === 'save_user') {
    $id = (int)postv('id', 0);
    try {
        $ok = save_app_user($id, postv('full_name'), postv('email'), postv('password'));
        header('Location: ?page=users' . ($ok ? '&saved=1' : '&error=1'));
    } catch (Throwable $e) {
        header('Location: ?page=user-edit' . ($id ? '&id=' . $id : '') . '&error=1');
    }
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && postv('action') === 'delete_user') {
    $ok = delete_app_user((int)postv('id'));
    header('Location: ?page=users' . ($ok ? '&deleted=1' : '&error=delete'));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && postv('action') === 'save_questionnaire') {
    $id = trim((string)postv('id'));
    $title = trim((string)postv('title', 'Новая анкета')) ?: 'Новая анкета';
    $payload = trim((string)postv('builder_json'));
    $sections = db_json_decode($payload, []);
    if (!$sections) $sections = questionnaire_sections_static();
    $now = date('Y-m-d H:i:s');
    if ($id === '') {
        $id = questionnaire_id_from_title($title);
        $stmt = db()->prepare('INSERT INTO questionnaires (id,title,created_at,updated_at) VALUES (?,?,?,?)');
        $stmt->execute([$id, $title, $now, $now]);
        questionnaire_save_structure($id, $sections);
        save_hint_config(default_hint_config($sections), $id);
    } else {
        $stmt = db()->prepare('UPDATE questionnaires SET title=?, updated_at=? WHERE id=? AND deleted_at IS NULL');
        $stmt->execute([$title, $now, $id]);
        questionnaire_save_structure($id, $sections);
    }
    header('Location: ?page=questionnaires&saved=1');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && postv('action') === 'delete_questionnaire') {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim((string)postv('id'));
    if ($id === '') {
        echo json_encode(['ok' => false, 'message' => 'Не указан ID анкеты.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $now = date('Y-m-d H:i:s');
    $stmt = db()->prepare('UPDATE questionnaires SET deleted_at=?, updated_at=? WHERE id=? AND deleted_at IS NULL');
    $stmt->execute([$now, $now, $id]);
    $ok = $stmt->rowCount() > 0;
    echo json_encode(['ok' => $ok, 'message' => $ok ? 'Анкета удалена.' : 'Анкета не найдена.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (postv('action') === 'save_hints')) {
    header('Content-Type: application/json; charset=utf-8');

    $questionnaire = questionnaire_find(postv('questionnaire_id', $_GET['qid'] ?? ($_GET['id'] ?? null)));
    $sections = $questionnaire['sections'] ?? questionnaire_sections_static();
    $config = hints_config_from_post($sections, $_POST);

    if (!save_hint_config($config, $questionnaire['id'] ?? null)) {
        echo json_encode([
            'ok' => false,
            'error' => 'Не удалось сохранить настройки ИИ в базе данных.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Настройки ИИ сохранены.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}


if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (postv('action') === 'save_ai_answer')) {
    header('Content-Type: application/json; charset=utf-8');

    $id = trim((string)postv('id'));
    $html = sanitize_editor_html((string)postv('ai_answer_html'));
    if ($id === '' || $html === '') {
        echo json_encode(['ok' => false, 'error' => 'Не передан ответ ИИ для сохранения.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $saved = update_patient_response($id, function ($response) use ($html) {
        $response['ai_answer_html'] = $html;
        $response['history'][] = ['date' => date('c'), 'event' => 'ИИ-ответ отредактирован'];
        return $response;
    });

    echo json_encode([
        'ok' => $saved,
        'message' => $saved ? 'ИИ-ответ сохранён.' : 'Ответ пациента не найден.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && postv('action') === 'update_patient_details') {
    $id = trim((string)postv('id'));
    $patientFields = [
        'surname' => 255,
        'name' => 255,
        'patronymic' => 255,
        'dob' => 32,
        'phone' => 64,
    ];
    $patient = [];
    foreach ($patientFields as $field => $maxLength) {
        $value = trim((string)postv('patient_' . $field));
        $patient[$field] = function_exists('mb_substr')
            ? mb_substr($value, 0, $maxLength, 'UTF-8')
            : substr($value, 0, $maxLength);
    }

    if ($id === '') {
        header('Location: ?page=responses&patient_update=error');
        exit;
    }

    $saved = update_patient_response($id, function ($response) use ($patient) {
        $response['patient'] = array_merge(
            is_array($response['patient'] ?? null) ? $response['patient'] : [],
            $patient
        );
        $response['history'][] = ['date' => date('c'), 'event' => 'Данные пациента отредактированы'];
        return $response;
    });

    header('Location: ?page=response-view&id=' . rawurlencode($id) . '&patient_update=' . ($saved ? 'success' : 'error'));
    exit;
}



if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (postv('action') === 'bulk_create_rnova_tasks')) {
    header('Content-Type: application/json; charset=utf-8');
    $items = patient_response_items();
    $created = 0;
    $skipped = 0;
    $errors = [];
    foreach ($items as $item) {
        if (response_has_rnova_task($item)) {
            $skipped++;
            continue;
        }
        $task = queue_rnova_task_for_response_id($item['id'] ?? '');
        if (!empty($task['ok'])) {
            if (empty($task['skipped'])) $created++;
            else $skipped++;
        } else {
            $errors[] = ($item['patient_display'] ?? ($item['id'] ?? 'Ответ')) . ': ' . ($task['error'] ?? 'не удалось создать задачу RNOVA');
        }
    }
    echo json_encode([
        'ok' => count($errors) === 0,
        'created' => $created,
        'skipped' => $skipped,
        'errors' => $errors,
        'message' => 'Создано задач RNOVA: ' . $created . '. Пропущено: ' . $skipped . (count($errors) ? '. Ошибок: ' . count($errors) : '.'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (postv('action') === 'regenerate_ai_answer')) {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim((string)postv('id'));
    $response = $id !== '' ? find_patient_response($id) : null;
    if (!$response) {
        echo json_encode(['ok' => false, 'error' => 'Ответ пациента не найден.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if (response_has_ai_analysis($response)) {
        echo json_encode(['ok' => false, 'error' => 'ИИ-ответ уже создан.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $generated = generate_ai_analysis_for_response($response);
    if (!empty($generated['ok'])) {
        update_patient_response($id, function ($item) use ($generated) {
            $item['analysis'] = $generated['analysis'] ?? null;
            $item['analysis_raw'] = $generated['analysis_raw'] ?? '';
            $item['ai_answer_html'] = $generated['ai_answer_html'] ?? '<p>ИИ-анализ пока не сформирован.</p>';
            $item['vsegpt_cost'] = (float)($generated['vsegpt_cost'] ?? 0);
            $item['billing_amount'] = (float)($generated['vsegpt_cost'] ?? 0) * 3;
            $item['vsegpt_usage_json'] = $generated['vsegpt_usage_json'] ?? '';
            $item['history'][] = ['date' => date('c'), 'event' => 'ИИ-ответ пересоздан'];
            return $item;
        });
    }
    echo json_encode($generated + ['message' => !empty($generated['ok']) ? 'ИИ-ответ пересоздан.' : ($generated['error'] ?? 'Не удалось пересоздать ИИ-ответ.')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (postv('action') === 'delete_response')) {
    header('Content-Type: application/json; charset=utf-8');
    $ok = delete_patient_response(trim((string)postv('id')));
    echo json_encode(['ok' => $ok, 'message' => $ok ? 'Ответ удалён.' : 'Ответ пациента не найден.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (postv('action') === 'mark_processed')) {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim((string)postv('id'));
    $response = $id !== '' ? find_patient_response($id) : null;
    if (!$response) {
        echo json_encode(['ok' => false, 'error' => 'Ответ пациента не найден.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $postedHtml = trim((string)postv('ai_answer_html'));
    if ($postedHtml !== '') {
        $response['ai_answer_html'] = sanitize_editor_html($postedHtml);
    }
    $taskCreated = false;
    if (!response_has_rnova_task($response)) {
        $task = create_rnova_task_for_response($response);
        if (!$task['ok']) {
            echo json_encode($task + ['message' => $task['error'] ?? 'Не удалось создать задачу RNOVA.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        $response['mis_patient_id'] = $task['patient_id'] ?? null;
        $response['mis_task_id'] = $task['task_id'] ?? null;
        $response['mis_task'] = $task['task'] ?? null;
        $taskCreated = empty($task['skipped']);
        if ($taskCreated) {
            // Store the new link before uploading. If RNOVA rejects the file,
            // a retry attaches to this task instead of creating a duplicate.
            update_patient_response($response['id'], function ($item) use ($response) {
                $item['mis_sent_at'] = date('c');
                $item['mis_patient_id'] = $response['mis_patient_id'];
                $item['mis_task_id'] = $response['mis_task_id'];
                $item['mis_task'] = $response['mis_task'];
                $item['history'][] = ['date' => date('c'), 'event' => 'В RNOVA создана активная задача для анкеты'];
                return $item;
            });
        }
    }
    $sent = attach_response_pdf_to_rnova_task($response);
    if ($sent['ok']) {
        update_patient_response($response['id'], function ($item) use ($sent, $response) {
            $item['status'] = 'processed';
            $item['ai_answer_html'] = $response['ai_answer_html'] ?? $item['ai_answer_html'];
            $item['mis_sent_at'] = date('c');
            $item['mis_patient_id'] = $sent['patient_id'] ?? null;
            $item['mis_task_id'] = $sent['task_id'] ?? ($item['mis_task_id'] ?? null);
            $item['mis_task'] = $response['mis_task'] ?? ($item['mis_task'] ?? null);
            $item['mis_file'] = $sent['file'] ?? null;
            $item['history'][] = ['date' => date('c'), 'event' => 'PDF прикреплён к карточке пациента RNOVA и связан с задачей анкеты, задача не закрывалась'];
            return $item;
        });
    }
    echo json_encode($sent + ['message' => $sent['ok'] ? 'Анкета обработана: ' . ($taskCreated ? 'создана задача RNOVA и ' : '') . 'PDF прикреплён к карточке пациента в RNOVA и связан с текущей задачей анкеты.' : ($sent['error'] ?? 'Ошибка отправки в МИС.')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (postv('action') === 'send_to_mis')) {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim((string)postv('id'));
    $response = $id !== '' ? find_patient_response($id) : null;
    if (!$response) {
        echo json_encode(['ok' => false, 'error' => 'Ответ пациента не найден.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $postedHtml = trim((string)postv('ai_answer_html'));
    if ($postedHtml !== '') {
        $response['ai_answer_html'] = sanitize_editor_html($postedHtml);
    }
    $sent = send_response_to_rnova($response);
    if ($sent['ok']) {
        update_patient_response($response['id'], function ($item) use ($sent, $response) {
            $item['mis_sent_at'] = date('c');
            $item['mis_patient_id'] = $sent['patient_id'] ?? null;
            $item['mis_task_id'] = $sent['task_id'] ?? ($item['mis_task_id'] ?? null);
            $item['mis_task'] = $sent['task'] ?? ($item['mis_task'] ?? null);
            $item['mis_file'] = $sent['file'] ?? null;
            $item['history'][] = ['date' => date('c'), 'event' => 'PDF прикреплён к карточке пациента RNOVA и связан с активной задачей анкеты'];
            return $item;
        });
    }
    echo json_encode($sent + ['message' => $sent['ok'] ? 'Ответ отправлен в МИС.' : ($sent['error'] ?? 'Ошибка отправки в МИС.')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (postv('action') === 'analyze')) {
    header('Content-Type: application/json; charset=utf-8');

    if (postv('personal_data_consent') !== '1' || postv('offer_agreement_consent') !== '1') {
        echo json_encode([
            'ok' => false,
            'error' => 'Для отправки анкеты необходимо подтвердить согласие на обработку персональных данных и принять условия договора-оферты.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $questionnaire = questionnaire_find(postv('questionnaire_id', $_GET['qid'] ?? ($_GET['id'] ?? null)));
    $sections = $questionnaire['sections'] ?? questionnaire_sections_static();
    $hintConfig = load_hint_config($sections, $questionnaire['id'] ?? null);
    $sections = apply_hint_config($sections, $hintConfig);

    $surname = trim((string)postv('surname'));
    $name = trim((string)postv('name'));
    $patronymic = trim((string)postv('patronymic'));
    $dob = trim((string)postv('dob'));

    if ($surname === '' || $name === '' || $dob === '') {
        echo json_encode([
            'ok' => false,
            'error' => 'Заполните обязательные поля пациента: фамилия, имя и дата рождения.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $phone = trim((string)postv('phone'));

    $patient = [
        'surname' => $surname,
        'name' => $name,
        'patronymic' => $patronymic,
        'dob' => $dob,
        'phone' => $phone,
        'email' => trim((string)postv('email')),
        'sex' => trim((string)postv('sex')),
        'height' => trim((string)postv('height')),
        'weight' => trim((string)postv('weight')),
        'waist' => trim((string)postv('waist')),
        'filled_at' => trim((string)postv('filled_at', date('Y-m-d'))),
    ];
    if (!filter_var($patient['email'], FILTER_VALIDATE_EMAIL)) {
        echo json_encode([
            'ok' => false,
            'error' => 'Введите корректный адрес электронной почты.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $GLOBALS['current_response_survey_title'] = $questionnaire['title'] ?? 'Анкета здоровья';
    $GLOBALS['current_response_questionnaire_id'] = $questionnaire['id'] ?? null;
    list($readableAnswers, $hints) = build_ai_payload($sections, $_POST);
    $draftRecord = build_response_record($sections, $patient, $readableAnswers, $hints);
    if (!response_has_medical_answers($readableAnswers)) {
        $responseId = add_patient_response($draftRecord);
        $rnovaTask = $responseId ? queue_rnova_task_for_response_id($responseId) : null;
        $emailResult = $responseId ? send_patient_questionnaire_confirmation($patient) : skipped_email_result();
        echo json_encode([
            'ok' => (bool)$responseId,
            'message' => $responseId ? 'Анкета успешно отправлена.' : 'Не удалось сохранить ответ в базе данных.',
            'email_sent' => $emailResult['sent'],
            'email_status' => $emailResult['status'],
            'email_error' => $emailResult['error'],
            'rnova_task' => $rnovaTask,
            'warning' => 'VSEGPT не вызывался, потому что медицинские блоки анкеты пустые.',
            'response_id' => $responseId,
            'payment_url' => $responseId ? prodamus_payment_url($responseId, $patient) : '',
            'patient' => $patient,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

$system = <<<SYS
Ты лучший врач мира, эксперт по превентивной, интегративной и доказательной медицине.
Твоя задача — внимательно проанализировать анкету пациента и дать медицински грамотные рекомендации по обследованиям.

ВАЖНЫЕ ПРАВИЛА:

1. Анализируй только ответы анкеты из блоков 2-19.
2. Блок 1 с персональными данными пациента не используй для медицинского анализа.
3. Не ставь окончательный диагноз. Формулируй выводы как возможные причины, риски или направления для проверки.
4. Ты обязан рекомендовать анализы и обследования, которые подходят под жалобы, цели визита, симптомы, анамнез и наследственность пациента.
5. Если в анкете/PDF напротив выбранного пациентом ответа были указаны анализы, обследования или консультации врача, эти рекомендации считаются обязательными.
6. Все обязательные рекомендации из поля internal_hints должны быть включены в recommended_tests или specialists.
7. Не удаляй и не игнорируй анализы из internal_hints, даже если считаешь их второстепенными.
8. Если один и тот же анализ встречается несколько раз, объедини дубли.
9. Разделяй лабораторные анализы, инструментальные обследования и консультации специалистов логично, но в JSON используй заданные поля.
10. При наличии потенциально опасных симптомов добавляй их в red_flags.
11. Ответ должен быть понятным для врача и администратора клиники.
12. Ответ должен быть на русском языке.
13. Верни только валидный JSON без markdown, без поясняющего текста и без code fences.

Формат ответа строго такой:
{
  "summary": "Краткая оценка состояния пациента",
  "priority": "низкий|средний|высокий",
  "likely_issues": ["возможная причина или направление проверки"],
  "recommended_tests": ["анализ или обследование"],
  "specialists": ["специалист"],
  "red_flags": ["симптом или ситуация, требующая внимания"],
  "next_steps": ["следующий шаг"]
}

Расшифровка:
- summary — краткое резюме состояния по анкете.
- priority — общий приоритет обращения: низкий, средний или высокий.
- likely_issues — возможные направления поиска причин, без постановки диагноза.
- recommended_tests — лабораторные анализы и инструментальные обследования. Сюда обязательно включай всё из internal_hints, если это анализ или обследование.
- specialists — врачи-специалисты. Сюда обязательно включай всё из internal_hints, если это консультация специалиста.
- red_flags — тревожные признаки, если они есть.
- next_steps — практические следующие шаги.

Если данных недостаточно, всё равно сформируй предварительные рекомендации на основании выбранных ответов и internal_hints.
SYS;

$userPayload = [
    'questionnaire' => $readableAnswers,
    'internal_hints' => $hints,
    'mandatory_recommendations_from_pdf' => $hints,
    'rules' => [
        'block1_excluded_from_ai' => true,
        'language' => 'ru',
        'must_include_pdf_recommendations' => true,
        'deduplicate_tests' => true,
    ],
];

    $messages = [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => json_encode($userPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
    ];

    $api = call_vsegpt($messages);

    if (!$api['ok']) {
        $responseId = add_patient_response($draftRecord);
        $rnovaTask = $responseId ? queue_rnova_task_for_response_id($responseId) : null;
        $emailResult = $responseId ? send_patient_questionnaire_confirmation($patient) : skipped_email_result();
        echo json_encode([
            'ok' => (bool)$responseId,
            'message' => $responseId ? 'Ответ успешно отправлен.' : 'Ответ получен, но не удалось сохранить его в базе данных.',
            'rnova_task' => $rnovaTask,
            'email_sent' => $emailResult['sent'],
            'email_status' => $emailResult['status'],
            'email_error' => $emailResult['error'],
            'warning' => $api['error'],
            'response_id' => $responseId,
            'payment_url' => $responseId ? prodamus_payment_url($responseId, $patient) : '',
            'patient' => $patient,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $decoded = json_decode($api['raw'], true);
    if (!is_array($decoded)) {
        $responseId = add_patient_response($draftRecord);
        $rnovaTask = $responseId ? queue_rnova_task_for_response_id($responseId) : null;
        $emailResult = $responseId ? send_patient_questionnaire_confirmation($patient) : skipped_email_result();
        echo json_encode([
            'ok' => (bool)$responseId,
            'message' => $responseId ? 'Ответ успешно отправлен.' : 'Ответ получен, но не удалось сохранить его в базе данных.',
            'rnova_task' => $rnovaTask,
            'email_sent' => $emailResult['sent'],
            'email_status' => $emailResult['status'],
            'email_error' => $emailResult['error'],
            'warning' => 'VSEGPT вернул невалидный JSON.',
            'response_id' => $responseId,
            'payment_url' => $responseId ? prodamus_payment_url($responseId, $patient) : '',
            'patient' => $patient,
            'raw' => $api['raw'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $content = $decoded['choices'][0]['message']['content'] ?? '';
    $clean = extract_json($content);
    $analysis = json_decode($clean, true);

    $record = build_response_record($sections, $patient, $readableAnswers, $hints, is_array($analysis) ? $analysis : null, $content);
    $vsegptCost = vsegpt_cost_from_response($decoded);
    $record['vsegpt_cost'] = $vsegptCost;
    $record['billing_amount'] = $vsegptCost * 3;
    $record['vsegpt_usage_json'] = json_encode($decoded['usage'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    $record['history'][] = ['date' => date('c'), 'event' => 'Сгенерировано ИИ'];
    $responseId = add_patient_response($record);
    $rnovaTask = $responseId ? queue_rnova_task_for_response_id($responseId) : null;
    $emailResult = $responseId ? send_patient_questionnaire_confirmation($patient) : skipped_email_result();

    echo json_encode([
        'ok' => (bool)$responseId,
        'rnova_task' => $rnovaTask,
        'email_sent' => $emailResult['sent'],
        'email_status' => $emailResult['status'],
        'email_error' => $emailResult['error'],
        'message' => $responseId ? 'Ответ успешно отправлен.' : 'Ответ получен, но не удалось сохранить его в базе данных.',
        'response_id' => $responseId,
        'payment_url' => $responseId ? prodamus_payment_url($responseId, $patient) : '',
        'patient' => $patient,
        'analysis' => is_array($analysis) ? $analysis : null,
        'analysis_raw' => $content,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}


function questionnaire_list_items($sections) {
    $items = [];
    $responseCount = count(load_patient_responses()['responses'] ?? []);
    for ($i = 0; isset($sections[$i]); $i++) {
        $meta = section_meta($i + 2, $sections[$i]);
        $items[] = [
            'title' => $meta['title'],
            'category' => 'Раздел анкеты здоровья',
            'status' => 'active',
            'created' => '—',
            'updated' => '—',
            'author' => '—',
            'responses' => $responseCount,
            'icon' => $meta['icon'],
            'color' => 'green',
        ];
    }

    return $items;
}

function patient_response_items() {
    $data = load_patient_responses();
    $items = [];

    foreach ($data['responses'] as $response) {
        $rawPatient = $response['patient'] ?? [];
        $legacyPatientName = !is_array($rawPatient) ? trim((string)$rawPatient) : '';
        $patient = is_array($rawPatient) ? $rawPatient : [];
        $completion = response_completion_stats($response['answers'] ?? []);
        if ($completion['total'] === 0 && isset($response['progress'])) {
            $completion['percent'] = max(0, min(100, (int)$response['progress']));
        }
        $age = patient_age($patient['dob'] ?? '');
        $metaParts = [];
        if ($age !== null) {
            $metaParts[] = $age . ' лет';
        }
        if (!empty($patient['sex'])) {
            $metaParts[] = (string)$patient['sex'];
        }
        $items[] = array_merge($response, [
            'patient' => $patient,
            'patient_name' => $legacyPatientName !== '' ? $legacyPatientName : response_full_name($patient),
            'patient_display' => $legacyPatientName !== '' ? $legacyPatientName : response_full_name($patient),
            'meta' => $metaParts ? implode(', ', $metaParts) : 'Данные пациента',
            'avatar' => initials_for_patient($patient),
            'survey' => response_questionnaire_title($response),
            'category' => $response['category'] ?? '',
            'date' => format_response_date_only($patient['filled_at'] ?? $response['created_at'] ?? ''),
            'doctor' => 'Иванова Е. А.',
            'status' => $response['status'] ?? 'completed',
            'progress' => $completion['percent'],
            'filled_answers' => $completion['filled'],
            'total_answers' => $completion['total'],
            'filled_label' => $completion['total'] > 0 ? ($completion['filled'] . ' из ' . $completion['total']) : ($completion['percent'] . '%'),
            'rnova_task_created' => response_has_rnova_task($response),
            'ai_analyzed' => response_has_ai_analysis($response),
        ]);
    }

    return $items;
}

function patient_response_view_data() {
    return find_patient_response($_GET['id'] ?? null);
}

function response_actual_status($response) {
    $status = (string)($response['status'] ?? '');
    if ($status === 'processed') return 'processed';
    if ($status === 'draft' && (int)($response['filled_answers'] ?? 0) <= 0 && (int)($response['progress'] ?? 0) <= 0) return 'draft';
    return 'in_work';
}

function response_yes_no_label($value) {
    return $value ? 'Да' : 'Нет';
}

function response_has_rnova_task($response) {
    return trim((string)($response['mis_task_id'] ?? '')) !== '';
}

function response_has_ai_analysis($response) {
    $html = trim(strip_tags((string)($response['ai_answer_html'] ?? '')));
    return $html !== '' && $html !== 'ИИ-анализ пока не сформирован.';
}

function response_status_label($status) {
    $labels = [
        'completed' => 'В работе',
        'in_work' => 'В работе',
        'processed' => 'Обработана',
        'progress' => 'В процессе',
        'draft' => 'Черновик',
    ];
    return $labels[$status] ?? 'В процессе';
}

function status_label($status) {
    $labels = [
        'active' => 'Активна',
        'draft' => 'Черновик',
        'archive' => 'Архивная',
    ];
    return $labels[$status] ?? 'Активна';
}

$page = (string)($_GET['page'] ?? 'questionnaires');
if ($page === 'logout') { logout_user(); header('Location: ?page=login'); exit; }
require_auth_for_page($page);
$authUser = current_user();
$currentQuestionnaire = questionnaire_find($_GET['qid'] ?? null);
$sections = $currentQuestionnaire['sections'] ?? questionnaire_sections_static();
$hintConfig = load_hint_config($sections, $currentQuestionnaire['id'] ?? null);
$sections = apply_hint_config($sections, $hintConfig);

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $page === 'login' ? 'Вход' : ($page === 'form' ? 'Анкета здоровья' : ($page === 'profile' ? 'Профиль' : ($page === 'response-view' ? 'Просмотр ответа пациента' : ($page === 'questionnaire-edit' ? 'Редактирование анкеты' : ($page === 'questionnaires' ? 'Анкеты' : 'Ответы пациентов'))))) ?></title>
    <style>
        :root{
            --bg:#f4f6f3;
            --card:#ffffff;
            --soft:#f7f9f7;
            --soft-green:#edf5f1;
            --line:#dfe6e1;
            --green:#00b4d8;
            --green-dark:#00b4d8;
            --text:#273235;
            --muted:#71807c;
            --accent:#d9534f;
            --shadow:0 18px 55px rgba(40,55,50,.08);
        }
        *{box-sizing:border-box}
        html{scroll-behavior:smooth}
        body{
            margin:0;
            min-height:100vh;
            font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
            color:var(--text);
            background:
                radial-gradient(circle at 20% 0%, rgba(0,180,216,.12), transparent 32%),
                linear-gradient(180deg,#fbfcfb 0%,var(--bg) 100%);
            font-size:14px;
        }
        .wrap{
            max-width:1320px;
            margin:16px auto;
            padding:20px 22px 24px;
            background:rgba(255,255,255,.82);
            border:1px solid rgba(223,230,225,.9);
            border-radius:18px;
            box-shadow:var(--shadow);
        }
        .hero{
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            gap:18px;
            padding:0 6px 18px;
            margin-bottom:0;
            border-bottom:1px solid var(--line);
        }
        .hero-title{display:flex;align-items:center;gap:18px}
        .hero-logo{
            width:62px;height:62px;border-radius:50%;
            color:var(--green-dark);
            display:grid;place-items:center;
            font-size:35px;line-height:1;
            background:linear-gradient(145deg,#fff,#f1f6f3);
        }
        .hero h1{
            margin:0 0 4px;
            font-size:31px;
            line-height:1;
            letter-spacing:.03em;
            color:var(--green-dark);
        }
        .hero p{margin:0;color:var(--muted);font-size:15px}
        .date-card{
            min-width:210px;
            padding:14px 18px;
            border:1px solid var(--line);
            border-radius:12px;
            color:var(--muted);
            background:#fff;
            display:flex;
            justify-content:flex-start;
            gap:14px;
            align-items:center;
            box-shadow:0 6px 18px rgba(40,55,50,.04);
        }
        .date-card b{display:block;color:var(--text);margin-top:5px}
        form{display:grid;gap:8px;margin-top:0}
        .section-card{
            position:relative;
            display:grid;
            grid-template-columns:360px minmax(0,1fr);
            gap:20px;
            min-height:120px;
            padding:22px 22px 18px 18px;
            background:rgba(255,255,255,.92);
            border:1px solid var(--line);
            border-radius:14px;
            box-shadow:0 6px 22px rgba(35,50,45,.04);
        }
        .section-card:first-of-type{margin-top:0}
        .section-aside{display:grid;grid-template-columns:42px 1fr;gap:18px;align-items:flex-start}
        .section-number{
            width:28px;height:28px;border-radius:6px;
            display:grid;place-items:center;
            background:var(--green-dark);color:#fff;font-weight:800;
            box-shadow:0 4px 10px rgba(71,120,105,.2);
        }
        .section-icon{
            width:66px;height:72px;border-radius:10px;
            display:grid;place-items:center;
            background:linear-gradient(145deg,#f8faf9,#eef3f0);
            color:var(--green-dark);
            border:1px solid #edf1ee;
        }
        .icon-svg,.logo-svg{width:34px;height:34px;stroke:currentColor;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}
        .logo-svg{width:40px;height:40px}.app-logo-img{width:100%;height:100%;object-fit:contain;border-radius:inherit}
        .section-aside h2{
            margin:5px 0 9px;
            font-size:16px;
            text-transform:uppercase;
            letter-spacing:.03em;
            line-height:1.25;
        }
        .section-aside p{margin:0;color:var(--muted);line-height:1.45}
        .section-content{display:grid;grid-template-columns:1fr;gap:18px;align-content:start;min-width:0}
        .question{min-width:0;padding:0;border:0}
        .question-label{font-size:13px;font-weight:700;margin:0 0 9px;color:#394447;line-height:1.3}
        .field{
            width:100%;
            border:1px solid #dfe6e2;
            border-radius:7px;
            padding:10px 12px;
            font:inherit;
            color:var(--text);
            background:#fff;
            outline:none;
            transition:border-color .18s,box-shadow .18s,background .18s;
        }
        .field:focus{border-color:var(--green);box-shadow:0 0 0 3px rgba(0,180,216,.12)}
        textarea.field{resize:vertical;min-height:76px}
        .inline-options,.checklist{display:grid;gap:11px;grid-template-columns:1fr;max-width:100%}
        .inline-options label,.checklist label{display:flex;align-items:flex-start;gap:10px;color:#455155;line-height:1.35;cursor:pointer;min-width:0;overflow-wrap:anywhere}
        input[type="radio"],input[type="checkbox"]{accent-color:var(--green-dark);width:16px;height:16px;margin:0;flex:0 0 auto}
        .inline-options + .field,.checklist + .field{margin-top:12px;max-width:280px}
        .top-grid{display:grid;grid-template-columns:1fr;gap:16px}
        .top-grid .question{border-bottom:1px solid #edf1ee;padding-bottom:8px}
        .section-content .question:has(textarea),.section-content .question:has(.checklist){grid-column:1 / -1}
        .sex-hide{display:none}
        .consent-card{margin:0 0 22px;padding:22px;border:1px solid var(--line);border-radius:16px;background:#fff;box-shadow:var(--shadow);display:grid;gap:16px}
        .consent-option{display:flex;align-items:flex-start;gap:12px;color:#303942;line-height:1.55;font-weight:600}
        .consent-option input{margin-top:4px}
        .consent-option a{color:var(--green-dark);text-decoration:underline;text-underline-offset:3px}
        .actions{
            position:sticky;bottom:0;z-index:20;
            display:flex;justify-content:flex-end;gap:18px;align-items:center;
            padding:18px 14px 0;
            background:linear-gradient(180deg,rgba(244,246,243,0),rgba(255,255,255,.95) 32%,rgba(255,255,255,.98));
        }
        .progress-card{
            margin-right:auto;
            width:570px;
            display:flex;align-items:center;gap:18px;
            padding:14px 20px;
            border:1px solid var(--line);
            border-radius:12px;
            background:#fff;
            color:var(--muted);
        }
        .progress-icon{width:34px;height:34px;border-radius:50%;display:grid;place-items:center;border:1px solid var(--green);color:var(--green-dark);font-weight:800;flex:0 0 auto}
        .progress-text{white-space:nowrap}
        .progress-track{height:6px;border-radius:999px;background:#e6ece8;overflow:hidden;flex:1}.progress-fill{display:block;height:100%;width:0;background:var(--green-dark);transition:width .2s ease}
        .btn{
            appearance:none;border:none;border-radius:10px;
            padding:15px 32px;min-width:178px;
            background:linear-gradient(135deg,var(--green-dark),#00b4d8);
            color:#fff;font:800 14px/1 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;
            letter-spacing:.03em;cursor:pointer;
            box-shadow:0 10px 26px rgba(71,120,105,.18);
        }
        .btn:disabled{opacity:.7;cursor:not-allowed}
        .btn-secondary{background:#fff;color:var(--green-dark);border:1.5px solid var(--green-dark);box-shadow:none}
        .btn-reset{background:#fff;color:#6a7674;border:1px solid var(--line);box-shadow:none;min-width:260px;text-align:left}
        .result{margin-top:18px;background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:var(--shadow);padding:18px}.result h2,.result h3{color:var(--green-dark)}
        .badge{display:inline-block;padding:6px 10px;border-radius:999px;background:#eef5f1;color:var(--green-dark);font-weight:700;font-size:13px}.error{color:var(--accent);font-weight:700;white-space:pre-wrap}ul.result-list{margin:8px 0 0 18px}pre{white-space:pre-wrap;word-break:break-word;background:#fafcfb;border:1px solid var(--line);padding:14px;border-radius:12px}
        .modal-backdrop{position:fixed;inset:0;display:none;align-items:center;justify-content:center;padding:40px;background:rgba(38,45,44,.52);backdrop-filter:blur(3px);z-index:1000}.modal-backdrop.is-open{display:flex}
        .modal{width:min(1380px,calc(100vw - 80px));height:min(900px,calc(100vh - 80px));background:#fff;border-radius:18px;box-shadow:0 26px 70px rgba(20,30,28,.35);display:flex;flex-direction:column;overflow:hidden}
        .modal-head{display:flex;justify-content:space-between;gap:18px;padding:24px 34px;border-bottom:1px solid var(--line);background:#fff}.modal-head h2{margin:0 0 8px;color:#2f3a3d;font-size:24px}.modal-head p{margin:0;color:var(--muted);font-size:15px}.modal-close{width:38px;height:38px;border:0;background:transparent;color:#64706d;font-size:34px;line-height:1;cursor:pointer}
        .modal-form{min-height:0;display:flex;flex-direction:column;flex:1}.modal-layout{min-height:0;display:grid;grid-template-columns:300px minmax(0,1fr);gap:34px;flex:1;padding:26px 34px 28px}.hint-nav{position:sticky;top:24px;align-self:start;max-height:calc(100vh - 260px);overflow:auto;padding:14px 12px;background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:0 14px 34px rgba(40,55,50,.06)}.hint-nav-title{position:sticky;top:0;z-index:2;margin:-14px -12px 10px;padding:14px 12px 12px;background:linear-gradient(180deg,#fff 78%,rgba(255,255,255,.92));border-bottom:1px solid #edf1ee}.hint-nav-title span{display:block;margin-bottom:4px;color:var(--green-dark);font-size:11px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}.hint-nav-title strong{display:block;color:#25303a;font-size:15px}.hint-nav-block{border-radius:10px;margin-bottom:10px;padding:4px}.hint-nav-block.is-active{background:var(--soft-green)}.hint-nav-item{display:grid;grid-template-columns:34px 1fr;align-items:center;gap:10px;padding:8px 10px;border-radius:8px;text-decoration:none;color:#4c5a5d}.hint-nav-item span{width:24px;height:24px;border-radius:7px;display:grid;place-items:center;background:#f2f5f3;color:#66736e;font-weight:800}.hint-nav-item b{font-size:14px;font-weight:700;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}.hint-nav-block.is-active .hint-nav-item{color:var(--green-dark)}.hint-nav-block.is-active .hint-nav-item span{background:#dcebe5;color:var(--green-dark)}.hint-nav-questions{display:grid;gap:2px;margin:0 4px 6px 48px}.hint-nav-questions a{display:block;padding:5px 8px;border-radius:6px;color:#62706d;text-decoration:none;font-size:12px;line-height:1.25}.hint-nav-questions a:hover{background:#fff;color:var(--green-dark)}
        .hint-editor{overflow:auto;padding-right:14px;scroll-behavior:smooth}.hint-section{margin-bottom:32px;scroll-margin-top:22px}.hint-section h3{margin:0 0 24px;color:#2f3a3d;font-size:20px;text-transform:uppercase;letter-spacing:.02em}.hint-question{margin-bottom:30px;scroll-margin-top:22px}.hint-question-title{display:flex;align-items:flex-start;gap:12px;margin:0 0 14px}.hint-question-title span{flex:0 0 auto;border-radius:999px;background:var(--soft-green);color:var(--green-dark);font-size:12px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;padding:6px 10px}.hint-question-title h4{margin:2px 0 0;color:#25303a;font-size:18px;line-height:1.35}.hint-panel{background:#f4f8f6;border:1px solid #eef4f0;border-radius:8px;padding:20px 20px 18px;margin-bottom:24px}.hint-panel-title{color:var(--green-dark);font-weight:800;margin-bottom:7px;font-size:15px}.hint-help{color:var(--muted);font-size:13px;margin-bottom:14px}.hint-textarea-wrap{position:relative;min-width:0}.hint-field{min-height:92px;border-radius:7px;padding:16px 44px 28px 16px;background:#fff}.hint-counter{position:absolute;right:12px;bottom:9px;color:#6f7b78;font-size:12px}.hint-option-list{display:grid;gap:16px}.hint-option-row{display:grid;grid-template-columns:18px 220px minmax(0,1fr);gap:16px;align-items:start}.hint-radio{width:16px;height:16px;border:1.5px solid #b5bfbb;border-radius:50%;margin-top:15px}.hint-option-name{width:100%;border:1px solid #dfe6e2;background:#fff;border-radius:0;padding:14px 16px;color:#394447;font:inherit}.modal-actions{display:flex;align-items:center;gap:18px;padding:22px 34px;border-top:1px solid var(--line);background:#fff}.hint-status{margin-right:auto;color:#6f7b78;font-size:14px;line-height:1.35}

        .app-shell{min-height:100vh;display:grid;grid-template-columns:320px minmax(0,1fr);background:#fbfcfb;color:#303942}
        .admin-sidebar{position:sticky;top:0;height:100vh;border-right:1px solid #e4e9e6;background:rgba(255,255,255,.92);display:flex;flex-direction:column;padding:34px 18px 32px;box-shadow:8px 0 32px rgba(42,55,50,.035)}
        .brand{display:flex;align-items:center;gap:16px;padding:0 12px 36px}.brand-mark{width:66px;height:66px;border-radius:50%;display:grid;place-items:center;color:var(--green-dark);background:#fff}.brand-mark .logo-svg{width:40px;height:40px}.app-logo-img{width:100%;height:100%;object-fit:contain;border-radius:inherit}.brand-title{font-weight:900;color:var(--green-dark);letter-spacing:.05em;font-size:18px}.brand-subtitle{margin-top:5px;color:#64716d;font-size:12px;line-height:1.35}.admin-nav{display:grid;gap:10px}.admin-nav a{display:flex;align-items:center;gap:14px;padding:14px 18px;border-radius:10px;color:#515d67;text-decoration:none;font-weight:600}.admin-nav a.is-active{background:#f2f6f4;color:var(--green-dark)}.admin-nav svg{width:23px;height:23px;stroke:currentColor;stroke-width:1.7;fill:none;stroke-linecap:round;stroke-linejoin:round}.ai-card{margin-top:auto;display:grid;grid-template-columns:46px 1fr 18px;gap:14px;align-items:center;padding:22px 18px;border:1px solid #dfe8e3;border-radius:10px;background:#fff;box-shadow:0 14px 28px rgba(31,43,39,.04);color:var(--green-dark);text-decoration:none}.ai-card strong{display:block;margin-bottom:7px}.ai-card span{color:#6b7774;line-height:1.35}.doctor-card{margin-top:120px;display:flex;align-items:center;gap:13px;padding:20px 12px;border:1px solid #dfe8e3;border-radius:10px;background:#fff}.doctor-avatar{width:58px;height:58px;border-radius:50%;display:grid;place-items:center;background:linear-gradient(135deg,#e9e0d8,#b8c8c2);font-size:31px}.doctor-name{font-weight:800;color:#394349}.doctor-role{color:#75807c;font-size:13px;line-height:1.25}.doctor-card svg{margin-left:auto;width:18px;height:18px;stroke:#51615c;fill:none}
        .admin-main{padding:54px 40px 34px;min-width:0}.admin-header{display:flex;justify-content:space-between;align-items:flex-start;gap:24px;margin-bottom:36px}.admin-title h1{font-size:34px;line-height:1.1;margin:0 0 12px;color:#2e363d}.admin-title p{margin:0;color:#59636d;font-size:17px}.create-btn{display:inline-flex;align-items:center;gap:14px;padding:18px 24px;border-radius:7px;background:linear-gradient(135deg,var(--green-dark),#5b8f7f);color:#fff;text-decoration:none;font-weight:800;box-shadow:0 12px 28px rgba(71,120,105,.2)}.create-btn svg{width:20px;height:20px;stroke:currentColor;stroke-width:2;fill:none}
        .filters-actions-row{display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:20px}.filters-actions-row .filters-card{margin-bottom:0}.filters-card{display:grid;grid-template-columns:minmax(260px,1.8fr) 190px 230px 190px 210px;gap:28px;align-items:end;padding:32px 22px;border:1px solid #dfe6e2;border-radius:10px;background:#fff;margin-bottom:20px}.filter-field{position:relative}.filter-field label{position:absolute;left:12px;top:-13px;background:#fff;padding:0 7px;color:#65716d;font-size:12px}.filter-control{width:100%;height:52px;border:1px solid #dfe6e2;border-radius:7px;background:#fff;color:#46515a;font:inherit;padding:0 14px}.search-control{padding-left:56px}.search-icon{position:absolute;left:20px;bottom:15px;color:var(--green-dark)}.reset-filter{height:48px;border:1px solid #dfe6e2;border-radius:7px;background:#fff;color:#4e5963;font-weight:600;font:inherit;display:flex;align-items:center;justify-content:center;gap:12px}.reset-filter svg,.search-icon{width:20px;height:20px;stroke:currentColor;stroke-width:1.8;fill:none;stroke-linecap:round;stroke-linejoin:round}
        .questionnaire-table{border:1px solid #dfe6e2;border-radius:10px;background:#fff;overflow:hidden}.table-head,.table-row{display:grid;grid-template-columns:2.15fr 1.18fr .92fr 1.22fr 1.22fr .86fr 1.12fr;align-items:center;column-gap:24px}.table-head{padding:0 22px;height:58px;color:#303b45;font-size:13px;font-weight:800}.table-row{min-height:122px;padding:0 22px;border-top:1px solid #e7ece9}.survey-name{display:grid;grid-template-columns:60px 1fr;gap:24px;align-items:center;min-width:0}.survey-icon{width:60px;height:60px;border-radius:9px;display:grid;place-items:center;background:#edf5f1;color:var(--green-dark)}.survey-icon .icon-svg{width:36px;height:36px}.survey-icon.purple{background:#f0eafa;color:#7455c7}.survey-icon.blue{background:#edf8ff;color:#1687d9}.survey-icon.violet{background:#f4eef8;color:#805bb7}.survey-icon.amber{background:#fff6e0;color:#b98900}.survey-icon.rose{background:#fff0f2;color:#bd5c72}.survey-icon.orange{background:#fff3e9;color:#c66f27}.survey-title{font-weight:800;color:#25303a;margin-bottom:7px;line-height:1.35}.survey-summary,.table-muted{color:#5e6974;line-height:1.55}.status-pill{display:inline-flex;align-items:center;gap:8px;border-radius:6px;padding:8px 11px;font-weight:800;font-size:13px}.status-pill:before{content:"";width:9px;height:9px;border-radius:50%;background:currentColor}.status-active{background:#e9f4ef;color:#3b806e}.status-draft{background:#fff6df;color:#b98500}.status-archive{background:#f0f2f3;color:#77808a}.responses-count{color:var(--green-dark);font-weight:700}.actions-cell{display:flex;gap:16px;justify-content:flex-end}.icon-button{width:52px;height:52px;border:1px solid #dfe6e2;border-radius:7px;background:#fff;display:grid;place-items:center;color:#28333c;text-decoration:none;cursor:pointer}.icon-button:disabled{cursor:not-allowed;opacity:.55}.icon-button.is-copied{border-color:var(--green-dark);background:#edf5f1;color:var(--green-dark);opacity:1}.copy-success-mark{font-size:24px;font-weight:900;line-height:1}.icon-button svg{width:20px;height:20px;stroke:currentColor;stroke-width:1.8;fill:none;stroke-linecap:round;stroke-linejoin:round}.sort-mark{color:var(--green-dark);font-size:18px;margin-left:8px}.table-footer{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:18px 22px 12px;color:#66716c}.pagination{display:flex;align-items:center;gap:16px}.page-btn,.page-number{width:40px;height:40px;border:1px solid transparent;background:#fff;border-radius:8px;display:grid;place-items:center;color:#26323a;text-decoration:none}.page-btn{border-color:#dfe6e2}.page-number.is-current{border-color:var(--green-dark);color:var(--green-dark)}.per-page{display:flex;align-items:center;gap:14px}.per-page select{width:86px;height:46px;border:1px solid #dfe6e2;border-radius:7px;background:#fff;padding:0 14px;color:#59636d}
        .responses-header{margin-bottom:34px}.response-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:36px;margin-bottom:30px}.stat-card{min-height:92px;border:1px solid #dfe6e2;border-radius:10px;background:#fff;display:flex;align-items:center;gap:20px;padding:20px 28px}.stat-card span{width:58px;height:58px;border-radius:8px;display:grid;place-items:center;background:#edf5f1;color:var(--green-dark);flex:0 0 auto}.stat-card .icon-svg{width:30px;height:30px}.stat-card strong{display:block;font-size:26px;line-height:1;color:#2c3540;margin-bottom:9px}.stat-card p{margin:0;color:#5f6974}.stat-blue span{background:#eef8ff;color:#1477d4}.stat-amber span{background:#fff7e5;color:#df9d05}.responses-table .table-head,.responses-table .table-row{grid-template-columns:1.45fr 1.45fr 1.15fr .85fr .9fr .55fr .45fr .58fr}.responses-table .table-row{min-height:84px}.patient-name{display:grid;grid-template-columns:44px 1fr;gap:16px;align-items:center;min-width:0}.patient-avatar{width:44px;height:44px;border-radius:50%;display:grid;place-items:center;background:#edf1ef;overflow:hidden;font-size:13px;font-weight:900;color:var(--green-dark)}.response-pill{display:inline-flex;border-radius:6px;padding:7px 10px;font-weight:800;font-size:12px}.response-completed{background:#e4f0eb;color:#2f806d}.response-in_work{background:#e7f2ff;color:#1c67bf}.response-processed{background:#e6fbff;color:#008fb0}.response-progress{background:#e7f2ff;color:#1c67bf}.response-draft{background:#edf0f2;color:#606a74}.progress-cell{display:grid;gap:10px;align-content:center}.progress-cell strong{font-size:15px;color:#2d3741}.mini-progress{display:block;width:150px;max-width:100%;height:5px;border-radius:999px;background:#e1e6e9;overflow:hidden}.mini-progress i{display:block;height:100%;border-radius:inherit;background:var(--green-dark)}
        .view-main{padding-top:46px}.breadcrumbs{display:flex;align-items:center;gap:12px;margin-bottom:22px;color:#66716c;font-size:14px}.breadcrumbs a{color:#66716c;text-decoration:none}.view-header{display:flex;justify-content:space-between;gap:24px;align-items:flex-start;margin-bottom:28px}.view-title h1{margin:0 0 16px;font-size:34px;line-height:1.1;color:#2e363d}.view-meta{display:flex;align-items:center;gap:24px;flex-wrap:wrap;color:#59636d}.view-meta span{display:inline-flex;align-items:center;gap:8px}.view-status,.ai-generated{display:inline-flex;align-items:center;border-radius:999px;padding:8px 14px;background:#e4f0eb;color:#2f806d;font-size:12px;font-weight:800}.view-actions{display:flex;gap:14px;align-items:center}.outline-btn,.more-btn{height:56px;border:1px solid #dfe6e2;border-radius:7px;background:#fff;color:#3e4b53;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:12px;padding:0 22px;font-weight:800}.outline-btn svg,.more-btn svg{width:20px;height:20px;stroke:currentColor;stroke-width:1.8;fill:none;stroke-linecap:round;stroke-linejoin:round}.more-btn{width:56px;padding:0}.view-grid{display:grid;grid-template-columns:minmax(360px,.9fr) minmax(520px,1.1fr);gap:24px}.view-card{border:1px solid #dfe6e2;border-radius:12px;background:#fff;padding:22px;box-shadow:0 12px 30px rgba(40,55,50,.035)}.view-card h2{margin:0 0 20px;color:#25303a;font-size:20px}.patient-details-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.patient-details-form .question-label{margin-bottom:7px}.patient-details-form .patient-details-actions{grid-column:1/-1;display:flex;justify-content:flex-end;margin-top:4px}.patient-details-form .outline-btn{height:46px}.answer-section{border:1px solid #dfe6e2;border-radius:9px;padding:16px 18px;margin-bottom:12px}.answer-section h3{margin:0 0 14px;display:flex;align-items:center;gap:12px;color:var(--green-dark);font-size:14px;text-transform:uppercase}.answer-section h3 span{width:24px;height:24px;border-radius:5px;display:grid;place-items:center;background:var(--green-dark);color:#fff;font-size:13px}.answer-row{display:grid;grid-template-columns:1fr 1fr;gap:18px;padding:6px 0;color:#334049}.answer-row small{color:#53605d;font-weight:700}.answer-section p{margin:8px 0;color:#334049;line-height:1.55}.answer-table{width:100%;border-collapse:collapse;font-size:13px}.answer-table th,.answer-table td{padding:9px 4px;border-bottom:1px solid #edf1ef;text-align:left}.answer-table th{color:#53605d}.show-all{width:100%;height:52px;border:1px solid #dfe6e2;border-radius:8px;background:#fff;color:#25303a;font-weight:800}.ai-card-view{padding:0;overflow:hidden}.ai-panel{padding:24px 28px;border:1px solid #dfe6e2;border-radius:9px;margin-bottom:18px;line-height:1.7}.ai-panel h3,.editor-title{margin:0 0 14px;color:var(--green-dark);font-size:16px}.ai-panel ul,.ai-panel ol,.editor-content ul,.editor-content ol{margin:8px 0 22px 20px;padding:0}.ai-panel li,.editor-content li{margin:8px 0}.editor-box{border:1px solid #dfe6e2;border-radius:9px;overflow:hidden}.editor-toolbar{display:flex;gap:4px;align-items:center;height:44px;border-bottom:1px solid #dfe6e2;background:#fbfcfb;padding:0 12px;color:#4c5962}.tool{width:28px;height:28px;display:grid;place-items:center;border-radius:5px;font-weight:800}.editor-content{padding:18px 22px;line-height:1.65;min-height:430px}.char-count{text-align:right;color:#7c8783;font-size:12px;margin-top:8px}.save-note{margin-top:18px;border-radius:8px;background:#edf5f1;color:var(--green-dark);padding:15px 18px}.history-card{margin-top:24px}.history-row{display:grid;grid-template-columns:150px 1fr;gap:16px;margin:12px 0;color:#59636d}.history-card .outline-btn{height:42px;float:right;margin-top:-50px}


        .login-shell{min-height:100vh;display:grid;place-items:center;padding:28px;background:radial-gradient(circle at 18% 8%,rgba(0,180,216,.16),transparent 34%),linear-gradient(135deg,#fbfcfb,#edf5f1)}.login-card{width:min(440px,100%);border:1px solid rgba(223,230,225,.95);border-radius:26px;background:rgba(255,255,255,.9);box-shadow:var(--shadow);padding:34px;backdrop-filter:blur(12px)}.login-card .brand{padding:0 0 28px}.login-card h1{margin:0 0 10px;font-size:30px;color:#25303a}.login-card p{margin:0 0 24px;color:var(--muted);line-height:1.55}.login-card form{gap:16px}.login-card .btn{width:100%;margin-top:8px}.login-error{border:1px solid #f0c7c5;background:#fff4f3;color:#b7433f;border-radius:12px;padding:12px 14px;font-weight:700}.sidebar-bottom{margin-top:auto;display:grid;gap:12px}.profile-card,.logout-card{display:flex;align-items:center;gap:12px;padding:14px 16px;border:1px solid #dfe8e3;border-radius:10px;background:#fff;color:#394349;text-decoration:none;font-weight:800}.profile-card.is-active{background:#f2f6f4;color:var(--green-dark)}.logout-card{color:#b7433f}.profile-form{max-width:620px;display:grid;gap:20px}.notice{border-radius:12px;padding:14px 16px;background:#edf5f1;color:var(--green-dark);font-weight:800}.notice-error{background:#fff4f3;color:#b7433f;border:1px solid #f0c7c5}
        .settings-panel{display:block;position:static;background:transparent;padding:0}.settings-panel .modal{width:100%;height:auto;min-height:calc(100vh - 190px);overflow:visible;box-shadow:var(--shadow);border:1px solid var(--line)}.settings-panel .modal-form,.settings-panel .modal-layout{overflow:visible}.settings-panel .modal-layout{align-items:start}.settings-panel .hint-nav{top:24px;max-height:calc(100vh - 48px);z-index:5}.settings-panel .hint-editor{overflow:visible}.settings-panel .modal-close,.settings-panel #cancelAiHints{display:none}.settings-panel .modal-actions{position:sticky;bottom:0;background:#fff}
        .success-dialog .modal-close{position:absolute;top:12px;right:14px}.success-modal{position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(38,45,44,.48);z-index:1200}.success-modal.is-open{display:flex}.success-dialog{width:min(460px,calc(100vw - 32px));background:#fff;border-radius:24px;box-shadow:0 24px 70px rgba(20,30,28,.28);padding:34px;text-align:center;position:relative}.success-icon{width:68px;height:68px;margin:0 auto 18px;border-radius:50%;display:grid;place-items:center;background:#edf5f1;color:var(--green-dark);font-size:38px;font-weight:800}.success-dialog h2{margin:0 0 10px;color:#2d3937}.success-dialog p{margin:0 0 24px;color:var(--muted);line-height:1.55}.editor-toolbar button.tool{border:0;background:#f3f6f4;cursor:pointer}.editor-content[contenteditable="true"]{outline:none;min-height:280px}.outline-btn,.create-btn{border:0;cursor:pointer;text-decoration:none}.empty-state{padding:28px;color:var(--muted);text-align:center}
        @media (max-width:1100px){.app-shell{grid-template-columns:1fr}.admin-sidebar{position:relative;height:auto}.filters-card,.table-head,.table-row,.response-stats{grid-template-columns:1fr}.table-head{display:none}.table-row{gap:12px;padding:22px}.doctor-card{margin-top:24px}.section-card{grid-template-columns:1fr}.section-aside{grid-template-columns:36px 1fr}.section-content,.top-grid,.patient-details-form{grid-template-columns:1fr}.progress-card{display:none}.modal-layout{grid-template-columns:1fr}.hint-nav{display:none}.modal{width:calc(100vw - 28px);height:calc(100vh - 28px)}.hint-option-row{grid-template-columns:18px 1fr}.hint-option-row .hint-textarea-wrap{grid-column:2}.actions{flex-wrap:wrap}}
        @media (max-width:720px){.admin-main{padding:26px 14px}.admin-header{display:block}.create-btn{margin-top:18px}.filters-card{gap:16px;padding:22px 14px}.survey-name{grid-template-columns:48px 1fr;gap:14px}.survey-icon{width:48px;height:48px}.table-footer{display:grid;justify-items:start}.wrap{margin:0;border-radius:0;padding:16px 12px}.hero{display:block}.date-card{margin-top:14px}.section-content,.top-grid,.inline-options,.checklist{grid-template-columns:1fr}.section-aside{grid-template-columns:34px 1fr}.btn{width:100%;min-width:0}.actions{position:static;padding-left:0;padding-right:0}.modal-backdrop{padding:8px}.modal-head,.modal-layout,.modal-actions{padding-left:18px;padding-right:18px}.modal-actions{flex-direction:column}.hint-status{margin-right:0}.btn-reset{min-width:0;width:100%}}
    </style>
</head>
<body>
<?php if ($page === 'login'): ?>
<div class="login-shell">
    <section class="login-card">
        <div class="brand"><div class="brand-mark" aria-hidden="true"><?= hero_logo_svg() ?></div><div><div class="brand-title">AdaptogenzzClinic</div><div class="brand-subtitle">закрытая зона сервиса</div></div></div>
        <h1>Вход в сервис</h1><p>Введите логин и пароль, чтобы открыть управление анкетами и ответами пациентов.</p>
        <?php if (isset($_GET['error'])): ?><div class="login-error">Неверный логин или пароль.</div><?php endif; ?>
        <form method="post"><input type="hidden" name="action" value="login"><input type="hidden" name="next" value="<?= e($_GET['next'] ?? '?page=questionnaires') ?>"><div class="question"><div class="question-label">Логин</div><input class="field" name="login" autocomplete="username" required autofocus></div><div class="question"><div class="question-label">Пароль</div><input class="field" type="password" name="password" autocomplete="current-password" required></div><button class="btn" type="submit">Войти</button></form>
    </section>
</div>
<?php elseif ($page !== 'form'): ?>
<div class="app-shell">
    <aside class="admin-sidebar">
        <div class="brand">
            <div class="brand-mark" aria-hidden="true"><?= hero_logo_svg() ?></div>
            <div>
                <div class="brand-title">AdaptogenzzClinic</div>
                <div class="brand-subtitle">закрытая зона сервиса</div>
            </div>
        </div>
        <nav class="admin-nav" aria-label="Основное меню">
            <a class="<?= ($page === 'questionnaires' || $page === 'questionnaire-edit') ? 'is-active' : '' ?>" href="?page=questionnaires"><svg viewBox="0 0 24 24"><path d="M6 3h12v18H6z"/><path d="M9 8h6M9 12h6M9 16h4"/></svg><span>Анкеты</span></a>
            <a class="<?= ($page === 'responses' || $page === 'response-view') ? 'is-active' : '' ?>" href="?page=responses"><svg viewBox="0 0 24 24"><path d="M4 5h14v14H4z"/><path d="M8 9h6M8 13h4M18 12l2 2 3-5"/></svg><span>Ответы пациентов</span></a>
            <a class="<?= $page === 'billing' ? 'is-active' : '' ?>" href="?page=billing"><svg viewBox="0 0 24 24"><path d="M4 7h16v12H4z"/><path d="M4 11h16"/><path d="M8 16h3"/></svg><span>Биллинг</span></a>
            <a class="<?= $page === 'settings' ? 'is-active' : '' ?>" href="?page=settings"><svg viewBox="0 0 24 24"><path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1A2 2 0 1 1 4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.6-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1A2 2 0 1 1 7 4.2l.1.1a1.7 1.7 0 0 0 1.9.3h.1A1.7 1.7 0 0 0 10 3.1V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.6h.1a1.7 1.7 0 0 0 1.9-.3l.1-.1A2 2 0 1 1 19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9v.1a1.7 1.7 0 0 0 1.6.9h.1a2 2 0 1 1 0 4H21a1.7 1.7 0 0 0-1.6 1Z"/></svg><span>Настройки</span></a>
            <a class="<?= ($page === 'users' || $page === 'user-edit') ? 'is-active' : '' ?>" href="?page=users"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-8 0v2"/><path d="M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/><path d="M22 21v-2a3 3 0 0 0-2-2.8"/></svg><span>Пользователи</span></a>
            <a class="<?= $page === 'profile' ? 'is-active' : '' ?>" href="?page=profile"><svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><path d="M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/></svg><span>Профиль</span></a>
        </nav>
        <div class="sidebar-bottom"><a class="logout-card" href="?page=logout">⎋ Выйти</a></div>
    </aside>
    <?php if ($page === 'users'): ?>
    <?php $users = app_users_all(); ?>
    <main class="admin-main">
        <header class="admin-header responses-header"><div class="admin-title"><h1>Пользователи</h1><p>Управляйте сотрудниками, которым разрешён вход в сервис.</p></div><a class="create-btn" href="?page=user-edit">+ Добавить пользователя</a></header>
        <?php if (isset($_GET['saved'])): ?><div class="notice" style="margin-bottom:18px">Пользователь сохранён.</div><?php endif; ?>
        <?php if (isset($_GET['deleted'])): ?><div class="notice" style="margin-bottom:18px">Пользователь удалён.</div><?php endif; ?>
        <?php if (isset($_GET['error'])): ?><div class="notice notice-error" style="margin-bottom:18px">Операция не выполнена. Проверьте данные пользователя.</div><?php endif; ?>
        <section class="responses-table questionnaire-table">
            <div class="table-head" style="grid-template-columns:1.5fr 1.5fr 180px"><div>ФИО</div><div>Email</div><div>Действия</div></div>
            <?php if (!$users): ?><div class="empty-state">Пользователи пока не созданы.</div><?php endif; ?>
            <?php foreach ($users as $user): ?>
                <article class="table-row" style="grid-template-columns:1.5fr 1.5fr 180px">
                    <div class="survey-title"><?= e($user['full_name'] ?: $user['login']) ?></div>
                    <div><?= e($user['email'] ?: $user['login']) ?></div>
                    <div class="actions-cell">
                        <a class="icon-button" href="?page=user-edit&amp;id=<?= e($user['id']) ?>" title="Редактировать"><svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z"/></svg></a>
                        <form method="post" onsubmit="return confirm('Удалить пользователя?')" style="margin:0;display:block"><input type="hidden" name="action" value="delete_user"><input type="hidden" name="id" value="<?= e($user['id']) ?>"><button class="icon-button" type="submit" title="Удалить" <?= (int)$user['id'] === (int)($authUser['id'] ?? 0) ? 'disabled' : '' ?>><svg viewBox="0 0 24 24"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="m19 6-1 14H6L5 6"/></svg></button></form>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    </main>
    <?php elseif ($page === 'user-edit'): ?>
    <?php $editUser = app_user_find((int)($_GET['id'] ?? 0)); ?>
    <main class="admin-main">
        <header class="admin-header responses-header"><div class="admin-title"><h1><?= e($editUser ? 'Редактирование пользователя' : 'Новый пользователь') ?></h1></div><a class="outline-btn" href="?page=users">← К списку</a></header>
        <?php if (isset($_GET['error'])): ?><div class="notice notice-error" style="margin-bottom:18px">Не удалось сохранить пользователя. Email должен быть уникальным, пароль обязателен для нового пользователя.</div><?php endif; ?>
        <section class="view-card profile-form"><form method="post" class="profile-form"><input type="hidden" name="action" value="save_user"><input type="hidden" name="id" value="<?= e($editUser['id'] ?? 0) ?>"><div class="question"><div class="question-label">ФИО</div><input class="field" name="full_name" value="<?= e($editUser['full_name'] ?? '') ?>" required></div><div class="question"><div class="question-label">Email</div><input class="field" type="email" name="email" value="<?= e($editUser['email'] ?? $editUser['login'] ?? '') ?>" required></div><div class="question"><div class="question-label">Пароль</div><input class="field" type="password" name="password" autocomplete="new-password" <?= $editUser ? 'placeholder="Оставьте пустым, чтобы не менять"' : 'required' ?>></div><div class="actions" style="position:static;padding:0"><button class="btn" type="submit">Сохранить</button></div></form></section>
    </main>
    <?php elseif ($page === 'settings'): ?>
    <?php $currentPrice = questionnaire_price(); ?>
    <main class="admin-main">
        <header class="admin-header responses-header"><div class="admin-title"><h1>Настройки</h1><p>Управляйте стоимостью анкеты для оплаты через Prodamus.</p></div></header>
        <section class="view-card profile-form">
            <?php if (isset($_GET['saved'])): ?><div class="notice">Настройки сохранены.</div><?php endif; ?>
            <form method="post" class="profile-form">
                <input type="hidden" name="action" value="save_settings">
                <div class="question"><div class="question-label">Стоимость анкеты, ₽</div><input class="field" type="number" name="questionnaire_price" value="<?= e(money_amount_input_value($currentPrice)) ?>" min="0" step="0.01" required><p class="hint-help">Если указать 0, оплата через Prodamus не выполняется: анкета отправляется сразу.</p></div>
                <div class="actions" style="position:static;padding:0"><button class="btn" type="submit">Сохранить настройки</button></div>
            </form>
        </section>
    </main>

    <?php elseif ($page === 'profile'): ?>

    <main class="admin-main">
        <header class="admin-header responses-header"><div class="admin-title"><h1>Профиль</h1></div></header>
        <section class="view-card profile-form">
            <?php if (isset($_GET['saved'])): ?><div class="notice">Профиль сохранён.</div><?php endif; ?>
            <?php if (isset($_GET['error'])): ?><div class="notice notice-error">Не удалось сохранить профиль. Проверьте логин: он должен быть уникальным и не пустым.</div><?php endif; ?>
            <form method="post" class="profile-form"><input type="hidden" name="action" value="update_profile"><div class="question"><div class="question-label">Логин</div><input class="field" name="login" value="<?= e($authUser['login'] ?? '') ?>" required></div><div class="question"><div class="question-label">Новый пароль</div><input class="field" type="password" name="password" autocomplete="new-password" placeholder="Оставьте пустым, чтобы не менять"></div><div class="actions" style="position:static;padding:0"><button class="btn" type="submit">Сохранить</button></div></form>
        </section>
    </main>
    <?php elseif ($page === 'billing'): ?>
    <?php list($billingFrom, $billingTo, $billingFromDateTime, $billingToDateTime) = billing_period_bounds(); $billingRows = billing_items($billingFromDateTime, $billingToDateTime); $billingTotal = array_sum(array_map(function ($row) { return (float)($row['billing_amount'] ?? 0); }, $billingRows)); ?>
    <main class="admin-main">
        <header class="admin-header responses-header">
            <div class="admin-title"><h1>Биллинг</h1></div>
        </header>
        <form class="filters-card" method="get" style="grid-template-columns:220px 220px 140px">
            <input type="hidden" name="page" value="billing">
            <div class="filter-field"><label>Дата с</label><input class="filter-control" type="date" name="from" value="<?= e($billingFrom) ?>"></div>
            <div class="filter-field"><label>Дата по</label><input class="filter-control" type="date" name="to" value="<?= e($billingTo) ?>"></div>
            <button class="reset-filter" type="submit">Показать</button>
        </form>
        <section class="response-stats" aria-label="Сводка биллинга">
            <article class="stat-card stat-green"><span><?= icon_svg('<path d="M4 7h16v12H4z"/><path d="M4 11h16"/><path d="M8 16h3"/>') ?></span><div><strong><?= e(format_money($billingTotal)) ?></strong><p>Общая сумма по фильтру</p></div></article>
        </section>
        <section class="questionnaire-table responses-table" aria-label="Биллинг VSEGPT">
            <div class="table-head" style="grid-template-columns:1.5fr 1.2fr 1fr"><div>Пациент</div><div>Анкета</div><div>Дата анализа</div></div>
            <?php if (!$billingRows): ?><div class="empty-state">За выбранный период нет запросов VSEGPT с рассчитанной стоимостью.</div><?php endif; ?>
            <?php foreach ($billingRows as $row): ?>
                <?php $patientName = trim(($row['patient_surname'] ?? '') . ' ' . ($row['patient_name'] ?? '') . ' ' . ($row['patient_patronymic'] ?? '')); ?>
                <article class="table-row" style="grid-template-columns:1.5fr 1.2fr 1fr">
                    <div class="survey-title"><?php if (!empty($row['response_exists'])): ?><a href="?page=response-view&amp;id=<?= e($row['id']) ?>"><?= e($patientName !== '' ? $patientName : 'Пациент') ?></a><?php else: ?><?= e($patientName !== '' ? $patientName : 'Пациент') ?> <span class="table-muted">(анкета удалена)</span><?php endif; ?></div>
                    <div><?= e($row['survey'] ?? 'Анкета здоровья') ?></div>
                    <div><?= e(format_response_date($row['updated_at'] ?? $row['created_at'] ?? '')) ?></div>
                </article>
            <?php endforeach; ?>
        </section>
        <footer class="table-footer"><div>Показано <?= e(count($billingRows)) ?> записей за период <?= e($billingFrom) ?> — <?= e($billingTo) ?></div></footer>
    </main>
    <?php elseif ($page === 'questionnaires'): ?>
    <?php $questionnaires = questionnaires_all(); ?>
    <main class="admin-main">
        <header class="admin-header responses-header">
            <div class="admin-title"><h1>Анкеты</h1><p>Настраивайте анкеты, ИИ-подсказки и копируйте ссылку для пациентов.</p></div>
            <a class="create-btn" href="?page=questionnaire-edit">+ Создать анкету</a>
        </header>
        <section class="responses-table">
            <div class="table-head" style="grid-template-columns:2fr 1fr 1fr 220px"><div>Название</div><div>Дата создания</div><div>Дата изменения</div><div>Действия</div></div>
            <?php foreach ($questionnaires as $q): ?>
                <?php $url = app_base_url() . '?page=form&qid=' . rawurlencode($q['id']); ?>
                <div class="table-row" style="grid-template-columns:2fr 1fr 1fr 220px">
                    <div class="survey-name"><div class="survey-icon"><?= hero_logo_svg() ?></div><div><strong><?= e($q['title']) ?></strong></div></div>
                    <div><?= e(format_response_date($q['created_at'])) ?></div>
                    <div><?= e(format_response_date($q['updated_at'])) ?></div>
                    <div class="actions-cell">
                        <a class="icon-button" href="?page=questionnaire-edit&amp;id=<?= e($q['id']) ?>" title="Редактировать" aria-label="Редактировать анкету"><svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z"/></svg></a>
                        <a class="icon-button" href="?page=questionnaire-edit&amp;id=<?= e($q['id']) ?>&amp;tab=ai" title="ИИ настройки" aria-label="Открыть ИИ настройки"><svg viewBox="0 0 24 24"><path d="M12 3 13.7 8.3 19 10l-5.3 1.7L12 17l-1.7-5.3L5 10l5.3-1.7L12 3Z"/><path d="M19 16v4"/><path d="M17 18h4"/><path d="M5 4v3"/><path d="M3.5 5.5h3"/></svg></a>
                        <button class="icon-button copy-url-btn" type="button" data-url="<?= e($url) ?>" title="Копировать URL" aria-label="Копировать ссылку на анкету"><svg viewBox="0 0 24 24"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg></button>
                        <button class="icon-button delete-questionnaire-btn" type="button" data-id="<?= e($q['id']) ?>" title="Удалить" aria-label="Удалить анкету"><svg viewBox="0 0 24 24"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="m19 6-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg></button>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
    </main>
    <?php elseif ($page === 'questionnaire-edit'): ?>
    <?php $editId = trim((string)($_GET['id'] ?? '')); $editQuestionnaire = $editId !== '' ? questionnaire_find($editId, false) : null; $editSections = $editQuestionnaire['sections'] ?? [['title'=>'Новый раздел','questions'=>[]]]; $editHints = load_hint_config($editSections, $editQuestionnaire['id'] ?? null); ?>
    <main class="admin-main">
        <header class="admin-header responses-header"><div class="admin-title"><h1><?= e($editQuestionnaire ? 'Редактирование анкеты' : 'Новая анкета') ?></h1><p>Добавляйте разделы и вопросы: текст, большое поле, выпадающий список, чекбоксы и радиокнопки.</p></div><a class="outline-btn" href="?page=questionnaires">← К списку</a></header>
        <?php if (($_GET['tab'] ?? '') === 'ai' && $editQuestionnaire): ?>
            <?= render_hints_page($editSections, $editHints) ?>
        <?php else: ?>
        <form method="post" id="questionnaireBuilderForm" class="view-card">
            <input type="hidden" name="action" value="save_questionnaire"><input type="hidden" name="id" value="<?= e($editQuestionnaire['id'] ?? '') ?>"><input type="hidden" name="builder_json" id="builderJson">
            <div class="question"><div class="question-label">Название анкеты</div><input class="field" name="title" value="<?= e($editQuestionnaire['title'] ?? 'Новая анкета') ?>" required></div>
            <div id="builderRoot"></div>
            <div class="actions" style="position:static;padding:18px 0 0"><button class="btn btn-secondary" type="button" id="addSectionBtn">+ Раздел</button><button class="btn" type="submit">Сохранить анкету</button></div>
        </form>
        <script type="application/json" id="builderInitial"><?= json_encode($editSections, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
        <?php endif; ?>
    </main>
    <?php elseif ($page === 'ai-settings'): ?>
    <main class="admin-main">
        <header class="admin-header responses-header">
            <div class="admin-title"><h1>ИИ настройки</h1><p>Редактируйте подсказки ИИ для вопросов и вариантов ответа.</p></div>
        </header>
        <?= render_hints_page($sections, $hintConfig) ?>
    </main>
    <?php elseif ($page === 'response-view'): ?>
    <?php $response = patient_response_view_data(); ?>
    <?php if (!$response): ?>
    <main class="admin-main view-main">
        <nav class="breadcrumbs" aria-label="Хлебные крошки"><a href="?page=responses">Ответы пациентов</a><span>›</span><span>Пусто</span></nav>
        <section class="view-card"><h1>Ответы пациентов не найдены</h1><p>После отправки анкеты данные появятся здесь из базы данных.</p><a class="create-btn" href="?page=form">Заполнить анкету</a></section>
    </main>
    <?php else: ?>
    <?php $patient = $response['patient'] ?? []; if (!is_array($patient)) { $patient = []; } $answerSections = $response['answers'] ?? []; if (!is_array($answerSections)) { $answerSections = []; } $history = $response['history'] ?? []; if (!is_array($history)) { $history = []; } $aiHtml = sanitize_editor_html($response['ai_answer_html'] ?? ai_analysis_to_html($response['analysis'] ?? null)); ?>
    <main class="admin-main view-main">
        <nav class="breadcrumbs" aria-label="Хлебные крошки"><a href="?page=responses">Ответы пациентов</a><span>›</span><span><?= e($response['survey']) ?></span><span>›</span><span>Просмотр</span></nav>
        <header class="view-header">
            <div class="view-title">
                <h1>Ответ пациента <span class="view-status"><?= e(response_status_label(response_actual_status($response))) ?></span></h1>
                <div class="view-meta"><span>♙ <?= e($response['patient_display'] ?? response_full_name($patient)) ?></span><span>⊙ <?= e($response['meta']) ?></span><span>Заполнено: <?= e($response['date']) ?></span></div>
            </div>
            <div class="view-actions">
                <?php if (!response_has_ai_analysis($response)): ?><button class="outline-btn" type="button" id="regenerateAiBtn">Пересоздать ИИ</button><?php endif; ?>
                <button class="outline-btn" type="button" id="markProcessedBtn">✓ Обработана</button>
                <a class="outline-btn" id="createPdfBtn" href="?action=download_response_pdf&amp;id=<?= e($response['id'] ?? '') ?>"><svg viewBox="0 0 24 24"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>Скачать PDF</a>
                <button class="create-btn" type="button" id="saveAiAnswerBtn"><svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="m16.5 3.5 4 4L8 20H4v-4L16.5 3.5Z"/></svg>Сохранить ИИ-ответ</button>
            </div>
        </header>
        <?php if (($_GET['patient_update'] ?? '') === 'success'): ?><div class="notice" style="margin-bottom:18px">Данные пациента сохранены.</div><?php endif; ?>
        <?php if (($_GET['patient_update'] ?? '') === 'error'): ?><div class="notice notice-error" style="margin-bottom:18px">Не удалось сохранить данные пациента.</div><?php endif; ?>
        <div class="view-grid">
            <section class="view-card">
                <h2>Данные пациента</h2>
                <form class="patient-details-form" method="post">
                    <input type="hidden" name="action" value="update_patient_details">
                    <input type="hidden" name="id" value="<?= e($response['id'] ?? '') ?>">
                    <label><div class="question-label">Фамилия</div><input class="field" type="text" name="patient_surname" value="<?= e($patient['surname'] ?? '') ?>" maxlength="255" autocomplete="family-name"></label>
                    <label><div class="question-label">Имя</div><input class="field" type="text" name="patient_name" value="<?= e($patient['name'] ?? '') ?>" maxlength="255" autocomplete="given-name"></label>
                    <label><div class="question-label">Отчество</div><input class="field" type="text" name="patient_patronymic" value="<?= e($patient['patronymic'] ?? '') ?>" maxlength="255" autocomplete="additional-name"></label>
                    <label><div class="question-label">Дата рождения</div><input class="field" type="date" name="patient_dob" value="<?= e($patient['dob'] ?? '') ?>" maxlength="32"></label>
                    <label><div class="question-label">Телефон</div><input class="field" type="tel" name="patient_phone" value="<?= e($patient['phone'] ?? '') ?>" maxlength="64" autocomplete="tel"></label>
                    <div class="patient-details-actions"><button class="outline-btn" type="submit">Сохранить данные пациента</button></div>
                </form>
                <hr style="border:0;border-top:1px solid #e7ece9;margin:22px 0">
                <h2>Ответы на анкету</h2>
                <article class="answer-section"><h3><span>1</span>Общая информация</h3>
                    <?php $bmi = patient_bmi($patient); ?>
                    <?php $waistRisk = patient_waist_risk($patient); ?>
                    <?php foreach ($patient as $key => $value): ?>
                        <?php if (!answer_has_value($value)) continue; ?>
                        <div class="answer-row"><small><?= e(patient_field_label($key)) ?>:</small><div><?= e(display_answer_value($value)) ?></div></div>
                        <?php if ($key === 'weight' && $bmi !== null): ?>
                            <div class="answer-row"><small>ИМТ:</small><div><?= e(format_bmi($bmi)) ?> — <?= e(bmi_category($bmi)) ?></div></div>
                        <?php endif; ?>
                        <?php if ($key === 'waist' && $waistRisk !== null): ?>
                            <div class="answer-row"><small>Оценка обхвата талии:</small><div><?= e(format_waist_risk($waistRisk)) ?></div></div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </article>
                <?php foreach ($answerSections as $idx => $section): ?>
                    <?php if (!is_array($section)) continue; ?>
                    <article class="answer-section"><h3><span><?= e($idx + 2) ?></span><?= e(section_clean_title($section['section'] ?? 'Раздел')) ?></h3>
                        <?php $sectionAnswers = is_array($section['answers'] ?? null) ? $section['answers'] : []; foreach ($sectionAnswers as $answer): ?>
                            <?php if (!is_array($answer)) continue; $value = display_answer_value($answer['answer'] ?? ''); ?>
                            <p><b><?= e($answer['question'] ?? 'Вопрос') ?></b><br><?= e($value) ?></p>
                        <?php endforeach; ?>
                    </article>
                <?php endforeach; ?>
            </section>
            <section>
                <div class="view-card ai-card-view">
                    <div style="padding:22px 22px 0"><h2>Ответ и анализ ИИ <span class="ai-generated">Сгенерировано: <?= e(format_response_date($response['updated_at'] ?? $response['created_at'] ?? '')) ?></span></h2>
                    <div class="ai-panel"><?= $aiHtml ?></div>
                    <h3 class="editor-title">Ответ ИИ <small>(можно редактировать)</small></h3>
                    <div class="editor-box">
                        <div class="editor-toolbar" role="toolbar" aria-label="Редактор ИИ-ответа">
                            <button type="button" class="tool" data-cmd="undo" title="Отменить" aria-label="Отменить">↶</button><button type="button" class="tool" data-cmd="redo" title="Повторить" aria-label="Повторить">↷</button><button type="button" class="tool" data-cmd="bold" title="Полужирный" aria-label="Полужирный"><b>B</b></button><button type="button" class="tool" data-cmd="italic" title="Курсив" aria-label="Курсив"><i>I</i></button><button type="button" class="tool" data-cmd="underline" title="Подчёркнутый" aria-label="Подчёркнутый"><u>U</u></button><button type="button" class="tool" data-cmd="formatBlock" data-value="h3" title="Заголовок" aria-label="Сделать заголовком">H</button><button type="button" class="tool" data-cmd="insertUnorderedList" title="Маркированный список" aria-label="Маркированный список">•</button><button type="button" class="tool" data-cmd="insertOrderedList" title="Нумерованный список" aria-label="Нумерованный список">1.</button>
                        </div>
                        <div class="editor-content" id="aiEditor" contenteditable="true"><?= $aiHtml ?></div>
                    </div>
                    <div class="char-count" id="aiCharCount">Символов: 0</div><div class="save-note" id="aiSaveStatus">ⓘ Этот ответ будет сохранён, включён в PDF и отправлен в МИС.</div></div>
                </div>
                <div class="view-card history-card"><h2>История изменений</h2><?php foreach ($history as $event): ?><div class="history-row"><div><?= e(format_response_date($event['date'] ?? '')) ?></div><div><?= e($event['event'] ?? '') ?></div></div><?php endforeach; ?></div>
            </section>
        </div>
    </main>
    <?php endif; ?>
    <?php else: ?>
    <main class="admin-main">
        <?php $responseItems = patient_response_items(); $statusFilter = (string)($_GET['status'] ?? 'all'); if (in_array($statusFilter, ['in_work','processed'], true)) { $responseItems = array_values(array_filter($responseItems, function ($item) use ($statusFilter) { return ($item['status'] ?? '') === $statusFilter; })); } $totalResponses = count($responseItems); $uniquePatients = count(array_unique(array_map(function ($item) { return $item['patient_display']; }, $responseItems))); $inWorkResponses = count(array_filter($responseItems, function ($item) { return ($item['status'] ?? '') === 'in_work'; })); ?>
        <header class="admin-header responses-header">
            <div class="admin-title"><h1>Ответы пациентов</h1></div>
        </header>
        <div class="filters-actions-row"><form class="filters-card" method="get" style="grid-template-columns:260px 140px"><input type="hidden" name="page" value="responses"><div class="filter-field"><label>Статус</label><select class="filter-control" name="status"><option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Все анкеты</option><option value="in_work" <?= $statusFilter === 'in_work' ? 'selected' : '' ?>>В работе</option><option value="processed" <?= $statusFilter === 'processed' ? 'selected' : '' ?>>Обработанные</option></select></div><button class="reset-filter" type="submit">Показать</button></form><button class="create-btn" type="button" id="bulkCreateRnovaTasksBtn">Обойти задачи с нет</button><span class="hint-status" id="bulkCreateRnovaTasksStatus" aria-live="polite"></span></div>
        <section class="response-stats" aria-label="Сводка ответов">
            <article class="stat-card stat-green"><span><?= icon_svg('<path d="M9 5h9v16H6V5h3Z"/><path d="M9 5a3 3 0 0 1 6 0H9Z"/><path d="M9 11h6M9 15h6"/>') ?></span><div><strong><?= e($totalResponses) ?></strong><p>Всего ответов</p></div></article>
            <article class="stat-card stat-blue"><span><?= icon_svg('<path d="M16 21v-2a4 4 0 0 0-8 0v2"/><path d="M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/><path d="M20 21v-2a3 3 0 0 0-2-2.8"/><path d="M18 4.2a3 3 0 0 1 0 5.6"/>') ?></span><div><strong><?= e($uniquePatients) ?></strong><p>Пациент</p></div></article>
            <article class="stat-card stat-amber"><span><?= icon_svg('<path d="M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z"/><path d="m8.5 12 2.2 2.2 4.8-5"/>') ?></span><div><strong><?= e($inWorkResponses) ?></strong><p>В работе</p></div></article>
        </section>
        <section class="questionnaire-table responses-table" aria-label="Ответы пациентов">
            <div class="table-head"><div>Пациент</div><div>Анкета</div><div>Дата заполнения</div><div>Статус</div><div>Заполнено</div><div>Задача</div><div>ИИ</div><div>Действия</div></div>
            <?php if (!$responseItems): ?>
            <div class="empty-state">Пока нет сохранённых ответов. Заполните анкету, чтобы данные появились здесь из базы данных.</div>
            <?php endif; ?>
            <?php foreach ($responseItems as $item): ?>
            <article class="table-row">
                <div class="patient-name"><div class="patient-avatar" aria-hidden="true"><?= e($item['avatar']) ?></div><div><div class="survey-title"><?= e($item['patient_display']) ?></div><div class="table-muted"><?= e($item['meta']) ?></div></div></div>
                <div><div class="survey-title"><?= e($item['survey']) ?></div></div>
                <div><div><?= e($item['date']) ?></div></div>
                <div><span class="response-pill response-<?= e($item['status']) ?>"><?= e(response_status_label($item['status'])) ?></span></div>
                <div class="progress-cell"><strong><?= e($item['filled_label']) ?></strong><span class="mini-progress"><i style="width:<?= e($item['progress']) ?>%"></i></span></div>
                <div><?= e(response_yes_no_label($item['rnova_task_created'])) ?></div>
                <div><?= e(response_yes_no_label($item['ai_analyzed'])) ?></div>
                <div class="actions-cell"><a class="icon-button" href="?page=response-view&amp;id=<?= e($item['id']) ?>" aria-label="Посмотреть"><svg viewBox="0 0 24 24"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z"/><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/></svg></a><button class="icon-button delete-response-btn" type="button" data-id="<?= e($item['id']) ?>" aria-label="Удалить"><svg viewBox="0 0 24 24"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="m19 6-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg></button></div>
            </article>
            <?php endforeach; ?>
        </section>
        <footer class="table-footer"><div>Показано <?= e($totalResponses) ?> из <?= e($totalResponses) ?> ответа</div></footer>
    </main>
    <?php endif; ?>
</div>
<?php else: ?>

<div class="wrap">
    <?php $questionnairePrice = questionnaire_price(); $paymentRequired = is_payment_required(); $submitButtonText = $paymentRequired ? 'Оплатить и отправить' : 'Отправить'; $initialPaymentUrl = prodamus_payment_url('anketa-' . date('YmdHis'), ['questionnaire_id' => $currentQuestionnaire['id'] ?? '']); ?>
    <div class="hero">
        <div class="hero-title">
            <div class="hero-logo" aria-hidden="true"><?= hero_logo_svg() ?></div>
            <div>
                <h1>AdaptogenzzClinic</h1>
            </div>
        </div>
        <div class="date-card">
            <span>Дата заполнения:<b><?= e(date('d.m.Y')) ?></b></span>
        </div>
    </div>

    <form id="quizForm" data-payment-url="<?= e($initialPaymentUrl) ?>" data-payment-required="<?= $paymentRequired ? 'Y' : 'N' ?>" data-payment-price="<?= e(money_amount_label($questionnairePrice)) ?>"><input type="hidden" name="questionnaire_id" value="<?= e($currentQuestionnaire['id'] ?? '') ?>">
        <input type="hidden" name="action" value="analyze">
        <input type="hidden" name="filled_at" value="<?= e(date('Y-m-d')) ?>">

        <section class="section-card">
            <aside class="section-aside">
                <div class="section-number">1</div>
                <div>
                    <h2>ОБЩАЯ ИНФОРМАЦИЯ</h2>
                </div>
            </aside>
            <div class="section-content">
                <div class="top-grid" style="grid-column:1 / -1;">
                    <div class="question">
                        <div class="question-label">Фамилия</div>
                        <input class="field" type="text" name="surname" required>
                    </div>
                    <div class="question">
                        <div class="question-label">Имя</div>
                        <input class="field" type="text" name="name" required>
                    </div>
                    <div class="question">
                        <div class="question-label">Отчество</div>
                        <input class="field" type="text" name="patronymic">
                    </div>
                    <div class="question">
                        <div class="question-label">Дата рождения</div>
                        <input class="field" type="date" name="dob" required>
                    </div>
                    <div class="question">
                        <div class="question-label">Телефон</div>
                        <input class="field" type="tel" name="phone" placeholder="Номер телефона" inputmode="tel" autocomplete="tel" required>
                    </div>
                    <div class="question">
                        <div class="question-label">E-mail</div>
                        <input class="field" type="email" name="email" required>
                    </div>
                    <div class="question">
                        <div class="question-label">Пол</div>
                        <div class="inline-options">
                            <label><input type="radio" name="sex" value="Мужчина" required> Мужчина</label>
                            <label><input type="radio" name="sex" value="Женщина" required> Женщина</label>
                        </div>
                    </div>
                    <div class="question">
                        <div class="question-label">Рост, см</div>
                        <input class="field" type="number" name="height" step="0.1" min="0">
                    </div>
                    <div class="question">
                        <div class="question-label">Вес, кг</div>
                        <input class="field" type="number" name="weight" step="0.1" min="0">
                    </div>
                    <div class="question">
                        <div class="question-label">Обхват талии</div>
                        <input class="field" type="number" name="waist" step="0.1" min="0">
                    </div>
                </div>
            </div>
        </section>

        <?php $sectionNumber = 2; ?>
        <?php foreach ($sections as $section): ?>
            <?= render_section_card($section, $sectionNumber++) ?>
        <?php endforeach; ?>

        <section class="consent-card" aria-label="Обязательные согласия">
            <label class="consent-option">
                <input type="checkbox" name="personal_data_consent" value="1" required>
                <span>Я согласен (согласна) с обработкой персональных данных в соответствии с <a href="https://back.nutrition-institute.ru/upload/law/PD%20Helix.pdf" target="_blank" rel="noopener noreferrer">политикой</a>.</span>
            </label>
            <label class="consent-option">
                <input type="checkbox" name="offer_agreement_consent" value="1" required>
                <span>Я согласен (согласна) и принимаю условия <a href="https://back.nutrition-institute.ru/upload/law/PubOfert_Anketa.pdf" target="_blank" rel="noopener noreferrer">договора-оферты</a>.</span>
            </label>
        </section>

        <div class="actions">
            <div class="progress-card">
                <span class="progress-icon">✓</span>
                <span class="progress-text" id="progressText">Заполнено: 0 из 19 разделов</span>
                <span class="progress-track"><span class="progress-fill" id="progressFill"></span></span>
            </div>
            <button type="submit" class="btn" id="submitBtn"><?= e($submitButtonText) ?></button>
        </div>
    </form>

    <div id="result" class="result" style="display:none;"></div>
</div>

<div class="success-modal" id="successModal" aria-hidden="true"><div class="success-dialog"><button type="button" class="modal-close" id="closeSuccessModal" aria-label="Закрыть">×</button><div class="success-icon">✓</div><h2 id="successModalTitle">Ответ успешно отправлен</h2><p id="successModalText"></p><a class="btn" id="paymentLink" href="#" style="display:none;justify-content:center;text-decoration:none;">Оплатить анкету</a></div></div>
<?php endif; ?>

<script>
(function () {
    const form = document.getElementById('quizForm');
    const result = document.getElementById('result');
    const submitBtn = document.getElementById('submitBtn');
    const aiHintsModal = document.getElementById('aiHintsModal');
    const aiHintsForm = document.getElementById('aiHintsForm');
    const aiHintsStatus = document.getElementById('aiHintsStatus');
    const closeAiHints = document.getElementById('closeAiHints');
    const cancelAiHints = document.getElementById('cancelAiHints');
    const saveAiHints = document.getElementById('saveAiHints');
    const successModal = document.getElementById('successModal');
    const closeSuccessModal = document.getElementById('closeSuccessModal');
    const successModalTitle = document.getElementById('successModalTitle');
    const successModalText = document.getElementById('successModalText');
    const paymentLink = document.getElementById('paymentLink');
    const pendingSurveyStorageKey = 'anketaai_pending_survey';

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function openSuccessModal(options = {}) {
        if (!successModal) return;
        const {title = 'Ответ успешно отправлен', text = '', paymentUrl = '', paymentButtonText = 'Оплатить анкету'} = options;
        if (successModalTitle) successModalTitle.textContent = title;
        if (successModalText) successModalText.textContent = text;
        if (paymentLink) {
            if (paymentUrl) {
                paymentLink.href = paymentUrl;
                paymentLink.textContent = paymentButtonText;
                paymentLink.style.display = 'inline-flex';
            } else {
                paymentLink.removeAttribute('href');
                paymentLink.style.display = 'none';
            }
        }
        successModal.classList.add('is-open');
        successModal.setAttribute('aria-hidden', 'false');
    }

    function closeSuccess() {
        if (!successModal) return;
        successModal.classList.remove('is-open');
        successModal.setAttribute('aria-hidden', 'true');
    }
    closeSuccessModal?.addEventListener('click', closeSuccess);
    successModal?.addEventListener('click', (ev) => { if (ev.target === successModal) closeSuccess(); });

    function savePendingSurvey() {
        if (!form) return;
        const fd = new FormData(form);
        const payload = [];
        fd.forEach((value, key) => payload.push([key, value]));
        localStorage.setItem(pendingSurveyStorageKey, JSON.stringify(payload));
    }

    function readPendingSurveyFormData() {
        const raw = localStorage.getItem(pendingSurveyStorageKey);
        if (!raw) return null;
        try {
            const entries = JSON.parse(raw);
            if (!Array.isArray(entries)) return null;
            const fd = new FormData();
            entries.forEach((entry) => {
                if (Array.isArray(entry) && entry.length >= 2) fd.append(entry[0], entry[1]);
            });
            if (!fd.has('action')) fd.append('action', 'analyze');
            return fd;
        } catch (err) {
            return null;
        }
    }

    async function sendSurveyToAiAfterPayment() {
        const fd = readPendingSurveyFormData();
        if (!fd) {
            openSuccessModal({
                title: 'Оплата прошла успешно',
                text: 'Не удалось найти сохраненную анкету в браузере. Пожалуйста, отправьте анкету еще раз.'
            });
            return;
        }

        openSuccessModal({
            title: 'Оплата прошла успешно',
            text: 'Отправляем анкету на анализ ИИ. Пожалуйста, дождитесь подтверждения.'
        });

        try {
            const res = await fetch(location.pathname + '?page=form', {method: 'POST', body: fd, headers: {'X-Requested-With': 'fetch'}});
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || data.message || 'Ошибка при сохранении анкеты');
            localStorage.removeItem(pendingSurveyStorageKey);
            openSuccessModal({
                title: 'Анкета успешно отправлена и оплачена',
                text: 'Спасибо! Оплата прошла успешно, анкета отправлена.'
            });
        } catch (err) {
            openSuccessModal({
                title: 'Ошибка отправки анкеты после оплаты',
                text: err.message || 'Оплата прошла, но анкету не удалось отправить. Пожалуйста, свяжитесь с клиникой.'
            });
        }
    }


    function initQuestionnaireBuilder() {
        const root = document.getElementById('builderRoot');
        const initial = document.getElementById('builderInitial');
        const out = document.getElementById('builderJson');
        const form = document.getElementById('questionnaireBuilderForm');
        if (!root || !initial || !out || !form) return;
        let sections = [];
        try { sections = JSON.parse(initial.textContent || '[]'); } catch (e) { sections = []; }
        if (!sections.length) sections = [{title:'Новый раздел', questions:[]}];
        const types = {text:'Маленькое текстовое поле', textarea:'Большое текстовое поле', select:'Выпадающий список', checklist:'Чекбокс', radio:'Радиобаттон', yesno:'Да/Нет'};
        function slug(v){ return String(v||'question').toLowerCase().replace(/[^a-zа-я0-9]+/gi,'_').replace(/^_+|_+$/g,'') || ('q_'+Date.now()); }
        function render(){
            root.innerHTML = sections.map((sec, si) => `<div class="answer-section builder-section"><h3><span>${si+1}</span><input class="field b-section-title" data-si="${si}" value="${escapeHtml(sec.title||'')}" placeholder="Название раздела"></h3><div>${(sec.questions||[]).map((q, qi) => `<div class="question" style="display:grid;grid-template-columns:1fr 190px 1fr auto;gap:10px;align-items:end"><div><div class="question-label">Вопрос</div><input class="field b-q-label" data-si="${si}" data-qi="${qi}" value="${escapeHtml(q.label||'')}"></div><div><div class="question-label">Тип</div><select class="field b-q-type" data-si="${si}" data-qi="${qi}">${Object.entries(types).map(([k,v])=>`<option value="${k}" ${q.type===k?'selected':''}>${v}</option>`).join('')}</select></div><div><div class="question-label">Варианты (каждый с новой строки)</div><textarea class="field b-q-options" data-si="${si}" data-qi="${qi}" rows="2" ${q.type==='yesno'?'disabled placeholder="Варианты Да/Нет подставляются автоматически"':''}>${escapeHtml((q.options||[]).join('\n'))}</textarea></div><button type="button" class="icon-button b-del-q" data-si="${si}" data-qi="${qi}">🗑</button></div>`).join('')}</div><button type="button" class="outline-btn b-add-q" data-si="${si}">+ Вопрос</button> <button type="button" class="outline-btn b-del-sec" data-si="${si}">Удалить раздел</button></div>`).join('');
        }
        root.addEventListener('input', ev => { const t=ev.target, si=+t.dataset.si, qi=t.dataset.qi!==undefined?+t.dataset.qi:null; if(t.classList.contains('b-section-title')) sections[si].title=t.value; if(t.classList.contains('b-q-label')) { sections[si].questions[qi].label=t.value; sections[si].questions[qi].name=slug(t.value); } if(t.classList.contains('b-q-options')) sections[si].questions[qi].options=t.value.split('\n').map(x=>x.trim()).filter(Boolean); });
        root.addEventListener('change', ev => { const t=ev.target; if(t.classList.contains('b-q-type')) { const q=sections[+t.dataset.si].questions[+t.dataset.qi]; q.type=t.value; if(t.value==='yesno') q.options=[]; } });
        root.addEventListener('click', ev => { const b=ev.target.closest('button'); if(!b) return; if(b.classList.contains('b-add-q')) sections[+b.dataset.si].questions.push({name:'q_'+Date.now(), type:'text', label:'Новый вопрос', options:[]}); if(b.classList.contains('b-del-q')) sections[+b.dataset.si].questions.splice(+b.dataset.qi,1); if(b.classList.contains('b-del-sec')) sections.splice(+b.dataset.si,1); render(); });
        document.getElementById('addSectionBtn')?.addEventListener('click', () => { sections.push({title:'Новый раздел', questions:[]}); render(); });
        form.addEventListener('submit', () => { out.value = JSON.stringify(sections); });
        render();
    }

    async function copyTextToClipboard(text) {
        if (!text) return false;
        if (navigator.clipboard && window.isSecureContext) {
            try { await navigator.clipboard.writeText(text); return true; } catch (e) {}
        }
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        textarea.style.top = '0';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();
        let copied = false;
        try { copied = document.execCommand('copy'); } catch (e) { copied = false; }
        textarea.remove();
        return copied;
    }

    document.querySelectorAll('.copy-url-btn').forEach(button => button.addEventListener('click', async () => {
        const url = button.dataset.url || '';
        const original = button.innerHTML;
        const originalLabel = button.getAttribute('aria-label') || 'Копировать ссылку на анкету';
        const originalTitle = button.getAttribute('title') || 'Копировать URL';
        button.disabled = true;
        if (await copyTextToClipboard(url)) {
            button.classList.add('is-copied');
            button.innerHTML = '<span class="copy-success-mark" aria-hidden="true">✓</span>';
            button.setAttribute('aria-label', 'Ссылка скопирована');
            button.setAttribute('title', 'Ссылка скопирована');
            setTimeout(() => {
                button.innerHTML = original;
                button.setAttribute('aria-label', originalLabel);
                button.setAttribute('title', originalTitle);
                button.classList.remove('is-copied');
                button.disabled = false;
            }, 1400);
        } else {
            button.disabled = false;
            prompt('Скопируйте URL анкеты', url);
        }
    }));
    document.querySelectorAll('.delete-questionnaire-btn').forEach(button => button.addEventListener('click', async () => {
        if(!confirm('Удалить анкету? Уже сохраненные ответы пациентов не изменятся.')) return;
        button.disabled = true;
        const fd=new FormData();
        fd.append('action','delete_questionnaire');
        fd.append('id',button.dataset.id||'');
        try {
            const res=await fetch(location.pathname + location.search,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
            const data=await res.json();
            if(data.ok) location.reload(); else alert(data.message||'Не удалось удалить анкету');
        } catch(e) {
            alert('Не удалось удалить анкету. Попробуйте ещё раз.');
        } finally {
            button.disabled = false;
        }
    }));

    function initHintsEditor() {
        if (!aiHintsForm) return;

        function closeAiHintsModal() {
            if (!aiHintsModal || aiHintsModal.classList.contains('settings-panel')) return;
            aiHintsModal.classList.remove('is-open');
            aiHintsModal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }

        closeAiHints?.addEventListener('click', closeAiHintsModal);
        cancelAiHints?.addEventListener('click', closeAiHintsModal);
        aiHintsModal?.addEventListener('click', (ev) => {
            if (ev.target === aiHintsModal) closeAiHintsModal();
        });
        document.addEventListener('keydown', (ev) => {
            if (ev.key === 'Escape') closeAiHintsModal();
        });

        function updateHintCounter(textarea) {
            const counter = textarea.parentElement?.querySelector('.hint-counter');
            if (counter) counter.textContent = `${textarea.value.length}/500`;
        }

        aiHintsForm.querySelectorAll('.hint-field').forEach((textarea) => {
            updateHintCounter(textarea);
            textarea.addEventListener('input', () => updateHintCounter(textarea));
        });

        document.querySelectorAll('.hint-nav a').forEach((link) => {
            link.addEventListener('click', () => {
                document.querySelectorAll('.hint-nav-block').forEach((item) => item.classList.remove('is-active'));
                link.closest('.hint-nav-block')?.classList.add('is-active');
            });
        });

        aiHintsForm.addEventListener('reset', () => {
            setTimeout(() => {
                aiHintsForm.querySelectorAll('.hint-field').forEach(updateHintCounter);
                if (aiHintsStatus) aiHintsStatus.textContent = 'Изменения сброшены.';
            }, 0);
        });

        aiHintsForm.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            if (saveAiHints) {
                saveAiHints.disabled = true;
                saveAiHints.textContent = 'Сохранение...';
            }
            if (aiHintsStatus) aiHintsStatus.textContent = 'Сохраняем настройки...';
            try {
                const fd = new FormData(aiHintsForm);
                const res = await fetch(location.href, {method: 'POST', body: fd, headers: {'X-Requested-With': 'fetch'}});
                const data = await res.json();
                if (!data.ok) throw new Error(data.error || 'Ошибка сохранения настроек ИИ');
                if (aiHintsStatus) aiHintsStatus.textContent = data.message || 'Настройки сохранены.';
            } catch (err) {
                if (aiHintsStatus) aiHintsStatus.textContent = err.message || 'Неизвестная ошибка сохранения';
            } finally {
                if (saveAiHints) {
                    saveAiHints.disabled = false;
                    saveAiHints.textContent = 'Сохранить';
                }
            }
        });
    }

    function initQuizForm() {
        if (!form) return;
        const sexInputs = document.querySelectorAll('input[name="sex"]');
        const femaleSection = document.querySelector('[data-sex-section="female"]');
        const maleSection = document.querySelector('[data-sex-section="male"]');
        const progressText = document.getElementById('progressText');
        const progressFill = document.getElementById('progressFill');

        function namedCheckedCount(name) {
            return Array.from(form.elements).filter(field => field.name === name && field.checked).length;
        }
        function isFieldFilled(field) {
            if (field.disabled) return true;
            if (field.type === 'radio' || field.type === 'checkbox') return namedCheckedCount(field.name) > 0;
            return String(field.value || '').trim() !== '';
        }
        function isQuestionAnswered(question) {
            const fields = Array.from(question.querySelectorAll('input, textarea, select')).filter(field => field.name !== 'filled_at');
            if (!fields.length) return true;
            const requiredFields = fields.filter(field => field.required);
            if (requiredFields.length) return requiredFields.every(isFieldFilled);
            const radioNames = [...new Set(fields.filter(field => field.type === 'radio').map(field => field.name))];
            if (radioNames.length) return radioNames.every(name => namedCheckedCount(name) > 0);
            const checkboxFields = fields.filter(field => field.type === 'checkbox');
            if (checkboxFields.length) {
                const names = [...new Set(checkboxFields.map(field => field.name))];
                return names.some(name => namedCheckedCount(name) > 0) || fields.some(field => field.type !== 'checkbox' && isFieldFilled(field));
            }
            return fields.every(field => !field.required) || fields.some(isFieldFilled);
        }
        function updateProgress() {
            const sections = Array.from(form.querySelectorAll('.section-card')).filter(section => window.getComputedStyle(section).display !== 'none');
            const filled = sections.filter(section => {
                const questions = Array.from(section.querySelectorAll('.question'));
                return questions.length && questions.every(isQuestionAnswered);
            }).length;
            const total = sections.length;
            const percent = total ? Math.round((filled / total) * 100) : 0;
            if (progressText) progressText.textContent = `Заполнено: ${filled} из ${total} разделов`;
            if (progressFill) progressFill.style.width = `${percent}%`;
        }
        function toggleSexBlocks() {
            const sex = document.querySelector('input[name="sex"]:checked')?.value || '';
            if (femaleSection) femaleSection.style.display = (sex === 'Женщина') ? 'grid' : 'none';
            if (maleSection) maleSection.style.display = (sex === 'Мужчина') ? 'grid' : 'none';
            updateProgress();
        }
        form.addEventListener('input', updateProgress);
        form.addEventListener('change', updateProgress);
        sexInputs.forEach(el => el.addEventListener('change', toggleSexBlocks));
        toggleSexBlocks();

        form.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }
            const paymentRequired = (form.dataset.paymentRequired || 'Y').toUpperCase() === 'Y';
            const paymentUrl = form.dataset.paymentUrl || '';

            if (paymentRequired) {
                if (!paymentUrl) {
                    if (result) {
                        result.style.display = 'block';
                        result.innerHTML = '<div class="error">Не настроена ссылка оплаты Prodamus.</div>';
                    }
                    return;
                }
                savePendingSurvey();
                submitBtn.disabled = true;
                submitBtn.textContent = 'Ожидание перехода к оплате';
                openSuccessModal({
                    title: 'Для анализа анкеты необходимо ее оплатить',
                    text: 'Нажмите кнопку ниже, чтобы открыть защищенную страницу оплаты Prodamus. После успешной оплаты анкета автоматически отправится на анализ ИИ.',
                    paymentUrl,
                    paymentButtonText: `Перейти к оплате ${form.dataset.paymentPrice || ''} ₽`.replace('  ₽', ' ₽')
                });
                return;
            }

            const fd = new FormData(form);
            if (!fd.has('action')) fd.append('action', 'analyze');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Отправляем анкету';
            if (result) {
                result.style.display = 'block';
                result.innerHTML = '<div class="muted">Отправляем анкету...</div>';
            }

            try {
                const res = await fetch(location.pathname + '?page=form', {method: 'POST', body: fd, headers: {'X-Requested-With': 'fetch'}});
                const data = await res.json();
                if (!data.ok) throw new Error(data.error || data.message || 'Ошибка при сохранении анкеты');
                openSuccessModal({
                    title: 'Анкета успешно отправлена',
                    text: 'Ваша анкета зарегистрирована в нашей системе.'
                });
                if (result) {
                    result.innerHTML = '';
                    result.style.display = 'none';
                }
                form.reset();
                updateProgress();
            } catch (err) {
                if (result) result.innerHTML = `<div class="error">${escapeHtml(err.message || 'Не удалось отправить анкету.')}</div>`;
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = paymentRequired ? 'Оплатить и отправить' : 'Отправить';
            }
        });
    }

    function initAiResponseEditor() {
        const editor = document.getElementById('aiEditor');
        if (!editor) return;
        const charCount = document.getElementById('aiCharCount');
        const saveStatus = document.getElementById('aiSaveStatus');
        const saveBtn = document.getElementById('saveAiAnswerBtn');
        const params = new URLSearchParams(location.search);
        const responseId = params.get('id') || '';
        const markProcessedBtn = document.getElementById('markProcessedBtn');
        const regenerateAiBtn = document.getElementById('regenerateAiBtn');
        const createPdfBtn = document.getElementById('createPdfBtn');

        function updateCount() {
            if (charCount) charCount.textContent = `Символов: ${editor.innerText.trim().length}`;
        }
        updateCount();
        editor.addEventListener('input', updateCount);
        document.querySelectorAll('.editor-toolbar [data-cmd]').forEach((button) => {
            button.addEventListener('click', () => {
                document.execCommand(button.dataset.cmd, false, button.dataset.value || null);
                editor.focus();
                updateCount();
            });
        });
        async function saveAiAnswer() {
            if (!saveBtn) return false;
            saveBtn.disabled = true;
            if (saveStatus) saveStatus.textContent = 'Сохраняем ИИ-ответ...';
            const fd = new FormData();
            fd.append('action', 'save_ai_answer');
            fd.append('id', responseId);
            fd.append('ai_answer_html', editor.innerHTML);
            try {
                const res = await fetch(location.href, {method: 'POST', body: fd, headers: {'X-Requested-With': 'fetch'}});
                const data = await res.json();
                if (!data.ok) throw new Error(data.message || data.error || 'Не удалось сохранить ИИ-ответ');
                if (saveStatus) saveStatus.textContent = data.message || 'ИИ-ответ сохранён.';
                return true;
            } catch (err) {
                if (saveStatus) saveStatus.textContent = err.message || 'Ошибка сохранения';
                return false;
            } finally {
                saveBtn.disabled = false;
            }
        }
        saveBtn?.addEventListener('click', saveAiAnswer);
        async function postResponseAction(action, button, pendingText) {
            if (!responseId || !button) return;
            const old = button.textContent;
            button.disabled = true;
            button.textContent = pendingText;
            const fd = new FormData();
            fd.append('action', action);
            fd.append('id', responseId);
            if (editor) fd.append('ai_answer_html', editor.innerHTML);
            try {
                const res = await fetch(location.href, {method: 'POST', body: fd, headers: {'X-Requested-With': 'fetch'}});
                const data = await res.json();
                if (!data.ok) throw new Error(data.message || data.error || 'Ошибка операции');
                if (saveStatus) saveStatus.textContent = data.message || 'Готово.';
                if (action === 'mark_processed' || action === 'regenerate_ai_answer') setTimeout(() => location.reload(), 500);
            } catch (err) {
                if (saveStatus) saveStatus.textContent = err.message || 'Ошибка операции';
            } finally {
                button.disabled = false;
                button.textContent = old;
            }
        }
        markProcessedBtn?.addEventListener('click', () => postResponseAction('mark_processed', markProcessedBtn, 'Отправляем в МИС...'));
        regenerateAiBtn?.addEventListener('click', () => postResponseAction('regenerate_ai_answer', regenerateAiBtn, 'Создаём ИИ...'));
        function downloadBlob(blob, filename) {
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = filename || 'response.pdf';
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
        }
        function filenameFromDisposition(disposition) {
            const match = /filename\*=UTF-8''([^;]+)|filename=\"?([^\";]+)\"?/i.exec(disposition || '');
            return match ? decodeURIComponent(match[1] || match[2] || '') : '';
        }
        createPdfBtn?.addEventListener('click', async (event) => {
            if (!editor || !responseId) return;
            event.preventDefault();
            createPdfBtn.setAttribute('aria-disabled', 'true');
            if (saveStatus) saveStatus.textContent = 'Готовим PDF с текущим текстом ИИ-ответа...';
            const fd = new FormData();
            fd.append('action', 'download_response_pdf');
            fd.append('id', responseId);
            fd.append('ai_answer_html', editor.innerHTML);
            fd.append('ai_answer_text', editor.innerText);
            try {
                await saveAiAnswer();
                const res = await fetch(createPdfBtn.href, {method: 'POST', body: fd, headers: {'X-Requested-With': 'fetch'}});
                if (!res.ok) throw new Error('Не удалось скачать PDF');
                const blob = await res.blob();
                downloadBlob(blob, filenameFromDisposition(res.headers.get('Content-Disposition')) || `response-${responseId}.pdf`);
                if (saveStatus) saveStatus.textContent = 'PDF скачан с текущим текстом ИИ-ответа.';
            } catch (err) {
                if (saveStatus) saveStatus.textContent = err.message || 'Ошибка скачивания PDF';
            } finally {
                createPdfBtn.removeAttribute('aria-disabled');
            }
        });
    }

    const paymentStatus = new URLSearchParams(location.search).get('payment');
    if (paymentStatus === 'success') {
        sendSurveyToAiAfterPayment();
    } else if (paymentStatus === 'error') {
        openSuccessModal({
            title: 'Ошибка оплаты',
            text: 'Платеж не был завершен или произошла ошибка оплаты. Попробуйте оплатить анкету еще раз.'
        });
    }

    document.querySelectorAll('.delete-response-btn').forEach((button) => {
        button.addEventListener('click', async () => {
            if (!confirm('Удалить ответ пациента?')) return;
            const fd = new FormData();
            fd.append('action', 'delete_response');
            fd.append('id', button.dataset.id || '');
            const res = await fetch(location.href, {method: 'POST', body: fd, headers: {'X-Requested-With': 'fetch'}});
            const data = await res.json();
            if (data.ok) location.reload(); else alert(data.message || data.error || 'Не удалось удалить ответ');
        });
    });

    const bulkCreateRnovaTasksBtn = document.getElementById('bulkCreateRnovaTasksBtn');
    const bulkCreateRnovaTasksStatus = document.getElementById('bulkCreateRnovaTasksStatus');
    bulkCreateRnovaTasksBtn?.addEventListener('click', async () => {
        const old = bulkCreateRnovaTasksBtn.textContent;
        bulkCreateRnovaTasksBtn.disabled = true;
        bulkCreateRnovaTasksBtn.textContent = 'Создаём задачи...';
        if (bulkCreateRnovaTasksStatus) bulkCreateRnovaTasksStatus.textContent = '';
        const fd = new FormData();
        fd.append('action', 'bulk_create_rnova_tasks');
        try {
            const res = await fetch(location.href, {method: 'POST', body: fd, headers: {'X-Requested-With': 'fetch'}});
            const data = await res.json();
            if (!data.ok && data.errors?.length) throw new Error(data.message + ' ' + data.errors.slice(0, 3).join('; '));
            if (bulkCreateRnovaTasksStatus) bulkCreateRnovaTasksStatus.textContent = data.message || 'Готово.';
            setTimeout(() => location.reload(), 800);
        } catch (err) {
            if (bulkCreateRnovaTasksStatus) bulkCreateRnovaTasksStatus.textContent = err.message || 'Не удалось создать задачи RNOVA.';
        } finally {
            bulkCreateRnovaTasksBtn.disabled = false;
            bulkCreateRnovaTasksBtn.textContent = old;
        }
    });

    initHintsEditor();
    initQuizForm();
    initAiResponseEditor();
    initQuestionnaireBuilder();
})();
</script>
</body>
</html>
