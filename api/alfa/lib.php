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
// Имя каталога солится путём приложения → сосед по shared-хостингу не может заранее
// занять предсказуемый путь и отравить кэш сертификатов/токена (dir 0700, файлы 0600).
function cache_dir(): string {
    static $d = null;
    if ($d !== null) return $d;
    $salt = substr(hash('sha256', __DIR__), 0, 20);
    $d = sys_get_temp_dir() . '/alfaproxy_' . $salt;
    if (!is_dir($d)) @mkdir($d, 0700, true);
    return $d;
}
// Атомарная запись кэша: temp-файл + rename, права 0600 (без гонок/усечения и не читаемо соседями).
function cache_write(string $file, string $data): void {
    $tmp = $file . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $data, LOCK_EX) !== false) { @chmod($tmp, 0600); @rename($tmp, $file); }
    else { @file_put_contents($file, $data, LOCK_EX); @chmod($file, 0600); }
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
    cache_write($cacheFile, json_encode(['exp' => time() + $ttl, 'certs' => $certs]));
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

    // Проверка подписи по сертификату с нужным kid ($kid из чужого заголовка — приводим к строке,
    // иначе массив в этом поле роняет обращение по ключу в 500 у неаутентифицированного запроса)
    $kid = is_string($header['kid'] ?? null) ? $header['kid'] : '';
    $certs = google_secure_certs();
    $pem = $certs[$kid] ?? '';
    if ($pem === '') json_out(['ok' => false, 'error' => 'Ключ подписи не найден'], 401);
    $pubkey = openssl_pkey_get_public($pem);
    if ($pubkey === false) json_out(['ok' => false, 'error' => 'Битый сертификат'], 500);
    $ok = openssl_verify("$h64.$p64", $sig, $pubkey, OPENSSL_ALGO_SHA256);
    if ($ok !== 1) json_out(['ok' => false, 'error' => 'Подпись токена неверна'], 401);

    $email = strtolower((string)($payload['email'] ?? ''));
    /* ⚠️ ДЫРА, КОТОРУЮ ЗАКРЫВАЕТ ЭТА ПРОВЕРКА: регистрация в Firebase открытая, а список
       разрешённых почт виден в публичном index.html. Если у почты из списка ЕЩЁ НЕТ аккаунта,
       посторонний может зарегистрировать её на себя и получить всю базу детей с телефонами.
       Включается флагом в config.php — по умолчанию ВЫКЛЮЧЕНО, чтобы не заблокировать работу:
       Firebase не считает почту подтверждённой, пока по ссылке из письма не перешли.
       Порядок: в модели нажать «✉ Подтвердить почту» (кнопка появляется сама), перейти по
       ссылке из письма — и только потом поставить здесь true. */
    if (!empty(cfg()['require_verified_email']) && empty($payload['email_verified']))
        json_out(['ok' => false, 'error' => 'Почта не подтверждена. Откройте модель → ☰ Меню → «✉ Подтвердить почту», перейдите по ссылке из письма и войдите заново.'], 403);

    // ⚠️ Пустой список раньше означал «пускаем любого вошедшего» — опечатка в config.php открывала
    //    выгрузку всех клиентов кому угодно. Теперь пусто = НИКОМУ (fail-closed).
    $allowed = cfg()['allowed_emails'] ?? [];
    if (empty($allowed))
        json_out(['ok' => false, 'error' => 'Доступ не настроен: в config.php пуст allowed_emails'], 403);
    $allowedLc = array_map('strtolower', $allowed);
    if (!in_array($email, $allowedLc, true))
        json_out(['ok' => false, 'error' => 'Нет доступа (email не в списке)'], 403);

    // Кто может ПИСАТЬ в CRM. Раньше разделения не было вовсе: любой, чья почта попала в список
    // ради вкладки «Клиенты», мог из консоли браузера архивировать и переименовывать клиентов,
    // создавать группы и вписывать детей. Пусто = писать может только первый в allowed_emails.
    $writers = cfg()['write_emails'] ?? [];
    if (empty($writers)) $writers = [$allowedLc[0]];
    $canWrite = in_array($email, array_map('strtolower', $writers), true);
    return ['uid' => $payload['sub'], 'email' => $email, 'can_write' => $canWrite];
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
    cache_write($cacheFile, json_encode(['exp' => time() + 3000, 'token' => $token]));
    return $token;
}

