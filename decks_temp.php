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
                    $ai_log = []; // Sammle AI-Schritte für Feedback
                    $generation_result = generateEnhancedAIDeck($pdo, $deck_id, $strategy, $format, $quality, $_SESSION['user_id'], [
                        'commander' => $commander,
                        'ai_features' => $ai_features,
                        'deck_size' => $deck_size,
                        'color_focus' => $color_focus
                    ], $ai_log);
                    
                    // Erstelle detaillierte Erfolgsmeldung
                    $feature_count = count($ai_features);
                    $ai_summary = implode(', ', $ai_log);
                    $success_message = "Deck wurde erfolgreich erstellt mit {$feature_count} AI-Features! 🤖 " . $ai_summary;
                    
                    // Prüfe auf potentielle Probleme
                    if (isset($generation_result['warnings']) && !empty($generation_result['warnings'])) {
                        $success_message .= " ⚠️ Hinweise: " . implode(', ', $generation_result['warnings']);
                    }
                }
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

// Function to extract commander's color identity
function getCommanderColorIdentity($pdo, $commander_name, $user_id) {
    try {
        $stmt = $pdo->prepare("SELECT card_data FROM collections WHERE user_id = ? AND card_name = ? LIMIT 1");
        $stmt->execute([$user_id, $commander_name]);
        $result = $stmt->fetch();
        
        if ($result && $result['card_data']) {
            $card_data = json_decode($result['card_data'], true);
            
            // Extract color identity from mana cost and color identity field
            $colors = [];
            
            // Check color_identity field first (most accurate)
            if (isset($card_data['color_identity']) && is_array($card_data['color_identity'])) {
                $colors = $card_data['color_identity'];
            } else {
                // Fallback: extract from mana_cost
                $mana_cost = $card_data['mana_cost'] ?? '';
                
                if (strpos($mana_cost, 'W') !== false) $colors[] = 'W';
                if (strpos($mana_cost, 'U') !== false) $colors[] = 'U';
                if (strpos($mana_cost, 'B') !== false) $colors[] = 'B';
                if (strpos($mana_cost, 'R') !== false) $colors[] = 'R';
                if (strpos($mana_cost, 'G') !== false) $colors[] = 'G';
                
                // Remove duplicates
                $colors = array_unique($colors);
            }
            
            return $colors;
        }
    } catch (Exception $e) {
        error_log("Error extracting commander color identity: " . $e->getMessage());
    }
    
    // Fallback: return all colors if we can't determine
    return ['W', 'U', 'B', 'R', 'G'];
}

// Function to check if a card is legal in commander color identity
function isCardLegalInCommander($card_data, $commander_colors) {
    if (empty($commander_colors)) {
        return true; // If no commander colors defined, allow all
    }
    
    if (!$card_data) {
        return true; // If no card data, allow (basic lands etc.)
    }
    
    $card_data_array = is_string($card_data) ? json_decode($card_data, true) : $card_data;
    
    if (!$card_data_array) {
        return true; // If can't parse, allow
    }
    
    // Get card's color identity
    $card_colors = [];
    
    // Check color_identity field first
    if (isset($card_data_array['color_identity']) && is_array($card_data_array['color_identity'])) {
        $card_colors = $card_data_array['color_identity'];
    } else {
        // Fallback: extract from mana_cost
        $mana_cost = $card_data_array['mana_cost'] ?? '';
        
        if (strpos($mana_cost, 'W') !== false) $card_colors[] = 'W';
        if (strpos($mana_cost, 'U') !== false) $card_colors[] = 'U';
        if (strpos($mana_cost, 'B') !== false) $card_colors[] = 'B';
        if (strpos($mana_cost, 'R') !== false) $card_colors[] = 'R';
        if (strpos($mana_cost, 'G') !== false) $card_colors[] = 'G';
    }
    
    // Check if all card colors are in commander's color identity
    foreach ($card_colors as $color) {
        if (!in_array($color, $commander_colors)) {
            return false;
        }
    }
    
    return true;
