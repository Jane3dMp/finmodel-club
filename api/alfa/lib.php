<?php
// Прокси к AlfaCRM — общая библиотека.
// CORS, ответы JSON, проверка Firebase ID-токена, авторизация и вызовы AlfaCRM.
declare(strict_types=1);

/* Ответ обязан быть ЧИСТЫМ JSON. Ошибки PHP печатать в тело нельзя (клиент получит «ответ не
   JSON», а в предупреждении может мелькнуть путь или аргумент вызова), поэтому вывод глушим и
   включаем буфер: всё лишнее, что успеет напечататься, json_out выбросит и покажет отдельным
   полем serverNoise. Логирование на сервере при этом сохраняется. */
@ini_set('display_errors', '0');
@ini_set('zend.exception_ignore_args', '1');
if (function_exists('ob_start')) @ob_start();

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
    /* ⚠️ Всё, что успело напечататься ДО ответа, выбрасываем. Иначе одна пустая строка после ?>
       в созданном вручную config.php, BOM из файлового менеджера или предупреждение PHP уезжают
       в тело раньше JSON — клиент получает 200 и «ответ не JSON», а причина не видна. */
    $junk = '';
    while (ob_get_level() > 0) { $junk .= (string)ob_get_clean(); }
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    $junk = trim($junk);
    if ($junk !== '') $data['serverNoise'] = mb_substr($junk, 0, 400);   // чтобы было видно, что именно мешало
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($json === false) $json = '{"ok":false,"error":"Не удалось собрать ответ (битые символы в данных CRM)"}';
    echo $json;
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

/* Реализация за день = сумма commission по участникам ПРОВЕДЁННЫХ занятий (status=3).
   present (без пропусков) = только is_attend=1; all (с пропусками) = все участники.
   ⚠️ lesson/index без фильтра НЕ отдаёт details — они приходят при фильтре по customer_id,
   поэтому по каждому занятию добираем детали запросом по одному из его учеников. */
function alfa_realization_day(string $date, ?array $branchFilter = null): array {
    $date = alfa_iso($date);
    $host = 'https://' . alfa_host(); $token = alfa_token();
    $branchFilter = $branchFilter ? array_values(array_filter(array_map('intval', $branchFilter))) : null;
    $branches = $branchFilter ?: alfa_all_branch_ids();   // null = все филиалы (для разведки); иначе только выбранные
    $PER = 50; $MAXP = 60;
    $les = []; $statusHist = []; $perBranch = [];
    foreach ($branches as $bid) {
        $before = count($les);
        for ($p = 0; $p < $MAXP; $p++) {
            $r = alfa_http('POST', "$host/v2api/$bid/lesson/index",
                ['date_from' => $date, 'date_to' => $date, 'b_date' => $date, 'e_date' => $date, 'page' => $p, 'count' => $PER], $token, true, 15);
            $items = isset($r['__err']) ? [] : ($r['items'] ?? []);
            foreach ($items as $ls) {
                if (!is_array($ls)) continue;
                $d = substr((string)($ls['date'] ?? ''), 0, 10);
                if ($d !== '' && $d !== $date) continue;
                $st = isset($ls['status']) ? (int)$ls['status'] : -1;
                $statusHist[(string)$st] = ($statusHist[(string)$st] ?? 0) + 1;
                // 3 = проведён (факт реализации). Остальные (запланированные) считаем отдельно —
                // это «прогноз по Alfa»: сколько ожидается списаний по расписанию.
                if ($st !== 3 && $st !== 1 && $st !== 2) continue;   // отменённые/удалённые не берём
                $mins = 0;
                $tf = strtotime((string)($ls['time_from'] ?? '')); $tt = strtotime((string)($ls['time_to'] ?? ''));
                if ($tf && $tt && $tt > $tf) $mins = (int)round(($tt - $tf) / 60);
                $les[] = ['branch' => (int)$bid, 'id' => (int)($ls['id'] ?? 0), 'done' => ($st === 3),
                          'teachers' => array_values(array_map('intval', (array)($ls['teacher_ids'] ?? []))),
                          'minutes' => $mins, 'subject' => (int)($ls['subject_id'] ?? 0),
                          'cids' => array_values(array_map('intval', (array)($ls['customer_ids'] ?? [])))];
            }
            if (count($items) < $PER) break;
        }
        $perBranch[$bid] = count($les) - $before;
    }
    $present = 0.0; $all = 0.0; $nPresent = 0; $nAll = 0; $processed = 0; $noDet = 0;
    $planned = 0.0; $nPlanned = 0; $plannedLessons = 0; $doneLessons = 0;
    $sampleDetail = null; $samplePlanned = null; $cache = []; $byBranch = []; $byTeacher = []; $wageRows = [];
    foreach ($les as $L) {
        $bid = (int)$L['branch'];
        if (!isset($byBranch[$bid])) $byBranch[$bid] = ['present' => 0.0, 'all' => 0.0, 'lessons' => 0];
        if ($L['done']) { $doneLessons++; $byBranch[$bid]['lessons']++; } else $plannedLessons++;
        $cid = (int)($L['cids'][0] ?? 0); if (!$cid) { $noDet++; continue; }
        $ck = $bid . ':' . $cid;
        if (!isset($cache[$ck])) {
            $rr = alfa_http('POST', "$host/v2api/$bid/lesson/index",
                ['customer_id' => $cid, 'date_from' => $date, 'date_to' => $date, 'page' => 0, 'count' => 50], $token, true, 12);
            $cache[$ck] = isset($rr['__err']) ? [] : ($rr['items'] ?? []);
        }
        $found = null; foreach ($cache[$ck] as $x) { if ((int)($x['id'] ?? 0) === $L['id']) { $found = $x; break; } }
        if (!$found) { $noDet++; continue; }
        $processed++;
        // сколько детей реально пришло на это занятие — нужно для расчёта ЗП по сетке ставок
        if ($L['done']) {
            $att = 0;
            foreach ((array)($found['details'] ?? []) as $d2) { if (is_array($d2) && !empty($d2['is_attend'])) $att++; }
            foreach (($L['teachers'] ?? []) as $tid0) {
                if (!isset($wageRows[$tid0])) $wageRows[$tid0] = [];
                $k2 = ((int)$L['subject']) . '|' . $att . '|' . ((int)$L['minutes']);
                $wageRows[$tid0][$k2] = ($wageRows[$tid0][$k2] ?? 0) + 1;
            }
        }
        foreach ((array)($found['details'] ?? []) as $dt) {
            if (!is_array($dt)) continue;
            $c = (float)($dt['commission'] ?? 0);
            if (!$L['done']) {   // запланированное занятие — ожидаемое списание (прогноз)
                $planned += $c; $nPlanned++;
                if ($samplePlanned === null) $samplePlanned = $dt;
                continue;
            }
            $att = !empty($dt['is_attend']);
            $all += $c; $nAll++; $byBranch[$bid]['all'] += $c;
            if ($att) { $present += $c; $nPresent++; $byBranch[$bid]['present'] += $c; }
            // разбивка по педагогам — для «Рейтинга педагогов» (копится тем же проходом)
            foreach (($L['teachers'] ?? []) as $tid) {
                if (!isset($byTeacher[$tid])) $byTeacher[$tid] = ['lessons' => 0, 'minutes' => 0, 'seats' => 0, 'revenue' => 0.0, '_les' => []];
                $byTeacher[$tid]['revenue'] += $c;
                if ($att) $byTeacher[$tid]['seats']++;
                if (!isset($byTeacher[$tid]['_les'][$L['id']])) {
                    $byTeacher[$tid]['_les'][$L['id']] = 1;
                    $byTeacher[$tid]['lessons']++;
                    $byTeacher[$tid]['minutes'] += (int)($L['minutes'] ?? 0);
                }
            }
            if ($sampleDetail === null) $sampleDetail = $dt;
        }
    }
    /* ⚠️ «Ожидается» отдельным запросом со status=1: lesson/index по умолчанию отдаёт ТОЛЬКО
       проведённые (status=3), поэтому запланированные занятия выше не приходят и колонка была
       пустой. У них Alfa сама проставляет списания в details.commission — просто складываем. */
    if ($planned <= 0) {
        foreach ($branches as $bid) {
            $bid = (int)$bid;
            for ($p = 0; $p < $MAXP; $p++) {
                $r = alfa_http('POST', "$host/v2api/$bid/lesson/index",
                    ['status' => 1, 'date_from' => $date, 'date_to' => $date, 'page' => $p, 'count' => $PER], $token, true, 15);
                $items = isset($r['__err']) ? [] : ($r['items'] ?? []);
                foreach ($items as $ls) {
                    if (!is_array($ls)) continue;
                    $d = substr((string)($ls['date'] ?? ''), 0, 10);
                    if ($d !== '' && $d !== $date) continue;
                    $plannedLessons2 = true; $plannedLessonsCnt = ($plannedLessonsCnt ?? 0) + 1;
                    foreach ((array)($ls['details'] ?? []) as $dt) {
                        if (!is_array($dt)) continue;
                        $planned += (float)($dt['commission'] ?? 0); $nPlanned++;
                        if ($samplePlanned === null) $samplePlanned = $dt;
                    }
                }
                if (count($items) < $PER) break;
            }
        }
        if (isset($plannedLessonsCnt)) $plannedLessons = (int)$plannedLessonsCnt;
    }
    foreach ($byBranch as &$b) { $b['present'] = round($b['present'], 2); $b['all'] = round($b['all'], 2); } unset($b);
    foreach ($byTeacher as &$t) { unset($t['_les']); $t['revenue'] = round($t['revenue'], 2); } unset($t);
    foreach ($wageRows as $tid0 => $rows0) { if (isset($byTeacher[$tid0])) $byTeacher[$tid0]['rows'] = $rows0; }
    return ['date' => $date, 'lessons' => $doneLessons, 'plannedLessons' => $plannedLessons, 'byTeacher' => $byTeacher,
            'perBranch' => $perBranch, 'statusHist' => $statusHist,
            'branchesUsed' => array_values($branches), 'branchNames' => alfa_branch_names(), 'byBranch' => $byBranch,
            'realizationPresent' => round($present, 2), 'realizationAll' => round($all, 2),
            'realizationPlanned' => round($planned, 2), 'plannedCount' => $nPlanned,
            'attendedCount' => $nPresent, 'chargedCount' => $nAll,
            'lessonsProcessed' => $processed, 'lessonsNoDetails' => $noDet,
            'sampleDetail' => $sampleDetail, 'samplePlanned' => $samplePlanned];
}

