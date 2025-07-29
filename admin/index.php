<?php
session_start();
require_once '../config/database.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || !$_SESSION['is_admin']) {
    header('Location: ../index.php');
    exit();
}

// Handle admin actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'toggle_admin') {
        $user_id = intval($_POST['user_id']);
        $stmt = $pdo->prepare("UPDATE users SET is_admin = NOT is_admin WHERE id = ?");
        $stmt->execute([$user_id]);
        $message = "Admin-Status wurde geändert.";
    } elseif ($_POST['action'] === 'delete_user') {
        $user_id = intval($_POST['user_id']);
        if ($user_id !== $_SESSION['user_id']) { // Can't delete self
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $message = "Benutzer wurde gelöscht.";
        } else {
            $error = "Sie können sich nicht selbst löschen.";
        }
    } elseif ($_POST['action'] === 'cleanup_database') {
        // Remove orphaned records
        $pdo->exec("DELETE FROM deck_cards WHERE deck_id NOT IN (SELECT id FROM decks)");
        $pdo->exec("DELETE FROM user_settings WHERE user_id NOT IN (SELECT id FROM users)");
        $message = "Datenbank wurde bereinigt.";
    }
}

// Get system statistics
$stmt = $pdo->query("SELECT COUNT(*) FROM users");
$total_users = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(DISTINCT card_name) FROM collections");
$unique_cards = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM decks");
$total_decks = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT SUM(quantity) FROM collections");
$total_cards = $stmt->fetchColumn() ?: 0;

// Get all users
$stmt = $pdo->query("
    SELECT u.*, 
           COUNT(DISTINCT c.id) as card_count,
           COUNT(DISTINCT d.id) as deck_count,
           SUM(c.quantity) as total_quantity
    FROM users u
    LEFT JOIN collections c ON u.id = c.user_id
    LEFT JOIN decks d ON u.id = d.user_id
    GROUP BY u.id
    ORDER BY u.created_at DESC
");
$users = $stmt->fetchAll();

// Get recent activity
$stmt = $pdo->query("
    SELECT 'card_added' as activity_type, u.username, c.card_name as item_name, c.added_at as activity_date
    FROM collections c
    JOIN users u ON c.user_id = u.id
    UNION ALL
    SELECT 'deck_created' as activity_type, u.username, d.name as item_name, d.created_at as activity_date
    FROM decks d
    JOIN users u ON d.user_id = u.id
    ORDER BY activity_date DESC
    LIMIT 10
");
$recent_activity = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard - MTG Collection Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .stat-card .stat-value {
            font-size: 2.5rem;
            font-weight: bold;
        }
        .activity-item {
            border-left: 4px solid #007bff;
            padding-left: 15px;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <h1><i class="fas fa-user-shield"></i> Admin Dashboard</h1>
                
                <?php if (isset($message)): ?>
                    <div class="alert alert-success"><?= $message ?></div>
                <?php endif; ?>
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?= $error ?></div>
                <?php endif; ?>
                
                <!-- Statistics Cards -->
                <div class="row">
                    <div class="col-md-3">
                        <div class="card stat-card">
                            <div class="card-body text-center">
                                <i class="fas fa-users fa-2x mb-3"></i>
                                <div class="stat-value"><?= $total_users ?></div>
                                <div>Total Users</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card">
                            <div class="card-body text-center">
                                <i class="fas fa-layer-group fa-2x mb-3"></i>
                                <div class="stat-value"><?= $total_decks ?></div>
                                <div>Total Decks</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card">
                            <div class="card-body text-center">
                                <i class="fas fa-magic fa-2x mb-3"></i>
                                <div class="stat-value"><?= $unique_cards ?></div>
                                <div>Unique Cards</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card">
                            <div class="card-body text-center">
                                <i class="fas fa-database fa-2x mb-3"></i>
                                <div class="stat-value"><?= number_format($total_cards) ?></div>
                                <div>Total Cards</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity and Users -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="fas fa-clock"></i> Recent Activity</h5>
                            </div>
                            <div class="card-body">
                                <?php foreach ($recent_activity as $activity): ?>
                                    <div class="activity-item">
                                        <strong><?= htmlspecialchars($activity['username']) ?></strong>
                                        <?php if ($activity['activity_type'] === 'card_added'): ?>
                                            added <em><?= htmlspecialchars($activity['item_name']) ?></em> to collection
                                        <?php else: ?>
                                            created deck <em><?= htmlspecialchars($activity['item_name']) ?></em>
                                        <?php endif; ?>
                                        <small class="text-muted d-block"><?= date('M j, Y g:i A', strtotime($activity['activity_date'])) ?></small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="fas fa-users"></i> User Overview</h5>
                            </div>
                            <div class="card-body">
                                <?php foreach ($users as $user): ?>
                                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                        <div>
                                            <strong><?= htmlspecialchars($user['username']) ?></strong>
                                            <?php if ($user['is_admin']): ?>
                                                <span class="badge bg-warning">Admin</span>
                                            <?php endif; ?>
                                            <br><small class="text-muted"><?= htmlspecialchars($user['email']) ?></small>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge bg-primary"><?= $user['card_count'] ?> cards</span>
                                            <span class="badge bg-success"><?= $user['deck_count'] ?> decks</span>
                                            <div class="btn-group btn-group-sm mt-1">
                                                <form method="post" style="display: inline;">
                                                    <input type="hidden" name="action" value="toggle_admin">
                                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                    <button type="submit" class="btn btn-outline-warning btn-sm" title="Toggle Admin">
                                                        <i class="fas fa-user-shield"></i>
                                                    </button>
                                                </form>
                                                <?php if ($user['id'] !== $_SESSION['user_id']): ?>
                                                <form method="post" style="display: inline;" onsubmit="return confirm('Benutzer wirklich löschen?')">
                                                    <input type="hidden" name="action" value="delete_user">
                                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete User">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Admin Tools -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="fas fa-tools"></i> Database Tools</h5>
                            </div>
                            <div class="card-body">
                                <a href="../sync_quantities.php" class="btn btn-warning me-2 mb-2">
                                    <i class="fas fa-sync"></i> Sync Quantities
                                </a>
                                <a href="../merge_duplicates.php" class="btn btn-info me-2 mb-2">
                                    <i class="fas fa-clone"></i> Merge Duplicates
                                </a>
                                <a href="../remove_test_cards.php" class="btn btn-secondary me-2 mb-2">
                                    <i class="fas fa-trash"></i> Remove Test Cards
                                </a>
                                <a href="../bulk_import.php" class="btn btn-primary me-2 mb-2">
                                    <i class="fas fa-upload"></i> Bulk Import
                                </a>
                                <form method="post" style="display: inline;" onsubmit="return confirm('Datenbank wirklich bereinigen?')">
                                    <input type="hidden" name="action" value="cleanup_database">
                                    <button type="submit" class="btn btn-danger me-2 mb-2">
                                        <i class="fas fa-broom"></i> Cleanup Database
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="fas fa-info-circle"></i> System Info</h5>
                            </div>
                            <div class="card-body">
                                <p><strong>PHP Version:</strong> <?= phpversion() ?></p>
                                <p><strong>MySQL Version:</strong> <?= $pdo->query('SELECT VERSION()')->fetchColumn() ?></p>
                                <p><strong>Server:</strong> <?= $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown' ?></p>
                                <p><strong>Users:</strong> <?= count($users) ?></p>
                                <p><strong>Document Root:</strong> <?= $_SERVER['DOCUMENT_ROOT'] ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
