<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');

$eventId = intval($_GET['event_id'] ?? 0);
if (!$eventId) { header('Location: dashboard.php'); exit(); }

$stmt = $pdo->prepare("UPDATE events SET status = 'registration' WHERE id = ? AND created_by = ? AND status = 'draft'");
$stmt->execute([$eventId, getCurrentUserId()]);

header("Location: dashboard.php");
exit();
?>
