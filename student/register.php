<?php
$pageTitle = 'Register for Event';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../config/db.php';
requireRole('student');

$eventId = intval($_GET['event_id'] ?? 0);
$today = date('Y-m-d');
$event = $pdo->prepare("SELECT * FROM events WHERE id = ? AND status = 'registration' AND reg_end_date >= ?");
$event->execute([$eventId, $today]);
$event = $event->fetch();
if (!$event) { header('Location: dashboard.php'); exit(); }

// Check already registered
$check = $pdo->prepare("SELECT id FROM registrations WHERE event_id = ? AND user_id = ?");
$check->execute([$eventId, getCurrentUserId()]);
if ($check->fetch()) {
    header('Location: dashboard.php');
    exit();
}

// Get sports
$stmt = $pdo->prepare("SELECT * FROM event_sports WHERE event_id = ?");
$stmt->execute([$eventId]);
$sports = $stmt->fetchAll();

// Get user info
$user = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$user->execute([getCurrentUserId()]);
$user = $user->fetch();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $playerName = trim($_POST['player_name'] ?? '');
    $regId = trim($_POST['reg_id'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $sport1 = intval($_POST['sport1'] ?? 0);
    $sport2 = intval($_POST['sport2'] ?? 0);
    
    if (empty($playerName) || empty($regId) || empty($phone) || empty($email) || empty($gender)) {
        $error = 'Please fill in all fields.';
    } elseif (!in_array($gender, ['male','female','other'], true)) {
        $error = 'Please select a valid gender.';
    } elseif (!$sport1) {
        $error = 'Please choose at least 1 sport.';
    } elseif ($sport2 && $sport1 === $sport2) {
        $error = 'If you choose 2 sports, they must be different.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
        $error = 'Please enter a valid 10-digit phone number.';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO registrations (event_id, user_id, player_name, reg_id, phone, email, gender, sport1_id, sport2_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$eventId, getCurrentUserId(), $playerName, $regId, $phone, $email, $gender, $sport1, $sport2 ?: null]);
            
            header("Location: dashboard.php?registered=1");
            exit();
        } catch (Exception $e) {
            $error = 'Registration failed. You may already be registered.';
        }
    }
}
?>

<div class="dashboard">
    <div class="page-header">
        <h1>Register - <?= htmlspecialchars($event['name']) ?></h1>
        <a href="dashboard.php" class="btn btn-secondary">← Back</a>
    </div>
    
    <div class="event-info-box">
        <p><?= htmlspecialchars($event['description'] ?? '') ?></p>
        <p>Event: <?= date('M d, Y', strtotime($event['event_start_date'])) ?> - <?= date('M d, Y', strtotime($event['event_end_date'])) ?></p>
        <p>Registration closes: <?= date('M d, Y', strtotime($event['reg_end_date'])) ?></p>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <form method="POST" class="form-card">
        <div class="form-section">
            <h2>Your Information</h2>
            <div class="form-row">
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="player_name" required value="<?= htmlspecialchars($_POST['player_name'] ?? $user['full_name']) ?>">
                </div>
                <div class="form-group">
                    <label>Registration ID *</label>
                    <input type="text" name="reg_id" required value="<?= htmlspecialchars($_POST['reg_id'] ?? $user['registration_id']) ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Phone Number *</label>
                    <input type="tel" name="phone" required placeholder="98XXXXXXXX" value="<?= htmlspecialchars($_POST['phone'] ?? $user['phone']) ?>">
                </div>
                <div class="form-group">
                    <label>Email *</label>
                    <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? $user['email']) ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Gender *</label>
                    <select name="gender" required>
                        <option value="">Select Gender</option>
                        <option value="male" <?= (($_POST['gender'] ?? '') === 'male') ? 'selected' : '' ?>>Male</option>
                        <option value="female" <?= (($_POST['gender'] ?? '') === 'female') ? 'selected' : '' ?>>Female</option>
                        <option value="other" <?= (($_POST['gender'] ?? '') === 'other') ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>
            </div>
        </div>
        
        <div class="form-section">
            <h2>Choose up to 2 Sports</h2>
            <p class="form-help">You must select at least 1 sport. Choosing a 2nd different sport is optional.</p>
            <div class="form-row">
                <div class="form-group">
                    <label>Sport 1 *</label>
                    <select name="sport1" required>
                        <option value="">Select Sport</option>
                        <?php foreach ($sports as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= (intval($_POST['sport1'] ?? 0) === $s['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['sport_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Sport 2 (optional)</label>
                    <select name="sport2">
                        <option value="">None / No Second Sport</option>
                        <?php foreach ($sports as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= (intval($_POST['sport2'] ?? 0) === $s['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['sport_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Register for Event</button>
        </div>
    </form>
</div>

