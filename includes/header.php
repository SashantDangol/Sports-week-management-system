<?php require_once __DIR__ . '/auth.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'Sports Event Management' ?></title>
    <link rel="stylesheet" href="/sports-event/assets/css/style.css">
</head>
<body>
    <nav class="navbar">
        <div class="nav-brand">
            <a href="/sports-event/<?= $_SESSION['role'] ?? '' ?>/dashboard.php">Sports Event Manager</a>
        </div>
        <?php if (isLoggedIn()): ?>
        <div class="nav-links">
            <?= htmlspecialchars(getCurrentName()) ?> (<?= ucfirst(getCurrentRole()) ?>)
            <a href="/sports-event/<?= getCurrentRole() ?>/dashboard.php" class="nav-link">Dashboard</a>
            <a href="/sports-event/logout.php" class="nav-link btn-logout">Logout</a>
        </div>
        <?php endif; ?>
    </nav>
    <main class="main-content">
