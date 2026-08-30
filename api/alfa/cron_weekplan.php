<?php
// Фиксация прогноза на НОВУЮ неделю. Запуск: воскресенье в 23:00.
//   wget -qO- "https://app.proznanie.club/finmodel/api/alfa/cron_weekplan.php?key=СЕКРЕТ"
// Зачем снимок: если прогноз считать «на лету», то по мере проведения занятий ожидаемое
// превращается в факт и прогноз сравнивается сам с собой (% выполнения всегда 100).
// Поэтому в конце воскресенья фиксируем ожидаемое на грядущую неделю и потом сравниваем.
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
    echo json_encode(['ok' => false, 'error' => 'филиал «Пожарный» не найден в Alfa']);
    exit;
}

/* Какую неделю фиксируем: по умолчанию БЛИЖАЙШУЮ БУДУЩУЮ (в вс 23:00 это завтрашний
   понедельник). Можно передать ?week=YYYY-MM-DD, чтобы зафиксировать конкретную. */
$weekParam = (string)($_GET['week'] ?? ($argv[1] ?? ''));
$target = $weekParam !== '' ? $weekParam : date('Y-m-d', strtotime('monday this week', strtotime('+1 day')));

$snap = alfa_weekplan_snapshot($target, $branches);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'ranAt' => date('c'), 'branches' => $branches, 'snapshot' => $snap], JSON_UNESCAPED_UNICODE);
