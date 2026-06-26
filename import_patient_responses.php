<?php
declare(strict_types=1);

/**
 * Импортирует patient_responses.json в MySQL:
 * - создаёт/обновляет одну анкету «Анкета здоровья»;
 * - строит разделы и вопросы по структуре JSON;
 * - сохраняет подсказки/настройки вопросов;
 * - добавляет ответы пациентов и связанные разделы/ответы/историю.
 *
 * Запуск из CLI:
 *   php import_patient_responses.php
 *   php import_patient_responses.php /path/to/patient_responses.json
 *
 * Можно переопределить подключение через env:
 *   DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_CHARSET, QUESTIONNAIRE_ID
 */

const DEFAULT_DB_HOST = 'localhost';
const DEFAULT_DB_NAME = 'anketaai';
const DEFAULT_DB_USER = 'anketaai';
const DEFAULT_DB_PASS = 'password';
const DEFAULT_DB_CHARSET = 'utf8mb4';
const DEFAULT_QUESTIONNAIRE_ID = 'health';
const DEFAULT_QUESTIONNAIRE_TITLE = 'Анкета здоровья';

function env_value(string $name, string $default): string
{
    $value = getenv($name);
    return $value === false || $value === '' ? $default : (string)$value;
}

function pdo_connection(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = env_value('DB_HOST', DEFAULT_DB_HOST);
    $name = env_value('DB_NAME', DEFAULT_DB_NAME);
    $charset = env_value('DB_CHARSET', DEFAULT_DB_CHARSET);
    $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";

    $pdo = new PDO($dsn, env_value('DB_USER', DEFAULT_DB_USER), env_value('DB_PASS', DEFAULT_DB_PASS), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    db_init($pdo);

    return $pdo;
}

function db_init(PDO $pdo): void
{
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
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX questionnaire_id_idx (questionnaire_id),
        INDEX status_idx (status)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    ensure_columns($pdo, 'patient_responses', [
        'analysis_raw' => 'LONGTEXT NULL',
        'ai_answer_html' => 'LONGTEXT NULL',
        'mis_sent_at' => 'VARCHAR(64) NULL',
        'mis_patient_id' => 'VARCHAR(64) NULL',
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
}

function ensure_columns(PDO $pdo, string $table, array $columns): void
{
    foreach ($columns as $name => $definition) {
        $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '` LIKE ' . $pdo->quote($name));
        if (!$stmt->fetch()) {
            $pdo->exec('ALTER TABLE `' . str_replace('`', '', $table) . '` ADD COLUMN `' . str_replace('`', '', $name) . '` ' . $definition);
        }
    }
}

function db_date(?string $iso): string
{
    if (!$iso) {
        return date('Y-m-d H:i:s');
    }
    try {
        return (new DateTimeImmutable($iso))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return date('Y-m-d H:i:s');
    }
}

function slugify_question(string $label, array &$used): string
{
    $map = [
        'Как вы оцениваете состояние своего здоровья?' => 'health_state',
        'Как часто вы болеете?' => 'illness_frequency',
        'Что для вас значит "быть здоровым"?' => 'healthy_meaning',
        'Какова основная цель вашего визита?' => 'primary_goal',
    ];
    $base = $map[$label] ?? '';
    if ($base === '') {
        $latin = function_exists('transliterator_transliterate') ? (transliterator_transliterate('Any-Latin; Latin-ASCII', $label) ?: $label) : $label;
        $base = strtolower(trim((string)preg_replace('/[^a-zA-Z0-9]+/u', '_', $latin), '_'));
    }
    $base = substr($base !== '' ? $base : 'question', 0, 112);
    $name = $base;
    $i = 2;
    while (isset($used[$name])) {
        $suffix = '_' . $i++;
        $name = substr($base, 0, 128 - strlen($suffix)) . $suffix;
    }
    $used[$name] = true;
    return $name;
}

function normalize_answer($answer): string
{
    return is_array($answer) ? implode("\n", array_map('strval', $answer)) : trim((string)$answer);
}

function infer_question_type(array $values): string
{
    $values = array_values(array_filter(array_unique(array_map('trim', $values)), 'strlen'));
    if (!$values) {
        return 'text';
    }
    $yesNo = ['Да' => true, 'Нет' => true];
    $allYesNo = true;
    foreach ($values as $value) {
        if (!isset($yesNo[$value])) {
            $allYesNo = false;
            break;
        }
    }
    if ($allYesNo) {
        return 'yesno';
    }
    foreach ($values as $value) {
        if (mb_strlen($value) > 80 || str_contains($value, "\n")) {
            return 'textarea';
        }
    }
    return count($values) <= 12 ? 'radio' : 'text';
}

function build_questionnaire_sections(array $responses): array
{
    $sectionMap = [];
    foreach ($responses as $response) {
        foreach (($response['answers'] ?? []) as $sectionIndex => $section) {
            $sectionTitle = (string)($section['section'] ?? 'Раздел');
            if (!isset($sectionMap[$sectionTitle])) {
                $sectionMap[$sectionTitle] = ['position' => $sectionIndex, 'questions' => []];
            }
            foreach (($section['answers'] ?? []) as $questionIndex => $answer) {
                $label = (string)($answer['question'] ?? 'Вопрос');
                if (!isset($sectionMap[$sectionTitle]['questions'][$label])) {
                    $sectionMap[$sectionTitle]['questions'][$label] = ['position' => $questionIndex, 'values' => []];
                }
                $value = normalize_answer($answer['answer'] ?? '');
                if ($value !== '') {
                    $sectionMap[$sectionTitle]['questions'][$label]['values'][] = $value;
                }
            }
        }
    }

    uasort($sectionMap, static fn(array $a, array $b): int => $a['position'] <=> $b['position']);
    $usedNames = [];
    $sections = [];
    foreach ($sectionMap as $sectionTitle => $sectionData) {
        uasort($sectionData['questions'], static fn(array $a, array $b): int => $a['position'] <=> $b['position']);
        $questions = [];
        foreach ($sectionData['questions'] as $label => $questionData) {
            $values = array_values(array_filter(array_unique($questionData['values']), 'strlen'));
            $type = infer_question_type($values);
            $question = [
                'name' => slugify_question($label, $usedNames),
                'type' => $type,
                'label' => $label,
            ];
            if ($type === 'yesno') {
                $question['options'] = ['Да', 'Нет'];
            } elseif ($type === 'radio') {
                $question['options'] = $values;
            }
            $questions[] = $question;
        }
        $sections[] = ['title' => $sectionTitle, 'questions' => $questions];
    }

    return $sections;
}

function save_questionnaire(string $questionnaireId, string $title, array $sections, string $updatedAt): void
{
    $pdo = pdo_connection();
    $now = db_date($updatedAt);
    $pdo->prepare('INSERT INTO questionnaires (id, title, created_at, updated_at, deleted_at) VALUES (?, ?, ?, ?, NULL)
        ON DUPLICATE KEY UPDATE title=VALUES(title), updated_at=VALUES(updated_at), deleted_at=NULL')->execute([$questionnaireId, $title, $now, $now]);

    $sectionIds = $pdo->prepare('SELECT id FROM questionnaire_sections WHERE questionnaire_id=?');
    $sectionIds->execute([$questionnaireId]);
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
        $pdo->prepare('DELETE FROM questionnaire_sections WHERE questionnaire_id=?')->execute([$questionnaireId]);
    }

    $insertSection = $pdo->prepare('INSERT INTO questionnaire_sections (questionnaire_id, position, title, sex) VALUES (?, ?, ?, NULL)');
    $insertQuestion = $pdo->prepare('INSERT INTO questionnaire_questions (section_id, position, name, type, label, other_name, min_value, max_value, step_value) VALUES (?, ?, ?, ?, ?, NULL, NULL, NULL, NULL)');
    $insertOption = $pdo->prepare('INSERT INTO questionnaire_question_options (question_id, position, option_value) VALUES (?, ?, ?)');
    $insertQuestionHint = $pdo->prepare('INSERT INTO questionnaire_question_hints (questionnaire_id, question_name, hint) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE hint=VALUES(hint)');
    $insertOptionHint = $pdo->prepare('INSERT INTO questionnaire_option_hints (questionnaire_id, question_name, option_value, hint) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE hint=VALUES(hint)');

    $pdo->prepare('DELETE FROM questionnaire_question_hints WHERE questionnaire_id=?')->execute([$questionnaireId]);
    $pdo->prepare('DELETE FROM questionnaire_option_hints WHERE questionnaire_id=?')->execute([$questionnaireId]);

    foreach ($sections as $sectionPosition => $section) {
        $insertSection->execute([$questionnaireId, $sectionPosition, (string)$section['title']]);
        $sectionId = (int)$pdo->lastInsertId();
        foreach ($section['questions'] as $questionPosition => $question) {
            $insertQuestion->execute([$sectionId, $questionPosition, $question['name'], $question['type'], $question['label']]);
            $questionId = (int)$pdo->lastInsertId();
            $insertQuestionHint->execute([$questionnaireId, $question['name'], '']);
            foreach (($question['options'] ?? []) as $optionPosition => $option) {
                $insertOption->execute([$questionId, $optionPosition, (string)$option]);
                $insertOptionHint->execute([$questionnaireId, $question['name'], (string)$option, '']);
            }
        }
    }
}

function upsert_patient_response(array $record, string $questionnaireId): void
{
    $pdo = pdo_connection();
    $patient = is_array($record['patient'] ?? null) ? $record['patient'] : [];
    $analysisRaw = $record['analysis_raw'] ?? ($record['analysis'] ?? '');
    if (is_array($analysisRaw)) {
        $analysisRaw = json_encode($analysisRaw, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '';
    }
    $aiAnswerHtml = (string)($record['ai_answer_html'] ?? '<p>ИИ-анализ пока не сформирован.</p>');

    $stmt = $pdo->prepare('INSERT INTO patient_responses (id, questionnaire_id, survey, category, status, progress, filled_answers, total_answers, patient_surname, patient_name, patient_patronymic, patient_dob, patient_phone, patient_email, patient_sex, patient_height, patient_weight, patient_waist, patient_filled_at, analysis_raw, ai_answer_html, mis_sent_at, mis_patient_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE questionnaire_id=VALUES(questionnaire_id), survey=VALUES(survey), category=VALUES(category), status=VALUES(status), progress=VALUES(progress), filled_answers=VALUES(filled_answers), total_answers=VALUES(total_answers), patient_surname=VALUES(patient_surname), patient_name=VALUES(patient_name), patient_patronymic=VALUES(patient_patronymic), patient_dob=VALUES(patient_dob), patient_phone=VALUES(patient_phone), patient_email=VALUES(patient_email), patient_sex=VALUES(patient_sex), patient_height=VALUES(patient_height), patient_weight=VALUES(patient_weight), patient_waist=VALUES(patient_waist), patient_filled_at=VALUES(patient_filled_at), analysis_raw=VALUES(analysis_raw), ai_answer_html=VALUES(ai_answer_html), mis_sent_at=VALUES(mis_sent_at), mis_patient_id=VALUES(mis_patient_id), updated_at=VALUES(updated_at)');
    $stmt->execute([
        (string)$record['id'], $questionnaireId, $record['survey'] ?? DEFAULT_QUESTIONNAIRE_TITLE, $record['category'] ?? null,
        $record['status'] ?? 'completed', (int)($record['progress'] ?? 0), (int)($record['filled_answers'] ?? 0), (int)($record['total_answers'] ?? 0),
        $patient['surname'] ?? '', $patient['name'] ?? '', $patient['patronymic'] ?? '', $patient['dob'] ?? '', $patient['phone'] ?? '', $patient['email'] ?? '',
        $patient['sex'] ?? '', $patient['height'] ?? '', $patient['weight'] ?? '', $patient['waist'] ?? '', $patient['filled_at'] ?? '',
        (string)$analysisRaw, $aiAnswerHtml, $record['mis_sent_at'] ?? null, $record['mis_patient_id'] ?? null,
        db_date($record['created_at'] ?? null), db_date($record['updated_at'] ?? null),
    ]);

    $id = (string)$record['id'];
    $pdo->prepare('DELETE FROM patient_response_hints WHERE response_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM patient_response_history WHERE response_id=?')->execute([$id]);
    $oldSections = $pdo->prepare('SELECT id FROM patient_response_sections WHERE response_id=?');
    $oldSections->execute([$id]);
    $sectionIds = $oldSections->fetchAll(PDO::FETCH_COLUMN);
    if ($sectionIds) {
        $pdo->prepare('DELETE FROM patient_response_answers WHERE response_section_id IN (' . implode(',', array_fill(0, count($sectionIds), '?')) . ')')->execute($sectionIds);
    }
    $pdo->prepare('DELETE FROM patient_response_sections WHERE response_id=?')->execute([$id]);

    $insertSection = $pdo->prepare('INSERT INTO patient_response_sections (response_id, position, title) VALUES (?, ?, ?)');
    $insertAnswer = $pdo->prepare('INSERT INTO patient_response_answers (response_section_id, position, question, answer_text) VALUES (?, ?, ?, ?)');
    foreach (array_values($record['answers'] ?? []) as $sectionPosition => $section) {
        $insertSection->execute([$id, $sectionPosition, (string)($section['section'] ?? '')]);
        $sectionId = (int)$pdo->lastInsertId();
        foreach (array_values($section['answers'] ?? []) as $answerPosition => $answer) {
            $insertAnswer->execute([$sectionId, $answerPosition, (string)($answer['question'] ?? ''), normalize_answer($answer['answer'] ?? '')]);
        }
    }

    $insertHint = $pdo->prepare('INSERT INTO patient_response_hints (response_id, position, hint) VALUES (?, ?, ?)');
    foreach (array_values($record['hints'] ?? []) as $position => $hint) {
        $insertHint->execute([$id, $position, (string)$hint]);
    }

    $insertHistory = $pdo->prepare('INSERT INTO patient_response_history (response_id, position, event_date, event_text) VALUES (?, ?, ?, ?)');
    foreach (array_values($record['history'] ?? []) as $position => $event) {
        $insertHistory->execute([$id, $position, (string)($event['date'] ?? ''), (string)($event['event'] ?? '')]);
    }
}

function import_patient_responses(string $jsonPath): array
{
    if (!is_file($jsonPath)) {
        throw new RuntimeException("Файл не найден: {$jsonPath}");
    }

    $raw = file_get_contents($jsonPath);
    $data = json_decode((string)$raw, true);
    if (!is_array($data) || !isset($data['responses']) || !is_array($data['responses'])) {
        throw new RuntimeException('JSON должен содержать массив responses.');
    }

    $responses = array_values(array_filter($data['responses'], 'is_array'));
    $questionnaireId = env_value('QUESTIONNAIRE_ID', DEFAULT_QUESTIONNAIRE_ID);
    $title = (string)($responses[0]['survey'] ?? DEFAULT_QUESTIONNAIRE_TITLE);
    $updatedAt = (string)($data['updated_at'] ?? ($responses[0]['updated_at'] ?? 'now'));
    $sections = build_questionnaire_sections($responses);

    $pdo = pdo_connection();
    $pdo->beginTransaction();
    try {
        save_questionnaire($questionnaireId, $title, $sections, $updatedAt);
        foreach ($responses as $response) {
            if (!empty($response['id'])) {
                upsert_patient_response($response, $questionnaireId);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $questionCount = 0;
    foreach ($sections as $section) {
        $questionCount += count($section['questions']);
    }

    return [
        'questionnaire_id' => $questionnaireId,
        'sections' => count($sections),
        'questions' => $questionCount,
        'responses' => count($responses),
    ];
}

if (realpath((string)($argv[0] ?? '')) === __FILE__) {
    try {
        $jsonPath = $argv[1] ?? (__DIR__ . DIRECTORY_SEPARATOR . 'patient_responses.json');
        $result = import_patient_responses($jsonPath);
        echo 'Импорт завершён: анкета=' . $result['questionnaire_id']
            . ', разделов=' . $result['sections']
            . ', вопросов=' . $result['questions']
            . ', ответов пациентов=' . $result['responses'] . PHP_EOL;
    } catch (Throwable $e) {
        fwrite(STDERR, 'Ошибка импорта: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}
