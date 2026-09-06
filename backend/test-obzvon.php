<?php
// Серверная часть списков на обзвон: роспись и воскресный прогон кусками.
// Запуск: php backend/test-obzvon.php
//
// Здесь НЕТ разбора на корзины («не дошёл», «не пришёл на курс») — он живёт в модели и
// проверяется в backend/test-obzvon.cjs. PHP отвечает только за то, чтобы снимок был полным
// и не подменял собой предыдущий, пока не собран до конца: недособранный список — это тихая
// ложь, в понедельник по нему обзвонят половину клуба и решат, что остальные дошли.
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

/* Хранилище кладём во временный каталог: настоящий store трогать нельзя, там живой снимок. */
$TMP = sys_get_temp_dir() . '/obzvon_test_' . getmypid();
@mkdir($TMP, 0770, true);
function alfa_store_dir(): string { global $TMP; return $TMP; }
function alfa_iso(string $d): string { return substr($d, 0, 10); }
function alfa_realization_branches(): array { return [1]; }
/* Заглушки Alfa: настоящих запросов в тесте нет, но запомним, кого спрашивали — по этому и
   видно, что прогон обошёл ВСЕХ и ровно по одному разу. */
$ASKED = [];
function alfa_kids_lessons(array $ids, string $from, string $to, ?array $b = null, bool $f = false, string $season = ''): array {
    global $ASKED;
    $k = []; $bf = []; $c = [];
    foreach ($ids as $id) { $ASKED[] = (int)$id;
        $k[(int)$id] = [['date' => '2026-09-05', 'subjectId' => 7, 'from' => '17:30', 'to' => '18:30',
                         'teacherIds' => [], 'done' => true, 'sum' => 0, 'attend' => null]];
        $bf[(int)$id] = ['n' => 0, 'last' => ''];
        $c[(int)$id] = ['name' => 'Ребёнок ' . (int)$id, 'archived' => false, 'evzz' => ''];
    }
    return ['kids' => $k, 'before' => $bf, 'cards' => $c, 'asked' => count($ids)];
}
function alfa_kids_tariffs(array $ids, ?array $b = null, bool $f = false): array {
    $o = [];
    foreach ($ids as $id) $o[(int)$id] = ['tariffs' => [], 'paid' => false];
    return ['tariffs' => $o, 'asked' => count($ids)];
}
$REFS = 0;
function alfa_simple_ref(string $e, array $b): array { global $REFS; if ($e === 'subject') $REFS++; return [7 => 'Roblox', 9 => 'Scratch']; }

// переносы приводим к \n: на Windows рабочая копия бывает с CRLF, и «конец строки» в шаблонах
// ниже переставал совпадать — тест падал не на коде, а на переводе строки
$lib = str_replace("\r\n", "\n", (string)file_get_contents(__DIR__ . '/../api/alfa/lib.php'));
$src = '';
foreach (['alfa_obzvon_store_path', 'alfa_obzvon_read', 'alfa_obzvon_write', 'alfa_obzvon_roster_norm',
          'alfa_obzvon_roster_set', 'alfa_obzvon_season', 'alfa_obzvon_from', 'alfa_obzvon_to',
          'alfa_obzvon_build', 'alfa_obzvon_run'] as $fn) {
    // однострочные функции (alfa_obzvon_from/to) закрываются на своей же строке — жадный
    // поиск до «\n}» проглотил бы вместе с ними и следующую
    if (preg_match('/\nfunction ' . $fn . '\([^\n]*\}[ \t]*$/m', $lib, $m)) { $src .= $m[0] . "\n"; continue; }
    if (!preg_match('/\nfunction ' . $fn . '\(.*?\n\}/s', $lib, $m)) { echo "не найдено в lib.php: $fn\n"; exit(1); }
    $src .= $m[0] . "\n";
}
eval($src);

echo "--- 1. роспись: что приходит из модели ---\n";
$r = alfa_obzvon_roster_norm([
    ['id' => 5, 'name' => ' Аня ', 'phone' => ' +375291112233 '],
    ['id' => 0, 'name' => 'Без Альфы'],          // связи с Alfa нет — занятия не спросишь
    ['id' => 5, 'name' => 'Аня ещё раз'],        // тот же ребёнок дважды
    'мусор',
    ['name' => 'без id'],
]);
ok('в росписи остался один', count($r), 1);
ok('имя обрезано по краям', $r[0]['name'], 'Аня');
ok('телефон обрезан по краям', $r[0]['phone'], '+375291112233');

echo "--- 2. ГЛАВНОЕ: один ребёнок не может быть и новым, и прошлогодним ---\n";
$c = alfa_obzvon_roster_set(
    [['id' => 1, 'name' => 'Аня'], ['id' => 2, 'name' => 'Боря']],
    [['id' => 2, 'name' => 'Боря'], ['id' => 3, 'name' => 'Витя']]);
ok('новых', $c['new'], 2);
ok('прошлогодних — Боря выброшен, он уже в новых', $c['old'], 1);
$d = alfa_obzvon_read();
ok('в прошлогодних остался только Витя', array_column($d['roster']['old'], 'id'), [3]);

echo "--- 3. без росписи прогон честно отказывается ---\n";
@unlink(alfa_obzvon_store_path());
$r = alfa_obzvon_build(0, 15, false, [1]);
yes('прогона нет', empty($r['ok']));
yes('и сказано почему', strpos((string)($r['error'] ?? ''), 'росписи нет') === 0, (string)($r['error'] ?? ''));

