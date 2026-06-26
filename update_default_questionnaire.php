<?php
declare(strict_types=1);

/**
 * Обновляет дефолтную анкету по patient_responses.json, который лежит рядом со скриптом.
 *
 * Скрипт читает локальный JSON, строит структуру анкеты по разделам/вопросам из responses
 * и обновляет только таблицы анкеты (questionnaires, questionnaire_sections,
 * questionnaire_questions, questionnaire_question_options и подсказки).
 * Ответы пациентов этим скриптом не импортируются.
 *
 * Запуск из CLI:
 *   php update_default_questionnaire.php
 *
 * Можно передать другой локальный путь первым аргументом:
 *   php update_default_questionnaire.php /path/to/patient_responses.json
 *
 * Можно переопределить подключение и ID анкеты через env:
 *   DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_CHARSET, QUESTIONNAIRE_ID
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'import_patient_responses.php';

const DEFAULT_PATIENT_RESPONSES_FILE = 'patient_responses.json';

function patient_responses_path(array $argv): string
{
    $path = (string)($argv[1] ?? (__DIR__ . DIRECTORY_SEPARATOR . DEFAULT_PATIENT_RESPONSES_FILE));
    if ($path === '') {
        $path = __DIR__ . DIRECTORY_SEPARATOR . DEFAULT_PATIENT_RESPONSES_FILE;
    }
    if (!is_file($path)) {
        throw new RuntimeException('Файл patient_responses.json не найден: ' . $path);
    }
    if (!is_readable($path)) {
        throw new RuntimeException('Файл patient_responses.json недоступен для чтения: ' . $path);
    }
    return $path;
}

function load_patient_responses_file(string $path): array
{
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('Не удалось прочитать patient_responses.json: ' . $path);
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['responses']) || !is_array($data['responses'])) {
        throw new RuntimeException('JSON должен содержать массив responses.');
    }
    return $data;
}

function update_default_questionnaire_from_file(string $path): array
{
    $data = load_patient_responses_file($path);
    $responses = array_values(array_filter($data['responses'], 'is_array'));
    if (!$responses) {
        throw new RuntimeException('В JSON нет пригодных записей responses для построения анкеты.');
    }

    $questionnaireId = env_value('QUESTIONNAIRE_ID', DEFAULT_QUESTIONNAIRE_ID);
    $title = (string)($responses[0]['survey'] ?? DEFAULT_QUESTIONNAIRE_TITLE);
    $updatedAt = (string)($data['updated_at'] ?? ($responses[0]['updated_at'] ?? 'now'));
    $sections = build_questionnaire_sections($responses);

    $pdo = pdo_connection();
    $pdo->beginTransaction();
    try {
        save_questionnaire($questionnaireId, $title, $sections, $updatedAt);
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
        'title' => $title,
        'sections' => count($sections),
        'questions' => $questionCount,
        'source_path' => $path,
    ];
}

try {
    $result = update_default_questionnaire_from_file(patient_responses_path($argv));
    echo 'Дефолтная анкета обновлена: анкета=' . $result['questionnaire_id']
        . ', название=' . $result['title']
        . ', разделов=' . $result['sections']
        . ', вопросов=' . $result['questions']
        . ', источник=' . $result['source_path'] . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Ошибка обновления дефолтной анкеты: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