/* --- Хранилище посчитанной реализации по дням ---
   Файл лежит рядом с прокси, но с непредсказуемым (солёным) именем — прямой веб-доступ
   к нему нереален, а данные — только суммы за день (не персональные). Переживает деплой
   (в git не коммитим). Пишем атомарно. */
function alfa_store_dir(): string {
    $d = __DIR__ . '/store';
    if (!is_dir($d)) { @mkdir($d, 0770, true); @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n"); }
    return $d;
}
function alfa_realization_store_path(): string {
    $salt = substr(hash('sha256', __DIR__ . '|realization'), 0, 24);
    return alfa_store_dir() . '/realization_' . $salt . '.json';
}
function alfa_realization_store_read(): array {
    $f = alfa_realization_store_path();
    if (!is_file($f)) return [];
    $j = json_decode((string)@file_get_contents($f), true);
    return is_array($j) ? $j : [];
}
function alfa_realization_store_write(array $data): void {
    $f = alfa_realization_store_path();
    $tmp = $f . '.' . getmypid() . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (@file_put_contents($tmp, $json, LOCK_EX) !== false) { @chmod($tmp, 0660); @rename($tmp, $f); }
    else @file_put_contents($f, $json, LOCK_EX);
}
/* Филиалы, входящие в клубную реализацию. По умолчанию — «Пожарный» (по названию, не по id:
   id разнятся между копиями CRM). «Детали» (взрослое) и прочее сюда НЕ входят. Настройка —
   config['realization_branch_names'] (список подстрок). Возвращает id филиалов Alfa. */
function alfa_branch_ids_by_name(array $needles): array {
    $names = alfa_branch_names();   // [id => name]
    $out = [];
    foreach ($names as $id => $nm) {
        foreach ($needles as $nd) { $nd = trim((string)$nd);
            if ($nd !== '' && mb_stripos((string)$nm, $nd) !== false) { $out[] = (int)$id; break; } }
    }
    return array_values(array_unique($out));
}
function alfa_realization_branches(): array {
    $n = cfg()['realization_branch_names'] ?? ['Пожарный'];
    return alfa_branch_ids_by_name(is_array($n) ? $n : ['Пожарный']);
}
/* Посчитать реализацию за день по выбранным филиалам и записать в хранилище. */
function alfa_realization_upsert(string $date, ?array $branches = null): array {
    $r = alfa_realization_day($date, $branches);
    $row = ['present' => $r['realizationPresent'], 'all' => $r['realizationAll'],
            'planned' => $r['realizationPlanned'], 'lessons' => $r['lessons'],
            'plannedLessons' => $r['plannedLessons'], 'byTeacher' => $r['byTeacher'] ?? [], 'ts' => date('c')];
    $s = alfa_realization_store_read();
    /* ⚠️ ЗАМОРОЖЕННОЕ «ожидалось» (expect) переносим из старой строки. Иначе его затирал бы
       этот же ежедневный пересчёт: по мере проведения занятий planned падает в ноль, и к концу
       недели сравнивать факт становится не с чем. Заполняется один раз (alfa_expect_freeze). */
    $old = $s[$r['date']] ?? null;
    if (is_array($old) && isset($old['expect'])) {
        $row['expect'] = $old['expect'];
        if (isset($old['expectTs'])) $row['expectTs'] = $old['expectTs'];
    }
    $s[$r['date']] = $row;
    ksort($s);
    alfa_realization_store_write($s);
    return ['date' => $r['date']] + $row;
}
/* Заморозить «ожидалось» на неделю (пн–вс): по каждому дню записать текущее planned в expect
   и больше никогда его не трогать. Делается раз в неделю (вс 22:00, вместе со снимком прогноза):
   потом реализация сравнивается с тем, что ожидали ДО начала недели, а не с остатком расписания.
   $force — перезаписать уже замороженное (только по явной просьбе с экрана). */
function alfa_expect_freeze(string $mondayIso, ?array $branches = null, bool $force = false): array {
    $mon = alfa_monday_of($mondayIso);
    $s = alfa_realization_store_read();
    $set = 0; $kept = 0; $late = 0; $days = [];
    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime("+$i day", strtotime($mon)));
        $row = $s[$d] ?? null;
        if (!is_array($row)) { $days[$d] = null; continue; }
        if (isset($row['expect']) && !$force) { $kept++; $days[$d] = (float)$row['expect']; continue; }
        /* ⚠️ День, который УЖЕ ПРОШЁЛ, замораживать нечем: занятия проведены, planned стёк в ноль.
           Записать сюда 0 — значит навсегда зафиксировать «ожидали ноль» и испортить и день, и месячный
           %, причём снять это можно будет только через force. Отличаем «день прошёл»
           (planned=0, но занятия были) от «в этот день и не планировали» (planned=0, занятий 0):
           второе — честный ноль, его морозим. Прошедшие дни восстанавливаются отдельно,
           по регулярному расписанию (alfa_expect_rebuild_week). */
        if ((float)($row['planned'] ?? 0) <= 0 && (int)($row['lessons'] ?? 0) > 0) {
            $late++; $days[$d] = null; continue;
        }
        $row['expect'] = round((float)($row['planned'] ?? 0), 2);
        $row['expectTs'] = date('c');
        $s[$d] = $row; $set++; $days[$d] = (float)$row['expect'];
    }
    if ($set) { ksort($s); alfa_realization_store_write($s); }
    return ['week' => $mon, 'frozen' => $set, 'kept' => $kept, 'late' => $late, 'days' => $days,
            'total' => round(array_sum(array_map('floatval', array_filter($days, 'is_numeric'))), 2)];
}
function alfa_cron_key(): string { return (string)(cfg()['cron_key'] ?? ''); }

/* ===== ПРОГНОЗ ОПЛАТЫ «как в Alfa» =====
   В Alfa есть отчёт «Прогноз оплаты» (Расход за период), но наружу v2api его не отдаёт (404).
   Механику повторяем: будущих уроков в lesson/index нет, зато есть РЕГУЛЯРНОЕ расписание.
   За неделю каждое активное регулярное занятие проходит ровно 1 раз, поэтому:
     уроки = Σ по группам (регулярных занятий группы × число зачисленных),
     расход = Σ по каждому ученику (его уроки × цена его занятия по абонементу).
   Цена берётся из абонемента ученика (customer-tariff): так учитываются скидки и майские цены. */
/* Кэш цены занятия по ученику (файл, живёт сутки): без него каждый расчёт прогноза
   делает ~600 запросов к Alfa и шлюз рвёт связь по таймауту. */
function alfa_price_cache_path(): string {
    $salt = substr(hash('sha256', __DIR__ . '|lessonprice3'), 0, 24);   // v3: цена из шаблона + окно недели
    return alfa_store_dir() . '/lessonprice_' . $salt . '.json';
}
/* Справочник шаблонов тарифов: id => [цена, число уроков, тип]. Цена занятия ученика берётся
   именно отсюда: у абонементов на новый год balance=0 (деньги ещё не внесены), поэтому
   «остаток / число уроков» не работает. Кэшируем на сутки. */
/* Разбор одной записи справочника: поля подтверждены ответом Alfa —
   price («264.00») и lessons_count (8) → цена занятия = 264/8 = 33. */
