<?php
// Замок недельного прогноза («Дашборд руководителя → Прогноз по всем»).
// Запуск: php backend/test-weekplan-lock.php
//
// Жанна нечаянно нажала «Зафиксировать неделю» на уже закончившейся неделе — снимок затёр
// прежнюю цифру, и взять её было больше неоткуда. Замок держит цифру от кнопки и от ручной
// правки; снять его можно только отдельным действием — в этом вся защита.
// Воскресный cron — сознательное исключение (решение Жанны): он пересобирает прогноз по
// самому свежему расписанию, ради этого и заведён, и замок его не останавливает.
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

/* --- хранилище в памяти вместо файла --- */
$WP = [];
function alfa_weekplan_read(): array { global $WP; return $WP; }
function alfa_weekplan_write(array $d): void { global $WP; $WP = $d; }
function alfa_iso(string $d): string { return substr($d, 0, 10); }

/* --- всё, что снимок спрашивает у Alfa, подменяем управляемыми числами --- */
$FC_DAY = 500.0;                       // прогноз одного дня недели
function alfa_realization_branches(): array { return [1]; }
function alfa_forecast_lessons_day(string $d, ?array $b = null): array {
    global $FC_DAY; return ['forecast' => $FC_DAY, 'lessons' => 3];
}
function alfa_forecast_week(string $mon, ?array $b = null): array {
    return ['forecast' => 0.0, 'lessons' => 0, 'groups' => 0];
}
function alfa_realization_upsert(string $d, ?array $b = null): array {
    return ['present' => 100, 'all' => 120, 'planned' => 0];
}
$EXP = ['total' => 7777.0, 'frozen' => 7];
function alfa_expect_freeze(string $mon, ?array $b = null, bool $force = false): array {
    global $EXP; return $EXP;
}

/* --- вырезаем НАСТОЯЩИЕ функции из lib.php --- */
$lib = file_get_contents(__DIR__ . '/../api/alfa/lib.php');
$src = '';
foreach (['alfa_monday_of', 'alfa_weekplan_locked', 'alfa_weekplan_set', 'alfa_weekplan_snapshot'] as $fn) {
    if (!preg_match('/\nfunction ' . $fn . '\(.*?\n\}/s', $lib, $m)) { echo "не найдено в lib.php: $fn\n"; exit(1); }
    $src .= $m[0] . "\n";
}
eval($src);

$PAST   = alfa_monday_of(date('Y-m-d', strtotime('-3 week')));
$FUTURE = alfa_monday_of(date('Y-m-d', strtotime('+3 week')));

/* ===== 1. старые записи: прошедшая неделя заперта, будущая нет ===== */
//     Поля locked у них нет — правило по смыслу: прошлое неизменно, будущее cron ещё уточняет.
$oldRec = ['plan' => 29884.0, 'lessons' => 933, 'src' => 'alfa', 'ts' => '2026-08-30T22:00:03+03:00'];
yes('прошедшая неделя без флага — заперта', alfa_weekplan_locked($PAST, $oldRec));
yes('будущая неделя без флага — не заперта', !alfa_weekplan_locked($FUTURE, $oldRec));
yes('недели без записи вовсе — замка нет', !alfa_weekplan_locked($PAST, null));
yes('явный locked=false сильнее даты', !alfa_weekplan_locked($PAST, ['plan' => 1, 'locked' => false]));
yes('явный locked=true сильнее даты', alfa_weekplan_locked($FUTURE, ['plan' => 1, 'locked' => true]));

/* ===== 2. под замком не проходит ручная правка ===== */
$WP = [$PAST => $oldRec];
$r = alfa_weekplan_set($PAST, 31000, null, 'smalprince@gmail.com');
yes('правка запертой недели отклонена', empty($r['ok']));
yes('и сказано почему', str_contains((string)($r['error'] ?? ''), 'замок'), (string)($r['error'] ?? ''));
ok('цифра не изменилась', $WP[$PAST]['plan'], 29884.0);

/* ===== 3. снять замок → поправить → запереть обратно ===== */
$r = alfa_weekplan_set($PAST, null, false, 'smalprince@gmail.com');
yes('замок снят', empty($r['locked']));
$r = alfa_weekplan_set($PAST, 31000.5, null, 'smalprince@gmail.com');
yes('правка прошла', !empty($r['ok']));
ok('цифра новая', $WP[$PAST]['plan'], 31000.5);
ok('источник — рука', $WP[$PAST]['src'], 'manual');
ok('видно, кто правил', $WP[$PAST]['by'], 'smalprince@gmail.com');
yes('и метка времени обновилась', strtotime((string)$WP[$PAST]['ts']) > strtotime('-1 minute'));
yes('после правки неделя всё ещё открыта', !alfa_weekplan_locked($PAST, $WP[$PAST]));

$r = alfa_weekplan_set($PAST, null, true, '');
yes('замок поставлен обратно', !empty($r['locked']));
$r = alfa_weekplan_set($PAST, 999, null, '');
yes('и следующая правка снова отклонена', empty($r['ok']));
ok('цифра осталась поправленной', $WP[$PAST]['plan'], 31000.5);

/* ===== 4. неделя без записи: правку принимаем, запись создаётся ===== */
$WP = [];
$r = alfa_weekplan_set($FUTURE, 12345, null, 'smalprince@gmail.com');
yes('пустую будущую неделю править можно', !empty($r['ok']));
ok('запись появилась', $WP[$FUTURE]['plan'], 12345.0);
$r = alfa_weekplan_set(date('Y-m-d', strtotime($FUTURE . ' +3 day')), 500, null, '');
ok('дата середины недели приведена к понедельнику', $r['week'], $FUTURE);

