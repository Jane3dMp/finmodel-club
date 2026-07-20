<?php
// Прокси к amoCRM — роутер (только ЧТЕНИЕ, ничего в amo не меняем).
//
//   ?action=ping       — проверка связи: токен принят, аккаунт виден
//   ?action=pipelines  — воронки и их этапы (id/название/порядок)
//   ?action=leads      — сделки выбранного этапа + контакты (имя, телефон)
//
// Токен amoCRM лежит ТОЛЬКО в config.php на сервере (в git его нет).
// Клиент шлёт Firebase ID-токен — проверка та же, что у alfa-прокси.
declare(strict_types=1);

require __DIR__ . '/lib.php';

cors();
$action = $_GET['action'] ?? 'ping';
$user   = require_firebase_user();

$in = [];
$rawIn = file_get_contents('php://input');
if ($rawIn) { $j = json_decode($rawIn, true); if (is_array($j)) $in = $j; }

switch ($action) {

    case 'ping':
        $r = amo_http('GET', '/api/v4/account');
        json_out(['ok' => true, 'user' => $user['email'], 'host' => amo_host(),
                  'account' => ['id' => $r['data']['id'] ?? null, 'name' => $r['data']['name'] ?? '']]);
        break;

    // --- воронки и этапы (для выбора «откуда тянуть») ---
    case 'pipelines':
        $r = amo_http('GET', '/api/v4/leads/pipelines');
        $out = [];
        foreach ((array)($r['data']['_embedded']['pipelines'] ?? []) as $p) {
            $statuses = [];
            foreach ((array)($p['_embedded']['statuses'] ?? []) as $s) {
                $statuses[] = ['id' => $s['id'] ?? null, 'name' => (string)($s['name'] ?? ''),
                               'sort' => $s['sort'] ?? 0, 'color' => $s['color'] ?? ''];
            }
            usort($statuses, fn($a, $b) => ($a['sort'] <=> $b['sort']));
            $out[] = ['id' => $p['id'] ?? null, 'name' => (string)($p['name'] ?? ''),
                      'is_main' => !empty($p['is_main']), 'statuses' => $statuses];
        }
        json_out(['ok' => true, 'pipelines' => $out]);
        break;

    // --- сделки выбранных этапов + контакты ---
    //     На вход: pipelineId, statusIds[] (или statusId), необязательно createdFrom (unix).
    //     Отдаём нормализованный список: сделка + её контакты с телефонами + все кастомные
    //     поля (имя ребёнка у всех лежит по-разному — покажем клиенту и выберем на живых данных).
    case 'leads':
        @set_time_limit(120);
        $pipelineId = (int)($in['pipelineId'] ?? 0);
        $statusIds  = is_array($in['statusIds'] ?? null) ? $in['statusIds'] : (isset($in['statusId']) ? [$in['statusId']] : []);
        $statusIds  = array_values(array_unique(array_filter(array_map('intval', $statusIds))));
        if (!$pipelineId && !$statusIds) json_out(['ok' => false, 'error' => 'Не выбраны воронка и этап']);

        $LIMIT = 250; $MAX_PAGES = 10;      // предохранитель: до 2500 сделок за раз
        $leads = []; $contactIds = []; $sample = null; $pages = 0;

        for ($page = 1; $page <= $MAX_PAGES; $page++) {
            $q = ['limit' => $LIMIT, 'page' => $page, 'with' => 'contacts'];
            if ($statusIds) {
                foreach ($statusIds as $i => $sid) {
                    $q['filter']['statuses'][$i] = ['pipeline_id' => $pipelineId, 'status_id' => $sid];
                }
            } elseif ($pipelineId) {
                $q['filter']['pipeline_id'] = $pipelineId;
            }
            if (!empty($in['createdFrom'])) $q['filter']['created_at']['from'] = (int)$in['createdFrom'];

            $r = amo_http('GET', '/api/v4/leads', $q);
            $pages = $page;
            if (($r['__status'] ?? 200) === 204) break;                 // 204 = сделок нет, это не ошибка
            $items = (array)($r['data']['_embedded']['leads'] ?? []);
            foreach ($items as $l) {
                if (!is_array($l)) continue;
                if ($sample === null) $sample = $l;
                $cids = [];
                foreach ((array)($l['_embedded']['contacts'] ?? []) as $c) {
                    if (isset($c['id'])) { $cids[] = (int)$c['id']; $contactIds[] = (int)$c['id']; }
                }
                $leads[] = [
                    'id'         => $l['id'] ?? null,
                    'name'       => trim((string)($l['name'] ?? '')),
                    'price'      => $l['price'] ?? null,
                    'status_id'  => $l['status_id'] ?? null,
                    'pipeline_id'=> $l['pipeline_id'] ?? null,
                    'created_at' => $l['created_at'] ?? null,
                    'fields'     => amo_fields_map($l),
                    'contactIds' => $cids,
                ];
            }
            if (count($items) < $LIMIT) break;
        }

        $contacts = $contactIds ? amo_contacts_by_ids($contactIds) : [];
        json_out(['ok' => true, 'leads' => $leads, 'contacts' => $contacts,
                  'count' => count($leads), 'pages' => $pages,
                  'debug' => ['sampleLead' => $sample, 'sampleContact' => $contacts ? reset($contacts) : null]]);
        break;

    default:
        json_out(['ok' => false, 'error' => 'Неизвестное действие: ' . $action], 400);
}
