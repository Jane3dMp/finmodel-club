<?php
// Заморозка «ожидалось» (expect) в дневном хранилище реализации.
// Запуск: php backend/test-expect-freeze.php
//
// Главное, что проверяется: единожды записанное «ожидалось» не затирается НИЧЕМ —
// ни ежедневным пересчётом из Alfa, ни повторной заморозкой. Затрётся — сравнивать
// реализацию будет не с чем: по мере проведения занятий planned падает в ноль.
declare(strict_types=1);

$bad = 0;
function ok(string $name, $got, $want): void {
    global $bad;
    $good = is_float($want) || is_float($got) ? abs((float)$got - (float)$want) < 0.01 : $got === $want;
    if (!$good) $bad++;
    echo ($good ? "✓ " : "✗ ") . $name . ' = ' . json_encode($got, JSON_UNESCAPED_UNICODE)
       . ($good ? '' : ' ≠ ' . json_encode($want, JSON_UNESCAPED_UNICODE)) . "\n";
}
function yes(string $name, bool $cond, string $detail = ''): void {
    global $bad;
    if (!$cond) $bad++;
    echo ($cond ? "✓ " : "✗ ") . $name . ($cond || $detail === '' ? '' : ': ' . $detail) . "\n";
}

/* --- хранилище в памяти вместо файла: подменяем чтение/запись --- */
$STORE = [];
function alfa_realization_store_read(): array { global $STORE; return $STORE; }
function alfa_realization_store_write(array $d): void { global $STORE; $STORE = $d; }
function alfa_iso(string $d): string { return substr($d, 0, 10); }
function alfa_monday_of(string $iso): string {
    $ts = strtotime(alfa_iso($iso));
    $wd = (int)date('N', $ts);
    return date('Y-m-d', strtotime('-' . ($wd - 1) . ' day', $ts));
}
/* Alfa отвечает по дню: сколько ожидается (planned) и сколько уже реализовано.
   $DAY управляется тестом — так имитируется «занятие прошло, planned упал в ноль». */
$DAY = [];
function alfa_realization_day(string $date, ?array $b = null): array {
    global $DAY;
    $d = $DAY[$date] ?? ['present' => 0, 'all' => 0, 'planned' => 0, 'lessons' => 0, 'plannedLessons' => 0];
    return ['date' => $date, 'realizationPresent' => $d['present'], 'realizationAll' => $d['all'],
            'realizationPlanned' => $d['planned'], 'lessons' => $d['lessons'],
            'plannedLessons' => $d['plannedLessons'], 'byTeacher' => []];
}

/* --- вырезаем НАСТОЯЩИЕ функции из lib.php --- */
$lib = file_get_contents(__DIR__ . '/../api/alfa/lib.php');
$src = '';
foreach (['alfa_realization_upsert', 'alfa_expect_freeze'] as $fn) {
    if (!preg_match('/\nfunction ' . $fn . '\(.*?\n\}/s', $lib, $m)) { echo "не найдено в lib.php: $fn\n"; exit(1); }
    $src .= $m[0] . "\n";
}
eval($src);

/* --- неделя 07–13.09.2026 (пн–вс): по расписанию ждём деньги в пн, ср, пт --- */
$MON = '2026-09-07';
$DAY = [
    '2026-09-07' => ['present' => 0, 'all' => 0, 'planned' => 2364.0, 'lessons' => 0, 'plannedLessons' => 11],
    '2026-09-08' => ['present' => 0, 'all' => 0, 'planned' => 0.0,    'lessons' => 0, 'plannedLessons' => 0],
    '2026-09-09' => ['present' => 0, 'all' => 0, 'planned' => 8773.0, 'lessons' => 0, 'plannedLessons' => 36],
    '2026-09-10' => ['present' => 0, 'all' => 0, 'planned' => 0.0,    'lessons' => 0, 'plannedLessons' => 0],
    '2026-09-11' => ['present' => 0, 'all' => 0, 'planned' => 7389.0, 'lessons' => 0, 'plannedLessons' => 29],
    '2026-09-12' => ['present' => 0, 'all' => 0, 'planned' => 0.0,    'lessons' => 0, 'plannedLessons' => 0],
    '2026-09-13' => ['present' => 0, 'all' => 0, 'planned' => 0.0,    'lessons' => 0, 'plannedLessons' => 0],
];
// воскресный cron: сначала пересчитал дни недели, потом заморозил ожидание
for ($i = 0; $i < 7; $i++) alfa_realization_upsert(date('Y-m-d', strtotime("+$i day", strtotime($MON))));
$f = alfa_expect_freeze($MON);

ok('заморожено дней', $f['frozen'], 7);
ok('ожидание за неделю', $f['total'], 2364.0 + 8773.0 + 7389.0);
ok('понедельник', $STORE['2026-09-07']['expect'], 2364.0);
yes('проставлена отметка времени заморозки', !empty($STORE['2026-09-07']['expectTs']));
ok('выходной тоже зафиксирован нулём', $STORE['2026-09-13']['expect'], 0.0);

/* === ГЛАВНОЕ: занятия прошли, planned упал в ноль — expect обязан остаться === */
$DAY['2026-09-07'] = ['present' => 1783.0, 'all' => 1805.0, 'planned' => 0.0, 'lessons' => 12, 'plannedLessons' => 0];
$DAY['2026-09-09'] = ['present' => 7658.0, 'all' => 8010.0, 'planned' => 0.0, 'lessons' => 36, 'plannedLessons' => 0];
alfa_realization_upsert('2026-09-07');
alfa_realization_upsert('2026-09-09');

