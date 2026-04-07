<?php
$pageTitle = 'Team Formation';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../config/db.php';
requireRole('admin');

$eventId = intval($_GET['event_id'] ?? 0);
if (!$eventId) { header('Location: dashboard.php'); exit(); }

$event = $pdo->prepare("SELECT * FROM events WHERE id = ? AND created_by = ?");
$event->execute([$eventId, getCurrentUserId()]);
$event = $event->fetch();
if (!$event) { header('Location: dashboard.php'); exit(); }

if ($event['status'] !== 'registration_ended') {
    header('Location: dashboard.php');
    exit();
}

$stmt = $pdo->prepare("SELECT * FROM event_sports WHERE event_id = ? AND is_team_sport = 1");
$stmt->execute([$eventId]);
$teamSports = $stmt->fetchAll();

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_team') {
        $sportId   = intval($_POST['sport_id'] ?? 0);
        $teamName  = trim($_POST['team_name'] ?? '');
        $memberIds = $_POST['member_ids'] ?? [];

        if (empty($teamName) || empty($memberIds)) {
            $error = 'Please provide a team name and select at least one member.';
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO teams (event_sport_id, team_name) VALUES (?, ?)");
                $stmt->execute([$sportId, $teamName]);
                $teamId = $pdo->lastInsertId();
                foreach ($memberIds as $memberId) {
                    $pdo->prepare("INSERT INTO team_members (team_id, user_id) VALUES (?, ?)")
                        ->execute([$teamId, intval($memberId)]);
                }
                $pdo->commit();
                $success = 'Team "' . htmlspecialchars($teamName) . '" created successfully!';
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Error creating team: ' . $e->getMessage();
            }
        }

    } elseif ($action === 'randomize') {
        $sportId        = intval($_POST['sport_id'] ?? 0);
        $targetGender   = $_POST['gender'] ?? '';
        $teamsPerGender = max(2, intval($_POST['teams_per_gender'] ?? 2));

        if (!$sportId || !in_array($targetGender, ['male', 'female', 'other'])) {
            $error = 'Invalid randomize request.';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    SELECT DISTINCT u.id, u.full_name
                    FROM registrations r
                    JOIN users u ON r.user_id = u.id
                    WHERE r.event_id = ? AND (r.sport1_id = ? OR r.sport2_id = ?) AND r.gender = ?
                    ORDER BY u.full_name
                ");
                $stmt->execute([$eventId, $sportId, $sportId, $targetGender]);
                $players = $stmt->fetchAll();

                if (count($players) < 2) {
                    throw new Exception('Not enough ' . $targetGender . ' players to form teams (need at least 2).');
                }

                $gLabel = ucfirst($targetGender);
                $stmt = $pdo->prepare("SELECT t.id FROM teams t WHERE t.event_sport_id = ? AND t.team_name LIKE ?");
                $stmt->execute([$sportId, "$gLabel Team %"]);
                $oldTeamIds = array_column($stmt->fetchAll(), 'id');

                if (!empty($oldTeamIds)) {
                    $ph = implode(',', array_fill(0, count($oldTeamIds), '?'));
                    $pdo->prepare("DELETE FROM team_members WHERE team_id IN ($ph)")->execute($oldTeamIds);
                    $pdo->prepare("DELETE FROM teams WHERE id IN ($ph)")->execute($oldTeamIds);
                }

                $newTeamIds = [];
                for ($t = 1; $t <= $teamsPerGender; $t++) {
                    $pdo->prepare("INSERT INTO teams (event_sport_id, team_name) VALUES (?, ?)")
                        ->execute([$sportId, "$gLabel Team $t"]);
                    $newTeamIds[] = $pdo->lastInsertId();
                }

                $n = count($players);
                for ($i = $n - 1; $i > 0; $i--) {
                    $j = random_int(0, $i);
                    [$players[$i], $players[$j]] = [$players[$j], $players[$i]];
                }

                foreach ($players as $idx => $p) {
                    $pdo->prepare("INSERT INTO team_members (team_id, user_id) VALUES (?, ?)")
                        ->execute([$newTeamIds[$idx % $teamsPerGender], $p['id']]);
                }

                $pdo->commit();
                $success = ucfirst($targetGender) . ' players have been randomly distributed into ' . $teamsPerGender . ' teams.';
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = $e->getMessage();
            }
        }

    } elseif ($action === 'delete_team') {
        $teamId = intval($_POST['team_id'] ?? 0);
        if ($teamId) {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM team_members WHERE team_id = ?")->execute([$teamId]);
                $pdo->prepare("DELETE FROM teams WHERE id = ?")->execute([$teamId]);
                $pdo->commit();
                $success = 'Team deleted successfully.';
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Error deleting team: ' . $e->getMessage();
            }
        }
    }
}

