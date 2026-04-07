<?php
$pageTitle = 'Generate Brackets';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/bracket_generator.php';
requireRole('admin');

$eventId = intval($_GET['event_id'] ?? 0);
$event = $pdo->prepare("SELECT * FROM events WHERE id = ? AND created_by = ?");
$event->execute([$eventId, getCurrentUserId()]);
$event = $event->fetch();
if (!$event) { header('Location: dashboard.php'); exit(); }

$stmt = $pdo->prepare("SELECT * FROM event_sports WHERE event_id = ?");
$stmt->execute([$eventId]);
$eventSports = $stmt->fetchAll();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        // Delete existing brackets (keep teams as configured by admin)
        foreach ($eventSports as $es) {
            $pdo->prepare("DELETE FROM matches WHERE event_sport_id = ?")->execute([$es['id']]);
        }

        $startTime        = $event['event_start_date'] . ' ' . $event['event_start_time'];
        $eventEndDateTime = $event['event_end_date']   . ' ' . $event['event_end_time'];

        // Shared busy-map: uid -> [['start'=>DateTime,'end'=>DateTime], ...]
        // Passed by reference so conflict checks span across all sports.
        $busyMap = [];

        foreach ($eventSports as $es) {
            if ($es['is_team_sport']) {
                generateTeamBracket(
                    $pdo, $es['id'], $startTime,
                    (int)$es['avg_game_time'], $es['placement_type'],
                    $eventEndDateTime, $busyMap
                );
            } else {
                foreach (['male', 'female', 'other'] as $gender) {
                    $players = getPlayersForSport($pdo, $es['id'], $gender);
                    if (count($players) < 2) continue;

                    generateBracket(
                        $pdo, $es['id'], $startTime,
                        (int)$es['avg_game_time'], $es['placement_type'],
                        $eventEndDateTime, $gender, $busyMap
                    );
                }
            }
        }

        $pdo->prepare("UPDATE events SET status='ongoing' WHERE id=?")->execute([$eventId]);
        $pdo->commit();
        $message = 'Brackets generated successfully! Event is now ongoing.';
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Error generating brackets: ' . $e->getMessage();
    }
}
?>

<div class="dashboard">
    <div class="page-header">
        <h1>Generate Brackets - <?= htmlspecialchars($event['name']) ?></h1>
        <a href="dashboard.php" class="btn btn-secondary">← Back</a>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-success"><?= $message ?>
            <br><a href="manage_events.php?event_id=<?= $eventId ?>" class="btn btn-primary" style="margin-top:10px">Manage Event →</a>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= $error ?></div>
    <?php endif; ?>
    
    <div class="form-card">
        <h2>Sports Summary</h2>
        <?php foreach ($eventSports as $es): 
            $players = getPlayersForSport($pdo, $es['id']);
        ?>
            <div class="sport-summary">
                <h3><?= htmlspecialchars($es['sport_name']) ?></h3>
                <p>Players: <?= count($players) ?> | Bracket: <?= str_replace('_', ' ', ucfirst($es['bracket_type'])) ?> | Placement: <?= ucfirst($es['placement_type']) ?></p>
                <?php if (count($players) < 2): ?>
                    <p class="text-warning">⚠️ Not enough players (need at least 2)</p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        
        <form method="POST" class="form-actions">
            <p class="form-help">⚠️ This will generate brackets and start the event. Make sure registration is complete.</p>
            <button type="submit" class="btn btn-primary" onclick="return confirm('Generate brackets and start the event?')">
                🏁 Generate Brackets & Start Event
            </button>
        </form>
    </div>
</div>