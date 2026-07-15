<?php
// Прокси к AlfaCRM — общая библиотека.
// CORS, ответы JSON, проверка Firebase ID-токена, авторизация и вызовы AlfaCRM.
declare(strict_types=1);

// ---------- Конфигурация ----------
function cfg(): array {
    static $c = null;
    if ($c === null) {
        $path = __DIR__ . '/config.php';
        if (!file_exists($path)) {
            json_out(['ok' => false, 'error' => 'config.php не найден на сервере — создайте его из config.example.php'], 500);
        }
        $c = require $path;
    }
    return $c;
}

// ---------- Ответ JSON ----------
function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------- CORS ----------
function cors(): void {
    $allowed = cfg()['allowed_origins'] ?? [];
    $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin && in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Max-Age: 600');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// ---------- Каталог для кэша (токены Alfa, сертификаты Google) ----------
function cache_dir(): string {
    $d = sys_get_temp_dir() . '/alfaproxy';
    if (!is_dir($d)) @mkdir($d, 0700, true);
    return $d;
}

// =====================================================================
//  Проверка Firebase ID-токена (RS256) без сторонних библиотек
// =====================================================================
function b64url_decode(string $s): string {
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    return base64_decode($s) ?: '';
}

// Публичные сертификаты Google для securetoken. Кэшируем по заголовку Cache-Control.
function google_secure_certs(): array {
    $cacheFile = cache_dir() . '/google_certs.json';
    if (is_file($cacheFile)) {
        $raw = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($raw) && ($raw['exp'] ?? 0) > time() && is_array($raw['certs'] ?? null)) {
            return $raw['certs'];
        }
    }
    $url = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) json_out(['ok' => false, 'error' => 'Не удалось получить сертификаты Google'], 502);
    $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = substr((string)$resp, 0, $hsize);
    $body    = substr((string)$resp, $hsize);
    $certs   = json_decode($body, true);
    if (!is_array($certs)) json_out(['ok' => false, 'error' => 'Некорректный ответ сертификатов Google'], 502);

    $ttl = 3600;
    if (preg_match('/max-age=(\d+)/i', $headers, $m)) $ttl = max(60, (int)$m[1]);
    @file_put_contents($cacheFile, json_encode(['exp' => time() + $ttl, 'certs' => $certs]));
    return $certs;
}

// Возвращает данные пользователя из валидного токена или обрывает запрос 401/403.
function require_firebase_user(): array {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($hdr === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) { if (strcasecmp($k, 'Authorization') === 0) { $hdr = $v; break; } }
    }
    if (!preg_match('/Bearer\s+(.+)/i', $hdr, $m)) {
        json_out(['ok' => false, 'error' => 'Нет токена авторизации'], 401);
    }
    $jwt   = trim($m[1]);
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) json_out(['ok' => false, 'error' => 'Некорректный токен'], 401);

    [$h64, $p64, $s64] = $parts;
    $header  = json_decode(b64url_decode($h64), true);
    $payload = json_decode(b64url_decode($p64), true);
    $sig     = b64url_decode($s64);
    if (!is_array($header) || !is_array($payload)) json_out(['ok' => false, 'error' => 'Некорректный токен'], 401);
    if (($header['alg'] ?? '') !== 'RS256') json_out(['ok' => false, 'error' => 'Неверный алгоритм токена'], 401);

    $project = cfg()['firebase_project'] ?? '';
    $now     = time();
    $leeway  = 60;
    if (($payload['aud'] ?? '') !== $project)
        json_out(['ok' => false, 'error' => 'Токен другого проекта'], 403);
    if (($payload['iss'] ?? '') !== "https://securetoken.google.com/$project")
        json_out(['ok' => false, 'error' => 'Неверный издатель токена'], 403);
    if (($payload['exp'] ?? 0) < $now - $leeway)
        json_out(['ok' => false, 'error' => 'Токен истёк — обновите страницу'], 401);
    if (($payload['iat'] ?? PHP_INT_MAX) > $now + $leeway)
        json_out(['ok' => false, 'error' => 'Токен из будущего'], 401);
    if (empty($payload['sub']))
        json_out(['ok' => false, 'error' => 'Токен без пользователя'], 401);

    // Проверка подписи по сертификату с нужным kid
    $kid = $header['kid'] ?? '';
    $certs = google_secure_certs();
    $pem = $certs[$kid] ?? '';
    if ($pem === '') json_out(['ok' => false, 'error' => 'Ключ подписи не найден'], 401);
    $pubkey = openssl_pkey_get_public($pem);
    if ($pubkey === false) json_out(['ok' => false, 'error' => 'Битый сертификат'], 500);
    $ok = openssl_verify("$h64.$p64", $sig, $pubkey, OPENSSL_ALGO_SHA256);
    if ($ok !== 1) json_out(['ok' => false, 'error' => 'Подпись токена неверна'], 401);

    $email = strtolower((string)($payload['email'] ?? ''));
    $allowed = cfg()['allowed_emails'] ?? [];
    if (!empty($allowed)) {
        $allowedLc = array_map('strtolower', $allowed);
        if (!in_array($email, $allowedLc, true)) {
            json_out(['ok' => false, 'error' => 'Нет доступа к контактам (email не в списке)'], 403);
        }
    }
    return ['uid' => $payload['sub'], 'email' => $email];
}

