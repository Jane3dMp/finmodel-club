<?php
// Ежедневный пересчёт реализации (клуб = «Пожарный») и запись в хранилище.
// Запуск по расписанию на HostFly (напр. в 20:00):
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

$days = (int)($_GET['days'] ?? ($argv[1] ?? 7));
$days = max(1, min(60, $days));
$done = [];
for ($i = 0; $i < $days; $i++) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $r = alfa_realization_upsert($d, $branches);
    $done[$d] = ['present' => $r['present'], 'all' => $r['all'], 'lessons' => $r['lessons']];
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'ranAt' => date('c'), 'branches' => $branches, 'days' => $done], JSON_UNESCAPED_UNICODE);
