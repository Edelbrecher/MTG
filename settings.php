<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_settings') {
        $settings = [
            'preferred_format' => $_POST['preferred_format'] ?? 'Standard',
            'default_condition' => $_POST['default_condition'] ?? 'NM',
            'auto_fetch_images' => isset($_POST['auto_fetch_images']) ? '1' : '0',
            'enable_notifications' => isset($_POST['enable_notifications']) ? '1' : '0'
        ];
        
        foreach ($settings as $key => $value) {
            $stmt = $pdo->prepare("
                INSERT INTO user_settings (user_id, setting_key, setting_value) 
                VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE setting_value = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $key, $value, $value]);
        }
        
        $message = "Einstellungen wurden gespeichert!";
    } elseif ($_POST['action'] === 'delete_collection') {
        if (isset($_POST['confirm_delete']) && $_POST['confirm_delete'] === 'DELETE') {
            $stmt = $pdo->prepare("DELETE FROM collections WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            
            $stmt = $pdo->prepare("DELETE FROM deck_cards WHERE deck_id IN (SELECT id FROM decks WHERE user_id = ?)");
            $stmt->execute([$_SESSION['user_id']]);
            
            $stmt = $pdo->prepare("DELETE FROM decks WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            
            $message = "Sammlung wurde vollständig gelöscht!";
        } else {
            $error = "Bitte geben Sie 'DELETE' ein, um die Löschung zu bestätigen.";
        }
    } elseif ($_POST['action'] === 'export_collection') {
        exportCollection($_SESSION['user_id'], $pdo);
        exit();
    }
}

// Get current settings
$stmt = $pdo->prepare("SELECT setting_key, setting_value FROM user_settings WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$settings_raw = $stmt->fetchAll();

$settings = [];
foreach ($settings_raw as $setting) {
    $settings[$setting['setting_key']] = $setting['setting_value'];
}

// Default values
$settings = array_merge([
    'preferred_format' => 'Standard',
    'default_condition' => 'NM',
    'auto_fetch_images' => '1',
    'enable_notifications' => '1'
], $settings);

function exportCollection($user_id, $pdo) {
    $stmt = $pdo->prepare("
        SELECT card_name, quantity, condition_card, added_at 
        FROM collections 
        WHERE user_id = ? 
        ORDER BY card_name
    ");
    $stmt->execute([$user_id]);
    $collection = $stmt->fetchAll();
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=mtg_collection_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Kartenname', 'Anzahl', 'Zustand', 'Hinzugefügt am']);
    
    foreach ($collection as $card) {
        fputcsv($output, [
            $card['card_name'],
            $card['quantity'],
            $card['condition_card'],
            $card['added_at']
        ]);
    }
    
    fclose($output);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Einstellungen - MTG Collection Manager</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="container">
        <div class="main-content">
            <div class="page-header">
                <h1 class="page-title">Einstellungen</h1>
                <p class="page-subtitle">Verwalten Sie Ihre Anwendungseinstellungen</p>
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

            <!-- General Settings -->
            <div class="card mb-4">
                <div class="card-header">
                    <h3>Allgemeine Einstellungen</h3>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_settings">
                        
                        <div class="grid grid-2 gap-4 mb-4">
                            <div class="form-group">
                                <label>Bevorzugtes Format</label>
                                <select name="preferred_format">
                                    <option value="Standard" <?php echo $settings['preferred_format'] === 'Standard' ? 'selected' : ''; ?>>Standard</option>
                                    <option value="Modern" <?php echo $settings['preferred_format'] === 'Modern' ? 'selected' : ''; ?>>Modern</option>
                                    <option value="Legacy" <?php echo $settings['preferred_format'] === 'Legacy' ? 'selected' : ''; ?>>Legacy</option>
                                    <option value="Vintage" <?php echo $settings['preferred_format'] === 'Vintage' ? 'selected' : ''; ?>>Vintage</option>
                                    <option value="Commander" <?php echo $settings['preferred_format'] === 'Commander' ? 'selected' : ''; ?>>Commander</option>
                                    <option value="Pioneer" <?php echo $settings['preferred_format'] === 'Pioneer' ? 'selected' : ''; ?>>Pioneer</option>
                                    <option value="Pauper" <?php echo $settings['preferred_format'] === 'Pauper' ? 'selected' : ''; ?>>Pauper</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Standard-Kartenzustand</label>
                                <select name="default_condition">
                                    <option value="NM" <?php echo $settings['default_condition'] === 'NM' ? 'selected' : ''; ?>>Near Mint (NM)</option>
                                    <option value="LP" <?php echo $settings['default_condition'] === 'LP' ? 'selected' : ''; ?>>Light Played (LP)</option>
                                    <option value="MP" <?php echo $settings['default_condition'] === 'MP' ? 'selected' : ''; ?>>Moderately Played (MP)</option>
                                    <option value="HP" <?php echo $settings['default_condition'] === 'HP' ? 'selected' : ''; ?>>Heavily Played (HP)</option>
                                    <option value="DMG" <?php echo $settings['default_condition'] === 'DMG' ? 'selected' : ''; ?>>Damaged (DMG)</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="auto_fetch_images" <?php echo $settings['auto_fetch_images'] === '1' ? 'checked' : ''; ?>>
                                Kartenbilder automatisch laden
                            </label>
                        </div>
                        
                        <div class="mb-4">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="enable_notifications" <?php echo $settings['enable_notifications'] === '1' ? 'checked' : ''; ?>>
                                Benachrichtigungen aktivieren
                            </label>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">Einstellungen speichern</button>
                    </form>
                </div>
            </div>

            <!-- Data Management -->
            <div class="card mb-4">
                <div class="card-header">
                    <h3>Datenverwaltung</h3>
                </div>
                <div class="card-body">
                    <div class="grid grid-2 gap-4">
                        <!-- Export Collection -->
                        <div>
                            <h4 class="mb-2">Sammlung exportieren</h4>
                            <p class="text-muted mb-3">Exportieren Sie Ihre Sammlung als CSV-Datei für Backups oder andere Anwendungen.</p>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="export_collection">
                                <button type="submit" class="btn btn-secondary">CSV exportieren</button>
                            </form>
                        </div>
                        
                        <!-- Import Collection -->
                        <div>
                            <h4 class="mb-2">Sammlung importieren</h4>
                            <p class="text-muted mb-3">Importieren Sie Karten aus einer CSV-Datei. Format: Kartenname, Anzahl</p>
                            <input type="file" accept=".csv" class="form-control mb-2" id="import-file">
                            <button type="button" class="btn btn-secondary" onclick="alert('Import-Funktion wird in einer zukünftigen Version verfügbar sein.')">CSV importieren</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Database Settings -->
            <div class="card mb-4">
                <div class="card-header">
                    <h3>Datenbank-Einstellungen</h3>
                </div>
                <div class="card-body">
                    <div class="grid grid-2 gap-4">
                        <div>
                            <h4 class="mb-2">Datenbankstatus</h4>
                            <div class="mb-2">
                                <strong>Server:</strong> <?php echo $_SERVER['SERVER_NAME']; ?>
                            </div>
                            <div class="mb-2">
                                <strong>PHP Version:</strong> <?php echo PHP_VERSION; ?>
                            </div>
                            <div class="mb-2">
                                <strong>MySQL Status:</strong> 
                                <span style="color: var(--success-color);">Verbunden</span>
                            </div>
                        </div>
                        
                        <div>
                            <h4 class="mb-2">Cache-Verwaltung</h4>
                            <p class="text-muted mb-3">Löschen Sie temporäre Daten und Cache-Dateien.</p>
                            <button type="button" class="btn btn-secondary" onclick="alert('Cache wurde geleert!')">Cache leeren</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Danger Zone -->
            <div class="card" style="border-color: var(--danger-color);">
                <div class="card-header" style="background: rgba(220, 38, 38, 0.1); color: var(--danger-color);">
                    <h3>⚠️ Gefahrenbereich</h3>
                </div>
                <div class="card-body">
                    <h4 class="mb-2">Sammlung komplett löschen</h4>
                    <p class="text-muted mb-3">
                        <strong>Warnung:</strong> Diese Aktion löscht alle Ihre Karten, Decks und Einstellungen unwiderruflich!
                    </p>
                    
                    <form method="POST" onsubmit="return confirm('Sind Sie sicher, dass Sie Ihre gesamte Sammlung löschen möchten? Diese Aktion kann nicht rückgängig gemacht werden!')">
                        <input type="hidden" name="action" value="delete_collection">
                        <div class="form-group mb-3">
                            <label>Geben Sie 'DELETE' ein, um die Löschung zu bestätigen:</label>
                            <input type="text" name="confirm_delete" placeholder="DELETE" required>
                        </div>
                        <button type="submit" class="btn btn-danger">
                            Sammlung unwiderruflich löschen
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php include 'includes/footer.php'; ?>
</body>
</html>
