<?php
declare(strict_types=1);

/*
  Настройки
*/
const VSEGPT_API_KEY = '...';
const VSEGPT_API_URL = 'https://api.vsegpt.ru:7090/v1/chat/completions';
const VSEGPT_MODEL   = 'gpt-4o-mini';
const AI_HINTS_FILE   = __DIR__ . '/ai_hints.json';
const PATIENT_RESPONSES_FILE = __DIR__ . '/patient_responses.json';

/*
  Helpers
*/
function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function postv($key, $default = '') {
    return $_POST[$key] ?? $default;
}

function read_json_file($file, $default) {
    if (!is_file($file)) {
        return $default;
    }
    $raw = file_get_contents($file);
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : $default;
}

function write_json_file($file, $data) {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }
    return file_put_contents($file, $json . "\n", LOCK_EX) !== false;
}

function load_patient_responses() {
    $data = read_json_file(PATIENT_RESPONSES_FILE, ['responses' => []]);
    if (!isset($data['responses']) || !is_array($data['responses'])) {
        $data['responses'] = [];
    }
    return $data;
}

function save_patient_responses($data) {
    $data['updated_at'] = date('c');
    return write_json_file(PATIENT_RESPONSES_FILE, $data);
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
    if (!empty($analysis['summary'])) {
        $html .= '<h3>Общий вывод</h3><p>'.e($analysis['summary']).'</p>';
    }
    if (!empty($analysis['priority'])) {
        $html .= '<h3>Приоритет</h3><p>'.e($analysis['priority']).'</p>';
    }
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
        'survey' => 'Анкета здоровья',
        'category' => 'Комплексный превентивный приём',
        'status' => 'completed',
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
    $data = load_patient_responses();
    array_unshift($data['responses'], $record);
    return save_patient_responses($data) ? $record['id'] : false;
}

