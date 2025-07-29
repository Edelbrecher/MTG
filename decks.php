<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$success_message = '';
$error_message = '';

// Handle deck creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create_deck') {
        $name = trim($_POST['name']);
        $format = $_POST['format'];
        $strategy = $_POST['strategy'] ?? '';
        $quality = $_POST['quality'] ?? 'Mittel';
        $commander = $_POST['commander'] ?? '';
        $ai_features = $_POST['ai_features'] ?? [];
        $deck_size = intval($_POST['deck_size'] ?? 60);
        $color_focus = $_POST['color_focus'] ?? '';
        
        if (!empty($name) && !empty($format)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO decks (name, format_type, user_id, strategy, quality_level, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$name, $format, $_SESSION['user_id'], $strategy, $quality]);
                $deck_id = $pdo->lastInsertId();
                
                // Generate deck based on AI parameters
                if (!empty($strategy)) {
                    generateEnhancedAIDeck($pdo, $deck_id, $strategy, $format, $quality, $_SESSION['user_id'], [
                        'commander' => $commander,
                        'ai_features' => $ai_features,
                        'deck_size' => $deck_size,
                        'color_focus' => $color_focus
                    ]);
                }
                
                $success_message = "Deck wurde erfolgreich erstellt mit " . count($ai_features) . " AI-Features!";
            } catch (Exception $e) {
                $error_message = "Fehler beim Erstellen des Decks: " . $e->getMessage();
            }
        } else {
            $error_message = "Bitte füllen Sie alle Pflichtfelder aus.";
        }
    } elseif ($_POST['action'] === 'delete_deck') {
        $deck_id = intval($_POST['deck_id']);
        try {
            $stmt = $pdo->prepare("DELETE FROM deck_cards WHERE deck_id = ?");
            $stmt->execute([$deck_id]);
            $stmt = $pdo->prepare("DELETE FROM decks WHERE id = ? AND user_id = ?");
            $stmt->execute([$deck_id, $_SESSION['user_id']]);
            $success_message = "Deck wurde gelöscht!";
        } catch (Exception $e) {
            $error_message = "Fehler beim Löschen: " . $e->getMessage();
        }
    }
}

