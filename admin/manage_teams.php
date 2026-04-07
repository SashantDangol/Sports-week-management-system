<?php
$pageTitle = 'Manage Teams';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../config/db.php';
requireRole('admin');

$eventId = intval($_GET['event_id'] ?? 0);
$sportId = intval($_GET['sport_id'] ?? 0);
if (!$eventId || !$sportId) { header('Location: dashboard.php'); exit(); }

// Load event and ensure ownership
$stmt = $pdo->prepare("SELECT * FROM events WHERE id = ? AND created_by = ?");
$stmt->execute([$eventId, getCurrentUserId()]);
$event = $stmt->fetch();
if (!$event) { header('Location: dashboard.php'); exit(); }

// Allow team management after registration has ended OR while ongoing (admin may need to adjust then regenerate brackets)
$today = date('Y-m-d');
$registrationEnded = ($event['status'] === 'registration' && $today >= $event['reg_end_date']);
$allowed = $registrationEnded || ($event['status'] === 'ongoing');
if (!$allowed) {
    header('Location: dashboard.php');
    exit();
}

// Load sport and ensure it's a team sport
$stmt = $pdo->prepare("SELECT * FROM event_sports WHERE id = ? AND event_id = ?");
$stmt->execute([$sportId, $eventId]);
$sport = $stmt->fetch();
if (!$sport || !$sport['is_team_sport']) { header('Location: configure_sports.php?event_id='.$eventId); exit(); }

// Ensure base teams exist
$stmt = $pdo->prepare("SELECT * FROM teams WHERE event_sport_id = ? ORDER BY id");
$stmt->execute([$sportId]);
$teams = $stmt->fetchAll();

if (!$teams) {
    $maxTeams = $sport['max_teams'] ?? 4;
    if ($maxTeams < 2) $maxTeams = 2;
    for ($t = 1; $t <= $maxTeams; $t++) {
        $stmtIns = $pdo->prepare("INSERT INTO teams (event_sport_id, team_name) VALUES (?, ?)");
        $stmtIns->execute([$sportId, "Team $t"]);
    }
    $stmt = $pdo->prepare("SELECT * FROM teams WHERE event_sport_id = ? ORDER BY id");
    $stmt->execute([$sportId]);
    $teams = $stmt->fetchAll();
}

// Fetch all registered players for this sport
$stmt = $pdo->prepare("
    SELECT DISTINCT u.id, u.full_name, r.gender
    FROM registrations r
    JOIN users u ON r.user_id = u.id
    WHERE r.event_id = ? AND (r.sport1_id = ? OR r.sport2_id = ?)
    ORDER BY u.full_name
");
$stmt->execute([$eventId, $sportId, $sportId]);
$allPlayers = $stmt->fetchAll();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        $pdo->beginTransaction();

        if ($action === 'assign_manual') {
            $targetTeam = intval($_POST['team_id'] ?? 0);
            $selected = $_POST['players'] ?? [];
            if ($targetTeam && !empty($selected)) {
                foreach ($selected as $pid) {
                    $pid = intval($pid);
                    $pdo->prepare("DELETE tm FROM team_members tm JOIN teams t ON tm.team_id = t.id WHERE t.event_sport_id = ? AND tm.user_id = ?")
                        ->execute([$sportId, $pid]);
                    $pdo->prepare("INSERT INTO team_members (team_id, user_id) VALUES (?, ?)")
                        ->execute([$targetTeam, $pid]);
                }
            }
        } elseif ($action === 'auto_distribute') {
            $pdo->prepare("DELETE tm FROM team_members tm JOIN teams t ON tm.team_id = t.id WHERE t.event_sport_id = ?")
                ->execute([$sportId]);

            // Fisher–Yates shuffle
            $participants = $allPlayers;
            $n = count($participants);
            for ($i = $n - 1; $i > 0; $i--) {
                $j = random_int(0, $i);
                $tmp = $participants[$i];
                $participants[$i] = $participants[$j];
                $participants[$j] = $tmp;
            }

            $membersPerTeam = $sport['members_per_team'] ?? 5;
            if ($membersPerTeam < 1) $membersPerTeam = 1;

            $teamCount = count($teams);
            $teamIdx = 0;
            $assignedCounts = array_fill(0, $teamCount, 0);

            foreach ($participants as $p) {
                $attempts = 0;
                while ($attempts < $teamCount && $assignedCounts[$teamIdx] >= $membersPerTeam) {
                    $teamIdx = ($teamIdx + 1) % $teamCount;
                    $attempts++;
                }
                $team = $teams[$teamIdx];
                $pdo->prepare("INSERT INTO team_members (team_id, user_id) VALUES (?, ?)")
                    ->execute([$team['id'], $p['id']]);
                $assignedCounts[$teamIdx]++;
                $teamIdx = ($teamIdx + 1) % $teamCount;
            }
        }

        $pdo->commit();
        header("Location: manage_teams.php?event_id=$eventId&sport_id=$sportId&success=1");
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Error managing teams: ' . $e->getMessage();
    }
}

