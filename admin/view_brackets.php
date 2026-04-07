<?php
$pageTitle = 'View Brackets';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../config/db.php';
requireRole('admin');

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
<style>
    /* Container for alignment and spacing */
.table-container {
    margin: 2rem auto;
    max-width: 900px;
    padding: 0 15px;
}

.standings-title {
    font-family: sans-serif;
    color: #333;
    margin-bottom: 1rem;
    border-left: 5px solid #2c3e50;
    padding-left: 15px;
}

/* Main Table Styling */
.rr-table {
    width: 100%;
    border-collapse: collapse;
    background-color: #fff;
    border-radius: 8px;
    overflow: hidden; /* Rounds the corners of the table */
    box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
}

.rr-table thead tr {
    background-color: #2c3e50;
    color: #ffffff;
    text-align: center;
}

/* Cell Padding & Borders */
.rr-table th,
.rr-table td {
    padding: 15px 20px;
    border-bottom: 1px solid #eee;
}

/* Align names to the left for better readability */
.rr-table .player-name {
    text-align: left;
    font-weight: 500;
    color: #2c3e50;
}

/* Style the rank and stats columns */
.rr-table td:not(.player-name) {
    text-align: center;
}

/* Highlighting the Points column */
.rr-table td strong {
    color: #27ae60; /* Professional green for points */
    font-size: 1.1rem;
}

/* Zebra Striping */
.rr-table tbody tr:nth-of-type(even) {
    background-color: #f8f9fa;
}

/* Row Hover Animation */
.rr-table tbody tr {
    transition: background-color 0.2s ease;
}

.rr-table tbody tr:hover {
    background-color: #edf2f7;
}

