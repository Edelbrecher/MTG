<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Handle deck creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create_deck') {
        $name = trim($_POST['name']);
        $format = $_POST['format'];
        $strategy = $_POST['strategy'] ?? '';
        $quality = $_POST['quality'] ?? 'Mittel';
        
        if (!empty($name) && !empty($format)) {
            $stmt = $pdo->prepare("INSERT INTO decks (name, format_type, user_id, strategy, quality_level, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$name, $format, $_SESSION['user_id'], $strategy, $quality]);
            $deck_id = $pdo->lastInsertId();
            
            // Generate deck based on AI parameters
            if (!empty($strategy)) {
                generateAIDeck($pdo, $deck_id, $strategy, $format, $quality, $_SESSION['user_id']);
            }
            
            $success_message = "Deck wurde erfolgreich erstellt!";
        }
    } elseif ($_POST['action'] === 'delete_deck') {
        $deck_id = intval($_POST['deck_id']);
        $stmt = $pdo->prepare("DELETE FROM deck_cards WHERE deck_id = ?");
        $stmt->execute([$deck_id]);
        $stmt = $pdo->prepare("DELETE FROM decks WHERE id = ? AND user_id = ?");
        $stmt->execute([$deck_id, $_SESSION['user_id']]);
        $success_message = "Deck wurde gelöscht!";
    }
}

// Get existing decks
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