// Низкоуровневый HTTP к Alfa. $token=null для логина.
// $soft=true → при ошибке НЕ обрывать запрос, а вернуть ['__err'=>...] (для необязательных вызовов).
function alfa_http(string $method, string $url, array $body, ?string $token, bool $soft = false, int $timeout = 25): array {
    $headers = ['Content-Type: application/json'];
    if ($token !== null) $headers[] = 'X-ALFACRM-TOKEN: ' . $token;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 6,
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
    // ⚠️ КОД ОТВЕТА ПРОВЕРЯЕМ ОБЯЗАТЕЛЬНО. Раньше он не смотрелся вовсе: ответ 401/403/429/500
    //    с JSON-телом возвращался как штатный, в нём нет ключа items → вызывающий получал пустой
    //    список и понимал его как «данных нет». Для списка групп/состава это означало «в CRM
    //    ничего нет» → создание дублей. Ошибку надо видеть, а не принимать за пустоту.
    if ($code >= 400) {
        if ($soft) return ['__err' => 'http', 'code' => $code, 'body' => $data];
        json_out(['ok' => false, 'error' => 'AlfaCRM ответила ошибкой ' . $code . ': ' . alfa_err_text($data),
                  'code' => $code, 'alfa' => $data], 502);
    }
    return $data;
}

// Alfa принимает даты ТОЛЬКО как ДД.ММ.ГГГГ (проверено на createCustomer: с ISO-датой запись
// молча не создавалась). Из <input type="date"> приходит ГГГГ-ММ-ДД — переводим.
function alfa_date(string $d): string {
    $d = trim($d);
    if ($d === '') return '';
    if (preg_match('#^(\d{4})-(\d{2})-(\d{2})#', $d, $m)) return "$m[3].$m[2].$m[1]";
    return $d;
}

// Обратное преобразование: ДД.ММ.ГГГГ → ГГГГ-ММ-ДД (ISO). Уже-ISO оставляем как есть.
function alfa_iso(string $d): string {
    $d = trim($d);
    if ($d === '') return '';
    if (preg_match('#^(\d{2})\.(\d{2})\.(\d{4})#', $d, $m)) return "$m[3]-$m[2]-$m[1]";
    if (preg_match('#^(\d{4})-(\d{2})-(\d{2})#', $d, $m)) return "$m[1]-$m[2]-$m[3]";
    return $d;
}

// «ЧЧ:ММ» из любого вида времени (9:00, 09:00:00).
function alfa_hm(string $t): string {
    $p = explode(':', trim($t));
    return sprintf('%02d:%02d', (int)($p[0] ?? 0), (int)($p[1] ?? 0));
}

/* --- ПОЛЯ REGULAR-LESSON ---
   В модели Alfa у периода и времени есть ДВЕ формы: «сырая» (b_date/time_from — внутренние
   числовые) и строковая с суффиксом _v (b_date_v/e_date_v, time_from_v/time_to_v). Писать надо
   в строковые, иначе Alfa отвечает «Неверный формат значения "Начало периода"». День недели у
   регулярного занятия — поле `days` (список), филиал обязателен отдельным `branch_id`.
   Форматы (даты ДД.ММ.ГГГГ, время ЧЧ:ММ) — как в документации Alfa для уроков.
   Чтобы не полагаться на документацию вслепую, форму СВЕРЯЕМ с реальной записью из этой же CRM. */
