<?php
$pageTitle = 'View Registrations';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../config/db.php';
requireRole('admin');

$eventId = intval($_GET['event_id'] ?? 0);
$event = $pdo->prepare("SELECT * FROM events WHERE id = ?");
$event->execute([$eventId]);
$event = $event->fetch();
if (!$event) { header('Location: dashboard.php'); exit(); }

$stmt = $pdo->prepare("
    SELECT r.*, u.full_name, u.username, 
           es1.sport_name as sport1_name, es2.sport_name as sport2_name
    FROM registrations r
    JOIN users u ON r.user_id = u.id
    JOIN event_sports es1 ON r.sport1_id = es1.id
    LEFT JOIN event_sports es2 ON r.sport2_id = es2.id
    WHERE r.event_id = ?
    ORDER BY r.registered_at DESC
");
$stmt->execute([$eventId]);
$registrations = $stmt->fetchAll();

// Get sport counts
$stmt = $pdo->prepare("
    SELECT es.sport_name, 
           COUNT(DISTINCT CASE WHEN r.sport1_id = es.id OR r.sport2_id = es.id THEN r.user_id END) as player_count
    FROM event_sports es
    LEFT JOIN registrations r ON (r.sport1_id = es.id OR r.sport2_id = es.id) AND r.event_id = es.event_id
    WHERE es.event_id = ?
    GROUP BY es.id, es.sport_name
");
$stmt->execute([$eventId]);
$sportCounts = $stmt->fetchAll();
?>

<div class="dashboard">
    <div class="page-header">
        <h1>Registrations - <?= htmlspecialchars($event['name']) ?></h1>
        <a href="dashboard.php" class="btn btn-secondary">← Back</a>
    </div>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?= count($registrations) ?></div>
            <div class="stat-label">Total Registrations</div>
        </div>
        <?php foreach ($sportCounts as $sc): ?>
            <div class="stat-card">
                <div class="stat-number"><?= $sc['player_count'] ?></div>
                <div class="stat-label"><?= htmlspecialchars($sc['sport_name']) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>S.N</th>
                    <th>Name</th>
                    <th>Reg ID</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Gender</th>
                    <th>Sport 1</th>
                    <th>Sport 2</th>
                    <th>Registered</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($registrations as $i => $r): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= htmlspecialchars($r['player_name']) ?></td>
                        <td><?= htmlspecialchars($r['reg_id']) ?></td>
                        <td><?= htmlspecialchars($r['phone']) ?></td>
                        <td><?= htmlspecialchars($r['email']) ?></td>
                        <td><?= htmlspecialchars(ucfirst($r['gender'])) ?></td>
                        <td><?= htmlspecialchars($r['sport1_name']) ?></td>
                        <td><?= htmlspecialchars($r['sport2_name']) ?></td>
                        <td><?= date('M d, H:i', strtotime($r['registered_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>


