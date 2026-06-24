<?php
/**
 * Health questionnaire landing page with Bitrix24 CRM submission.
 *
 * Configure before production by setting environment variables or editing defaults:
 * - BITRIX24_WEBHOOK_URL: https://example.bitrix24.ru/rest/1/webhook/crm.lead.add.json
 * - BITRIX24_CATEGORY_ID: funnel/category id
 * - BITRIX24_STAGE_ID: target status/stage id
 */

$bitrixWebhookUrl = getenv('BITRIX24_WEBHOOK_URL') ?: '';
$bitrixCategoryId = getenv('BITRIX24_CATEGORY_ID') ?: '';
$bitrixStageId = getenv('BITRIX24_STAGE_ID') ?: '';

$textFields = [
    ['last-name', 'Фамилия *', 'Фамилия', 'text', true],
    ['first-name', 'Имя *', 'Имя', 'text', true],
    ['sur-name', 'Отчество', 'Отчество', 'text', false],
    ['phone', 'Телефон *', 'Телефон', 'tel', true],
    ['email', 'Ваш E-mail *', 'Ваш E-mail', 'email', true],
];

$fields = [
    ['radio', 'health-state', 'Как Вы оцениваете состояние своего здоровья *', ['хорошее', 'удовлетворительное', 'плохое'], true],
    ['radio', 'how-freq', 'Как часто Вы болеете *', ['1 раз в месяц', '1 раз в 3 месяца', '1 раз в 6 месяцев', '1 раз в 1 год', 'другой вариант'], true],
    ['checkbox', 'doc-checkup', 'В настоящее находитесь или ранее находились на обследовании, диспансерном наблюдении или лечении у врачей следующих специальностей', ['аллерголог', 'гастроэнтеролог', 'гематолог', 'гинеколог', 'дерматовенеролог', 'иммунолог', 'инфекционист', 'кардиолог', 'невролог', 'нефролог', 'нарколог', 'офтальмолог', 'отоларинголог', 'пульмонолог', 'фтизиатр', 'психиатр', 'психолог', 'уролог', 'хирург или травматолог', 'эндокринолог'], false],
    ['textarea', 'ongoing-medications', 'Какие лекарственные препараты Вы принимаете на постоянной основе', [], false],
    ['textarea', 'course-medications', 'Какие лекарственные препараты Вы принимаете курсами', [], false],
    ['textarea', 'take-bads', 'Принимаете БАДы? Если да, то какие?', [], false],
    ['textarea', 'any-injuries', 'В течении жизни, начиная с раннего возраста, были ли травмы? Если были, то какие?', [], false],
    ['radio', 'chest-pain', 'Бывают ли у Вас боли, ощущения давления или другие неприятные ощущения в грудной клетке', ['Да', 'Нет'], false],
    ['radio', 'blood-pressure', 'Ваше артериальное давление: -120\80', ['Да', 'Нет'], false],
    ['radio', 'headache', 'Беспокоит ли Вас головная боль', ['Да', 'Нет'], false],
    ['radio', 'dizziness', 'Бывают ли головокружения', ['Да', 'Нет'], false],
    ['radio', 'weakness', 'Беспокоит ли Вас слабость, быстрая утомляемость', ['Да', 'Нет'], false],
    ['checkbox', 'complaints-1', 'Есть жалобы на', ['ухудшение памяти', 'нарушения зрения', 'снижение слуха'], false],
    ['radio', 'gasp', 'При приступах удушья затруднен', ['вдох', 'выдох'], false],
    ['radio', 'fast-weight', 'Были ли ситуации когда Вы быстро худели или быстро набирали вес?', ['Да', 'Нет'], false],
    ['checkbox', 'complaints-2', 'Есть ли жалобы на', ['ухудшение аппетита', 'повышение аппетита', 'Нет'], false],
    ['radio', 'heaviness', 'Есть ли ощущение тяжести в верхней части живота после еды?', ['Да', 'Нет'], false],
    ['radio', 'abdominal-pain', 'Бывают ли боли в животе', ['до еды', 'после еды', 'не бывают'], false],
    ['radio', 'following-symptoms', 'Бывают ли у Вас следующие симптомы', ['рвота', 'тошнота', 'изжога', 'затруднение при глотании', 'Нет'], false],
    ['radio', 'stool-disorder', 'Есть ли расстройство стула', ['запор', 'жидкий стул', 'Нет'], false],
    ['radio', 'temperature-rise', 'Было ли беспричинное повышение температуры', ['Да', 'Нет'], false],
    ['radio', 'have-operations', 'Были операции', ['Да', 'Нет'], false],
    ['radio', 'allergic-reactions', 'Есть у Вас аллергические реакции', ['Да', 'Нет'], false],
    ['textarea', 'allergic-desc', 'Если есть опишите их', [], false],
    ['radio', 'runny-nose', 'Беспокоят ли Вас затруденное дыхание через нос, насморк?', ['Да', 'Нет'], false],
    ['radio', 'thirst-dryness', 'Беспокоят ли Вас', ['постоянная сухость во рту', 'беспричинная жажда', 'зуд кожи', 'нет'], false],
    ['radio', 'drink-liquid', 'Какой объем жидкости Вы выпиваете в течении дня', ['2 стакана воды', '1 литр воды', 'больше 1 литра', 'не знаю'], false],
    ['radio', 'sleep', 'Сон', ['сплю в течении 8 часов не просыпаюсь', 'просыпаюсь в течении ночи 2-3 раза', 'просыпаюсь в течении ночи 1 раз', 'сон спокойный без сновидений', 'сон беспокойный', 'лучше сплю в дневные часы, чем ночью', 'долго не могу заснуть', 'засыпаю быстро'], false],
    ['radio', 'do-sports', 'Занимаюсь спортом', ['Да', 'Нет'], false],
    ['radio', 'irritability', 'Характерны ли для Вас раздражительность, плохое настроение?', ['Да', 'Нет'], false],
    ['checkbox', 'cope-stress', 'Как справляетесь со стрессовыми ситуациями', ['занимаюсь физическим трудом', 'курю', 'пью алкогольные напитки', 'иду кушать', 'другое'], false],
    ['radio', 'pains-urinating', 'Бывают боли при мочеиспускании?', ['Да', 'Нет'], false],
    ['radio', 'pain-joints', 'Возникают ли болезненные ощущения в суставах?', ['Да', 'Нет'], false],
    ['radio', 'pain-back', 'Бывают ли боли в спине', ['Да', 'Нет'], false],
    ['radio', 'pain-back-desc', 'Если бывают, то постарайтесь описать где ощущаете боль и какой характер боли', ['колющая', 'тянущая', 'сильная', 'терпимая', 'Нет'], false],
    ['textarea', 'be-health', 'Что для Вас значит быть здоровым?', [], false],
];