function alfa_rl_sample(int $branch): ?array {
    $r = alfa_http('POST', 'https://' . alfa_host() . "/v2api/$branch/regular-lesson/index",
                   ['page' => 0, 'count' => 5], alfa_token(), true, 12);
    if (isset($r['__err'])) return null;
    $items = $r['items'] ?? [];
    return (is_array($items) && $items) ? $items[0] : null;
}
// Разбираем образец: какие поля реально используются и в каком формате лежат значения.
function alfa_rl_shape(?array $sample): array {
    $shape = ['dateField' => 'v', 'dateFmt' => 'dmy', 'timeFmt' => 'hm', 'days' => 'array', 'from' => 'docs'];
    if (!is_array($sample) || !$sample) return $shape;
    $shape['from'] = 'sample';
    $d = null;
    if (isset($sample['b_date_v']) && $sample['b_date_v'] !== '')      { $shape['dateField'] = 'v';     $d = (string)$sample['b_date_v']; }
    elseif (isset($sample['b_date']) && is_string($sample['b_date']) && $sample['b_date'] !== '')
                                                                       { $shape['dateField'] = 'plain'; $d = (string)$sample['b_date']; }
    if ($d !== null) $shape['dateFmt'] = preg_match('#^\d{4}-\d{2}-\d{2}#', $d) ? 'iso' : 'dmy';
    $t = $sample['time_from_v'] ?? null;
    if (is_string($t) && $t !== '') $shape['timeFmt'] = (substr_count($t, ':') >= 2) ? 'hms' : 'hm';
    if (array_key_exists('days', $sample)) $shape['days'] = is_array($sample['days']) ? 'array' : 'scalar';
    return $shape;
}
function alfa_rl_body(array $slot, array $shape, string $bIso, string $eIso, int $branch, $relId): array {
    $day = $slot['day'];
    $tf  = alfa_hm((string)($slot['time_from'] ?? ''));
    // пустой «конец» дал бы 00:00 (занятие «до полуночи») — считаем час от начала
    $ttRaw = trim((string)($slot['time_to'] ?? ''));
    if ($ttRaw === '') {
        $p  = explode(':', $tf);
        $m  = ((int)$p[0]) * 60 + (int)($p[1] ?? 0) + 60;
        $tt = sprintf('%02d:%02d', intdiv($m, 60) % 24, $m % 60);
    } else $tt = alfa_hm($ttRaw);
    $dB  = $shape['dateFmt'] === 'iso' ? alfa_iso($bIso) : alfa_date($bIso);
    $dE  = $shape['dateFmt'] === 'iso' ? alfa_iso($eIso) : alfa_date($eIso);
    $body = [
        'related_class'  => 'Group',
        'related_id'     => $relId,
        'branch_id'      => $branch,                       // Alfa: «Необходимо заполнить "Филиал"»
        'subject_id'     => $slot['subject_id'] ?? null,
        'room_id'        => $slot['room_id'] ?? null,
        'teacher_ids'    => $slot['teacher_ids'] ?? [],
        'lesson_type_id' => $slot['lesson_type_id'] ?? null,
        'day'            => $day,
        'days'           => $shape['days'] === 'array' ? [$day] : $day,   // Alfa: «Необходимо заполнить "День недели"»
        'time_from_v'    => $shape['timeFmt'] === 'hm' ? $tf : ($tf . ':00'),
        'time_to_v'      => $shape['timeFmt'] === 'hm' ? $tt : ($tt . ':00'),
        'is_public'      => true,
    ];
    if ($shape['dateField'] === 'v') { $body['b_date_v'] = $dB; $body['e_date_v'] = $dE; }
    else                             { $body['b_date']   = $dB; $body['e_date']   = $dE; }
    return $body;
}
/* Постраничное чтение списка Alfa (страница ≤50, обход «пока страница полная»).
   Возвращает ['items'=>[], 'ok'=>bool]. ok=false означает «прочитать НЕ удалось» — это НЕ то же
   самое, что «записей нет»: на пустом списке мы создаём записи, поэтому разница критична. */
