<?php
// Прокси к AlfaCRM — точка входа (роутер).
//
//   GET/POST ?action=ping       — проверка: токен валиден, конфиг на месте
//   GET/POST ?action=branches   — список филиалов Alfa (чтобы узнать branch id)
//   POST     ?action=customers  — ученики/клиенты из Alfa (id, ФИО, телефоны)
//
// Ключ AlfaCRM лежит только в config.php на сервере. Клиент шлёт Firebase ID-токен
// в заголовке Authorization: Bearer <token> — прокси проверяет его перед выдачей данных.
declare(strict_types=1);

require __DIR__ . '/lib.php';

cors();
$action = $_GET['action'] ?? 'ping';
$user   = require_firebase_user();   // 401/403 если токен не прошёл

// тело запроса (для POST)
$in = [];
$rawIn = file_get_contents('php://input');
if ($rawIn) { $j = json_decode($rawIn, true); if (is_array($j)) $in = $j; }

// ⚠️ ЗАПИСЬ В ЧУЖУЮ CRM — только тем, кому это разрешено в config.php (write_emails).
//    Прятать кнопки на клиенте недостаточно: запрос легко повторить из консоли браузера,
//    достаточно передать dryRun:false. Проверяем ДО обработки действия.
$WRITE_ACTIONS = ['publish', 'addToGroup', 'giveTariff', 'createCustomer', 'archiveCustomer', 'renameCustomer'];
if (in_array($action, $WRITE_ACTIONS, true)) {
    $wantsLive = array_key_exists('dryRun', $in) && $in['dryRun'] === false;
    if ($wantsLive && empty($user['can_write'])) {
        json_out(['ok' => false, 'error' => 'Запись в AlfaCRM разрешена только администратору (см. write_emails в config.php)'], 403);
    }
}

