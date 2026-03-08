<?php
$pageTitle = 'Create Event';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../config/db.php';
requireRole('admin');

$sports = [
    'Basketball', 'Football', 'Badminton Single', 'Badminton Double',
    'Table Tennis Single', 'Table Tennis Double', 'Chess',
    'Carrom Single', 'Carrom Double', 'Pool/Snooker'
];

$teamSports = ['Basketball', 'Football', 'Badminton Double', 'Table Tennis Double', 'Carrom Double'];

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $regStart = $_POST['reg_start_date'] ?? '';
    $regEnd = $_POST['reg_end_date'] ?? '';
    $eventStart = $_POST['event_start_date'] ?? '';
    $eventEnd = $_POST['event_end_date'] ?? '';
    $eventTimeStart = $_POST['event_start_time'] ?? '';
    $eventTimeEnd = $_POST['event_end_time'] ?? '';
    $selectedSports = $_POST['sports'] ?? [];
    
    if (empty($name) || empty($regStart) || empty($regEnd) || empty($eventStart) || empty($eventEnd) || empty($eventTimeStart) || empty($eventTimeEnd)) {
        $error = 'Please fill in all required fields.';
    } elseif (empty($selectedSports)) {
        $error = 'Please select at least one sport.';
    } else {
        // Basic time window validation
        $startDateTime = strtotime($eventStart . ' ' . $eventTimeStart);
        $endDateTime = strtotime($eventEnd . ' ' . $eventTimeEnd);
        if ($startDateTime === false || $endDateTime === false || $endDateTime <= $startDateTime) {
            $error = 'Event end date/time must be after the start date/time.';
        }
    }
    
    if (!$error) {
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("INSERT INTO events (name, description, reg_start_date, reg_end_date, event_start_date, event_end_date, event_start_time, event_end_time, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)");
            $stmt->execute([$name, $description, $regStart, $regEnd, $eventStart, $eventEnd, $eventTimeStart, $eventTimeEnd, getCurrentUserId()]);
            $eventId = $pdo->lastInsertId();
            
            foreach ($selectedSports as $sport) {
                $isTeam = in_array($sport, $teamSports) ? 1 : 0;
                $stmt = $pdo->prepare("INSERT INTO event_sports (event_id, sport_name, is_team_sport) VALUES (?, ?, ?)");
                $stmt->execute([$eventId, $sport, $isTeam]);
            }
            
            $pdo->commit();
            header("Location: dashboard.php");
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error creating event: ' . $e->getMessage();
        }
    }
}
?>

<div class="dashboard">
    <div class="page-header">
        <h1>Create New Sports Event</h1>
        <a href="dashboard.php" class="btn btn-secondary">← Back</a>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <form method="POST" class="form-card">
        <div class="form-section">
            <h2>Event Details</h2>
            <div class="form-group">
                <label for="name">Event Name *</label>
                <input type="text" id="name" name="name" required placeholder="e.g., College annual sports week" 
                       value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="3" placeholder="Describe the event..."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
            </div>
        </div>
        
        <div class="form-section">
            <h2>Dates & Time</h2>
            <div class="form-row">
                <div class="form-group">
                    <label for="reg_start_date">Registration Start Date *</label>
                    <input type="date" id="reg_start_date" name="reg_start_date" required value="<?= $_POST['reg_start_date'] ?? '' ?>">
                </div>
                <div class="form-group">
                    <label for="reg_end_date">Registration End Date *</label>
                    <input type="date" id="reg_end_date" name="reg_end_date" required value="<?= $_POST['reg_end_date'] ?? '' ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="event_start_date">Event Start Date *</label>
                    <input type="date" id="event_start_date" name="event_start_date" required value="<?= $_POST['event_start_date'] ?? '' ?>">
                </div>
                <div class="form-group">
                    <label for="event_end_date">Event End Date *</label>
                    <input type="date" id="event_end_date" name="event_end_date" required value="<?= $_POST['event_end_date'] ?? '' ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="event_start_time">Event Start Time *</label>
                    <input type="time" id="event_start_time" name="event_start_time" required value="<?= $_POST['event_start_time'] ?? '09:00' ?>">
                </div>
                <div class="form-group">
                    <label for="event_end_time">Event End Time *</label>
                    <input type="time" id="event_end_time" name="event_end_time" required value="<?= $_POST['event_end_time'] ?? '17:00' ?>">
                </div>
            </div>
        </div>
        
        <div class="form-section">
            <h2>Select Sports</h2>
            <p class="form-help">Choose the sports to include in this event.</p>
            <div class="sports-grid">
                <?php foreach ($sports as $sport): ?>
                    <label class="sport-checkbox">
                        <input type="checkbox" name="sports[]" value="<?= $sport ?>"
                               <?= in_array($sport, $_POST['sports'] ?? []) ? 'checked' : '' ?>>
                        <span class="sport-label">
                            <span><?= $sport ?></span>
                            <?php if (in_array($sport, $teamSports)): ?>
                                <span class="team-badge">Team</span>
                            <?php endif; ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Create Event →</button>
        </div>
    </form>
</div>