function alfa_index_all(int $branch, string $entity, array $filter = [], int $maxPages = 40, int $timeout = 15): array {
    $out = []; $page = 0; $per = 50; $ok = true;
    $host = 'https://' . alfa_host() . "/v2api/$branch/$entity/index";
    do {
        $r = alfa_http('POST', $host, array_merge($filter, ['page' => $page, 'count' => $per]), alfa_token(), true, $timeout);
        if (isset($r['__err'])) { $ok = false; break; }
        $items = $r['items'] ?? [];
        if (!is_array($items)) { $ok = false; break; }
        foreach ($items as $it) $out[] = $it;
        $page++;
    } while (count($items) === $per && $page < $maxPages);
    return ['items' => $out, 'ok' => $ok, 'pages' => $page];
}

/* Формат полей периода у членства (cgi). Alfa отвергла и ДД.ММ.ГГГГ, и ISO с ответом
   «Неверный формат значения "Начало действия"» — значит писать надо не в b_date/e_date.
   Не гадаем: смотрим РЕАЛЬНУЮ запись этой же CRM. Если у неё есть b_date_v — строковая форма
   лежит там (как у regular-lesson), а b_date внутри числовая. */
function alfa_cgi_shape(int $branch): array {
    static $memo = null;
    if ($memo !== null) return $memo;
    $sh = ['field' => 'plain', 'fmt' => 'dmy', 'from' => 'docs', 'keys' => []];
    $r = alfa_http('POST', 'https://' . alfa_host() . "/v2api/$branch/cgi/index",
                   ['page' => 0, 'count' => 5], alfa_token(), true, 12);
    $it = $r['items'][0] ?? null;
    if (is_array($it)) {
        $sh['from'] = 'sample';
        $sh['keys'] = array_keys($it);
        $d = '';
        if (isset($it['b_date_v']) && $it['b_date_v'] !== '') { $sh['field'] = 'v'; $d = (string)$it['b_date_v']; }
        elseif (isset($it['b_date']) && is_string($it['b_date'])) { $d = (string)$it['b_date']; }
        if ($d !== '') $sh['fmt'] = preg_match('#^\d{4}-\d{2}-\d{2}#', $d) ? 'iso' : 'dmy';
    }
    return $memo = $sh;
}
function alfa_cgi_dates(array $sh, string $bIso, string $eIso): array {
    $b = $sh['fmt'] === 'iso' ? $bIso : alfa_date($bIso);
    $e = $sh['fmt'] === 'iso' ? $eIso : alfa_date($eIso);
    return $sh['field'] === 'v' ? ['b_date_v' => $b, 'e_date_v' => $e] : ['b_date' => $b, 'e_date' => $e];
}
/* Найти членство ребёнка в конкретной группе — в том числе АРХИВНОЕ (прошлогоднее).
   ⚠️ Известная особенность Alfa (уже ловили её в «истории ребёнка»): cgi/index по умолчанию
   отдаёт в основном ДЕЙСТВУЮЩИЕ членства, а прошлогоднее — нет. Какой фильтр показывает
   архивные, документация не описывает, поэтому перебираем разумный набор вариантов: по клиенту
   и по группе, с диапазоном дат и с признаком «архивные», параметры и в теле, и в адресе
   (на cgi/create Alfa читает их именно из адреса). Что вернул каждый вариант — пишем в $dbg,
   чтобы при неудаче было видно, где искать, а не гадать заново. */
