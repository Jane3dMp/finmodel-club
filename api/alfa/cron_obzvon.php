<?php
// Списки на обзвон: воскресный прогон. Запуск — ВОСКРЕСЕНЬЕ в 21:00.
//   • CLI:  php /путь/к/api/alfa/cron_obzvon.php
//   • URL:  wget -qO- "https://app.proznanie.club/finmodel/api/alfa/cron_obzvon.php?key=СЕКРЕТ"
//
// Что делает: обходит всех детей из последней росписи (её кладёт модель — кто новый набор,
// кто прошлогодний) и заново читает из Alfa их занятия за сезон, абонементы и карточки.
// Складывает СЫРОЙ снимок. Разбор на корзины («не дошёл», «не пришёл на курс») делает модель
// при печати — правило «пришёл / не пришёл» должно жить в одном месте, иначе оно разъедется,
// а цена расхождения тут прямая: человеку зря звонят.
//
// Зачем ночью, а не по кнопке: это запрос в Alfa на каждого ребёнка, на весь клуб — минуты.
// Утром в понедельник Жанна открывает «Дашборд Администратора → Списки на обзвон», и списки
// уже готовы — остаётся распечатать.
//
// ⚠️ Если модель ни разу не открывала раздел, росписи нет и прогон честно отвечает об этом:
//    сервер сам не знает, кто новый, а кто прошлогодний, — эта разбивка живёт в таблице модели.
declare(strict_types=1);
require __DIR__ . '/lib.php';

if (php_sapi_name() !== 'cli') {
    $want = alfa_cron_key();
    if ($want === '' || !hash_equals($want, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'forbidden (нет/неверный key; задайте cron_key в config.php)']);
        exit;
    }
}

$branches = alfa_realization_branches();
if (!$branches) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'филиал «Пожарный» не найден в Alfa (config realization_branch_names)']);
    exit;
}

@set_time_limit(0);
// force по умолчанию: часовой кэш занятий для воскресного прогона бесполезен — нужны свежие
// данные на конец недели. ?force=0 — взять из кэша (для отладки, чтобы не мучить Alfa).
$force = !isset($_GET['force']) || $_GET['force'] !== '0';
$r = alfa_obzvon_run($branches, $force);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ranAt' => date('c'), 'branches' => $branches, 'force' => $force] + $r,
                 JSON_UNESCAPED_UNICODE);
