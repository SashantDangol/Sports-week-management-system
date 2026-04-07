<?php
$pageTitle = 'Manage Event';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/bracket_generator.php';
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

if (!$sportId && !empty($eventSports)) {
    $sportId = $eventSports[0]['id'];
}

$currentSport = null;
foreach ($eventSports as $es) {
    if ($es['id'] == $sportId) $currentSport = $es;
}

// Handle score submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $matchId = intval($_POST['match_id'] ?? 0);
    
    if ($action === 'update_score' && $matchId) {
        $score1 = trim($_POST['score1'] ?? '');
        $score2 = trim($_POST['score2'] ?? '');
        $winnerId = intval($_POST['winner_id'] ?? 0);
        
        $match = $pdo->prepare("SELECT * FROM matches WHERE id = ?")->execute([$matchId]);
        $match = $pdo->prepare("SELECT * FROM matches WHERE id = ?");
        $match->execute([$matchId]);
        $match = $match->fetch();
        
        if ($match && $winnerId) {
            $winnerName = ($winnerId == $match['player1_id']) ? $match['player1_name'] : $match['player2_name'];
            $loserId    = ($winnerId == $match['player1_id']) ? $match['player2_id']   : $match['player1_id'];
            $loserName  = ($winnerId == $match['player1_id']) ? $match['player2_name'] : $match['player1_name'];

            $pdo->prepare("UPDATE matches SET score1 = ?, score2 = ?, winner_id = ?, winner_name = ?, status = 'completed' WHERE id = ?")
                ->execute([$score1, $score2, $winnerId, $winnerName, $matchId]);

            advanceWinner($pdo, $matchId, $winnerId, $winnerName);
            // Route loser into losers bracket (double elimination)
            if ($loserId) advanceLoser($pdo, $matchId, $loserId, $loserName);
        }
    } elseif ($action === 'disqualify' && $matchId) {
        $disqualifiedId = intval($_POST['disqualified_id'] ?? 0);
        $match = $pdo->prepare("SELECT * FROM matches WHERE id = ?");
        $match->execute([$matchId]);
        $match = $match->fetch();

        if ($match && $disqualifiedId) {
            $winnerId   = ($disqualifiedId == $match['player1_id']) ? $match['player2_id']   : $match['player1_id'];
            $winnerName = ($winnerId == $match['player1_id'])        ? $match['player1_name'] : $match['player2_name'];
            $loserId    = $disqualifiedId;
            $loserName  = ($loserId == $match['player1_id'])         ? $match['player1_name'] : $match['player2_name'];

            $pdo->prepare("UPDATE matches SET winner_id = ?, winner_name = ?, status = 'disqualified', score1 = 'DQ', score2 = 'DQ' WHERE id = ?")
                ->execute([$winnerId, $winnerName, $matchId]);

            advanceWinner($pdo, $matchId, $winnerId, $winnerName);
            if ($loserId) advanceLoser($pdo, $matchId, $loserId, $loserName);
        }
    } elseif ($action === 'end_event') {
        // Generate results
        foreach ($eventSports as $es) {
            generateResults($pdo, $es['id']);
        }
        $pdo->prepare("UPDATE events SET status = 'completed' WHERE id = ?")->execute([$eventId]);
        header("Location: results.php?event_id=$eventId");
        exit();
    }
    
    header("Location: manage_events.php?event_id=$eventId&sport_id=$sportId");
    exit();
}

// Get matches for current sport
$matches = [];
if ($sportId) {
    $stmt = $pdo->prepare("SELECT * FROM matches WHERE event_sport_id = ? ORDER BY round, match_number");
    $stmt->execute([$sportId]);
    $matches = $stmt->fetchAll();
}

// Group by round
$rounds = [];
foreach ($matches as $m) {
    $rounds[$m['round']][] = $m;
}

function generateResults($pdo, $eventSportId) {
    // Find final match
    $stmt = $pdo->prepare("SELECT * FROM matches WHERE event_sport_id = ? ORDER BY round DESC, match_number LIMIT 1");
    $stmt->execute([$eventSportId]);
    $final = $stmt->fetch();
    
    if ($final && $final['status'] === 'completed') {
        // 1st place
        $pdo->prepare("INSERT INTO results (event_sport_id, position, player_id, player_name) VALUES (?, 1, ?, ?)")
            ->execute([$eventSportId, $final['winner_id'], $final['winner_name']]);
        
        // 2nd place (loser of final)
        $loserId = ($final['winner_id'] == $final['player1_id']) ? $final['player2_id'] : $final['player1_id'];
        $loserName = ($final['winner_id'] == $final['player1_id']) ? $final['player2_name'] : $final['player1_name'];
        $pdo->prepare("INSERT INTO results (event_sport_id, position, player_id, player_name) VALUES (?, 2, ?, ?)")
            ->execute([$eventSportId, $loserId, $loserName]);
        
        // 3rd place - losers of semi-finals
        $stmt = $pdo->prepare("SELECT * FROM matches WHERE event_sport_id = ? AND next_match_id = ? AND status = 'completed'");
        $stmt->execute([$eventSportId, $final['id']]);
        $semis = $stmt->fetchAll();
        foreach ($semis as $semi) {
            $semiLoserId = ($semi['winner_id'] == $semi['player1_id']) ? $semi['player2_id'] : $semi['player1_id'];
            $semiLoserName = ($semi['winner_id'] == $semi['player1_id']) ? $semi['player2_name'] : $semi['player1_name'];
            if ($semiLoserId) {
                $pdo->prepare("INSERT INTO results (event_sport_id, position, player_id, player_name) VALUES (?, 3, ?, ?)")
                    ->execute([$eventSportId, $semiLoserId, $semiLoserName]);
            }
        }
    }
}
?>