function alfa_cgi_find(int $branch, int $customerId, int $groupId, ?array &$dbg = null): ?array {
    $host = 'https://' . alfa_host() . "/v2api/$branch/cgi/index";
    $wide = ['date_from' => '2015-01-01', 'date_to' => '2030-12-31'];
    $variants = [
        ['?customer_id=' . $customerId, ['customer_id' => $customerId]],
        ['?customer_id=' . $customerId, array_merge(['customer_id' => $customerId], $wide)],
        ['?customer_id=' . $customerId . '&date_from=2015-01-01&date_to=2030-12-31', ['customer_id' => $customerId]],
        ['?customer_id=' . $customerId . '&removed=1', ['customer_id' => $customerId, 'removed' => 1]],
        ['?customer_id=' . $customerId . '&dead=1',    ['customer_id' => $customerId, 'dead' => true]],
        ['?group_id=' . $groupId,                      ['group_id' => $groupId]],
        ['?group_id=' . $groupId . '&removed=1',       ['group_id' => $groupId, 'removed' => 1]],
    ];
    $dbg = [];
    foreach ($variants as $i => $v) {
        $r = alfa_http('POST', $host . $v[0], array_merge($v[1], ['page' => 0, 'count' => 200]), alfa_token(), true, 12);
        if (isset($r['__err'])) { $dbg[] = ['v' => $i, 'q' => $v[0], 'err' => $r['__err']]; continue; }
        $items = $r['items'] ?? [];
        $seen = [];
        foreach ($items as $it) {
            $g = (int)($it['group_id'] ?? 0); $c = (int)($it['customer_id'] ?? 0);
            if (count($seen) < 25) $seen[] = $g . ($c === $customerId ? '' : '/c' . $c);
            if ($g === $groupId && $c === $customerId) {
                $dbg[] = ['v' => $i, 'q' => $v[0], 'n' => count($items), 'hit' => true];
                return $it;
            }
        }
        $dbg[] = ['v' => $i, 'q' => $v[0], 'n' => count($items), 'groups' => $seen,
                  'keys' => $items ? array_keys($items[0]) : []];
    }
    // Не нашли в этом филиале — членство могло быть заведено в контексте другого.
    // Alfa проверяет «уже состоит» шире, чем отдаёт списком, поэтому обходим остальные.
    foreach (alfa_all_branch_ids() as $bb) {
        if ((int)$bb === $branch) continue;
        $r = alfa_http('POST', 'https://' . alfa_host() . "/v2api/$bb/cgi/index?customer_id=" . $customerId,
                       ['customer_id' => $customerId, 'page' => 0, 'count' => 200], alfa_token(), true, 12);
        if (isset($r['__err'])) { $dbg[] = ['branch' => (int)$bb, 'err' => $r['__err']]; continue; }
        $items = $r['items'] ?? [];
        foreach ($items as $it) {
            if ((int)($it['group_id'] ?? 0) === $groupId && (int)($it['customer_id'] ?? 0) === $customerId) {
                $dbg[] = ['branch' => (int)$bb, 'n' => count($items), 'hit' => true];
                $it['__branch'] = (int)$bb;         // продлевать надо в том же филиале
                return $it;
            }
        }
        $dbg[] = ['branch' => (int)$bb, 'n' => count($items)];
    }
    return null;
}
/* Продлить существующее членство: меняем только КОНЕЦ периода, начало не трогаем — иначе
   затрём историю («ходит с 02.09.2025»). Alfa update перезаписывает запись целиком,
   поэтому переносим её поля как есть. */
function alfa_cgi_extend(int $branch, array $cur, string $eIso, array $sh): array {
    $id = (int)($cur['id'] ?? 0);
    $body = [];
    foreach (['customer_id','group_id','b_date','e_date','b_date_v','e_date_v'] as $f)
        if (isset($cur[$f]) && $cur[$f] !== '' && $cur[$f] !== null) $body[$f] = $cur[$f];
    $e = $sh['fmt'] === 'iso' ? $eIso : alfa_date($eIso);
    if ($sh['field'] === 'v') $body['e_date_v'] = $e; else $body['e_date'] = $e;
    $url = 'https://' . alfa_host() . "/v2api/$branch/cgi/update?id=" . $id
         . '&customer_id=' . (int)($cur['customer_id'] ?? 0) . '&group_id=' . (int)($cur['group_id'] ?? 0);
    return alfa_soft_body(alfa_http('POST', $url, $body, alfa_token(), true, 15));
}

