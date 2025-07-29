
<?php
session_start();
require_once '../config/database.php';
if (!isset($_SESSION['user_id']) || !$_SESSION['is_admin']) {
    header('Location: ../index.php');
    exit();
}
// Statistiken
$stmt = $pdo->query("SELECT COUNT(*) FROM users");
$total_users = $stmt->fetchColumn();
$stmt = $pdo->query("SELECT COUNT(DISTINCT card_name) FROM collections");
$unique_cards = $stmt->fetchColumn();
$stmt = $pdo->query("SELECT COUNT(*) FROM decks");
$total_decks = $stmt->fetchColumn();
$stmt = $pdo->query("SELECT SUM(quantity) FROM collections");
$total_cards = $stmt->fetchColumn() ?: 0;
// Letzte Aktivitäten (Dummy-Daten, später dynamisch)
$recent_activity = [];
?>
<?php include '../includes/navbar.php'; ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin-Dashboard - MTG Collection Manager</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
<div class="container">
    <div class="main-content">
        <div class="page-header">
            <h1 class="page-title">Admin-Dashboard</h1>
            <p class="page-subtitle">Alle wichtigen Verwaltungsfunktionen auf einen Blick</p>
        </div>
        <div class="card-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 28px; margin-bottom: 32px;">
            <!-- Nutzerverwaltung -->
            <div class="card" style="box-shadow: 0 4px 16px rgba(59,130,246,0.08); border-radius: 14px;">
                <div class="card-body">
                    <h3 style="color: var(--primary-color); font-weight: 600;">👤 Nutzerverwaltung</h3>
                    <p style="color: #374151;">Alle Nutzer anzeigen, bearbeiten, sperren, löschen und deren Decks/Karten verwalten.</p>
                    <a href="user_management.php" class="btn btn-primary">Nutzer verwalten</a>
                </div>
            </div>
            <!-- Deckverwaltung -->
            <div class="card" style="box-shadow: 0 4px 16px rgba(34,197,94,0.08); border-radius: 14px;">
                <div class="card-body">
                    <h3 style="color: #22c55e; font-weight: 600;">🗂️ Deckverwaltung</h3>
                    <p style="color: #374151;">Alle Decks anzeigen, bearbeiten, löschen und Nutzern zuordnen.</p>
                    <a href="deck_management.php" class="btn btn-primary">Decks verwalten</a>
                </div>
            </div>
            <!-- Kartenverwaltung -->
            <div class="card" style="box-shadow: 0 4px 16px rgba(245,158,11,0.08); border-radius: 14px;">
                <div class="card-body">
                    <h3 style="color: #f59e0b; font-weight: 600;">🃏 Kartenverwaltung</h3>
                    <p style="color: #374151;">Karten importieren, Duplikate finden, Karten löschen.</p>
                    <a href="../bulk_import.php" class="btn btn-secondary">Bulk Import</a>
                    <a href="card_management.php" class="btn btn-primary" style="margin-left:8px;">Karten verwalten</a>
                </div>
            </div>
            <!-- Backup & Restore -->
            <div class="card" style="box-shadow: 0 4px 16px rgba(59,130,246,0.08); border-radius: 14px;">
                <div class="card-body">
                    <h3 style="color: #6366f1; font-weight: 600;">💾 Backup & Restore</h3>
                    <p style="color: #374151;">Datenbank-Backup erstellen, herunterladen und wiederherstellen.</p>
                    <a href="backup.php" class="btn btn-primary">Backup erstellen</a>
                    <a href="backup_history.php" class="btn btn-secondary" style="margin-left:8px;">Backup-Historie</a>
                </div>
            </div>
            <!-- Debug-Zone -->
            <div class="card" style="box-shadow: 0 4px 16px rgba(239,68,68,0.08); border-radius: 14px;">
                <div class="card-body">
                    <h3 style="color: #ef4444; font-weight: 600;">🛠️ Debug-Zone</h3>
                    <p style="color: #374151;">Logs, Testfunktionen, Systeminfos und Fehleranalyse.</p>
                    <a href="debug.php" class="btn btn-primary">Debug-Bereich</a>
                </div>
            </div>
            <!-- Einstellungen -->
            <div class="card" style="box-shadow: 0 4px 16px rgba(16,185,129,0.08); border-radius: 14px;">
                <div class="card-body">
                    <h3 style="color: #10b981; font-weight: 600;">⚙️ Einstellungen</h3>
                    <p style="color: #374151;">Globale Einstellungen, Passwortregeln, Admin-Passwort ändern.</p>
                    <a href="settings.php" class="btn btn-primary">Einstellungen</a>
                </div>
            </div>
        </div>
        <div class="card" style="margin-top:32px; box-shadow: 0 4px 16px rgba(59,130,246,0.08); border-radius: 14px;">
            <div class="card-body">
                <h4 style="color: var(--primary-color); font-weight: 600;">Statistiken</h4>
                <ul style="list-style:none; padding:0; color: #374151;">
                    <li><strong>Nutzer:</strong> <?php echo $total_users; ?></li>
                    <li><strong>Decks:</strong> <?php echo $total_decks; ?></li>
                    <li><strong>Karten:</strong> <?php echo $total_cards; ?></li>
                    <li><strong>Einzigartige Karten:</strong> <?php echo $unique_cards; ?></li>
                </ul>
            </div>
        </div>
        <div class="card mb-3" style="margin-top:24px; box-shadow: 0 4px 16px rgba(59,130,246,0.08); border-radius: 14px;">
            <div class="card-body">
                <h4 style="margin-bottom: 16px; color: var(--primary-color); font-weight: 600;">Letzte Aktivitäten</h4>
                <ul style="list-style: none; padding: 0; margin: 0; color: #374151;">
                    <?php if (empty($recent_activity)): ?>
                        <li>Keine Aktivitäten vorhanden.</li>
                    <?php else: ?>
                        <?php foreach ($recent_activity as $activity): ?>
                            <li style="margin-bottom: 10px; border-left: 4px solid #007bff; padding-left: 15px;">
                                <span style="font-weight: bold; color: #374151;"><?php echo htmlspecialchars($activity['username']); ?></span>
                                <span style="color: #6b7280;">hat</span>
                                <span style="color: #007bff; font-weight: 500;"><?php echo htmlspecialchars($activity['item_name']); ?></span>
                                <span style="color: #6b7280;">(<?php echo $activity['activity_type'] === 'card_added' ? 'Karte hinzugefügt' : 'Deck erstellt'; ?>)</span>
                                <span style="color: #9ca3af; float: right; font-size: 0.9em;">am <?php echo date('d.m.Y H:i', strtotime($activity['activity_date'])); ?></span>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</div>
