<?php
/* Проверка расчётной части «Отчёта для отдела продаж» (api/alfa/lib.php).
   Alfa здесь не дёргается: проверяем чистые функции на выдуманном хранилище дней.
   Запуск:  php backend/test-salesreport.php                                        */
declare(strict_types=1);
require __DIR__ . '/../api/alfa/lib.php';

$fails = 0; $checks = 0;
function ok(string $what, $got, $want, float $eps = 0.01): void {
    global $fails, $checks; $checks++;
    $good = is_string($want) ? ($got === $want) : (abs((float)$got - (float)$want) <= $eps);
    if (!$good) { $fails++; echo "❌ $what: получили " . json_encode($got, JSON_UNESCAPED_UNICODE)
                             . ", ждали " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n"; }
    else echo "✓ $what = " . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n";
}

/* --- в каком воскресенье закрывается месяц --- */
ok('сентябрь 2025 закрывается в вс', alfa_sales_month_sunday('2025-09'), '2025-10-05');   // 30.09 — вторник
ok('октябрь 2025 закрывается в вс',  alfa_sales_month_sunday('2025-10'), '2025-11-02');   // 31.10 — пятница
ok('август 2025 (31.08 — вс)',       alfa_sales_month_sunday('2025-08'), '2025-08-31');   // сам последний день

/* --- хранилище: две прошедшие недели факта + будущая неделя с «ожидается» --- */
$today = '2025-10-12';                       // воскресенье, момент запуска отчёта
$store = [];
// пн–пт по 4000 (present 3800 / all 4200 → среднее 4000), сб 2000, вс пусто
$profile = [1 => 4000, 2 => 4000, 3 => 4000, 4 => 4000, 5 => 4000, 6 => 2000, 7 => 0];
foreach (['2025-09-29', '2025-10-06'] as $mon) {
    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime("+$i day", strtotime($mon)));
        $wd = (int)date('N', strtotime($d));
        $v = $profile[$wd];
        if (!$v) continue;
        $store[$d] = ['present' => $v - 200, 'all' => $v + 200, 'planned' => 0, 'lessons' => 10];
    }
}
// будущая неделя 13–19.10: Alfa уже создала занятия, «ожидается» на 10% выше факта
for ($i = 0; $i < 7; $i++) {
    $d = date('Y-m-d', strtotime("+$i day", strtotime('2025-10-13')));
    $wd = (int)date('N', strtotime($d));
    if (!$profile[$wd]) continue;
    $store[$d] = ['present' => 0, 'all' => 0, 'planned' => $profile[$wd] * 1.1, 'lessons' => 0];
}

/* --- доход дня: прошедший = среднее без/с пропусками, будущий = ожидается --- */
ok('день прошедший (факт)', alfa_sales_day_val($store, '2025-10-06', $today), 4000);
ok('день будущий (прогноз)', alfa_sales_day_val($store, '2025-10-14', $today), 4400);
ok('день без данных', alfa_sales_day_val($store, '2025-10-12', $today), 0);

/* --- факт недели 06–12.10: 5 будних × 4000 + суббота 2000 --- */
$wf = 0.0;
for ($i = 0; $i < 7; $i++) $wf += alfa_sales_day_val($store, date('Y-m-d', strtotime("+$i day", strtotime('2025-10-06'))), $today);
ok('оборот недели 06–12.10', $wf, 22000);

/* --- профиль дня недели: берётся из «ожидается» будущей недели --- */
$prof = alfa_sales_weekday_profile($store, '2025-10-13', $today);
ok('профиль: понедельник', $prof[1], 4400);
ok('профиль: суббота',     $prof[6], 2200);
ok('профиль: воскресенье (нет занятий)', $prof[7], 0);
ok('сумма профиля за неделю', array_sum($prof), 5 * 4400 + 2200);

/* Профиль подгоняется под недельный прогноз из «Прогноза по всем» — иначе неделя и месяц
   считались бы по-разному. Прогноз недели 24 200 → профиль ужимается вдвое. */
$scaled = alfa_sales_weekday_profile($store, '2025-10-13', $today, 12100.0);
ok('масштаб под недельный прогноз: сумма', array_sum($scaled), 12100);
ok('масштаб: понедельник вдвое меньше', $scaled[1], 2200);
ok('без недельного прогноза профиль не трогаем', array_sum(alfa_sales_weekday_profile($store, '2025-10-13', $today, 0.0)), 24200);

/* --- прогноз на ноябрь: занятий в Alfa ещё нет, значит весь месяц из профиля.
       В ноябре 2025: 20 будних дней и 5 суббот → 20×4400 + 5×2200 = 99 000 --- */
$nf = alfa_sales_month_forecast('2025-11', $store, $prof, $today);
ok('прогноз на ноябрь', $nf['sum'], 99000);
ok('учебных дней в ноябре', $nf['studyDays'], 25);
ok('дней из профиля', $nf['fromProfile'], 25);

/* --- прогноз на октябрь (месяц идёт): прошедшие дни фактом, будущие — ожидаемым --- */
$of = alfa_sales_month_forecast('2025-10', $store, $prof, $today);
// факт 1–12.10 = 8 будних × 4000 + 2 сб × 2000 = 36 000
// ожидается 13–19.10 из хранилища = 5×4400 + 2200 = 24 200
// 20–31.10 из профиля = 10 будних × 4400 + 1 сб × 2200 = 46 200
ok('прогноз на октябрь (факт + ожидается + профиль)', $of['sum'], 36000 + 24200 + 46200);
ok('октябрь: дней фактом',   $of['fromFact'], 10);
ok('октябрь: дней из Alfa',  $of['fromPlanned'], 6);
ok('октябрь: дней профилем', $of['fromProfile'], 11);

/* --- факт месяца --- */
$mf = alfa_sales_month_fact('2025-10', $store, $today);
ok('факт октября на 12.10', $mf['sum'], 36000);
ok('дней с занятиями', $mf['daysWithLessons'], 10);

/* --- медиана «факт ÷ прогноз» --- */
$reports = [
    '2025-09-01' => ['fact' => 9000,  'prevForecast' => 10000],   // 0.90
    '2025-09-08' => ['fact' => 8000,  'prevForecast' => 10000],   // 0.80
    '2025-09-15' => ['fact' => 10000, 'prevForecast' => 10000],   // 1.00
];
ok('медиана факт/прогноз', alfa_sales_ratio($reports), 0.9);
ok('без истории — 0.9', alfa_sales_ratio([]), 0.9);
ok('аномалия не пускается ниже 0.5', alfa_sales_ratio(['a' => ['fact' => 1, 'prevForecast' => 100]]), 0.5);

echo "\n" . ($fails ? "❌ провалено $fails из $checks" : "✅ все $checks проверок прошли") . "\n";
exit($fails ? 1 : 0);
