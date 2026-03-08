<?php
$pageTitle = 'Student Dashboard';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../config/db.php';
requireRole('student');

// Get available events (registration open or ongoing/completed)
$stmt = $pdo->query("SELECT e.*, 
    (SELECT COUNT(*) FROM registrations r WHERE r.event_id = e.id) as reg_count,
    (SELECT COUNT(*) FROM registrations r WHERE r.event_id = e.id AND r.user_id = " . getCurrentUserId() . ") as my_reg
    FROM events e 
    WHERE e.status IN ('registration', 'ongoing', 'completed')
    ORDER BY e.created_at DESC");
$events = $stmt->fetchAll();

// Separate events by status for display
$registrationEvents = array_filter($events, fn($e) => $e['status'] === 'registration');
$ongoingEvents = array_filter($events, fn($e) => $e['status'] === 'ongoing');
$completedEvents = array_filter($events, fn($e) => $e['status'] === 'completed');
?>

<div class="dashboard">
    <div class="page-header">
        <h1>Student Dashboard</h1>
    </div>
    
    <!-- Registration Open Events -->
    <section class="dashboard-section">
        <div class="section-header">
            <h2>Open for Registration</h2>
            <span class="section-count"><?= count($registrationEvents) ?> events</span>
        </div>
        
        <?php if (empty($registrationEvents)): ?>
            <div class="empty-state">
                <p>No events open for registration at the moment.</p>
            </div>
        <?php else: ?>
            <div class="events-grid">
                <?php foreach ($registrationEvents as $event): ?>
                    <div class="event-card">
                        <div class="event-card-header">
                            <h3><?= htmlspecialchars($event['name']) ?></h3>
                            <span class="badge badge-registration">Registration Open</span>
                        </div>
                        <p class="event-desc"><?= htmlspecialchars($event['description'] ?? 'No description') ?></p>
                        <div class="event-meta">
                            <span>Event: <?= date('M d', strtotime($event['event_start_date'])) ?> - <?= date('M d, Y', strtotime($event['event_end_date'])) ?></span>
                            <span>Reg: <?= date('M d', strtotime($event['reg_start_date'])) ?> - <?= date('M d', strtotime($event['reg_end_date'])) ?></span>
                            <span>👥 <?= $event['reg_count'] ?> participants</span>
                        </div>
                        <div class="event-actions">
                            <?php if ($event['my_reg'] > 0): ?>
                                <span class="badge badge-success">Registered</span>
                            <?php else: ?>
                                <a href="register.php?event_id=<?= $event['id'] ?>" class="btn btn-sm btn-primary">Register Now</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- Ongoing Events -->
    <section class="dashboard-section">
        <div class="section-header">
            <h2>Ongoing Events</h2>
            <span class="section-count"><?= count($ongoingEvents) ?> events</span>
        </div>
        
        <?php if (empty($ongoingEvents)): ?>
            <div class="empty-state">
                <p>No ongoing events at the moment.</p>
            </div>
        <?php else: ?>
            <div class="events-grid">
                <?php foreach ($ongoingEvents as $event): ?>
                    <div class="event-card">
                        <div class="event-card-header">
                            <h3><?= htmlspecialchars($event['name']) ?></h3>
                            <span class="badge badge-ongoing">Ongoing</span>
                        </div>
                        <p class="event-desc"><?= htmlspecialchars($event['description'] ?? 'No description') ?></p>
                        <div class="event-meta">
                            <span>Event: <?= date('M d', strtotime($event['event_start_date'])) ?> - <?= date('M d, Y', strtotime($event['event_end_date'])) ?></span>
                            <span><?= $event['reg_count'] ?> participants</span>
                        </div>
                        <div class="event-actions">
                            <a href="view_event.php?event_id=<?= $event['id'] ?>" class="btn btn-sm btn-secondary">View Details</a>
                            <a href="view_event.php?event_id=<?= $event['id'] ?>#brackets" class="btn btn-sm btn-primary">View Brackets</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- Completed Events -->
    <section class="dashboard-section">
        <div class="section-header">
            <h2>Completed Events</h2>
            <span class="section-count"><?= count($completedEvents) ?> events</span>
        </div>
        
        <?php if (empty($completedEvents)): ?>
            <div class="empty-state">
                <p>No completed events yet.</p>
            </div>
        <?php else: ?>
            <div class="events-grid">
                <?php foreach ($completedEvents as $event): ?>
                    <div class="event-card">
                        <div class="event-card-header">
                            <h3><?= htmlspecialchars($event['name']) ?></h3>
                            <span class="badge badge-completed">Completed</span>
                        </div>
                        <p class="event-desc"><?= htmlspecialchars($event['description'] ?? 'No description') ?></p>
                        <div class="event-meta">
                            <span>Event: <?= date('M d', strtotime($event['event_start_date'])) ?> - <?= date('M d, Y', strtotime($event['event_end_date'])) ?></span>
                            <span><?= $event['reg_count'] ?> participants</span>
                        </div>
                        <div class="event-actions">
                            <a href="view_event.php?event_id=<?= $event['id'] ?>" class="btn btn-sm btn-secondary">View Details</a>
                            <a href="results.php?event_id=<?= $event['id'] ?>" class="btn btn-sm btn-primary">View Results</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

