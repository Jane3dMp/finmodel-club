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
        json_out(['ok' => true, 'branches' => $out, 'current_branch' => (int)(cfg()['alfa']['branch'] ?? 1)]);
        break;

    // --- ученики/клиенты ---
    case 'customers':
        // По умолчанию тянем действующих учеников (is_study=1). Клиент может переопределить.
        $filter = is_array($in['filter'] ?? null) ? $in['filter'] : [];
        if (!array_key_exists('is_study', $filter)) $filter['is_study'] = 1;
        $filter['removed'] = $filter['removed'] ?? 0;

        $all      = [];
        $page     = 0;
        $count    = 500;              // сколько за страницу
        $maxPages = 60;               // предохранитель
        do {
            $body = array_merge($filter, ['page' => $page, 'count' => $count]);
            $r = alfa_call('customer', 'index', $body);
            $items = $r['items'] ?? [];
            foreach ($items as $c) {
                $phones = $c['phone'] ?? [];
                if (is_string($phones)) $phones = $phones === '' ? [] : [$phones];
                $all[] = [
                    'id'       => $c['id'] ?? null,
                    'name'     => trim((string)($c['name'] ?? '')),
                    'phones'   => array_values(array_filter(array_map('strval', (array)$phones))),
                    'is_study' => (int)($c['is_study'] ?? 0),
                    'status'   => $c['study_status_id'] ?? null,
                    'balance'  => $c['balance'] ?? null,
                    'note'     => (string)($c['note'] ?? ''),
                ];
            }
            $total = (int)($r['total'] ?? count($all));
            $page++;
        } while (count($items) === $count && count($all) < $total && $page < $maxPages);

        json_out(['ok' => true, 'count' => count($all), 'customers' => $all]);
        break;

    default:
        json_out(['ok' => false, 'error' => 'Неизвестное действие: ' . $action], 400);
}