/* Привязка ученика к группе (cgi/create).
   ⚠️ Alfa на этом эндпоинте читает ключевые параметры ИЗ СТРОКИ АДРЕСА, а не из тела: с телом
   вида {customer_id, group_id, …} она отвечает «Отсутствуют обязательные параметры: group_id».
   Ровно та же особенность, что у customer-tariff/index (там понадобился ?customer_id= в URL).
   Поэтому дублируем id и в адрес, и в тело — лишнее Alfa игнорирует. */
function alfa_cgi_create(int $branch, int $customerId, int $groupId, array $body): array {
    $url = 'https://' . alfa_host() . "/v2api/$branch/cgi/create"
         . '?customer_id=' . $customerId . '&group_id=' . $groupId;
    return alfa_soft_body(alfa_http('POST', $url, $body, alfa_token(), true));
}

// В каком филиале лежит группа. Нужно, чтобы вызывающему (карточка занятия) не приходилось
// это знать: cgi и расписание пишутся в контексте филиала группы. 0 — не нашли.
function alfa_group_branch(int $gid): int {
    static $memo = [];
    if ($gid <= 0) return 0;
    if (isset($memo[$gid])) return $memo[$gid];
    foreach (alfa_all_branch_ids() as $bid) {
        $r = alfa_http('POST', 'https://' . alfa_host() . "/v2api/$bid/group/index",
                       ['id' => $gid, 'page' => 0, 'count' => 5], alfa_token(), true, 10);
        foreach (($r['items'] ?? []) as $row) {
            if ((int)($row['id'] ?? 0) === $gid) { $memo[$gid] = (int)$bid; return $memo[$gid]; }
        }
    }
    $memo[$gid] = 0;
    return 0;
}

// Формат дат у групп — сверяем с реальной группой этой CRM (b_date как её отдаёт Alfa).
function alfa_group_date_fmt(int $branch): string {
    $r = alfa_http('POST', 'https://' . alfa_host() . "/v2api/$branch/group/index",
                   ['page' => 0, 'count' => 5], alfa_token(), true, 12);
    foreach (($r['items'] ?? []) as $g) {
        $d = $g['b_date'] ?? '';
        if (is_string($d) && $d !== '') return preg_match('#^\d{4}-\d{2}-\d{2}#', $d) ? 'iso' : 'dmy';
    }
    return 'dmy';                                          // документация Alfa: даты ДД.ММ.ГГГГ
}

// Человекочитаемая причина отказа из ответа Alfa. Без неё «не вернула id» — загадка:
// у Alfa текст лежит то в errors[поле][], то в error/message.
function alfa_err_text($r): string {
    if (!is_array($r)) return '';
    $out = [];
    if (!empty($r['errors']) && is_array($r['errors'])) {
        foreach ($r['errors'] as $f => $msgs) {
            $t = is_array($msgs) ? implode('; ', array_map('strval', $msgs)) : (string)$msgs;
            if ($t !== '') $out[] = (is_string($f) && $f !== '' ? $f . ': ' : '') . $t;
        }
    }
    foreach (['error', 'message', 'msg'] as $k) {
        if (!empty($r[$k]) && is_string($r[$k])) $out[] = $r[$k];
    }
    if (isset($r['__err'])) $out[] = 'связь: ' . $r['__err'] . ' ' . (string)($r['msg'] ?? $r['code'] ?? '');
    return implode(' · ', array_unique($out));
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
    cache_write($cacheFile, json_encode(['exp' => time() + 86400, 'branch' => $resolved]));
    return $resolved;
}

// Вызов сущности Alfa: POST /v2api/{branch}/{entity}/{cmd}. $global=true → без branch.
/* Пишущие вызовы (create/update) — МЯГКИЕ. Отказ Alfa по валидации (422) должен вернуться
   вызывающему как обычный ответ с описанием ошибки, а не оборвать весь запрос: иначе публикация
   упала бы посреди работы и клиент не узнал бы уже созданный group_id → при повторе дубль.
   Тело ошибки отдаём как есть (в нём текст Alfa), добавляя __code для диагностики. */
