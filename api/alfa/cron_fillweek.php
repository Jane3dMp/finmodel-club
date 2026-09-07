<?php
// Загрузка групп за неделю: досчитать дни ДО сборки отчёта продажам. Запуск — ВОСКРЕСЕНЬЕ в 20:00.
//   • CLI:  php /путь/к/api/alfa/cron_fillweek.php
//   • URL:  wget -qO- "https://app.proznanie.club/finmodel/api/alfa/cron_fillweek.php?key=СЕКРЕТ"
//
// ЗАЧЕМ ОТДЕЛЬНАЯ ЗАДАЧА, если детоместа и так пишутся ночным пересчётом реализации.
// Ночной cron идёт в 22:00 — то есть ПОСЛЕ отчёта отдела продаж, который снимается тем же
// воскресеньем в 22:00. Значит в момент сборки отчёта воскресенье ещё не посчитано, и сводка
// загрузки в сообщении была бы неполной: неделя без последнего дня. Этот запуск в 20:00 добивает
// дни текущей недели, и к 22:00 отчёт видит её целиком.
//
// Задача идемпотентна: alfa_realization_upsert можно звать сколько угодно, он перезаписывает день
// свежими цифрами. Поэтому её не страшно запустить руками или повторить после сбоя.
//
// ?week=YYYY-MM-DD — досчитать конкретную неделю (любая дата внутри неё).
// ?days=N          — ограничить число дней (по умолчанию все дни недели до сегодняшнего).
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
$weekParam = (string)($_GET['week'] ?? ($argv[1] ?? ''));
$mon   = alfa_monday_of($weekParam !== '' ? $weekParam : date('Y-m-d'));
$today = date('Y-m-d');
$limit = (int)($_GET['days'] ?? ($argv[2] ?? 7));
$limit = max(1, min(7, $limit));

/* Будущие дни не считаем: занятий по ним ещё не было, и запись пустого дня выглядела бы как
   «читали, никого не было» — а это разные вещи. */
$done = []; $skipped = [];
for ($i = 0; $i < $limit; $i++) {
    $d = date('Y-m-d', strtotime("+$i day", strtotime($mon)));
    if ($d > $today) { $skipped[] = $d; continue; }
    $r = alfa_realization_upsert($d, $branches);
    $done[$d] = ['lessons' => (int)($r['lessons'] ?? 0)];
}

/* Сводка за неделю — тем же кодом, что читает раздел «Заполняемость»: числа в ответе cron и на
   экране обязаны совпадать, иначе разбираться в расхождении будет негде. */
$sun  = date('Y-m-d', strtotime('+6 day', strtotime($mon)));
$kids = alfa_seatkids_range($mon, $sun);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true, 'ranAt' => date('c'), 'week' => $mon, 'to' => $sun,
    'branches' => $branches,
    'daysDone' => $done, 'daysSkipped' => $skipped,
    'kidsPaid' => count($kids['paid'] ?? []), 'kidsFree' => count($kids['free'] ?? []),
], JSON_UNESCAPED_UNICODE);