function alfa_tariff_row(array $t): array {
    $price = (isset($t['price']) && is_numeric($t['price'])) ? (float)$t['price'] : null;
    $cnt = 0;
    foreach (['lessons_count', 'lesson_count', 'count'] as $k) {
        if (isset($t[$k]) && is_numeric($t[$k]) && (float)$t[$k] > 0) { $cnt = (float)$t[$k]; break; }
    }
    return ['price' => $price, 'count' => $cnt, 'name' => (string)($t['name'] ?? ''),
            'per' => ($price !== null && $price > 0) ? ($cnt > 0 ? $price / $cnt : $price) : null];
}
function alfa_tariff_map(int $branch): array {
    static $memo = null;
    if ($memo !== null) return $memo;
    $f = alfa_store_dir() . '/tariffs_' . substr(hash('sha256', __DIR__ . '|tariffs2'), 0, 20) . '.json';
    if (is_file($f)) {
        $j = json_decode((string)@file_get_contents($f), true);
        // ⚠️ пустой кэш не принимаем: один неудачный обход иначе «залипал» бы на сутки
        if (is_array($j) && (int)($j['ts'] ?? 0) > time() - 86400 && is_array($j['m'] ?? null) && count($j['m']) > 5) return $memo = $j['m'];
    }
    $map = [];
    foreach (alfa_all_branch_ids() as $bid) {
        $r = alfa_index_all((int)$bid, 'tariff', [], 30, 12);
        foreach ($r['items'] as $t) {
            $id = (int)($t['id'] ?? 0); if (!$id || isset($map[$id])) continue;
            $map[$id] = alfa_tariff_row($t);
        }
    }
    if (count($map) > 5) @file_put_contents($f, json_encode(['ts' => time(), 'm' => $map], JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $memo = $map;
}
/* Справочник тарифов ПО ССЫЛКЕ: догруженные точечно тарифы должны оставаться доступными
   всем последующим ученикам в этом же запросе. */
function &alfa_tariff_map_ref(int $branch = 0): array {
    static $m = null;
    if ($m === null) $m = alfa_tariff_map($branch);
    return $m;
}
/* Догрузка тарифа, которого не оказалось в общем обходе (архивные/чужой филиал).
   Alfa игнорирует фильтр id в теле, поэтому ищем нужный id среди страниц сами. */
function alfa_tariff_one(int $branch, int $tariffId, array &$map): ?array {
    if (isset($map[$tariffId])) return $map[$tariffId];
    $host = 'https://' . alfa_host(); $token = alfa_token();
    foreach ([$branch] + alfa_all_branch_ids() as $bid) {
        $bid = (int)$bid;
        for ($p = 0; $p < 30; $p++) {
            $r = alfa_http('POST', "$host/v2api/$bid/tariff/index", ['page' => $p, 'count' => 50], $token, true, 10);
            if (isset($r['__err'])) break;
            $items = $r['items'] ?? [];
            foreach ($items as $t) {
                $id = (int)($t['id'] ?? 0); if (!$id) continue;
                if (!isset($map[$id])) $map[$id] = alfa_tariff_row($t);
                if ($id === $tariffId) return $map[$tariffId];
            }
            if (count($items) < 50) break;
        }
    }
    return null;
}
function alfa_price_cache_read(): array {
    $f = alfa_price_cache_path();
    if (!is_file($f)) return [];
    $j = json_decode((string)@file_get_contents($f), true);
    if (!is_array($j) || (int)($j['ts'] ?? 0) < time() - 86400) return [];   // сутки
    return is_array($j['p'] ?? null) ? $j['p'] : [];
}
function alfa_price_cache_write(array $prices): void {
    $f = alfa_price_cache_path();
    $tmp = $f . '.' . getmypid() . '.tmp';
    $json = json_encode(['ts' => time(), 'p' => $prices], JSON_UNESCAPED_UNICODE);
    if (@file_put_contents($tmp, $json, LOCK_EX) !== false) { @chmod($tmp, 0660); @rename($tmp, $f); }
    else @file_put_contents($f, $json, LOCK_EX);
}
/* Цена занятия ученика на дату: берём его абонемент, действующий на эту дату (и, если можно,
   по нужному предмету), и цену из ШАБЛОНА тарифа. Кэш — по «ученик|предмет». */
function alfa_lesson_price_of(int $branch, int $customerId, array &$cache, array &$dbg, int $subjectId = 0, string $from = '', string $to = ''): float {
    $ck = $customerId . '|' . $subjectId;
    if (isset($cache[$ck])) return (float)$cache[$ck];
    $r = alfa_customer_tariffs($branch, $customerId);
    $tm = &alfa_tariff_map_ref($branch);   // по ссылке: догруженные тарифы копятся на весь запрос
    /* ⚠️ Сверяем ПЕРЕСЕЧЕНИЕ периода абонемента с НЕДЕЛЕЙ, а не «действует ли в понедельник»:
       абонементы на новый год начинаются 02.09, а неделя стартует 31.08 — при проверке по одной
       дате отсеивались почти все ученики и прогноз выходил нулевым. */
    $from = $from !== '' ? alfa_iso($from) : date('Y-m-d');
    $to   = $to   !== '' ? alfa_iso($to)   : $from;
    $bySubject = null; $anyActive = null; $anyAtAll = null;
    foreach ($r['items'] as $t) {
        if (!is_array($t) || !empty($t['is_archive'])) continue;
        $tid = (int)($t['tariff_id'] ?? 0); if (!$tid) continue;
        if (!isset($tm[$tid])) alfa_tariff_one($branch, $tid, $tm);   // нет в обходе — догружаем
        $per = $tm[$tid]['per'] ?? null; if ($per === null || $per <= 0) continue;
        if ($anyAtAll === null) $anyAtAll = (float)$per;
        $b = alfa_iso((string)($t['b_date'] ?? '')); $e = alfa_iso((string)($t['e_date'] ?? ''));
        $overlaps = ($b === '' || $b <= $to) && ($e === '' || $e >= $from);
        if (!$overlaps) continue;
        if ($anyActive === null) $anyActive = (float)$per;
        $sids = array_map('intval', (array)($t['subject_ids'] ?? []));
        if ($subjectId && in_array($subjectId, $sids, true)) { $bySubject = (float)$per; break; }
    }
    $price = $bySubject ?? $anyActive ?? 0.0;   // предмет → любой действующий на неделе → нет цены
    if ($price <= 0 && count($dbg) < 3) $dbg[] = ['customer_id' => $customerId, 'subject' => $subjectId,
                                                 'tariffs' => array_slice($r['items'], 0, 2)];
    $cache[$ck] = $price;
    return $price;
}
/* ТОЧНЫЙ ПРОГНОЗ ЗА ДЕНЬ: по реально запланированным занятиям Alfa (status=1).
   Документация: lesson/index по умолчанию отдаёт только проведённые (status=3), поэтому будущие
   «не находились». Так считает и сам отчёт «Прогноз оплаты»: списания по запланированным урокам.
   Участники берутся из details, а если их нет — из customer_ids занятия. */
function alfa_forecast_lessons_day(string $date, ?array $branches = null): array {
    $date = alfa_iso($date);
    $branches = $branches ?: alfa_realization_branches();
    $host = 'https://' . alfa_host(); $token = alfa_token();
    $priceCache = alfa_price_cache_read(); $before = count($priceCache);
    $sum = 0.0; $lessons = 0; $seats = 0; $noPrice = 0; $fallbackSeats = 0; $dbg = []; $PER = 50;
    foreach ($branches as $bid) {
        $bid = (int)$bid;
        for ($p = 0; $p < 40; $p++) {
            $r = alfa_http('POST', "$host/v2api/$bid/lesson/index",
                ['status' => 1, 'date_from' => $date, 'date_to' => $date, 'page' => $p, 'count' => $PER], $token, true, 15);
            $items = isset($r['__err']) ? [] : ($r['items'] ?? []);
            foreach ($items as $ls) {
                if (!is_array($ls)) continue;
                $d = substr((string)($ls['date'] ?? ''), 0, 10);
                if ($d !== '' && $d !== $date) continue;
                $lessons++;
                $subj = (int)($ls['subject_id'] ?? 0);
                /* 🔑 У запланированных занятий Alfa САМА проставляет сумму списания в details.commission
                   (проверено: сумма за неделю совпала с её отчётом «Прогноз оплаты» до копейки).
                   Поэтому цену не реконструируем — просто складываем. */
                $det = (array)($ls['details'] ?? []);
                if ($det) {
                    foreach ($det as $dt) {
                        if (!is_array($dt)) continue;
                        $sum += (float)($dt['commission'] ?? 0); $seats++;
                    }
                    continue;
                }
                // у занятия нет участников (Alfa их ещё не разложила) — считаем по составу и тарифу
                foreach (array_unique(array_map('intval', (array)($ls['customer_ids'] ?? []))) as $cid) {
                    if (!$cid) continue;
                    $pr = alfa_lesson_price_of($bid, (int)$cid, $priceCache, $dbg, $subj, $date, $date);
                    if ($pr <= 0) $noPrice++;
                    $sum += $pr; $seats++; $fallbackSeats++;
                }
            }
            if (count($items) < $PER) break;
        }
    }
    if (count($priceCache) !== $before) alfa_price_cache_write($priceCache);
    return ['date' => $date, 'forecast' => round($sum, 2), 'lessons' => $lessons,
            'seats' => $seats, 'withoutPrice' => $noPrice, 'fallbackSeats' => $fallbackSeats];
}

/* Шаг 1: какие группы и сколько раз занимаются на этой неделе (только regular-lesson — быстро). */
function alfa_forecast_plan(string $mondayIso, ?array $branches = null): array {
    $mon = alfa_monday_of($mondayIso);
    $sun = date('Y-m-d', strtotime('+6 day', strtotime($mon)));
    $branches = $branches ?: alfa_realization_branches();
    $out = [];
    foreach ($branches as $bid) {
        $bid = (int)$bid;
        $rr = alfa_index_all($bid, 'regular-lesson', [], 40, 15);
        $per = [];
        foreach ($rr['items'] as $rl) {
            if (!is_array($rl)) continue;
            $gid = (int)($rl['related_id'] ?? 0); if (!$gid) continue;
            $b = alfa_iso((string)($rl['b_date_v'] ?? ($rl['b_date'] ?? '')));
            $e = alfa_iso((string)($rl['e_date_v'] ?? ($rl['e_date'] ?? '')));
            if ($b !== '' && $b > $sun) continue;
            if ($e !== '' && $e < $mon) continue;
            if (!isset($per[$gid])) $per[$gid] = ['times' => 0, 'subject' => (int)($rl['subject_id'] ?? 0)];
            $per[$gid]['times']++;                   // за неделю каждое регулярное занятие = 1 раз
        }
        foreach ($per as $gid => $v) $out[] = ['id' => (int)$gid, 'branch' => $bid,
                                               'times' => (int)$v['times'], 'subject' => (int)$v['subject']];
    }
    return ['week' => $mon, 'to' => $sun, 'groups' => $out];
}
/* Шаг 2: прогноз по ПАЧКЕ групп (клиент шлёт чанками — иначе шлюз рвёт связь по таймауту). */
/* $from/$to — окно, в котором проверяется зачисление ученика (cgi) и действует цена.
   По умолчанию это вся неделя; восстановление ожидания за прошедший день передаёт один день,
   иначе в него попали бы дети, зачисленные позже в ту же неделю. */
function alfa_forecast_groups(string $mondayIso, array $groups, ?string $from = null, ?string $to = null): array {
    $mon = $from !== null ? alfa_iso($from) : alfa_monday_of($mondayIso);
    $sun = $to !== null ? alfa_iso($to) : date('Y-m-d', strtotime('+6 day', strtotime(alfa_monday_of($mondayIso))));
    $host = 'https://' . alfa_host(); $token = alfa_token();
    $priceCache = alfa_price_cache_read(); $before = count($priceCache);
    $sum = 0.0; $lessons = 0; $students = 0; $noPrice = 0; $dbg = []; $noPriceIds = [];
    foreach ($groups as $g) {
        $gid = (int)($g['id'] ?? 0); $bid = (int)($g['branch'] ?? 0); $times = (int)($g['times'] ?? 1);
        $subj = (int)($g['subject'] ?? 0);
        if (!$gid || !$bid) continue;
        $seen = [];
        foreach ([['?group_id=' . $gid, ['group_id' => $gid]],
                  ['?group_id=' . $gid . '&date_from=' . $mon . '&date_to=' . $sun,
                   ['group_id' => $gid, 'date_from' => $mon, 'date_to' => $sun]]] as $v) {
            $r = alfa_http('POST', "$host/v2api/$bid/cgi/index" . $v[0], array_merge($v[1], ['page' => 0, 'count' => 200]), $token, true, 12);
            if (isset($r['__err'])) continue;
            foreach (($r['items'] ?? []) as $it) {
                if ((int)($it['group_id'] ?? 0) !== $gid) continue;
                $cid = (int)($it['customer_id'] ?? 0); if (!$cid || isset($seen[$cid])) continue;
                $bb = alfa_iso((string)($it['b_date'] ?? '')); $ee = alfa_iso((string)($it['e_date'] ?? ''));
                if ($bb !== '' && $bb > $sun) continue;
                if ($ee !== '' && $ee < $mon) continue;
                $seen[$cid] = 1;
            }
        }
        foreach (array_keys($seen) as $cid) {
            $p = alfa_lesson_price_of($bid, (int)$cid, $priceCache, $dbg, $subj, $mon, $sun);
            if ($p <= 0) { $noPrice++; if (count($noPriceIds) < 8) $noPriceIds[] = ['id' => (int)$cid, 'branch' => $bid]; }
            $sum += $p * $times; $lessons += $times; $students++;
        }
    }
    if (count($priceCache) !== $before) alfa_price_cache_write($priceCache);
    $tmRef = &alfa_tariff_map_ref();
    return ['week' => $mon, 'forecast' => round($sum, 2), 'lessons' => $lessons,
            'students' => $students, 'withoutPrice' => $noPrice, 'noPriceIds' => $noPriceIds,
            'tariffMapSize' => count($tmRef), 'sampleTariffs' => $dbg];
}
/* Прогноз на неделю целиком (для cron: там ограничения по времени мягче). */
function alfa_forecast_week(string $mondayIso, ?array $branches = null): array {
    $mon = alfa_monday_of($mondayIso);
    $sun = date('Y-m-d', strtotime('+6 day', strtotime($mon)));
    $branches = $branches ?: alfa_realization_branches();
    $sum = 0.0; $lessonsCount = 0; $noPrice = 0; $dbg = [];
    // Считаем ровно теми же функциями, что и клиент (план → пачки групп), чтобы логика была одна.
    $plan = alfa_forecast_plan($mon, $branches);
    foreach (array_chunk($plan['groups'], 12) as $chunk) {
        $r = alfa_forecast_groups($mon, $chunk);
        $sum += (float)$r['forecast']; $lessonsCount += (int)$r['lessons']; $noPrice += (int)$r['withoutPrice'];
        if (!$dbg && !empty($r['sampleTariffs'])) $dbg = $r['sampleTariffs'];
    }
    $groupsUsed = count($plan['groups']);
    return ['week' => $mon, 'to' => $sun, 'forecast' => round($sum, 2), 'lessons' => $lessonsCount,
            'groups' => $groupsUsed, 'withoutPrice' => $noPrice, 'sampleTariffs' => $dbg];
}

/* ===== ВОССТАНОВЛЕНИЕ «ОЖИДАЛОСЬ» ЗА ПРОШЕДШУЮ НЕДЕЛЮ =====
   Если неделю не заморозили вовремя, ожидание уже не достать из lesson/index: проведённые
   занятия перестали быть запланированными и planned = 0. Единственный уцелевший след того,
   что ДОЛЖНО было пройти, — регулярное расписание (regular-lesson) плюс состав групп на ту дату.
   ⚠️ Нумерация поля day в Alfa не документирована (PUBLISH_GROUPS.md: «сверить нумерацию»),
   поэтому её здесь не угадывают, а ПОДБИРАЮТ: эталонную неделю, где подневное число
   запланированных занятий уже известно из хранилища, считают всеми режимами и берут тот,
   что сошёлся точно. Не сошёлся ни один — не пишем ничего и говорим об этом прямо.
   Деньги — слишком дорогая цена за красивое предположение. */
const ALFA_DAY_MODES = ['iso', 'zero', 'sun1'];

/* day из Alfa → день недели ISO (1=пн … 7=вс). Чистая функция, покрыта тестом. */
function alfa_day_to_iso(int $day, string $mode): ?int {
    if ($mode === 'zero') { $d = $day + 1; return ($d >= 1 && $d <= 7) ? $d : null; }   // 0=пн … 6=вс
    if ($mode === 'sun1') { $d = $day - 1; return $d === 0 ? 7 : (($d >= 1 && $d <= 6) ? $d : null); }  // 1=вс, 2=пн …
    return ($day >= 1 && $day <= 7) ? $day : null;                                      // iso: 1=пн … 7=вс
}
/* Разложить регулярные занятия по дням недели ISO, сохранив период действия слота. */
function alfa_regular_by_iso_dow(array $items, string $mode): array {
    $out = [];
    foreach ($items as $rl) {
        if (!is_array($rl)) continue;
        $gid = (int)($rl['related_id'] ?? 0); if (!$gid) continue;
        $dow = alfa_day_to_iso((int)($rl['day'] ?? -1), $mode);
        if ($dow === null) continue;
        $out[$dow][] = ['id' => $gid, 'branch' => (int)($rl['__branch'] ?? 0),
                        'subject' => (int)($rl['subject_id'] ?? 0),
                        'from' => alfa_iso((string)($rl['b_date_v'] ?? ($rl['b_date'] ?? ''))),
                        'to'   => alfa_iso((string)($rl['e_date_v'] ?? ($rl['e_date'] ?? '')))];
    }
    return $out;
}
/* Какие группы шли в каждый день недели (с учётом периода действия слота). */
function alfa_regular_days_of_week(array $byDow, string $mon): array {
    $out = [];
    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime("+$i day", strtotime($mon)));
        $dow = (int)date('N', strtotime($d));
        $list = [];
        foreach ($byDow[$dow] ?? [] as $g) {
            if ($g['from'] !== '' && $g['from'] > $d) continue;
            if ($g['to'] !== '' && $g['to'] < $d) continue;
            $list[] = $g;
        }
        $out[$d] = $list;
    }
    return $out;
}
/* Подбор режима нумерации: сверяем расчётное число занятий по дням эталонной недели
   с фактическим plannedLessons из хранилища. Целые числа — сигнал надёжнее денег. */
