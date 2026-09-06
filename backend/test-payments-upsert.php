<?php
// Снимок кассы за день: alfa_payments_upsert().
// Запуск: php backend/test-payments-upsert.php
//
// Действие paymentsDay читает журнал платежей Alfa САМО, а потом звало upsert, который читал его
// ещё раз. 33 тысячи записей, два прохода за один запрос — вдвое дольше, и вдвое чаще шлюз
// хостинга обрывал ответ на полпути. Для владельца обрыв выглядел так: «нажимаю обновить, а
// данные старые». Теперь готовый результат передаётся третьим аргументом.
//
// Проверяется: журнал не читается повторно, снимок при этом получается ТОТ ЖЕ, а испорченный
// аргумент не превращается в пустую кассу — лучше лишний проход, чем нули вместо денег.
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

/* --- журнал платежей за 06.09: приход тремя кассами и один расход --- */
$ROWS = [
    ['income' => 148.0,  'pay_type_name' => 'Оплата',  'pay_account_id' => 1, 'pay_item_id' => 0],
    ['income' => 2021.0, 'pay_type_name' => 'Оплата',  'pay_account_id' => 2, 'pay_item_id' => 0],
    ['income' => 851.0,  'pay_type_name' => 'Оплата',  'pay_account_id' => 3, 'pay_item_id' => 0],
    ['income' => 120.0,  'pay_type_name' => 'Расход',  'pay_account_id' => 2, 'pay_item_id' => 7],
];
$SCANS = 0;                     // сколько раз пришлось лезть в журнал Alfa
function alfa_payments_day(string $date, ?array $b = null): array {
    global $ROWS, $SCANS; $SCANS++;
    return ['date' => $date, 'rows' => $ROWS, 'scanned' => 33000, 'pages' => 660];
}
function alfa_pay_refs(): array {
    return ['payAccounts' => [1 => 'ЕРИП', 2 => 'Наличные', 3 => 'Терминал'],
            'payItems' => [7 => 'Бытовые расходы'], 'locations' => []];
}
$STORE = [];
function alfa_pay_store_read(): array { global $STORE; return $STORE; }
function alfa_pay_store_write(array $d): void { global $STORE; $STORE = $d; }

/* --- вырезаем НАСТОЯЩИЕ функции из lib.php --- */
$lib = file_get_contents(__DIR__ . '/../api/alfa/lib.php');
$src = '';
foreach (['alfa_pay_kassa_name', 'alfa_payments_upsert'] as $fn) {
    if (!preg_match('/\nfunction ' . $fn . '\(.*?\n\}/s', $lib, $m)) { echo "не найдено в lib.php: $fn\n"; exit(1); }
    $src .= $m[0] . "\n";
}
eval($src);

/* ===== 1. как было: без готового результата журнал читается ===== */
$SCANS = 0;
$fromScan = alfa_payments_upsert('2026-09-06');
ok('без готового результата журнал читаем', $SCANS, 1);
ok('приход', $fromScan['income'], 148.0 + 2021.0 + 851.0);
ok('расход', $fromScan['expense'], 120.0);
ok('операций', $fromScan['count'], 4);
ok('наличные в приходе', $fromScan['byIn']['Наличные'], 2021.0);
ok('наличные в расходе', $fromScan['byOut']['Наличные'], 120.0);
ok('статья расхода', $fromScan['byItem']['Бытовые расходы'], 120.0);
yes('расход не попал в приход', ($fromScan['byIn']['Наличные'] ?? 0) === 2021.0);

/* ===== 2. как стало: готовый результат — второго прохода нет ===== */
$STORE = [];
$SCANS = 0;
$pre = ['date' => '2026-09-06', 'rows' => $ROWS, 'scanned' => 33000, 'pages' => 660];
$fromPre = alfa_payments_upsert('2026-09-06', null, $pre);
ok('журнал НЕ перечитан', $SCANS, 0);
ok('снимок тот же, что при полном проходе',
   json_encode($fromPre, JSON_UNESCAPED_UNICODE), json_encode($fromScan, JSON_UNESCAPED_UNICODE));
yes('и он записан в хранилище', isset($STORE['2026-09-06']));
ok('в хранилище тот же приход', $STORE['2026-09-06']['income'], 148.0 + 2021.0 + 851.0);
yes('у записи есть метка времени', ($STORE['2026-09-06']['ts'] ?? '') !== '');
yes('метка времени разбирается как дата', strtotime((string)$STORE['2026-09-06']['ts']) > 0,
    (string)($STORE['2026-09-06']['ts'] ?? ''));

/* ===== 3. испорченный аргумент: лучше лишний проход, чем пустая касса ===== */
foreach ([['без rows', ['date' => '2026-09-06']],
          ['без date', ['rows' => $ROWS]],
          ['не массив', null]] as [$why, $junk]) {
    $STORE = []; $SCANS = 0;
    $r = alfa_payments_upsert('2026-09-06', null, $junk);
    ok("$why — читаем журнал заново", $SCANS, 1);
    ok("$why — деньги на месте", $r['income'], 148.0 + 2021.0 + 851.0);
}

/* ===== 4. день без платежей: ноль записывается честно, а не пропускается ===== */
$STORE = []; $ROWS = [];
$empty = alfa_payments_upsert('2026-09-07', null, ['date' => '2026-09-07', 'rows' => []]);
ok('пустой день: приход', $empty['income'], 0.0);
ok('пустой день: операций', $empty['count'], 0);
yes('пустой день всё равно попал в хранилище', isset($STORE['2026-09-07']));

echo $bad ? "\n❌ провалов: $bad\n" : "\n✅ всё сошлось\n";
exit($bad ? 1 : 0);
