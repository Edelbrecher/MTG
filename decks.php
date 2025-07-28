<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Handle deck actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create_deck') {
        $name = trim($_POST['name']);
        $format = $_POST['format'] ?? 'Standard';
        $description = trim($_POST['description']);
        
        if (!empty($name)) {
            $stmt = $pdo->prepare("INSERT INTO decks (user_id, name, format_type, description) VALUES (?, ?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $name, $format, $description]);
            $message = "Deck '{$name}' wurde erstellt!";
        }
    } elseif ($_POST['action'] === 'delete_deck') {
        $deck_id = intval($_POST['deck_id']);
        $stmt = $pdo->prepare("DELETE FROM decks WHERE id = ? AND user_id = ?");
        $stmt->execute([$deck_id, $_SESSION['user_id']]);
        $message = "Deck wurde gelöscht.";
    } elseif ($_POST['action'] === 'add_to_deck') {
        $deck_id = intval($_POST['deck_id']);
        $card_name = trim($_POST['card_name']);
        $quantity = intval($_POST['quantity']) ?: 1;
        $is_sideboard = isset($_POST['is_sideboard']) ? 1 : 0;
        
        if (!empty($card_name)) {
            // Check if card exists in collection
            $stmt = $pdo->prepare("SELECT card_name FROM collections WHERE user_id = ? AND card_name = ?");
            $stmt->execute([$_SESSION['user_id'], $card_name]);
            
            if ($stmt->fetch()) {
                // Add to deck
                $stmt = $pdo->prepare("
                    INSERT INTO deck_cards (deck_id, card_name, quantity, is_sideboard) 
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE quantity = quantity + ?
                ");
                $stmt->execute([$deck_id, $card_name, $quantity, $is_sideboard, $quantity]);
                $message = "Karte wurde zum Deck hinzugefügt!";
            } else {
                $error = "Karte nicht in Ihrer Sammlung gefunden.";
            }
        }
    } elseif ($_POST['action'] === 'generate_deck') {
        $format = $_POST['format'] ?? 'Standard';
        $generated_deck = generateDeck($_SESSION['user_id'], $format, $pdo);
        
        if ($generated_deck) {
            $deck_name = "Generiertes Deck (" . date('Y-m-d H:i') . ")";
            $stmt = $pdo->prepare("INSERT INTO decks (user_id, name, format_type, description) VALUES (?, ?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $deck_name, $format, 'Automatisch generiertes Deck']);
            $deck_id = $pdo->lastInsertId();
            
            // Add cards to deck
            foreach ($generated_deck as $card_name => $quantity) {
                $stmt = $pdo->prepare("INSERT INTO deck_cards (deck_id, card_name, quantity) VALUES (?, ?, ?)");
                $stmt->execute([$deck_id, $card_name, $quantity]);
            }
            
            $message = "Deck wurde automatisch generiert!";
        } else {
            $error = "Nicht genügend Karten für ein vollständiges Deck verfügbar.";
        }
    }
}

// Get user's decks
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
$decks = $stmt->fetchAll();

function generateDeck($user_id, $format, $pdo) {
    // Get user's collection
    $stmt = $pdo->prepare("
        SELECT card_name, card_data, quantity 
        FROM collections 
        WHERE user_id = ? AND quantity > 0
    ");
    $stmt->execute([$user_id]);
    $collection = $stmt->fetchAll();
    
    if (count($collection) < 20) {
        return false; // Not enough cards
    }
    
    $deck = [];
    $lands = [];
    $creatures = [];
    $spells = [];
    
    // Categorize cards
    foreach ($collection as $card) {
        $card_data = json_decode($card['card_data'], true);
        $type_line = strtolower($card_data['type_line'] ?? '');
        
        if (strpos($type_line, 'land') !== false) {
            $lands[] = $card;
        } elseif (strpos($type_line, 'creature') !== false) {
            $creatures[] = $card;
        } else {
            $spells[] = $card;
        }
    }
    
    // Build deck with ratio: 24 lands, 20-25 creatures, 11-16 spells
    $target_lands = min(24, count($lands));
    $target_creatures = min(25, count($creatures));
    $target_spells = min(11, count($spells));
    
    // Add lands
    shuffle($lands);
    foreach (array_slice($lands, 0, $target_lands) as $land) {
        $quantity = min(4, $land['quantity']);
        $deck[$land['card_name']] = $quantity;
    }
    
    // Add creatures
    shuffle($creatures);
    $cards_added = 0;
    foreach (array_slice($creatures, 0, $target_creatures) as $creature) {
        if ($cards_added >= 60) break;
        $quantity = min(4, $creature['quantity'], 60 - $cards_added);
        $deck[$creature['card_name']] = $quantity;
        $cards_added += $quantity;
    }
    
    // Add spells
    shuffle($spells);
    foreach (array_slice($spells, 0, $target_spells) as $spell) {
        if ($cards_added >= 60) break;
        $quantity = min(4, $spell['quantity'], 60 - $cards_added);
        $deck[$spell['card_name']] = $quantity;
        $cards_added += $quantity;
    }
    
    return count($deck) >= 40 ? $deck : false;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Decks - MTG Collection Manager</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="container">
        <div class="main-content">
            <div class="page-header">
                <h1 class="page-title">Meine Decks</h1>
                <p class="page-subtitle">Erstellen und verwalten Sie Ihre Magic: The Gathering Decks</p>
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

            <!-- Create Deck Form -->
            <div class="card mb-4">
                <div class="card-header">
                    <h3>Neues Deck erstellen</h3>
                </div>
                <div class="card-body">
                    <form method="POST" class="grid grid-2 gap-4">
                        <input type="hidden" name="action" value="create_deck">
                        <div class="form-group">
                            <label>Deck Name</label>
                            <input type="text" name="name" required>
                        </div>
                        <div class="form-group">
                            <label>Format</label>
                            <select name="format">
                                <option value="Standard">Standard</option>
                                <option value="Modern">Modern</option>
                                <option value="Legacy">Legacy</option>
                                <option value="Vintage">Vintage</option>
                                <option value="Commander">Commander</option>
                                <option value="Pioneer">Pioneer</option>
                                <option value="Pauper">Pauper</option>
                                <option value="Casual">Casual</option>
                            </select>
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label>Beschreibung</label>
                            <textarea name="description" rows="3"></textarea>
                        </div>
                        <div style="grid-column: 1 / -1;">
                            <button type="submit" class="btn btn-primary">Deck erstellen</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Auto-Generate Deck -->
            <div class="card mb-4">
                <div class="card-header">
                    <h3>Deck automatisch generieren</h3>
                </div>
                <div class="card-body">
                    <form method="POST" class="flex gap-4 items-center">
                        <input type="hidden" name="action" value="generate_deck">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Format</label>
                            <select name="format">
                                <option value="Standard">Standard</option>
                                <option value="Modern">Modern</option>
                                <option value="Casual">Casual</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-secondary">Deck generieren</button>
                    </form>
                    <p class="text-muted mt-2" style="font-size: 0.875rem;">
                        Erstellt automatisch ein spielbares Deck aus Ihrer Sammlung basierend auf Magic-Regeln.
                    </p>
                </div>
            </div>

            <!-- Deck List -->
            <div class="grid grid-2 gap-4">
                <?php foreach ($decks as $deck): ?>
                    <div class="card">
                        <div class="card-header flex justify-between items-center">
                            <div>
                                <h4><?php echo htmlspecialchars($deck['name']); ?></h4>
                                <span class="text-muted"><?php echo htmlspecialchars($deck['format_type']); ?></span>
                            </div>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="delete_deck">
                                <input type="hidden" name="deck_id" value="<?php echo $deck['id']; ?>">
                                <button type="submit" class="btn btn-danger" 
                                        onclick="return confirm('Deck wirklich löschen?')" 
                                        style="padding: 0.5rem;">🗑</button>
                            </form>
                        </div>
                        <div class="card-body">
                            <?php if ($deck['description']): ?>
                                <p class="mb-2"><?php echo htmlspecialchars($deck['description']); ?></p>
                            <?php endif; ?>
                            
                            <div class="flex justify-between text-muted mb-3">
                                <span><?php echo $deck['card_count'] ?: 0; ?> verschiedene Karten</span>
                                <span><?php echo $deck['total_cards'] ?: 0; ?> Karten insgesamt</span>
                            </div>
                            
                            <!-- Add Card to Deck -->
                            <form method="POST" class="flex gap-2 items-center">
                                <input type="hidden" name="action" value="add_to_deck">
                                <input type="hidden" name="deck_id" value="<?php echo $deck['id']; ?>">
                                <input type="text" name="card_name" placeholder="Kartenname" 
                                       style="flex: 1; padding: 0.5rem; font-size: 0.875rem;">
                                <input type="number" name="quantity" value="1" min="1" max="4" 
                                       style="width: 60px; padding: 0.5rem; font-size: 0.875rem;">
                                <label style="font-size: 0.875rem;">
                                    <input type="checkbox" name="is_sideboard"> Sideboard
                                </label>
                                <button type="submit" class="btn btn-secondary" 
                                        style="padding: 0.5rem 1rem; font-size: 0.875rem;">+</button>
                            </form>
                            
                            <div class="mt-2">
                                <a href="deck_view.php?id=<?php echo $deck['id']; ?>" class="btn btn-primary" 
                                   style="padding: 0.5rem 1rem; font-size: 0.875rem;">Deck anzeigen</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (empty($decks)): ?>
                <div class="card text-center">
                    <div class="card-body">
                        <h3>Keine Decks vorhanden</h3>
                        <p>Erstellen Sie Ihr erstes Deck!</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php include 'includes/footer.php'; ?>
</body>
</html>