ok('после проведения занятий planned обнулился', (float)$STORE['2026-09-07']['planned'], 0.0);
ok('а «ожидалось» осталось', (float)$STORE['2026-09-07']['expect'], 2364.0);
ok('и у среды тоже', (float)$STORE['2026-09-09']['expect'], 8773.0);
ok('факт записался', (float)$STORE['2026-09-07']['present'], 1783.0);
yes('отметка времени заморозки пережила пересчёт', !empty($STORE['2026-09-07']['expectTs']));

// процент выполнения — то, ради чего всё затевалось
$fact = ((float)$STORE['2026-09-07']['present'] + (float)$STORE['2026-09-07']['all']) / 2;
ok('реализация от ожидания, %', round(100 * $fact / (float)$STORE['2026-09-07']['expect']), 76.0);

/* --- повторная заморозка ничего не портит --- */
$again = alfa_expect_freeze($MON);
ok('второй раз не перезаписывает', $again['frozen'], 0);
ok('все семь дней остались как были', $again['kept'], 7);
ok('понедельник не тронут', (float)$STORE['2026-09-07']['expect'], 2364.0);

/* --- force: расписание переделали ДО начала недели, ожидание пересобираем осознанно.
   ⚠️ Но даже force не смеет обнулить УЖЕ ПРОШЕДШИЙ день: у него planned давно ноль, и запись
   «ожидали ноль» испортила бы и день, и месячный %. Раньше здесь ждали frozen=7 и понедельник=0
   — это и был баг: любая попытка дозаморозить текущую неделю стирала ожидание у прошедших дней. */
$forced = alfa_expect_freeze($MON, null, true);
ok('force трогает только дни, которые ещё не прошли', $forced['frozen'], 5);
ok('прошедшие пропущены', $forced['late'], 2);
ok('понедельник НЕ обнулён', (float)$STORE['2026-09-07']['expect'], 2364.0);
ok('среда тоже цела', (float)$STORE['2026-09-09']['expect'], 8773.0);
ok('а вторник (занятий не было) спокойно переписан нулём', (float)$STORE['2026-09-08']['expect'], 0.0);

/* --- главный сценарий из жизни: неделю не заморозили вовремя, часть дней уже прошла.
   Нажимаем «Зафиксировать» в субботу — будущие дни обязаны замёрзнуть, прошедшие остаться пустыми. --- */
$KEEP_STORE = $STORE; $KEEP_DAY = $DAY;
$STORE = []; $MON2 = '2026-08-31';
$DAY = [
    '2026-08-31' => ['present' => 0,      'all' => 0,      'planned' => 0.0,    'lessons' => 0,  'plannedLessons' => 0],
    '2026-09-01' => ['present' => 1783.0, 'all' => 1805.0, 'planned' => 0.0,    'lessons' => 12, 'plannedLessons' => 0],
    '2026-09-02' => ['present' => 1658.0, 'all' => 1697.0, 'planned' => 0.0,    'lessons' => 12, 'plannedLessons' => 0],
    '2026-09-03' => ['present' => 1620.0, 'all' => 1666.0, 'planned' => 0.0,    'lessons' => 11, 'plannedLessons' => 0],
    '2026-09-04' => ['present' => 0,      'all' => 0,      'planned' => 0.0,    'lessons' => 0,  'plannedLessons' => 0],
    '2026-09-05' => ['present' => 0,      'all' => 0,      'planned' => 8477.0, 'lessons' => 0,  'plannedLessons' => 34],
    '2026-09-06' => ['present' => 0,      'all' => 0,      'planned' => 7490.0, 'lessons' => 0,  'plannedLessons' => 30],
];
for ($i = 0; $i < 7; $i++) alfa_realization_upsert(date('Y-m-d', strtotime("+$i day", strtotime($MON2))));
$late = alfa_expect_freeze($MON2);
ok('заморожены только не прошедшие дни', $late['frozen'], 4);
ok('прошедшие с занятиями пропущены', $late['late'], 3);
ok('суббота спасена', (float)$STORE['2026-09-05']['expect'], 8477.0);
ok('воскресенье спасено', (float)$STORE['2026-09-06']['expect'], 7490.0);
yes('вторник НЕ получил лживый ноль', !isset($STORE['2026-09-01']['expect']));
yes('среда НЕ получила лживый ноль', !isset($STORE['2026-09-02']['expect']));
ok('в итог недели попало только спасённое', $late['total'], 8477.0 + 7490.0);
$STORE = $KEEP_STORE; $DAY = $KEEP_DAY;

/* --- дня нет в хранилище: заморозка его не выдумывает --- */
$STORE2 = $STORE;
unset($STORE['2026-09-08']);
$f2 = alfa_expect_freeze($MON);
yes('пустой день не появился из ниоткуда', !isset($STORE['2026-09-08']));
ok('он отмечен как «данных нет»', $f2['days']['2026-09-08'], null);
$STORE = $STORE2;

/* --- неделя определяется по любому дню внутри неё --- */
$w = alfa_expect_freeze('2026-09-11');           // пятница
ok('пятница указывает на свой понедельник', $w['week'], $MON);

echo $bad ? "\n❌ провалено проверок: $bad\n" : "\n✅ всё сошлось\n";
exit($bad ? 1 : 0);
