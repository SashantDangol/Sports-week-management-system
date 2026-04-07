<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');

$eventId = intval($_GET['event_id'] ?? 0);
if (!$eventId) { header('Location: dashboard.php'); exit(); }

// Only update the status — do NOT change reg_end_date.
// Changing reg_end_date to today caused the event to disappear from the
// dashboard because the "Registration Ended" section checks reg_end_date < today,
// and today == today is not strictly less than today.
$stmt = $pdo->prepare("UPDATE events 
    SET status = 'registration_ended' 
    WHERE id = ? AND created_by = ? AND status = 'registration'");
$stmt->execute([$eventId, getCurrentUserId()]);

header("Location: dashboard.php");
exit();
?>