function alfa_day_mode_pick(array $items, string $refMon, array $refLessonsPerDate): array {
    $score = [];
    foreach (ALFA_DAY_MODES as $mode) {
        $days = alfa_regular_days_of_week(alfa_regular_by_iso_dow($items, $mode), $refMon);
        $diff = 0; $cmp = 0; $exact = 0; $calc = [];
        foreach ($days as $d => $list) {
            $calc[$d] = count($list);
            if (!array_key_exists($d, $refLessonsPerDate)) continue;
            $cmp++; $n = (int)$refLessonsPerDate[$d];
            $diff += abs($calc[$d] - $n);
            if ($calc[$d] === $n) $exact++;
        }
        $score[] = ['mode' => $mode, 'diff' => $diff, 'compared' => $cmp, 'exact' => $exact, 'calc' => $calc];
    }
    usort($score, function ($a, $b) { return [$a['diff'], -$a['exact']] <=> [$b['diff'], -$b['exact']]; });
    return $score;
}
/* Режим засчитан только если он сошёлся ТОЧНО по всем сверенным дням и при этом
   единственный такой: два одинаково хороших режима означают, что неделя их не различает
   (например, занятия стоят симметрично) — тогда выбирать наугад нельзя. */
function alfa_day_mode_verdict(array $score): array {
    $best = $score[0] ?? null;
    if (!$best || $best['compared'] < 3) return ['ok' => false, 'why' => 'мало дней для сверки', 'score' => $score];
    if ($best['diff'] !== 0) return ['ok' => false, 'why' => 'ни один режим не сошёлся с расписанием', 'score' => $score];
    $ties = 0;
    foreach ($score as $s) if ($s['diff'] === 0) $ties++;
    if ($ties > 1) return ['ok' => false, 'why' => 'эталонная неделя не различает режимы нумерации', 'score' => $score];
    return ['ok' => true, 'mode' => $best['mode'], 'score' => $score];
}

/* Регулярное расписание клубных филиалов целиком (с пометкой филиала — она нужна для цен). */
function alfa_regular_all(?array $branches = null): array {
    $branches = $branches ?: alfa_realization_branches();
    $items = [];
    foreach ($branches as $bid) {
        $bid = (int)$bid;
        $rr = alfa_index_all($bid, 'regular-lesson', [], 40, 15);
        foreach (($rr['items'] ?? []) as $rl) {
            if (!is_array($rl)) continue;
            $rl['__branch'] = $bid;
            $items[] = $rl;
        }
    }
    return $items;
}
/* Эталон для калибровки — неделя ЦЕЛИКОМ БУДУЩАЯ, у которой в хранилище уже есть подневное
   число запланированных занятий. Только на такой неделе Alfa показывает расписание как план,
   и его можно сверить с regular-lesson. Прошедшая неделя для сверки не годится: там занятия
   уже проведены и plannedLessons давно ноль. */
function alfa_expect_calib_week(array $store, ?string $prefer = null): ?string {
    $today = date('Y-m-d');
    $cands = [];
    if ($prefer) $cands[] = alfa_monday_of(alfa_iso($prefer));
    $start = alfa_monday_of(date('Y-m-d', strtotime('+7 day')));
    for ($w = 0; $w < 6; $w++) $cands[] = date('Y-m-d', strtotime('+' . ($w * 7) . ' day', strtotime($start)));
    foreach ($cands as $m) {
        $full = true; $lessons = 0;
        for ($i = 0; $i < 7; $i++) {
            $d = date('Y-m-d', strtotime("+$i day", strtotime($m)));
            $r = $store[$d] ?? null;
            if ($d <= $today || !is_array($r)) { $full = false; break; }
            $lessons += (int)($r['plannedLessons'] ?? 0);
        }
        if ($full && $lessons > 0) return $m;
    }
    return null;
}
/* Восстановить «ожидалось» по дням недели из регулярного расписания.
   $apply=false (по умолчанию) — только показать расчёт и сверку, ничего не записывая.
   Уже зафиксированное expect не трогаем никогда: снимок, снятый вовремя, точнее реконструкции. */