/* ===== 5. ГЛАВНОЕ: случайное «Зафиксировать неделю» больше не затирает ===== */
$WP = [$PAST => $oldRec];                       // старая запись без флага = заперта
$FC_DAY = 999.0;                                // Alfa посчитала бы 7×999 = 6993
$s = alfa_weekplan_snapshot($PAST);
ok('цифра прогноза уцелела', $WP[$PAST]['plan'], 29884.0);
ok('в ответе так и сказано', $s['locked'], true);
ok('ответ отдаёт сохранённую цифру, а не свежую', $s['plan'], 29884.0);
ok('свежий расчёт виден отдельно — для сверки', $s['fresh'], 7 * 999.0);
ok('источник не подменён', $WP[$PAST]['src'], 'alfa');
ok('метка снимка прежняя', $WP[$PAST]['ts'], '2026-08-30T22:00:03+03:00');
// а то, что от цифры прогноза не зависит, обновиться должно: этим живёт «Реализация»
ok('«ожидалось» пересчитано', $WP[$PAST]['expect'], 7777.0);
ok('дней заморожено', $WP[$PAST]['expectFrozen'], 7);
ok('уже проведённое обновлено', $WP[$PAST]['alreadyDone'], 7 * 110.0);

/* ===== 5b. воскресный cron замок НЕ останавливает =====
   Замок защищает от случайного нажатия человеком, а не от плановой работы: cron снимает
   прогноз по самому свежему расписанию — ровно ради этого он и заведён. Так решила Жанна. */
$WP = [$FUTURE => ['plan' => 100.0, 'src' => 'alfa', 'ts' => '2026-09-01T22:00:00+03:00', 'locked' => true]];
$FC_DAY = 300.0;
$s = alfa_weekplan_snapshot($FUTURE, null, true);        // так его зовёт cron_weekplan.php
ok('cron пересчитал запертую неделю', $WP[$FUTURE]['plan'], 7 * 300.0);
ok('и не делает вид, что цифру оставили', $s['locked'], false);
yes('сам замок никуда не делся', alfa_weekplan_locked($FUTURE, $WP[$FUTURE]));

// а кнопка в интерфейсе — уважает: те же данные, вызов без третьего аргумента
$WP = [$FUTURE => ['plan' => 100.0, 'src' => 'alfa', 'ts' => '2026-09-01T22:00:00+03:00', 'locked' => true]];
$s = alfa_weekplan_snapshot($FUTURE);
ok('кнопка запертую неделю не трогает', $WP[$FUTURE]['plan'], 100.0);
ok('и честно об этом говорит', $s['locked'], true);

// следствие, о котором надо помнить: ручную правку cron тоже перезапишет. По умолчанию он
// снимает только БУДУЩУЮ неделю, так что поправленное прошлое переживёт воскресенье.
$WP = [$PAST => ['plan' => 31000.5, 'src' => 'manual', 'by' => 'smalprince@gmail.com', 'locked' => true]];
alfa_weekplan_snapshot($PAST, null, true);
ok('запущенный на прошлую неделю cron перезапишет и ручную цифру', $WP[$PAST]['plan'], 7 * 300.0);
ok('и вернёт источник в автоматический', $WP[$PAST]['src'], 'alfa');

/* ===== 6. незапертую неделю снимок пересчитывает, как и раньше ===== */
$WP = []; $FC_DAY = 999.0;
$s = alfa_weekplan_snapshot($FUTURE);           // будущая, записи нет
ok('будущая неделя посчитана', $WP[$FUTURE]['plan'], 7 * 999.0);
ok('и не заперта в ответе', $s['locked'], false);
$FC_DAY = 111.0;
alfa_weekplan_snapshot($FUTURE);                // cron в воскресенье уточняет её же
ok('cron уточнил будущую неделю', $WP[$FUTURE]['plan'], 7 * 111.0);

/* ===== 7. снятый замок на прошедшей неделе — пересчёт разрешён (она сама попросила) ===== */
$WP = [$PAST => ['plan' => 100.0, 'src' => 'manual', 'locked' => false]];
$FC_DAY = 200.0;
alfa_weekplan_snapshot($PAST);
ok('по снятому замку прошедшая неделя пересчитана', $WP[$PAST]['plan'], 7 * 200.0);
yes('и осталась незапертой — замок ставит человек', !alfa_weekplan_locked($PAST, $WP[$PAST]));

/* ===== 8. ручная цифра под замком переживает снимок целиком ===== */
$WP = [$PAST => ['plan' => 31000.5, 'src' => 'manual', 'by' => 'smalprince@gmail.com',
                 'ts' => '2026-09-06T15:00:00+03:00', 'locked' => true]];
$FC_DAY = 42.0;
alfa_weekplan_snapshot($PAST);
ok('ручная цифра цела', $WP[$PAST]['plan'], 31000.5);
ok('и помечена как ручная', $WP[$PAST]['src'], 'manual');
ok('и автор сохранён', $WP[$PAST]['by'], 'smalprince@gmail.com');
yes('и замок на месте', alfa_weekplan_locked($PAST, $WP[$PAST]));

echo $bad ? "\n❌ провалов: $bad\n" : "\n✅ всё сошлось\n";
exit($bad ? 1 : 0);
