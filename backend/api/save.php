<?php
// POST /api/save.php
// Принимает тот же формат, что раньше уходил в Google Apps Script:
// {"action":"save","data":{"scenarios":{...},"current":"...","savedAt":"..."}}
//
// Сохраняет ВСЕ версии (полная перезапись). Версии, которых нет в запросе, удаляются.

declare(strict_types=1);
require __DIR__ . '/db.php';

cors();
require_auth();

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!is_array($body) || ($body['action'] ?? '') !== 'save' || !isset($body['data']['scenarios'])) {
    json_out(['ok' => false, 'error' => 'Неверный формат запроса'], 400);
}

$scenarios = $body['data']['scenarios'];
$current   = (string)($body['data']['current'] ?? '');
$savedAt   = $body['data']['savedAt'] ?? gmdate('c');

if (!is_array($scenarios) || !$scenarios) {
    json_out(['ok' => false, 'error' => 'Нет версий для сохранения'], 400);
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $incoming = array_keys($scenarios);

    // Удаляем версии, которых больше нет в модели
    $existing = $pdo->query("SELECT id, name FROM versions")->fetchAll();
    foreach ($existing as $row) {
        if (!in_array($row['name'], $incoming, true)) {
            $pdo->prepare("DELETE FROM versions WHERE id=?")->execute([$row['id']]);
        }
    }

    foreach ($scenarios as $name => $state) {
        if (!is_array($state)) continue;

        // Метка времени: берём из состояния, иначе общую
        $vSavedAt = $state['_savedAt'] ?? $savedAt;
        $dt = date('Y-m-d H:i:s', strtotime($vSavedAt) ?: time());

        // Версия существует?
        $q = $pdo->prepare("SELECT id FROM versions WHERE name=?");
        $q->execute([$name]);
        $vid = $q->fetchColumn();

        if ($vid) {
            $pdo->prepare("UPDATE versions SET saved_at=?, is_current=? WHERE id=?")
                ->execute([$dt, $name === $current ? 1 : 0, $vid]);
            $vid = (int)$vid;
        } else {
            $pdo->prepare("INSERT INTO versions (name, is_current, saved_at) VALUES (?,?,?)")
                ->execute([$name, $name === $current ? 1 : 0, $dt]);
            $vid = (int)$pdo->lastInsertId();
        }

        state_to_version($pdo, $vid, $state);
    }

    // Гарантируем ровно одну текущую версию
    if ($current !== '') {
        $pdo->prepare("UPDATE versions SET is_current=0")->execute();
        $pdo->prepare("UPDATE versions SET is_current=1 WHERE name=?")->execute([$current]);
    }

    $pdo->commit();
    json_out(['ok' => true, 'saved' => count($scenarios)]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    json_out(['ok' => false, 'error' => 'Ошибка сохранения'], 500);
}
