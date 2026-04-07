<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/bracket_generator.php';
requireRole('admin');

$eventId = intval($_GET['event_id'] ?? 0);
if (!$eventId) { header('Location: dashboard.php'); exit(); }

$event = $pdo->prepare("SELECT * FROM events WHERE id = ? AND created_by = ? AND status = 'registration_ended'");
$event->execute([$eventId, getCurrentUserId()]);
$event = $event->fetch();
if (!$event) { header('Location: dashboard.php'); exit(); }

$stmt = $pdo->prepare("SELECT * FROM event_sports WHERE event_id = ?");
$stmt->execute([$eventId]);
$eventSports = $stmt->fetchAll();

if (empty($eventSports)) {
    header('Location: configure_sports.php?event_id=' . $eventId . '&error=Please configure sports first');
    exit();
}

try {
    $pdo->beginTransaction();

    // Clear any previously attempted brackets
    foreach ($eventSports as $es) {
        $pdo->prepare("DELETE FROM matches WHERE event_sport_id = ?")->execute([$es['id']]);
    }

    $startTime        = $event['event_start_date'] . ' ' . $event['event_start_time'];
    $eventEndDateTime = $event['event_end_date']   . ' ' . $event['event_end_time'];

    foreach ($eventSports as $es) {
        if ($es['is_team_sport']) {
            generateTeamBracket($pdo, $es['id'], $startTime, $es['avg_game_time'], $es['placement_type'], $eventEndDateTime);
        } else {
            foreach (['male', 'female', 'other'] as $gender) {
                $players = getPlayersForSport($pdo, $es['id'], $gender);
                if (count($players) < 2) continue;
                generateBracket($pdo, $es['id'], $startTime, $es['avg_game_time'], $es['placement_type'], $eventEndDateTime, $gender);
            }
        }
    }

    $pdo->prepare("UPDATE events SET status = 'ongoing' WHERE id = ?")->execute([$eventId]);

    $pdo->commit();
    header("Location: dashboard.php");
    exit();

} catch (Exception $e) {
    $pdo->rollBack();
    $msg = urlencode('Failed to generate brackets: ' . $e->getMessage());
    header("Location: configure_sports.php?event_id={$eventId}&error={$msg}");
    exit();
}
?>