<?php include '../includes/footer.php'; ?>
</body>
</html>
                <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-wrap: wrap; gap: 18px;">
                    <li><a href="migrate_existing_cards.php" class="btn btn-secondary">Migration: Karten aktualisieren</a></li>
                    <li><a href="../bulk_import.php" class="btn btn-secondary">Bulk Import</a></li>
                    <li><a href="../check_import_tables.php" class="btn btn-secondary">Import Tabellen prüfen</a></li>
                    <li><a href="../check_migration.php" class="btn btn-secondary">Migration prüfen</a></li>
                    <li><a href="../reset.php" class="btn btn-danger">Datenbank zurücksetzen</a></li>
                </ul>
            </div>
        </div>
        <div class="card" style="margin-bottom: 24px; box-shadow: 0 4px 6px rgba(0,0,0,0.08); border-radius: 12px;">
            <div class="card-body">
                <h4 style="margin-bottom: 16px; color: var(--primary-color);">Letzte Aktivitäten</h4>
                <ul style="list-style: none; padding: 0; margin: 0;">
                    <?php foreach ($recent_activity as $activity): ?>
                        <li style="margin-bottom: 10px; border-left: 4px solid #007bff; padding-left: 15px;">
                            <span style="font-weight: bold; color: #374151;"><?php echo htmlspecialchars($activity['username']); ?></span>
                            <span style="color: #6b7280;">hat</span>
                            <span style="color: #007bff; font-weight: 500;"><?php echo htmlspecialchars($activity['item_name']); ?></span>
                            <span style="color: #6b7280;">(<?php echo $activity['activity_type'] === 'card_added' ? 'Karte hinzugefügt' : 'Deck erstellt'; ?>)</span>
                            <span style="color: #9ca3af; float: right; font-size: 0.9em;">am <?php echo date('d.m.Y H:i', strtotime($activity['activity_date'])); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

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
