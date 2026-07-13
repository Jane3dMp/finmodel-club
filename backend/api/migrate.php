<?php
// ОДНОРАЗОВЫЙ СКРИПТ МИГРАЦИИ
// Переносит данные из Google Apps Script в MySQL.
//
// Как запустить:
//   1. Убедиться, что schema.sql выполнен (таблицы созданы).
//   2. Положить этот файл в /api/ на хостинге.
//   3. Открыть в браузере: https://ваш-домен/api/migrate.php?token=ВАШ_ТОКЕН
//   4. Проверить отчёт (сколько версий, курсов, занятий перенесено).
//   5. ⚠️ УДАЛИТЬ ЭТОТ ФАЙЛ С ХОСТИНГА после успешной миграции.

declare(strict_types=1);
require __DIR__ . '/db.php';

require_auth();
header('Content-Type: text/plain; charset=utf-8');

// URL старого Apps Script
$GAS_URL = 'https://script.google.com/macros/s/AKfycbxJajbntVctGjXImc1hVhWimvOkXHcNCkuVFpszEbA8ad-B40Lj2Q8gdMMTBar4YLnsew/exec';

echo "=== МИГРАЦИЯ ИЗ GOOGLE APPS SCRIPT ===\n\n";

// 1. Забираем данные
echo "1) Запрашиваю данные из Google...\n";
$ctx = stream_context_create(['http' => ['timeout' => 120, 'follow_location' => 1]]);
$raw = @file_get_contents($GAS_URL . '?action=load', false, $ctx);

if ($raw === false || $raw === '') {
    exit("ОШИБКА: не удалось получить данные из Google Apps Script.\n");
}

// Apps Script иногда отвечает text/html с JSON внутри — вытаскиваем JSON
$json = json_decode($raw, true);
if (!is_array($json)) {
    if (preg_match('/\{.*\}/s', $raw, $m)) {
        $json = json_decode($m[0], true);
    }
}
if (!is_array($json) || empty($json['data']['scenarios'])) {
    exit("ОШИБКА: ответ Google не содержит версий модели.\n");
}

$scenarios = $json['data']['scenarios'];
$current   = (string)($json['data']['current'] ?? '');
echo "   Получено версий: " . count($scenarios) . "\n\n";

// 2. Проверяем целостность каждой версии
echo "2) Проверяю целостность версий...\n";
$good = [];
$bad  = [];

foreach ($scenarios as $name => $state) {
    // Признаки целой версии: есть массив courses с полем name у первого элемента
    $ok = is_array($state)
       && isset($state['courses']) && is_array($state['courses'])
       && (count($state['courses']) === 0 || isset($state['courses'][0]['name']));

    if ($ok) {
        $good[$name] = $state;
        printf("   ✓ %-40s курсов: %3d, занятий: %3d\n",
            $name,
            count($state['courses'] ?? []),
            count($state['grid'] ?? [])
        );
    } else {
        $bad[] = $name;
        printf("   ✗ %-40s ПОВРЕЖДЕНА — пропускаю\n", $name);
    }
}
echo "\n   Целых версий: " . count($good) . ", повреждённых: " . count($bad) . "\n\n";

if (!$good) {
    exit("ОШИБКА: нет ни одной целой версии для переноса.\n");
}

// 3. Переносим в MySQL
echo "3) Переношу в базу данных...\n";
$pdo = db();
$pdo->beginTransaction();

try {
    foreach ($good as $name => $state) {
        $vSavedAt = $state['_savedAt'] ?? gmdate('c');
        $dt = date('Y-m-d H:i:s', strtotime($vSavedAt) ?: time());

        $q = $pdo->prepare("SELECT id FROM versions WHERE name=?");
        $q->execute([$name]);
        $vid = $q->fetchColumn();

        if ($vid) {
            $pdo->prepare("UPDATE versions SET saved_at=?, is_current=? WHERE id=?")
                ->execute([$dt, $name === $current ? 1 : 0, $vid]);
            $vid = (int)$vid;
        } else {
            $pdo->prepare("INSERT INTO versions (name,is_current,saved_at) VALUES (?,?,?)")
                ->execute([$name, $name === $current ? 1 : 0, $dt]);
            $vid = (int)$pdo->lastInsertId();
        }

        state_to_version($pdo, $vid, $state);
        echo "   → перенесена: $name\n";
    }

    $pdo->commit();

} catch (Throwable $e) {
    $pdo->rollBack();
    exit("\nОШИБКА при записи в базу: " . $e->getMessage() . "\n");
}

// 4. Сверка
echo "\n4) Сверка (должно совпадать с пунктом 2):\n";
$rows = $pdo->query("
    SELECT v.name,
           (SELECT COUNT(*) FROM courses  c WHERE c.version_id = v.id) AS courses,
           (SELECT COUNT(*) FROM lessons  l WHERE l.version_id = v.id) AS lessons,
           (SELECT COUNT(*) FROM teachers t WHERE t.version_id = v.id) AS teachers
    FROM versions v ORDER BY v.id
")->fetchAll();

foreach ($rows as $r) {
    printf("   %-40s курсов: %3d, занятий: %3d, педагогов: %2d\n",
        $r['name'], $r['courses'], $r['lessons'], $r['teachers']);
}

echo "\n=== ГОТОВО ===\n";
if ($bad) {
    echo "\nПропущены повреждённые версии:\n";
    foreach ($bad as $b) echo "   - $b\n";
    echo "\nЭто ожидаемо: они были испорчены старым Apps Script.\n";
}
echo "\n⚠️ УДАЛИТЕ ЭТОТ ФАЙЛ (migrate.php) С ХОСТИНГА.\n";