<div class="dashboard">
    <div class="page-header">
        <h1>Manage: <?= htmlspecialchars($event['name']) ?></h1>
        <div>
            <a href="view_brackets.php?event_id=<?= $eventId ?>" class="btn btn-secondary">View Brackets</a>
            <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="end_event">
                <button type="submit" class="btn btn-danger" onclick="return confirm('End this event? This will generate final results.')">🏁 End Event</button>
            </form>
        </div>
    </div>
    
    <!-- Sport Tabs -->
    <div class="sport-tabs">
        <?php foreach ($eventSports as $es): ?>
            <a href="?event_id=<?= $eventId ?>&sport_id=<?= $es['id'] ?>" 
               class="sport-tab <?= $es['id'] == $sportId ? 'active' : '' ?>">
                <?= htmlspecialchars($es['sport_name']) ?>
            </a>
        <?php endforeach; ?>
    </div>
    
    <?php if ($currentSport): ?>
        <h2><?= htmlspecialchars($currentSport['sport_name']) ?> - Matches</h2>
        
        <?php
        $bracketType = $currentSport['bracket_type'] ?? 'single_elim_one';
        
        // For double elim, separate WB/LB/GF
        if ($bracketType === 'double_elim') {
            $wbRounds = []; $lbRounds = [];
            foreach ($rounds as $rn => $rm) {
                $stage = $rm[0]['stage'] ?? 'stage1';
                if ($stage === 'stage2') $lbRounds[$rn] = $rm;
                else $wbRounds[$rn] = $rm;
            }
            $grandFinalRound  = !empty($wbRounds) ? max(array_keys($wbRounds)) : null;
            $wbOnlyRounds     = array_filter($wbRounds, fn($k) => $k < $grandFinalRound, ARRAY_FILTER_USE_KEY);
            $roundSections    = [
                'Winners Bracket' => $wbOnlyRounds,
                'Losers Bracket'  => $lbRounds,
                'Grand Final'     => $grandFinalRound && isset($wbRounds[$grandFinalRound]) ? [$grandFinalRound => $wbRounds[$grandFinalRound]] : [],
            ];
        } elseif ($bracketType === 'round_robin') {
            $roundSections = ['Round Robin Matches' => $rounds];
        } else {
            $roundSections = ['Bracket' => $rounds];
        }

        foreach ($roundSections as $sectionTitle => $sectionRounds):
            if (empty($sectionRounds)) continue;
        ?>
        <h3 style="margin-top:1.5rem;margin-bottom:0.5rem"><?= $sectionTitle ?></h3>
        <?php
        $roundCount = count($sectionRounds);
        $roundIdx   = 1;
        foreach ($sectionRounds as $roundNum => $roundMatches):
        ?>
            <div class="round-section">
                <h3>
                    <?php
                    if ($bracketType === 'round_robin') {
                        echo "Round $roundNum";
                    } elseif ($sectionTitle === 'Grand Final') {
                        echo "Grand Final";
                    } elseif ($sectionTitle === 'Winners Bracket') {
                        if ($roundIdx == $roundCount) echo 'WB Final';
                        elseif ($roundIdx == $roundCount - 1) echo 'WB Semi-Finals';
                        else echo "WB Round $roundIdx";
                    } elseif ($sectionTitle === 'Losers Bracket') {
                        if ($roundIdx == $roundCount) echo 'LB Final';
                        else echo "LB Round $roundIdx";
                    } else {
                        if ($roundNum == count($rounds)) echo 'Final';
                        elseif ($roundNum == count($rounds) - 1) echo 'Semi-Finals';
                        else echo "Round $roundNum";
                    }
                    ?>
                </h3>
                <div class="matches-list">
                    <?php foreach ($roundMatches as $match): ?>
                        <div class="match-card <?= $match['status'] ?>">
                            <div class="match-header">
                                <span class="match-num">Match #<?= $match['match_number'] ?></span>
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
                            
                            <?php if ($match['status'] === 'pending' && $match['player1_id'] && $match['player2_id']): ?>
                                <form method="POST" class="match-form">
                                    <input type="hidden" name="action" value="update_score">
                                    <input type="hidden" name="match_id" value="<?= $match['id'] ?>">
                                    <div class="score-inputs">
                                        <input type="text" name="score1" placeholder="Score" class="score-input">
                                        <span>-</span>
                                        <input type="text" name="score2" placeholder="Score" class="score-input">
                                    </div>
                                    <div class="winner-select">
                                        <label>Winner:</label>
                                        <select name="winner_id" required>
                                            <option value="">Select Winner</option>
                                            <option value="<?= $match['player1_id'] ?>"><?= htmlspecialchars($match['player1_name']) ?></option>
                                            <option value="<?= $match['player2_id'] ?>"><?= htmlspecialchars($match['player2_name']) ?></option>
                                        </select>
                                    </div>
                                    <button type="submit" class="btn btn-sm btn-primary">Submit Result</button>
                                </form>
                                
                                <form method="POST" class="disqualify-form">
                                    <input type="hidden" name="action" value="disqualify">
                                    <input type="hidden" name="match_id" value="<?= $match['id'] ?>">
                                    <select name="disqualified_id">
                                        <option value="">Disqualify...</option>
                                        <option value="<?= $match['player1_id'] ?>"><?= htmlspecialchars($match['player1_name']) ?></option>
                                        <option value="<?= $match['player2_id'] ?>"><?= htmlspecialchars($match['player2_name']) ?></option>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Disqualify this player?')">DQ</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php $roundIdx++; endforeach; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>


