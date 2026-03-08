<?php
$pageTitle = 'View Event';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../config/db.php';
requireRole('student');

$eventId = intval($_GET['event_id'] ?? 0);
$event = $pdo->prepare("SELECT * FROM events WHERE id = ?");
$event->execute([$eventId]);
$event = $event->fetch();
if (!$event) { header('Location: dashboard.php'); exit(); }

$sportId = intval($_GET['sport_id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM event_sports WHERE event_id = ?");
$stmt->execute([$eventId]);
$eventSports = $stmt->fetchAll();
if (!$sportId && !empty($eventSports)) $sportId = $eventSports[0]['id'];

$matches = [];
$rounds = [];
$genders = [];
$currentGender = $_GET['gender'] ?? null;
if ($sportId) {
    // Find available genders for this sport's brackets
    $stmt = $pdo->prepare("SELECT DISTINCT gender FROM matches WHERE event_sport_id = ? ORDER BY gender");
    $stmt->execute([$sportId]);
    $genders = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($genders) {
        if (!$currentGender || !in_array($currentGender, $genders, true)) {
            $currentGender = $genders[0];
        }
        $stmt = $pdo->prepare("SELECT * FROM matches WHERE event_sport_id = ? AND gender = ? ORDER BY round, match_number");
        $stmt->execute([$sportId, $currentGender]);
        $matches = $stmt->fetchAll();
        foreach ($matches as $m) $rounds[$m['round']][] = $m;
    }
}
?>

<div class="dashboard">
    <div class="page-header">
        <h1><?= htmlspecialchars($event['name']) ?></h1>
        <a href="dashboard.php" class="btn btn-secondary">← Back</a>
    </div>
    
    <div class="event-info-box">
        <p><?= htmlspecialchars($event['description'] ?? '') ?></p>
        <p><?= date('M d', strtotime($event['event_start_date'])) ?> - <?= date('M d, Y', strtotime($event['event_end_date'])) ?></p>
        <span class="badge badge-<?= $event['status'] ?>"><?= ucfirst($event['status']) ?></span>
    </div>
    
    <div class="sport-tabs">
        <?php foreach ($eventSports as $es): ?>
            <a href="?event_id=<?= $eventId ?>&sport_id=<?= $es['id'] ?>" 
               class="sport-tab <?= $es['id'] == $sportId ? 'active' : '' ?>">
                <?= htmlspecialchars($es['sport_name']) ?>
            </a>
        <?php endforeach; ?>
    </div>
    
    <?php if ($genders): ?>
        <div class="sport-tabs" style="margin-top: 0.5rem;">
            <?php foreach ($genders as $g): ?>
                <a href="?event_id=<?= $eventId ?>&sport_id=<?= $sportId ?>&gender=<?= $g ?>"
                   class="sport-tab <?= $g === $currentGender ? 'active' : '' ?>">
                    <?= htmlspecialchars(ucfirst($g)) ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
    <div class="bracket-container">
        <?php foreach ($rounds as $roundNum => $roundMatches): ?>
            <div class="bracket-round">
                <h3 class="round-title">
                    <?php if ($roundNum == count($rounds)): ?>Final
                    <?php elseif ($roundNum == count($rounds) - 1): ?>Semi-Finals
                    <?php else: ?>Round <?= $roundNum ?>
                    <?php endif; ?>
                </h3>
                <?php foreach ($roundMatches as $match): ?>
                    <div class="bracket-match <?= $match['status'] ?>">
                        <div class="bracket-player <?= $match['winner_id'] == $match['player1_id'] && $match['winner_id'] ? 'winner' : '' ?>">
                            <span class="name"><?= htmlspecialchars($match['player1_name'] ?: 'TBD') ?></span>
                            <span class="score"><?= htmlspecialchars($match['score1'] ?? '') ?></span>
                        </div>
                        <div class="bracket-player <?= $match['winner_id'] == $match['player2_id'] && $match['winner_id'] ? 'winner' : '' ?>">
                            <span class="name"><?= htmlspecialchars($match['player2_name'] ?: 'TBD') ?></span>
                            <span class="score"><?= htmlspecialchars($match['score2'] ?? '') ?></span>
                        </div>
                        <div class="match-time-label"><?= $match['scheduled_time'] ? date('h:i A', strtotime($match['scheduled_time'])) : '' ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

