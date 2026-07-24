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
        // 2) уроки в окне с ПАГИНАЦИЕЙ (Alfa отдаёт ≤50 на страницу; у активного ребёнка за год >150 уроков —
        //    без пагинации терялись ранние группы/расписание). Лимит 20 страниц = 1000 уроков.
        $lesItems = [];
        for ($lp = 0; $lp < 20; $lp++) {
            $les = alfa_http('POST', "$host/lesson/index",
                ['customer_id' => $cid, 'date_from' => $from, 'date_to' => $to, 'b_date' => $from, 'e_date' => $to, 'page' => $lp, 'count' => 100],
                $token, true, 14);
            if (isset($les['__err'])) break;
            $batch = $les['items'] ?? [];
            foreach ($batch as $ls) $lesItems[] = $ls;
            $total = (int)($les['total'] ?? 0);
            if (count($batch) === 0 || count($lesItems) >= $total) break;
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
    case 'refs':
        $out = [
            'subjects'     => alfa_ref('subject', ['id', 'name']),
            'rooms'        => alfa_ref('room', ['id', 'name', 'is_enabled']),
            'lesson_types' => alfa_ref('lesson-type', ['id', 'name']),
        ];
        // необязательные справочники — мягко (эндпоинт может отсутствовать в v2)
        foreach (['teacher'=>'teachers','group-status'=>'group_statuses','group-level'=>'group_levels','level'=>'levels'] as $ent => $key) {
            $items = alfa_try_index($ent);
            if ($items !== null) $out[$key] = array_map(fn($i) => ['id' => $i['id'] ?? null, 'name' => $i['name'] ?? ''], $items);
        }
        json_out(['ok' => true, 'branch' => alfa_branch(), 'refs' => $out]);
        break;

    // --- публикация ОДНОЙ группы: dryRun=true по умолчанию (ничего не создаёт) ---
    case 'publish':
        $dry      = !isset($in['dryRun']) || $in['dryRun'] !== false;
        $g        = is_array($in['group'] ?? null) ? $in['group'] : [];
        $sched    = is_array($in['schedule'] ?? null) ? $in['schedule'] : [];
        $students = is_array($in['studentAlfaIds'] ?? null) ? $in['studentAlfaIds'] : [];
        $branch   = alfa_branch();
        $bDate    = (string)($in['b_date'] ?? '2026-09-02');
        $eDate    = (string)($in['e_date'] ?? '2027-05-31');

        $groupPayload = array_merge(
            ['name' => (string)($g['name'] ?? ''), 'branch_ids' => [$branch], 'b_date' => $bDate, 'e_date' => $eDate],
            array_intersect_key($g, array_flip(['teacher_ids','level_id','status_id','limit','note','subject_ids']))
        );

        $plan = ['group' => $groupPayload, 'schedule' => [], 'links' => []];
        foreach ($sched as $s) {
            $plan['schedule'][] = [
                'related_class' => 'Group', 'related_id' => '<group_id>',
                'subject_id'    => $s['subject_id'] ?? null,
                'room_id'       => $s['room_id'] ?? null,
                'teacher_ids'   => $s['teacher_ids'] ?? ($g['teacher_ids'] ?? []),
                'day'           => $s['day'] ?? null,
                'time_from_v'   => $s['time_from'] ?? null,
                'time_to_v'     => $s['time_to'] ?? null,
                'lesson_type_id'=> $s['lesson_type_id'] ?? null,
                'b_date' => $bDate, 'e_date' => $eDate, 'is_public' => true,
            ];
        }
        foreach ($students as $cid) $plan['links'][] = ['customer_id' => $cid, 'group_id' => '<group_id>', 'b_date' => $bDate, 'e_date' => $eDate];

        if ($dry) { json_out(['ok' => true, 'dryRun' => true, 'plan' => $plan]); }

        // ЖИВОЕ создание (только при явном dryRun:false)
        $gr  = alfa_create('group', $groupPayload);
        $gid = $gr['id'] ?? ($gr['model']['id'] ?? null);
        if (!$gid) json_out(['ok' => false, 'error' => 'AlfaCRM не вернула id группы', 'alfa' => $gr], 502);
        $created = ['group_id' => $gid, 'lessons' => [], 'links' => []];
        foreach ($plan['schedule'] as $rl) { $rl['related_id'] = $gid; $created['lessons'][] = alfa_create('regular-lesson', $rl); }
        foreach ($students as $cid) { $created['links'][] = alfa_create('cgi', ['customer_id' => $cid, 'group_id' => $gid, 'b_date' => $bDate, 'e_date' => $eDate]); }
        json_out(['ok' => true, 'dryRun' => false, 'created' => $created]);
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
                $total = (int)($r['total'] ?? 0); $page++;
            } while (count($items) === $perPage && ($page * $perPage) < $total && $page < $maxPages);
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
                $total = (int)($r['total'] ?? 0); $page++;
                if ($scanned >= $maxScan) { $capped = true; break; }
            } while (count($items) === $perPage && ($page * $perPage) < $total && $page < $maxPages);
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
