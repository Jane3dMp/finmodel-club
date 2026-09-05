<?php
// Подбор нумерации дня недели в Alfa для восстановления «ожидалось» за прошедшую неделю.
// Запуск: php backend/test-expect-rebuild.php
//
// Почему это важно: поле day у regular-lesson не документировано (PUBLISH_GROUPS.md).
// Ошибка в нумерации сдвинет ожидание по дням — числа останутся правдоподобными, но неверными,
// а это деньги. Поэтому режим ПОДБИРАЕТСЯ по эталонной неделе, а неоднозначность отвергается.
declare(strict_types=1);

$bad = 0;
function ok(string $name, $got, $want): void {
    global $bad;
    $good = $got === $want;
    if (!$good) $bad++;
    echo ($good ? "✓ " : "✗ ") . $name . ' = ' . json_encode($got, JSON_UNESCAPED_UNICODE)
       . ($good ? '' : ' ≠ ' . json_encode($want, JSON_UNESCAPED_UNICODE)) . "\n";
}
function yes(string $name, bool $cond, string $detail = ''): void {
    global $bad;
    if (!$cond) $bad++;
    echo ($cond ? "✓ " : "✗ ") . $name . ($cond || $detail === '' ? '' : ': ' . $detail) . "\n";
}

function alfa_iso(string $d): string { return substr($d, 0, 10); }

/* --- вырезаем НАСТОЯЩИЕ функции из lib.php --- */
$lib = file_get_contents(__DIR__ . '/../api/alfa/lib.php');
if (!preg_match("/\nconst ALFA_DAY_MODES = \[[^\]]*\];/", $lib, $c)) { echo "не найдено: ALFA_DAY_MODES\n"; exit(1); }
$src = $c[0] . "\n";
foreach (['alfa_day_to_iso', 'alfa_regular_by_iso_dow', 'alfa_regular_days_of_week',
          'alfa_day_mode_pick', 'alfa_day_mode_verdict'] as $fn) {
    if (!preg_match('/\nfunction ' . $fn . '\(.*?\n\}/s', $lib, $m)) { echo "не найдено в lib.php: $fn\n"; exit(1); }
    $src .= $m[0] . "\n";
}
eval($src);

/* --- 1. чистое отображение day → ISO --- */
ok('iso: 1 → понедельник', alfa_day_to_iso(1, 'iso'), 1);
ok('iso: 7 → воскресенье', alfa_day_to_iso(7, 'iso'), 7);
ok('iso: 0 вне диапазона', alfa_day_to_iso(0, 'iso'), null);
ok('zero: 0 → понедельник', alfa_day_to_iso(0, 'zero'), 1);
ok('zero: 6 → воскресенье', alfa_day_to_iso(6, 'zero'), 7);
ok('zero: 7 вне диапазона', alfa_day_to_iso(7, 'zero'), null);
ok('sun1: 1 → воскресенье', alfa_day_to_iso(1, 'sun1'), 7);
ok('sun1: 2 → понедельник', alfa_day_to_iso(2, 'sun1'), 1);
ok('sun1: 7 → суббота', alfa_day_to_iso(7, 'sun1'), 6);
ok('sun1: 8 вне диапазона', alfa_day_to_iso(8, 'sun1'), null);

/* --- 2. подбор режима по эталонной неделе.
   Расписание задано в нумерации ISO: пн 2 группы, ср 3, пт 1. --- */
$MON = '2026-09-07';   // понедельник
$rl = [];
$mk = function (int $day, int $gid) { return ['related_id' => $gid, 'day' => $day, 'subject_id' => 1,
                                              'b_date_v' => '2026-09-01', 'e_date_v' => '2027-05-31']; };
foreach ([101, 102] as $g) $rl[] = $mk(1, $g);          // понедельник
foreach ([201, 202, 203] as $g) $rl[] = $mk(3, $g);     // среда
$rl[] = $mk(5, 301);                                    // пятница
// то, что Alfa реально показала по дням этой недели (plannedLessons из хранилища)
$ref = ['2026-09-07' => 2, '2026-09-08' => 0, '2026-09-09' => 3,
        '2026-09-10' => 0, '2026-09-11' => 1, '2026-09-12' => 0, '2026-09-13' => 0];