function alfa_expect_rebuild_week(string $mondayIso, ?array $branches = null, bool $apply = false, ?string $calibWeek = null): array {
    $mon = alfa_monday_of(alfa_iso($mondayIso));
    $branches = $branches ?: alfa_realization_branches();
    $store = alfa_realization_store_read();

    $ref = alfa_expect_calib_week($store, $calibWeek);
    if ($ref === null) {
        return ['ok' => false, 'week' => $mon,
                'why' => 'не на чем откалибровать нумерацию дней: нужна целиком будущая неделя с расписанием в хранилище — сначала нажмите «Обновить месяц из Alfa»'];
    }
    $items = alfa_regular_all($branches);
    if (!$items) return ['ok' => false, 'week' => $mon, 'calibWeek' => $ref, 'why' => 'Alfa не отдала регулярное расписание'];

    $refLessons = [];
    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime("+$i day", strtotime($ref)));
        $refLessons[$d] = (int)($store[$d]['plannedLessons'] ?? 0);
    }
    $verdict = alfa_day_mode_verdict(alfa_day_mode_pick($items, $ref, $refLessons));
    if (empty($verdict['ok'])) {
        return ['ok' => false, 'week' => $mon, 'calibWeek' => $ref, 'why' => $verdict['why'],
                'refLessons' => $refLessons, 'score' => $verdict['score']];
    }
    $mode = $verdict['mode'];

    /* Деньги считаем по дням: окно cgi — ровно этот день, иначе в него попали бы дети,
       зачисленные позже в ту же неделю, и ожидание задним числом выросло бы. */
    $calc = function (string $weekMon) use ($items, $mode, $branches) {
        $days = alfa_regular_days_of_week(alfa_regular_by_iso_dow($items, $mode), $weekMon);
        $out = [];
        foreach ($days as $d => $list) {
            if (!$list) { $out[$d] = ['expect' => 0.0, 'groups' => 0, 'students' => 0, 'withoutPrice' => 0]; continue; }
            $sum = 0.0; $st = 0; $np = 0;
            foreach (array_chunk($list, 12) as $chunk) {
                $gs = [];
                foreach ($chunk as $g) $gs[] = ['id' => $g['id'], 'branch' => $g['branch'], 'times' => 1, 'subject' => $g['subject']];
                $r = alfa_forecast_groups($weekMon, $gs, $d, $d);
                $sum += (float)$r['forecast']; $st += (int)$r['students']; $np += (int)$r['withoutPrice'];
            }
            $out[$d] = ['expect' => round($sum, 2), 'groups' => count($list), 'students' => $st, 'withoutPrice' => $np];
        }
        return $out;
    };

    $out = $calc($mon);
    $total = 0.0;
    foreach ($out as $v) $total += (float)$v['expect'];

    /* Сверка на эталонной неделе: там подневное «ожидается» известно по-настоящему (planned),
       и видно, насколько реконструкция вообще похожа на правду. Считаем только в режиме
       предпросмотра — при записи это лишние запросы к Alfa. */
    $check = null;
    if (!$apply) {
        $rc = $calc($ref); $rows = []; $sc = 0.0; $sp = 0.0;
        foreach ($rc as $d => $v) {
            $planned = round((float)($store[$d]['planned'] ?? 0), 2);
            $rows[$d] = ['rebuilt' => $v['expect'], 'planned' => $planned,
                         'diff' => round($v['expect'] - $planned, 2)];
            $sc += $v['expect']; $sp += $planned;
        }
        $check = ['week' => $ref, 'days' => $rows, 'rebuiltTotal' => round($sc, 2), 'plannedTotal' => round($sp, 2),
                  'offPct' => $sp > 0 ? round(100 * ($sc - $sp) / $sp, 1) : null];
    }

    $written = 0; $kept = 0; $noRow = 0;
    if ($apply) {
        foreach ($out as $d => $v) {
            $row = $store[$d] ?? null;
            if (!is_array($row)) { $noRow++; continue; }
            if (isset($row['expect'])) { $kept++; continue; }   // снимок вовремя точнее реконструкции
            $row['expect'] = $v['expect'];
            $row['expectTs'] = date('c');
            $row['expectSrc'] = 'schedule';   // честно помечаем: это восстановлено, а не снято вовремя
            $store[$d] = $row; $written++;
        }
        if ($written) { ksort($store); alfa_realization_store_write($store); }
    }
    return ['ok' => true, 'week' => $mon, 'mode' => $mode, 'calibWeek' => $ref, 'days' => $out,
            'total' => round($total, 2), 'applied' => $apply, 'written' => $written,
            'kept' => $kept, 'noRow' => $noRow, 'check' => $check, 'score' => $verdict['score']];
}

/* --- НЕДЕЛЬНЫЙ ПРОГНОЗ (снимок) ---
   Прогноз надо ЗАФИКСИРОВАТЬ до начала недели: если считать его «на лету», то по мере
   проведения занятий ожидаемое превращается в факт и прогноз сравнивается сам с собой
   (% выполнения всегда 100). Поэтому в вс 23:00 снимаем прогноз на новую неделю и храним. */
function alfa_weekplan_store_path(): string {
    $salt = substr(hash('sha256', __DIR__ . '|weekplan'), 0, 24);
    return alfa_store_dir() . '/weekplan_' . $salt . '.json';
}
function alfa_weekplan_read(): array {
    $f = alfa_weekplan_store_path();
    if (!is_file($f)) return [];
    $j = json_decode((string)@file_get_contents($f), true);
    return is_array($j) ? $j : [];
}
function alfa_weekplan_write(array $d): void {
    $f = alfa_weekplan_store_path();
    $tmp = $f . '.' . getmypid() . '.tmp';
    $json = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (@file_put_contents($tmp, $json, LOCK_EX) !== false) { @chmod($tmp, 0660); @rename($tmp, $f); }
    else @file_put_contents($f, $json, LOCK_EX);
}
/* Понедельник недели, в которую попадает дата (ISO). */
function alfa_monday_of(string $iso): string {
    $ts = strtotime(alfa_iso($iso));
    $wd = (int)date('N', $ts);              // 1=пн … 7=вс
    return date('Y-m-d', strtotime('-' . ($wd - 1) . ' day', $ts));
}
/* Снимок прогноза на неделю: считаем 7 дней и складываем
   ожидаемое (planned) + уже проведённое (среднее без/с пропусками). */
function alfa_weekplan_snapshot(string $mondayIso, ?array $branches = null): array {
    $mon = alfa_monday_of($mondayIso);
    $branches = $branches ?: alfa_realization_branches();
    /* Прогноз недели = сумма списаний по запланированным занятиям Alfa (status=1). Сверено с её
       отчётом «Прогноз оплаты»: совпало до копейки. Источник только Alfa — работает и когда
       финмодель заморожена. Если занятий ещё нет, падаем на расчёт по регулярному расписанию. */
    $fc = ['forecast' => 0.0, 'lessons' => 0, 'groups' => 0];
    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime("+$i day", strtotime($mon)));
        $r = alfa_forecast_lessons_day($d, $branches);
        $fc['forecast'] += (float)$r['forecast']; $fc['lessons'] += (int)$r['lessons'];
    }
    $fc['forecast'] = round($fc['forecast'], 2);
    if ($fc['lessons'] === 0) $fc = alfa_forecast_week($mon, $branches);   // занятий нет — по расписанию
    // заодно обновим дневное хранилище реализации за эту неделю (факт)
    $days = []; $donePart = 0.0;
    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime("+$i day", strtotime($mon)));
        $r = alfa_realization_upsert($d, $branches);
        $donePart += ((float)$r['present'] + (float)$r['all']) / 2;
        $days[$d] = ['present' => $r['present'], 'all' => $r['all'], 'planned' => $r['planned']];
    }
    /* Заодно замораживаем подневное «ожидалось» — тем же снимком, что и недельный прогноз.
       Строго после upsert-ов выше: они только что положили в planned свежее расписание. */
    $exp = alfa_expect_freeze($mon, $branches);
    $store = alfa_weekplan_read();
    $store[$mon] = ['plan' => $fc['forecast'], 'lessons' => $fc['lessons'], 'groups' => ($fc['groups'] ?? 0),
                    'alreadyDone' => round($donePart, 2), 'src' => 'alfa', 'ts' => date('c'),
                    'expect' => $exp['total'], 'expectFrozen' => $exp['frozen']];
    ksort($store);
    alfa_weekplan_write($store);
    return ['week' => $mon, 'plan' => $fc['forecast'], 'lessons' => $fc['lessons'],
            'groups' => ($fc['groups'] ?? 0), 'alreadyDone' => round($donePart, 2), 'days' => $days,
            'expect' => $exp];
}

/* Форма полей периода по РЕАЛЬНОЙ записи: у части сущностей Alfa строковая дата лежит в
   b_date_v, а в b_date — внутреннее число (так у regular-lesson). Определяем по образцу. */
function alfa_date_shape(?array $item): array {
    $sh = ['field' => 'plain', 'fmt' => 'dmy', 'from' => 'docs'];
    if (!is_array($item) || !$item) return $sh;
    $sh['from'] = 'sample'; $d = '';
    if (isset($item['b_date_v']) && $item['b_date_v'] !== '') { $sh['field'] = 'v'; $d = (string)$item['b_date_v']; }
    elseif (isset($item['b_date']) && is_string($item['b_date'])) { $d = (string)$item['b_date']; }
    if ($d !== '') $sh['fmt'] = preg_match('#^\d{4}-\d{2}-\d{2}#', $d) ? 'iso' : 'dmy';
    return $sh;
}
function alfa_shape_dates(array $sh, string $bIso, string $eIso): array {
    $b = $sh['fmt'] === 'iso' ? $bIso : alfa_date($bIso);
    $e = $sh['fmt'] === 'iso' ? $eIso : alfa_date($eIso);
    return $sh['field'] === 'v' ? ['b_date_v' => $b, 'e_date_v' => $e] : ['b_date' => $b, 'e_date' => $e];
}
/* Абонементы клиента. 🔑 customer-tariff/index требует customer_id именно В АДРЕСЕ —
   в теле Alfa его игнорирует и отдаёт пусто (долго ловили это раньше). */
