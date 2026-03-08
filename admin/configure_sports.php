<?php
$pageTitle = 'Configure Sports';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../config/db.php';
requireRole('admin');

$eventId = intval($_GET['event_id'] ?? 0);
if (!$eventId) { header('Location: dashboard.php'); exit(); }

$event = $pdo->prepare("SELECT * FROM events WHERE id = ? AND created_by = ?");
$event->execute([$eventId, getCurrentUserId()]);
$event = $event->fetch();
if (!$event) { header('Location: dashboard.php'); exit(); }

// Allow configuring sports only after registration period has ended
if ($event['status'] !== 'registration_ended') {
    header('Location: dashboard.php');
    exit();
}

$stmt = $pdo->prepare("SELECT * FROM event_sports WHERE event_id = ?");
$stmt->execute([$eventId]);
$eventSports = $stmt->fetchAll();

$teamSports = ['Basketball', 'Football', 'Badminton Double', 'Table Tennis Double', 'Carrom Double'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        foreach ($eventSports as $es) {
            $prefix = "sport_" . $es['id'];
            $bracketType = $_POST[$prefix . '_bracket'] ?? 'single_elim_one';
            $format = $_POST[$prefix . '_format'] ?? 'single_stage';
            $secondBracket = $_POST[$prefix . '_second_bracket'] ?? null;
            $avgTime = intval($_POST[$prefix . '_avg_time'] ?? 30);
            $placement = $_POST[$prefix . '_placement'] ?? 'random';
            $maxTeams = intval($_POST[$prefix . '_max_teams'] ?? 0) ?: null;
            $membersPerTeam = intval($_POST[$prefix . '_members'] ?? 0) ?: null;
            
            $stmt = $pdo->prepare("UPDATE event_sports SET 
                bracket_type = ?, format = ?, second_stage_bracket = ?,
                avg_game_time = ?, placement_type = ?, max_teams = ?, members_per_team = ?
                WHERE id = ?");
            $stmt->execute([$bracketType, $format, $secondBracket, $avgTime, $placement, $maxTeams, $membersPerTeam, $es['id']]);
        }
        
        $pdo->commit();
        $success = 'Sports configured successfully!';
        
        // Refresh data
        $stmt = $pdo->prepare("SELECT * FROM event_sports WHERE event_id = ?");
        $stmt->execute([$eventId]);
        $eventSports = $stmt->fetchAll();
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Error: ' . $e->getMessage();
    }
}
?>

