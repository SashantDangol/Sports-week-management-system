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

// Only allow team formation after sports are configured
if ($event['status'] !== 'registration_ended') {
    header('Location: dashboard.php');
    exit();
}

// Get team sports for this event
$stmt = $pdo->prepare("SELECT * FROM event_sports WHERE event_id = ? AND is_team_sport = 1");
$stmt->execute([$eventId]);
$teamSports = $stmt->fetchAll();

// Get registrations for team sports
$stmt = $pdo->prepare("SELECT r.*, es.sport_name 
                      FROM registrations r 
                      JOIN event_sports es ON r.sport1_id = es.id OR r.sport2_id = es.id
                      WHERE r.event_id = ? 
                      AND (es.is_team_sport = 1 OR (r.sport2_id IS NOT NULL AND 
                          (SELECT is_team_sport FROM event_sports WHERE id = r.sport2_id) = 1))
                      GROUP BY r.id");
$stmt->execute([$eventId]);
$registrations = $stmt->fetchAll();

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_team') {
        $sportId = intval($_POST['sport_id'] ?? 0);
        $teamName = trim($_POST['team_name'] ?? '');
        $memberIds = $_POST['member_ids'] ?? [];
        
        if (empty($teamName) || empty($memberIds)) {
            $error = 'Please provide team name and select members.';
        } else {
            try {
                $pdo->beginTransaction();
                
                // Create team
                $stmt = $pdo->prepare("INSERT INTO teams (event_sport_id, team_name) VALUES (?, ?)");
                $stmt->execute([$sportId, $teamName]);
                $teamId = $pdo->lastInsertId();
                
                // Add team members
                foreach ($memberIds as $memberId) {
                    $stmt = $pdo->prepare("INSERT INTO team_members (team_id, user_id) VALUES (?, ?)");
                    $stmt->execute([$teamId, $memberId]);
                }
                
                $pdo->commit();
                $success = 'Team created successfully!';
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Error creating team: ' . $e->getMessage();
            }
        }
    }
}

// Get existing teams
$stmt = $pdo->prepare("SELECT t.*, es.sport_name, COUNT(tm.id) as member_count
                      FROM teams t 
                      JOIN event_sports es ON t.event_sport_id = es.id
                      LEFT JOIN team_members tm ON t.id = tm.team_id
                      WHERE t.event_sport_id IN (SELECT id FROM event_sports WHERE event_id = ? AND is_team_sport = 1)
                      GROUP BY t.id
                      ORDER BY es.sport_name, t.team_name");
$stmt->execute([$eventId]);
$existingTeams = $stmt->fetchAll();
?>

<div class="dashboard">
    <div class="page-header">
        <h1>Team Formation</h1>
        <div>
            <a href="configure_sports.php?event_id=<?= $eventId ?>" class="btn btn-secondary">← Back to Configuration</a>
            <a href="generate_brackets.php?event_id=<?= $eventId ?>" class="btn btn-primary">Generate Brackets →</a>
        </div>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    
    <div class="event-info-box">
        <h2><?= htmlspecialchars($event['name']) ?></h2>
        <p>Organize teams for team sports before generating brackets.</p>
    </div>
    
    <?php if (empty($teamSports)): ?>
        <div class="empty-state">
            <p>No team sports configured for this event.</p>
        </div>
    <?php else: ?>
        <!-- Create New Team Form -->
        <div class="form-card">
            <h3>Create New Team</h3>
            <form method="POST">
                <input type="hidden" name="action" value="create_team">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="sport_id">Sport</label>
                        <select id="sport_id" name="sport_id" required>
                            <option value="">Select Sport</option>
                            <?php foreach ($teamSports as $sport): ?>
                                <option value="<?= $sport['id'] ?>"><?= htmlspecialchars($sport['sport_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="team_name">Team Name</label>
                        <input type="text" id="team_name" name="team_name" placeholder="Enter team name" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Select Members</label>
                    <div class="members-grid">
                        <?php foreach ($registrations as $reg): ?>
                            <label class="member-checkbox">
                                <input type="checkbox" name="member_ids[]" value="<?= $reg['user_id'] ?>">
                                <span><?= htmlspecialchars($reg['player_name']) ?></span>
                                <small><?= htmlspecialchars($reg['sport_name']) ?></small>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Create Team</button>
                </div>
            </form>
        </div>
        
        <!-- Existing Teams -->
        <div class="form-card">
            <h3>Existing Teams</h3>
            <?php if (empty($existingTeams)): ?>
                <p>No teams created yet.</p>
            <?php else: ?>
                <div class="teams-list">
                    <?php foreach ($existingTeams as $team): ?>
                        <div class="team-item">
                            <div class="team-info">
                                <h4><?= htmlspecialchars($team['team_name']) ?></h4>
                                <p><?= htmlspecialchars($team['sport_name']) ?></p>
                                <span class="member-count"><?= $team['member_count'] ?> members</span>
                            </div>
                            <div class="team-actions">
                                <a href="view_team.php?team_id=<?= $team['id'] ?>" class="btn btn-sm btn-secondary">View Members</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<style>
.members-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 0.5rem;
    max-height: 200px;
    overflow-y: auto;
    border: 1px solid var(--gray-200);
    padding: 1rem;
    border-radius: var(--radius);
}

.member-checkbox {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem;
    border-radius: var(--radius);
    cursor: pointer;
    transition: background-color 0.2s;
}

.member-checkbox:hover {
    background-color: var(--gray-50);
}

.member-checkbox input {
    margin: 0;
}

.member-checkbox span {
    flex: 1;
    font-size: 0.9rem;
}

.member-checkbox small {
    display: block;
    color: var(--gray-500);
    font-size: 0.8rem;
}

.teams-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.team-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius);
}

.team-info h4 {
    margin: 0 0 0.25rem 0;
    color: var(--gray-800);
}

.team-info p {
    margin: 0 0 0.5rem 0;
    color: var(--gray-600);
    font-size: 0.9rem;
}

.member-count {
    background: var(--primary-light);
    color: var(--primary);
    padding: 0.25rem 0.5rem;
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 600;
}
</style>