function update_patient_response($id, $callback) {
    $data = load_patient_responses();
    foreach ($data['responses'] as &$response) {
        if ((string)($response['id'] ?? '') === (string)$id) {
            $response = $callback($response);
            $response['updated_at'] = date('c');
            unset($response);
            return save_patient_responses($data);
        }
    }
    unset($response);
    return false;
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
    $allowed = '<p><br><b><strong><i><em><u><ul><ol><li><h1><h2><h3><h4><blockquote><div><span>';
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

function load_hint_config($sections) {
    $config = default_hint_config($sections);

    if (is_file(AI_HINTS_FILE)) {
        $raw = file_get_contents(AI_HINTS_FILE);
        $saved = json_decode((string)$raw, true);
        if (is_array($saved)) {
            $saved = normalize_hint_config($saved);
            foreach ($saved['questions'] as $name => $savedQuestion) {
                if (!isset($config['questions'][$name])) {
                    continue;
                }
                $config['questions'][$name]['question_hint'] = trim((string)($savedQuestion['question_hint'] ?? ''));
                foreach (($savedQuestion['options'] ?? []) as $option => $hint) {
                    if (array_key_exists($option, $config['questions'][$name]['options'])) {
                        $config['questions'][$name]['options'][$option] = trim((string)$hint);
                    }
                }
            }
        }
    }

    return $config;
}

function save_hint_config($config) {
    $config = normalize_hint_config($config);
    $config['updated_at'] = date('c');
    $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }
    return file_put_contents(AI_HINTS_FILE, $json . "\n", LOCK_EX) !== false;
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
    $summaries = [
        1 => 'ФИО, дата рождения, пол, рост, вес и др.',
        2 => 'Как вы оцениваете состояние своего здоровья?',
        3 => 'Какая основная цель вашего визита?',
        4 => 'Симптомы, длительность, выраженность и др.',
        5 => 'Беспокоит ли вас: ухудшение памяти, зрения и др.',
        6 => 'Аппетит, стул, боли в животе и др.',
        7 => 'Одышка, кашель, затруднение дыхания и др.',
        8 => 'Объём жидкости, состояние кожи и др.',
        9 => 'Качество сна, засыпание, пробуждение и др.',
        10 => 'Настроение, стресс, привычки и др.',
        11 => 'Локализация и характер боли.',
        12 => 'Травмы и операции.',
        13 => 'Аллергические реакции.',
        14 => 'Лекарства и БАДы.',
        15 => 'Наблюдение у специалистов и диагнозы.',
        16 => 'Семейный анамнез и наследственность.',
        17 => 'Работа, проживание и факторы среды.',
        18 => 'Менструальный цикл и беременность.',
        19 => 'Либидо и урологические симптомы.',
    ];
    return [
        'title' => section_clean_title($section['title'] ?? ('Блок '.$index)),
        'summary' => $summaries[$index] ?? 'Заполните ответы раздела.',
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
    return icon_svg('<path d="M12 21V8"/><path d="M12 17c-4 0-7-3-7-7 4 0 7 3 7 7Z"/><path d="M12 14c4 0 7-3 7-7-4 0-7 3-7 7Z"/><path d="M12 10c-3 0-5-2-5-5 3 0 5 2 5 5Z"/>', 'logo-svg');
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
    $html .= '<div class="section-icon" aria-hidden="true">'.$meta['icon'].'</div>';
    $html .= '<div><h2>'.e($meta['title']).'</h2><p>'.e($meta['summary']).'</p></div>';
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

function questionnaire_sections() {
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

/*
  API endpoint
*/
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (postv('action') === 'save_hints')) {
    header('Content-Type: application/json; charset=utf-8');

    $sections = questionnaire_sections();
    $config = hints_config_from_post($sections, $_POST);

    if (!save_hint_config($config)) {
        echo json_encode([
            'ok' => false,
            'error' => 'Не удалось сохранить настройки ИИ в JSON-файл.',
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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (postv('action') === 'analyze')) {
    header('Content-Type: application/json; charset=utf-8');

    $sections = questionnaire_sections();
    $hintConfig = load_hint_config($sections);
    $sections = apply_hint_config($sections, $hintConfig);

    $surname = trim((string)postv('surname'));
    $name = trim((string)postv('name'));
    $patronymic = trim((string)postv('patronymic'));
    $dob = trim((string)postv('dob'));

    $patient = [
        'surname' => $surname,
        'name' => $name,
        'patronymic' => $patronymic,
        'dob' => $dob,
        'phone' => trim((string)postv('phone')),
        'email' => trim((string)postv('email')),
        'sex' => trim((string)postv('sex')),
        'height' => trim((string)postv('height')),
        'weight' => trim((string)postv('weight')),
        'waist' => trim((string)postv('waist')),
        'filled_at' => trim((string)postv('filled_at', date('Y-m-d'))),
    ];

    list($readableAnswers, $hints) = build_ai_payload($sections, $_POST);
    $draftRecord = build_response_record($sections, $patient, $readableAnswers, $hints);

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
        echo json_encode([
            'ok' => (bool)$responseId,
            'message' => $responseId ? 'Ответ успешно отправлен.' : 'Ответ получен, но не удалось сохранить JSON.',
            'warning' => $api['error'],
            'response_id' => $responseId,
            'patient' => $patient,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $decoded = json_decode($api['raw'], true);
    if (!is_array($decoded)) {
        $responseId = add_patient_response($draftRecord);
        echo json_encode([
            'ok' => (bool)$responseId,
            'message' => $responseId ? 'Ответ успешно отправлен.' : 'Ответ получен, но не удалось сохранить JSON.',
            'warning' => 'VSEGPT вернул невалидный JSON.',
            'response_id' => $responseId,
            'patient' => $patient,
            'raw' => $api['raw'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $content = $decoded['choices'][0]['message']['content'] ?? '';
    $clean = extract_json($content);
    $analysis = json_decode($clean, true);

    $record = build_response_record($sections, $patient, $readableAnswers, $hints, is_array($analysis) ? $analysis : null, $content);
    $record['history'][] = ['date' => date('c'), 'event' => 'Сгенерировано ИИ'];
    $responseId = add_patient_response($record);

    echo json_encode([
        'ok' => (bool)$responseId,
        'message' => $responseId ? 'Ответ успешно отправлен.' : 'Ответ получен, но не удалось сохранить JSON.',
        'response_id' => $responseId,
        'patient' => $patient,
        'analysis' => is_array($analysis) ? $analysis : null,
        'analysis_raw' => $content,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}


function questionnaire_list_items($sections) {
    $categories = [
        1 => 'Общее здоровье',
        2 => 'Общее здоровье',
        3 => 'Анамнез',
        4 => 'Неврология',
        5 => 'Гастроэнтерология',
        6 => 'Пульмонология',
        7 => 'Дерматология',
        8 => 'Сон и восстановление',
        9 => 'Психоэмоциональное здоровье',
        10 => 'Болевой синдром',
        11 => 'Травматология',
        12 => 'Аллергология',
        13 => 'Терапия',
        14 => 'Врачи и диагнозы',
        15 => 'Наследственность',
        16 => 'Образ жизни',
    ];
    $createdDates = ['12.05.2025','10.05.2025','08.05.2025','07.05.2025','05.05.2025','01.05.2025','28.04.2025','24.04.2025','22.04.2025','20.04.2025','18.04.2025','16.04.2025','14.04.2025','12.04.2025','10.04.2025','08.04.2025'];
    $updatedDates = ['14.05.2025','10.05.2025','12.05.2025','07.05.2025','11.05.2025','02.05.2025','29.04.2025','25.04.2025','23.04.2025','21.04.2025','19.04.2025','17.04.2025','15.04.2025','13.04.2025','11.04.2025','09.04.2025'];
    $responses = [128, 95, 156, null, 87, 32, 64, 48, 71, 53, 24, 45, 69, 38, 42, 57];
    $statuses = ['active','active','active','draft','active','archive','active','active','draft','active','archive','active','active','active','draft','active'];
    $colors = ['green','green','green','purple','blue','violet','teal','indigo','amber','rose','slate','orange','cyan','emerald','lime','pink'];

    $items = [];
    for ($i = 0; $i < 16 && isset($sections[$i]); $i++) {
        $meta = section_meta($i + 2, $sections[$i]);
        $items[] = [
            'title' => $meta['title'],
            'summary' => $meta['summary'],
            'category' => $categories[$i + 1] ?? 'Общее здоровье',
            'status' => $statuses[$i] ?? 'active',
            'created' => $createdDates[$i] ?? '01.04.2025',
            'updated' => $updatedDates[$i] ?? '02.04.2025',
            'author' => 'Иванова Е. А.',
            'responses' => $responses[$i] ?? null,
            'icon' => $meta['icon'],
            'color' => $colors[$i] ?? 'green',
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
            'survey' => $response['survey'] ?? 'Анкета здоровья',
            'category' => $response['category'] ?? 'Комплексный превентивный приём',
            'date' => format_response_date($response['created_at'] ?? ''),
            'doctor' => 'Иванова Е. А.',
            'status' => $response['status'] ?? 'completed',
            'progress' => $completion['percent'],
            'filled_answers' => $completion['filled'],
            'total_answers' => $completion['total'],
            'filled_label' => $completion['total'] > 0 ? ($completion['filled'] . ' из ' . $completion['total']) : ($completion['percent'] . '%'),
        ]);
    }

    return $items;
}

function patient_response_view_data() {
    return find_patient_response($_GET['id'] ?? null);
}

function response_status_label($status) {
    $labels = [
        'completed' => 'Завершено',
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

$sections = questionnaire_sections();
$hintConfig = load_hint_config($sections);
$sections = apply_hint_config($sections, $hintConfig);
$page = (string)($_GET['page'] ?? 'responses');
$listItems = questionnaire_list_items($sections);
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $page === 'form' ? 'Анкета здоровья' : ($page === 'response-view' ? 'Просмотр ответа пациента' : ($page === 'ai-settings' ? 'ИИ настройки' : 'Ответы пациентов')) ?></title>
    <style>
        :root{
            --bg:#f4f6f3;
            --card:#ffffff;
            --soft:#f7f9f7;
            --soft-green:#edf5f1;
            --line:#dfe6e1;
            --green:#6f9a8a;
            --green-dark:#477869;
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
                radial-gradient(circle at 20% 0%, rgba(111,154,138,.12), transparent 32%),
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
            border:2px solid var(--green-dark);
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
        .section-aside{display:grid;grid-template-columns:42px 72px 1fr;gap:18px;align-items:flex-start}
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
        .logo-svg{width:40px;height:40px}
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
        .field:focus{border-color:var(--green);box-shadow:0 0 0 3px rgba(111,154,138,.12)}
        textarea.field{resize:vertical;min-height:76px}
        .inline-options,.checklist{display:grid;gap:11px;grid-template-columns:1fr;max-width:100%}
        .inline-options label,.checklist label{display:flex;align-items:flex-start;gap:10px;color:#455155;line-height:1.35;cursor:pointer;min-width:0;overflow-wrap:anywhere}
        input[type="radio"],input[type="checkbox"]{accent-color:var(--green-dark);width:16px;height:16px;margin:0;flex:0 0 auto}
        .inline-options + .field,.checklist + .field{margin-top:12px;max-width:280px}
        .top-grid{display:grid;grid-template-columns:1fr;gap:16px}
        .top-grid .question{border-bottom:1px solid #edf1ee;padding-bottom:8px}
        .section-content .question:has(textarea),.section-content .question:has(.checklist){grid-column:1 / -1}
        .sex-hide{display:none}
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
            background:linear-gradient(135deg,var(--green-dark),#6f9a8a);
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
        .brand{display:flex;align-items:center;gap:16px;padding:0 12px 36px}.brand-mark{width:66px;height:66px;border-radius:50%;border:1.8px solid var(--green-dark);display:grid;place-items:center;color:var(--green-dark);background:#fff}.brand-mark .logo-svg{width:40px;height:40px}.brand-title{font-weight:900;color:var(--green-dark);letter-spacing:.05em;font-size:18px}.brand-subtitle{margin-top:5px;color:#64716d;font-size:12px;line-height:1.35}.admin-nav{display:grid;gap:10px}.admin-nav a{display:flex;align-items:center;gap:14px;padding:14px 18px;border-radius:10px;color:#515d67;text-decoration:none;font-weight:600}.admin-nav a.is-active{background:#f2f6f4;color:var(--green-dark)}.admin-nav svg{width:23px;height:23px;stroke:currentColor;stroke-width:1.7;fill:none;stroke-linecap:round;stroke-linejoin:round}.ai-card{margin-top:auto;display:grid;grid-template-columns:46px 1fr 18px;gap:14px;align-items:center;padding:22px 18px;border:1px solid #dfe8e3;border-radius:10px;background:#fff;box-shadow:0 14px 28px rgba(31,43,39,.04);color:var(--green-dark);text-decoration:none}.ai-card strong{display:block;margin-bottom:7px}.ai-card span{color:#6b7774;line-height:1.35}.doctor-card{margin-top:120px;display:flex;align-items:center;gap:13px;padding:20px 12px;border:1px solid #dfe8e3;border-radius:10px;background:#fff}.doctor-avatar{width:58px;height:58px;border-radius:50%;display:grid;place-items:center;background:linear-gradient(135deg,#e9e0d8,#b8c8c2);font-size:31px}.doctor-name{font-weight:800;color:#394349}.doctor-role{color:#75807c;font-size:13px;line-height:1.25}.doctor-card svg{margin-left:auto;width:18px;height:18px;stroke:#51615c;fill:none}
        .admin-main{padding:54px 40px 34px;min-width:0}.admin-header{display:flex;justify-content:space-between;align-items:flex-start;gap:24px;margin-bottom:36px}.admin-title h1{font-size:34px;line-height:1.1;margin:0 0 12px;color:#2e363d}.admin-title p{margin:0;color:#59636d;font-size:17px}.create-btn{display:inline-flex;align-items:center;gap:14px;padding:18px 24px;border-radius:7px;background:linear-gradient(135deg,var(--green-dark),#5b8f7f);color:#fff;text-decoration:none;font-weight:800;box-shadow:0 12px 28px rgba(71,120,105,.2)}.create-btn svg{width:20px;height:20px;stroke:currentColor;stroke-width:2;fill:none}
        .filters-card{display:grid;grid-template-columns:minmax(260px,1.8fr) 190px 230px 190px 210px;gap:28px;align-items:end;padding:32px 22px;border:1px solid #dfe6e2;border-radius:10px;background:#fff;margin-bottom:20px}.filter-field{position:relative}.filter-field label{position:absolute;left:12px;top:-13px;background:#fff;padding:0 7px;color:#65716d;font-size:12px}.filter-control{width:100%;height:52px;border:1px solid #dfe6e2;border-radius:7px;background:#fff;color:#46515a;font:inherit;padding:0 14px}.search-control{padding-left:56px}.search-icon{position:absolute;left:20px;bottom:15px;color:var(--green-dark)}.reset-filter{height:48px;border:1px solid #dfe6e2;border-radius:7px;background:#fff;color:#4e5963;font-weight:600;font:inherit;display:flex;align-items:center;justify-content:center;gap:12px}.reset-filter svg,.search-icon{width:20px;height:20px;stroke:currentColor;stroke-width:1.8;fill:none;stroke-linecap:round;stroke-linejoin:round}
        .questionnaire-table{border:1px solid #dfe6e2;border-radius:10px;background:#fff;overflow:hidden}.table-head,.table-row{display:grid;grid-template-columns:2.15fr 1.18fr .92fr 1.22fr 1.22fr .86fr 1.12fr;align-items:center;column-gap:24px}.table-head{padding:0 22px;height:58px;color:#303b45;font-size:13px;font-weight:800}.table-row{min-height:122px;padding:0 22px;border-top:1px solid #e7ece9}.survey-name{display:grid;grid-template-columns:60px 1fr;gap:24px;align-items:center;min-width:0}.survey-icon{width:60px;height:60px;border-radius:9px;display:grid;place-items:center;background:#edf5f1;color:var(--green-dark)}.survey-icon .icon-svg{width:36px;height:36px}.survey-icon.purple{background:#f0eafa;color:#7455c7}.survey-icon.blue{background:#edf8ff;color:#1687d9}.survey-icon.violet{background:#f4eef8;color:#805bb7}.survey-icon.amber{background:#fff6e0;color:#b98900}.survey-icon.rose{background:#fff0f2;color:#bd5c72}.survey-icon.orange{background:#fff3e9;color:#c66f27}.survey-title{font-weight:800;color:#25303a;margin-bottom:7px;line-height:1.35}.survey-summary,.table-muted{color:#5e6974;line-height:1.55}.status-pill{display:inline-flex;align-items:center;gap:8px;border-radius:6px;padding:8px 11px;font-weight:800;font-size:13px}.status-pill:before{content:"";width:9px;height:9px;border-radius:50%;background:currentColor}.status-active{background:#e9f4ef;color:#3b806e}.status-draft{background:#fff6df;color:#b98500}.status-archive{background:#f0f2f3;color:#77808a}.responses-count{color:var(--green-dark);font-weight:700}.actions-cell{display:flex;gap:16px;justify-content:flex-end}.icon-button{width:52px;height:52px;border:1px solid #dfe6e2;border-radius:7px;background:#fff;display:grid;place-items:center;color:#28333c;text-decoration:none}.icon-button svg{width:20px;height:20px;stroke:currentColor;stroke-width:1.8;fill:none;stroke-linecap:round;stroke-linejoin:round}.sort-mark{color:var(--green-dark);font-size:18px;margin-left:8px}.table-footer{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:18px 22px 12px;color:#66716c}.pagination{display:flex;align-items:center;gap:16px}.page-btn,.page-number{width:40px;height:40px;border:1px solid transparent;background:#fff;border-radius:8px;display:grid;place-items:center;color:#26323a;text-decoration:none}.page-btn{border-color:#dfe6e2}.page-number.is-current{border-color:var(--green-dark);color:var(--green-dark)}.per-page{display:flex;align-items:center;gap:14px}.per-page select{width:86px;height:46px;border:1px solid #dfe6e2;border-radius:7px;background:#fff;padding:0 14px;color:#59636d}
        .responses-header{margin-bottom:34px}.response-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:36px;margin-bottom:30px}.stat-card{min-height:92px;border:1px solid #dfe6e2;border-radius:10px;background:#fff;display:flex;align-items:center;gap:20px;padding:20px 28px}.stat-card span{width:58px;height:58px;border-radius:8px;display:grid;place-items:center;background:#edf5f1;color:var(--green-dark);flex:0 0 auto}.stat-card .icon-svg{width:30px;height:30px}.stat-card strong{display:block;font-size:26px;line-height:1;color:#2c3540;margin-bottom:9px}.stat-card p{margin:0;color:#5f6974}.stat-blue span{background:#eef8ff;color:#1477d4}.stat-amber span{background:#fff7e5;color:#df9d05}.responses-table .table-head,.responses-table .table-row{grid-template-columns:1.55fr 1.7fr 1.35fr .98fr 1.05fr .58fr}.responses-table .table-row{min-height:84px}.patient-name{display:grid;grid-template-columns:44px 1fr;gap:16px;align-items:center;min-width:0}.patient-avatar{width:44px;height:44px;border-radius:50%;display:grid;place-items:center;background:#edf1ef;overflow:hidden;font-size:13px;font-weight:900;color:var(--green-dark)}.response-pill{display:inline-flex;border-radius:6px;padding:7px 10px;font-weight:800;font-size:12px}.response-completed{background:#e4f0eb;color:#2f806d}.response-progress{background:#e7f2ff;color:#1c67bf}.response-draft{background:#edf0f2;color:#606a74}.progress-cell{display:grid;gap:10px;align-content:center}.progress-cell strong{font-size:15px;color:#2d3741}.mini-progress{display:block;width:150px;max-width:100%;height:5px;border-radius:999px;background:#e1e6e9;overflow:hidden}.mini-progress i{display:block;height:100%;border-radius:inherit;background:var(--green-dark)}
        .view-main{padding-top:46px}.breadcrumbs{display:flex;align-items:center;gap:12px;margin-bottom:22px;color:#66716c;font-size:14px}.breadcrumbs a{color:#66716c;text-decoration:none}.view-header{display:flex;justify-content:space-between;gap:24px;align-items:flex-start;margin-bottom:28px}.view-title h1{margin:0 0 16px;font-size:34px;line-height:1.1;color:#2e363d}.view-meta{display:flex;align-items:center;gap:24px;flex-wrap:wrap;color:#59636d}.view-meta span{display:inline-flex;align-items:center;gap:8px}.view-status,.ai-generated{display:inline-flex;align-items:center;border-radius:999px;padding:8px 14px;background:#e4f0eb;color:#2f806d;font-size:12px;font-weight:800}.view-actions{display:flex;gap:14px;align-items:center}.outline-btn,.more-btn{height:56px;border:1px solid #dfe6e2;border-radius:7px;background:#fff;color:#3e4b53;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:12px;padding:0 22px;font-weight:800}.outline-btn svg,.more-btn svg{width:20px;height:20px;stroke:currentColor;stroke-width:1.8;fill:none;stroke-linecap:round;stroke-linejoin:round}.more-btn{width:56px;padding:0}.view-grid{display:grid;grid-template-columns:minmax(360px,.9fr) minmax(520px,1.1fr);gap:24px}.view-card{border:1px solid #dfe6e2;border-radius:12px;background:#fff;padding:22px;box-shadow:0 12px 30px rgba(40,55,50,.035)}.view-card h2{margin:0 0 20px;color:#25303a;font-size:20px}.answer-section{border:1px solid #dfe6e2;border-radius:9px;padding:16px 18px;margin-bottom:12px}.answer-section h3{margin:0 0 14px;display:flex;align-items:center;gap:12px;color:var(--green-dark);font-size:14px;text-transform:uppercase}.answer-section h3 span{width:24px;height:24px;border-radius:5px;display:grid;place-items:center;background:var(--green-dark);color:#fff;font-size:13px}.answer-row{display:grid;grid-template-columns:1fr 1fr;gap:18px;padding:6px 0;color:#334049}.answer-row small{color:#53605d;font-weight:700}.answer-section p{margin:8px 0;color:#334049;line-height:1.55}.answer-table{width:100%;border-collapse:collapse;font-size:13px}.answer-table th,.answer-table td{padding:9px 4px;border-bottom:1px solid #edf1ef;text-align:left}.answer-table th{color:#53605d}.show-all{width:100%;height:52px;border:1px solid #dfe6e2;border-radius:8px;background:#fff;color:#25303a;font-weight:800}.ai-card-view{padding:0;overflow:hidden}.ai-panel{padding:24px 28px;border:1px solid #dfe6e2;border-radius:9px;margin-bottom:18px;line-height:1.7}.ai-panel h3,.editor-title{margin:0 0 14px;color:var(--green-dark);font-size:16px}.ai-panel ul,.ai-panel ol,.editor-content ul,.editor-content ol{margin:8px 0 22px 20px;padding:0}.ai-panel li,.editor-content li{margin:8px 0}.editor-box{border:1px solid #dfe6e2;border-radius:9px;overflow:hidden}.editor-toolbar{display:flex;gap:4px;align-items:center;height:44px;border-bottom:1px solid #dfe6e2;background:#fbfcfb;padding:0 12px;color:#4c5962}.tool{width:28px;height:28px;display:grid;place-items:center;border-radius:5px;font-weight:800}.editor-content{padding:18px 22px;line-height:1.65;min-height:430px}.char-count{text-align:right;color:#7c8783;font-size:12px;margin-top:8px}.save-note{margin-top:18px;border-radius:8px;background:#edf5f1;color:var(--green-dark);padding:15px 18px}.history-card{margin-top:24px}.history-row{display:grid;grid-template-columns:150px 1fr;gap:16px;margin:12px 0;color:#59636d}.history-card .outline-btn{height:42px;float:right;margin-top:-50px}


        .settings-panel{display:block;position:static;background:transparent;padding:0}.settings-panel .modal{width:100%;height:auto;min-height:calc(100vh - 190px);overflow:visible;box-shadow:var(--shadow);border:1px solid var(--line)}.settings-panel .modal-form,.settings-panel .modal-layout{overflow:visible}.settings-panel .modal-layout{align-items:start}.settings-panel .hint-nav{top:24px;max-height:calc(100vh - 48px);z-index:5}.settings-panel .hint-editor{overflow:visible}.settings-panel .modal-close,.settings-panel #cancelAiHints{display:none}.settings-panel .modal-actions{position:sticky;bottom:0;background:#fff}
        .success-modal{position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(38,45,44,.48);z-index:1200}.success-modal.is-open{display:flex}.success-dialog{width:min(460px,calc(100vw - 32px));background:#fff;border-radius:24px;box-shadow:0 24px 70px rgba(20,30,28,.28);padding:34px;text-align:center;position:relative}.success-icon{width:68px;height:68px;margin:0 auto 18px;border-radius:50%;display:grid;place-items:center;background:#edf5f1;color:var(--green-dark);font-size:38px;font-weight:800}.success-dialog h2{margin:0 0 10px;color:#2d3937}.success-dialog p{margin:0 0 24px;color:var(--muted);line-height:1.55}.editor-toolbar button.tool{border:0;background:#f3f6f4;cursor:pointer}.editor-content[contenteditable="true"]{outline:none;min-height:280px}.outline-btn,.create-btn{border:0;cursor:pointer;text-decoration:none}.empty-state{padding:28px;color:var(--muted);text-align:center}
        @media (max-width:1100px){.app-shell{grid-template-columns:1fr}.admin-sidebar{position:relative;height:auto}.filters-card,.table-head,.table-row,.response-stats{grid-template-columns:1fr}.table-head{display:none}.table-row{gap:12px;padding:22px}.doctor-card{margin-top:24px}.section-card{grid-template-columns:1fr}.section-aside{grid-template-columns:36px 56px 1fr}.section-content,.top-grid{grid-template-columns:1fr}.progress-card{display:none}.modal-layout{grid-template-columns:1fr}.hint-nav{display:none}.modal{width:calc(100vw - 28px);height:calc(100vh - 28px)}.hint-option-row{grid-template-columns:18px 1fr}.hint-option-row .hint-textarea-wrap{grid-column:2}.actions{flex-wrap:wrap}}
        @media (max-width:720px){.admin-main{padding:26px 14px}.admin-header{display:block}.create-btn{margin-top:18px}.filters-card{gap:16px;padding:22px 14px}.survey-name{grid-template-columns:48px 1fr;gap:14px}.survey-icon{width:48px;height:48px}.table-footer{display:grid;justify-items:start}.wrap{margin:0;border-radius:0;padding:16px 12px}.hero{display:block}.date-card{margin-top:14px}.section-content,.top-grid,.inline-options,.checklist{grid-template-columns:1fr}.section-aside{grid-template-columns:34px 50px 1fr}.btn{width:100%;min-width:0}.actions{position:static;padding-left:0;padding-right:0}.modal-backdrop{padding:8px}.modal-head,.modal-layout,.modal-actions{padding-left:18px;padding-right:18px}.modal-actions{flex-direction:column}.hint-status{margin-right:0}.btn-reset{min-width:0;width:100%}}
    </style>
</head>
<body>
<?php if ($page !== 'form'): ?>
<div class="app-shell">
    <aside class="admin-sidebar">
        <div class="brand">
            <div class="brand-mark" aria-hidden="true"><?= hero_logo_svg() ?></div>
            <div>
                <div class="brand-title">ОПРОСНИК</div>
                <div class="brand-subtitle">для комплексного<br>превентивного приёма</div>
            </div>
        </div>
        <nav class="admin-nav" aria-label="Основное меню">
            <a class="<?= $page === 'form' ? 'is-active' : '' ?>" href="?page=form"><svg viewBox="0 0 24 24"><path d="M6 3h12v18H6z"/><path d="M9 8h6M9 12h6M9 16h4"/></svg><span>Анкета</span></a>
            <a class="<?= ($page === 'responses' || $page === 'response-view') ? 'is-active' : '' ?>" href="?page=responses"><svg viewBox="0 0 24 24"><path d="M4 5h14v14H4z"/><path d="M8 9h6M8 13h4M18 12l2 2 3-5"/></svg><span>Ответы пациентов</span></a>
            <a class="<?= $page === 'ai-settings' ? 'is-active' : '' ?>" href="?page=ai-settings"><svg viewBox="0 0 24 24"><path d="M12 2l1.5 4.5L18 8l-4.5 1.5L12 14l-1.5-4.5L6 8l4.5-1.5L12 2Z"/><path d="M4 19h16M6 16h12"/></svg><span>ИИ настройки</span></a>
        </nav>
        <div class="doctor-card"><div class="doctor-avatar">👩🏻‍⚕️</div><div><div class="doctor-name">Иванова Е. А.</div><div class="doctor-role">Врач превентивной<br>медицины</div></div><svg viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg></div>
    </aside>
    <?php if ($page === 'ai-settings'): ?>
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
        <section class="view-card"><h1>Ответы пациентов не найдены</h1><p>После отправки анкеты данные появятся здесь из JSON-файла.</p><a class="create-btn" href="?page=form">Заполнить анкету</a></section>
    </main>
    <?php else: ?>
    <?php $patient = $response['patient'] ?? []; if (!is_array($patient)) { $patient = []; } $answerSections = $response['answers'] ?? []; if (!is_array($answerSections)) { $answerSections = []; } $history = $response['history'] ?? []; if (!is_array($history)) { $history = []; } $aiHtml = sanitize_editor_html($response['ai_answer_html'] ?? ai_analysis_to_html($response['analysis'] ?? null)); ?>
    <main class="admin-main view-main">
        <nav class="breadcrumbs" aria-label="Хлебные крошки"><a href="?page=responses">Ответы пациентов</a><span>›</span><span><?= e($response['survey']) ?></span><span>›</span><span>Просмотр</span></nav>
        <header class="view-header">
            <div class="view-title">
                <h1>Ответ пациента <span class="view-status"><?= e(response_status_label($response['status'] ?? 'completed')) ?></span></h1>
                <div class="view-meta"><span>♙ <?= e($response['patient_display'] ?? response_full_name($patient)) ?></span><span>⊙ <?= e($response['meta']) ?></span><span>Заполнено: <?= e($response['date']) ?></span></div>
            </div>
            <div class="view-actions">
                <button class="outline-btn" type="button" id="createPdfBtn"><svg viewBox="0 0 24 24"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>Создать PDF</button>
                <button class="create-btn" type="button" id="saveAiAnswerBtn"><svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="m16.5 3.5 4 4L8 20H4v-4L16.5 3.5Z"/></svg>Сохранить ИИ-ответ</button>
            </div>
        </header>
        <div class="view-grid">
            <section class="view-card">
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
                            <button type="button" class="tool" data-cmd="undo">↶</button><button type="button" class="tool" data-cmd="redo">↷</button><button type="button" class="tool" data-cmd="bold"><b>B</b></button><button type="button" class="tool" data-cmd="italic"><i>I</i></button><button type="button" class="tool" data-cmd="underline"><u>U</u></button><button type="button" class="tool" data-cmd="insertUnorderedList">•</button><button type="button" class="tool" data-cmd="insertOrderedList">1.</button>
                        </div>
                        <div class="editor-content" id="aiEditor" contenteditable="true"><?= $aiHtml ?></div>
                    </div>
                    <div class="char-count" id="aiCharCount">Символов: 0</div><div class="save-note" id="aiSaveStatus">ⓘ Этот ответ будет сохранён и включён в PDF.</div></div>
                </div>
                <div class="view-card history-card"><h2>История изменений</h2><?php foreach ($history as $event): ?><div class="history-row"><div><?= e(format_response_date($event['date'] ?? '')) ?></div><div><?= e($event['event'] ?? '') ?></div></div><?php endforeach; ?></div>
            </section>
        </div>
    </main>
    <?php endif; ?>
    <?php else: ?>
    <main class="admin-main">
        <?php $responseItems = patient_response_items(); $totalResponses = count($responseItems); $uniquePatients = count(array_unique(array_map(function ($item) { return $item['patient_display']; }, $responseItems))); $completedResponses = count(array_filter($responseItems, function ($item) { return ($item['status'] ?? '') === 'completed'; })); ?>
        <header class="admin-header responses-header">
            <div class="admin-title"><h1>Ответы пациентов</h1><p>Просматривайте и анализируйте ответы пациентов по анкетам.</p></div>
        </header>
        <section class="response-stats" aria-label="Сводка ответов">
            <article class="stat-card stat-green"><span><?= icon_svg('<path d="M9 5h9v16H6V5h3Z"/><path d="M9 5a3 3 0 0 1 6 0H9Z"/><path d="M9 11h6M9 15h6"/>') ?></span><div><strong><?= e($totalResponses) ?></strong><p>Всего ответов</p></div></article>
            <article class="stat-card stat-blue"><span><?= icon_svg('<path d="M16 21v-2a4 4 0 0 0-8 0v2"/><path d="M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/><path d="M20 21v-2a3 3 0 0 0-2-2.8"/><path d="M18 4.2a3 3 0 0 1 0 5.6"/>') ?></span><div><strong><?= e($uniquePatients) ?></strong><p>Пациент</p></div></article>
            <article class="stat-card stat-amber"><span><?= icon_svg('<path d="M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z"/><path d="m8.5 12 2.2 2.2 4.8-5"/>') ?></span><div><strong><?= e($completedResponses) ?></strong><p>Завершено</p></div></article>
        </section>
        <section class="questionnaire-table responses-table" aria-label="Ответы пациентов">
            <div class="table-head"><div>Пациент</div><div>Анкета</div><div>Дата заполнения <span class="sort-mark">↕</span></div><div>Статус</div><div>Заполнено</div><div>Действия</div></div>
            <?php if (!$responseItems): ?>
            <div class="empty-state">Пока нет сохранённых ответов. Заполните анкету, чтобы данные появились здесь из JSON.</div>
            <?php endif; ?>
            <?php foreach ($responseItems as $item): ?>
            <article class="table-row">
                <div class="patient-name"><div class="patient-avatar" aria-hidden="true"><?= e($item['avatar']) ?></div><div><div class="survey-title"><?= e($item['patient_display']) ?></div><div class="table-muted"><?= e($item['meta']) ?></div></div></div>
                <div><div class="survey-title"><?= e($item['survey']) ?></div><div class="table-muted"><?= e($item['category']) ?></div></div>
                <div><div><?= e($item['date']) ?></div><div class="table-muted"><?= e($item['doctor']) ?></div></div>
                <div><span class="response-pill response-<?= e($item['status']) ?>"><?= e(response_status_label($item['status'])) ?></span></div>
                <div class="progress-cell"><strong><?= e($item['filled_label']) ?></strong><span class="mini-progress"><i style="width:<?= e($item['progress']) ?>%"></i></span></div>
                <div class="actions-cell"><a class="icon-button" href="?page=response-view&amp;id=<?= e($item['id']) ?>" aria-label="Посмотреть"><svg viewBox="0 0 24 24"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z"/><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/></svg></a></div>
            </article>
            <?php endforeach; ?>
        </section>
        <footer class="table-footer"><div>Показано <?= e($totalResponses) ?> из <?= e($totalResponses) ?> ответа</div></footer>
    </main>
    <?php endif; ?>
</div>
<?php else: ?>

<div class="wrap">
    <div class="hero">
        <div class="hero-title">
            <div class="hero-logo" aria-hidden="true"><?= hero_logo_svg() ?></div>
            <div>
                <h1>ОПРОСНИК</h1>
                <p>для комплексного превентивного приёма</p>
            </div>
        </div>
        <div class="date-card">
            <span>Дата заполнения:<b><?= e(date('d.m.Y')) ?></b></span>
        </div>
    </div>

    <form id="quizForm">
        <input type="hidden" name="action" value="analyze">
        <input type="hidden" name="filled_at" value="<?= e(date('Y-m-d')) ?>">

        <section class="section-card">
            <aside class="section-aside">
                <div class="section-number">1</div>
                <div class="section-icon" aria-hidden="true"><?= section_icon_svg(1) ?></div>
                <div>
                    <h2>ОБЩАЯ ИНФОРМАЦИЯ</h2>
                    <p>ФИО, дата рождения, пол, рост, вес и др.</p>
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
                        <input class="field" type="tel" name="phone" required>
                    </div>
                    <div class="question">
                        <div class="question-label">E-mail</div>
                        <input class="field" type="email" name="email">
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

        <div class="actions">
            <div class="progress-card">
                <span class="progress-icon">✓</span>
                <span class="progress-text" id="progressText">Заполнено: 0 из 19 разделов</span>
                <span class="progress-track"><span class="progress-fill" id="progressFill"></span></span>
            </div>
            <button type="submit" class="btn" id="submitBtn">ОТПРАВИТЬ АНКЕТУ</button>
        </div>
    </form>

    <div id="result" class="result" style="display:none;"></div>
</div>

<div class="success-modal" id="successModal" aria-hidden="true"><div class="success-dialog"><button type="button" class="modal-close" id="closeSuccessModal" aria-label="Закрыть">×</button><div class="success-icon">✓</div><h2>Ответ успешно отправлен</h2></div></div>
<?php endif; ?>

<?php if ($page !== 'questionnaires'): ?>
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

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function openSuccessModal() {
        if (!successModal) return;
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
            submitBtn.disabled = true;
            submitBtn.textContent = 'Отправка...';
            if (result) {
                result.style.display = 'block';
                result.innerHTML = '<p>Отправка и анализ...</p>';
            }
            try {
                const fd = new FormData(form);
                const res = await fetch(location.href, {method: 'POST', body: fd, headers: {'X-Requested-With': 'fetch'}});
                const data = await res.json();
                if (!data.ok) throw new Error(data.error || data.message || 'Ошибка при сохранении анкеты');
                if (result) {
                    result.style.display = 'none';
                    result.innerHTML = '';
                }
                openSuccessModal();
            } catch (err) {
                if (result) result.innerHTML = `<div class="error">${escapeHtml(err.message || 'Неизвестная ошибка')}</div>`;
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Отправить анкету';
            }
        });
    }

    function initAiResponseEditor() {
        const editor = document.getElementById('aiEditor');
        if (!editor) return;
        const charCount = document.getElementById('aiCharCount');
        const saveStatus = document.getElementById('aiSaveStatus');
        const saveBtn = document.getElementById('saveAiAnswerBtn');
        const pdfBtn = document.getElementById('createPdfBtn');
        const params = new URLSearchParams(location.search);
        const responseId = params.get('id') || '';

        function updateCount() {
            if (charCount) charCount.textContent = `Символов: ${editor.innerText.trim().length}`;
        }
        updateCount();
        editor.addEventListener('input', updateCount);
        document.querySelectorAll('.editor-toolbar [data-cmd]').forEach((button) => {
            button.addEventListener('click', () => {
                document.execCommand(button.dataset.cmd, false, null);
                editor.focus();
                updateCount();
            });
        });
        saveBtn?.addEventListener('click', async () => {
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
            } catch (err) {
                if (saveStatus) saveStatus.textContent = err.message || 'Ошибка сохранения';
            } finally {
                saveBtn.disabled = false;
            }
        });
        pdfBtn?.addEventListener('click', () => {
            const win = window.open('', '_blank');
            if (!win) return;
            win.document.write(`<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>Ответ ИИ</title><style>body{font-family:Arial,sans-serif;line-height:1.55;padding:32px;color:#273235}h1,h2,h3{color:#477869}</style></head><body><h1>Ответ ИИ</h1>${editor.innerHTML}</body></html>`);
            win.document.close();
            win.focus();
            win.print();
        });
    }

    initHintsEditor();
    initQuizForm();
    initAiResponseEditor();
})();
</script>
<?php endif; ?>
</body>
</html>
