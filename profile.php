<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';

// Get current user data
$stmt = $pdo->prepare("SELECT username, email, nickname, is_admin, created_at, language_preference FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: auth/logout.php');
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_profile':
                $nickname = trim($_POST['nickname'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $language_preference = $_POST['language_preference'] ?? 'en';
                
                if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error_message = 'Ungültige E-Mail-Adresse';
                    break;
                }
                
                // Validate language preference
                if (!in_array($language_preference, ['en', 'de'])) {
                    $language_preference = 'en';
                }
                
                try {
                    // Check if email already exists (except for current user)
                    if (!empty($email) && $email !== $user['email']) {
                        $check_stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                        $check_stmt->execute([$email, $user_id]);
                        if ($check_stmt->fetch()) {
                            $error_message = 'E-Mail-Adresse bereits vergeben';
                            break;
                        }
                    }
                    
                    $update_stmt = $pdo->prepare("UPDATE users SET nickname = ?, email = ?, language_preference = ? WHERE id = ?");
                    $update_stmt->execute([$nickname, $email ?: $user['email'], $language_preference, $user_id]);
                    
                    $user['nickname'] = $nickname;
                    $user['email'] = $email ?: $user['email'];
                    $user['language_preference'] = $language_preference;
                    $_SESSION['username'] = $user['username']; // Keep username in session
                    
                    $success_message = 'Profil erfolgreich aktualisiert';
                } catch (PDOException $e) {
                    $error_message = 'Fehler beim Aktualisieren des Profils';
                }
                break;
                
            case 'change_password':
                $current_password = $_POST['current_password'] ?? '';
                $new_password = $_POST['new_password'] ?? '';
                $confirm_password = $_POST['confirm_password'] ?? '';
                
                if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                    $error_message = 'Alle Passwort-Felder sind erforderlich';
                    break;
                }
                
                if ($new_password !== $confirm_password) {
                    $error_message = 'Neue Passwörter stimmen nicht überein';
                    break;
                }
                
                if (strlen($new_password) < 6) {
                    $error_message = 'Neues Passwort muss mindestens 6 Zeichen lang sein';
                    break;
                }
                
                try {
                    // Verify current password
                    $pass_stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
                    $pass_stmt->execute([$user_id]);
                    $current_hash = $pass_stmt->fetchColumn();
                    
                    if (!password_verify($current_password, $current_hash)) {
                        $error_message = 'Aktuelles Passwort ist falsch';
                        break;
                    }
                    
                    // Update password
                    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $update_stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                    $update_stmt->execute([$new_hash, $user_id]);
                    
                    $success_message = 'Passwort erfolgreich geändert';
                } catch (PDOException $e) {
                    $error_message = 'Fehler beim Ändern des Passworts';
                }
                break;
        }
    }
}

