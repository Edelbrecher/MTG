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
<?php
// Enhanced AI Deck Generation Function with intelligent monitoring
function generateEnhancedAIDeck($pdo, $deck_id, $strategy, $format, $quality, $user_id, $options = [], &$ai_log = []) {
    $commander = $options['commander'] ?? '';
    $ai_features = $options['ai_features'] ?? [];
    $deck_size = $options['deck_size'] ?? 60;
    $color_focus = $options['color_focus'] ?? '';
    
    $ai_log = [];
    $warnings = [];
    $mana_curve_analysis = [];
    
    try {
        $ai_log[] = "🔄 Analysiere Sammlung...";
        
        // Get user's collection
        $stmt = $pdo->prepare("SELECT DISTINCT card_name, card_data FROM collections WHERE user_id = ? LIMIT 200");
        $stmt->execute([$user_id]);
        $user_cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($user_cards)) {
            $ai_log[] = "⚠️ Sammlung leer - verwende Fallback-Karten";
            $user_cards = [
                ['card_name' => 'Lightning Bolt'], ['card_name' => 'Counterspell'], 
                ['card_name' => 'Serra Angel'], ['card_name' => 'Giant Growth'], 
                ['card_name' => 'Dark Ritual'], ['card_name' => 'Forest'], ['card_name' => 'Island']
            ];
        } else {
            $ai_log[] = "✅ " . count($user_cards) . " Karten in Sammlung gefunden";
        }
        
        // Add Commander first if specified and extract color identity
        $commander_colors = [];
        if (!empty($commander) && $format === 'Commander') {
            $ai_log[] = "👑 Füge Commander hinzu: " . $commander;
            $stmt = $pdo->prepare("INSERT INTO deck_cards (deck_id, card_name, quantity, is_sideboard, is_commander) VALUES (?, ?, 1, 0, 1)");
            $stmt->execute([$deck_id, $commander]);
            
            // Extract commander's color identity from user's collection
            $commander_colors = getCommanderColorIdentity($pdo, $commander, $user_id);
            if (!empty($commander_colors)) {
                $ai_log[] = "🎨 Color Identity: " . implode('', $commander_colors);
            }
        }
        
        // Calculate correct target based on deck size and format rules
        $actual_deck_size = $deck_size;
        if ($format === 'Commander') {
            $actual_deck_size = 99; // Commander format ist immer 99 + 1 Commander = 100
        }
        
        $ai_log[] = "📊 Zielgröße: {$actual_deck_size} Karten ({$format} Format)";
        
        // Strategy-based card selection with AI features
        $mana_curve = [];
        
        // Enhanced mana curve based on AI features
        if (in_array('mana_curve', $ai_features)) {
            $ai_log[] = "⚡ Optimiere Mana-Kurve für {$strategy}-Strategie...";
            
            $curve_multiplier = $actual_deck_size / 60;
            
            switch ($strategy) {
                case 'Aggro':
                    $base_curve = [1 => 12, 2 => 16, 3 => 8, 4 => 4, 5 => 2, 6 => 1];
                    $ai_log[] = "🔥 Aggro-Kurve: Low-Cost fokussiert";
                    break;
                case 'Control':
                    $base_curve = [1 => 4, 2 => 8, 3 => 10, 4 => 10, 5 => 8, 6 => 6];
                    $ai_log[] = "🛡️ Control-Kurve: Mid-to-High Cost";
                    break;
                case 'Midrange':
                    $base_curve = [1 => 6, 2 => 10, 3 => 12, 4 => 10, 5 => 6, 6 => 4];
                    $ai_log[] = "⚖️ Midrange-Kurve: Ausgewogen";
                    break;
                case 'Combo':
                    $base_curve = [1 => 8, 2 => 12, 3 => 8, 4 => 8, 5 => 6, 6 => 4];
                    $ai_log[] = "⚙️ Combo-Kurve: Setup-orientiert";
                    break;
                default:
                    $base_curve = [1 => 8, 2 => 10, 3 => 10, 4 => 8, 5 => 6, 6 => 4];
                    $ai_log[] = "📈 Standard-Kurve angewendet";
            }
            
            foreach ($base_curve as $cmc => $count) {
                $mana_curve[$cmc] = round($count * $curve_multiplier);
            }
            
            $total_curve_cards = array_sum($mana_curve);
            $avg_cmc = 0;
            $weighted_sum = 0;
            foreach ($mana_curve as $cmc => $count) {
                $weighted_sum += $cmc * $count;
            }
            if ($total_curve_cards > 0) {
                $avg_cmc = round($weighted_sum / $total_curve_cards, 1);
            }
            
            $mana_curve_analysis = [
                'total_spells' => $total_curve_cards,
                'avg_cmc' => $avg_cmc,
                'curve_distribution' => $mana_curve
            ];
            
            $ai_log[] = "📊 Durchschnittliche Mana-Kosten: {$avg_cmc}";
            
        } else {
            $curve_multiplier = $actual_deck_size / 60;
            $base_curve = [1 => 6, 2 => 8, 3 => 8, 4 => 6, 5 => 4, 6 => 3];
            foreach ($base_curve as $cmc => $count) {
                $mana_curve[$cmc] = round($count * $curve_multiplier);
            }
            $ai_log[] = "📈 Standard Mana-Kurve verwendet";
        }
        
        // Calculate land count based on deck size and format
        $land_count = 0;
        if (in_array('balance', $ai_features)) {
            $ai_log[] = "⚖️ Berechne optimale Land-Verteilung...";
            if ($format === 'Commander') {
                $land_count = 36;
            } else {
                $land_count = round($actual_deck_size * 0.4);
            }
            $ai_log[] = "🏞️ {$land_count} Länder geplant";
        }
        
        $non_land_slots = $actual_deck_size - $land_count;
        $spell_target = min($non_land_slots, count($user_cards) * 2);
        $added_cards = 0;
        
        // Add lands first if balance feature is enabled
        if (in_array('balance', $ai_features) && $land_count > 0) {
            $ai_log[] = "🏞️ Füge Länder hinzu...";
            
            if ($format === 'Commander' && !empty($commander_colors)) {
                $available_basics = [];
                if (in_array('W', $commander_colors)) $available_basics[] = 'Plains';
                if (in_array('U', $commander_colors)) $available_basics[] = 'Island';
                if (in_array('B', $commander_colors)) $available_basics[] = 'Swamp';
                if (in_array('R', $commander_colors)) $available_basics[] = 'Mountain';
                if (in_array('G', $commander_colors)) $available_basics[] = 'Forest';
                
                if (empty($available_basics)) {
                    $available_basics = ['Plains', 'Island', 'Swamp', 'Mountain', 'Forest'];
                }
                $basic_lands = $available_basics;
            } else {
                $basic_lands = ['Forest', 'Island', 'Mountain', 'Plains', 'Swamp'];
            }
            
            for ($i = 0; $i < $land_count; $i++) {
                $land = $basic_lands[array_rand($basic_lands)];
                $stmt = $pdo->prepare("INSERT INTO deck_cards (deck_id, card_name, quantity, is_sideboard) VALUES (?, ?, 1, 0)");
                $stmt->execute([$deck_id, $land]);
            }
        }
        
        // Add creatures and spells based on mana curve
        $ai_log[] = "🃏 Generiere Karten nach Mana-Kurve...";
        
        foreach ($mana_curve as $cmc => $count) {
            for ($i = 0; $i < $count && $added_cards < $spell_target; $i++) {
                if (!empty($user_cards)) {
                    $attempts = 0;
                    $max_attempts = count($user_cards) * 2;
                    
                    do {
                        $random_card = $user_cards[array_rand($user_cards)];
                        $attempts++;
                        
                        $is_legal = true;
                        if ($format === 'Commander' && !empty($commander_colors)) {
                            $is_legal = isCardLegalInCommander($random_card['card_data'] ?? null, $commander_colors);
                        }
                        
                        $card_cmc = extractManaValue($random_card['card_data'] ?? null);
                        $cmc_matches = ($card_cmc == $cmc || $cmc >= 6 && $card_cmc >= 6);
                        
                    } while ((!$is_legal || !$cmc_matches) && $attempts < $max_attempts);
                    
                    if ($is_legal && $cmc_matches) {
                        $stmt = $pdo->prepare("INSERT INTO deck_cards (deck_id, card_name, quantity, is_sideboard) VALUES (?, ?, 1, 0)");
                        $stmt->execute([$deck_id, $random_card['card_name']]);
                        $added_cards++;
                    }
                }
            }
        }
        
        $ai_log[] = "✅ {$added_cards} Zauber hinzugefügt";
        
        // Intelligent deck analysis and warnings
        if (in_array('mana_curve', $ai_features)) {
            $curve_problems = [];
            
            if ($mana_curve_analysis['avg_cmc'] > 4.0) {
                $curve_problems[] = "Hohe durchschnittliche Mana-Kosten";
                $warnings[] = "Deck könnte zu langsam sein (Avg CMC: " . $mana_curve_analysis['avg_cmc'] . ")";
            }
            
            if ($mana_curve_analysis['avg_cmc'] < 2.0) {
                $curve_problems[] = "Sehr niedrige Mana-Kosten";
                $warnings[] = "Deck könnte zu schnell ausgehen (Avg CMC: " . $mana_curve_analysis['avg_cmc'] . ")";
            }
            
            $curve_dist = $mana_curve_analysis['curve_distribution'];
            $low_cost = ($curve_dist[1] ?? 0) + ($curve_dist[2] ?? 0);
            $total_spells = $mana_curve_analysis['total_spells'];
            
            if ($total_spells > 0 && $low_cost / $total_spells < 0.3) {
                $warnings[] = "Wenig Early Game Optionen (nur " . round($low_cost / $total_spells * 100) . "% CMC 1-2)";
            }
            
            if (empty($curve_problems)) {
                $ai_log[] = "✅ Mana-Kurve optimal";
            } else {
                $ai_log[] = "⚠️ Mana-Kurven-Analyse: " . implode(', ', $curve_problems);
            }
        }
        
        // Fill remaining slots if needed
        $total_non_lands = $added_cards;
        $remaining_slots = $spell_target - $total_non_lands;
        
        if ($remaining_slots > 0) {
            $ai_log[] = "🔄 Fülle verbleibende {$remaining_slots} Slots...";
            $attempts = 0;
            $max_fill_attempts = count($user_cards) * 3;
            
            for ($i = 0; $i < $remaining_slots && $attempts < $max_fill_attempts; $attempts++) {
                if (!empty($user_cards)) {
                    $random_card = $user_cards[array_rand($user_cards)];
                    
                    $is_legal = true;
                    if ($format === 'Commander' && !empty($commander_colors)) {
                        $is_legal = isCardLegalInCommander($random_card['card_data'] ?? null, $commander_colors);
                    }
                    
                    if ($is_legal && $random_card['card_name'] !== $commander) {
                        $quantity = ($format === 'Commander') ? 1 : min(4, $remaining_slots - $i);
                        $stmt = $pdo->prepare("INSERT INTO deck_cards (deck_id, card_name, quantity, is_sideboard) VALUES (?, ?, ?, 0)");
                        $stmt->execute([$deck_id, $random_card['card_name'], $quantity]);
                        $i += $quantity;
                    }
                }
            }
        }
        
        // Log AI features used
        if (!empty($ai_features)) {
            $features_log = implode(',', $ai_features);
            $ai_log[] = "🤖 AI-Features verwendet: " . $features_log;
        }
        
        // Final statistics
        $final_count = $added_cards + $land_count + ($format === 'Commander' ? 1 : 0);
        $ai_log[] = "📈 Finales Deck: {$final_count} Karten";
        
        return [
            'success' => true,
            'cards_added' => $added_cards,
            'lands_added' => $land_count,
            'total_cards' => $final_count,
            'warnings' => $warnings,
            'mana_curve_analysis' => $mana_curve_analysis,
            'ai_steps' => $ai_log
        ];
        
    } catch (Exception $e) {
        error_log("AI Deck Generation Error: " . $e->getMessage());
        $ai_log[] = "❌ Fehler: " . $e->getMessage();
        
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'ai_steps' => $ai_log
        ];
    }
}