/* Responsive adjustment for smaller screens */
@media (max-width: 600px) {
    .rr-table th, .rr-table td {
        padding: 10px;
        font-size: 0.9rem;
    }
}
</style>
<div class="dashboard">
    <div class="page-header">
        <h1>Brackets - <?= htmlspecialchars($event['name']) ?></h1>
        <a href="<?= $event['status'] === 'ongoing' ? 'manage_events' : 'dashboard' ?>.php?event_id=<?= $eventId ?>" class="btn btn-secondary">← Back</a>
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
    
    <?php
    // Fetch bracket type for current sport
    $currentSportData = null;
    foreach ($eventSports as $es) { if ($es['id'] == $sportId) { $currentSportData = $es; break; } }
    $bracketType = $currentSportData['bracket_type'] ?? 'single_elim_one';

    if ($bracketType === 'round_robin'):
        // ── Round Robin: show standings table + match list ────────────────
        // Build standings from completed matches
        $standings = [];
        foreach ($matches as $m) {
            foreach (['player1' => 'player2', 'player2' => 'player1'] as $side => $opp) {
                $pid  = $m[$side . '_id'];
                $pname = $m[$side . '_name'];
                if (!$pid || $m['is_' . $side . '_bye']) continue;
                if (!isset($standings[$pid])) {
                    $standings[$pid] = ['name' => $pname, 'played' => 0, 'wins' => 0, 'losses' => 0, 'points' => 0];
                }
            }
            if ($m['status'] === 'completed' && $m['winner_id']) {
                $loserId = ($m['winner_id'] == $m['player1_id']) ? $m['player2_id'] : $m['player1_id'];
                if ($m['winner_id'] && isset($standings[$m['winner_id']])) {
                    $standings[$m['winner_id']]['played']++;
                    $standings[$m['winner_id']]['wins']++;
                    $standings[$m['winner_id']]['points'] += 3;
                }
                if ($loserId && isset($standings[$loserId])) {
                    $standings[$loserId]['played']++;
                    $standings[$loserId]['losses']++;
                }
            }
        }
        usort($standings, fn($a, $b) => $b['points'] <=> $a['points'] ?: $b['wins'] <=> $a['wins']);
    ?>
        <div class="table-container">
    <h2 class="standings-title">Tournament Standings</h2>
    <table class="rr-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Player</th>
                <th>Played</th>
                <th>Wins</th>
                <th>Losses</th>
                <th class="pts-col">Pts</th>
            </tr>
        </thead>
        <tbody>
            <?php $rank = 1; foreach ($standings as $row): ?>
                <tr>
                    <td><?= $rank++ ?></td>
                    <td class="player-name"><?= htmlspecialchars($row['name']) ?></td>
                    <td><?= $row['played'] ?></td>
                    <td><?= $row['wins'] ?></td>
                    <td><?= $row['losses'] ?></td>
                    <td><strong><?= $row['points'] ?></strong></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

        <h2>Match Schedule</h2>
        <?php foreach ($rounds as $roundNum => $roundMatches): ?>
            <div class="round-section">
                <h3>Round <?= $roundNum ?></h3>
                <div class="matches-list">
                    <?php foreach ($roundMatches as $match): ?>
                        <div class="match-card <?= $match['status'] ?>">
                            <div class="match-header">
                                <span class="match-time"><?= $match['scheduled_time'] ? date('M d, h:i A', strtotime($match['scheduled_time'])) : 'TBD' ?></span>
                                <span class="badge badge-<?= $match['status'] ?>"><?= ucfirst($match['status']) ?></span>
                            </div>
                            <div class="match-players">
                                <div class="player <?= $match['winner_id'] == $match['player1_id'] && $match['winner_id'] ? 'winner' : '' ?>">
                                    <?= htmlspecialchars($match['player1_name'] ?: 'TBD') ?>
                                    <?php if ($match['score1']): ?><span class="score"><?= htmlspecialchars($match['score1']) ?></span><?php endif; ?>
                                </div>
                                <div class="vs">VS</div>
                                <div class="player <?= $match['winner_id'] == $match['player2_id'] && $match['winner_id'] ? 'winner' : '' ?>">
                                    <?= htmlspecialchars($match['player2_name'] ?: 'TBD') ?>
                                    <?php if ($match['score2']): ?><span class="score"><?= htmlspecialchars($match['score2']) ?></span><?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

    <?php elseif ($bracketType === 'double_elim'):
        // ── Double Elimination: split into Winners / Losers / Grand Final ──
        $wbMatches = array_filter($matches, fn($m) => $m['stage'] === 'stage1');
        $lbMatches = array_filter($matches, fn($m) => $m['stage'] === 'stage2');

        $wbRounds = []; foreach ($wbMatches as $m) $wbRounds[$m['round']][] = $m;
        $lbRounds = []; foreach ($lbMatches as $m) $lbRounds[$m['round']][] = $m;

        // Last WB round is the Grand Final
        $grandFinalRound = max(array_keys($wbRounds));
        $wbOnlyRounds    = array_filter($wbRounds, fn($k) => $k < $grandFinalRound, ARRAY_FILTER_USE_KEY);
    ?>
        <h2 style="margin-top:1.5rem">Winners Bracket</h2>
        <div class="bracket-container">
            <?php $totalWB = count($wbOnlyRounds); foreach ($wbOnlyRounds as $rn => $rm): ?>
                <div class="bracket-round">
                    <h3 class="round-title">
                        <?php if ($rn == $totalWB): ?>WB Final
                        <?php elseif ($rn == $totalWB - 1): ?>WB Semi-Finals
                        <?php else: ?>WB Round <?= $rn ?><?php endif; ?>
                    </h3>
                    <?php foreach ($rm as $match): ?>
                        <div class="bracket-match <?= $match['status'] ?>">
                            <div class="bracket-player <?= $match['winner_id'] == $match['player1_id'] && $match['winner_id'] ? 'winner' : '' ?> <?= $match['is_player1_bye'] ? 'bye' : '' ?>">
                                <span class="name"><?= htmlspecialchars($match['player1_name'] ?: 'TBD') ?></span>
                                <span class="score"><?= htmlspecialchars($match['score1'] ?? '') ?></span>
                            </div>
                            <div class="bracket-player <?= $match['winner_id'] == $match['player2_id'] && $match['winner_id'] ? 'winner' : '' ?> <?= $match['is_player2_bye'] ? 'bye' : '' ?>">
                                <span class="name"><?= htmlspecialchars($match['player2_name'] ?: 'TBD') ?></span>
                                <span class="score"><?= htmlspecialchars($match['score2'] ?? '') ?></span>
                            </div>
                            <div class="match-time-label"><?= $match['scheduled_time'] ? date('h:i A', strtotime($match['scheduled_time'])) : '' ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($lbRounds)): ?>
        <h2 style="margin-top:2rem">Losers Bracket</h2>
        <div class="bracket-container">
            <?php $lbCount = count($lbRounds); $lbIdx = 1; foreach ($lbRounds as $rn => $rm): ?>
                <div class="bracket-round">
                    <h3 class="round-title">
                        <?php if ($lbIdx == $lbCount): ?>LB Final
                        <?php else: ?>LB Round <?= $lbIdx ?><?php endif; ?>
                    </h3>
                    <?php foreach ($rm as $match): ?>
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
            <?php $lbIdx++; endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (isset($wbRounds[$grandFinalRound])): ?>
        <h2 style="margin-top:2rem">Grand Final</h2>
        <div class="bracket-container">
            <div class="bracket-round">
                <h3 class="round-title">Grand Final</h3>
                <?php foreach ($wbRounds[$grandFinalRound] as $match): ?>
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
        </div>
        <?php endif; ?>

    <?php else:
        // ── Single Elimination ────────────────────────────────────────────
    ?>
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
                        <div class="bracket-player <?= $match['winner_id'] == $match['player1_id'] && $match['winner_id'] ? 'winner' : '' ?> <?= $match['is_player1_bye'] ? 'bye' : '' ?>">
                            <span class="name"><?= htmlspecialchars($match['player1_name'] ?: 'TBD') ?></span>
                            <span class="score"><?= htmlspecialchars($match['score1'] ?? '') ?></span>
                        </div>
                        <div class="bracket-player <?= $match['winner_id'] == $match['player2_id'] && $match['winner_id'] ? 'winner' : '' ?> <?= $match['is_player2_bye'] ? 'bye' : '' ?>">
                            <span class="name"><?= htmlspecialchars($match['player2_name'] ?: 'TBD') ?></span>
                            <span class="score"><?= htmlspecialchars($match['score2'] ?? '') ?></span>
                        </div>
                        <div class="match-time-label"><?= $match['scheduled_time'] ? date('h:i A', strtotime($match['scheduled_time'])) : '' ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>


