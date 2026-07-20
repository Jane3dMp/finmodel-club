<?php
// Прокси к amoCRM — библиотека.
// Firebase-проверку, CORS и json_out переиспользуем из соседнего alfa/lib.php:
// это ровно та же авторизация (человек вошёл в модель), дублировать её нельзя —
// разъедется.
declare(strict_types=1);

require_once __DIR__ . '/../alfa/lib.php';

// ---------- Конфигурация amoCRM (отдельный config.php, в git не идёт) ----------
function amo_cfg(): array {
    static $c = null;
    if ($c === null) {
        $path = __DIR__ . '/config.php';
        if (!file_exists($path)) {
            json_out(['ok' => false, 'error' => 'api/amo/config.php не найден на сервере — создайте его из config.example.php'], 500);
        }
        $c = require $path;
    }
    return $c;
}

function amo_host(): string {
    $h = trim((string)(amo_cfg()['subdomain'] ?? ''));
    $h = preg_replace('#^https?://#', '', $h);
    $h = rtrim((string)$h, '/');
    if ($h === '') json_out(['ok' => false, 'error' => 'В config.php не указан subdomain amoCRM'], 500);
    // допускаем и «proznanie», и «proznanie.amocrm.ru»
    return strpos($h, '.') === false ? ($h . '.amocrm.ru') : $h;
}

function amo_token(): string {
    $t = trim((string)(amo_cfg()['access_token'] ?? ''));
    if ($t === '') json_out(['ok' => false, 'error' => 'В config.php не указан access_token amoCRM'], 500);
    return $t;
}

// ---------- HTTP к amoCRM (API v4, Bearer) ----------
// Возвращает ['__status'=>код, 'data'=>массив|null]. 204 = «ничего не найдено» (amo так отвечает
// на пустую выборку — это НЕ ошибка, важно не принять её за сбой).
function amo_http(string $method, string $path, array $query = [], ?array $body = null, int $timeout = 20): array {
    $url = 'https://' . amo_host() . $path . ($query ? ('?' . http_build_query($query)) : '');
    $ch  = curl_init($url);
    $headers = ['Authorization: Bearer ' . amo_token(), 'Content-Type: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) json_out(['ok' => false, 'error' => 'Сеть до amoCRM недоступна: ' . $err], 502);
    if ($code === 401) json_out(['ok' => false, 'error' => 'amoCRM не принял токен (401). Проверьте access_token в config.php — возможно, истёк.'], 502);
    if ($code === 403) json_out(['ok' => false, 'error' => 'amoCRM: доступ запрещён (403). У интеграции нет прав на этот раздел.'], 502);
    if ($code === 204) return ['__status' => 204, 'data' => null];
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        json_out(['ok' => false, 'error' => 'amoCRM вернул не-JSON (код ' . $code . ')', 'raw' => mb_substr((string)$raw, 0, 300)], 502);
    }
    if ($code >= 400) {
        $msg = $data['title'] ?? ($data['detail'] ?? ('ошибка ' . $code));
        json_out(['ok' => false, 'error' => 'amoCRM: ' . $msg, 'code' => $code, 'raw' => $data], 502);
    }
    return ['__status' => $code, 'data' => $data];
}

// ---------- Телефоны/почта из custom_fields_values контакта ----------
function amo_field_values(array $entity, string $code): array {
    $out = [];
    foreach ((array)($entity['custom_fields_values'] ?? []) as $f) {
        if (!is_array($f)) continue;
        if (strtoupper((string)($f['field_code'] ?? '')) !== strtoupper($code)) continue;
        foreach ((array)($f['values'] ?? []) as $v) {
            $val = is_array($v) ? ($v['value'] ?? null) : $v;
            if ($val !== null && $val !== '') $out[] = (string)$val;
        }
    }
    return $out;
}

// Все кастомные поля сущности в виде «название → значения» (чтобы увидеть, где лежит имя ребёнка).
function amo_fields_map(array $entity): array {
    $out = [];
    foreach ((array)($entity['custom_fields_values'] ?? []) as $f) {
        if (!is_array($f)) continue;
        $name = (string)($f['field_name'] ?? ($f['field_code'] ?? ('field_' . ($f['field_id'] ?? '?'))));
        $vals = [];
        foreach ((array)($f['values'] ?? []) as $v) {
            $val = is_array($v) ? ($v['value'] ?? null) : $v;
            if (is_array($val)) $val = json_encode($val, JSON_UNESCAPED_UNICODE);
            if ($val !== null && $val !== '') $vals[] = (string)$val;
        }
        if ($vals) $out[$name] = $vals;
    }
    return $out;
}

// Контакты пачкой по id (amo отдаёт до 250 за раз).
function amo_contacts_by_ids(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $out = [];
    foreach (array_chunk($ids, 100) as $chunk) {
        $q = ['limit' => 250];
        foreach ($chunk as $i => $id) $q['filter']['id'][$i] = $id;
        $r = amo_http('GET', '/api/v4/contacts', $q);
        foreach ((array)(($r['data']['_embedded']['contacts'] ?? [])) as $c) {
            if (!is_array($c) || !isset($c['id'])) continue;
            $out[(int)$c['id']] = [
                'id'     => (int)$c['id'],
                'name'   => trim((string)($c['name'] ?? '')),
                'phones' => amo_field_values($c, 'PHONE'),
                'emails' => amo_field_values($c, 'EMAIL'),
                'fields' => amo_fields_map($c),
            ];
        }
    }
    return $out;
}
