<?php
// Журнал правок задним числом.
// Запуск: php backend/test-changelog.php
//
// Вопрос Жанны: «если кто-то в Alfa задним числом что-то поправит — мы узнаем?». Раньше — нет:
// ночной пересчёт молча перезаписывал дневную строку, и «было 1 658, стало 1 590» не оставалось
// нигде. Теперь перед записью прежнее значение сравнивается с новым.
//
// Главная ловушка — ложные срабатывания. День, который вчера ещё не прошёл, а сегодня прошёл,
// меняет цифру с нуля на настоящую. Это не правка задним числом, а ход времени, и в журнале
// такому не место: если он зашумлён, его перестают читать, и настоящая правка теряется.
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

/* --- журнал пишется в НАСТОЯЩИЙ файл, только во временной папке: так проверяются и запись,
       и чтение, и обрезка, а не только логика в памяти --- */
$TMP = sys_get_temp_dir() . '/finmodel-test-' . getmypid();
@mkdir($TMP, 0777, true);
function alfa_store_dir(): string { global $TMP; return $TMP; }

/* --- остальное подменяем --- */
$STORE = [];
function alfa_realization_store_read(): array { global $STORE; return $STORE; }
function alfa_realization_store_write(array $d): void { global $STORE; $STORE = $d; }
$ATT = [];
/* хранилище детомест по группам — тоже в памяти (реализация пишет и его) */
$FILL = [];
function alfa_fill_read(): array { global $FILL; return $FILL; }
function alfa_fill_write(array $d): void { global $FILL; $FILL = $d; }
function alfa_fill_row(array $g): array { return $g; }
function alfa_attend_read(): array { global $ATT; return $ATT; }
function alfa_attend_write(array $d): void { global $ATT; $ATT = $d; }
function alfa_iso(string $d): string { return substr($d, 0, 10); }
$DAY = ['present' => 0.0, 'all' => 0.0, 'lessons' => 0];
function alfa_realization_day(string $date, ?array $b = null): array {
    global $DAY;
    return ['date' => $date, 'realizationPresent' => $DAY['present'], 'realizationAll' => $DAY['all'],
            'realizationPlanned' => 0.0, 'lessons' => $DAY['lessons'], 'plannedLessons' => 0,
            'byTeacher' => [], 'attendedIds' => []];
}

/* --- вырезаем НАСТОЯЩИЕ функции из lib.php --- */
$lib = file_get_contents(__DIR__ . '/../api/alfa/lib.php');
$src = '';
foreach (['alfa_changelog_path', 'alfa_changelog_read', 'alfa_changelog_write',
          'alfa_changelog_add', 'alfa_realization_upsert'] as $fn) {
    if (!preg_match('/\nfunction ' . $fn . '\(.*?\n\}/s', $lib, $m)) { echo "не найдено в lib.php: $fn\n"; exit(1); }
    $src .= $m[0] . "\n";
}
eval($src);
@unlink(alfa_changelog_path());

$D = '2026-09-03';
$run = function (float $present, float $all, int $lessons) use (&$DAY, $D) {
    $DAY = ['present' => $present, 'all' => $all, 'lessons' => $lessons];
    alfa_realization_upsert($D);
};

/* ===== 1. первый раз день считать не с чем ===== */
$run(1600, 1716, 12);                       // факт = 1658
ok('день записан', $STORE[$D]['present'], 1600.0);
ok('журнал пуст: сравнивать было не с чем', count(alfa_changelog_read()), 0);

/* ===== 2. повторный пересчёт с тем же результатом молчит ===== */
$run(1600, 1716, 12);
ok('одинаковый пересчёт в журнал не идёт', count(alfa_changelog_read()), 0);

/* ===== 3. кто-то поправил посещаемость задним числом ===== */
$run(1540, 1640, 12);                       // факт = 1590
$log = alfa_changelog_read();
ok('правка замечена', count($log), 1);
ok('день назван', $log[0]['d'], $D);
ok('что поехало', $log[0]['f'], 'fact');
ok('было', $log[0]['was'], 1658.0);
ok('стало', $log[0]['now'], 1590.0);
ok('занятий было', $log[0]['wasLes'], 12);
ok('занятий стало', $log[0]['nowLes'], 12);
yes('метка времени разбирается как дата', strtotime((string)$log[0]['at']) > 0, (string)$log[0]['at']);

/* ===== 4. копейки не считаем правкой ===== */
$run(1540, 1640.005, 12);
ok('расхождение меньше копейки пропускаем', count(alfa_changelog_read()), 1);

/* ===== 5. ГЛАВНОЕ: ход времени — не правка =====
   Вчера день ещё не прошёл (занятий 0), сегодня прошёл. Цифра изменилась с нуля на настоящую,
   но это нормальная жизнь, а не чья-то правка. */
$STORE = []; $ATT = [];
@unlink(alfa_changelog_path());
$run(0, 0, 0);                              // день впереди: занятий нет
$run(2000, 2100, 14);                       // день прошёл
ok('переход «занятий не было → прошли» в журнал не попал', count(alfa_changelog_read()), 0);
// а вот следующая правка того же дня — уже настоящая
$run(1900, 2000, 14);
ok('и следующая правка уже записана', count(alfa_changelog_read()), 1);
ok('она про уменьшение', alfa_changelog_read()[0]['now'] < alfa_changelog_read()[0]['was'], true);

/* ===== 6. занятие отменили задним числом ===== */
$run(0, 0, 0);
$log = alfa_changelog_read();
$last = $log[count($log) - 1];
ok('обнуление проведённого дня замечено', $last['now'], 0.0);
ok('и видно, что занятия пропали', $last['nowLes'], 0);

/* ===== 7. журнал не растёт бесконечно ===== */
$many = [];
for ($i = 0; $i < 450; $i++) $many[] = ['d' => $D, 'f' => 'fact', 'was' => $i, 'now' => $i + 1, 'at' => date('c')];
alfa_changelog_write($many);
$back = alfa_changelog_read();
ok('журнал обрезан до 400', count($back), 400);
ok('и оставлены СВЕЖИЕ записи, а не первые', $back[399]['was'], 449);

@unlink(alfa_changelog_path());
@rmdir($TMP);
echo $bad ? "\n❌ провалов: $bad\n" : "\n✅ всё сошлось\n";
exit($bad ? 1 : 0);
