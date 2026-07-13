<?php
// GET /api/load.php
// Отдаёт все версии модели в том же формате, что раньше отдавал Google Apps Script:
// {"ok":true,"data":{"scenarios":{...},"current":"...","savedAt":"..."}}

declare(strict_types=1);
require __DIR__ . '/db.php';

cors();
require_auth();

try {
    $pdo = db();

    $versions = $pdo->query("SELECT id, name, is_current, saved_at FROM versions ORDER BY id")->fetchAll();

    $scenarios = [];
    $current   = '';
    $savedAt   = '';

    foreach ($versions as $v) {
        $scenarios[$v['name']] = version_to_state($pdo, (int)$v['id'], $v['name']);
        if ((int)$v['is_current'] === 1) {
            $current = $v['name'];
            $savedAt = $v['saved_at'] ? gmdate('c', strtotime($v['saved_at'])) : '';
        }
    }

    // если текущая не отмечена — берём первую
    if ($current === '' && $versions) {
        $current = $versions[0]['name'];
        $savedAt = $versions[0]['saved_at'] ? gmdate('c', strtotime($versions[0]['saved_at'])) : '';
    }

    json_out([
        'ok'   => true,
        'data' => [
            'scenarios' => (object)$scenarios,
            'current'   => $current,
            'savedAt'   => $savedAt,
        ],
    ]);

} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'Ошибка загрузки'], 500);
}
