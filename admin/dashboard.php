<?php
$pageTitle = 'Admin Dashboard';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../config/db.php';
requireRole('admin');

// Get events
$stmt = $pdo->prepare("SELECT e.*, COUNT(DISTINCT r.id) as reg_count 
                        FROM events e 
                        LEFT JOIN registrations r ON e.id = r.event_id 
                        WHERE e.created_by = ? 
                        GROUP BY e.id 
                        ORDER BY e.created_at DESC");
$stmt->execute([getCurrentUserId()]);
$events = $stmt->fetchAll();

// Filter events by status
$draftEvents = array_filter($events, fn($e) => $e['status'] === 'draft');
$upcomingEvents = array_filter($events, fn($e) => $e['status'] === 'registration');
$registrationEndedEvents = array_filter($events, fn($e) => $e['status'] === 'registration_ended');
$ongoingEvents = array_filter($events, fn($e) => $e['status'] === 'ongoing');
$completedEvents = array_filter($events, fn($e) => $e['status'] === 'completed');
?>
<style>
  
</style>
<div class="admin-dashboard">
    <div class="dashboard-sidebar">
        <div class="sidebar-section">
            <h3>Create Event</h3>
            <a href="create_event.php" class="btn btn-primary btn-full">+ Create New Event</a>
        </div>
        
        <div class="sidebar-section">
            <h3>Quick Stats</h3>
            <div class="sidebar-stats">
                <div class="sidebar-stat">
                    <span class="stat-number"><?= count($events) ?></span>
                    <span class="stat-label">Total Events</span>
                </div>
                <div class="sidebar-stat">
                    <span class="stat-number"><?= count($draftEvents) ?></span>
                    <span class="stat-label">Draft</span>
                </div>
                <div class="sidebar-stat">
                    <span class="stat-number"><?= count($upcomingEvents) ?></span>
                    <span class="stat-label">Registration Open</span>
                </div>
                <div class="sidebar-stat">
                    <span class="stat-number"><?= count($registrationEndedEvents) ?></span>
                    <span class="stat-label">Registration Ended</span>
                </div>
                <div class="sidebar-stat">
                    <span class="stat-number"><?= count($ongoingEvents) ?></span>
                    <span class="stat-label">Ongoing</span>
                </div>
                <div class="sidebar-stat">
                    <span class="stat-number"><?= count($completedEvents) ?></span>
                    <span class="stat-label">Completed</span>
                </div>
            </div>
        </div>
    </div>
    
    <div class="dashboard-main">
        <!-- Draft Events Section -->
        <section class="dashboard-section">
            <div class="section-header">
                <h2>Draft Events</h2>
                <span class="section-count"><?= count($draftEvents) ?> events</span>
            </div>
            
            <?php if (empty($draftEvents)): ?>
                <div class="empty-state">
                    <p>No draft events at the moment.</p>
                </div>
            <?php else: ?>
                <div class="events-list">
                    <?php foreach ($draftEvents as $event): ?>
                        <div class="event-item">
                            <div class="event-info">
                                <h3><?= htmlspecialchars($event['name']) ?></h3>
                                <p class="event-desc"><?= htmlspecialchars(substr($event['description'] ?? '', 0, 150)) ?></p>
                                <div class="event-meta">
                                    <span><?= date('M d, Y', strtotime($event['event_start_date'])) ?> - <?= date('M d, Y', strtotime($event['event_end_date'])) ?></span>
                                    <span>Reg: <?= date('M d', strtotime($event['reg_start_date'])) ?> - <?= date('M d', strtotime($event['reg_end_date'])) ?></span>
                                </div>
                            </div>
                            <div class="event-actions">
                                <a href="start_event.php?event_id=<?= $event['id'] ?>" class="btn btn-sm btn-primary" data-confirm="Start registration for this event? Students will be able to register.">Start Registration</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- Upcoming Events Section -->
        <section class="dashboard-section">
            <div class="section-header">
                <h2>Upcoming Events</h2>
                <span class="section-count"><?= count($upcomingEvents) ?> events</span>
            </div>
            
            <?php if (empty($upcomingEvents)): ?>
                <div class="empty-state">
                    <p>No events with open registration at the moment.</p>
                </div>
            <?php else: ?>
                <div class="events-list">
                    <?php foreach ($upcomingEvents as $event): ?>
                        <div class="event-item">
                            <div class="event-info">
                                <h3><?= htmlspecialchars($event['name']) ?></h3>
                                <p class="event-desc"><?= htmlspecialchars(substr($event['description'] ?? '', 0, 150)) ?></p>
                                <div class="event-meta">
                                    <span><?= date('M d, Y', strtotime($event['event_start_date'])) ?> - <?= date('M d, Y', strtotime($event['event_end_date'])) ?></span>
                                    <span><?= $event['reg_count'] ?> registered</span>
                                    <span>Reg ends: <?= date('M d', strtotime($event['reg_end_date'])) ?></span>
                                </div>
                            </div>
                            <div class="event-actions">
                                <a href="view_registrations.php?event_id=<?= $event['id'] ?>" class="btn btn-sm btn-secondary">View Registrations</a>
                                <?php 
                                $today = date('Y-m-d');
                                if ($today < $event['reg_end_date']): ?>
                                    <a href="end_registration.php?event_id=<?= $event['id'] ?>" class="btn btn-sm btn-danger" data-confirm="End registration for this event now?">End Registration</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- Registration Ended Events Section -->
        <section class="dashboard-section">
            <div class="section-header">
                <h2>Registration Ended</h2>
                <span class="section-count"><?= count($registrationEndedEvents) ?> events</span>
            </div>
            
            <?php if (empty($registrationEndedEvents)): ?>
                <div class="empty-state">
                    <p>No events with ended registration at the moment.</p>
                </div>
            <?php else: ?>
                <div class="events-list">
                    <?php foreach ($registrationEndedEvents as $event): ?>
                        <div class="event-item">
                            <div class="event-info">
                                <h3><?= htmlspecialchars($event['name']) ?></h3>
                                <p class="event-desc"><?= htmlspecialchars(substr($event['description'] ?? '', 0, 150)) ?></p>
                                <div class="event-meta">
                                    <span><?= date('M d, Y', strtotime($event['event_start_date'])) ?> - <?= date('M d, Y', strtotime($event['event_end_date'])) ?></span>
                                    <span><?= $event['reg_count'] ?> registered</span>
                                    <span>Reg ended: <?= date('M d', strtotime($event['reg_end_date'])) ?></span>
                                </div>
                            </div>
                            <div class="event-actions">
                                <a href="view_registrations.php?event_id=<?= $event['id'] ?>" class="btn btn-sm btn-secondary">View Registrations</a>
                                <a href="configure_sports.php?event_id=<?= $event['id'] ?>" class="btn btn-sm btn-primary">Configure Sports</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- Ongoing Events Section -->
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
                <div class="events-list">
                    <?php foreach ($ongoingEvents as $event): ?>
                        <div class="event-item">
                            <div class="event-info">
                                <h3><?= htmlspecialchars($event['name']) ?></h3>
                                <p class="event-desc"><?= htmlspecialchars(substr($event['description'] ?? '', 0, 150)) ?></p>
                                <div class="event-meta">
                                    <span><?= date('M d, Y', strtotime($event['event_start_date'])) ?> - <?= date('M d, Y', strtotime($event['event_end_date'])) ?></span>
                                    <span><?= $event['reg_count'] ?> participants</span>
                                </div>
                            </div>
                            <div class="event-actions">
                                <a href="manage_events.php?event_id=<?= $event['id'] ?>" class="btn btn-sm btn-primary">Manage Event</a>
                                <a href="view_brackets.php?event_id=<?= $event['id'] ?>" class="btn btn-sm btn-secondary">View Brackets</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- Completed Events Section -->
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
                <div class="events-list">
                    <?php foreach ($completedEvents as $event): ?>
                        <div class="event-item">
                            <div class="event-info">
                                <h3><?= htmlspecialchars($event['name']) ?></h3>
                                <p class="event-desc"><?= htmlspecialchars(substr($event['description'] ?? '', 0, 150)) ?></p>
                                <div class="event-meta">
                                    <span><?= date('M d, Y', strtotime($event['event_start_date'])) ?> - <?= date('M d, Y', strtotime($event['event_end_date'])) ?></span>
                                    <span><?= $event['reg_count'] ?> participants</span>
                                </div>
                            </div>
                            <div class="event-actions">
                                <a href="results.php?event_id=<?= $event['id'] ?>" class="btn btn-sm btn-secondary">View Results</a>
                                <a href="view_brackets.php?event_id=<?= $event['id'] ?>" class="btn btn-sm btn-secondary">View Brackets</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>


