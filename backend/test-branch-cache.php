<?php
// Кэш справочника филиалов. Запуск: php backend/test-branch-cache.php
//
// Зачем: alfa_branch_names() спрашивается на КАЖДОМ действии прокси, а внутри одного действия —
// дважды (сам вызов + alfa_realization_branches). Без кэша даже «открыть Реализацию», где все
// данные лежат в локальном файле, требовало живых ответов Alfa, и при её задержке раздел падал
// с «соединение оборвалось».
declare(strict_types=1);

$bad = 0;
function ok(string $n, $got, $want): void {
    global $bad; $good = $got === $want; if (!$good) $bad++;
    echo ($good ? "✓ " : "✗ ") . $n . ' = ' . json_encode($got, JSON_UNESCAPED_UNICODE)
       . ($good ? '' : ' ≠ ' . json_encode($want, JSON_UNESCAPED_UNICODE)) . "\n";
}
function yes(string $n, bool $c, string $d = ''): void {
    global $bad; if (!$c) $bad++;
    echo ($c ? "✓ " : "✗ ") . $n . ($c || $d === '' ? '' : ': ' . $d) . "\n";
}

/* --- окружение вместо сети и диска --- */
$DIR = sys_get_temp_dir() . '/finmodel-branch-test-' . getmypid();
@mkdir($DIR, 0777, true);
function cache_dir(): string { global $DIR; return $DIR; }
function cache_write(string $f, string $s): void { file_put_contents($f, $s); }
function alfa_host(): string { return 'x.s20.online'; }
function alfa_token(): string { return 'tok'; }
$CALLS = 0; $REPLY = ['items' => [['id' => 3, 'name' => 'Пожарный, 19'], ['id' => 7, 'name' => 'Мира']]];
function alfa_http($m, $u, $b, $t, $soft = false, $to = 10) { global $CALLS, $REPLY; $CALLS++; return $REPLY; }

$lib = file_get_contents(__DIR__ . '/../api/alfa/lib.php');
foreach (['alfa_branch_names', 'alfa_branch_ids_by_name'] as $fn) {
    if (!preg_match('/\nfunction ' . $fn . '\(.*?\n\}/s', $lib, $m)) { echo "не найдено: $fn\n"; exit(1); }
    eval($m[0]);
}

/* --- 1. в памяти: два вызова подряд — один запрос в Alfa --- */
$a = alfa_branch_names();
$b = alfa_branch_names();
ok('справочник прочитан', $a[3], 'Пожарный, 19');
ok('повторный вызов дал то же', $b, $a);
ok('в Alfa сходили один раз, а не дважды', $CALLS, 1);
ok('ключи остались числами', array_keys($a), [3, 7]);

/* --- 2. поиск филиала по названию работает поверх кэша --- */
ok('«Пожарный» найден', alfa_branch_ids_by_name(['Пожарный']), [3]);
ok('лишних запросов не добавилось', $CALLS, 1);

/* --- 3. файловый кэш переживает новый запрос (память сброшена) --- */
$f = $DIR . '/alfa_branches.json';
yes('файл кэша создан', is_file($f));
$saved = json_decode((string)file_get_contents($f), true);
yes('в файле лежат имена', !empty($saved['names']));
yes('и отметка времени', (int)($saved['ts'] ?? 0) > 0);

/* --- 4. ГЛАВНОЕ: пустой ответ Alfa не кэшируется, иначе «филиал не найден» залипло бы на 12 часов --- */
@unlink($f);
$CALLS = 0; $REPLY = ['items' => []];
// память уже прогрета прошлым значением — клонируем функцию под новым именем, чтобы
// проверить саму логику записи на чистом static
preg_match('/\nfunction alfa_branch_names\(.*?\n\}/s', $lib, $m3);
eval(str_replace('function alfa_branch_names(', 'function alfa_branch_names_fresh(', $m3[0]));
$empty = alfa_branch_names_fresh();
ok('пустой ответ вернулся пустым', $empty, []);
yes('и НЕ записан в кэш', !is_file($f), 'файл создан, хотя данных не было');

/* --- 5. непустой ответ пишется --- */
$REPLY = ['items' => [['id' => 3, 'name' => 'Пожарный, 19']]];
eval(str_replace('function alfa_branch_names(', 'function alfa_branch_names_fresh2(', $m3[0]));
alfa_branch_names_fresh2();
yes('непустой ответ закэширован', is_file($f));

@unlink($f); @rmdir($DIR);
echo $bad ? "\n❌ провалено проверок: $bad\n" : "\n✅ всё сошлось\n";
exit($bad ? 1 : 0);