function alfa_customer_tariffs(int $branch, int $customerId): array {
    $url = 'https://' . alfa_host() . "/v2api/$branch/customer-tariff/index?customer_id=" . $customerId;
    $r = alfa_http('POST', $url, ['customer_id' => $customerId, 'page' => 0, 'count' => 200], alfa_token(), true, 12);
    if (isset($r['__err'])) return ['ok' => false, 'items' => []];
    $out = []; $seen = [];
    foreach (($r['items'] ?? []) as $it) {
        $id = (string)($it['id'] ?? '');
        if ($id === '' || isset($seen[$id])) continue;   // тот же абонемент приходит по разу на филиал
        $seen[$id] = 1; $out[] = $it;
    }
    return ['ok' => true, 'items' => $out];
}
/* Есть ли у ребёнка ДЕЙСТВУЮЩИЙ абонемент по этим предметам на нашу дату начала.
   ⚠️ Прошлогодние (архивные и закончившиеся до начала периода) не в счёт: у ребёнка,
   который ходит не первый год, их пачка, и раньше из-за них новый абонемент молча
   не выдавался. Одна функция на выдачу и на проверку — чтобы значок в списке и решение
   при выдаче не могли разойтись. */
function alfa_tariff_active(array $items, array $subjectIds, string $bIso): array {
    foreach ($items as $ex) {
        $exs = array_map('intval', (array)($ex['subject_ids'] ?? []));
        if ($subjectIds && !array_intersect($exs, $subjectIds)) continue;
        if (!empty($ex['is_archive']) || !empty($ex['dead'])) continue;
        $end = alfa_iso((string)($ex['e_date_v'] ?? ($ex['e_date'] ?? '')));
        if ($end !== '' && $bIso !== '' && $end < $bIso) continue;
        return ['active' => true, 'until' => $end];
    }
    return ['active' => false, 'until' => ''];
}
/* Выдать абонемент. Поля — по модели Alfa: tariff_id, subject_ids, lesson_type_ids,
   is_separate_balance, период, комментарий. customer_id дублируем в адрес. */