function alfa_soft_body(array $r): array {
    if (isset($r['__err']) && $r['__err'] === 'http' && is_array($r['body'] ?? null)) {
        return array_merge($r['body'], ['__code' => $r['code'] ?? 0]);
    }
    return $r;
}
function alfa_call(string $entity, string $cmd, array $body, bool $global = false): array {
    $token  = alfa_token();
    $path   = $global ? "/v2api/$entity/$cmd" : '/v2api/' . alfa_branch() . "/$entity/$cmd";
    return alfa_soft_body(alfa_http('POST', 'https://' . alfa_host() . $path, $body, $token, true));
}

// Вызов в контексте конкретного филиала.
function alfa_call_branch(int $branch, string $entity, string $cmd, array $body): array {
    return alfa_soft_body(alfa_http('POST', 'https://' . alfa_host() . "/v2api/$branch/$entity/$cmd", $body, alfa_token(), true));
}

// Создать сущность: POST /v2api/{branch}/{entity}/create с телом $data.
function alfa_create(string $entity, array $data): array {
    return alfa_call($entity, 'create', $data);
}

// Справочник в контексте КОНКРЕТНОГО филиала. Кабинеты (и часто педагоги) привязаны к филиалу,
// поэтому единый список из дефолтного филиала давал чужие id. null, если эндпоинта нет/ошибка.
function alfa_ref_branch(int $branch, string $entity, int $timeout = 12): ?array {
    $r = alfa_http('POST', 'https://' . alfa_host() . "/v2api/$branch/$entity/index",
        ['page' => 0, 'count' => 200], alfa_token(), true, $timeout);
    if (isset($r['__err']) || !isset($r['items'])) return null;
    return $r['items'];
}

// Имена филиалов: id => name.
function alfa_branch_names(): array {
    $r = alfa_http('POST', 'https://' . alfa_host() . '/v2api/branch/index',
        ['is_active' => 1, 'page' => 0], alfa_token(), true, 8);
    $out = [];
    foreach (($r['items'] ?? []) as $b) { if (isset($b['id'])) $out[(int)$b['id']] = (string)($b['name'] ?? ''); }
    return $out;
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

// Найти клиента по id. Клиент может лежать НЕ в дефолтном филиале — обходим все.
// $branchOut — филиал, в котором нашли (для последующего update в том же контексте).
function alfa_customer_get(int $id, ?int &$branchOut = null): ?array {
    $token = alfa_token();
    $host  = 'https://' . alfa_host();
    foreach (alfa_all_branch_ids() as $bid) {
        // 1) фильтр по id в теле; 2) запасной вариант — id в URL (Alfa местами игнорит тело)
        foreach ([['url' => '', 'body' => ['id' => $id, 'page' => 0, 'count' => 5]],
                  ['url' => '?id=' . $id, 'body' => ['page' => 0, 'count' => 5]]] as $v) {
            $r = alfa_http('POST', "$host/v2api/$bid/customer/index" . $v['url'], $v['body'], $token, true, 8);
            if (isset($r['__err'])) continue;
            foreach (($r['items'] ?? []) as $c) {
                if ((int)($c['id'] ?? 0) === $id) { $branchOut = (int)$bid; return $c; }
            }
        }
    }
    return null;
}

// Поля-кандидаты на «архив» — короткая сводка карточки для сверки в консоли/UI.
function alfa_flags(array $c): array {
    $out = [];
    foreach (['id','name','is_study','removed','is_archive','archived','is_active','active',
              'study_status_id','lead_status_id','lead_reject_id','custom_status','branch_ids','dt_update'] as $f) {
        if (array_key_exists($f, $c)) $out[$f] = $c[$f];
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
