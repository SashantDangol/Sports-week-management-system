<?php
session_start();
require_once 'config/db.php';

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: ' . $_SESSION['role'] . '/dashboard.php');
    exit();
}

// Get events for display
$stmt = $pdo->query("SELECT e.*, COUNT(DISTINCT r.id) as reg_count 
                     FROM events e 
                     LEFT JOIN registrations r ON e.id = r.event_id 
                     WHERE e.status IN ('registration', 'ongoing', 'completed') 
                     GROUP BY e.id 
                     ORDER BY e.created_at DESC 
                     LIMIT 6");
$events = $stmt->fetchAll();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['username'] = $user['username'];
            
            header('Location: ' . $user['role'] . '/dashboard.php');
            exit();
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sports Event Management - Home</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="nav-brand">
            <a href="index.php">Sports Event Manager</a>
        </div>
        <div class="nav-links">
            <a href="#home" class="nav-link">Home</a>
            <a href="#about" class="nav-link">About Us</a>
            <a href="#tournaments" class="nav-link">Tournaments</a>
            <button class="btn btn-primary" onclick="openLoginModal()" style="margin-left: 1rem;">Login</button>
        </div>
    </nav>

    <!-- Hero Section -->
    <section id="home" class="hero">
        <div class="hero-overlay"></div>
        <div class="hero-content">
            <h1>Sports Event Management System</h1>
            <div class="hero-buttons">
                <button class="btn btn-primary btn-large" onclick="openLoginModal()">Get Started</button>
                <a href="#tournaments" class="btn btn-secondary btn-large">View Tournaments</a>
            </div>
        </div>
    </section>

    <!-- About Us Section -->
    <section id="about" class="about">
        <div class="container">
            <h2>About Us</h2>
            <div class="about-content">
                <div class="about-text">
                    <h3  align="center">Sports Event Management</h3>
                    <p>Manage sports events process including registrations, configuring sports, brackets and match results.</p>
                    <div class="features">
                        <div class="feature">
                            <h4>Multiple Sports</h4>
                            <p>Support for basketball, football, badminton, table tennis, chess, and more</p>
                        </div>
                        <div class="feature">
                            <h4>Smart Brackets</h4>
                            <p>Automated tournament bracket generation with multiple formats</p>
                        </div>
                        <div class="feature">
                            <h4>Team Management</h4>
                            <p>Easy team formation and member management</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Tournaments Section -->
    <section id="tournaments" class="tournaments">
        <div class="container">
            <h2>Sports Events</h2>
            <?php if (empty($events)): ?>
                <div class="empty-state">
                    <p>No tournaments available at the moment. Check back later!</p>
                </div>
            <?php else: ?>
                <div class="tournaments-grid">
                    <?php foreach ($events as $event): ?>
                        <div class="tournament-card">
                            <div class="tournament-header">
                                <h3><?= htmlspecialchars($event['name']) ?></h3>
                                <span class="badge badge-<?= $event['status'] ?>"><?= ucfirst($event['status']) ?></span>
                            </div>
                            <p class="tournament-desc"><?= htmlspecialchars($event['description'] ?? 'No description available') ?></p>
                            <div class="tournament-meta">
                                <span><?= date('M d, Y', strtotime($event['event_start_date'])) ?> - <?= date('M d, Y', strtotime($event['event_end_date'])) ?></span>
                                <span><?= $event['reg_count'] ?> participants</span>
                            </div>
                            <div class="tournament-actions">
                                <button class="btn btn-primary" onclick="promptLogin()">View Details</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="view-all">
                <button class="btn btn-secondary" onclick="promptLogin()">View All Tournaments</button>
            </div>
        </div>
    </section>

    <!-- Login Modal -->
    <div id="loginModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Login to Your Account</h2>
                <span class="close" onclick="closeLoginModal()">&times;</span>
            </div>
            <form method="POST" class="login-form-modal">
                <?php if ($error): ?>
                    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" placeholder="Enter your username" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                </div>
                <div style="margin-top: 1rem;"></div>
                <button type="submit" name="login" class="btn btn-primary btn-full">Login</button>
            </form>
            <div class="demo-credentials">
                <p><strong>Demo:</strong> admin / admin123 | STU1 / student1</p>
            </div>
        </div>
    </div>
    <script>
        function openLoginModal() {
            document.getElementById('loginModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeLoginModal() {
            document.getElementById('loginModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function promptLogin() {
            openLoginModal();
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('loginModal');
            if (event.target == modal) {
                closeLoginModal();
            }
        }

        // Smooth scrolling for navigation links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    </script>
</body>
</html>