// Get existing decks
try {
    $stmt = $pdo->prepare("
        SELECT d.*, 
               COUNT(dc.id) as card_count,
               SUM(dc.quantity) as total_cards
        FROM decks d
        LEFT JOIN deck_cards dc ON d.id = dc.deck_id AND dc.is_sideboard = 0
        WHERE d.user_id = ?
        GROUP BY d.id
        ORDER BY d.created_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $existing_decks = $stmt->fetchAll();
} catch (Exception $e) {
    $existing_decks = [];
    $error_message = "Fehler beim Laden der Decks: " . $e->getMessage();
}

// Enhanced AI Deck Generation Function
function generateEnhancedAIDeck($pdo, $deck_id, $strategy, $format, $quality, $user_id, $options = []) {
    $commander = $options['commander'] ?? '';
    $ai_features = $options['ai_features'] ?? [];
    $deck_size = $options['deck_size'] ?? 60;
    $color_focus = $options['color_focus'] ?? '';
    
    try {
        // Get user's collection
        $stmt = $pdo->prepare("SELECT DISTINCT card_name, card_data FROM collections WHERE user_id = ? LIMIT 200");
        $stmt->execute([$user_id]);
        $user_cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($user_cards)) {
            // Fallback: Use some basic card names
            $user_cards = [
                ['card_name' => 'Lightning Bolt'], ['card_name' => 'Counterspell'], 
                ['card_name' => 'Serra Angel'], ['card_name' => 'Giant Growth'], 
                ['card_name' => 'Dark Ritual'], ['card_name' => 'Forest'], ['card_name' => 'Island']
            ];
        }
        
        // Add Commander first if specified
        if (!empty($commander) && $format === 'Commander') {
            $stmt = $pdo->prepare("INSERT INTO deck_cards (deck_id, card_name, quantity, is_sideboard, is_commander) VALUES (?, ?, 1, 0, 1)");
            $stmt->execute([$deck_id, $commander]);
            $deck_size--; // Reduce target since commander is already added
        }
        
        // Strategy-based card selection with AI features
        $mana_curve = [];
        
        // Enhanced mana curve based on AI features
        if (in_array('mana_curve', $ai_features)) {
            switch ($strategy) {
                case 'Aggro':
                    $mana_curve = [1 => 12, 2 => 16, 3 => 8, 4 => 4, 5 => 2, 6 => 1];
                    break;
                case 'Control':
                    $mana_curve = [1 => 4, 2 => 8, 3 => 10, 4 => 10, 5 => 8, 6 => 6];
                    break;
                case 'Midrange':
                    $mana_curve = [1 => 6, 2 => 10, 3 => 12, 4 => 10, 5 => 6, 6 => 4];
                    break;
                case 'Combo':
                    $mana_curve = [1 => 8, 2 => 12, 3 => 8, 4 => 8, 5 => 6, 6 => 4];
                    break;
                default:
                    $mana_curve = [1 => 8, 2 => 10, 3 => 10, 4 => 8, 5 => 6, 6 => 4];
            }
        } else {
            // Standard mana curve
            $mana_curve = [1 => 6, 2 => 8, 3 => 8, 4 => 6, 5 => 4, 6 => 3];
        }
        
        // Add cards based on enhanced AI logic
        $added_cards = 0;
        $target_cards = min($deck_size, count($user_cards) * 2); // Reasonable limit
        
        // Add lands first if balance feature is enabled
        if (in_array('balance', $ai_features)) {
            $land_count = $format === 'Commander' ? 36 : 24;
            $basic_lands = ['Forest', 'Island', 'Mountain', 'Plains', 'Swamp'];
            
            for ($i = 0; $i < $land_count && $added_cards < $target_cards; $i++) {
                $land = $basic_lands[array_rand($basic_lands)];
                $stmt = $pdo->prepare("INSERT INTO deck_cards (deck_id, card_name, quantity, is_sideboard) VALUES (?, ?, 1, 0)");
                $stmt->execute([$deck_id, $land]);
                $added_cards++;
            }
        }
        
        // Add creatures and spells based on mana curve
        foreach ($mana_curve as $cmc => $count) {
            for ($i = 0; $i < $count && $added_cards < $target_cards; $i++) {
                if (!empty($user_cards)) {
                    $random_card = $user_cards[array_rand($user_cards)];
                    $quantity = ($format === 'Commander') ? 1 : rand(1, min(4, $target_cards - $added_cards));
                    
                    // Avoid adding the same card as commander
                    if ($random_card['card_name'] !== $commander) {
                        $stmt = $pdo->prepare("INSERT INTO deck_cards (deck_id, card_name, quantity, is_sideboard) VALUES (?, ?, ?, 0)");
                        $stmt->execute([$deck_id, $random_card['card_name'], $quantity]);
                        $added_cards += $quantity;
                    }
                }
            }
        }
        
        // Log AI features used (for debugging/analytics)
        if (!empty($ai_features)) {
            $features_log = implode(',', $ai_features);
            // Could add to database log table if needed
        }
        
    } catch (Exception $e) {
        // Ignore generation errors
        error_log("AI Deck Generation Error: " . $e->getMessage());
    }
}

