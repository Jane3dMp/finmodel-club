<?php
// Статистика пробных: сколько должно было состояться и сколько не дошло.
// Запуск: php backend/test-trials.php
//
// Правило: у пробного ребёнка абонемент «пробное». Списали — дошёл; закрыли нулём при
// действующем пробном — не дошёл. Считаем по деньгам, а не по отметке присутствия.
declare(strict_types=1);

$bad = 0;
function ok(string $name, $got, $want): void {
    global $bad; $good = $got === $want; if (!$good) $bad++;
    echo ($good ? "✓ " : "✗ ") . $name . ' = ' . json_encode($got, JSON_UNESCAPED_UNICODE)
       . ($good ? '' : ' ≠ ' . json_encode($want, JSON_UNESCAPED_UNICODE)) . "\n";
}
function yes(string $name, bool $c, string $d = ''): void {
    global $bad; if (!$c) $bad++;
    echo ($c ? "✓ " : "✗ ") . $name . ($c || $d === '' ? '' : ': ' . $d) . "\n";
}
function alfa_iso(string $d): string { return substr($d, 0, 10); }

$lib = file_get_contents(__DIR__ . '/../api/alfa/lib.php');
$src = '';
foreach (['alfa_is_trial_name', 'alfa_detail_customer_id', 'alfa_trial_count_details'] as $fn) {
    if (!preg_match('/\nfunction ' . $fn . '\(.*?\n\}/s', $lib, $m)) { echo "не найдено в lib.php: $fn\n"; exit(1); }
    $src .= $m[0] . "\n";
}
eval($src);

/* --- 1. распознавание пробного по названию --- */
yes('«Пробное занятие» — пробное', alfa_is_trial_name('Пробное занятие'));
yes('«пробный урок» — пробное', alfa_is_trial_name('пробный урок'));
yes('«ПРОБНОЕ» капсом — пробное', alfa_is_trial_name('ПРОБНОЕ'));
yes('«Абонемент 8 занятий» — НЕ пробное', !alfa_is_trial_name('Абонемент 8 занятий'));
yes('«Разовое посещение» — НЕ пробное', !alfa_is_trial_name('Разовое посещение'));
yes('пустое имя — НЕ пробное', !alfa_is_trial_name(''));

/* --- 2. id ребёнка из строки участника (имя поля в Alfa не документировано) --- */
ok('customer_id', alfa_detail_customer_id(['customer_id' => 77]), 77);
ok('client_id как запасной вариант', alfa_detail_customer_id(['client_id' => 88]), 88);
ok('ноль, если поля нет вовсе', alfa_detail_customer_id(['commission' => 15]), 0);
ok('ноль не принимаем за id', alfa_detail_customer_id(['customer_id' => 0]), 0);

/* --- 3. ГЛАВНОЕ: кто дошёл, кто нет --- */
$D = '2026-09-03';
$trial = [
    101 => ['from' => '2026-09-01', 'to' => '2026-09-30', 'tariff' => 5],   // пробное действует
    102 => ['from' => '2026-09-01', 'to' => '2026-09-30', 'tariff' => 5],
    103 => ['from' => '2025-09-01', 'to' => '2025-09-30', 'tariff' => 5],   // прошлогоднее
];
$details = [
    ['customer_id' => 101, 'commission' => 15],   // пробный дошёл
    ['customer_id' => 102, 'commission' => 0],    // пробный не дошёл
    ['customer_id' => 103, 'commission' => 0],    // прошлогоднее пробное — не в счёт
    ['customer_id' => 201, 'commission' => 33],   // обычный ребёнок пришёл
    ['customer_id' => 202, 'commission' => 0],    // обычный пропустил — это НЕ пробное
];
$r = alfa_trial_count_details($details, $trial, $D);
ok('дошли', $r['done'], 1);
ok('не дошли', $r['missed'], 1);
ok('всего пробных должно было состояться', $r['done'] + $r['missed'], 2);
ok('кто именно не дошёл', $r['missedIds'], [102]);
ok('кто дошёл', $r['doneIds'], [101]);
yes('обычные дети в статистику не попали', !in_array(201, $r['missedIds'], true) && !in_array(202, $r['missedIds'], true));
ok('участников разобрано', $r['seen'], 5);
ok('без id участников нет', $r['noCid'], 0);

/* --- 4. периоды абонемента --- */
$r2 = alfa_trial_count_details([['customer_id' => 103, 'commission' => 0]], $trial, '2025-09-15');
ok('в свой период прошлогоднее пробное считается', $r2['missed'], 1);
$r3 = alfa_trial_count_details([['customer_id' => 101, 'commission' => 15]], $trial, '2026-10-05');
ok('после окончания абонемента — не считается', $r3['done'], 0);
$open = [104 => ['from' => '', 'to' => '', 'tariff' => 5]];
ok('абонемент без дат действует всегда',
   alfa_trial_count_details([['customer_id' => 104, 'commission' => 0]], $open, $D)['missed'], 1);

/* --- 5. поле с id не найдено: молчать нельзя, иначе нули сойдут за «пробных не было» --- */
$blind = alfa_trial_count_details([['commission' => 15], ['commission' => 0]], $trial, $D);
ok('без id ничего не насчитали', $blind['done'] + $blind['missed'], 0);
ok('но пометили, сколько строк не разобрали', $blind['noCid'], 2);

/* --- 6. пустой день --- */
$empty = alfa_trial_count_details([], $trial, $D);
ok('пустое занятие — нули', $empty['done'] + $empty['missed'] + $empty['seen'], 0);

echo $bad ? "\n❌ провалено проверок: $bad\n" : "\n✅ всё сошлось\n";
exit($bad ? 1 : 0);
