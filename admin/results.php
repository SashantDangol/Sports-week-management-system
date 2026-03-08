<?php
$pageTitle = 'Event Results';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../config/db.php';
requireRole('admin');

$eventId = intval($_GET['event_id'] ?? 0);
$event = $pdo->prepare("SELECT * FROM events WHERE id = ?");
$event->execute([$eventId]);
$event = $event->fetch();
if (!$event) { header('Location: dashboard.php'); exit(); }

$stmt = $pdo->prepare("SELECT * FROM event_sports WHERE event_id = ?");
$stmt->execute([$eventId]);
$eventSports = $stmt->fetchAll();
?>

<div class="dashboard">
    <div class="page-header">
        <h1>🏆 Results - <?= htmlspecialchars($event['name']) ?></h1>
        <div>
            <a href="view_brackets.php?event_id=<?= $eventId ?>" class="btn btn-secondary">View Brackets</a>
            <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
        </div>
    </div>
    
    <?php foreach ($eventSports as $es): 
        $stmt = $pdo->prepare("SELECT * FROM results WHERE event_sport_id = ? ORDER BY position");
        $stmt->execute([$es['id']]);
        $results = $stmt->fetchAll();
    ?>
        <div class="result-card">
            <h2><?= htmlspecialchars($es['sport_name']) ?></h2>
            <?php if (empty($results)): ?>
                <p>No results available yet.</p>
            <?php else: ?>
                <div class="podium">
                    <?php foreach ($results as $r): ?>
                        <div class="podium-item position-<?= $r['position'] ?>">
                            <div class="podium-medal">
                                <?php if ($r['position'] == 1): ?>🥇
                                <?php elseif ($r['position'] == 2): ?>🥈
                                <?php else: ?>🥉
                                <?php endif; ?>
                            </div>
                            <div class="podium-name"><?= htmlspecialchars($r['player_name']) ?></div>
                            <div class="podium-position">
                                <?php if ($r['position'] == 1): ?>Champion
                                <?php elseif ($r['position'] == 2): ?>Runner-up
                                <?php else: ?>3rd Place
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>


