<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');

$eventId = intval($_GET['event_id'] ?? 0);
if (!$eventId) { header('Location: dashboard.php'); exit(); }

// Close registration early by moving reg_end_date to today (or earlier) and changing status
$today = date('Y-m-d');
$stmt = $pdo->prepare("UPDATE events 
    SET reg_end_date = ?, status = 'registration_ended' 
    WHERE id = ? AND created_by = ? AND status = 'registration' AND reg_end_date > ?");
$stmt->execute([$today, $eventId, getCurrentUserId(), $today]);

header("Location: dashboard.php");
exit();
?>
