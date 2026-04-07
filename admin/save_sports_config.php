<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');

header('Content-Type: application/json');

$sportId = intval($_POST['sport_id'] ?? 0);
$field   = $_POST['field']   ?? '';
$value   = $_POST['value']   ?? '';

$allowed = [
    'bracket_type'        => ['single_elim_one','single_elim_two','double_elim','round_robin'],
    'format'              => ['single_stage','double_stage'],
    'second_stage_bracket'=> ['single_elim_one','single_elim_two','double_elim',''],
    'placement_type'      => ['random','manual'],
    'avg_game_time'       => null,   // numeric, validated below
    'max_teams'           => null,
    'members_per_team'    => null,
];

if (!$sportId || !array_key_exists($field, $allowed)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid field']);
    exit;
}

// Verify the sport belongs to an event owned by this admin
$stmt = $pdo->prepare("
    SELECT es.id FROM event_sports es
    JOIN events e ON es.event_id = e.id
    WHERE es.id = ? AND e.created_by = ?
");
$stmt->execute([$sportId, getCurrentUserId()]);
if (!$stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

// Validate value
if ($allowed[$field] !== null) {
    if (!in_array($value, $allowed[$field], true)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid value']);
        exit;
    }
} else {
    $value = intval($value);
    if ($value < 1) $value = ($field === 'avg_game_time' ? 30 : 1);
}

$stmt = $pdo->prepare("UPDATE event_sports SET `{$field}` = ? WHERE id = ?");
$stmt->execute([$value, $sportId]);

echo json_encode(['ok' => true]);