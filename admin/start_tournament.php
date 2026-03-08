<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');

$eventId = intval($_GET['event_id'] ?? 0);
if (!$eventId) { header('Location: dashboard.php'); exit(); }

// Check if sports are configured before allowing to start event
$stmt = $pdo->prepare("SELECT COUNT(*) as configured_count 
                      FROM event_sports 
                      WHERE event_id = ? AND 
                            (bracket_type IS NOT NULL AND 
                             avg_game_time IS NOT NULL AND 
                             placement_type IS NOT NULL)");
$stmt->execute([$eventId]);
$result = $stmt->fetch();

if ($result['configured_count'] == 0) {
    header('Location: configure_sports.php?event_id=' . $eventId . '&error=Please configure sports first');
    exit();
}

// Start the event by changing status to 'ongoing'
$stmt = $pdo->prepare("UPDATE events 
    SET status = 'ongoing' 
    WHERE id = ? AND created_by = ? AND status = 'registration_ended'");
$stmt->execute([$eventId, getCurrentUserId()]);

header("Location: dashboard.php");
exit();
?>
