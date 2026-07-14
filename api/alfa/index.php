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

    default:
        json_out(['ok' => false, 'error' => 'Неизвестное действие: ' . $action], 400);
}