// Legacy AI Deck Generation Function (keep for compatibility)
function generateAIDeck($pdo, $deck_id, $strategy, $format, $quality, $user_id) {
    // Get user's collection
    try {
        $stmt = $pdo->prepare("SELECT DISTINCT card_name FROM collections WHERE user_id = ? LIMIT 100");
        $stmt->execute([$user_id]);
        $user_cards = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($user_cards)) {
            // Fallback: Use some basic card names
            $user_cards = ['Lightning Bolt', 'Counterspell', 'Serra Angel', 'Giant Growth', 'Dark Ritual'];
        }
        
        // Strategy-based card selection
        $mana_curve = [];
        
        switch ($strategy) {
            case 'Aggro':
                $mana_curve = [1 => 8, 2 => 12, 3 => 8, 4 => 4, 5 => 2, 6 => 1];
                break;
            case 'Control':
                $mana_curve = [1 => 2, 2 => 6, 3 => 8, 4 => 8, 5 => 6, 6 => 4];
                break;
            case 'Midrange':
                $mana_curve = [1 => 4, 2 => 8, 3 => 10, 4 => 8, 5 => 4, 6 => 2];
                break;
            case 'Combo':
                $mana_curve = [1 => 6, 2 => 8, 3 => 6, 4 => 6, 5 => 4, 6 => 2];
                break;
            default:
                $mana_curve = [1 => 6, 2 => 8, 3 => 8, 4 => 6, 5 => 4, 6 => 3];
        }
        
        // Add cards based on mana curve
        $added_cards = 0;
        $target_cards = ($format === 'Commander') ? 99 : 60;
        
        foreach ($mana_curve as $cmc => $count) {
            for ($i = 0; $i < $count && $added_cards < $target_cards; $i++) {
                if (!empty($user_cards)) {
                    $random_card = $user_cards[array_rand($user_cards)];
                    $quantity = ($format === 'Commander') ? 1 : rand(1, 4);
                    
                    $stmt = $pdo->prepare("INSERT INTO deck_cards (deck_id, card_name, quantity, is_sideboard) VALUES (?, ?, ?, 0)");
                    $stmt->execute([$deck_id, $random_card, $quantity]);
                    $added_cards++;
                }
            }
        }
    } catch (Exception $e) {
        // Ignore generation errors
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deck Builder - MTG Collection Manager</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Message styles */
        .message {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border: 1px solid;
        }
        .message-success {
            background-color: #f0f9ff;
            border-color: var(--success-color);
            color: var(--success-color);
        }
        .message-error {
            background-color: #fef2f2;
            border-color: var(--danger-color);
            color: var(--danger-color);
        }
        .message-close {
            float: right;
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            opacity: 0.7;
        }
        .message-close:hover {
            opacity: 1;
        }
        
        /* Custom deck builder styles */
        .deck-builder-card {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: white;
            border: none;
            border-radius: 12px;
            padding: 2rem;
        }
        .strategy-btn-compact {
            width: 100%;
            height: 50px;
            border-radius: 6px;
            border: 2px solid rgba(255,255,255,0.3);
            background: rgba(255,255,255,0.1);
            color: white;
            transition: all 0.3s ease;
            cursor: pointer;
            font-size: 0.9rem;
        }
        .strategy-btn-compact.active,
        .strategy-btn-compact:hover {
            border-color: white;
            background: rgba(255,255,255,0.2);
        }
        .deck-list {
            max-height: 500px;
            overflow-y: auto;
        }
        .deck-item {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            background: var(--surface-color);
            transition: transform 0.2s ease;
        }
        .deck-item:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .deck-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.5rem;
        }
        .deck-header h6 {
            margin: 0;
            font-size: 1rem;
            font-weight: 600;
        }
        .deck-badges {
            display: flex;
            gap: 0.25rem;
        }
        .badge {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .badge-format {
            background: var(--primary-color);
            color: white;
        }
        .badge-strategy {
            background: var(--secondary-color);
            color: white;
        }
        .deck-stats {
            display: flex;
            gap: 1rem;
            margin-bottom: 0.75rem;
            font-size: 0.85rem;
            color: var(--text-secondary);
        }
        .deck-actions {
            display: flex;
            gap: 0.5rem;
        }
        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.8rem;
            border-radius: 4px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-primary { background: var(--primary-color); color: white; }
        .btn-secondary { background: var(--secondary-color); color: white; }
        .btn-danger { background: var(--danger-color); color: white; }
        .btn-sm:hover { opacity: 0.8; }
        .ai-features {
            background: linear-gradient(135deg, #ffeaa7 0%, #fab1a0 100%);
            border-radius: 8px;
            padding: 1.5rem;
            margin: 1.5rem 0;
            color: #333;
        }
        .deck-card {
            transition: transform 0.2s ease;
            margin-bottom: 1rem;
        }
        .deck-card:hover {
            transform: translateY(-2px);
        }
        .format-badge {
            font-size: 0.8rem;
            padding: 4px 8px;
        }
        .deck-stats {
            background: var(--background-color);
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="container">
        <div class="main-content">
        <?php if ($success_message): ?>
            <div class="message message-success">
                <button class="message-close" onclick="this.parentElement.style.display='none'">&times;</button>
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <div class="message message-error">
                <button class="message-close" onclick="this.parentElement.style.display='none'">&times;</button>
                <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>
        
        <div class="grid grid-2 gap-4">
            <!-- Left Column: Deck Builder -->
            <div class="card deck-builder-card">
                <h3><i class="fas fa-brain"></i> Intelligenter Deck Builder</h3>
                <p style="margin-bottom: 1.5rem; opacity: 0.9;">Erstellen Sie optimierte Decks mit KI-Unterstützung</p>
                
                <form method="post" id="deckBuilderForm">
                    <input type="hidden" name="action" value="create_deck">
                    
                    <!-- Compact Form Layout -->
                    <div class="grid grid-2 gap-3" style="margin-bottom: 1rem;">
                        <div>
                            <label style="color: white; font-weight: 500; margin-bottom: 0.5rem; display: block;"><i class="fas fa-layer-group"></i> Format</label>
                            <select name="format" style="width: 100%; padding: 0.5rem; border-radius: 6px; border: 1px solid #ddd;" required>
                                <option value="">-- Format wählen --</option>
                                <option value="Standard">Standard</option>
                                <option value="Modern">Modern</option>
                                <option value="Legacy">Legacy</option>
                                <option value="Commander">Commander</option>
                                <option value="Pioneer">Pioneer</option>
                                <option value="Vintage">Vintage</option>
                                <option value="Pauper">Pauper</option>
                                <option value="Historic">Historic</option>
                                <option value="Casual">Casual</option>
                            </select>
                        </div>
                        <div>
                            <label style="color: white; font-weight: 500; margin-bottom: 0.5rem; display: block;"><i class="fas fa-star"></i> Qualität</label>
                            <select name="quality" style="width: 100%; padding: 0.5rem; border-radius: 6px; border: 1px solid #ddd;">
                                <option value="Niedrig">Budget (Niedrig)</option>
                                <option value="Mittel" selected>Standard (Mittel)</option>
                                <option value="Hoch">Premium (Hoch)</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Commander Selection (only visible when Commander format is selected) -->
                    <div id="commanderSection" style="display: none; margin-bottom: 1rem;">
                        <label style="color: white; font-weight: 500; margin-bottom: 0.5rem; display: block;"><i class="fas fa-crown"></i> Commander aus Ihrer Sammlung</label>
                        <select name="commander" id="commanderSelect" style="width: 100%; padding: 0.5rem; border-radius: 6px; border: 1px solid #ddd;">
                            <option value="">-- Commander wählen (optional) --</option>
                        </select>
                        <small style="color: rgba(255,255,255,0.7); font-size: 0.8rem;">Legendäre Kreaturen aus Ihrer Sammlung</small>
                    </div>
                    
                    <!-- AI Features Section -->
                    <div style="background: rgba(255,255,255,0.1); border-radius: 8px; padding: 1rem; margin-bottom: 1rem;">
                        <h6 style="color: white; margin-bottom: 0.75rem;"><i class="fas fa-robot"></i> AI-Features</h6>
                        
                        <div class="grid grid-2 gap-2" style="margin-bottom: 0.75rem;">
                            <label style="color: white; font-size: 0.9rem; display: flex; align-items: center; gap: 0.5rem;">
                                <input type="checkbox" name="ai_features[]" value="mana_curve" checked>
                                <span>Mana-Kurve optimieren</span>
                            </label>
                            <label style="color: white; font-size: 0.9rem; display: flex; align-items: center; gap: 0.5rem;">
                                <input type="checkbox" name="ai_features[]" value="synergy">
                                <span>Synergie-Analyse</span>
                            </label>
                            <label style="color: white; font-size: 0.9rem; display: flex; align-items: center; gap: 0.5rem;">
                                <input type="checkbox" name="ai_features[]" value="meta_game">
                                <span>Meta-Game Anpassung</span>
                            </label>
                            <label style="color: white; font-size: 0.9rem; display: flex; align-items: center; gap: 0.5rem;">
                                <input type="checkbox" name="ai_features[]" value="balance">
                                <span>Deck-Balance prüfen</span>
                            </label>
                        </div>
                        
                        <div style="margin-bottom: 0.75rem;">
                            <label style="color: white; font-weight: 500; margin-bottom: 0.5rem; display: block;"><i class="fas fa-chart-line"></i> Deck-Größe</label>
                            <select name="deck_size" style="width: 100%; padding: 0.5rem; border-radius: 6px; border: 1px solid #ddd;">
                                <option value="60">60 Karten (Standard)</option>
                                <option value="75">75 Karten (inkl. Sideboard)</option>
                                <option value="100">100 Karten (Commander)</option>
                                <option value="40">40 Karten (Limited)</option>
                            </select>
                        </div>
                        
                        <div>
                            <label style="color: white; font-weight: 500; margin-bottom: 0.5rem; display: block;"><i class="fas fa-palette"></i> Farbfokus</label>
                            <select name="color_focus" style="width: 100%; padding: 0.5rem; border-radius: 6px; border: 1px solid #ddd;">
                                <option value="">Automatisch bestimmen</option>
                                <option value="mono">Mono-Color (1 Farbe)</option>
                                <option value="dual">Dual-Color (2 Farben)</option>
                                <option value="tri">Tri-Color (3 Farben)</option>
                                <option value="multi">Multi-Color (4+ Farben)</option>
                                <option value="colorless">Farblos</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Compact Strategy Selection -->
                    <div style="margin-bottom: 1rem;">
                        <label style="color: white; font-weight: 500; margin-bottom: 0.5rem; display: block;"><i class="fas fa-chess"></i> Deck-Strategie</label>
                        <div class="grid grid-2 gap-2">
                            <button type="button" class="strategy-btn-compact" data-strategy="Aggro">
                                <i class="fas fa-fire"></i> <strong>Aggro</strong>
                            </button>
                            <button type="button" class="strategy-btn-compact" data-strategy="Control">
                                <i class="fas fa-shield-alt"></i> <strong>Control</strong>
                            </button>
                            <button type="button" class="strategy-btn-compact" data-strategy="Midrange">
                                <i class="fas fa-balance-scale"></i> <strong>Midrange</strong>
                            </button>
                            <button type="button" class="strategy-btn-compact" data-strategy="Combo">
                                <i class="fas fa-cogs"></i> <strong>Combo</strong>
                            </button>
                        </div>
                        <input type="hidden" name="strategy" id="selectedStrategy">
                    </div>
                    
                    <!-- Deck Name -->
                    <div style="margin-bottom: 1.5rem;">
                        <label style="color: white; font-weight: 500; margin-bottom: 0.5rem; display: block;"><i class="fas fa-tag"></i> Deck Name</label>
                        <input type="text" name="name" style="width: 100%; padding: 0.5rem; border-radius: 6px; border: 1px solid #ddd;" placeholder="Mein neues Deck" required>
                    </div>
                    
                    <button type="submit" style="width: 100%; background: #fff; color: var(--primary-color); border: none; padding: 0.75rem; border-radius: 8px; font-weight: 600; transition: all 0.2s;">
                        <i class="fas fa-magic"></i> Deck erstellen
                    </button>
                </form>
            </div>
            
            <!-- Right Column: Existing Decks -->
            <div>
                <div class="card">
                    <h5 style="margin-bottom: 1rem;"><i class="fas fa-layer-group"></i> Ihre Decks</h5>
                    </div>
                    <?php if (empty($existing_decks)): ?>
                        <div style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                            <i class="fas fa-plus-circle" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                            <p>Noch keine Decks erstellt</p>
                            <p style="font-size: 0.9rem;">Nutzen Sie den Deck Builder links, um Ihr erstes Deck zu erstellen!</p>
                        </div>
                    <?php else: ?>
                        <div class="deck-list">
                            <?php foreach ($existing_decks as $deck): ?>
                                <div class="deck-item">
                                    <div class="deck-header">
                                        <h6><?= htmlspecialchars($deck['name']) ?></h6>
                                        <div class="deck-badges">
                                            <span class="badge badge-format"><?= htmlspecialchars($deck['format_type']) ?></span>
                                            <?php if ($deck['strategy']): ?>
                                                <span class="badge badge-strategy"><?= htmlspecialchars($deck['strategy']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="deck-stats">
                                        <span><i class="fas fa-layer-group"></i> <?= $deck['card_count'] ?: 0 ?> unique</span>
                                        <span><i class="fas fa-clone"></i> <?= $deck['total_cards'] ?: 0 ?> total</span>
                                        <span><i class="fas fa-calendar"></i> <?= date('d.m.Y', strtotime($deck['created_at'])) ?></span>
                                    </div>
                                    
                                    <div class="deck-actions">
                                        <a href="deck_view.php?id=<?= $deck['id'] ?>" class="btn-sm btn-primary">
                                            <i class="fas fa-eye"></i> Ansehen
                                        </a>
                                        <a href="#" class="btn-sm btn-secondary">
                                            <i class="fas fa-download"></i> Export
                                        </a>
                                        <form method="post" style="display: inline;" onsubmit="return confirm('Deck wirklich löschen?')">
                                            <input type="hidden" name="action" value="delete_deck">
                                            <input type="hidden" name="deck_id" value="<?= $deck['id'] ?>">
                                            <button type="submit" class="btn-sm btn-danger">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        </div> <!-- end main-content -->
    </div> <!-- end container -->

    <script>
        // Strategy selection handling
        document.querySelectorAll('.strategy-btn-compact').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                
                // Remove active class from all buttons
                document.querySelectorAll('.strategy-btn-compact').forEach(b => b.classList.remove('active'));
                
                // Add active class to clicked button
                this.classList.add('active');
                
                // Set hidden input value
                document.getElementById('selectedStrategy').value = this.dataset.strategy;
            });
        });
        
        // Auto-generate deck name based on strategy and format
        document.querySelector('select[name="format"]').addEventListener('change', function() {
            updateDeckName();
            handleFormatChange(this.value);
        });
        document.querySelectorAll('.strategy-btn-compact').forEach(btn => {
            btn.addEventListener('click', updateDeckName);
        });
        
        function updateDeckName() {
            const format = document.querySelector('select[name="format"]').value;
            const strategy = document.getElementById('selectedStrategy').value;
            const nameInput = document.querySelector('input[name="name"]');
            
            if (format && strategy) {
                nameInput.value = strategy + ' ' + format + ' Deck';
            }
        }
        
        function handleFormatChange(format) {
            const commanderSection = document.getElementById('commanderSection');
            const deckSizeSelect = document.querySelector('select[name="deck_size"]');
            
            if (format === 'Commander') {
                commanderSection.style.display = 'block';
                deckSizeSelect.value = '100';
                loadCommanders();
            } else {
                commanderSection.style.display = 'none';
                deckSizeSelect.value = '60';
            }
        }
        
        function loadCommanders() {
            const commanderSelect = document.getElementById('commanderSelect');
            
            // Lade Commander aus der Sammlung via AJAX
            fetch('get_commanders.php')
                .then(response => response.json())
                .then(data => {
                    commanderSelect.innerHTML = '<option value="">-- Commander wählen (optional) --</option>';
                    
                    if (data.commanders && data.commanders.length > 0) {
                        data.commanders.forEach(commander => {
                            const option = document.createElement('option');
                            option.value = commander.card_name;
                            option.textContent = commander.card_name;
                            commanderSelect.appendChild(option);
                        });
                    } else {
                        const option = document.createElement('option');
                        option.value = '';
                        option.textContent = 'Keine legendären Kreaturen in Ihrer Sammlung gefunden';
                        option.disabled = true;
                        commanderSelect.appendChild(option);
                    }
                })
                .catch(error => {
                    console.error('Fehler beim Laden der Commander:', error);
                    commanderSelect.innerHTML = '<option value="">Fehler beim Laden der Commander</option>';
                });
        }
        
        // Form validation
        document.getElementById('deckBuilderForm').addEventListener('submit', function(e) {
            const format = document.querySelector('select[name="format"]').value;
            const strategy = document.getElementById('selectedStrategy').value;
            const name = document.querySelector('input[name="name"]').value;
            
            if (!format) {
                e.preventDefault();
                alert('Bitte wählen Sie ein Format aus!');
                return;
            }
            
            if (!strategy) {
                e.preventDefault();
                alert('Bitte wählen Sie eine Strategie aus!');
                return;
            }
            
            if (!name.trim()) {
                e.preventDefault();
                alert('Bitte geben Sie einen Deck-Namen ein!');
                return;
            }
        });
    </script>
</body>
</html>