// Build per-sport data
$sportData = [];
foreach ($teamSports as $sport) {
    $sid = $sport['id'];

    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, u.full_name, r.gender
        FROM registrations r
        JOIN users u ON r.user_id = u.id
        WHERE r.event_id = ? AND (r.sport1_id = ? OR r.sport2_id = ?)
        ORDER BY r.gender, u.full_name
    ");
    $stmt->execute([$eventId, $sid, $sid]);
    $allPlayers = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT DISTINCT tm.user_id FROM team_members tm
        JOIN teams t ON tm.team_id = t.id WHERE t.event_sport_id = ?
    ");
    $stmt->execute([$sid]);
    $assignedFlip = array_flip(array_column($stmt->fetchAll(), 'user_id'));

    $allByGender        = ['male' => [], 'female' => [], 'other' => []];
    $unassignedByGender = ['male' => [], 'female' => [], 'other' => []];
    foreach ($allPlayers as $p) {
        $g = $p['gender'] ?? 'other';
        $allByGender[$g][] = $p;
        if (!isset($assignedFlip[$p['id']])) $unassignedByGender[$g][] = $p;
    }

    $stmt = $pdo->prepare("
        SELECT t.*, COUNT(tm.id) as member_count FROM teams t
        LEFT JOIN team_members tm ON t.id = tm.team_id
        WHERE t.event_sport_id = ? GROUP BY t.id ORDER BY t.team_name
    ");
    $stmt->execute([$sid]);
    $teams = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT tm.team_id, u.full_name, r.gender
        FROM team_members tm
        JOIN users u ON tm.user_id = u.id
        JOIN registrations r ON r.user_id = u.id AND r.event_id = ?
        JOIN teams t ON tm.team_id = t.id
        WHERE t.event_sport_id = ? ORDER BY u.full_name
    ");
    $stmt->execute([$eventId, $sid]);
    $membersByTeam = [];
    foreach ($stmt->fetchAll() as $m) $membersByTeam[$m['team_id']][] = $m;

    $teamsByGender = ['male' => [], 'female' => [], 'other' => []];
    foreach ($teams as $t) {
        if (stripos($t['team_name'], 'Male Team') === 0)        $teamsByGender['male'][]   = $t;
        elseif (stripos($t['team_name'], 'Female Team') === 0)  $teamsByGender['female'][] = $t;
        else                                                     $teamsByGender['other'][]  = $t;
    }

    $sportData[$sid] = compact(
        'sport', 'allByGender', 'unassignedByGender',
        'teams', 'teamsByGender', 'membersByTeam', 'assignedFlip', 'allPlayers'
    );
}
?>
<style>
    :root {
    --primary-color: #2563eb;
    --primary-hover: #1d4ed8;
    --bg-color: #f8fafc;
    --card-bg: #ffffff;
    --border-color: #e2e8f0;
    --text-main: #1e293b;
    --text-muted: #64748b;
    --male-accent: #0ea5e9;
    --female-accent: #ec4899;
    --other-accent: #8b5cf6;
    --success: #22c55e;
    --warning: #f59e0b;
}

/* Page Layout */
.dashboard {
    max-width: 1200px;
    margin: 2rem auto;
    padding: 0 1.5rem;
    font-family: 'Inter', system-ui, sans-serif;
    color: var(--text-main);
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid var(--border-color);
}

/* Sport Cards */
.tf-sport-card {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    margin-bottom: 3rem;
    box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
    overflow: hidden;
}

.tf-sport-header {
    padding: 1.5rem;
    background: #f1f5f9;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid var(--border-color);
}

.tf-sport-title h2 { margin: 0; font-size: 1.5rem; }
.team-badge {
    background: var(--primary-color);
    color: white;
    font-size: 0.75rem;
    padding: 0.2rem 0.6rem;
    border-radius: 99px;
    text-transform: uppercase;
}

/* Stats Pills */
.tf-sport-stats { display: flex; gap: 0.75rem; }
.tf-stat-pill {
    background: white;
    border: 1px solid var(--border-color);
    padding: 0.5rem 1rem;
    border-radius: 8px;
    text-align: center;
}
.tf-stat-num { display: block; font-weight: 700; font-size: 1.1rem; }
.tf-stat-lbl { font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; }
.tf-stat-warn { border-color: var(--warning); color: #b45309; }
.tf-stat-done { border-color: var(--success); color: #15803d; }

/* Tabs */
.tf-tabs {
    display: flex;
    background: #f8fafc;
    padding: 0.5rem 1.5rem 0;
    gap: 0.5rem;
}

.tf-tab {
    padding: 0.75rem 1.5rem;
    border: none;
    background: none;
    cursor: pointer;
    font-weight: 600;
    color: var(--text-muted);
    border-bottom: 3px solid transparent;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.2s;
}

.tf-tab.active {
    color: var(--primary-color);
    border-bottom-color: var(--primary-color);
}

.tf-tab-badge {
    background: var(--warning);
    color: white;
    font-size: 0.7rem;
    padding: 0.1rem 0.4rem;
    border-radius: 6px;
}

/* Panel Layout */
.tf-panel-inner {
    display: grid;
    grid-template-columns: 350px 1fr;
    min-height: 500px;
}

.tf-actions-col {
    padding: 1.5rem;
    background: #fafcfd;
    border-right: 1px solid var(--border-color);
}

.tf-teams-col { padding: 1.5rem; }

/* Action Boxes */
.tf-action-box {
    background: white;
    border: 1px solid var(--border-color);
    border-radius: 10px;
    padding: 1.25rem;
    margin-bottom: 1.5rem;
}

.tf-action-box-header { display: flex; gap: 1rem; margin-bottom: 1rem; }
.tf-action-icon { font-size: 1.5rem; }
.tf-action-box h4 { margin: 0; font-size: 1rem; }
.tf-action-desc { font-size: 0.85rem; color: var(--text-muted); margin: 0.25rem 0 0; }

.tf-randomize-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1rem;
    font-size: 0.9rem;
}

.tf-num-input {
    width: 60px;
    padding: 0.4rem;
    border: 1px solid var(--border-color);
    border-radius: 4px;
}

/* Checkbox Grid */
.members-grid {
    max-height: 200px;
    overflow-y: auto;
    border: 1px solid var(--border-color);
    padding: 0.5rem;
    border-radius: 6px;
    background: white;
}

.member-checkbox {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.4rem;
    font-size: 0.85rem;
    cursor: pointer;
}

.member-checkbox:hover { background: #f1f5f9; }

/* Team Cards */
.tf-teams-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 1.25rem;
}

.tf-team-card {
    border: 1px solid var(--border-color);
    border-radius: 10px;
    padding: 1rem;
    position: relative;
    background: white;
}

.tf-team-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 0.75rem;
}

.tf-team-card h5 { margin: 0; font-size: 1rem; color: var(--text-main); }

.tf-team-card-actions { display: flex; align-items: center; gap: 0.5rem; }

.member-count {
    background: #f1f5f9;
    font-size: 0.75rem;
    font-weight: bold;
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
}

.tf-delete-btn {
    border: none;
    background: none;
    color: #ef4444;
    font-size: 1.2rem;
    cursor: pointer;
    line-height: 1;
}

.team-members-list {
    list-style: none;
    padding: 0;
    margin: 0;
    border-top: 1px solid #f1f5f9;
    padding-top: 0.5rem;
}

.team-members-list li {
    font-size: 0.85rem;
    padding: 0.25rem 0;
    color: var(--text-muted);
}

/* Gender Accents */
.tf-team-male { border-top: 4px solid var(--male-accent); }
.tf-team-female { border-top: 4px solid var(--female-accent); }
.tf-team-other { border-top: 4px solid var(--other-accent); }

/* Buttons */
.btn {
    padding: 0.6rem 1.2rem;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    border: none;
    transition: background 0.2s;
}

.btn-primary { background: var(--primary-color); color: white; }
.btn-primary:hover { background: var(--primary-hover); }
.btn-secondary { background: #e2e8f0; color: var(--text-main); }
.btn-secondary:hover { background: #cbd5e1; }
.btn-full { width: 100%; margin-top: 0.5rem; }

/* Empty States */
.tf-no-teams, .tf-all-assigned-box {
    text-align: center;
    padding: 3rem 1rem;
    color: var(--text-muted);
}
.tf-check-big { font-size: 3rem; color: var(--success); display: block; }
.tf-all-placed { color: var(--success); font-weight: bold; }

@media (max-width: 900px) {
    .tf-panel-inner { grid-template-columns: 1fr; }
    .tf-actions-col { border-right: none; border-bottom: 1px solid var(--border-color); }
}
</style>
<div class="dashboard">
    <div class="page-header">
        <h1>Team Formation — <?= htmlspecialchars($event['name']) ?></h1>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
            <a href="configure_sports.php?event_id=<?= $eventId ?>" class="btn btn-secondary">← Configure Sports</a>
            <a href="generate_brackets.php?event_id=<?= $eventId ?>" class="btn btn-primary">Generate Brackets →</a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if (empty($teamSports)): ?>
        <div class="empty-state"><p>No team sports configured for this event.</p></div>
    <?php else: ?>

    <?php foreach ($sportData as $sid => $data):
        $sport            = $data['sport'];
        $membersByTeam    = $data['membersByTeam'];
        $totalPlayers     = count($data['allPlayers']);
        $totalAssigned    = count($data['assignedFlip']);
        $totalUnassigned  = $totalPlayers - $totalAssigned;
        $allDone          = $totalUnassigned === 0;
    ?>

    <div class="tf-sport-card">

        <!-- Sport header -->
        <div class="tf-sport-header">
            <div class="tf-sport-title">
                <h2><?= htmlspecialchars($sport['sport_name']) ?></h2>
                <span class="team-badge">Team Sport</span>
            </div>
            <div class="tf-sport-stats">
                <div class="tf-stat-pill">
                    <span class="tf-stat-num"><?= $totalPlayers ?></span>
                    <span class="tf-stat-lbl">Registered</span>
                </div>
                <div class="tf-stat-pill <?= $allDone ? 'tf-stat-done' : 'tf-stat-warn' ?>">
                    <span class="tf-stat-num"><?= $totalAssigned ?></span>
                    <span class="tf-stat-lbl">Assigned</span>
                </div>
                <div class="tf-stat-pill <?= $allDone ? 'tf-stat-done' : 'tf-stat-warn' ?>">
                    <span class="tf-stat-num"><?= $totalUnassigned ?></span>
                    <span class="tf-stat-lbl">Unassigned</span>
                </div>
            </div>
        </div>

        <!-- Gender tabs -->
        <div class="tf-tabs" id="tabs-<?= $sid ?>">
            <?php $first = true;
            foreach (['male' => '♂ Male', 'female' => '♀ Female', 'other' => '◎ Other'] as $gender => $gLabel):
                if (empty($data['allByGender'][$gender])) continue;
                $uc = count($data['unassignedByGender'][$gender]);
            ?>
                <button type="button"
                        class="tf-tab <?= $first ? 'active' : '' ?>"
                        onclick="switchTab(<?= $sid ?>, '<?= $gender ?>', this)">
                    <?= $gLabel ?>
                    <?php if ($uc > 0): ?>
                        <span class="tf-tab-badge"><?= $uc ?></span>
                    <?php else: ?>
                        <span class="tf-tab-check">✓</span>
                    <?php endif; ?>
                </button>
            <?php $first = false; endforeach; ?>
        </div>

        <!-- Gender panels -->
        <?php $first = true;
        foreach (['male' => '♂ Male', 'female' => '♀ Female', 'other' => '◎ Other'] as $gender => $gLabel):
            if (empty($data['allByGender'][$gender])) continue;
            $gUnassigned = $data['unassignedByGender'][$gender];
            $gTeams      = $data['teamsByGender'][$gender];
            $gTotal      = count($data['allByGender'][$gender]);
        ?>
        <div class="tf-panel" id="panel-<?= $sid ?>-<?= $gender ?>" style="<?= $first ? '' : 'display:none' ?>">
            <div class="tf-panel-inner">

                <!-- Actions column -->
                <div class="tf-actions-col">

                    <!-- Randomize -->
                    <div class="tf-action-box tf-box-randomize">
                        <div class="tf-action-box-header">
                            <span class="tf-action-icon">🔀</span>
                            <div>
                                <h4>Auto-randomize</h4>
                                <p class="tf-action-desc">Randomly distribute all <?= $gTotal ?> <?= strtolower($gLabel) ?> players into equal teams. Replaces existing <?= strtolower($gLabel) ?> teams.</p>
                            </div>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="action"   value="randomize">
                            <input type="hidden" name="sport_id" value="<?= $sid ?>">
                            <input type="hidden" name="gender"   value="<?= $gender ?>">
                            <div class="tf-randomize-row">
                                <label>Number of teams</label>
                                <input type="number" name="teams_per_gender"
                                       value="2" min="2" max="<?= $gTotal ?>"
                                       class="tf-num-input">
                            </div>
                            <button type="submit" class="btn btn-primary btn-full"
                                    onclick="return confirm('Randomize all <?= $gLabel ?> players? Existing <?= strtolower($gLabel) ?> teams for this sport will be deleted and recreated.')">
                                🔀 Randomize <?= $gLabel ?> Players
                            </button>
                        </form>
                    </div>

                    <!-- Manual create -->
                    <div class="tf-action-box tf-box-manual">
                        <div class="tf-action-box-header">
                            <span class="tf-action-icon">✋</span>
                            <div>
                                <h4>Create manually</h4>
                                <?php if (!empty($gUnassigned)): ?>
                                    <p class="tf-action-desc"><?= count($gUnassigned) ?> unassigned <?= strtolower($gLabel) ?> player<?= count($gUnassigned) !== 1 ? 's' : '' ?>.</p>
                                <?php else: ?>
                                    <p class="tf-action-desc tf-all-placed">All <?= strtolower($gLabel) ?> players are assigned.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!empty($gUnassigned)): ?>
                        <form method="POST">
                            <input type="hidden" name="action"   value="create_team">
                            <input type="hidden" name="sport_id" value="<?= $sid ?>">
                            <div class="form-group" style="margin-bottom:0.75rem;">
                                <label>Team name</label>
                                <input type="text" name="team_name"
                                       placeholder="e.g. <?= $gLabel ?> Team A" required>
                            </div>
                            <div class="form-group" style="margin-bottom:0.75rem;">
                                <label>Select players <small>(unassigned only)</small></label>
                                <div class="members-grid">
                                    <?php foreach ($gUnassigned as $p): ?>
                                        <label class="member-checkbox">
                                            <input type="checkbox" name="member_ids[]" value="<?= $p['id'] ?>">
                                            <span><?= htmlspecialchars($p['full_name']) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-secondary btn-full">+ Create Team</button>
                        </form>
                        <?php else: ?>
                        <div class="tf-all-assigned-box">
                            <span class="tf-check-big">✓</span>
                            <p>All <?= strtolower($gLabel) ?> players placed.</p>
                        </div>
                        <?php endif; ?>
                    </div>

                </div><!-- tf-actions-col -->

                <!-- Teams column -->
                <div class="tf-teams-col">
                    <div class="tf-teams-col-header">
                        <h4><?= $gLabel ?> Teams</h4>
                        <span class="tf-team-count"><?= count($gTeams) ?> team<?= count($gTeams) !== 1 ? 's' : '' ?></span>
                    </div>

                    <?php if (empty($gTeams)): ?>
                        <div class="tf-no-teams">
                            <span class="tf-no-teams-icon">🏷️</span>
                            <p>No teams yet. Use Randomize or create one manually.</p>
                        </div>
                    <?php else: ?>
                        <div class="tf-teams-grid">
                            <?php foreach ($gTeams as $t):
                                $members = $membersByTeam[$t['id']] ?? [];
                            ?>
                                <div class="tf-team-card tf-team-<?= $gender ?>">
                                    <div class="tf-team-card-header">
                                        <h5><?= htmlspecialchars($t['team_name']) ?></h5>
                                        <div class="tf-team-card-actions">
                                            <span class="member-count"><?= count($members) ?></span>
                                            <form method="POST" style="margin:0;display:inline;">
                                                <input type="hidden" name="action"  value="delete_team">
                                                <input type="hidden" name="team_id" value="<?= $t['id'] ?>">
                                                <button type="submit" class="tf-delete-btn"
                                                        onclick="return confirm('Delete \'<?= htmlspecialchars(addslashes($t['team_name'])) ?>\'? Members will become unassigned.')"
                                                        title="Delete team">×</button>
                                            </form>
                                        </div>
                                    </div>
                                    <?php if (empty($members)): ?>
                                        <p class="team-empty">No members yet.</p>
                                    <?php else: ?>
                                        <ul class="team-members-list">
                                            <?php foreach ($members as $m): ?>
                                                <li><?= htmlspecialchars($m['full_name']) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div><!-- tf-teams-col -->

            </div><!-- tf-panel-inner -->
        </div><!-- tf-panel -->
        <?php $first = false; endforeach; ?>

    </div><!-- tf-sport-card -->
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
function switchTab(sportId, gender, btn) {
    document.querySelectorAll('[id^="panel-' + sportId + '-"]').forEach(p => p.style.display = 'none');
    document.querySelectorAll('#tabs-' + sportId + ' .tf-tab').forEach(t => t.classList.remove('active'));
    document.getElementById('panel-' + sportId + '-' + gender).style.display = '';
    btn.classList.add('active');
}
</script>