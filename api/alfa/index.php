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
                    if ($id === null || isset($byId[$id])) continue;
                    $phones = $c['phone'] ?? [];
                    if (is_string($phones)) $phones = $phones === '' ? [] : [$phones];
                    $byId[$id] = [
                        'id'       => $id,
                        'name'     => trim((string)($c['name'] ?? '')),
                        'phones'   => array_values(array_filter(array_map('strval', (array)$phones))),
                        'is_study' => (int)($c['is_study'] ?? 0),
                        'dob'      => $c['dob'] ?? null,
                    ];
                }
                $total = (int)($r['total'] ?? 0);
                $page++;
                // продолжаем, пока страница полная И не выбрали весь total филиала
            } while (count($items) === $perPage && ($page * $perPage) < $total && $page < $maxPages);
            $perBranch[$bid] = count($byId) - $before;
        }

        $all = array_values($byId);
        json_out(['ok' => true, 'count' => count($all), 'customers' => $all,
                  'branches' => count($branches), 'per_branch' => $perBranch]);
        break;

    // --- история ребёнка: в каких группах был + краткая сводка (READ) ---
    case 'history':
        $cid = (int)($in['customerId'] ?? 0);
        $matchedName = '';
        if (!$cid && !empty($in['name'])) {           // поиск по ФИО, если id не передан
            $s = alfa_http('POST', 'https://' . alfa_host() . '/v2api/' . alfa_branch() . '/customer/index',
                ['name' => (string)$in['name'], 'removed' => 0, 'page' => 0, 'count' => 20], alfa_token(), true);
            $cands = $s['items'] ?? [];
            if (count($cands)) { $cid = (int)($cands[0]['id'] ?? 0); $matchedName = (string)($cands[0]['name'] ?? ''); }
        }
        if (!$cid) json_out(['ok' => true, 'notFound' => true, 'history' => [], 'summary' => []]);
        // членства в группах (cgi по customer_id) — вся история
        $cgi = alfa_call('cgi', 'index', ['customer_id' => $cid, 'page' => 0, 'count' => 200]);
        $items = $cgi['items'] ?? [];
        // фильтр по прошлому учебному году (по умолчанию 01.09.2025 – 31.05.2026)
        $from = (string)($in['from'] ?? '2025-09-01');
        $to   = (string)($in['to'] ?? '2026-05-31');
        $overlap = function ($b, $e) use ($from, $to) {
            $b = $b ? substr($b, 0, 10) : null;
            $e = $e ? substr($e, 0, 10) : null;
            if ($b && $b > $to) return false;      // началось после окна
            if ($e && $e < $from) return false;    // закончилось до окна
            return true;                            // пересекается (пустые границы = открыто)
        };
        $inWindow = array_values(array_filter($items, fn($it) => $overlap($it['b_date'] ?? null, $it['e_date'] ?? null)));
        // имена групп по id (только для попавших в окно — мягко)
        $gnames = [];
        foreach ($inWindow as $it) {
            $gid = $it['group_id'] ?? null;
            if ($gid === null || isset($gnames[$gid])) continue;
            $gr = alfa_http('POST', 'https://' . alfa_host() . '/v2api/' . alfa_branch() . '/group/index',
                ['id' => (int)$gid, 'page' => 0], alfa_token(), true);
            $gnames[$gid] = $gr['items'][0]['name'] ?? ('Группа #' . $gid);
        }
        $history = array_map(fn($it) => [
            'group'  => $gnames[$it['group_id'] ?? 0] ?? ('Группа #' . ($it['group_id'] ?? '?')),
            'b_date' => $it['b_date'] ?? null,
            'e_date' => $it['e_date'] ?? null,
        ], $inWindow);
        // краткая сводка из карточки клиента
        $cu = alfa_http('POST', 'https://' . alfa_host() . '/v2api/' . alfa_branch() . '/customer/index',
            ['id' => $cid, 'page' => 0], alfa_token(), true);
        $c0 = $cu['items'][0] ?? [];
        $summary = [
            'name'        => $c0['name'] ?? '',
            'dob'         => $c0['dob'] ?? null,
            'balance'     => $c0['balance'] ?? null,
            'last_attend' => $c0['last_attend_date'] ?? null,
            'paid_till'   => $c0['paid_till'] ?? null,
            'next_lesson' => $c0['next_lesson_date'] ?? null,
        ];
        json_out(['ok' => true, 'customerId' => $cid, 'branch' => alfa_branch(), 'matched' => $matchedName,
                  'summary' => $summary, 'history' => $history, 'allCount' => count($items), 'from' => $from, 'to' => $to]);
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

    default:
        json_out(['ok' => false, 'error' => 'Неизвестное действие: ' . $action], 400);
}