<div class="dashboard">
    <div class="page-header">
        <h1>Configure Sports - <?= htmlspecialchars($event['name']) ?></h1>
        <a href="dashboard.php" class="btn btn-secondary">← Back</a>
    </div>
    
    <?php if (isset($success)): ?>
        <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div class="alert alert-error"><?= $error ?></div>
    <?php endif; ?>
    
    <form method="POST">
        <?php foreach ($eventSports as $es): ?>
            <?php
                $stmtCount = $pdo->prepare("
                    SELECT COUNT(DISTINCT r.user_id)
                    FROM registrations r
                    WHERE r.event_id = ? AND (r.sport1_id = ? OR r.sport2_id = ?)
                ");
                $stmtCount->execute([$eventId, $es['id'], $es['id']]);
                $playerCount = (int) $stmtCount->fetchColumn();
            ?>
            <div class="form-card sport-config-card">
                <h2><?= htmlspecialchars($es['sport_name']) ?>
                    <?php if ($es['is_team_sport']): ?><span class="team-badge">Team Sport</span><?php endif; ?>
                </h2>
                <p class="form-help">Registered players for this sport: <?= $playerCount ?></p>
                <?php if ($es['is_team_sport']): ?>
                    <p class="form-help">
                        <a href="manage_teams.php?event_id=<?= $eventId ?>&sport_id=<?= $es['id'] ?>" class="btn btn-sm btn-secondary">
                            Manage Teams
                        </a>
                    </p>
                <?php endif; ?>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Bracket Type</label>
                        <select name="sport_<?= $es['id'] ?>_bracket">
                            <option value="single_elim_one" <?= $es['bracket_type'] === 'single_elim_one' ? 'selected' : '' ?>>Single Elimination (One-sided)</option>
                            <option value="single_elim_two" <?= $es['bracket_type'] === 'single_elim_two' ? 'selected' : '' ?>>Single Elimination (Two-sided)</option>
                            <option value="double_elim" <?= $es['bracket_type'] === 'double_elim' ? 'selected' : '' ?>>Double Elimination</option>
                            <option value="round_robin" <?= $es['bracket_type'] === 'round_robin' ? 'selected' : '' ?>>Round Robin</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Format</label>
                        <select name="sport_<?= $es['id'] ?>_format" class="format-select" data-sport="<?= $es['id'] ?>">
                            <option value="single_stage" <?= $es['format'] === 'single_stage' ? 'selected' : '' ?>>Single Stage</option>
                            <option value="double_stage" <?= $es['format'] === 'double_stage' ? 'selected' : '' ?>>Double Stage</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row second-stage-config" id="second_stage_<?= $es['id'] ?>" style="<?= $es['format'] === 'double_stage' ? '' : 'display:none' ?>">
                    <div class="form-group">
                        <label>Second Stage Bracket Type</label>
                        <select name="sport_<?= $es['id'] ?>_second_bracket">
                            <option value="single_elim_one" <?= $es['second_stage_bracket'] === 'single_elim_one' ? 'selected' : '' ?>>Single Elimination (One-sided)</option>
                            <option value="single_elim_two" <?= $es['second_stage_bracket'] === 'single_elim_two' ? 'selected' : '' ?>>Single Elimination (Two-sided)</option>
                            <option value="double_elim" <?= $es['second_stage_bracket'] === 'double_elim' ? 'selected' : '' ?>>Double Elimination</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Avg. Game Time (minutes)</label>
                        <input type="number" name="sport_<?= $es['id'] ?>_avg_time" value="<?= $es['avg_game_time'] ?>" min="5" max="180">
                    </div>
                    <div class="form-group">
                        <label>Player Placement</label>
                        <select name="sport_<?= $es['id'] ?>_placement">
                            <option value="random" <?= $es['placement_type'] === 'random' ? 'selected' : '' ?>>Randomized</option>
                            <option value="manual" <?= $es['placement_type'] === 'manual' ? 'selected' : '' ?>>Manual</option>
                        </select>
                    </div>
                </div>
                
                <?php if ($es['is_team_sport']): ?>
                <div class="form-row">
                    <div class="form-group">
                        <label>Max Teams</label>
                        <input type="number" name="sport_<?= $es['id'] ?>_max_teams" value="<?= $es['max_teams'] ?? 8 ?>" min="2" max="64">
                    </div>
                    <div class="form-group">
                        <label>Members Per Team</label>
                        <input type="number" name="sport_<?= $es['id'] ?>_members" value="<?= $es['members_per_team'] ?? 5 ?>" min="2" max="30">
                    </div>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save Configuration</button>
            <?php 
            // Check if there are team sports
            $hasTeamSports = false;
            foreach ($eventSports as $es) {
                if ($es['is_team_sport']) {
                    $hasTeamSports = true;
                    break;
                }
            }
            ?>
            <?php if ($hasTeamSports): ?>
                <a href="team_formation.php?event_id=<?= $eventId ?>" class="btn btn-secondary">Form Teams</a>
            <?php endif; ?>
            <a href="start_tournament.php?event_id=<?= $eventId ?>" class="btn btn-success">Start Tournament →</a>
        </div>
    </form>
</div>

<script>
document.querySelectorAll('.format-select').forEach(select => {
    select.addEventListener('change', function() {
        const sportId = this.dataset.sport;
        document.getElementById('second_stage_' + sportId).style.display = 
            this.value === 'double_stage' ? '' : 'none';
    });
});
</script>

