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

$stmt = $pdo->query("SELECT COUNT(*) FROM collections");
$total_cards = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM decks");
$total_decks = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT SUM(quantity) FROM collections");
$total_quantity = $stmt->fetchColumn() ?: 0;

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
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - MTG Collection Manager</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container">
        <div class="main-content">
            <div class="page-header">
                <h1 class="page-title">Admin Dashboard</h1>
                <p class="page-subtitle">Systemverwaltung und Benutzerübersicht</p>
            </div>

            <?php if (isset($message)): ?>
                <div class="card mb-3" style="background: var(--success-color); color: white;">
                    <div class="card-body"><?php echo htmlspecialchars($message); ?></div>
                </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="card mb-3" style="background: var(--danger-color); color: white;">
                    <div class="card-body"><?php echo htmlspecialchars($error); ?></div>
                </div>
            <?php endif; ?>

            <!-- System Statistics -->
            <div class="grid grid-4 mb-4">
                <div class="card">
                    <div class="card-body text-center">
                        <h3 style="color: var(--primary-color); font-size: 2rem; margin-bottom: 0.5rem;"><?php echo $total_users; ?></h3>
                        <p>Registrierte Benutzer</p>
                    </div>
                </div>
                <div class="card">
                    <div class="card-body text-center">
                        <h3 style="color: var(--success-color); font-size: 2rem; margin-bottom: 0.5rem;"><?php echo $total_cards; ?></h3>
                        <p>Verschiedene Karten</p>
                    </div>
                </div>
                <div class="card">
                    <div class="card-body text-center">
                        <h3 style="color: var(--warning-color); font-size: 2rem; margin-bottom: 0.5rem;"><?php echo $total_quantity; ?></h3>
                        <p>Karten insgesamt</p>
                    </div>
                </div>
                <div class="card">
                    <div class="card-body text-center">
                        <h3 style="color: var(--secondary-color); font-size: 2rem; margin-bottom: 0.5rem;"><?php echo $total_decks; ?></h3>
                        <p>Erstellte Decks</p>
                    </div>
                </div>
            </div>

            <div class="grid grid-2 gap-4">
                <!-- Users Management -->
                <div class="card">
                    <div class="card-header">
                        <h3>Benutzerverwaltung</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table style="width: 100%; border-collapse: collapse;">
                                <thead>
                                    <tr style="border-bottom: 1px solid var(--border-color);">
                                        <th style="padding: 0.5rem; text-align: left;">Benutzer</th>
                                        <th style="padding: 0.5rem; text-align: center;">Karten</th>
                                        <th style="padding: 0.5rem; text-align: center;">Decks</th>
                                        <th style="padding: 0.5rem; text-align: center;">Admin</th>
                                        <th style="padding: 0.5rem; text-align: center;">Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): ?>
                                        <tr style="border-bottom: 1px solid var(--border-color);">
                                            <td style="padding: 0.5rem;">
                                                <div>
                                                    <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                                                    <div style="font-size: 0.75rem; color: var(--text-muted);">
                                                        <?php echo htmlspecialchars($user['email']); ?>
                                                    </div>
                                                    <div style="font-size: 0.75rem; color: var(--text-muted);">
                                                        Seit: <?php echo date('d.m.Y', strtotime($user['created_at'])); ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td style="padding: 0.5rem; text-align: center;">
                                                <?php echo $user['card_count'] ?: 0; ?> 
                                                <small>(<?php echo $user['total_quantity'] ?: 0; ?>)</small>
                                            </td>
                                            <td style="padding: 0.5rem; text-align: center;">
                                                <?php echo $user['deck_count'] ?: 0; ?>
                                            </td>
                                            <td style="padding: 0.5rem; text-align: center;">
                                                <?php if ($user['is_admin']): ?>
                                                    <span style="color: var(--success-color);">✓</span>
                                                <?php else: ?>
                                                    <span style="color: var(--text-muted);">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding: 0.5rem; text-align: center;">
                                                <div style="display: flex; gap: 0.25rem; justify-content: center;">
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="action" value="toggle_admin">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                        <button type="submit" class="btn btn-secondary" 
                                                                style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">
                                                            <?php echo $user['is_admin'] ? 'Admin ↓' : 'Admin ↑'; ?>
                                                        </button>
                                                    </form>
                                                    
                                                    <?php if ($user['id'] !== $_SESSION['user_id']): ?>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="action" value="delete_user">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                            <button type="submit" class="btn btn-danger" 
                                                                    style="padding: 0.25rem 0.5rem; font-size: 0.75rem;"
                                                                    onclick="return confirm('Benutzer wirklich löschen?')">
                                                                🗑
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="card">
                    <div class="card-header">
                        <h3>Letzte Aktivitäten</h3>
                    </div>
                    <div class="card-body">
                        <?php foreach ($recent_activity as $activity): ?>
                            <div class="flex justify-between items-center mb-2 p-2" 
                                 style="border-bottom: 1px solid var(--border-color);">
                                <div>
                                    <strong><?php echo htmlspecialchars($activity['username']); ?></strong>
                                    <?php if ($activity['activity_type'] === 'card_added'): ?>
                                        hat Karte "<em><?php echo htmlspecialchars($activity['item_name']); ?></em>" hinzugefügt
                                    <?php else: ?>
                                        hat Deck "<em><?php echo htmlspecialchars($activity['item_name']); ?></em>" erstellt
                                    <?php endif; ?>
                                </div>
                                <small class="text-muted">
                                    <?php echo date('d.m. H:i', strtotime($activity['activity_date'])); ?>
                                </small>
                            </div>
                        <?php endforeach; ?>
                        
                        <?php if (empty($recent_activity)): ?>
                            <p class="text-muted">Keine Aktivitäten gefunden.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- System Tools -->
            <div class="card mt-4">
                <div class="card-header">
                    <h3>System-Tools</h3>
                </div>
                <div class="card-body">
                    <div class="grid grid-3 gap-4">
                        <div>
                            <h4 class="mb-2">Datenbank bereinigen</h4>
                            <p class="text-muted mb-3">Entfernt verwaiste Datensätze und optimiert die Datenbank.</p>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="cleanup_database">
                                <button type="submit" class="btn btn-secondary">Datenbank bereinigen</button>
                            </form>
                        </div>
                        
                        <div>
                            <h4 class="mb-2">System-Info</h4>
                            <div style="font-size: 0.875rem;">
                                <div><strong>PHP:</strong> <?php echo PHP_VERSION; ?></div>
                                <div><strong>Server:</strong> <?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'; ?></div>
                                <div><strong>MySQL:</strong> <?php echo $pdo->getAttribute(PDO::ATTR_SERVER_VERSION); ?></div>
                            </div>
                        </div>
                        
                        <div>
                            <h4 class="mb-2">Backup erstellen</h4>
                            <p class="text-muted mb-3">Erstellt ein vollständiges Backup der Datenbank.</p>
                            <button type="button" class="btn btn-secondary" 
                                    onclick="alert('Backup-Funktion wird in einer zukünftigen Version verfügbar sein.')">
                                Backup erstellen
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