function alfa_tariff_give(int $branch, int $customerId, array $body): array {
    $url = 'https://' . alfa_host() . "/v2api/$branch/customer-tariff/create?customer_id=" . $customerId;
    return alfa_soft_body(alfa_http('POST', $url, $body, alfa_token(), true, 15));
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

/* --- ПЛАТЕЖИ ПО ДНЯМ (для сверки с листами админов) ---
   Снимок «что внесено в Alfa на 22:00» — чтобы вечернюю сверку можно было посмотреть и потом. */
function alfa_pay_store_path(): string {
    $salt = substr(hash('sha256', __DIR__ . '|payday'), 0, 24);
    return alfa_store_dir() . '/payday_' . $salt . '.json';
}
function alfa_pay_store_read(): array {
    $f = alfa_pay_store_path();
    if (!is_file($f)) return [];
    $j = json_decode((string)@file_get_contents($f), true);
    return is_array($j) ? $j : [];
}
function alfa_pay_store_write(array $d): void {
    $f = alfa_pay_store_path();
    $tmp = $f . '.' . getmypid() . '.tmp';
    $json = json_encode($d, JSON_UNESCAPED_UNICODE);
    if (@file_put_contents($tmp, $json, LOCK_EX) !== false) { @chmod($tmp, 0660); @rename($tmp, $f); }
    else @file_put_contents($f, $json, LOCK_EX);
}
/* Справочники касс/счетов/локаций (id => имя), кэш на сутки — чтобы снимок хранил
   человеческие названия («ЕРИП», «Наличные», «Терминал»), а не id. */
function alfa_pay_refs(): array {
    static $memo = null;
    if ($memo !== null) return $memo;
    $f = alfa_store_dir() . '/payrefs_' . substr(hash('sha256', __DIR__ . '|payrefs'), 0, 20) . '.json';
    if (is_file($f)) {
        $j = json_decode((string)@file_get_contents($f), true);
        if (is_array($j) && (int)($j['ts'] ?? 0) > time() - 86400 && is_array($j['r'] ?? null)) return $memo = $j['r'];
    }
    $br = alfa_realization_branches() ?: [alfa_branch()];
    $bid = (int)$br[0];
    $out = [];
    foreach (['pay-account' => 'payAccounts', 'pay-item' => 'payItems', 'location' => 'locations'] as $ent => $key) {
        $rr = alfa_http('POST', 'https://' . alfa_host() . "/v2api/$bid/$ent/index", ['page' => 0, 'count' => 100], alfa_token(), true, 8);
        if (isset($rr['__err'])) continue;
        $m = [];
        foreach (($rr['items'] ?? []) as $it) { if (isset($it['id'])) $m[(int)$it['id']] = (string)($it['name'] ?? ''); }
        if ($m) $out[$key] = $m;
    }
    if ($out) @file_put_contents($f, json_encode(['ts' => time(), 'r' => $out], JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $memo = $out;
}
/* Имя кассы для платежа: счёт → локация → тип оплаты. */
function alfa_pay_kassa_name(array $x, array $refs): string {
    $acc = $refs['payAccounts'][(int)($x['pay_account_id'] ?? 0)] ?? '';
    if ($acc !== '') return $acc;
    $loc = $refs['locations'][(int)($x['location_id'] ?? 0)] ?? '';
    if ($loc !== '') return $loc;
    $t = trim((string)($x['pay_type_name'] ?? ''));
    return $t !== '' ? $t : 'Без кассы';
}
/* Снимок платежей за день по кассам (для cron в 22:00) — с названиями и списком операций. */
function alfa_payments_upsert(string $date, ?array $branches = null): array {
    $r = alfa_payments_day($date, $branches);
    $refs = alfa_pay_refs();
    $inc = 0.0; $out = 0.0; $byIn = []; $byOut = []; $byItem = [];
    foreach ($r['rows'] as $x) {
        $name = alfa_pay_kassa_name($x, $refs);
        $isOut = (mb_stripos((string)$x['pay_type_name'], 'расход') !== false) || ((float)$x['income'] < 0);
        $v = abs((float)$x['income']);
        if ($isOut) {
            $byOut[$name] = round(($byOut[$name] ?? 0) + $v, 2); $out += $v;
            // статья расхода («Аренда», «Реклама», …) — для раздела «Расходы ежедневно»
            $item = $refs['payItems'][(int)($x['pay_item_id'] ?? 0)] ?? 'Без статьи';
            $byItem[$item] = round(($byItem[$item] ?? 0) + $v, 2);
        }
        else        { $byIn[$name]  = round(($byIn[$name]  ?? 0) + $v, 2); $inc += $v; }
    }
    $st = alfa_pay_store_read();
    $st[$r['date']] = ['income' => round($inc, 2), 'expense' => round($out, 2),
                       'count' => count($r['rows']), 'byIn' => $byIn, 'byOut' => $byOut,
                       'byItem' => $byItem, 'ts' => date('c')];
    ksort($st);
    if (count($st) > 400) $st = array_slice($st, -400, null, true);   // храним последние ~13 месяцев
    alfa_pay_store_write($st);
    return ['date' => $r['date'], 'income' => round($inc, 2), 'expense' => round($out, 2),
            'count' => count($r['rows']), 'byIn' => $byIn, 'byOut' => $byOut, 'byItem' => $byItem];
}

/* ===== ПЛАТЕЖИ ЗА ДЕНЬ (кассы) =====
   ⚠️ Две ловушки Alfa, обе проверены на реальных данных:
     1) document_date приходит как «27.08.2026» (дд.мм.гггг) — сравнивать надо после alfa_iso();
     2) фильтр дат в pay/index ИГНОРИРУЕТСЯ — отдаётся весь журнал (33k записей), отсортированный
        по дате по убыванию. Поэтому нужную дату ищем ДВОИЧНЫМ поиском по страницам (~10 запросов
        вместо сотен), затем листаем подряд, пока идут записи этого дня.
   В журнале лежат и приходы, и расходы (pay_type_name = «Расход») — разделяем по знаку/типу. */
function alfa_pay_page(int $branch, int $page, int $per = 50): array {
    $r = alfa_http('POST', 'https://' . alfa_host() . "/v2api/$branch/pay/index",
                   ['page' => $page, 'count' => $per], alfa_token(), true, 20);
    if (isset($r['__err'])) return ['items' => [], 'total' => 0, 'err' => $r];
    return ['items' => is_array($r['items'] ?? null) ? $r['items'] : [], 'total' => (int)($r['total'] ?? 0)];
}
function alfa_pay_row_date(array $x): string { return alfa_iso((string)($x['document_date'] ?? ($x['date'] ?? ''))); }
function alfa_payments_day(string $date, ?array $branches = null): array {
    $date = alfa_iso($date);
    $branches = $branches ?: (alfa_realization_branches() ?: [alfa_branch()]);
    $PER = 50; $rows = []; $scanned = 0; $pagesUsed = 0;
    foreach ($branches as $bid) {
        $bid = (int)$bid;
        $first = alfa_pay_page($bid, 0, $PER); $pagesUsed++;
        $total = $first['total']; $items0 = $first['items'];
        if (!$items0) continue;
        $pages = max(1, (int)ceil($total / $PER));
        // двоичный поиск: первая страница, где самая свежая запись уже НЕ новее нужного дня
        $lo = 0; $hi = $pages - 1; $start = 0;
        if (alfa_pay_row_date($items0[0]) > $date) {
            while ($lo <= $hi) {
                $mid = intdiv($lo + $hi, 2);
                $pg = alfa_pay_page($bid, $mid, $PER); $pagesUsed++;
                $it = $pg['items'];
                if (!$it) { $hi = $mid - 1; continue; }
                if (alfa_pay_row_date($it[0]) > $date) { $start = $mid + 1; $lo = $mid + 1; }
                else { $hi = $mid - 1; }
            }
            $start = max(0, $start - 1);   // шаг назад: день мог начаться на предыдущей странице
        }
        for ($p = $start; $p < $pages && $p < $start + 60; $p++) {
            $pg = alfa_pay_page($bid, $p, $PER); $pagesUsed++;
            $it = $pg['items']; if (!$it) break;
            $passed = false;
            foreach ($it as $x) {
                if (!is_array($x)) continue;
                $scanned++;
                $d = alfa_pay_row_date($x);
                if ($d === $date) {
                    $rows[] = ['id' => $x['id'] ?? null, 'branch' => $bid,
                               'customer_id' => (int)($x['customer_id'] ?? 0),
                               'income' => (float)($x['income'] ?? 0),
                               'note' => (string)($x['note'] ?? ''),
                               'payer' => (string)($x['payer_name'] ?? ''),
                               'date' => $d,
                               'pay_type_id' => $x['pay_type_id'] ?? null,
                               'pay_type_name' => (string)($x['pay_type_name'] ?? ''),
                               'pay_account_id' => $x['pay_account_id'] ?? null,
                               'pay_item_id' => $x['pay_item_id'] ?? null,
                               'location_id' => $x['location_id'] ?? null,
                               'created_at' => (string)($x['created_at'] ?? ''),
                               'is_confirmed' => $x['is_confirmed'] ?? null];
                } elseif ($d !== '' && $d < $date) { $passed = true; }
            }
            if ($passed && $rows) break;      // прошли нужный день насквозь
            if ($passed && !$rows && $p > $start + 3) break;   // день пуст — не листаем зря
        }
    }
    return ['date' => $date, 'rows' => $rows, 'scanned' => $scanned, 'pages' => $pagesUsed];
}

/* ===================== ОТЧЁТ ДЛЯ ОТДЕЛА ПРОДАЖ (вс 22:00) =====================
   Каждое воскресенье в 22:00 cron ЗАМОРАЖИВАЕТ цифры недели: оборот (факт), прогноз на
   следующую неделю и число активных клиентов. Заморозка обязательна: прогноз по мере
   проведения занятий превращается в факт (и сравнивался бы сам с собой), а активные
   клиенты меняются каждый день — «как было в воскресенье» через неделю уже не восстановить.
   Сам ТЕКСТ сообщения собирает клиент: рейтинг педагогов считается по справочнику ставок
   финмодели, которого на сервере нет. Цифры при этом берутся только из этого снимка. */

function alfa_sales_store_path(): string {
    $salt = substr(hash('sha256', __DIR__ . '|salesreport'), 0, 24);
    return alfa_store_dir() . '/salesreport_' . $salt . '.json';
}
function alfa_sales_read(): array {
    $f = alfa_sales_store_path();
    if (!is_file($f)) return ['reports' => [], 'activeLog' => [], 'settings' => []];
    $j = json_decode((string)@file_get_contents($f), true);
    if (!is_array($j)) $j = [];
    $j['reports']   = is_array($j['reports']   ?? null) ? $j['reports']   : [];
    $j['activeLog'] = is_array($j['activeLog'] ?? null) ? $j['activeLog'] : [];
    $j['settings']  = is_array($j['settings']  ?? null) ? $j['settings']  : [];
    return $j;
}
function alfa_sales_write(array $d): void {
    $f = alfa_sales_store_path();
    $tmp = $f . '.' . getmypid() . '.tmp';
    $json = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (@file_put_contents($tmp, $json, LOCK_EX) !== false) { @chmod($tmp, 0660); @rename($tmp, $f); }
    else @file_put_contents($f, $json, LOCK_EX);
}

/* --- Активные клиенты ---
   «Активный» = учащийся (is_study=1), не в архиве (removed=0), в клубных филиалах.
   Если в Alfa этот счёт считается иначе — фильтр меняется в config: active_customer_filter. */
function alfa_sales_active_filter(): array {
    $f = cfg()['active_customer_filter'] ?? null;
    return (is_array($f) && $f) ? $f : ['is_study' => 1, 'removed' => 0];
}
function alfa_active_count(?array $branches = null): array {
    $branches = $branches ?: (alfa_realization_branches() ?: [alfa_branch()]);
    $filter = alfa_sales_active_filter();
    // один филиал — хватает total с первой страницы (один запрос вместо полутора десятков)
    if (count($branches) === 1) {
        $r = alfa_call_branch((int)$branches[0], 'customer', 'index', $filter + ['page' => 0, 'count' => 1]);
        $t = (int)($r['total'] ?? 0);
        if ($t > 0) return ['count' => $t, 'src' => 'total', 'branches' => $branches, 'filter' => $filter];
    }
    // несколько филиалов — листаем и дедуплицируем по id (один ребёнок бывает в двух филиалах)
    $ids = [];
    foreach ($branches as $bid) {
        for ($p = 0; $p < 200; $p++) {
            $r = alfa_call_branch((int)$bid, 'customer', 'index', $filter + ['page' => $p, 'count' => 50]);
            $items = is_array($r['items'] ?? null) ? $r['items'] : [];
            foreach ($items as $c) { if (isset($c['id'])) $ids[(int)$c['id']] = 1; }
            if (count($items) < 50) break;
        }
    }
    return ['count' => count($ids), 'src' => 'scan', 'branches' => $branches, 'filter' => $filter];
}

/* Доход дня: прошедший — факт (среднее без/с пропусками, как во всех разделах),
   будущий — ожидаемые списания по расписанию Alfa. */
function alfa_sales_day_val(array $store, string $iso, string $today): float {
    $r = $store[$iso] ?? null;
    if (!$r) return 0.0;
    if ($iso <= $today) return ((float)($r['present'] ?? 0) + (float)($r['all'] ?? 0)) / 2;
    return (float)($r['planned'] ?? 0);
}
/* В отчёте какого воскресенья закрывается месяц: первое вс, которое не раньше последнего дня
   месяца. Правило детерминированное — не зависит от того, запускался ли cron. */
function alfa_sales_month_sunday(string $ym): string {
    $last = date('Y-m-t', strtotime($ym . '-01'));
    $wd = (int)date('N', strtotime($last));               // 1=пн … 7=вс
    return $wd === 7 ? $last : date('Y-m-d', strtotime('+' . (7 - $wd) . ' day', strtotime($last)));
}
/* Профиль дня недели: сколько ожидается в пн, вт, … Нужен для прогноза на месяц вперёд —
   Alfa создаёт занятия только на ближайшие недели, дальше данных просто нет.
   Берём ожидаемое по ближайшим двум неделям, чего не хватило — средний факт того же дня
   недели за последние 4 недели. Праздники и каникулы профиль не знает (о чём написано в UI).
   $weekTotal — недельный прогноз из «Прогноза по всем»: если он есть, профиль масштабируется
   так, чтобы сумма за неделю равнялась ему. Иначе получилось бы два источника правды: неделя
   считалась бы одним способом (снимок Alfa), а месяц — другим (дневное «ожидается»). */
function alfa_sales_weekday_profile(array $store, string $fromMon, string $today, float $weekTotal = 0.0): array {
    $prof = [];
    for ($w = 0; $w < 2; $w++) {
        for ($i = 0; $i < 7; $i++) {
            $d = date('Y-m-d', strtotime('+' . ($w * 7 + $i) . ' day', strtotime($fromMon)));
            if ($d <= $today) continue;
            $wd = (int)date('N', strtotime($d));
            $v = (float)($store[$d]['planned'] ?? 0);
            if ($v > 0 && empty($prof[$wd])) $prof[$wd] = $v;
        }
    }
    $hist = [];
    for ($i = 1; $i <= 28; $i++) {
        $d = date('Y-m-d', strtotime("-$i day", strtotime($today)));
        $r = $store[$d] ?? null;
        if (!$r || empty($r['lessons'])) continue;
        $wd = (int)date('N', strtotime($d));
        $hist[$wd][] = ((float)($r['present'] ?? 0) + (float)($r['all'] ?? 0)) / 2;
    }
    for ($wd = 1; $wd <= 7; $wd++) {
        if (!empty($prof[$wd])) continue;
        $a = $hist[$wd] ?? [];
        $prof[$wd] = $a ? round(array_sum($a) / count($a), 2) : 0.0;
    }
    // подгоняем «типовую неделю» под недельный прогноз — чтобы месяц считался от той же цифры
    $sum = array_sum($prof);
    if ($weekTotal > 0 && $sum > 0) {
        $k = $weekTotal / $sum;
        foreach ($prof as $wd => $v) $prof[$wd] = round($v * $k, 2);
    }
    ksort($prof);
    return $prof;
}
/* Прогноз на месяц: по каждому дню — факт (если день прошёл), ожидаемое из Alfa (если занятия
   уже созданы) или профиль дня недели. Так учитывается разное число учебных дней в месяцах —
   то самое «в сентябре учились 29 дней, в октябре 31». */
function alfa_sales_month_forecast(string $ym, array $store, array $prof, string $today): array {
    $days = (int)date('t', strtotime($ym . '-01'));
    $sum = 0.0; $fFact = 0; $fPlan = 0; $fProf = 0; $studyDays = 0;
    for ($d = 1; $d <= $days; $d++) {
        $iso = $ym . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT);
        $r = $store[$iso] ?? null;
        if ($iso <= $today) {
            $v = $r ? (((float)($r['present'] ?? 0) + (float)($r['all'] ?? 0)) / 2) : 0.0;
            if ($v > 0) { $sum += $v; $fFact++; $studyDays++; }
            continue;
        }
        $pl = (float)($r['planned'] ?? 0);
        if ($pl > 0) { $sum += $pl; $fPlan++; $studyDays++; continue; }
        $v = (float)($prof[(int)date('N', strtotime($iso))] ?? 0);
        if ($v > 0) { $sum += $v; $fProf++; $studyDays++; }
    }
    return ['sum' => round($sum, 2), 'days' => $days, 'studyDays' => $studyDays,
            'fromFact' => $fFact, 'fromPlanned' => $fPlan, 'fromProfile' => $fProf];
}
/* Факт месяца по дневному хранилищу. */
function alfa_sales_month_fact(string $ym, array $store, string $today): array {
    $days = (int)date('t', strtotime($ym . '-01'));
    $sum = 0.0; $have = 0;
    for ($d = 1; $d <= $days; $d++) {
        $iso = $ym . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT);
        $r = $store[$iso] ?? null;
        if (!$r) continue;
        $sum += ((float)($r['present'] ?? 0) + (float)($r['all'] ?? 0)) / 2;
        if (!empty($r['lessons'])) $have++;
    }
    return ['sum' => round($sum, 2), 'daysWithLessons' => $have, 'days' => $days];
}
/* Медиана «факт ÷ прогноз» по прошлым неделям — по ней предлагаем реалистичное «идём на»
   (прогноз всегда оптимистичнее факта: часть детей не приходит и списание не проходит). */
function alfa_sales_ratio(array $reports, int $limit = 8): float {
    $keys = array_keys($reports); sort($keys);
    $r = [];
    foreach (array_reverse($keys) as $k) {
        $rep = $reports[$k];
        $f = (float)($rep['fact'] ?? 0);
        $p = (float)($rep['prevForecast'] ?? 0);          // прогноз, который делали НА эту неделю
        if ($f > 0 && $p > 0) $r[] = $f / $p;
        if (count($r) >= $limit) break;
    }
    if (!$r) return 0.9;
    sort($r);
    $n = count($r);
    $m = ($n % 2) ? $r[(int)(($n - 1) / 2)] : (($r[$n / 2 - 1] + $r[$n / 2]) / 2);
    return max(0.5, min(1.2, $m));
}
/* Собрать (и сохранить) отчёт за неделю, которая заканчивается в $runDate (обычно — вс).
   $deep=true: пересчитать дни недели из Alfa и зафиксировать прогноз на следующую неделю. */
function alfa_sales_build(string $runDate, ?array $branches = null, bool $deep = true): array {
    $branches = $branches ?: alfa_realization_branches();
    $run   = alfa_iso($runDate);
    $mon   = alfa_monday_of($run);
    $sun   = date('Y-m-d', strtotime('+6 day', strtotime($mon)));
    $nMon  = date('Y-m-d', strtotime('+7 day', strtotime($mon)));
    $today = date('Y-m-d');

    // 1) факт недели пересчитываем из Alfa: посещаемость правят задним числом
    if ($deep) {
        for ($i = 0; $i < 7; $i++) {
            $d = date('Y-m-d', strtotime("+$i day", strtotime($mon)));
            if ($d > $today) break;
            alfa_realization_upsert($d, $branches);
        }
    }
    // 2) прогноз на следующую неделю — снимок (он же заполнит «ожидается» по её дням)
    $wp = alfa_weekplan_read();
    if ($deep && empty($wp[$nMon]['plan'])) { alfa_weekplan_snapshot($nMon, $branches); $wp = alfa_weekplan_read(); }

    $sales = alfa_sales_read();
    $reports = $sales['reports'];
    $store = alfa_realization_store_read();

    // --- неделя ---
    $fact = 0.0; $daysWith = 0;
    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime("+$i day", strtotime($mon)));
        $fact += alfa_sales_day_val($store, $d, $today);
        if (!empty($store[$d]['lessons'])) $daysWith++;
    }
    $fact = round($fact, 2);

    $nf = round((float)($wp[$nMon]['plan'] ?? 0), 2); $nfSrc = 'snapshot';
    if ($nf <= 0) {
        $nfSrc = 'planned'; $nf = 0.0;
        for ($i = 0; $i < 7; $i++) {
            $d = date('Y-m-d', strtotime("+$i day", strtotime($nMon)));
            $nf += (float)($store[$d]['planned'] ?? 0);
        }
        $nf = round($nf, 2);
    }

    // предыдущий отчёт: из него «шли на» (что обещали на эту неделю) и прошлое число клиентов
    $prevKey = ''; foreach (array_keys($reports) as $k) { if ($k < $mon && $k > $prevKey) $prevKey = (string)$k; }
    $prev = $prevKey !== '' ? $reports[$prevKey] : null;
    $goal = $prev ? (float)($prev['man']['nextGoal'] ?? 0) : 0.0;
    $goalSrc = $goal > 0 ? 'prev' : '';
    // если цель руками не вписали — берём ту, что предлагали в прошлом отчёте (она и уходила в чат)
    if ($goal <= 0 && $prev) { $goal = (float)($prev['next']['suggest'] ?? 0); $goalSrc = $goal > 0 ? 'suggest' : ''; }
    if ($goal <= 0) { $goal = round((float)($wp[$mon]['plan'] ?? 0), 2); $goalSrc = $goal > 0 ? 'snapshot' : ''; }
    // прогноз, который делали НА эту неделю — нужен для медианы «факт ÷ прогноз»
    $prevForecast = $prev ? (float)($prev['next']['forecast'] ?? 0) : round((float)($wp[$mon]['plan'] ?? 0), 2);

    // --- активные клиенты ---
    $act = ['count' => 0, 'src' => 'skip'];
    try { $act = alfa_active_count($branches); } catch (Throwable $e) { $act['err'] = $e->getMessage(); }
    $log = $sales['activeLog']; if ((int)$act['count'] > 0) $log[$run] = (int)$act['count'];
    $actPrev = $prev ? (int)($prev['active'] ?? 0) : 0;

    // --- месяц, если он закрылся этим воскресеньем ---
    $month = null;
    $cands = [date('Y-m', strtotime($run)), date('Y-m', strtotime('-1 month', strtotime(date('Y-m-15', strtotime($run)))))];
    foreach ($cands as $ym) {
        if (alfa_sales_month_sunday($ym) !== $sun) continue;
        $mf  = alfa_sales_month_fact($ym, $store, $today);
        $nYm = date('Y-m', strtotime('+1 month', strtotime($ym . '-15')));
        // профиль подогнан под недельный прогноз $nf — месяц и неделя считаются от одной цифры
        $prof = alfa_sales_weekday_profile($store, $nMon, $today, $nf);
        $nfc = alfa_sales_month_forecast($nYm, $store, $prof, $today);
        // «шли на» по месяцу и прошлое число клиентов — из последнего отчёта с месячным блоком
        $mGoal = 0.0; $mActPrev = 0;
        foreach (array_reverse(array_keys($reports)) as $k) {
            $pm = $reports[$k]['month']['ym'] ?? '';
            if ($pm === '' || $pm === $ym) continue;
            $mGoal = (float)($reports[$k]['man']['monthNextGoal'] ?? 0);
            if ($mGoal <= 0) $mGoal = (float)($reports[$k]['month']['suggest'] ?? 0);
            $mActPrev = (int)($reports[$k]['active'] ?? 0);
            break;
        }
        $month = ['ym' => $ym, 'fact' => $mf['sum'], 'daysWithLessons' => $mf['daysWithLessons'],
                  'goal' => $mGoal, 'nextYm' => $nYm, 'forecast' => $nfc['sum'],
                  'nextDays' => $nfc['days'], 'nextStudyDays' => $nfc['studyDays'],
                  'src' => ['fact' => $nfc['fromFact'], 'planned' => $nfc['fromPlanned'], 'profile' => $nfc['fromProfile']],
                  'activePrev' => $mActPrev];
        break;
    }

    $ratio = alfa_sales_ratio($reports);
    $suggest  = $nf > 0 ? floor($nf * $ratio / 100) * 100 : 0;
    $mSuggest = ($month && $month['forecast'] > 0) ? floor($month['forecast'] * $ratio / 1000) * 1000 : 0;

    $old = $reports[$mon] ?? [];
    $rep = [
        'week' => $mon, 'to' => $sun, 'runAt' => $run,
        'fact' => $fact, 'daysWithLessons' => $daysWith,
        'goal' => $goal, 'goalSrc' => $goalSrc, 'prevForecast' => round((float)$prevForecast, 2),
        'next' => ['week' => $nMon, 'forecast' => $nf, 'src' => $nfSrc, 'suggest' => $suggest],
        'active' => (int)$act['count'], 'activePrev' => $actPrev,
        'activeSrc' => (string)$act['src'] . (isset($act['err']) ? (': ' . $act['err']) : ''),
        'ratio' => round($ratio, 4),
        'month' => $month,
        // ручные поля (что вписала Жанна) переживают пересборку
        'man' => is_array($old['man'] ?? null) ? $old['man'] : [],
        'ts' => date('c'),
    ];
    if ($month && $mSuggest > 0) $rep['month']['suggest'] = $mSuggest;

    $reports[$mon] = $rep;
    ksort($reports);
    if (count($reports) > 120) $reports = array_slice($reports, -120, null, true);
    ksort($log);
    if (count($log) > 200) $log = array_slice($log, -200, null, true);
    $sales['reports'] = $reports; $sales['activeLog'] = $log;
    $sales['settings']['lastRun'] = date('c');
    alfa_sales_write($sales);
    return $rep;
}
/* Сохранить ручные поля отчёта («идём на», комментарий). Пересборка их не затирает. */
function alfa_sales_set_manual(string $week, array $man): array {
    $sales = alfa_sales_read();
    $key = alfa_monday_of($week);
    if (!isset($sales['reports'][$key])) return ['ok' => false, 'error' => 'Отчёта за эту неделю ещё нет'];
    $cur = is_array($sales['reports'][$key]['man'] ?? null) ? $sales['reports'][$key]['man'] : [];
    foreach (['nextGoal', 'monthNextGoal', 'nextForecast', 'monthForecast'] as $f) {
        if (array_key_exists($f, $man)) {
            $v = (float)$man[$f];
            if ($v > 0) $cur[$f] = round($v, 2); else unset($cur[$f]);
        }
    }
    foreach (['note', 'monthNote'] as $f) {
        if (array_key_exists($f, $man)) {
            $v = trim((string)$man[$f]);
            if ($v !== '') $cur[$f] = mb_substr($v, 0, 2000); else unset($cur[$f]);
        }
    }
    $sales['reports'][$key]['man'] = $cur;
    alfa_sales_write($sales);
    return ['ok' => true, 'week' => $key, 'man' => $cur];
}