function clean_value($value) {
    if (is_array($value)) {
        return array_values(array_filter(array_map('clean_value', $value), static fn($item) => $item !== ''));
    }

    return trim(strip_tags((string) $value));
}

function field_value(array $source, string $name): string {
    $value = $source[$name] ?? '';
    return is_array($value) ? implode(', ', clean_value($value)) : clean_value($value);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $required = ['last-name', 'first-name', 'phone', 'email', 'health-state', 'how-freq'];
    foreach ($required as $name) {
        if (empty($_POST[$name])) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Заполните обязательные поля.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    if (!filter_var((string) $_POST['email'], FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Укажите корректный E-mail.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $fullName = trim(field_value($_POST, 'last-name') . ' ' . field_value($_POST, 'first-name') . ' ' . field_value($_POST, 'sur-name'));
    $comments = [];
    foreach (array_merge($textFields, $fields) as $field) {
        $name = $field[0] === 'textarea' || in_array($field[0], ['radio', 'checkbox'], true) ? $field[1] : $field[0];
        $label = $field[0] === 'textarea' || in_array($field[0], ['radio', 'checkbox'], true) ? $field[2] : $field[1];
        $value = field_value($_POST, $name);
        if ($value !== '') {
            $comments[] = $label . ': ' . $value;
        }
    }

    $payload = [
        'fields' => array_filter([
            'TITLE' => 'Анкета здоровья — ' . $fullName,
            'NAME' => field_value($_POST, 'first-name'),
            'LAST_NAME' => field_value($_POST, 'last-name'),
            'SECOND_NAME' => field_value($_POST, 'sur-name'),
            'PHONE' => [['VALUE' => field_value($_POST, 'phone'), 'VALUE_TYPE' => 'WORK']],
            'EMAIL' => [['VALUE' => field_value($_POST, 'email'), 'VALUE_TYPE' => 'WORK']],
            'COMMENTS' => implode("\n", $comments),
            'CATEGORY_ID' => $GLOBALS['bitrixCategoryId'],
            'STATUS_ID' => $GLOBALS['bitrixStageId'],
            'SOURCE_ID' => 'WEB',
        ], static fn($value) => $value !== '' && $value !== null),
        'params' => ['REGISTER_SONET_EVENT' => 'Y'],
    ];

    if ($bitrixWebhookUrl !== '') {
        $ch = curl_init($bitrixWebhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 20,
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $decoded = json_decode((string) $response, true);
        if ($error || $statusCode >= 400 || isset($decoded['error'])) {
            http_response_code(502);
            echo json_encode(['success' => false, 'message' => 'Не удалось отправить анкету в CRM. Попробуйте позже.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    echo json_encode(['success' => true, 'message' => 'Анкета успешно отправлена. Спасибо!'], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Анкета здоровья</title>
    <style>
        :root { --accent:#01b3d8; --bg:#eef3f8; --panel:#f7f7f7; --text:#030303; }
        * { box-sizing:border-box; }
        body { margin:0; font-family:Arial, Helvetica, sans-serif; background:var(--bg); color:var(--text); }
        .page { padding:7px 9px; }
        .hero { min-height:253px; display:flex; flex-direction:column; align-items:center; justify-content:flex-start; padding-top:59px; background:linear-gradient(180deg,#08aac8 0%,#0798c9 100%); border-radius:25px; color:#fff; }
        .brand { display:inline-flex; align-items:center; gap:5px; padding:7px 16px; border-radius:999px; background:#fff; color:var(--accent); font-weight:700; text-transform:uppercase; font-size:13px; }
        .hero h1 { margin:18px 0 0; font-size:47px; font-weight:400; line-height:1.15; }
        .layout { display:grid; grid-template-columns:minmax(260px, 1fr) minmax(420px, 675px); gap:30px; margin-top:16px; padding:30px 31px 30px 32px; background:#fff; border-radius:28px; }
        .left-title { margin:0; font-size:40px; font-weight:700; line-height:1.2; }
        form { padding:16px 14px; background:var(--panel); border-radius:16px; }
        .field { margin-bottom:16px; }
        .field-title { margin:0 0 10px; color:var(--accent); font-size:24px; font-weight:700; line-height:.98; }
        input[type=text], input[type=tel], input[type=email], textarea { width:100%; border:0; outline:none; border-radius:8px; background:#fff; padding:0 16px; color:#333; font-size:16px; }
        input[type=text], input[type=tel], input[type=email] { height:48px; }
        textarea { min-height:146px; padding-top:14px; resize:vertical; }
        input::placeholder { color:#b6b6b6; text-transform:uppercase; }
        .option { display:flex; align-items:center; gap:8px; margin:6px 0; font-size:14px; font-weight:700; line-height:1.1; cursor:pointer; }
        .option input { width:18px; height:18px; margin:0; accent-color:var(--accent); }
        .submit { width:100%; height:48px; margin-top:18px; border:0; border-radius:24px; background:var(--accent); color:#fff; font-size:14px; font-weight:700; cursor:pointer; }
        .submit:disabled { opacity:.7; cursor:wait; }
        .modal { position:fixed; inset:0; display:none; align-items:center; justify-content:center; padding:20px; background:rgba(0,0,0,.45); z-index:10; }
        .modal.is-open { display:flex; }
        .modal-card { width:min(460px,100%); padding:34px; border-radius:22px; background:#fff; text-align:center; box-shadow:0 20px 70px rgba(0,0,0,.24); }
        .modal-card h2 { margin:0 0 12px; color:var(--accent); font-size:28px; }
        .modal-card p { margin:0 0 24px; font-size:17px; line-height:1.45; }
        .modal-card button { border:0; border-radius:22px; background:var(--accent); color:#fff; padding:13px 28px; font-weight:700; cursor:pointer; }
        @media (max-width:900px) { .layout { grid-template-columns:1fr; padding:24px 16px; } .hero h1 { font-size:36px; } .left-title { font-size:32px; } }
    </style>
</head>
<body>
<div class="page">
    <header class="hero">
        <div class="brand" aria-label="Adaptogenzz clinic">✦ Adaptogenzz clinic ✦</div>
        <h1>Анкета здоровья</h1>
    </header>

    <main class="layout">
        <aside><p class="left-title">Форма здоровья</p></aside>
        <form id="healthForm" method="post" novalidate>
            <?php foreach ($textFields as [$name, $label, $placeholder, $type, $required]): ?>
                <div class="field">
                    <p class="field-title"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></p>
                    <input type="<?= $type ?>" name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') ?>" <?= $required ? 'required' : '' ?>>
                </div>
            <?php endforeach; ?>

            <?php foreach ($fields as [$type, $name, $label, $options, $required]): ?>
                <div class="field">
                    <p class="field-title"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></p>
                    <?php if ($type === 'textarea'): ?>
                        <textarea name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"></textarea>
                    <?php else: ?>
                        <?php foreach ($options as $option): ?>
                            <label class="option">
                                <input type="<?= $type ?>" name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?><?= $type === 'checkbox' ? '[]' : '' ?>" value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>" <?= $required ? 'required' : '' ?>>
                                <span><?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?></span>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <button class="submit" type="submit">Отправить</button>
        </form>
    </main>
</div>

<div class="modal" id="resultModal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal-card">
        <h2 id="modalTitle">Успешно отправлено</h2>
        <p id="modalText">Анкета успешно отправлена. Спасибо!</p>
        <button type="button" id="modalClose">Закрыть</button>
    </div>
</div>

<script>
    const form = document.getElementById('healthForm');
    const modal = document.getElementById('resultModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalText = document.getElementById('modalText');
    const modalClose = document.getElementById('modalClose');
    const submitButton = form.querySelector('.submit');

    function showModal(title, text) {
        modalTitle.textContent = title;
        modalText.textContent = text;
        modal.classList.add('is-open');
    }

    modalClose.addEventListener('click', () => modal.classList.remove('is-open'));
    modal.addEventListener('click', event => {
        if (event.target === modal) modal.classList.remove('is-open');
    });

    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (!form.reportValidity()) return;

        submitButton.disabled = true;
        submitButton.textContent = 'Отправляем...';

        try {
            const response = await fetch(window.location.href, { method: 'POST', body: new FormData(form) });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || 'Ошибка отправки формы.');
            form.reset();
            showModal('Успешно отправлено', result.message || 'Анкета успешно отправлена. Спасибо!');
        } catch (error) {
            showModal('Не удалось отправить', error.message || 'Попробуйте позже.');
        } finally {
            submitButton.disabled = false;
            submitButton.textContent = 'Отправить';
        }
    });
</script>
</body>
</html>