// Helper function to get commander color identity
function getCommanderColorIdentity($pdo, $commander_name, $user_id) {
    try {
        $stmt = $pdo->prepare("SELECT card_data FROM collections WHERE user_id = ? AND card_name = ? LIMIT 1");
        $stmt->execute([$user_id, $commander_name]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && !empty($result['card_data'])) {
            $card_data = json_decode($result['card_data'], true);
            if (isset($card_data['colorIdentity'])) {
                return $card_data['colorIdentity'];
            }
        }
        
        return [];
    } catch (Exception $e) {
        return [];
    }
}

// Helper function to check if card is legal in Commander format
function isCardLegalInCommander($card_data, $commander_colors) {
    if (empty($card_data) || empty($commander_colors)) {
        return true;
    }
    
    try {
        $data = is_string($card_data) ? json_decode($card_data, true) : $card_data;
        
        if (isset($data['colorIdentity'])) {
            $card_colors = $data['colorIdentity'];
            
            foreach ($card_colors as $color) {
                if (!in_array($color, $commander_colors)) {
                    return false;
                }
            }
        }
        
        return true;
    } catch (Exception $e) {
        return true;
    }
}

// Helper function to extract mana value from card data
function extractManaValue($card_data) {
    if (empty($card_data)) {
        return 0;
    }
    
    try {
        $data = is_string($card_data) ? json_decode($card_data, true) : $card_data;
        
        if (isset($data['manaValue'])) {
            return (int)$data['manaValue'];
        }
        
        if (isset($data['cmc'])) {
            return (int)$data['cmc'];
        }
        
        if (isset($data['manaCost'])) {
            $mana_cost = $data['manaCost'];
            preg_match_all('/\{(\d+)\}/', $mana_cost, $numbers);
            preg_match_all('/\{[WUBRG]\}/', $mana_cost, $colors);
            
            $total = 0;
            foreach ($numbers[1] as $num) {
                $total += (int)$num;
            }
            $total += count($colors[0]);
            
            return $total;
        }
        
        return 0;
    } catch (Exception $e) {
        return 0;
    }
}
?>
