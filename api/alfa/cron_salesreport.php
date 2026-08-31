<?php
// Отчёт для отдела продаж: заморозка цифр недели. Запуск — ВОСКРЕСЕНЬЕ в 22:00.
//   • CLI:  php /путь/к/api/alfa/cron_salesreport.php
//   • URL:  wget -qO- "https://app.proznanie.club/finmodel/api/alfa/cron_salesreport.php?key=СЕКРЕТ"
//
// Что делает (в этом порядке):
//   1. пересчитывает из Alfa все 7 дней закончившейся недели — это оборот недели (факт);
//   2. фиксирует прогноз на следующую неделю (снимок, если его ещё нет);
//   3. считает активных клиентов в клубных филиалах — «на сейчас», иначе цифру не восстановить;
//   4. если этим воскресеньем закрылся месяц — добавляет месячный блок (оборот месяца
//      и прогноз на следующий месяц с учётом числа учебных дней в нём);
//   5. кладёт всё в хранилище — раздел «Отчёт для отдела продаж» в Дашборде руководителя
//      собирает из этого готовый текст сообщения (рейтинг педагогов считается уже там,
//      по справочнику ставок финмодели).
//
// ?week=YYYY-MM-DD — пересобрать отчёт за конкретную неделю (любая дата внутри неё).
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
$week = (string)($_GET['week'] ?? ($argv[1] ?? date('Y-m-d')));
$rep  = alfa_sales_build($week, $branches, true);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'ranAt' => date('c'), 'branches' => $branches, 'report' => $rep],
                 JSON_UNESCAPED_UNICODE);