// Get user statistics
$stats = [];
try {
    // Collection count
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT card_name) as unique_cards, SUM(quantity) as total_cards FROM collections WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $collection_stats = $stmt->fetch();
    
    // Deck count
    $stmt = $pdo->prepare("SELECT COUNT(*) as deck_count FROM decks WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $deck_stats = $stmt->fetch();
    
    $stats = [
        'unique_cards' => $collection_stats['unique_cards'] ?? 0,
        'total_cards' => $collection_stats['total_cards'] ?? 0,
        'decks' => $deck_stats['deck_count'] ?? 0
    ];
} catch (Exception $e) {
    $stats = ['unique_cards' => 0, 'total_cards' => 0, 'decks' => 0];
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mein Profil - MTG Collection Manager</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/svg+xml" href="assets/images/favicon.svg">
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .profile-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .profile-header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            text-align: center;
        }
        
        .profile-avatar {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin: 0 auto 1rem;
            border: 3px solid rgba(255, 255, 255, 0.3);
        }
        
        .profile-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin: 2rem 0;
        }
        
        .stat-card {
            background: var(--surface-color);
            padding: 1.5rem;
            border-radius: 8px;
            text-align: center;
            border: 1px solid var(--border-color);
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            display: block;
        }
        
        .stat-label {
            color: var(--text-secondary);
            font-size: 0.875rem;
            margin-top: 0.5rem;
        }
        
        .profile-section {
            background: var(--surface-color);
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
        }
        
        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        @media (max-width: 640px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }
        
        .profile-info {
            display: grid;
            gap: 1rem;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .info-item:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 500;
            color: var(--text-secondary);
        }
        
        .info-value {
            color: var(--text-primary);
        }
        
        .badge {
            background: var(--primary-color);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .badge.admin {
            background: var(--warning-color);
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="container">
        <div class="profile-container">
            <!-- Profile Header -->
            <div class="profile-header">
                <div class="profile-avatar">
                    👤
                </div>
                <h1><?php echo htmlspecialchars($user['nickname'] ?: $user['username']); ?></h1>
                <p>@<?php echo htmlspecialchars($user['username']); ?></p>
                <?php if ($user['is_admin']): ?>
                    <span class="badge admin">Administrator</span>
                <?php endif; ?>
            </div>
            
            <!-- Statistics -->
            <div class="profile-stats">
                <div class="stat-card">
                    <span class="stat-number"><?php echo number_format($stats['unique_cards']); ?></span>
                    <div class="stat-label">Verschiedene Karten</div>
                </div>
                <div class="stat-card">
                    <span class="stat-number"><?php echo number_format($stats['total_cards']); ?></span>
                    <div class="stat-label">Karten insgesamt</div>
                </div>
                <div class="stat-card">
                    <span class="stat-number"><?php echo number_format($stats['decks']); ?></span>
                    <div class="stat-label">Decks erstellt</div>
                </div>
            </div>
            
            <!-- Messages -->
            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <span class="alert-icon">✅</span>
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class="alert alert-error">
                    <span class="alert-icon">❌</span>
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>
            
            <!-- Account Information -->
            <div class="profile-section">
                <h2 class="section-title">📋 Account-Informationen</h2>
                <div class="profile-info">
                    <div class="info-item">
                        <span class="info-label">Benutzername</span>
                        <span class="info-value"><?php echo htmlspecialchars($user['username']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">E-Mail</span>
                        <span class="info-value"><?php echo htmlspecialchars($user['email']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Mitglied seit</span>
                        <span class="info-value"><?php echo date('d.m.Y', strtotime($user['created_at'])); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Account-Typ</span>
                        <span class="info-value">
                            <?php if ($user['is_admin']): ?>
                                <span class="badge admin">Administrator</span>
                            <?php else: ?>
                                Standard-Benutzer
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            </div>
            
            <!-- Profile Settings -->
            <div class="profile-section">
                <h2 class="section-title">⚙️ Profil bearbeiten</h2>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="nickname">Anzeigename (optional)</label>
                            <input type="text" id="nickname" name="nickname" 
                                   value="<?php echo htmlspecialchars($user['nickname'] ?? ''); ?>"
                                   placeholder="Ihr Anzeigename">
                            <small class="form-text">Wird anstelle des Benutzernamens angezeigt</small>
                        </div>
                        <div class="form-group">
                            <label for="email">E-Mail-Adresse</label>
                            <input type="email" id="email" name="email" 
                                   value="<?php echo htmlspecialchars($user['email']); ?>" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="language_preference">Sprache für Kartentexte</label>
                        <select id="language_preference" name="language_preference">
                            <option value="en" <?php echo ($user['language_preference'] ?? 'en') === 'en' ? 'selected' : ''; ?>>
                                🇺🇸 Englisch (Original)
                            </option>
                            <option value="de" <?php echo ($user['language_preference'] ?? 'en') === 'de' ? 'selected' : ''; ?>>
                                🇩🇪 Deutsch (Übersetzt)
                            </option>
                        </select>
                        <small class="form-text">Bestimmt, in welcher Sprache Kartentexte angezeigt werden</small>
                    </div>
                    <button type="submit" class="btn btn-primary">Profil aktualisieren</button>
                </form>
            </div>
            
            <!-- Password Change -->
            <div class="profile-section">
                <h2 class="section-title">🔒 Passwort ändern</h2>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="change_password">
                    <div class="form-group">
                        <label for="current_password">Aktuelles Passwort</label>
                        <input type="password" id="current_password" name="current_password" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="new_password">Neues Passwort</label>
                            <input type="password" id="new_password" name="new_password" 
                                   minlength="6" required>
                            <small class="form-text">Mindestens 6 Zeichen</small>
                        </div>
                        <div class="form-group">
                            <label for="confirm_password">Passwort bestätigen</label>
                            <input type="password" id="confirm_password" name="confirm_password" 
                                   minlength="6" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Passwort ändern</button>
                </form>
            </div>
            
            <!-- Account Actions -->
            <div class="profile-section">
                <h2 class="section-title">⚡ Aktionen</h2>
                <div class="button-group">
                    <a href="settings.php" class="btn btn-secondary">
                        <span>⚙️</span> Erweiterte Einstellungen
                    </a>
                    <a href="collection.php" class="btn btn-secondary">
                        <span>📚</span> Meine Sammlung
                    </a>
                    <a href="decks.php" class="btn btn-secondary">
                        <span>🃏</span> Meine Decks
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Password confirmation validation
        const newPassword = document.getElementById('new_password');
        const confirmPassword = document.getElementById('confirm_password');
        
        function validatePasswords() {
            if (newPassword.value !== confirmPassword.value) {
                confirmPassword.setCustomValidity('Passwörter stimmen nicht überein');
            } else {
                confirmPassword.setCustomValidity('');
            }
        }
        
        newPassword.addEventListener('input', validatePasswords);
        confirmPassword.addEventListener('input', validatePasswords);
    </script>
</body>
</html>