echo "--- 4. прогон кусками обходит всех и ровно по разу ---\n";
$new = []; $old = [];
for ($i = 1; $i <= 7; $i++)  $new[] = ['id' => $i, 'name' => 'Новый ' . $i];
for ($i = 11; $i <= 15; $i++) $old[] = ['id' => $i, 'name' => 'Старый ' . $i];
alfa_obzvon_roster_set($new, $old);
$ASKED = []; $REFS = 0;
$r = alfa_obzvon_build(0, 5, false, [1]);
ok('первый кусок — 5 из 12', [$r['done'], $r['total']], [5, 12]);
yes('прогон ещё не закончен', empty($r['complete']));
$d = alfa_obzvon_read();
yes('ГЛАВНОЕ: недособранный снимок не подменил готовый', empty($d['snap']));
yes('он копится отдельно', !empty($d['wip']));
$r = alfa_obzvon_build((int)$r['nextOffset'], 5, false, [1]);
$r = alfa_obzvon_build((int)$r['nextOffset'], 5, false, [1]);
yes('на третьем куске закончили', !empty($r['complete']));
ok('обошли всех', $r['done'], 12);
sort($ASKED);
ok('и каждого ровно по разу', $ASKED, [1, 2, 3, 4, 5, 6, 7, 11, 12, 13, 14, 15]);
ok('справочник курсов спросили один раз за прогон, а не на каждом куске', $REFS, 1);

echo "--- 5. что лежит в готовом снимке ---\n";
$d = alfa_obzvon_read();
$s = $d['snap'];
yes('снимок появился', !empty($s));
yes('незавершённый черновик убран', empty($d['wip']));
ok('детей в снимке', count($s['kids']), 12);
ok('карточек столько же', count($s['cards']), 12);
ok('абонементов столько же', count($s['tar']), 12);
yes('роспись уехала в снимок — печатается ровно то, что собрано',
    count($s['roster']['new']) === 7 && count($s['roster']['old']) === 5);
yes('названия курсов на месте', ($s['subjects'][7] ?? '') === 'Roblox');
yes('видно, когда собран', !empty($s['builtAt']));
yes('и кем — вручную из браузера (кусками его крутит модель)', ($s['ranBy'] ?? '') === 'вручную', (string)($s['ranBy'] ?? ''));
ok('окно занятий начинается за год до сезона', alfa_obzvon_from(),
   ((int)substr(alfa_obzvon_season(), 0, 4) - 1) . '-09-01');
yes('сезон — 1 сентября', substr(alfa_obzvon_season(), 4) === '-09-01', alfa_obzvon_season());
yes('в снимке нет служебного списка id', !isset($s['ids']));

echo "--- 6. прогон целиком (так его крутит cron в вс 21:00) ---\n";
$ASKED = [];
$prev = $d['snap']['builtAt'];
$r = alfa_obzvon_run([1], true);
yes('дошёл до конца', !empty($r['complete']));
ok('обошёл всех', $r['done'], 12);
$d = alfa_obzvon_read();
yes('снимок заменился на свежий', $d['snap']['builtAt'] !== '' );
yes('и помечен как воскресный прогон', ($d['snap']['ranBy'] ?? '') === 'cron', (string)($d['snap']['ranBy'] ?? ''));
ok('в снимке снова все', count($d['snap']['kids']), 12);

echo "--- 7. ГЛАВНОЕ: дыру в середине прогон замечает и идёт латать её ---\n";
// Так бывает, когда Жанна жмёт «пересобрать сейчас» в 21:00, пока идёт cron: оба пишут файл
// целиком, и чей-то кусок затирается. По счётчику «сколько кусков прошло» прогон объявил бы
// себя законченным, и в понедельник печатался бы дырявый список.
alfa_obzvon_build(0, 5, false, [1]);
alfa_obzvon_build(5, 5, false, [1]);
$d = alfa_obzvon_read();
unset($d['wip']['kids']['3'], $d['wip']['kids']['4']);   // как будто параллельная запись их съела
alfa_obzvon_write($d);
$r = alfa_obzvon_build(10, 5, false, [1]);               // последний кусок — тут и решается
yes('прогон НЕ объявил себя законченным', empty($r['complete']));
ok('и честно сказал, сколько собрано', $r['done'], 10);
ok('следующий кусок начнётся с дыры, а не с конца списка', $r['nextOffset'], 2);
$r = alfa_obzvon_build((int)$r['nextOffset'], 5, false, [1]);
yes('дыра залатана — теперь готово', !empty($r['complete']));
ok('и в снимке снова все', count(alfa_obzvon_read()['snap']['kids']), 12);

echo "--- 8. оборванный прогон не портит уже собранное ---\n";
$d = alfa_obzvon_read();
$goodAt = $d['snap']['builtAt'];
alfa_obzvon_build(0, 5, false, [1]);            // начали и «упали» — второго куска не будет
$d = alfa_obzvon_read();
ok('печатается прежний, полный снимок', $d['snap']['builtAt'], $goodAt);
ok('и он всё ещё полный', count($d['snap']['kids']), 12);

@array_map('unlink', (array)glob($TMP . '/*'));
@rmdir($TMP);
echo $bad ? "\nПРОВАЛОВ: $bad\n" : "\nвсё хорошо\n";
exit($bad ? 1 : 0);