switch ($action) {

    // --- health-check ---
    case 'ping':
        json_out(['ok' => true, 'user' => $user['email'], 'host' => alfa_host()]);
        break;

    // --- список филиалов (глобальный метод, без branch) ---
    case 'branches':
        $r = alfa_call('branch', 'index', ['is_active' => 1, 'page' => 0], true);
        $items = $r['items'] ?? [];
        $out = array_map(fn($b) => ['id' => $b['id'] ?? null, 'name' => $b['name'] ?? ''], $items);
        json_out(['ok' => true, 'branches' => $out, 'current_branch' => alfa_branch()]);
        break;

    // --- ученики/клиенты ---
    case 'customers':
        @set_time_limit(180);   // обход всех филиалов может занять время

        // По умолчанию берём всех НЕ удалённых (и учеников is_study=1, и лидов is_study=0 —
        // у части детей контакт лежит в лиде). Клиент может переопределить фильтр.
        $filter = is_array($in['filter'] ?? null) ? $in['filter'] : [];
        $filter['removed'] = $filter['removed'] ?? 0;

        $branches = alfa_all_branch_ids();
        // имена филиалов (id => name) — чтобы клиент мог отличить «Детали» (взрослое пространство) от детских
        $brNames = [];
        $brResp = alfa_http('POST', 'https://' . alfa_host() . '/v2api/branch/index', ['is_active' => 1, 'page' => 0], alfa_token(), true, 8);
        foreach (($brResp['items'] ?? []) as $b) { if (isset($b['id'])) $brNames[(int)$b['id']] = (string)($b['name'] ?? ''); }

        $byId     = [];          // дедуп по id (один ребёнок может быть в нескольких филиалах)
        $perPage  = 50;          // Alfa отдаёт максимум ~50 на страницу
        $maxPages = 200;         // предохранитель на филиал
        $perBranch = [];

        foreach ($branches as $bid) {
            $page = 0; $before = count($byId);
            do {
                $body = array_merge($filter, ['page' => $page, 'count' => $perPage]);
                $r = alfa_call_branch((int)$bid, 'customer', 'index', $body);
                $items = $r['items'] ?? [];
                foreach ($items as $c) {
                    $id = $c['id'] ?? null;
                    if ($id === null) continue;
                    if (!isset($byId[$id])) {
                        $phones = $c['phone'] ?? [];
                        if (is_string($phones)) $phones = $phones === '' ? [] : [$phones];
                        $byId[$id] = [
                            'id'       => $id,
                            'name'     => trim((string)($c['name'] ?? '')),
                            'phones'   => array_values(array_filter(array_map('strval', (array)$phones))),
                            'is_study' => (int)($c['is_study'] ?? 0),
                            'dob'      => $c['dob'] ?? null,
                            // «Заказчик» в Alfa — это родитель; нужен для обращения в сообщениях
                            'parent'   => $c['legal_name'] ?? null,
                            'balance'  => $c['balance'] ?? null,   // активный баланс (остаток по счёту клиента)
                            // Дата создания записи. dt_add в списке нет, есть created_at — им и пользуемся.
                            // (b_date не берём — это дата начала договора, у вернувшихся часто это лето.)
                            'created'  => $c['dt_add'] ?? ($c['created_at'] ?? null),
                            // Последнее посещение. Сильнейший признак «вернувшегося»: кто посещал занятия
                            // до этого лета — точно был в клубе, что бы ни стояло в дате создания записи.
                            'last_attend' => $c['last_attend_date'] ?? null,
                            'branch_ids' => [],
                        ];
                    }
                    // копим ВСЕ филиалы, где встретился клиент (чтобы отличить «только Детали» от детских)
                    if (!in_array((int)$bid, $byId[$id]['branch_ids'], true)) $byId[$id]['branch_ids'][] = (int)$bid;
                }
                $total = (int)($r['total'] ?? 0);
                $page++;
                // Продолжаем, пока страница ПОЛНАЯ. Раньше здесь был ещё и лимит по total, но если
                // Alfa его не вернула (total=0), обход обрывался после первой страницы — и в выгрузку
                // попадали только 50 клиентов филиала. Полная страница сама по себе значит «есть ещё».
            } while (count($items) === $perPage && $page < $maxPages);
            $perBranch[$bid] = count($byId) - $before;
        }

        $all = array_values($byId);
        // сколько записей реально несут дату рождения / дату внесения — клиенту, чтобы отличить
        // «не нашли ребёнка» от «Alfa не отдала поле» (иначе кнопки молча ничего не делают)
        $withDob = 0; $withCreated = 0;
        foreach ($all as $c) { if (!empty($c['dob'])) $withDob++; if (!empty($c['created'])) $withCreated++; }

        // ДИАГНОСТИКА: какие вообще поля есть в записи клиента и что в «датовых».
        // Нужна, чтобы понять, отдаёт ли customer/index дату создания (dt_add) — по ней делим
        // «новый/возврат». Просим ОДНУ сырую запись клиента (первый филиал, первый клиент).
        $diag = null;
        if (!empty($in['diag'])) {
            $bid0 = $branches[0] ?? alfa_branch();
            $r0 = alfa_call_branch((int)$bid0, 'customer', 'index', ['removed' => 0, 'page' => 0, 'count' => 1]);
            $c0 = $r0['items'][0] ?? [];
            $dateKeys = [];
            foreach ($c0 as $k => $v) {
                if (is_scalar($v) && preg_match('/date|add|creat|updat|b_date|dt_|added|reg/i', (string)$k)) {
                    $dateKeys[$k] = is_string($v) ? mb_substr($v, 0, 30) : $v;
                }
            }
            $diag = ['all_keys' => array_keys($c0), 'date_fields' => $dateKeys];
        }
        json_out(['ok' => true, 'count' => count($all), 'customers' => $all, 'branchNames' => $brNames,
                  'branches' => count($branches), 'per_branch' => $perBranch,
                  'with_dob' => $withDob, 'with_created' => $withCreated, 'diag' => $diag]);
        break;

    // --- история ребёнка: в каких группах был + краткая сводка (READ) ---
    case 'history':
        @set_time_limit(60);
        $cid = (int)($in['customerId'] ?? 0);
        $matchedName = '';
        if (!$cid && !empty($in['name'])) {           // поиск по ФИО, если id не передан
            $s = alfa_http('POST', 'https://' . alfa_host() . '/v2api/' . alfa_branch() . '/customer/index',
                ['name' => (string)$in['name'], 'removed' => 0, 'page' => 0, 'count' => 20], alfa_token(), true);
            $cands = $s['items'] ?? [];
            if (count($cands)) { $cid = (int)($cands[0]['id'] ?? 0); $matchedName = (string)($cands[0]['name'] ?? ''); }
        }
        if (!$cid) json_out(['ok' => true, 'notFound' => true, 'history' => [], 'summary' => []]);
        $from  = (string)($in['from'] ?? '2025-09-01');
        $to    = (string)($in['to'] ?? '2026-05-31');
        $host  = 'https://' . alfa_host() . '/v2api/' . alfa_branch();
        $token = alfa_token();

        // группы ребёнка (id) с диапазоном дат — из ДВУХ источников:
        //   1) cgi (членства) — но отдаёт в основном действующие;
        //   2) lesson (уроки/посещения) — ловит и АРХИВНЫЕ группы прошлого года.
        $gid = [];   // group_id => ['b'=>минДата, 'e'=>максДата]
        $note = function (&$gid, $id, $d) {
            if (!$id) return; $d = $d ? substr($d, 0, 10) : null;
            if (!isset($gid[$id])) $gid[$id] = ['b' => $d, 'e' => $d];
            elseif ($d) { if (!$gid[$id]['b'] || $d < $gid[$id]['b']) $gid[$id]['b'] = $d; if (!$gid[$id]['e'] || $d > $gid[$id]['e']) $gid[$id]['e'] = $d; }
        };
        $inWin = fn($d) => !$d || ($d >= $from && $d <= $to);

        // 1) cgi (членства): дефолтный запрос отдаёт в основном ТЕКУЩИЕ. Членства на новый учебный
        //    год (с 01.09) — будущие, в дефолт не попадают → добираем вторым запросом с диапазоном дат
        //    до 2027-06-30. Мержим по (id+group_id). Так «активные абонементы» ловят и сентябрьские.
        $today  = date('Y-m-d');
        $futTo  = '2027-06-30';
        $cgiItems = []; $seenCgi = [];
        foreach ([
            ['customer_id' => $cid, 'page' => 0, 'count' => 200],
            ['customer_id' => $cid, 'date_from' => $today, 'date_to' => $futTo, 'b_date' => $today, 'e_date' => $futTo, 'page' => 0, 'count' => 200],
        ] as $q) {
            $rr = alfa_http('POST', "$host/cgi/index", $q, $token, true, 10);
            if (isset($rr['__err'])) continue;
            foreach (($rr['items'] ?? []) as $it) {
                $key = ($it['id'] ?? '') . ':' . ($it['group_id'] ?? '');
                if (isset($seenCgi[$key])) continue;
                $seenCgi[$key] = 1; $cgiItems[] = $it;
            }
        }
        $activeCgi = [];   // group_id => ['b'=>..,'e'=>..] — ДЕЙСТВУЮЩИЕ и БУДУЩИЕ членства
        $cgiDbg = [];      // диагностика: что реально вернула Альфа
        foreach ($cgiItems as $it) {
            $b = $it['b_date'] ?? null; $e = $it['e_date'] ?? null;
            $bb = $b ? substr($b, 0, 10) : null; $ee = $e ? substr($e, 0, 10) : null;
            $gidc = $it['group_id'] ?? null;
            if (count($cgiDbg) < 15) $cgiDbg[] = ['g' => $gidc, 'b' => $bb, 'e' => $ee];
            // активное = ещё не закончилось (нет конца ИЛИ конец в будущем) ИЛИ ещё не началось (старт в будущем)
            if ($gidc && ((!$ee || $ee >= $today) || ($bb && $bb >= $today))) $activeCgi[$gidc] = ['b' => $bb, 'e' => $ee];
            if (($bb && $bb > $to) || ($ee && $ee < $from)) continue;   // не пересекается с окном прошлого года
            $note($gid, $gidc, $ee ?: $bb);
        }
        // 2) уроки в окне с ПАГИНАЦИЕЙ (Alfa отдаёт ≤50 на страницу; у активного ребёнка за год >150
        //    уроков — без пагинации терялись ранние группы/расписание). Лимит 20 страниц = 1000.
        //    ⚠️ Выход по `total` был ловушкой: Alfa возвращает это поле НЕ всегда, при его отсутствии
        //    $total=0 и условие `count >= 0` срывалось на первой же странице — то есть у активного
        //    ребёнка история обрывалась, ранние группы «исчезали», и он выглядел новым. Идём, пока
        //    страница ПОЛНАЯ (тот же приём, что в customers/groupsList).
        $lesItems = []; $lesPer = 50;
        for ($lp = 0; $lp < 20; $lp++) {
            $les = alfa_http('POST', "$host/lesson/index",
                ['customer_id' => $cid, 'date_from' => $from, 'date_to' => $to, 'b_date' => $from, 'e_date' => $to, 'page' => $lp, 'count' => $lesPer],
                $token, true, 14);
            if (isset($les['__err'])) break;
            $batch = $les['items'] ?? [];
            foreach ($batch as $ls) $lesItems[] = $ls;
            if (count($batch) < $lesPer) break;          // страница неполная — она последняя
        }
        $gsched = [];   // group_id => набор слотов "деньНедели|начало|конец" (из фактических уроков)
        $hm = function ($v) { return preg_match('/(\d{1,2}:\d{2})/', (string)$v, $m) ? $m[1] : ''; };
        foreach ($lesItems as $ls) {
            $d = $ls['date'] ?? ($ls['lesson_date'] ?? null);
            if (!$inWin($d ? substr($d, 0, 10) : null)) continue;
            $dn = 0; if ($d) { $ts = strtotime(substr($d, 0, 10)); if ($ts) $dn = (int)date('N', $ts); }
            $slot = $dn . '|' . $hm($ls['time_from'] ?? '') . '|' . $hm($ls['time_to'] ?? '');
            $gs = (array)($ls['group_ids'] ?? []); if (isset($ls['group_id'])) $gs[] = $ls['group_id'];
            foreach ($gs as $g) { if (!$g) continue; $note($gid, $g, $d); if (!isset($gsched[$g])) $gsched[$g] = []; $gsched[$g][$slot] = ($gsched[$g][$slot] ?? 0) + 1; }
        }
        // расписание группы из слотов (самые частые сверху)
        $dnames = ['', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
        $schedOf = function ($id) use ($gsched, $dnames) {
            if (empty($gsched[$id])) return '';
            $slots = $gsched[$id]; arsort($slots);
            $out = [];
            foreach (array_keys($slots) as $slot) {
                [$dn, $f, $t] = array_pad(explode('|', $slot), 3, '');
                $s = trim(($dnames[(int)$dn] ?? '') . ' ' . $f . ($t ? '–' . $t : ''));
                if ($s !== '' && !in_array($s, $out, true)) $out[] = $s;
                if (count($out) >= 2) break;   // максимум 2 слота (напр. 2×/нед)
            }
            return implode(', ', $out);
        };
        // имена групп: сперва ОДИН общий запрос активных (быстро), затем точечно по id только
        // для тех, кого там нет (архивные) — так минимум запросов.
        $names = [];
        $grAll = alfa_http('POST', "$host/group/index", ['page' => 0, 'count' => 500], $token, true, 12);
        foreach (($grAll['items'] ?? []) as $g) { if (isset($g['id'])) $names[$g['id']] = $g['name'] ?? ('Группа #' . $g['id']); }
        $history = []; $miss = 0;
        foreach ($gid as $id => $dr) {
            if (isset($names[$id])) { $nm = $names[$id]; }
            elseif ($miss++ < 6) {                      // архивную группу тянем по id (не более 6)
                $gr = alfa_http('POST', "$host/group/index", ['id' => (int)$id, 'page' => 0], $token, true, 6);
                $nm = $gr['items'][0]['name'] ?? ('Группа #' . $id);
            } else { $nm = 'Группа #' . $id; }
            $history[] = ['group_id' => (int)$id, 'group' => $nm, 'b_date' => $dr['b'], 'e_date' => $dr['e'], 'sched' => $schedOf($id)];
        }
        // активные абонементы (действующие членства из cgi) — с именем и расписанием группы
        $active = [];
        foreach ($activeCgi as $agid => $adr) {
            $nm = $names[$agid] ?? null;
            if ($nm === null && $miss < 6) {   // добираем имя архивной/непопавшей в общий список группы
                $gr = alfa_http('POST', "$host/group/index", ['id' => (int)$agid, 'page' => 0], $token, true, 6); $miss++;
                $nm = $gr['items'][0]['name'] ?? ('Группа #' . $agid);
            }
            $active[] = ['group_id' => (int)$agid, 'group' => $nm ?: ('Группа #' . $agid),
                         'b_date' => $adr['b'], 'e_date' => $adr['e'], 'sched' => $schedOf($agid)];
        }
        // краткая сводка из карточки клиента
        $cu = alfa_http('POST', "$host/customer/index", ['id' => $cid, 'page' => 0], $token, true, 12);
        $c0 = $cu['items'][0] ?? [];
        // «Этап взаимодействия» (ЭВ) по годам — кастомные поля Alfa (в объекте клиента лежат как custom_<ключ>)
        $evKeys = ['evzz' => 'ЭВ 26/27', 'evz' => 'ЭВ 25/26', 'ev' => 'ЭВ 24/25', 'etapvzaimodeystviya' => 'ЭВ 23/24'];
        $custom = [];
        foreach ($evKeys as $k => $lab) {
            $v = $c0['custom_' . $k] ?? ($c0[$k] ?? null);
            $custom[$k] = is_scalar($v) ? trim((string)$v) : '';
        }
        // телефоны родителя — чтобы кнопка «⟳» у ребёнка могла подтянуть контакт точечно
        $ph0 = $c0['phone'] ?? [];
        if (is_string($ph0)) $ph0 = $ph0 === '' ? [] : [$ph0];
        $summary = [
            'name'        => $c0['name'] ?? '',
            'dob'         => $c0['dob'] ?? null,
            'parent'      => $c0['legal_name'] ?? null,   // «Заказчик» = родитель
            'phones'      => array_values(array_filter(array_map('strval', (array)$ph0))),
            'balance'     => $c0['balance'] ?? null,
            'last_attend' => $c0['last_attend_date'] ?? null,
            'paid_till'   => $c0['paid_till'] ?? null,
            'next_lesson' => $c0['next_lesson_date'] ?? null,
        ];
        // «Счета и абонементы» (карточка 🪪, только full=1). У ЭТОГО клуба абонементы-сущности
        // (customer-tariff) пустые — реальные «счета» лежат в ПЛАТЕЖАХ (pay): сумма (income),
        // пометка (note, напр. «майский»), дата (document_date). Абонементы/справочники в Alfa
        // привязаны к филиалу клиента, поэтому спрашиваем по всем его branch_ids.
        $subs = []; $subsArch = []; $tariffRaw = null; $tariffCount = 0; $payCount = 0; $ctDbg = []; $brList = []; $paySrc = false;
        if (!empty($in['full'])) {
            $custBr = [];
            $bids = $c0['branch_ids'] ?? ($c0['branch'] ?? null);
            if (is_array($bids)) { foreach ($bids as $bb) { $bb = (int)$bb; if ($bb) $custBr[$bb] = 1; } }
            elseif ($bids) { $custBr[(int)$bids] = 1; }
            if (!$custBr) $custBr[alfa_branch()] = 1;
            $brList = array_keys($custBr);
            $numN = fn($v) => ($v === null || $v === '' || !is_numeric($v)) ? null : (0 + $v);
            $isoOf = function ($d) { $d = (string)$d; if (preg_match('#^(\d{2})\.(\d{2})\.(\d{4})#', $d, $m)) return "$m[3]-$m[2]-$m[1]"; return substr($d, 0, 10); };

            // справочники: предметы (id→курс) и тарифы (id→название)
            $subjMap = [];
            $sj = alfa_http('POST', 'https://' . alfa_host() . '/v2api/' . $brList[0] . '/subject/index', ['page' => 0, 'count' => 500], $token, true, 8);
            foreach (($sj['items'] ?? []) as $sji) { if (isset($sji['id'])) $subjMap[(int)$sji['id']] = (string)($sji['name'] ?? ''); }
            $tarMap = [];
            $tr = alfa_http('POST', 'https://' . alfa_host() . '/v2api/' . $brList[0] . '/tariff/index', ['page' => 0, 'count' => 500], $token, true, 8);
            foreach (($tr['items'] ?? []) as $t2) { if (isset($t2['id'])) $tarMap[(int)$t2['id']] = (string)($t2['name'] ?? ''); }

            // АБОНЕМЕНТЫ клиента: customer-tariff/index — ⚠️ customer_id В URL (в body Alfa игнорит → пусто!).
            // Ответ несёт tariff_id (→ название через tariff/index), subject_ids (→ КУРС через subject/index),
            // balance/paid_count (остаток), b/e_date (dd.mm.yyyy), note («майский …»).
            $items = []; $seenT = [];
            foreach ($brList as $br) {
                $hb = 'https://' . alfa_host() . '/v2api/' . $br;
                $ct = alfa_http('POST', "$hb/customer-tariff/index?customer_id=" . $cid, ['page' => 0, 'count' => 100], $token, true, 8);
                $its = is_array($ct['items'] ?? null) ? $ct['items'] : [];
                // ⚠️ с customer_id в URL Alfa отдаёт одни и те же абонементы в КАЖДОМ филиале → дедуп по id записи
                foreach ($its as $t) { $tk = isset($t['id']) ? (int)$t['id'] : null; if ($tk !== null) { if (isset($seenT[$tk])) continue; $seenT[$tk] = 1; } $items[] = $t; }
                $ctDbg[] = ['branch' => $br, 'err' => $ct['__err'] ?? null, 'count' => count($its)];
            }

            // курсы по договору (кастомное поле клиента) — контекст
            $dogovora = [];
            $rawDog = $c0['custom_dogovora'] ?? '';
            if (is_array($rawDog)) $dogovora = $rawDog;
            elseif (is_string($rawDog) && trim($rawDog) !== '') { $dec = json_decode($rawDog, true); $dogovora = is_array($dec) ? $dec : preg_split('/[;\n]+/', $rawDog); }
            $dogovora = array_values(array_filter(array_map(fn($x) => trim((string)$x), (array)$dogovora)));
            $school = trim((string)($c0['custom_school'] ?? ''));
            $klass  = trim((string)($c0['custom_klass'] ?? ''));

            // разбор абонементов → subs (активные) / subsArch (архив, по e_date)
            $tariffCount = count($items);
            if ($tariffCount && is_array($items[0] ?? null)) $tariffRaw = $items[0];
            foreach ($items as $t) {
                if (!is_array($t)) continue;
                $tid = (int)($t['tariff_id'] ?? 0);
                $sids = $t['subject_ids'] ?? [];
                if (!is_array($sids)) $sids = ($sids === null || $sids === '') ? [] : [$sids];
                $subjNames = []; foreach ($sids as $sid) { $nm = $subjMap[(int)$sid] ?? ''; if ($nm !== '') $subjNames[] = $nm; }
                $bIso = $isoOf($t['b_date'] ?? '');
                $eIso = $isoOf($t['e_date'] ?? '');
                $note = is_scalar($t['note'] ?? null) ? trim((string)$t['note']) : '';
                $row = ['kind' => 'tariff', 'name' => $tarMap[$tid] ?? ('тариф #' . $tid),
                        'subject' => implode(', ', $subjNames), 'b_date' => $bIso, 'e_date' => $eIso,
                        'balance' => $numN($t['balance'] ?? null),
                        'lessons' => $numN($t['paid_count'] ?? ($t['paid_lesson_count'] ?? null)),
                        'note' => $note, 'may' => ($note !== '' && mb_stripos($note, 'майск') !== false)];
                if ($eIso === '' || $eIso >= $today) $subs[] = $row; else $subsArch[] = $row;
            }
            usort($subs, fn($a, $b) => strcmp((string)($b['e_date'] ?? ''), (string)($a['e_date'] ?? '')));
            usort($subsArch, fn($a, $b) => strcmp((string)($b['e_date'] ?? ''), (string)($a['e_date'] ?? '')));

            // КУПЛЕННЫЕ МАЙСКИЕ = абонементы с пометкой «май» — теперь С КУРСОМ (subject) и остатком
            $mayPays = [];
            foreach (array_merge($subs, $subsArch) as $s) {
                if (empty($s['may'])) continue;
                $mayPays[] = ['course' => $s['subject'], 'name' => $s['name'], 'amount' => $s['balance'],
                              'lessons' => $s['lessons'], 'note' => $s['note'], 'date' => $s['b_date'], 'e_date' => $s['e_date']];
            }
        }
        json_out(['ok' => true, 'customerId' => $cid, 'branch' => alfa_branch(), 'matched' => $matchedName,
                  'summary' => $summary, 'history' => $history, 'active' => $active, 'custom' => $custom,
                  'subs' => $subs, 'subsArch' => $subsArch, 'paySrc' => $paySrc,
                  'mayPays' => $mayPays ?? [], 'dogovora' => $dogovora ?? [], 'school' => $school ?? '', 'klass' => $klass ?? '', 'from' => $from, 'to' => $to,
                  'debug' => ['cgi' => count($cgiItems), 'lessons' => count($lesItems), 'groups' => count($gid), 'active' => count($active), 'today' => $today, 'tariffCount' => $tariffCount, 'payCount' => $payCount, 'custBranches' => $brList, 'ct' => $ctDbg]]);
        break;

    // --- СОЗДАТЬ НОВОГО КЛИЕНТА (ребёнка) в Alfa (WRITE) ---
    //     dryRun=true → только показать payload; dryRun=false → реально создать.
    case 'createCustomer':
        @set_time_limit(30);
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') json_out(['ok' => false, 'error' => 'Пустое ФИО ребёнка']);
        $parent = trim((string)($in['parentName'] ?? ''));
        $phone  = trim((string)($in['phone'] ?? ''));
        $dob    = trim((string)($in['dob'] ?? ''));      // приходит YYYY-MM-DD
        // Alfa ждёт дату в формате ДД.ММ.ГГГГ (как отдаёт сама) — конвертируем из input[type=date]
        if ($dob !== '' && preg_match('#^(\d{4})-(\d{2})-(\d{2})#', $dob, $dm)) $dob = "$dm[3].$dm[2].$dm[1]";
        $evzz   = trim((string)($in['evzz'] ?? ''));
        $branch = alfa_branch();

        // legal_type=1 — физлицо (обычно обязательно при создании клиента)
        $payload = ['name' => $name, 'branch_ids' => [$branch], 'is_study' => 1, 'legal_type' => 1];
        if ($phone  !== '') $payload['phone']       = [$phone];
        if ($dob    !== '') $payload['dob']         = $dob;
        if ($parent !== '') $payload['legal_name']  = $parent;   // «Заказчик» в Alfa
        if ($evzz   !== '') $payload['custom_evzz'] = $evzz;     // ЭВ 26/27

        // По умолчанию — СУХОЙ прогон. Реальное создание в CRM только при явном dryRun:false
        // (защита от случайного/повторного POST без флага).
        $live = array_key_exists('dryRun', $in) && $in['dryRun'] === false;
        if (!$live) {
            json_out(['ok' => true, 'dryRun' => true, 'payload' => $payload, 'branch' => $branch]);
        }
        $res   = alfa_create('customer', $payload);
        $newId = $res['id'] ?? ($res['model']['id'] ?? ($res['items'][0]['id'] ?? null));
        if ($newId === null) {
            json_out(['ok' => false, 'error' => 'Alfa не вернула id (проверьте поля).', 'payload' => $payload, 'raw' => $res]);
        }
        json_out(['ok' => true, 'created' => true, 'id' => $newId, 'branch' => $branch, 'payload' => $payload]);
        break;

    // --- СЫРАЯ КАРТОЧКА КЛИЕНТА (READ) — для сверки «какое поле означает архив» ---
    //     На вход id (или список ids). Отдаём запись КАК ЕСТЬ, со всеми полями Alfa.
    //     Приём: Жанна архивирует одного клиента руками в Alfa → сравниваем его с активным.
    case 'customerRaw':
        @set_time_limit(60);
        $ids = is_array($in['ids'] ?? null) ? $in['ids'] : [$in['customerId'] ?? 0];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) json_out(['ok' => false, 'error' => 'Не передан id клиента']);
        $out = [];
        foreach (array_slice($ids, 0, 10) as $id) {
            $br = null;
            $c  = alfa_customer_get((int)$id, $br);
            $out[(string)$id] = $c === null ? null : ['branch' => $br, 'customer' => $c];
        }
        json_out(['ok' => true, 'customers' => $out]);
        break;

    // --- АРХИВИРОВАТЬ КЛИЕНТА в Alfa (WRITE) — когда убираем ребёнка из нашей системы ---
    //     «Архив» в Alfa точно не подтверждён, поэтому:
    //       • какие поля ставить — задаёт клиент (mode=is_study|removed|both или готовый set{}),
    //       • update шлём ВМЕСТЕ с обязательными полями карточки (Alfa перезаписывает запись),
    //       • после записи ПЕРЕЧИТЫВАЕМ карточку и возвращаем before/after → видно, сработало ли.
    //     dryRun по умолчанию.
    case 'archiveCustomer':
        @set_time_limit(40);
        $cid = (int)($in['customerId'] ?? 0);
        if (!$cid) json_out(['ok' => false, 'error' => 'Не передан id клиента']);

        $branch = null;
        $before = alfa_customer_get($cid, $branch);
        if ($before === null) json_out(['ok' => false, 'error' => 'Клиент не найден в Alfa (id ' . $cid . ')']);

        $set = is_array($in['set'] ?? null) ? $in['set'] : [];
        if (!$set) {
            $mode = (string)($in['mode'] ?? 'is_study');
            if ($mode === 'removed')   $set = ['removed'  => 1];
            elseif ($mode === 'both')  $set = ['is_study' => 0, 'removed' => 1];
            else                       $set = ['is_study' => 0];
        }
        // Alfa update перезаписывает карточку целиком → переносим обязательные/значимые поля
        $keep = [];
        foreach (['name','legal_type','branch_ids','phone','dob','legal_name','note','assigned_id',
                  'lead_status_id','lead_source_id','study_status_id','color','is_study'] as $f) {
            if (isset($before[$f]) && $before[$f] !== '' && $before[$f] !== null) $keep[$f] = $before[$f];
        }
        if (empty($keep['branch_ids'])) $keep['branch_ids'] = [$branch ?: alfa_branch()];
        $payload = array_merge($keep, $set);

        $live = array_key_exists('dryRun', $in) && $in['dryRun'] === false;
        if (!$live) {
            json_out(['ok' => true, 'dryRun' => true, 'customerId' => $cid, 'branch' => $branch,
                      'set' => $set, 'payload' => $payload, 'before' => alfa_flags($before)]);
        }
        $res = alfa_http('POST', 'https://' . alfa_host() . '/v2api/' . ($branch ?: alfa_branch()) . '/customer/update?id=' . $cid,
                         $payload, alfa_token(), true, 15);
        // сверка по факту: перечитываем карточку и смотрим, встали ли нужные значения
        $after   = alfa_customer_get($cid);
        $changed = []; $okAll = $after !== null;
        foreach ($set as $k => $v) {
            $now = $after[$k] ?? null;
            $hit = $after !== null && (string)$now === (string)$v;
            if (!$hit) $okAll = false;
            $changed[$k] = ['want' => $v, 'now' => $now, 'ok' => $hit];
        }
        json_out(['ok' => true, 'archived' => $okAll, 'verified' => $after !== null, 'customerId' => $cid,
                  'branch' => $branch, 'set' => $set, 'changed' => $changed,
                  'before' => alfa_flags($before), 'after' => $after ? alfa_flags($after) : null,
                  'raw' => $res]);
        break;

    // --- ПЕРЕИМЕНОВАТЬ КЛИЕНТА в Alfa (WRITE) ---
    //     Интеграция amo→Alfa заводит клиента по НАЗВАНИЮ СДЕЛКИ («Арт-студия Половикова Арина»),
    //     а нужно чистое ФИО ребёнка. Меняем только поле name, остальные поля карточки переносим
    //     как есть (customer/update перезаписывает запись целиком) и перечитываем результат.
    case 'renameCustomer':
        @set_time_limit(40);
        $cid  = (int)($in['customerId'] ?? 0);
        $name = trim((string)($in['name'] ?? ''));
        if (!$cid)          json_out(['ok' => false, 'error' => 'Не передан id клиента']);
        if ($name === '')   json_out(['ok' => false, 'error' => 'Не передано новое имя']);

        $branch = null;
        $before = alfa_customer_get($cid, $branch);
        if ($before === null) json_out(['ok' => false, 'error' => 'Клиент не найден в Alfa (id ' . $cid . ')']);
        if (trim((string)($before['name'] ?? '')) === $name) {
            json_out(['ok' => true, 'skipped' => true, 'reason' => 'имя уже такое', 'id' => $cid, 'name' => $name]);
        }

        $keep = [];
        foreach (['legal_type','branch_ids','phone','dob','legal_name','note','assigned_id',
                  'lead_status_id','lead_source_id','study_status_id','color','is_study'] as $f) {
            if (isset($before[$f]) && $before[$f] !== '' && $before[$f] !== null) $keep[$f] = $before[$f];
        }
        if (empty($keep['branch_ids'])) $keep['branch_ids'] = [$branch ?: alfa_branch()];
        $payload = array_merge($keep, ['name' => $name]);

        $live = array_key_exists('dryRun', $in) && $in['dryRun'] === false;
        if (!$live) {
            json_out(['ok' => true, 'dryRun' => true, 'id' => $cid, 'was' => $before['name'] ?? '', 'will' => $name, 'payload' => $payload]);
        }
        $res   = alfa_http('POST', 'https://' . alfa_host() . '/v2api/' . ($branch ?: alfa_branch()) . '/customer/update?id=' . $cid,
                           $payload, alfa_token(), true, 15);
        $after = alfa_customer_get($cid);
        $now   = $after ? trim((string)($after['name'] ?? '')) : null;
        json_out(['ok' => true, 'renamed' => ($now === $name), 'id' => $cid,
                  'was' => $before['name'] ?? '', 'now' => $now, 'raw' => $res]);
        break;

    // --- ПОСЕЩЕНИЯ ЗА ПРОШЛЫЙ ГОД по клиентам (для «старый/новый») ---
    //     На вход список id (батч). Отдаём ТРИ счётчика на ребёнка, а правило выбирает клиент:
    //       t — все уроки в окне, d — со статусом «проведён», a — проведён И ребёнок присутствовал.
    //     Почему так: точные названия полей статуса/присутствия в этой Alfa не подтверждены,
    //     поэтому вместо одной догадки считаем все варианты + отдаём сырой образец урока в debug.
    //     Присутствие берём из lesson.details[] (участники урока) по customer_id — там оно и лежит.
    case 'visitcounts':
        @set_time_limit(180);
        $ids  = is_array($in['ids'] ?? null) ? array_values(array_unique(array_filter(array_map('intval', $in['ids'])))) : [];
        $from = (string)($in['from'] ?? '2025-09-01');
        $to   = (string)($in['to']   ?? '2026-05-31');
        $host = 'https://' . alfa_host() . '/v2api/' . alfa_branch();
        $token = alfa_token();
        $PER = 50; $MAX_PAGES = 6;      // Alfa отдаёт ≤50 на страницу; 300 уроков на ребёнка хватает
        // Порог «старого»: как только присутствий набралось столько, дальше считать незачем —
        // ответ «≥ порога» уже не изменится. На 1400 детей это экономит тысячи запросов.
        $TH = max(1, (int)($in['th'] ?? 0));

        $counts = [];                    // id => ['t'=>..,'d'=>..,'a'=>..]
        $dbg = ['ids' => count($ids), 'statusHist' => [], 'withDetails' => 0, 'noDetails' => 0,
                'sampleLesson' => null, 'sampleDetail' => null, 'sampleKeys' => null, 'detailKeys' => null];

        foreach ($ids as $cid) {
            $t = 0; $d = 0; $a = 0;
            for ($page = 0; $page < $MAX_PAGES; $page++) {
                $r = alfa_http('POST', "$host/lesson/index",
                    ['customer_id' => $cid, 'date_from' => $from, 'date_to' => $to,
                     'b_date' => $from, 'e_date' => $to, 'page' => $page, 'count' => $PER],
                    $token, true, 7);
                $items = isset($r['__err']) ? [] : ($r['items'] ?? []);
                foreach ($items as $ls) {
                    if (!is_array($ls)) continue;
                    if ($dbg['sampleLesson'] === null) { $dbg['sampleLesson'] = $ls; $dbg['sampleKeys'] = array_keys($ls); }

                    $dd = substr((string)($ls['date'] ?? ($ls['lesson_date'] ?? '')), 0, 10);
                    if ($dd !== '' && ($dd < $from || $dd > $to)) continue;      // строго в окне
                    $t++;

                    $st = isset($ls['status']) ? (int)$ls['status'] : -1;
                    $dbg['statusHist'][(string)$st] = ($dbg['statusHist'][(string)$st] ?? 0) + 1;
                    $done = ($st === -1) || ($st === 3);                          // 3 = «проведён» (если поля нет — считаем проведённым)
                    if (!$done) continue;
                    $d++;

                    // присутствие ИМЕННО этого ребёнка — из участников урока
                    $det = null;
                    foreach ((array)($ls['details'] ?? $ls['participants'] ?? []) as $p) {
                        if (is_array($p) && (int)($p['customer_id'] ?? 0) === $cid) { $det = $p; break; }
                    }
                    if ($det !== null) {
                        $dbg['withDetails']++;
                        if ($dbg['sampleDetail'] === null) { $dbg['sampleDetail'] = $det; $dbg['detailKeys'] = array_keys($det); }
                    } else { $dbg['noDetails']++; }

                    $src = $det ?? $ls;
                    $absent = (isset($src['is_attend'])  && !$src['is_attend'])
                           || (isset($src['is_present']) && !$src['is_present'])
                           || (isset($src['is_missed'])  &&  $src['is_missed']);
                    if (!$absent) $a++;
                }
                if (count($items) < $PER) break;
                if ($TH && $a >= $TH) break;      // порог взят (a ≤ d ≤ t, значит и они не меньше)
            }
            $counts[(string)$cid] = ['t' => $t, 'd' => $d, 'a' => $a];
        }
        json_out(['ok' => true, 'from' => $from, 'to' => $to, 'counts' => $counts, 'debug' => $dbg]);
        break;

    // --- справочники для маппинга модель→Alfa (READ) ---
    // Справочники собираем ПО ФИЛИАЛАМ: кабинеты (и часто педагоги) branch-scoped, поэтому
    // единый список из дефолтного филиала подсовывал чужие id. Клиент может сузить обход
    // параметром branches (напр. [1,2]) — меньше запросов, меньше риск таймаута шлюза.
    case 'refs':
        @set_time_limit(180);
        $brNames  = alfa_branch_names();
        $want     = array_values(array_filter(array_map('intval', (array)($in['branches'] ?? []))));
        $branches = $want ?: alfa_all_branch_ids();
        $map = ['subject'=>'subjects','room'=>'rooms','lesson-type'=>'lesson_types',
                'teacher'=>'teachers','group-status'=>'group_statuses','group-level'=>'group_levels'];
        $perBranch = ['rooms'=>1,'teachers'=>1];      // у этих запоминаем, из какого филиала запись
        $out = []; $seen = [];
        foreach ($map as $key) $out[$key] = [];
        foreach ($branches as $bid) {
            foreach ($map as $ent => $key) {
                $items = alfa_ref_branch((int)$bid, $ent);
                if ($items === null) continue;        // эндпоинта нет в v2 — это нормально
                foreach ($items as $it) {
                    $id = $it['id'] ?? null;
                    if ($id === null) continue;
                    $k = $key . '#' . $id;
                    if (isset($seen[$k])) continue;
                    $seen[$k] = true;
                    $row = ['id' => $id, 'name' => (string)($it['name'] ?? '')];
                    if (isset($perBranch[$key])) $row['branch'] = (int)$bid;
                    $out[$key][] = $row;
                }
            }
        }
        // Аудитория «Без локации» может не прийти в выборке по филиалу. Если её потерять,
        // кабинет молча сопоставится с ПОХОЖИМ («1 этаж №1» → «1 этаж №2») и группа уедет
        // не в тот кабинет. Поэтому добираем глобальным списком, помечая branch=0.
        $glob = alfa_try_index('room', true);
        if (is_array($glob)) {
            foreach ($glob as $it) {
                $id = $it['id'] ?? null;
                if ($id === null || isset($seen['rooms#' . $id])) continue;
                $seen['rooms#' . $id] = true;
                $out['rooms'][] = ['id' => $id, 'name' => (string)($it['name'] ?? ''), 'branch' => 0];
            }
        }
        foreach ($out as $k => $v) if (!$v) unset($out[$k]);   // пустые справочники не отдаём
        json_out(['ok' => true, 'branch' => alfa_branch(), 'branchNames' => $brNames,
                  'branchesUsed' => array_values($branches), 'refs' => $out]);
        break;

    // --- СПИСОК ГРУПП В ALFA (READ): id + имя + предмет + педагоги — чтобы клиент понял,
    //     какие группы уже есть, а какие надо создать. Активные и (мягко) архивные. ---
    // ⚠️ Те же грабли, что были с выгрузкой клиентов: Alfa отдаёт максимум ~50 записей на
    //    страницу, а группы лежат по ФИЛИАЛАМ. Просили по 100 и обходили один филиал → цикл
    //    обрывался после первой страницы, в выгрузку попадало ≤50 групп одного филиала,
    //    и почти все группы модели выглядели «новыми» (риск наделать дублей).
    case 'groupsList':
        @set_time_limit(180);
        $token = alfa_token();
        $host  = 'https://' . alfa_host();
        $perPage = 50;      // максимум страницы в Alfa
        $maxPages = 100;    // предохранитель на филиал
        $byId = []; $perBranch = []; $sample = null; $failed = [];
        $want = array_values(array_filter(array_map('intval', (array)($in['branches'] ?? []))));
        foreach ($want ?: alfa_all_branch_ids() as $bid) {
            $page = 0; $before = count($byId); $items = [];
            do {
                $r = alfa_http('POST', "$host/v2api/$bid/group/index", ['page' => $page, 'count' => $perPage], $token, true, 15);
                // ⚠️ сбой чтения раньше молча превращался в «в филиале нет групп» → все наши
                //    группы выглядели новыми → дубли в CRM. Теперь помечаем выгрузку неполной.
                if (isset($r['__err'])) { $failed[] = ['branch' => (int)$bid, 'page' => $page, 'why' => $r['__err']]; break; }
                $items = $r['items'] ?? [];
                foreach ($items as $g) {
                    $gid = (int)($g['id'] ?? 0);
                    if (!$gid || isset($byId[$gid])) continue;   // одна группа может встретиться в нескольких филиалах
                    if ($sample === null) $sample = $g;          // образец со ВСЕМИ полями — сверить имена полей
                    $byId[$gid] = [
                        'id'          => $gid,
                        'name'        => (string)($g['name'] ?? ''),
                        'branch'      => (int)$bid,
                        'subject_ids' => array_values(array_map('intval', (array)($g['subject_ids'] ?? []))),
                        'teacher_ids' => array_values(array_map('intval', (array)($g['teacher_ids'] ?? ($g['teachers'] ?? [])))),
                        'status_id'   => $g['status_id'] ?? null,
                        'level_id'    => $g['level_id'] ?? null,
                        'limit'       => $g['limit'] ?? null,
                        'note'        => $g['note'] ?? null,
                        'b_date'      => $g['b_date'] ?? null,
                        'e_date'      => $g['e_date'] ?? null,
                        'is_archive'  => (int)($g['is_archive'] ?? 0),
                    ];
                }
                $page++;
                // продолжаем, пока страница ПОЛНАЯ (total Alfa возвращает не всегда)
            } while (count($items) === $perPage && $page < $maxPages);
            $perBranch[$bid] = count($byId) - $before;
        }
        json_out(['ok' => true, 'branch' => alfa_branch(), 'branches' => $perBranch,
                  'branchNames' => alfa_branch_names(), 'incomplete' => !empty($failed), 'failed' => $failed,
                  'count' => count($byId), 'groups' => array_values($byId), 'sample' => $sample]);
        break;

    // --- ТАРИФЫ = ШАБЛОНЫ АБОНЕМЕНТОВ (READ). В Alfa «абонемент» — это ДВА объекта:
    //     tariff (шаблон: название, тип, цена, число уроков) и customer-tariff (выданный ученику).
    //     Здесь читаем шаблоны, чтобы сопоставить их с ценами из конструктора.
    case 'tariffs':
        @set_time_limit(90);
        $token = alfa_token();
        $host  = 'https://' . alfa_host();
        $want  = array_values(array_filter(array_map('intval', (array)($in['branches'] ?? []))));
        $byId = []; $sample = null;
        foreach ($want ?: alfa_all_branch_ids() as $bid) {
            $page = 0; $items = [];
            do {
                $r = alfa_http('POST', "$host/v2api/$bid/tariff/index", ['page' => $page, 'count' => 50], $token, true, 12);
                $items = isset($r['__err']) ? [] : ($r['items'] ?? []);
                foreach ($items as $t) {
                    $tid = (int)($t['id'] ?? 0);
                    if (!$tid || isset($byId[$tid])) continue;
                    if ($sample === null) $sample = $t;
                    $byId[$tid] = [
                        'id'           => $tid,
                        'name'         => (string)($t['name'] ?? ''),
                        'type'         => $t['type'] ?? ($t['tariff_type'] ?? null),   // 1 поурочный, 2 помесячный, 3 недельный
                        // имя поля цены в v2api не описано — отдаём первое непустое из вероятных,
                        // а разбирает значение уже клиент (Alfa шлёт строкой, бывает с запятой)
                        'price'        => $t['price'] ?? ($t['cost'] ?? ($t['amount'] ?? ($t['sum'] ?? null))),
                        'lesson_count' => $t['lesson_count'] ?? ($t['lessons_count'] ?? ($t['count'] ?? null)),
                        'duration'     => $t['duration'] ?? null,
                        'branch'       => (int)$bid,
                        'branch_ids'   => array_values(array_map('intval', (array)($t['branch_ids'] ?? []))),
                        'subject_ids'  => array_values(array_map('intval', (array)($t['subject_ids'] ?? []))),
                        'is_archive'   => (int)($t['is_archive'] ?? 0),
                    ];
                }
                $page++;
            } while (count($items) === 50 && $page < 40);
        }
        json_out(['ok' => true, 'count' => count($byId), 'tariffs' => array_values($byId), 'sample' => $sample]);
        break;

    // --- АБОНЕМЕНТЫ РЕБЁНКА (READ): что у него уже выдано, чтобы не выдать второй раз ---
    //     Принимает и одного (customerId), и список (customerIds) — модели нужен сразу весь
    //     состав группы: иначе «у кого абонемент есть» было видно ТОЛЬКО в отказе после
    //     попытки выдать, и ребёнок без абонемента ничем не отличался от ребёнка с ним.
    case 'customerTariffs':
        @set_time_limit(120);
        $ids = array_values(array_unique(array_filter(array_map('intval',
                   (array)($in['customerIds'] ?? [])), fn($x) => $x > 0)));
        if (!$ids && (int)($in['customerId'] ?? 0) > 0) $ids = [(int)$in['customerId']];
        if (!$ids) json_out(['ok' => false, 'error' => 'Не передан id клиента']);
        if (count($ids) > 80) $ids = array_slice($ids, 0, 80);   // страховка от долгого запроса
        $bid  = (int)($in['branch'] ?? 0) ?: alfa_branch();
        $subj = array_values(array_filter(array_map('intval', (array)($in['subjectIds'] ?? []))));
        $bIso = alfa_iso((string)($in['bDate'] ?? '')) ?: '';
        $map = [];
        foreach ($ids as $cid) {
            $r = alfa_customer_tariffs($bid, $cid);
            $rows = [];
            foreach ($r['items'] as $it) {
                $rows[] = ['id' => $it['id'] ?? null, 'tariff_id' => $it['tariff_id'] ?? null,
                           'subject_ids' => array_values(array_map('intval', (array)($it['subject_ids'] ?? []))),
                           'b_date' => $it['b_date_v'] ?? ($it['b_date'] ?? null),
                           'e_date' => $it['e_date_v'] ?? ($it['e_date'] ?? null),
                           'is_archive' => (int)($it['is_archive'] ?? 0),
                           'balance' => $it['balance'] ?? null, 'note' => $it['note'] ?? null];
            }
            // «действующий» считаем ровно теми же правилами, что и выдача, — иначе значок
            // в списке и решение прокси разошлись бы, а это худший вид вранья интерфейса
            $act = $subj ? alfa_tariff_active($r['items'], $subj, $bIso) : ['active' => null, 'until' => ''];
            $map[$cid] = ['ok' => $r['ok'], 'count' => count($rows), 'subs' => $rows,
                          'active' => $act['active'], 'until' => $act['until']];
        }
        $one = count($ids) === 1 ? $map[$ids[0]] : null;
        json_out(['ok' => true, 'branch' => $bid, 'ids' => $ids, 'byId' => $map]
                 + ($one ? ['customerId' => $ids[0], 'count' => $one['count'], 'subs' => $one['subs']] : []));
        break;

    // --- ВЫДАТЬ АБОНЕМЕНТ (WRITE, dryRun по умолчанию) ---
    //     Шаблон (tariff) заводится в самой Alfa — через API его создание не описано. Мы только
    //     ВЫДАЁМ уже существующий шаблон ребёнку: предмет = курс, период = учебный год.
    case 'giveTariff':
        @set_time_limit(180);
        $bid   = (int)($in['branch'] ?? 0) ?: alfa_branch();
        $items = is_array($in['items'] ?? null) ? $in['items'] : [];
        $bIso  = alfa_iso((string)($in['b_date'] ?? '2026-09-02'));
        $eIso  = alfa_iso((string)($in['e_date'] ?? '2027-05-31'));
        $sep   = !empty($in['separate']);              // раздельный счёт; по умолчанию базовый
        $note  = (string)($in['note'] ?? '');
        if (!$items) json_out(['ok' => false, 'error' => 'Некому выдавать']);

        $live = array_key_exists('dryRun', $in) && $in['dryRun'] === false;
        // Форму дат берём с РЕАЛЬНОГО абонемента (у майских он есть) — не угадываем.
        $shape = null; $existing = [];
        foreach ($items as $it) {
            $cid = (int)($it['customerId'] ?? 0); if (!$cid) continue;
            $ct = alfa_customer_tariffs($bid, $cid);
            $existing[$cid] = $ct['items'];
            if ($shape === null && $ct['items']) $shape = alfa_date_shape($ct['items'][0]);
        }
        if ($shape === null) $shape = ['field' => 'plain', 'fmt' => 'dmy', 'from' => 'docs'];

        $plan = []; $skipped = [];
        foreach ($items as $it) {
            $cid  = (int)($it['customerId'] ?? 0);
            $tid  = (int)($it['tariffId'] ?? 0);
            $subj = array_values(array_filter(array_map('intval', (array)($it['subjectIds'] ?? []))));
            $lt   = array_values(array_filter(array_map('intval', (array)($it['lessonTypeIds'] ?? []))));
            if (!$cid || !$tid || !$subj) { $skipped[] = ['customer_id' => $cid, 'why' => 'не хватает данных (клиент, шаблон или предмет)']; continue; }
            // период этого ребёнка нужен уже для проверки «есть ли действующий»
            $itB = alfa_iso((string)($it['bDate'] ?? '')) ?: $bIso;
            $itE = alfa_iso((string)($it['eDate'] ?? '')) ?: $eIso;
            /* Уже есть абонемент по этому предмету — второй не выдаём. ⚠️ Но «есть» означает
               ДЕЙСТВУЮЩИЙ на наш период: у ребёнка, который ходит не первый год, лежат
               прошлогодние абонементы (в карточке Alfa это «Архивные абонементы»). Раньше они
               тоже считались, и новый абонемент молча не выдавался — при том что действующих
               у ребёнка не было ни одного. */
            $act = alfa_tariff_active($existing[$cid] ?? [], $subj, $itB);
            if ($act['active']) {
                $skipped[] = ['customer_id' => $cid, 'have' => true,
                              'why' => 'действующий абонемент по этому курсу уже есть'
                                       . ($act['until'] !== '' ? (' (до ' . alfa_date($act['until']) . ')') : '')];
                continue;
            }
            // период можно задать НА КАЖДОГО: у купивших майский абонемент действует по майской
            // цене только до перехода на полную стоимость, у остальных — весь учебный год
            $body = array_merge(['customer_id' => $cid, 'tariff_id' => $tid, 'subject_ids' => $subj,
                                 'is_separate_balance' => $sep ? 1 : 0],
                                $lt ? ['lesson_type_ids' => $lt] : [],
                                $note !== '' ? ['note' => $note] : [],
                                alfa_shape_dates($shape, $itB, $itE));
            $plan[] = ['customer_id' => $cid, 'body' => $body];
        }
        if (!$live) json_out(['ok' => true, 'dryRun' => true, 'branch' => $bid, 'shape' => $shape,
                              'plan' => $plan, 'skipped' => $skipped]);

        $given = []; $errors = [];
        foreach ($plan as $p) {
            $res = alfa_tariff_give($bid, (int)$p['customer_id'], $p['body']);
            $nid = $res['id'] ?? ($res['model']['id'] ?? null);
            if ($nid) { $given[] = ['customer_id' => $p['customer_id'], 'tariff_row' => (int)$nid]; continue; }
            $errors[] = ['customer_id' => $p['customer_id'], 'why' => alfa_err_text($res), 'sent' => $p['body'], 'alfa' => $res];
        }
        json_out(['ok' => true, 'dryRun' => false, 'branch' => $bid, 'shape' => $shape,
                  'given' => $given, 'skipped' => $skipped, 'errors' => $errors]);
        break;

    // --- СОСТАВ ГРУППЫ (READ): кто уже привязан к группе в Alfa (сущность cgi).
    //     ⚠️ Дефолтный запрос отдаёт в основном ТЕКУЩИЕ членства. Наши — с 02.09.2026, т.е. БУДУЩИЕ,
    //     и в дефолт не попадают: без второго запроса с диапазоном мы бы считали группу пустой
    //     и добавляли одних и тех же детей повторно.
    case 'groupMembers':
        @set_time_limit(60);
        $gid = (int)($in['groupId'] ?? 0);
        if ($gid <= 0) json_out(['ok' => false, 'error' => 'Не передан id группы']);
        // филиал можно не передавать — найдём сам по группе (членства branch-scoped)
        $bid = (int)($in['branch'] ?? 0) ?: (alfa_group_branch($gid) ?: alfa_branch());
        $today = date('Y-m-d');
        // окно берём из запроса: период группы задаёт пользователь, жёсткая дата отсекала бы
        // членства, начинающиеся позже, и мы сочли бы их отсутствующими
        $winTo = alfa_iso((string)($in['e_date'] ?? '')) ?: '2027-08-31';
        if ($winTo < $today) $winTo = '2027-08-31';
        $seen = []; $members = []; $readOk = false;
        foreach ([
            ['group_id' => $gid],
            ['group_id' => $gid, 'date_from' => $today, 'date_to' => $winTo, 'b_date' => $today, 'e_date' => $winTo],
        ] as $q) {
            $r = alfa_index_all($bid, 'cgi', $q, 20, 12);
            if ($r['ok']) $readOk = true;                                // хотя бы один заход удался
            foreach ($r['items'] as $it) {
                if ((int)($it['group_id'] ?? 0) !== $gid) continue;      // Alfa может проигнорировать фильтр
                $cid = (int)($it['customer_id'] ?? 0);
                if (!$cid || isset($seen[$cid])) continue;
                $seen[$cid] = 1;
                $members[] = ['id' => $it['id'] ?? null, 'customer_id' => $cid,
                              'b_date' => $it['b_date'] ?? null, 'e_date' => $it['e_date'] ?? null];
            }
        }
        // ⚠️ «не прочитали» ≠ «никого нет»: на пустом составе клиент предлагает добавить всех
        if (!$readOk) json_out(['ok' => false, 'error' => 'Не удалось прочитать состав группы в Alfa — попробуйте ещё раз (добавлять вслепую нельзя: получатся дубли).'], 502);
        json_out(['ok' => true, 'groupId' => $gid, 'branch' => $bid, 'count' => count($members), 'members' => $members]);
        break;

    // --- ВСЕ ЧЛЕНСТВА СРАЗУ (READ): cgi по всем филиалам одним обходом, сгруппировано по group_id.
    //     Зачем отдельно от groupMembers: печать составов на 100+ групп по одному запросу на группу
    //     = 200+ обращений к Alfa пачкой → таймауты шлюза/лимиты, всё падает. Здесь один обход с
    //     пагинацией (как customers/groupsList) — и быстро, и надёжно. Наши членства с 02.09 —
    //     БУДУЩИЕ, дефолт их не отдаёт, поэтому второй заход с диапазоном дат (как в groupMembers).
    case 'membershipsAll':
        @set_time_limit(240);
        $today = date('Y-m-d');
        $winTo = alfa_iso((string)($in['e_date'] ?? '')) ?: '2027-08-31';
        if ($winTo < $today) $winTo = '2027-08-31';
        $want = array_values(array_filter(array_map('intval', (array)($in['branches'] ?? []))));
        $branches = $want ?: alfa_all_branch_ids();
        $token = alfa_token(); $host = 'https://' . alfa_host();
        $per = 50; $maxPages = 400;
        $seen = []; $byGroup = []; $readOk = false; $scanned = 0; $perBranch = []; $failed = [];
        foreach ($branches as $bid) {
            $before = count($seen);
            foreach ([
                [],
                ['date_from' => $today, 'date_to' => $winTo, 'b_date' => $today, 'e_date' => $winTo],
            ] as $q) {
                $page = 0; $items = [];
                do {
                    $r = alfa_http('POST', "$host/v2api/$bid/cgi/index", array_merge($q, ['page' => $page, 'count' => $per]), $token, true, 20);
                    if (isset($r['__err'])) { $failed[] = ['branch' => (int)$bid, 'page' => $page, 'why' => $r['__err']]; break; }
                    $readOk = true;
                    $items = $r['items'] ?? [];
                    foreach ($items as $it) {
                        $scanned++;
                        $cid = (int)($it['customer_id'] ?? 0); $g = (int)($it['group_id'] ?? 0);
                        if (!$cid || !$g) continue;
                        $key = $g . ':' . $cid;
                        if (isset($seen[$key])) continue;   // одна связь может прийти в обоих заходах / филиалах
                        $seen[$key] = 1;
                        $byGroup[(string)$g][] = ['customer_id' => $cid, 'b_date' => $it['b_date'] ?? null, 'e_date' => $it['e_date'] ?? null];
                    }
                    $page++;
                } while (count($items) === $per && $page < $maxPages);
            }
            $perBranch[$bid] = count($seen) - $before;
        }
        if (!$readOk) json_out(['ok' => false, 'error' => 'Не удалось прочитать членства (cgi) из Alfa — попробуйте ещё раз.'], 502);
        json_out(['ok' => true, 'groups' => count($byGroup), 'links' => count($seen), 'scanned' => $scanned,
                  'perBranch' => $perBranch, 'incomplete' => !empty($failed), 'failed' => $failed, 'byGroup' => $byGroup]);
        break;

    // --- ДОБАВИТЬ ДЕТЕЙ В ГРУППУ (WRITE, dryRun по умолчанию). Сущность cgi = членство:
    //     customer_id, group_id, b_date, e_date — и всё. Период участия задаём как у группы.
    case 'addToGroup':
        @set_time_limit(180);
        $gid  = (int)($in['groupId'] ?? 0);
        // филиал можно не передавать (из карточки занятия он неизвестен) — определяем по группе
        $bid  = (int)($in['branch'] ?? 0) ?: (alfa_group_branch($gid) ?: alfa_branch());
        $ids  = array_values(array_unique(array_filter(array_map('intval', (array)($in['customerIds'] ?? [])))));
        $bIso = alfa_iso((string)($in['b_date'] ?? '2026-09-02'));
        $eIso = alfa_iso((string)($in['e_date'] ?? '2027-05-31'));
        if ($gid <= 0) json_out(['ok' => false, 'error' => 'Не передан id группы']);
        if (!$ids)     json_out(['ok' => false, 'error' => 'Не переданы дети']);

        $live = array_key_exists('dryRun', $in) && $in['dryRun'] === false;
        if (!$live) {
            json_out(['ok' => true, 'dryRun' => true, 'groupId' => $gid, 'branch' => $bid, 'count' => count($ids),
                      'sample' => ['customer_id' => $ids[0], 'group_id' => $gid,
                                   'b_date' => alfa_date($bIso), 'e_date' => alfa_date($eIso)]]);
        }
        /* Ребёнок может УЖЕ состоять в этой группе — прошлым учебным годом, запись архивная
           (в карточке группы она зачёркнута). Alfa второе членство создать не даёт:
           «Данный клиент уже состоит в этой группе». Поэтому: нашли существующее — ПРОДЛЕВАЕМ
           срок до нового конца (начало не трогаем, чтобы не стереть «ходит с 2025 года»),
           не нашли — создаём. Формат полей периода берём по реальной записи этой CRM. */
        $sh = alfa_cgi_shape($bid);
        $added = []; $extended = []; $errors = [];
        foreach ($ids as $cid) {
            $findDbg = null;
            $cur = alfa_cgi_find($bid, $cid, $gid, $findDbg);
            if ($cur) {
                // членство могло найтись в другом филиале — продлевать надо там же
                $res = alfa_cgi_extend((int)($cur['__branch'] ?? $bid), $cur, $eIso, $sh);
                $okUpd = !empty($res['id']) || !empty($res['model']['id']);
                if ($okUpd) $extended[] = ['customer_id' => $cid, 'cgi_id' => (int)($cur['id'] ?? 0)];
                else $errors[] = ['customer_id' => $cid, 'step' => 'продление', 'why' => alfa_err_text($res),
                                  'was' => ['b' => $cur['b_date'] ?? null, 'e' => $cur['e_date'] ?? null], 'alfa' => $res];
                continue;
            }
            $body = array_merge(['customer_id' => $cid, 'group_id' => $gid], alfa_cgi_dates($sh, $bIso, $eIso));
            $res = alfa_cgi_create($bid, $cid, $gid, $body);
            $nid = $res['id'] ?? ($res['model']['id'] ?? null);
            if ($nid) { $added[] = ['customer_id' => $cid, 'cgi_id' => (int)$nid]; continue; }
            // Alfa всё-таки нашла членство, которого не отдал поиск — пробуем продлить
            if (preg_match('/уже состоит/ui', alfa_err_text($res))) {
                $dbg2 = null;
                $cur2 = alfa_cgi_find($bid, $cid, $gid, $dbg2);
                if ($cur2) {
                    $res2 = alfa_cgi_extend((int)($cur2['__branch'] ?? $bid), $cur2, $eIso, $sh);
                    if (!empty($res2['id']) || !empty($res2['model']['id'])) { $extended[] = ['customer_id' => $cid, 'cgi_id' => (int)($cur2['id'] ?? 0)]; continue; }
                    $errors[] = ['customer_id' => $cid, 'step' => 'продление', 'why' => alfa_err_text($res2), 'alfa' => $res2];
                    continue;
                }
                $errors[] = ['customer_id' => $cid, 'step' => 'создание',
                             'why' => 'Ребёнок числится в этой группе, но Alfa не отдаёт саму запись ни одним из запросов (архивные членства она скрывает). Пока продлите срок вручную в карточке группы — диагностика в консоли (F12)',
                             'find' => $dbg2 ?: $findDbg, 'alfa' => $res];
                continue;
            }
            $errors[] = ['customer_id' => $cid, 'step' => 'создание', 'why' => alfa_err_text($res), 'sent' => $body,
                         'url' => "/v2api/$bid/cgi/create?customer_id=$cid&group_id=$gid", 'alfa' => $res];
        }
        json_out(['ok' => true, 'dryRun' => false, 'groupId' => $gid, 'branch' => $bid, 'shape' => $sh,
                  'added' => $added, 'extended' => $extended, 'errors' => $errors]);
        break;

    // --- ГРУППА ПО ID (READ). Связать нашу группу с альфовской можно по id, который Жанна видит
    //     в адресе карточки. Ищем по всем филиалам: группа может лежать не в дефолтном, а также
    //     не попасть в общую выгрузку (архив/другой филиал) — тогда по имени её не найти вовсе.
    case 'groupById':
        @set_time_limit(60);
        $gid = (int)($in['groupId'] ?? 0);
        if ($gid <= 0) json_out(['ok' => false, 'error' => 'Не передан id группы']);
        $want = array_values(array_filter(array_map('intval', (array)($in['branches'] ?? []))));
        $found = null;
        foreach ($want ?: alfa_all_branch_ids() as $bid) {
            $r = alfa_http('POST', 'https://' . alfa_host() . "/v2api/$bid/group/index",
                           ['id' => $gid, 'page' => 0, 'count' => 5], alfa_token(), true, 12);
            foreach (($r['items'] ?? []) as $row) {
                if ((int)($row['id'] ?? 0) !== $gid) continue;      // Alfa может проигнорировать фильтр
                $found = ['id' => $gid, 'name' => (string)($row['name'] ?? ''), 'branch' => (int)$bid,
                          'subject_ids' => array_values(array_map('intval', (array)($row['subject_ids'] ?? []))),
                          'teacher_ids' => array_values(array_map('intval', (array)($row['teacher_ids'] ?? ($row['teachers'] ?? [])))),
                          'status_id' => $row['status_id'] ?? null, 'level_id' => $row['level_id'] ?? null,
                          'limit' => $row['limit'] ?? null, 'note' => $row['note'] ?? null,
                          'b_date' => $row['b_date'] ?? null, 'e_date' => $row['e_date'] ?? null,
                          'is_archive' => (int)($row['is_archive'] ?? 0)];
                break 2;
            }
        }
        if (!$found) json_out(['ok' => false, 'error' => 'Группа с id ' . $gid . ' в Alfa не найдена (проверьте номер и филиалы)']);
        json_out(['ok' => true, 'group' => $found]);
        break;

    // --- РЕГУЛЯРНОЕ РАСПИСАНИЕ УЖЕ СУЩЕСТВУЮЩИХ ГРУПП (READ). Нужно, чтобы БЕЗ пробной записи
    //     сверить нумерацию дня недели в Alfa и реальные имена полей (day / time_from_v / ...). ---
    case 'regularLessons':
        @set_time_limit(60);
        $gid   = (int)($in['groupId'] ?? 0);
        // группа может лежать не в дефолтном филиале — клиент передаёт её филиал из groupsList
        $bid   = (int)($in['branch'] ?? 0) ?: alfa_branch();
        $host  = 'https://' . alfa_host() . '/v2api/' . $bid;
        $token = alfa_token();
        $perPage = 50;                      // Alfa режет страницу; просить 100 бессмысленно
        $body  = ['count' => $perPage];
        if ($gid > 0) { $body['related_class'] = 'Group'; $body['related_id'] = $gid; }
        $out = []; $sample = null; $page = 0; $items = [];
        do {
            $body['page'] = $page;
            $r = alfa_http('POST', "$host/regular-lesson/index", $body, $token, true, 12);
            $items = isset($r['__err']) ? [] : ($r['items'] ?? []);
            foreach ($items as $rl) {
                // Alfa может проигнорировать фильтр в теле — отсекаем чужие уроки сами
                if ($gid > 0 && (int)($rl['related_id'] ?? 0) !== $gid) continue;
                if ($sample === null) $sample = $rl;
                $out[] = [
                    'id'             => $rl['id'] ?? null,
                    'related_id'     => $rl['related_id'] ?? null,
                    'subject_id'     => $rl['subject_id'] ?? null,
                    'room_id'        => $rl['room_id'] ?? null,
                    'teacher_ids'    => array_values(array_map('intval', (array)($rl['teacher_ids'] ?? []))),
                    'day'            => $rl['day'] ?? null,
                    'time_from'      => $rl['time_from_v'] ?? ($rl['time_from'] ?? null),
                    'time_to'        => $rl['time_to_v'] ?? ($rl['time_to'] ?? null),
                    'lesson_type_id' => $rl['lesson_type_id'] ?? null,
                    'b_date'         => $rl['b_date'] ?? null,
                    'e_date'         => $rl['e_date'] ?? null,
                ];
            }
            $page++;
            // ⚠️ раньше страницы листались ТОЛЬКО без groupId, то есть в боевом режиме читалась одна
            //    страница. Если Alfa игнорит фильтр в теле, на ней лежат чужие занятия филиала, свои
            //    отсекаются — и «нумерация дня» определялась по пустоте.
        } while (count($items) === $perPage && $page < 40);
        json_out(['ok' => true, 'branch' => $bid, 'count' => count($out), 'lessons' => $out, 'sample' => $sample]);
        break;

    // --- публикация ОДНОЙ группы: dryRun=true по умолчанию (ничего не создаёт) ---
    //  groupId > 0  → группа в Alfa УЖЕ ЕСТЬ: новую НЕ создаём, только дописываем ей расписание.
    //  Повтор безопасен: слоты, которые в расписании Alfa уже стоят (день+время), пропускаем —
    //  иначе вторая публикация той же группы дала бы дубли занятий.
    case 'publish':
        @set_time_limit(120);
        $dry      = !isset($in['dryRun']) || $in['dryRun'] !== false;
        $g        = is_array($in['group'] ?? null) ? $in['group'] : [];
        $sched    = is_array($in['schedule'] ?? null) ? $in['schedule'] : [];
        $students = is_array($in['studentAlfaIds'] ?? null) ? $in['studentAlfaIds'] : [];
        // Филиал ВЫБИРАЕТ клиент: кабинеты branch-scoped, и группа обязана лечь в тот филиал,
        // которому принадлежит её кабинет. Раньше молча писали в авто-определённый филиал.
        $branch   = (int)($in['branch'] ?? 0) ?: alfa_branch();
        $bIso     = alfa_iso((string)($in['b_date'] ?? '2026-09-02'));
        $eIso     = alfa_iso((string)($in['e_date'] ?? '2027-05-31'));
        $groupId  = (int)($in['groupId'] ?? 0);
        $updGroup = !empty($in['updateGroup']);   // прописать нашего педагога/даты в карточку существующей группы

        // формат дат группы — по реальной группе этой CRM (документация Alfa: ДД.ММ.ГГГГ)
        $gFmt  = alfa_group_date_fmt($branch);
        $bDate = $gFmt === 'iso' ? $bIso : alfa_date($bIso);
        $eDate = $gFmt === 'iso' ? $eIso : alfa_date($eIso);
        // форма полей регулярного занятия — по реальной записи этой же CRM, иначе по документации
        $rlShape = alfa_rl_shape(alfa_rl_sample($branch));

        $groupPayload = array_merge(
            ['name' => (string)($g['name'] ?? ''), 'branch_ids' => [$branch], 'b_date' => $bDate, 'e_date' => $eDate],
            array_intersect_key($g, array_flip(['teacher_ids','level_id','status_id','limit','note','subject_ids']))
        );

        // Что у этой группы уже стоит в регулярном расписании Alfa. На этом держится обещание
        // «повторная публикация не плодит дубли», поэтому читаем ЧЕСТНО: с пагинацией и с
        // различением «занятий нет» и «прочитать не удалось». Раньше любая ошибка чтения давала
        // пустой список → все занятия создавались заново.
        $have = []; $haveOk = true;
        if ($groupId > 0) {
            $rr = alfa_index_all($branch, 'regular-lesson', ['related_class' => 'Group', 'related_id' => $groupId], 40, 12);
            $haveOk = $rr['ok'];
            foreach ($rr['items'] as $rl) {
                if ((int)($rl['related_id'] ?? 0) !== $groupId) continue;   // Alfa может проигнорировать фильтр
                $have[] = ['id'   => $rl['id'] ?? null,
                           'day'  => $rl['day'] ?? null,
                           'time' => alfa_hm((string)($rl['time_from_v'] ?? ''))];   // «9:00» и «09:00:00» — одно и то же
            }
        }

        $plan = ['branch' => $branch, 'groupId' => $groupId ?: null, 'mode' => ($groupId ? 'schedule' : 'create'),
                 'group' => ($groupId ? null : $groupPayload), 'schedule' => [], 'skipped' => [],
                 'links' => [], 'have' => $have];
        foreach ($sched as $s) {
            $day = $s['day'] ?? null;
            $tf  = alfa_hm((string)($s['time_from'] ?? ''));
            $dup = null;
            foreach ($have as $h) { if ((string)$h['day'] === (string)$day && $h['time'] === $tf) { $dup = $h['id']; break; } }
            if ($dup !== null) { $plan['skipped'][] = ['day' => $day, 'time' => $tf, 'lesson_id' => $dup]; continue; }
            $slot = ['day' => $day, 'subject_id' => $s['subject_id'] ?? null, 'room_id' => $s['room_id'] ?? null,
                     'teacher_ids' => $s['teacher_ids'] ?? ($g['teacher_ids'] ?? []),
                     'lesson_type_id' => $s['lesson_type_id'] ?? null,
                     'time_from' => $s['time_from'] ?? '', 'time_to' => $s['time_to'] ?? ''];
            $plan['schedule'][] = alfa_rl_body($slot, $rlShape, $bIso, $eIso, $branch, ($groupId ?: '<group_id>'));
        }
        foreach ($students as $cid) $plan['links'][] = ['customer_id' => $cid, 'group_id' => ($groupId ?: '<group_id>'), 'b_date' => $bDate, 'e_date' => $eDate];

        $plan['shape'] = $rlShape; $plan['groupDateFmt'] = $gFmt; $plan['haveOk'] = $haveOk;
        if ($dry) { json_out(['ok' => true, 'dryRun' => true, 'plan' => $plan]); }

        // Не смогли прочитать существующее расписание — значит не знаем, что уже стоит.
        // Записывать вслепую нельзя: получим второй комплект занятий у той же группы.
        if ($groupId > 0 && !$haveOk) {
            json_out(['ok' => false, 'error' => 'Не удалось прочитать текущее расписание группы в Alfa — публикация отменена, чтобы не создать дубли занятий. Попробуйте ещё раз.'], 502);
        }

        // ЖИВАЯ запись (только при явном dryRun:false) — строго в выбранном филиале
        $created = ['group_id' => (int)$groupId, 'branch' => $branch, 'createdGroup' => false,
                    'lessons' => [], 'skipped' => count($plan['skipped']), 'links' => [], 'errors' => [],
                    'shape' => $rlShape, 'groupDateFmt' => $gFmt];
        $gid = $groupId;
        if (!$gid) {
            // формат дат определяли по тому, как Alfa их ОТДАЁТ; на запись он может отличаться —
            // если не приняла, пробуем второй, а не сдаёмся с невнятным «не вернула id»
            $tries = [];
            foreach ([$gFmt, ($gFmt === 'iso' ? 'dmy' : 'iso')] as $f) {
                $p = $groupPayload;
                $p['b_date'] = $f === 'iso' ? $bIso : alfa_date($bIso);
                $p['e_date'] = $f === 'iso' ? $eIso : alfa_date($eIso);
                $gr  = alfa_call_branch($branch, 'group', 'create', $p);
                $gid = $gr['id'] ?? ($gr['model']['id'] ?? null);
                if ($gid) { $created['groupDateFmt'] = $f; break; }
                $tries[] = ['format' => $f, 'why' => alfa_err_text($gr), 'sent' => $p, 'alfa' => $gr];
            }
            if (!$gid) {
                $det = [];
                foreach ($tries as $t) $det[] = $t['format'] . ' → ' . ($t['why'] !== '' ? $t['why'] : 'ответ без описания');
                json_out(['ok' => false, 'error' => 'AlfaCRM не создала группу: ' . implode(' | ', $det),
                          'branch' => $branch, 'sent' => $tries ? $tries[0]['sent'] : $groupPayload,
                          'alfa' => $tries ? $tries[0]['alfa'] : null, 'tries' => $tries], 502);
            }
            $created['group_id'] = (int)$gid; $created['createdGroup'] = true;
        } elseif ($updGroup) {
            // Alfa update перезаписывает запись ЦЕЛИКОМ → читаем карточку и меняем только своё
            $cur = alfa_http('POST', 'https://' . alfa_host() . "/v2api/$branch/group/index",
                             ['id' => $gid, 'count' => 50, 'page' => 0], alfa_token(), true, 12);
            // ⚠️ Alfa местами ИГНОРИРУЕТ фильтр в теле — брать items[0] вслепую нельзя: перезаписали
            //    бы чужую группу её же полями. Ищем нужный id, не нашли — карточку не трогаем.
            $row = null;
            foreach (($cur['items'] ?? []) as $cand) {
                if ((int)($cand['id'] ?? 0) === (int)$gid) { $row = $cand; break; }
            }
            if (!is_array($row)) {
                $created['errors'][] = ['step' => 'карточка группы (не удалось прочитать — не меняли)', 'alfa' => $cur];
            } else {
                $keep = [];
                foreach (['name','branch_ids','subject_ids','teacher_ids','level_id','status_id','limit','note','b_date','e_date','is_archive'] as $f) {
                    if (isset($row[$f]) && $row[$f] !== '' && $row[$f] !== null) $keep[$f] = $row[$f];
                }
                if (!empty($g['teacher_ids'])) $keep['teacher_ids'] = $g['teacher_ids'];
                $keep['b_date'] = $bDate; $keep['e_date'] = $eDate;
                if (empty($keep['branch_ids'])) $keep['branch_ids'] = [$branch];
                $res = alfa_http('POST', 'https://' . alfa_host() . "/v2api/$branch/group/update?id=" . (int)$gid,
                                 $keep, alfa_token(), true, 15);
                if (empty($res['id']) && empty($res['model']['id'])) $created['errors'][] = ['step' => 'карточка группы', 'why' => alfa_err_text($res), 'sent' => $keep, 'alfa' => $res];
                else $created['groupUpdated'] = true;
            }
        }
        // Дальше ошибки НЕ обрываем, а собираем: клиенту важно записать group_id (иначе при
        // повторе создастся дубль), а недоделанное показать списком.
        foreach ($plan['schedule'] as $i => $rl) {
            $rl['related_id'] = (int)$gid;
            $res = alfa_call_branch($branch, 'regular-lesson', 'create', $rl);
            $rid = $res['id'] ?? ($res['model']['id'] ?? null);
            if ($rid) $created['lessons'][] = (int)$rid;
            else      $created['errors'][] = ['step' => 'расписание #' . ($i + 1), 'why' => alfa_err_text($res), 'sent' => $rl, 'alfa' => $res];
        }
        foreach ($students as $cid) {
            // id — и в адрес, и в тело: Alfa на cgi/create читает их из адреса (см. alfa_cgi_create)
            $res = alfa_cgi_create($branch, (int)$cid, (int)$gid,
                     ['customer_id' => (int)$cid, 'group_id' => (int)$gid, 'b_date' => $bDate, 'e_date' => $eDate]);
            $lid = $res['id'] ?? ($res['model']['id'] ?? null);
            if ($lid) $created['links'][] = (int)$cid;
            else      $created['errors'][] = ['step' => 'ученик ' . $cid, 'why' => alfa_err_text($res), 'alfa' => $res];
        }
        json_out(['ok' => true, 'dryRun' => false, 'partial' => !empty($created['errors']), 'created' => $created]);
        break;

    // --- майские из Alfa: ПЛАТЕЖИ (pay) с пометкой «май» → кто купил; курс — из custom_dogovora клиента ---
    //   Абонемента как такового в Alfa нет: майский = платёж-доход с note ~ «май»/«майский».
    case 'maysubs':
        @set_time_limit(180);
        $kw   = mb_strtolower(trim((string)($in['keyword'] ?? 'май')));   // метка в примечании платежа
        $from = (string)($in['from'] ?? '2026-05-01');                    // окно оплаты майского
        $to   = (string)($in['to']   ?? '2026-06-30');
        $allBranches = !isset($in['allBranches']) || $in['allBranches'] !== false;   // по умолчанию все филиалы
        $branches = $allBranches ? alfa_all_branch_ids() : [alfa_branch()];
        $token = alfa_token(); $host = 'https://' . alfa_host();
        $perPage = 50; $maxPages = 400; $maxScan = 20000;

        // 1) карта клиентов: id → {имя, курс(ы) из custom_dogovora}
        $custMap = [];
        foreach ($branches as $bid) {
            $page = 0;
            do {
                $r = alfa_http('POST', "$host/v2api/$bid/customer/index",
                    ['removed' => 0, 'page' => $page, 'count' => $perPage], $token, true, 20);
                if (isset($r['__err'])) break;
                $items = $r['items'] ?? [];
                foreach ($items as $c) {
                    $id = (int)($c['id'] ?? 0); if (!$id || isset($custMap[$id])) continue;
                    $dog = $c['custom_dogovora'] ?? [];
                    if (is_string($dog)) $dog = ($dog === '') ? [] : [$dog];
                    $custMap[$id] = ['name' => trim((string)($c['name'] ?? '')), 'dogovora' => array_values((array)$dog)];
                }
                $page++;
                // ⚠️ выход по `total` был ловушкой: Alfa отдаёт это поле НЕ всегда, а при его отсутствии
                //    $total=0 и условие `0 < 0` ложно — читалась только ПЕРВАЯ страница (50 клиентов
                //    на филиал), и у большинства майских не находилось ни имени, ни курса по договору.
            } while (count($items) === $perPage && $page < $maxPages);
        }

        // 2) honorит ли Alfa фильтр дат pay? (чтобы не сканировать все платежи за годы)
        $useDates = false;
        $t = alfa_http('POST', "$host/v2api/" . alfa_branch() . "/pay/index",
            ['date_from' => $from, 'date_to' => $to, 'page' => 0, 'count' => 1], $token, true, 15);
        if (!isset($t['__err']) && (int)($t['total'] ?? 0) > 0) $useDates = true;

        // 3) платежи с меткой «май» по филиалам
        $scanned = 0; $capped = false; $errNote = null; $totalReported = 0;
        $sampleKeys = null; $sampleRecords = []; $byCust = [];
        foreach ($branches as $bid) {
            $page = 0;
            do {
                $body = ['page' => $page, 'count' => $perPage];
                if ($useDates) { $body['date_from'] = $from; $body['date_to'] = $to; }
                $r = alfa_http('POST', "$host/v2api/$bid/pay/index", $body, $token, true, 20);
                if (isset($r['__err'])) { $errNote = $r; break; }
                if ($page === 0) $totalReported += (int)($r['total'] ?? 0);
                $items = $r['items'] ?? [];
                foreach ($items as $p) {
                    $scanned++;
                    if ($sampleKeys === null) $sampleKeys = array_keys($p);
                    if (count($sampleRecords) < 3) $sampleRecords[] = $p;
                    $note = mb_strtolower((string)($p['note'] ?? ''));
                    if ($kw !== '' && mb_strpos($note, $kw) === false) continue;   // метка «май»
                    $cid = (int)($p['customer_id'] ?? 0); if (!$cid) continue;
                    if (!isset($byCust[$cid]))
                        $byCust[$cid] = ['count' => 0, 'note' => (string)($p['note'] ?? ''), 'date' => (string)($p['document_date'] ?? ''), 'income' => (string)($p['income'] ?? '')];
                    $byCust[$cid]['count']++;   // сколько майских оплат у ребёнка (обычно = число курсов)
                }
                $page++;
                if ($scanned >= $maxScan) { $capped = true; break; }
                // тот же фикс, что и выше: идём, пока страница ПОЛНАЯ, а не по ненадёжному total
            } while (count($items) === $perPage && $page < $maxPages);
            if ($capped) break;
        }

        // 4) собрать список: по строке на каждый курс из договора + сколько майских оплачено
        $rows = [];
        foreach ($byCust as $cid => $pi) {
            $cm = $custMap[$cid] ?? null;
            $rows[] = ['customer_id' => $cid,
                       'name'      => $cm ? $cm['name'] : '',
                       'dogovora'  => $cm ? $cm['dogovora'] : [],   // все курсы договора (клиент разложит по строкам)
                       'may_count' => (int)$pi['count'],            // сколько майских оплат (=обычно число курсов)
                       'note'      => $pi['note'], 'date' => $pi['date'], 'income' => $pi['income']];
        }

        json_out(['ok' => true, 'count' => count($rows), 'subs' => $rows,
                  'keyword' => $kw, 'useDates' => $useDates, 'allBranches' => $allBranches,
                  'debug' => ['scanned' => $scanned, 'total_reported' => $totalReported, 'capped' => $capped,
                              'customers' => count($custMap), 'matched' => count($byCust),
                              'err' => $errNote, 'sample_keys' => $sampleKeys, 'sample_records' => $sampleRecords]]);
        break;

    // (диагностический action 'probe' убран из прода после аудита — свою задачу выполнил)

    default:
        json_out(['ok' => false, 'error' => 'Неизвестное действие: ' . $action], 400);
}