// Current memberships
$stmt = $pdo->prepare("
    SELECT tm.team_id, tm.user_id, u.full_name
    FROM team_members tm
    JOIN users u ON tm.user_id = u.id
    JOIN teams t ON tm.team_id = t.id
    WHERE t.event_sport_id = ?
    ORDER BY tm.team_id, u.full_name
");
$stmt->execute([$sportId]);
$memberships = $stmt->fetchAll();

$playerTeams = [];
foreach ($memberships as $m) $playerTeams[$m['user_id']] = $m['team_id'];

$unassigned = array_filter($allPlayers, fn($p) => !isset($playerTeams[$p['id']]));

?>

<div class="dashboard">
    <div class="page-header">
        <h1>Manage Teams - <?= htmlspecialchars($sport['sport_name']) ?></h1>
        <div>
            <?php if ($event['status'] === 'ongoing'): ?>
                <a href="generate_brackets.php?event_id=<?= $eventId ?>" class="btn btn-secondary">Regenerate Brackets</a>
            <?php endif; ?>
            <a href="manage_events.php?event_id=<?= $eventId ?>&sport_id=<?= $sportId ?>" class="btn btn-secondary">← Back</a>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">Teams updated successfully.</div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="form-card">
        <h2>Unassigned Players</h2>
        <?php if (empty($unassigned)): ?>
            <p class="form-help">All players are currently assigned to a team.</p>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="action" value="assign_manual">
                <div class="form-row">
                    <div class="form-group">
                        <label>Select Players</label>
                        <select name="players[]" multiple size="8">
                            <?php foreach ($unassigned as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['full_name']) ?> (<?= htmlspecialchars(ucfirst($p['gender'])) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-help">Hold Ctrl to select multiple players.</p>
                    </div>
                    <div class="form-group">
                        <label>Assign to Team</label>
                        <select name="team_id" required>
                            <option value="">Select Team</option>
                            <?php foreach ($teams as $t): ?>
                                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['team_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Assign Selected Players</button>
                </div>
            </form>
        <?php endif; ?>

        <form method="POST" class="form-actions">
            <input type="hidden" name="action" value="auto_distribute">
            <button type="submit" class="btn btn-secondary" onclick="return confirm('Randomly distribute all registered players into teams? This will reset existing team assignments.')">
                Auto-distribute Players (Fisher–Yates)
            </button>
        </form>
    </div>

    <div class="form-card">
        <h2>Current Teams</h2>
        <div class="events-grid">
            <?php foreach ($teams as $t): ?>
                <div class="event-card">
                    <div class="event-card-header">
                        <h3><?= htmlspecialchars($t['team_name']) ?></h3>
                    </div>
                    <ul class="event-desc">
                        <?php
                        $members = array_values(array_filter($memberships, fn($m) => $m['team_id'] == $t['id']));
                        if (empty($members)): ?>
                            <li>No members yet.</li>
                        <?php else: ?>
                            <?php foreach ($members as $m): ?>
                                <li><?= htmlspecialchars($m['full_name']) ?></li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>