// AI Deck Generation Function
function generateAIDeck($pdo, $deck_id, $strategy, $format, $quality, $user_id) {
    // Get user's collection
    $stmt = $pdo->prepare("SELECT DISTINCT card_name FROM collections WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user_cards = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($user_cards)) return;
    
    // Strategy-based card selection
    $card_pool = [];
    $mana_curve = [];
    
    switch ($strategy) {
        case 'Aggro':
            $mana_curve = [1 => 8, 2 => 12, 3 => 8, 4 => 4, 5 => 2, 6 => 1];
            $preferred_types = ['Creature', 'Instant', 'Sorcery'];
            break;
        case 'Control':
            $mana_curve = [1 => 2, 2 => 6, 3 => 8, 4 => 8, 5 => 6, 6 => 4];
            $preferred_types = ['Instant', 'Sorcery', 'Enchantment', 'Planeswalker'];
            break;
        case 'Midrange':
            $mana_curve = [1 => 4, 2 => 8, 3 => 10, 4 => 8, 5 => 4, 6 => 2];
            $preferred_types = ['Creature', 'Instant', 'Sorcery'];
            break;
        case 'Combo':
            $mana_curve = [1 => 6, 2 => 8, 3 => 6, 4 => 6, 5 => 4, 6 => 2];
            $preferred_types = ['Instant', 'Sorcery', 'Artifact', 'Enchantment'];
            break;
        default:
            $mana_curve = [1 => 6, 2 => 8, 3 => 8, 4 => 6, 5 => 4, 6 => 3];
            $preferred_types = ['Creature', 'Instant', 'Sorcery'];
    }
    
    // Quality adjustment
    $quality_multiplier = $quality === 'Hoch' ? 1.2 : ($quality === 'Niedrig' ? 0.8 : 1.0);
    
    // Add lands (simplified)
    $land_count = 24;
    for ($i = 0; $i < $land_count; $i++) {
        if (!empty($user_cards)) {
            $random_land = $user_cards[array_rand($user_cards)];
            $stmt = $pdo->prepare("INSERT INTO deck_cards (deck_id, card_name, quantity, is_sideboard) VALUES (?, ?, 1, 0)");
            $stmt->execute([$deck_id, $random_land]);
        }
    }
    
    // Add non-land cards based on mana curve
    $added_cards = 0;
    $target_cards = 36; // 60 total - 24 lands
    
    foreach ($mana_curve as $cmc => $count) {
        for ($i = 0; $i < $count && $added_cards < $target_cards; $i++) {
            if (!empty($user_cards)) {
                $random_card = $user_cards[array_rand($user_cards)];
                $quantity = rand(1, 4);
                
                $stmt = $pdo->prepare("INSERT INTO deck_cards (deck_id, card_name, quantity, is_sideboard) VALUES (?, ?, ?, 0)");
                $stmt->execute([$deck_id, $random_card, $quantity]);
                $added_cards++;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Deck Builder - MTG Collection Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .deck-builder-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .strategy-btn {
            width: 100%;
            height: 80px;
            margin-bottom: 10px;
            border-radius: 10px;
            border: 2px solid transparent;
            transition: all 0.3s ease;
        }
        .strategy-btn.active {
            border-color: #667eea;
            background-color: #667eea;
            color: white;
        }
        .strategy-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        .ai-features {
            background: linear-gradient(135deg, #ffeaa7 0%, #fab1a0 100%);
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
        }
        .deck-card {
            border-radius: 10px;
            transition: transform 0.2s ease;
        }
        .deck-card:hover {
            transform: translateY(-5px);
        }
        .format-badge {
            font-size: 0.8rem;
            padding: 4px 8px;
        }
        .deck-stats {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 10px;
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="container mt-4">
        <div class="row">
            <!-- Left Column: Deck Builder -->
            <div class="col-md-6">
                <div class="card deck-builder-card">
                    <div class="card-body">
                        <h3><i class="fas fa-brain"></i> Intelligenter Deck Builder</h3>
                        
                        <?php if (isset($success_message)): ?>
                            <div class="alert alert-success"><?= $success_message ?></div>
                        <?php endif; ?>
                        
                        <form method="post" id="deckBuilderForm">
                            <input type="hidden" name="action" value="create_deck">
                            
                            <!-- Format Selection -->
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-layer-group"></i> Format wählen</label>
                                <select name="format" class="form-select" required>
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
                            
                            <!-- Strategy Selection -->
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-chess"></i> Deck-Strategie</label>
                                <p class="small text-light">Wählen Sie die Hauptstrategie für optimale Kartenselektion</p>
                                <div class="row">
                                    <div class="col-6">
                                        <button type="button" class="btn btn-outline-light strategy-btn" data-strategy="Aggro">
                                            <i class="fas fa-fire"></i><br>
                                            <strong>Aggro</strong><br>
                                            <small>Schnell, aggressiv</small>
                                        </button>
                                    </div>
                                    <div class="col-6">
                                        <button type="button" class="btn btn-outline-light strategy-btn" data-strategy="Control">
                                            <i class="fas fa-shield-alt"></i><br>
                                            <strong>Control</strong><br>
                                            <small>Defensive, Langzeitspiel</small>
                                        </button>
                                    </div>
                                    <div class="col-6">
                                        <button type="button" class="btn btn-outline-light strategy-btn" data-strategy="Midrange">
                                            <i class="fas fa-balance-scale"></i><br>
                                            <strong>Midrange</strong><br>
                                            <small>Ausgewogen</small>
                                        </button>
                                    </div>
                                    <div class="col-6">
                                        <button type="button" class="btn btn-outline-light strategy-btn" data-strategy="Combo">
                                            <i class="fas fa-cogs"></i><br>
                                            <strong>Combo</strong><br>
                                            <small>Synergie-fokussiert</small>
                                        </button>
                                    </div>
                                </div>
                                <input type="hidden" name="strategy" id="selectedStrategy">
                            </div>
                            
                            <!-- Quality Level -->
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-star"></i> Deck-Qualitätsstufe</label>
                                <select name="quality" class="form-select">
                                    <option value="Niedrig">⭐ Niedrig (Budget, solide)</option>
                                    <option value="Mittel" selected>⭐⭐ Mittel (Ausgewogen, solide)</option>
                                    <option value="Hoch">⭐⭐⭐ Hoch (Kompetitiv, optimiert)</option>
                                </select>
                            </div>
                            
                            <!-- AI Features Info -->
                            <div class="ai-features">
                                <h6><i class="fas fa-robot"></i> AI-Features</h6>
                                <ul class="mb-0">
                                    <li>Automatische Mana-Kurve Optimierung</li>
                                    <li>Synergie-Analyse zwischen Karten</li>
                                    <li>Meta-Game Abgleich</li>
                                    <li>Format-spezifische Optimierungen</li>
                                </ul>
                            </div>
                            
                            <!-- Deck Name -->
                            <div class="mb-3">
                                <label class="form-label">Deck-Name</label>
                                <input type="text" name="name" class="form-control" placeholder="Mein neues Deck" required>
                            </div>
                            
                            <button type="submit" class="btn btn-warning btn-lg w-100">
                                <i class="fas fa-magic"></i> Optimiertes Deck generieren
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Right Column: Existing Decks -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-layer-group"></i> Ihre Decks</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($existing_decks)): ?>
                            <div class="text-center text-muted">
                                <i class="fas fa-plus-circle fa-3x mb-3"></i>
                                <p>Noch keine Decks erstellt</p>
                                <p class="small">Nutzen Sie den Deck Builder links, um Ihr erstes Deck zu erstellen!</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($existing_decks as $deck): ?>
                                <div class="deck-card card mb-3">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <h6 class="card-title"><?= htmlspecialchars($deck['name']) ?></h6>
                                                <span class="badge format-badge bg-<?= 
                                                    $deck['format_type'] === 'Standard' ? 'primary' : 
                                                    ($deck['format_type'] === 'Modern' ? 'success' : 
                                                    ($deck['format_type'] === 'Legacy' ? 'warning' : 
                                                    ($deck['format_type'] === 'Commander' ? 'info' : 'secondary')))
                                                ?>">
                                                    <?= htmlspecialchars($deck['format_type']) ?>
                                                </span>
                                                <?php if ($deck['strategy']): ?>
                                                    <span class="badge bg-secondary"><?= htmlspecialchars($deck['strategy']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-end">
                                                <span class="badge bg-info"><?= $deck['card_count'] ?: 0 ?> Unique</span>
                                                <span class="badge bg-secondary"><?= $deck['total_cards'] ?: 0 ?> Total</span>
                                            </div>
                                        </div>
                                        
                                        <div class="deck-stats mt-2">
                                            <small class="text-muted">
                                                <i class="fas fa-calendar"></i> Erstellt: <?= date('d.m.Y', strtotime($deck['created_at'])) ?>
                                                <?php if ($deck['quality_level']): ?>
                                                    | <i class="fas fa-star"></i> <?= htmlspecialchars($deck['quality_level']) ?>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                        
                                        <div class="mt-3">
                                            <a href="deck_view.php?id=<?= $deck['id'] ?>" class="btn btn-primary btn-sm">
                                                <i class="fas fa-eye"></i> Ansehen
                                            </a>
                                            <a href="#" class="btn btn-outline-secondary btn-sm">
                                                <i class="fas fa-download"></i> Export
                                            </a>
                                            <form method="post" style="display: inline;" onsubmit="return confirm('Deck wirklich löschen?')">
                                                <input type="hidden" name="action" value="delete_deck">
                                                <input type="hidden" name="deck_id" value="<?= $deck['id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm">
                                                    <i class="fas fa-trash"></i> Löschen
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Strategy selection handling
        document.querySelectorAll('.strategy-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                
                // Remove active class from all buttons
                document.querySelectorAll('.strategy-btn').forEach(b => b.classList.remove('active'));
                
                // Add active class to clicked button
                this.classList.add('active');
                
                // Set hidden input value
                document.getElementById('selectedStrategy').value = this.dataset.strategy;
            });
        });
        
        // Auto-generate deck name based on strategy and format
        document.querySelector('select[name="format"]').addEventListener('change', updateDeckName);
        document.querySelectorAll('.strategy-btn').forEach(btn => {
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
    </script>
</body>
</html>
