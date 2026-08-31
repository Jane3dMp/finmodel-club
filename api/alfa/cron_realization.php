<?php
// Ежедневный пересчёт реализации (клуб = «Пожарный») и запись в хранилище.
// Запуск по расписанию на HostFly: ЕЖЕДНЕВНО в 22:00 (доход за день + прогноз вперёд):
//   • CLI:  php /путь/к/api/alfa/cron_realization.php 7
//   • URL:  wget -qO- "https://app.proznanie.club/finmodel/api/alfa/cron_realization.php?key=СЕКРЕТ&days=7"
// days = катящееся окно: пересчитываем последние N дней (посещаемость правят задним числом).
declare(strict_types=1);
require __DIR__ . '/lib.php';

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    // из веба — только по секретному ключу (config['cron_key']), иначе любой мог бы дёргать
    $key = (string)($_GET['key'] ?? '');
    $want = alfa_cron_key();
    if ($want === '' || !hash_equals($want, $key)) {
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
$days  = (int)($_GET['days']  ?? ($argv[1] ?? 7));    // сколько дней НАЗАД пересчитать (факт)
$ahead = (int)($_GET['ahead'] ?? ($argv[2] ?? 14));   // минимум дней ВПЕРЁД (прогноз по расписанию)
$days  = max(1, min(60, $days));
$ahead = max(0, min(90, $ahead));

$today = date('Y-m-d');
$dates = [];
for ($i = 0; $i < $days; $i++)  $dates[date('Y-m-d', strtotime("-$i day"))] = 1;   // назад: факт
for ($i = 1; $i <= $ahead; $i++) $dates[date('Y-m-d', strtotime("+$i day"))] = 1;  // вперёд: прогноз
/* ⚠️ Обновляем и ВСЕ будущие дни, которые уже есть в хранилище: расписание меняется
   (занятия отменяют, детей дописывают), иначе однажды подтянутый день «зависнет»
   со старой суммой и прогноз будет врать. Ограничиваем горизонтом +90 дней. */
$horizon = date('Y-m-d', strtotime('+90 day'));
foreach (array_keys(alfa_realization_store_read()) as $d) {
    if ($d > $today && $d <= $horizon) $dates[$d] = 1;
}
$dates = array_keys($dates);
sort($dates);

$done = [];
foreach ($dates as $d) {
    $r = alfa_realization_upsert($d, $branches);
    $done[$d] = ['present' => $r['present'], 'all' => $r['all'], 'planned' => $r['planned'], 'lessons' => $r['lessons']];
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'ranAt' => date('c'), 'branches' => $branches,
                  'back' => $days, 'ahead' => $ahead, 'recalculated' => count($done), 'days' => $done], JSON_UNESCAPED_UNICODE);
