<?php
// Финмодель «Прознание + CODDY» — общие функции
// Подключение к БД, CORS, авторизация, конвертация «состояние ↔ таблицы».

declare(strict_types=1);

// ---------- Конфигурация ----------
function cfg(): array {
    static $c = null;
    if ($c === null) {
        $path = __DIR__ . '/config.php';
        if (!file_exists($path)) {
            json_out(['ok' => false, 'error' => 'config.php не найден'], 500);
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
        header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// ---------- Авторизация ----------
function require_auth(): void {
    $expected = cfg()['auth_token'] ?? '';
    if ($expected === '' || str_starts_with($expected, 'ЗАМЕНИТЬ')) {
        json_out(['ok' => false, 'error' => 'auth_token не настроен в config.php'], 500);
    }
    $got = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? ($_GET['token'] ?? '');
    if (!hash_equals($expected, (string)$got)) {
        json_out(['ok' => false, 'error' => 'Доступ запрещён'], 403);
    }
}

// ---------- База данных ----------
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $d = cfg()['db'];
        $dsn = "mysql:host={$d['host']};dbname={$d['name']};charset={$d['charset']}";
        try {
            $pdo = new PDO($dsn, $d['user'], $d['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            json_out(['ok' => false, 'error' => 'Не удалось подключиться к базе'], 500);
        }
    }
    return $pdo;
}

// =====================================================================
//  КОНВЕРТАЦИЯ: таблицы БД  →  объект состояния (как ждёт клиент)
// =====================================================================
function version_to_state(PDO $pdo, int $vid, string $vname): array {
    $state = [];

    // Курсы
    $rows = $pdo->prepare("SELECT * FROM courses WHERE version_id=? ORDER BY sort_order, id");
    $rows->execute([$vid]);
    $state['courses'] = array_map(function ($r) {
        return [
            'name'      => $r['name'],
            'eco'       => $r['eco'],
            'price'     => (float)$r['price'],
            'single'    => (float)$r['single_price'],
            'wage'      => (float)$r['wage'],
            'dur'       => (float)$r['duration'],
            'groupSize' => (int)$r['group_size'],
            'groups'    => (int)$r['groups_week'],
            'fill'      => (int)$r['fill_fact'],
            'material'  => (float)$r['material'],
            'visits'    => (int)$r['visits'],
            '_locked'   => (bool)$r['is_locked'],
        ];
    }, $rows->fetchAll());

    // Расписание
    $rows = $pdo->prepare("SELECT * FROM lessons WHERE version_id=? ORDER BY day_of_week, time_start, id");
    $rows->execute([$vid]);
    $state['grid'] = array_map(function ($r) {
        $l = [
            'day'      => (int)$r['day_of_week'],
            'start'    => $r['time_start'],
            'end'      => $r['time_end'],
            'room'     => $r['room'],
            'course'   => $r['course_name'],
            'teacher'  => $r['teacher_name'],
            'students' => (int)$r['students'],
            'note'     => $r['note'] ?? '',
        ];
        if ($r['new_intake']) $l['newIntake'] = true;
        if ($r['pinned'])     $l['pinned']    = true;
        return $l;
    }, $rows->fetchAll());

    // Педагоги
    $rows = $pdo->prepare("SELECT * FROM teachers WHERE version_id=? ORDER BY sort_order, id");
    $rows->execute([$vid]);
    $state['staff'] = array_map(function ($r) {
        $s = [
            'name'      => $r['name'],
            'wageMode'  => $r['wage_mode'],
            'fixedRate' => (float)$r['fixed_rate'],
        ];
        $ct = json_decode($r['can_teach'] ?? '[]', true);
        if (is_array($ct) && $ct) $s['canTeach'] = $ct;
        return $s;
    }, $rows->fetchAll());

    // Фикс-ставки: {педагог: {длительность: ставка}}
    $rows = $pdo->prepare("SELECT * FROM fix_rates WHERE version_id=?");
    $rows->execute([$vid]);
    $fix = [];
    foreach ($rows->fetchAll() as $r) {
        $fix[$r['teacher_name']][(string)(int)$r['duration_min']] = (float)$r['rate'];
    }
    $state['_fixRates'] = $fix;

    // Справочник ставок ЗП по курсам
    $rows = $pdo->prepare("SELECT * FROM wage_tiers WHERE version_id=?");
    $rows->execute([$vid]);
    $wt = [];
    foreach ($rows->fetchAll() as $r) {
        $entry = [];
        if ($r['base_rate'] !== null) $entry['base'] = (float)$r['base_rate'];
        $tiers = json_decode($r['tiers_json'] ?? '{}', true);
        if (is_array($tiers)) $entry['tiers'] = $tiers;
        $wt[$r['course_name']] = $entry;
    }
    $state['wageTable'] = $wt;

    // Постоянные расходы
    $rows = $pdo->prepare("SELECT * FROM expenses WHERE version_id=? ORDER BY sort_order, id");
    $rows->execute([$vid]);
    $fixed = [];
    $fgrp  = [];
    foreach ($rows->fetchAll() as $r) {
        $fixed[$r['name']] = (float)$r['amount'];
        $fgrp[$r['name']]  = (int)$r['group_type'];
    }
    $state['fixed']      = $fixed;
    $state['fixedGroup'] = $fgrp;

    // Расходы в % от оборота
    $rows = $pdo->prepare("SELECT * FROM expenses_pct WHERE version_id=?");
    $rows->execute([$vid]);
    $state['fixedPct'] = array_map(fn($r) => ['name' => $r['name'], 'pct' => (float)$r['pct']], $rows->fetchAll());

    // План набора
    $rows = $pdo->prepare("SELECT * FROM plan_items WHERE version_id=? ORDER BY sort_order, id");
    $rows->execute([$vid]);
    $state['plan'] = array_map(function ($r) {
        $p = [
            'name'     => $r['course_name'],
            'perGroup' => (float)$r['per_group'],
            'price'    => (float)$r['price'],
            'groups'   => (int)$r['groups_week'],
            'visits'   => (int)$r['visits'],
        ];
        if ($r['is_locked']) $p['_locked'] = true;
        return $p;
    }, $rows->fetchAll());

    // Настройки
    $st = $pdo->prepare("SELECT * FROM settings WHERE version_id=?");
    $st->execute([$vid]);
    $s = $st->fetch();
    if ($s) {
        $state['tax'] = [
            'usn'         => (float)$s['tax_usn'],
            'fszn'        => (float)$s['tax_fszn'],
            'belgosstrah' => (float)$s['tax_belgos'],
            'acquiring'   => (float)$s['tax_acquiring'],
        ];
        $state['assume'] = [
            'weeksPerMonth'     => (float)$s['weeks_per_month'],
            'targetProfitShare' => (float)$s['target_profit'],
            'ownerSalary'       => (float)$s['owner_salary'],
        ];
        $state['funnel'] = [
            'coef'     => (float)$s['funnel_coef'],
            'now'      => (int)$s['funnel_now'],
            'showRate' => (float)$s['funnel_showrate'],
        ];
        $state['_npdMonth'] = (float)$s['npd_month'];
        $state['_wageMode'] = $s['wage_mode'];

        // прочие поля состояния, которые не легли в колонки
        $extra = json_decode($s['extra_json'] ?? '{}', true);
        if (is_array($extra)) {
            foreach ($extra as $k => $v) $state[$k] = $v;
        }
    }

    // Метаданные версии
    $state['meta'] = ['name' => $vname];
    $v = $pdo->prepare("SELECT saved_at FROM versions WHERE id=?");
    $v->execute([$vid]);
    $row = $v->fetch();
    if ($row && $row['saved_at']) {
        $state['_savedAt'] = gmdate('c', strtotime($row['saved_at']));
    }

    return $state;
}

// =====================================================================
//  КОНВЕРТАЦИЯ: объект состояния  →  таблицы БД
// =====================================================================
function state_to_version(PDO $pdo, int $vid, array $state): void {
    // Полная перезапись данных версии
    foreach (['courses','lessons','teachers','fix_rates','wage_tiers','expenses','expenses_pct','plan_items','settings'] as $t) {
        $pdo->prepare("DELETE FROM $t WHERE version_id=?")->execute([$vid]);
    }

    // Курсы
    $ins = $pdo->prepare("INSERT INTO courses
        (version_id,name,eco,price,single_price,wage,duration,group_size,groups_week,fill_fact,material,visits,is_locked,sort_order)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach (($state['courses'] ?? []) as $i => $c) {
        $ins->execute([
            $vid, $c['name'] ?? '', $c['eco'] ?? '',
            $c['price'] ?? 0, $c['single'] ?? 0, $c['wage'] ?? 0, $c['dur'] ?? 1,
            $c['groupSize'] ?? 8, $c['groups'] ?? 0, $c['fill'] ?? 0,
            $c['material'] ?? 0, $c['visits'] ?? 1,
            !empty($c['_locked']) ? 1 : 0, $i,
        ]);
    }

    // Расписание
    $ins = $pdo->prepare("INSERT INTO lessons
        (version_id,day_of_week,time_start,time_end,room,course_name,teacher_name,students,note,new_intake,pinned,sort_order)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach (($state['grid'] ?? []) as $i => $l) {
        $ins->execute([
            $vid, $l['day'] ?? 1, $l['start'] ?? '', $l['end'] ?? '',
            $l['room'] ?? '', $l['course'] ?? '', $l['teacher'] ?? '',
            $l['students'] ?? 0, $l['note'] ?? '',
            !empty($l['newIntake']) ? 1 : 0, !empty($l['pinned']) ? 1 : 0, $i,
        ]);
    }

    // Педагоги
    $ins = $pdo->prepare("INSERT INTO teachers (version_id,name,can_teach,wage_mode,fixed_rate,sort_order) VALUES (?,?,?,?,?,?)");
    foreach (($state['staff'] ?? []) as $i => $s) {
        $ins->execute([
            $vid, $s['name'] ?? '',
            json_encode($s['canTeach'] ?? [], JSON_UNESCAPED_UNICODE),
            $s['wageMode'] ?? 'tier', $s['fixedRate'] ?? 0, $i,
        ]);
    }

    // Фикс-ставки
    $ins = $pdo->prepare("INSERT INTO fix_rates (version_id,teacher_name,duration_min,rate) VALUES (?,?,?,?)");
    foreach (($state['_fixRates'] ?? []) as $teacher => $byDur) {
        if (!is_array($byDur)) continue;
        foreach ($byDur as $dur => $rate) {
            if ($rate === '' || $rate === null) continue;
            $ins->execute([$vid, $teacher, (int)$dur, (float)$rate]);
        }
    }

    // Справочник ставок
    $ins = $pdo->prepare("INSERT INTO wage_tiers (version_id,course_name,base_rate,tiers_json) VALUES (?,?,?,?)");
    foreach (($state['wageTable'] ?? []) as $course => $w) {
        $ins->execute([
            $vid, $course,
            $w['base'] ?? 0,
            json_encode($w['tiers'] ?? new stdClass(), JSON_UNESCAPED_UNICODE),
        ]);
    }

    // Расходы
    $ins = $pdo->prepare("INSERT INTO expenses (version_id,name,amount,group_type,sort_order) VALUES (?,?,?,?,?)");
    $i = 0;
    foreach (($state['fixed'] ?? []) as $name => $amount) {
        $grp = $state['fixedGroup'][$name] ?? 1;
        $ins->execute([$vid, $name, (float)$amount, (int)$grp, $i++]);
    }

    // Расходы в %
    $ins = $pdo->prepare("INSERT INTO expenses_pct (version_id,name,pct) VALUES (?,?,?)");
    foreach (($state['fixedPct'] ?? []) as $p) {
        $ins->execute([$vid, $p['name'] ?? '', (float)($p['pct'] ?? 0)]);
    }

    // План набора
    $ins = $pdo->prepare("INSERT INTO plan_items (version_id,course_name,per_group,price,groups_week,visits,is_locked,sort_order) VALUES (?,?,?,?,?,?,?,?)");
    foreach (($state['plan'] ?? []) as $i => $p) {
        $ins->execute([
            $vid, $p['name'] ?? '', $p['perGroup'] ?? 0, $p['price'] ?? 0,
            $p['groups'] ?? 0, $p['visits'] ?? 1,
            !empty($p['_locked']) ? 1 : 0, $i,
        ]);
    }

    // Настройки + всё, что не легло в колонки → extra_json
    $known = ['courses','grid','staff','wageTable','fixed','fixedGroup','fixedPct','plan',
              'tax','assume','funnel','_npdMonth','_wageMode','_fixRates','meta','_savedAt'];
    $extra = [];
    foreach ($state as $k => $v) {
        if (!in_array($k, $known, true)) $extra[$k] = $v;
    }

    $tax    = $state['tax']    ?? [];
    $assume = $state['assume'] ?? [];
    $funnel = $state['funnel'] ?? [];

    $pdo->prepare("INSERT INTO settings
        (version_id,tax_usn,tax_fszn,tax_belgos,tax_acquiring,npd_month,wage_mode,
         weeks_per_month,target_profit,owner_salary,funnel_coef,funnel_now,funnel_showrate,extra_json)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([
            $vid,
            $tax['usn'] ?? 6, $tax['fszn'] ?? 34, $tax['belgosstrah'] ?? 0.6, $tax['acquiring'] ?? 1.0,
            $state['_npdMonth'] ?? 0, $state['_wageMode'] ?? 'kpi',
            $assume['weeksPerMonth'] ?? 4, $assume['targetProfitShare'] ?? 18, $assume['ownerSalary'] ?? 0,
            $funnel['coef'] ?? 1.32, $funnel['now'] ?? 0, $funnel['showRate'] ?? 0.8,
            json_encode($extra, JSON_UNESCAPED_UNICODE),
        ]);
}