// =====================================================================
//  AlfaCRM: авторизация и вызовы
// =====================================================================
function alfa_host(): string {
    $h = cfg()['alfa']['hostname'] ?? '';
    $h = preg_replace('#^https?://#', '', trim($h));
    return rtrim((string)$h, '/');
}

// Токен Alfa живёт 3600с — кэшируем на 50 минут.
function alfa_token(): string {
    $cacheFile = cache_dir() . '/alfa_token.json';
    if (is_file($cacheFile)) {
        $c = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($c) && ($c['exp'] ?? 0) > time() && !empty($c['token'])) return $c['token'];
    }
    $a = cfg()['alfa'];
    $resp = alfa_http('POST', 'https://' . alfa_host() . '/v2api/auth/login',
        ['email' => $a['email'] ?? '', 'api_key' => $a['api_key'] ?? ''], null);
    $token = $resp['token'] ?? '';
    if ($token === '') json_out(['ok' => false, 'error' => 'AlfaCRM не выдал токен — проверьте email/api_key', 'alfa' => $resp], 502);
    @file_put_contents($cacheFile, json_encode(['exp' => time() + 3000, 'token' => $token]));
    return $token;
}

// Низкоуровневый HTTP к Alfa. $token=null для логина.
// $soft=true → при ошибке НЕ обрывать запрос, а вернуть ['__err'=>...] (для необязательных вызовов).
function alfa_http(string $method, string $url, array $body, ?string $token, bool $soft = false): array {
    $headers = ['Content-Type: application/json'];
    if ($token !== null) $headers[] = 'X-ALFACRM-TOKEN: ' . $token;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 25,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        if ($soft) return ['__err' => 'network', 'msg' => $err];
        json_out(['ok' => false, 'error' => 'Сеть до AlfaCRM недоступна: ' . $err], 502);
    }
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        if ($soft) return ['__err' => 'nonjson', 'code' => $code];
        json_out(['ok' => false, 'error' => 'AlfaCRM вернул не-JSON (код ' . $code . ')', 'raw' => mb_substr((string)$raw, 0, 300)], 502);
    }
    return $data;
}

// Мягкая попытка получить справочник (index). null, если эндпоинта нет/ошибка.
function alfa_try_index(string $entity, bool $global = false): ?array {
    $path = $global ? "/v2api/$entity/index" : '/v2api/' . alfa_branch() . "/$entity/index";
    $r = alfa_http('POST', 'https://' . alfa_host() . $path, ['page' => 0, 'count' => 500], alfa_token(), true);
    if (isset($r['__err']) || !isset($r['items'])) return null;
    return $r['items'];
}

// ID филиала. Если в конфиге 0/пусто — определяем сам (первый активный филиал).
function alfa_branch(): int {
    static $resolved = null;
    if ($resolved !== null) return $resolved;
    $b = (int)(cfg()['alfa']['branch'] ?? 0);
    if ($b > 0) { $resolved = $b; return $resolved; }
    $cacheFile = cache_dir() . '/alfa_branch.json';
    if (is_file($cacheFile)) {
        $c = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($c) && ($c['exp'] ?? 0) > time() && !empty($c['branch'])) { $resolved = (int)$c['branch']; return $resolved; }
    }
    $r = alfa_http('POST', 'https://' . alfa_host() . '/v2api/branch/index',
        ['is_active' => 1, 'page' => 0], alfa_token());
    $resolved = (int)($r['items'][0]['id'] ?? 1);
    @file_put_contents($cacheFile, json_encode(['exp' => time() + 86400, 'branch' => $resolved]));
    return $resolved;
}

// Вызов сущности Alfa: POST /v2api/{branch}/{entity}/{cmd}. $global=true → без branch.
function alfa_call(string $entity, string $cmd, array $body, bool $global = false): array {
    $token  = alfa_token();
    $path   = $global ? "/v2api/$entity/$cmd" : '/v2api/' . alfa_branch() . "/$entity/$cmd";
    return alfa_http('POST', 'https://' . alfa_host() . $path, $body, $token);
}

// Вызов в контексте конкретного филиала.
function alfa_call_branch(int $branch, string $entity, string $cmd, array $body): array {
    return alfa_http('POST', 'https://' . alfa_host() . "/v2api/$branch/$entity/$cmd", $body, alfa_token());
}

// Создать сущность: POST /v2api/{branch}/{entity}/create с телом $data.
function alfa_create(string $entity, array $data): array {
    return alfa_call($entity, 'create', $data);
}

// Прочитать справочник целиком (index, до 500 записей) → массив items с нужными полями.
function alfa_ref(string $entity, array $fields, bool $global = false): array {
    $r = $global ? alfa_call($entity, 'index', ['page' => 0, 'count' => 500], true)
                 : alfa_call($entity, 'index', ['page' => 0, 'count' => 500]);
    $out = [];
    foreach (($r['items'] ?? []) as $it) {
        $row = [];
        foreach ($fields as $f) $row[$f] = $it[$f] ?? null;
        $out[] = $row;
    }
    return $out;
}

// Список id всех активных филиалов (клиенты в Alfa привязаны к филиалам).
function alfa_all_branch_ids(): array {
    $r = alfa_http('POST', 'https://' . alfa_host() . '/v2api/branch/index',
        ['is_active' => 1, 'page' => 0], alfa_token());
    $ids = [];
    foreach (($r['items'] ?? []) as $b) { if (isset($b['id'])) $ids[] = (int)$b['id']; }
    return $ids ?: [alfa_branch()];
}