$score = alfa_day_mode_pick($rl, $MON, $ref);
$v = alfa_day_mode_verdict($score);
yes('режим определён', $v['ok'], $v['why'] ?? '');
ok('и это iso', $v['mode'] ?? null, 'iso');
ok('он сошёлся без единой невязки', $score[0]['diff'], 0);
ok('сверено семь дней', $score[0]['compared'], 7);
yes('остальные режимы дали невязку', $score[1]['diff'] > 0 && $score[2]['diff'] > 0,
    'diff: ' . $score[1]['diff'] . ' / ' . $score[2]['diff']);

/* --- 3. то же расписание, но записанное в нумерации Alfa «0=пн» --- */
$rl0 = [];
foreach ([101, 102] as $g) $rl0[] = $mk(0, $g);
foreach ([201, 202, 203] as $g) $rl0[] = $mk(2, $g);
$rl0[] = $mk(4, 301);
$v0 = alfa_day_mode_verdict(alfa_day_mode_pick($rl0, $MON, $ref));
yes('нумерация 0=пн тоже распознаётся', $v0['ok'], $v0['why'] ?? '');
ok('и это zero', $v0['mode'] ?? null, 'zero');

/* --- 4. нумерация «1=вс» --- */
$rls = [];
foreach ([101, 102] as $g) $rls[] = $mk(2, $g);   // пн
foreach ([201, 202, 203] as $g) $rls[] = $mk(4, $g);   // ср
$rls[] = $mk(6, 301);                                   // пт
$vs = alfa_day_mode_verdict(alfa_day_mode_pick($rls, $MON, $ref));
yes('нумерация 1=вс тоже распознаётся', $vs['ok'], $vs['why'] ?? '');
ok('и это sun1', $vs['mode'] ?? null, 'sun1');

/* --- 5. ГЛАВНОЕ: неоднозначность и несовпадение отвергаются, а не угадываются --- */
$vEmpty = alfa_day_mode_verdict(alfa_day_mode_pick([], $MON, ['2026-09-07' => 0, '2026-09-08' => 0,
    '2026-09-09' => 0, '2026-09-10' => 0, '2026-09-11' => 0, '2026-09-12' => 0, '2026-09-13' => 0]));
yes('пустая неделя не различает режимы — отказ', !$vEmpty['ok']);
ok('и причина названа', $vEmpty['why'], 'эталонная неделя не различает режимы нумерации');

$vBad = alfa_day_mode_verdict(alfa_day_mode_pick($rl, $MON,
    ['2026-09-07' => 9, '2026-09-08' => 9, '2026-09-09' => 9, '2026-09-10' => 9,
     '2026-09-11' => 9, '2026-09-12' => 9, '2026-09-13' => 9]));
yes('расписание не бьётся с фактом — отказ', !$vBad['ok']);
ok('и причина названа', $vBad['why'], 'ни один режим не сошёлся с расписанием');

$vFew = alfa_day_mode_verdict(alfa_day_mode_pick($rl, $MON, ['2026-09-07' => 2, '2026-09-09' => 3]));
yes('слишком мало дней для сверки — отказ', !$vFew['ok']);
ok('и причина названа', $vFew['why'], 'мало дней для сверки');

/* --- 6. период действия слота: группа, начавшаяся позже, в этот день ещё не шла --- */
$rlLate = [$mk(1, 101), $mk(1, 102)];
$rlLate[1]['b_date_v'] = '2026-09-14';                 // вторая группа стартует со следующей недели
$days = alfa_regular_days_of_week(alfa_regular_by_iso_dow($rlLate, 'iso'), $MON);
ok('в понедельник идёт только одна группа', count($days['2026-09-07']), 1);
ok('и это первая', $days['2026-09-07'][0]['id'], 101);
$rlGone = [$mk(1, 101)];
$rlGone[0]['e_date_v'] = '2026-09-06';                 // слот закончился до недели
$days2 = alfa_regular_days_of_week(alfa_regular_by_iso_dow($rlGone, 'iso'), $MON);
ok('завершённый слот не считается', count($days2['2026-09-07']), 0);

echo $bad ? "\n❌ провалено проверок: $bad\n" : "\n✅ всё сошлось\n";
exit($bad ? 1 : 0);
