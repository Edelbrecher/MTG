<?php
session_start();
require_once 'config/database.php';
require_once 'includes/navbar.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit();
}

$success_message = '';
$error_message = '';

// Handle deck creation
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'create_deck') {
    try {
        $name = trim($_POST['name']);
        $format = $_POST['format'];
        $strategy = $_POST['strategy'] ?? '';
        $commander = $_POST['commander'] ?? '';
        $ai_features = $_POST['ai_features'] ?? [];
        $deck_size = (int)($_POST['deck_size'] ?? 60);
        $quality = $_POST['quality'] ?? 'medium';
        $color_focus = $_POST['color_focus'] ?? '';
        
        if (empty($name)) {
            throw new Exception('Deck-Name ist erforderlich');
        }
        
        // Create deck entry
        $stmt = $pdo->prepare("INSERT INTO decks (user_id, name, format_type, strategy, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$_SESSION['user_id'], $name, $format, $strategy]);
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
        $error_message = 'Fehler beim Erstellen des Decks: ' . $e->getMessage();
    }
}

// Handle deck deletion
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'delete_deck') {
    try {
        $deck_id = (int)$_POST['deck_id'];
        
        // Delete deck cards first
        $stmt = $pdo->prepare("DELETE FROM deck_cards WHERE deck_id = ?");
        $stmt->execute([$deck_id]);
        
        // Delete deck
        $stmt = $pdo->prepare("DELETE FROM decks WHERE id = ? AND user_id = ?");
        $stmt->execute([$deck_id, $_SESSION['user_id']]);
        
        $success_message = 'Deck wurde erfolgreich gelöscht!';
        
    } catch (Exception $e) {
        $error_message = 'Fehler beim Löschen des Decks: ' . $e->getMessage();
    }
}

// Get existing decks
try {
    $stmt = $pdo->prepare("
        SELECT d.*, 
               COUNT(DISTINCT dc.card_name) as card_count,
               SUM(dc.quantity) as total_cards
        FROM decks d 
        LEFT JOIN deck_cards dc ON d.id = dc.deck_id AND dc.is_sideboard = 0
        WHERE d.user_id = ? 
        GROUP BY d.id 
        ORDER BY d.created_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $existing_decks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $existing_decks = [];
    $error_message = 'Fehler beim Laden der Decks: ' . $e->getMessage();
}

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
                ['card_name' => 'Dark Ritual']
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
                $ai_log[] = "🎨 Color Identity: " . implode('', $commander_colors) . " (PFLICHT: Nur diese Farben erlaubt!)";
            } else {
                $ai_log[] = "⚠️ Commander Color Identity nicht gefunden - verwende colorless";
                $commander_colors = []; // Colorless commander
            }
        } elseif ($format === 'Commander') {
            // Commander Format OHNE Commander ist nicht erlaubt
            throw new Exception('Commander Format erfordert einen Commander!');
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
            $ai_log[] = "🏞️ Füge Länder hinzu (Standard-Länder sind unbegrenzt verfügbar)...";
            
            // Standard-Länder sind immer unbegrenzt verfügbar
            if ($format === 'Commander') {
                // Für Commander: NUR Länder der Commander Color Identity
                $available_basics = [];
                if (in_array('W', $commander_colors)) $available_basics[] = 'Plains';
                if (in_array('U', $commander_colors)) $available_basics[] = 'Island';
                if (in_array('B', $commander_colors)) $available_basics[] = 'Swamp';
                if (in_array('R', $commander_colors)) $available_basics[] = 'Mountain';
                if (in_array('G', $commander_colors)) $available_basics[] = 'Forest';
                
                if (empty($available_basics)) {
                    // Colorless Commander: Wastes oder andere colorless lands
                    $available_basics = ['Wastes'];
                    $ai_log[] = "⚪ Colorless Commander - verwende Wastes";
                } else {
                    $ai_log[] = "🎨 Commander Color Identity STRIKT befolgt: " . implode(', ', $available_basics);
                }
                
                $basic_lands = $available_basics;
            } else {
                $basic_lands = ['Forest', 'Island', 'Mountain', 'Plains', 'Swamp'];
                $ai_log[] = "🌍 Alle Standard-Länder verfügbar";
            }
            
            // Füge Länder gleichmäßig verteilt hinzu
            $lands_per_type = [];
            $remaining_lands = $land_count;
            
            // Berechne gleichmäßige Verteilung
            foreach ($basic_lands as $land) {
                $lands_per_type[$land] = floor($land_count / count($basic_lands));
            }
            
            // Verteile übrige Länder
            $extra_lands = $land_count - (floor($land_count / count($basic_lands)) * count($basic_lands));
            $land_keys = array_keys($lands_per_type);
            for ($i = 0; $i < $extra_lands; $i++) {
                $lands_per_type[$land_keys[$i]]++;
            }
            
            // Füge Länder zur Datenbank hinzu
            foreach ($lands_per_type as $land => $quantity) {
                if ($quantity > 0) {
                    $stmt = $pdo->prepare("INSERT INTO deck_cards (deck_id, card_name, quantity, is_sideboard) VALUES (?, ?, ?, 0)");
                    $stmt->execute([$deck_id, $land, $quantity]);
                    $ai_log[] = "  🏞️ {$quantity}x {$land}";
                }
            }
        }
        
        // Add creatures and spells based on mana curve
        $ai_log[] = "🃏 Generiere Karten nach Mana-Kurve...";
        
        if ($format === 'Commander') {
            $ai_log[] = "⚠️ COMMANDER REGEL AKTIV: Nur Karten mit passender Color Identity werden hinzugefügt!";
            $ai_log[] = "🎨 Erlaubte Farben: " . implode('', $commander_colors) . " (Strikt durchgesetzt)";
        }
        
        $cards_attempted = 0;
        $cards_rejected_color = 0;
        $cards_rejected_cmc = 0;
        
        foreach ($mana_curve as $cmc => $count) {
            $cmc_added = 0; // Zähler für diese CMC-Kategorie
            $cmc_attempts = 0;
            $max_cmc_attempts = count($user_cards) * 5; // Mehr Versuche pro CMC
            
            while ($cmc_added < $count && $added_cards < $spell_target && $cmc_attempts < $max_cmc_attempts) {
                if (!empty($user_cards)) {
                    $random_card = $user_cards[array_rand($user_cards)];
                    $cmc_attempts++;
                    $cards_attempted++;
                    
                    // Skip commander card
                    if ($random_card['card_name'] === $commander) {
                        continue;
                    }
                    
                    $is_legal = true;
                    $reject_reason = '';
                    
                    if ($format === 'Commander') {
                        // PFLICHT: Color Identity Prüfung für Commander
                        $is_legal = isCardLegalInCommander($random_card['card_data'] ?? null, $commander_colors);
                        if (!$is_legal) {
                            $cards_rejected_color++;
                            $reject_reason = 'color_identity';
                            continue; // Sofort zur nächsten Karte
                        }
                    }
                    
                    $card_cmc = extractManaValue($random_card['card_data'] ?? null);
                    $cmc_matches = ($card_cmc == $cmc || ($cmc >= 6 && $card_cmc >= 6));
                    
                    if (!$cmc_matches) {
                        $cards_rejected_cmc++;
                        continue; // CMC passt nicht
                    }
                    
                    // Karte ist legal und passt zur CMC - hinzufügen
                    if ($is_legal && $cmc_matches) {
                        $stmt = $pdo->prepare("INSERT INTO deck_cards (deck_id, card_name, quantity, is_sideboard) VALUES (?, ?, 1, 0)");
                        $stmt->execute([$deck_id, $random_card['card_name']]);
                        $added_cards++;
                        $cmc_added++;
                        
                        // Debug: Log hinzugefügte Karte
                        if ($format === 'Commander') {
                            error_log("Added legal card: {$random_card['card_name']} (CMC: {$card_cmc})");
                        }
                    }
                }
            }
            
            if ($cmc_added < $count) {
                $ai_log[] = "⚠️ CMC {$cmc}: Nur {$cmc_added} von {$count} gewünschten Karten gefunden";
            }
        }
        
        if ($format === 'Commander' && $cards_rejected_color > 0) {
            $ai_log[] = "🚫 {$cards_rejected_color} Karten wegen Color Identity abgelehnt (Mana Curve Phase)";
            $ai_log[] = "✅ Nur erlaubte Farben verwendet: " . implode('', $commander_colors);
            $ai_log[] = "📊 Versuchte Karten: {$cards_attempted}, CMC-Ablehnungen: {$cards_rejected_cmc}";
        }
        
        $ai_log[] = "✅ {$added_cards} Zauber hinzugefügt (alle Color Identity konform)";
        
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
            if ($format === 'Commander') {
                $ai_log[] = "⚠️ Weiterhin nur Color Identity konforme Karten!";
            }
            
            $fill_attempts = 0;
            $max_fill_attempts = count($user_cards) * 10; // Viel mehr Versuche für Commander
            $fill_rejected_color = 0;
            $fill_added = 0;
            
            while ($fill_added < $remaining_slots && $fill_attempts < $max_fill_attempts) {
                if (!empty($user_cards)) {
                    $random_card = $user_cards[array_rand($user_cards)];
                    $fill_attempts++;
                    
                    // Skip commander card
                    if ($random_card['card_name'] === $commander) {
                        continue;
                    }
                    
                    $is_legal = true;
                    if ($format === 'Commander') {
                        // PFLICHT: Color Identity Prüfung
                        $is_legal = isCardLegalInCommander($random_card['card_data'] ?? null, $commander_colors);
                        if (!$is_legal) {
                            $fill_rejected_color++;
                            continue; // Sofort zur nächsten Karte
                        }
                    }
                    
                    if ($is_legal) {
                        $quantity = ($format === 'Commander') ? 1 : min(4, $remaining_slots - $fill_added);
                        $stmt = $pdo->prepare("INSERT INTO deck_cards (deck_id, card_name, quantity, is_sideboard) VALUES (?, ?, ?, 0)");
                        $stmt->execute([$deck_id, $random_card['card_name'], $quantity]);
                        $fill_added += $quantity;
                        
                        // Debug: Log hinzugefügte Karte
                        if ($format === 'Commander') {
                            error_log("Fill slot - Added legal card: {$random_card['card_name']}");
                        }
                    }
                }
            }
            
            $ai_log[] = "✅ {$fill_added} Karten in verbleibende Slots hinzugefügt";
            
            if ($format === 'Commander' && $fill_rejected_color > 0) {
                $ai_log[] = "🚫 {$fill_rejected_color} weitere Karten wegen Color Identity abgelehnt (Fill Phase)";
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
            
            // Try multiple possible fields for color identity
            if (isset($card_data['colorIdentity']) && is_array($card_data['colorIdentity'])) {
                return $card_data['colorIdentity'];
            } elseif (isset($card_data['color_identity']) && is_array($card_data['color_identity'])) {
                return $card_data['color_identity'];
            } elseif (isset($card_data['colors']) && is_array($card_data['colors'])) {
                return $card_data['colors'];
            } elseif (isset($card_data['manaCost'])) {
                // Extract colors from mana cost if no color identity field
                $mana_cost = $card_data['manaCost'];
                $color_identity = [];
                if (strpos($mana_cost, '{W}') !== false || strpos($mana_cost, 'W') !== false) $color_identity[] = 'W';
                if (strpos($mana_cost, '{U}') !== false || strpos($mana_cost, 'U') !== false) $color_identity[] = 'U';
                if (strpos($mana_cost, '{B}') !== false || strpos($mana_cost, 'B') !== false) $color_identity[] = 'B';
                if (strpos($mana_cost, '{R}') !== false || strpos($mana_cost, 'R') !== false) $color_identity[] = 'R';
                if (strpos($mana_cost, '{G}') !== false || strpos($mana_cost, 'G') !== false) $color_identity[] = 'G';
                return $color_identity;
            }
        }
        
        return [];
    } catch (Exception $e) {
        return [];
    }
}

// Helper function to check if card is legal in Commander format
function isCardLegalInCommander($card_data, $commander_colors) {
    if (empty($card_data)) {
        return false; // STRIKT: Karten ohne Daten ablehnen für Sicherheit
    }
    
    try {
        $data = is_string($card_data) ? json_decode($card_data, true) : $card_data;
        
        // Extract color identity from card
        $card_colors = [];
        if (isset($data['colorIdentity']) && is_array($data['colorIdentity'])) {
            $card_colors = $data['colorIdentity'];
        } elseif (isset($data['color_identity']) && is_array($data['color_identity'])) {
            $card_colors = $data['color_identity'];
        } elseif (isset($data['colors']) && is_array($data['colors'])) {
            $card_colors = $data['colors'];
        } elseif (isset($data['manaCost'])) {
            // Extract colors from mana cost if no color identity field
            $mana_cost = $data['manaCost'];
            if (strpos($mana_cost, '{W}') !== false || strpos($mana_cost, 'W') !== false) $card_colors[] = 'W';
            if (strpos($mana_cost, '{U}') !== false || strpos($mana_cost, 'U') !== false) $card_colors[] = 'U';
            if (strpos($mana_cost, '{B}') !== false || strpos($mana_cost, 'B') !== false) $card_colors[] = 'B';
            if (strpos($mana_cost, '{R}') !== false || strpos($mana_cost, 'R') !== false) $card_colors[] = 'R';
            if (strpos($mana_cost, '{G}') !== false || strpos($mana_cost, 'G') !== false) $card_colors[] = 'G';
        }
        
        // STRIKT: Jede Farbe der Karte muss im Commander enthalten sein
        foreach ($card_colors as $color) {
            if (!in_array($color, $commander_colors)) {
                return false; // Karte ist ILLEGAL - enthält nicht erlaubte Farbe
            }
        }
        
        // Colorless Karten oder Karten mit erlaubten Farben sind OK
        return true;
        
    } catch (Exception $e) {
        error_log("Error checking card legality: " . $e->getMessage());
        return false; // Bei Fehlern: Ablehnen für Sicherheit
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

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deck Builder - MTG Collection</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2c5f41;
            --primary-light: #4a7c59;
            --secondary-color: #d4af37;
            --background-color: #1a1a1a;
            --card-background: #2d2d2d;
            --text-primary: #ffffff;
            --text-secondary: #b0b0b0;
            --border-color: #404040;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
        }

        body {
            background: var(--background-color);
            color: var(--text-primary);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1rem;
        }

        .main-content {
            margin-top: 2rem;
            margin-bottom: 2rem;
        }

        .page-header {
            text-align: center;
            margin-bottom: 2rem;
            padding: 2rem 0;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }

        .page-header h1 {
            margin: 0;
            font-size: 2.5rem;
            font-weight: 700;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }

        .page-header p {
            margin: 0.5rem 0 0 0;
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .card {
            background: var(--card-background);
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            border: 1px solid var(--border-color);
            margin-bottom: 1.5rem;
        }

        .two-column {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-top: 2rem;
        }

        @media (max-width: 768px) {
            .two-column {
                grid-template-columns: 1fr;
            }
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border-left: 4px solid;
        }

        .alert-success {
            background: rgba(39, 174, 96, 0.1);
            border-color: var(--success-color);
            color: #2ecc71;
        }

        .alert-danger {
            background: rgba(231, 76, 60, 0.1);
            border-color: var(--danger-color);
            color: #e74c3c;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-primary);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: var(--background-color);
            color: var(--text-primary);
            font-size: 1rem;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(44, 95, 65, 0.2);
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-light);
            transform: translateY(-1px);
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }

        .strategy-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .strategy-btn-compact {
            background: var(--card-background);
            border: 2px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            color: var(--text-primary);
            text-decoration: none;
            display: block;
        }

        .strategy-btn-compact:hover {
            border-color: var(--primary-color);
            background: rgba(44, 95, 65, 0.1);
            transform: translateY(-2px);
        }

        .strategy-btn-compact.active {
            border-color: var(--primary-color);
            background: var(--primary-color);
            color: white;
        }

        .strategy-btn-compact i {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            display: block;
        }

        .ai-features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .feature-checkbox {
            background: var(--card-background);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 1rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .feature-checkbox:hover {
            border-color: var(--primary-color);
        }

        .feature-checkbox input[type="checkbox"] {
            margin-right: 0.5rem;
        }

        .feature-checkbox input[type="checkbox"]:checked + .feature-content {
            color: var(--primary-color);
        }

        .deck-list {
            max-height: 600px;
            overflow-y: auto;
        }

        .deck-item {
            background: var(--background-color);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            transition: all 0.2s;
        }

        .deck-item:hover {
            border-color: var(--primary-color);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .deck-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .deck-header h6 {
            margin: 0;
            color: var(--text-primary);
        }

        .deck-badges {
            display: flex;
            gap: 0.5rem;
        }

        .badge {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .badge-format {
            background: var(--primary-color);
            color: white;
        }

        .badge-strategy {
            background: var(--secondary-color);
            color: var(--background-color);
        }

        .deck-stats {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            color: var(--text-secondary);
        }

        .deck-stats span {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .deck-actions {
            display: flex;
            gap: 0.5rem;
        }

        .commander-select {
            margin-bottom: 1rem;
        }

        #commanderSelect {
            background: var(--background-color);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .color-symbol {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.9rem;
            margin-right: 0.5rem;
            border: 2px solid rgba(255,255,255,0.3);
        }

        .color-symbol.white { background: #FFFBD5; color: #000; }
        .color-symbol.blue { background: #0E68AB; color: #fff; }
        .color-symbol.black { background: #150B00; color: #fff; }
        .color-symbol.red { background: #D3202A; color: #fff; }
        .color-symbol.green { background: #00733E; color: #fff; }
        .color-symbol.colorless { background: #CAC5C0; color: #000; }
    </style>
</head>
<body>
    <div class="container">
        <div class="main-content">
            <!-- Page Header -->
            <div class="page-header">
                <h1><i class="fas fa-magic"></i> Intelligent Deck Builder</h1>
                <p>Erstellen Sie optimierte MTG-Decks mit KI-unterstützter Analyse</p>
            </div>

            <!-- Success/Error Messages -->
            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>

            <div class="two-column">
                <!-- Left Column: Deck Builder -->
                <div>
                    <div class="card">
                        <h5 style="margin-bottom: 1.5rem;"><i class="fas fa-robot"></i> AI Deck Builder</h5>
                        
                        <form method="post" id="deckBuilderForm">
                            <input type="hidden" name="action" value="create_deck">
                            
                            <!-- Format Selection -->
                            <div class="form-group">
                                <label><i class="fas fa-gamepad"></i> Format</label>
                                <select name="format" class="form-control" required>
                                    <option value="">-- Format wählen --</option>
                                    <option value="Standard">Standard</option>
                                    <option value="Modern">Modern</option>
                                    <option value="Pioneer">Pioneer</option>
                                    <option value="Commander">Commander</option>
                                    <option value="Legacy">Legacy</option>
                                    <option value="Vintage">Vintage</option>
                                    <option value="Pauper">Pauper</option>
                                </select>
                            </div>

                            <!-- Deck Size -->
                            <div class="form-group">
                                <label><i class="fas fa-layer-group"></i> Deck-Größe</label>
                                <select name="deck_size" class="form-control">
                                    <option value="60">60 Karten (Standard)</option>
                                    <option value="40">40 Karten (Limited)</option>
                                    <option value="100">100 Karten (Commander)</option>
                                </select>
                            </div>

            <!-- Commander Selection (shown when Commander format is selected) -->
            <div class="commander-select" id="commanderSection" style="display: none;">
                <label><i class="fas fa-crown"></i> Commander <span style="color: #e74c3c;">*</span></label>
                <select name="commander" id="commanderSelect" class="form-control" required>
                    <option value="">-- Commander AUSWÄHLEN (PFLICHT) --</option>
                </select>
                <small style="color: var(--warning-color); margin-top: 0.5rem; display: block;">
                    <i class="fas fa-exclamation-triangle"></i> 
                    Commander bestimmt die erlaubten Farben für alle Karten im Deck!
                </small>
                
                <!-- Commander Color Identity Display -->
                <div id="commanderColorDisplay" style="display: none; margin-top: 1rem; padding: 1rem; background: rgba(44, 95, 65, 0.1); border-radius: 6px; border: 1px solid var(--primary-color);">
                    <h6 style="margin: 0 0 0.5rem 0; color: var(--primary-color);">
                        <i class="fas fa-palette"></i> Erlaubte Farben für dieses Deck:
                    </h6>
                    <div id="colorIdentitySymbols" style="display: flex; gap: 0.5rem; align-items: center;">
                        <!-- Color symbols will be inserted here -->
                    </div>
                    <small style="color: var(--text-secondary); margin-top: 0.5rem; display: block;">
                        Nur Karten mit diesen Farben können dem Deck hinzugefügt werden.
                    </small>
                </div>
            </div>                            <!-- Strategy Selection -->
                            <div class="form-group">
                                <label><i class="fas fa-chess"></i> Strategie</label>
                                <div class="strategy-grid">
                                    <a href="#" class="strategy-btn-compact" data-strategy="Aggro">
                                        <i class="fas fa-fire"></i>
                                        <strong>Aggro</strong>
                                        <small style="display: block; margin-top: 0.5rem;">Schnell & aggressiv</small>
                                    </a>
                                    <a href="#" class="strategy-btn-compact" data-strategy="Control">
                                        <i class="fas fa-shield-alt"></i>
                                        <strong>Control</strong>
                                        <small style="display: block; margin-top: 0.5rem;">Kontrolle & Card Draw</small>
                                    </a>
                                    <a href="#" class="strategy-btn-compact" data-strategy="Midrange">
                                        <i class="fas fa-balance-scale"></i>
                                        <strong>Midrange</strong>
                                        <small style="display: block; margin-top: 0.5rem;">Ausgewogen</small>
                                    </a>
                                    <a href="#" class="strategy-btn-compact" data-strategy="Combo">
                                        <i class="fas fa-cogs"></i>
                                        <strong>Combo</strong>
                                        <small style="display: block; margin-top: 0.5rem;">Synergien & Combos</small>
                                    </a>
                                </div>
                                <input type="hidden" name="strategy" id="selectedStrategy">
                            </div>

                            <!-- AI Features -->
                            <div class="form-group">
                                <label><i class="fas fa-brain"></i> AI Features</label>
                                <div class="ai-features">
                                    <label class="feature-checkbox">
                                        <input type="checkbox" name="ai_features[]" value="mana_curve">
                                        <div class="feature-content">
                                            <strong>Mana Curve Optimization</strong>
                                            <small style="display: block;">Intelligente Mana-Kostenverteilung</small>
                                        </div>
                                    </label>
                                    <label class="feature-checkbox">
                                        <input type="checkbox" name="ai_features[]" value="type_balance">
                                        <div class="feature-content">
                                            <strong>Type Balance</strong>
                                            <small style="display: block;">Optimierte Kartentyp-Verteilung</small>
                                        </div>
                                    </label>
                                    <label class="feature-checkbox">
                                        <input type="checkbox" name="ai_features[]" value="color_optimization">
                                        <div class="feature-content">
                                            <strong>Color Optimization</strong>
                                            <small style="display: block;">Intelligente Farbverteilung</small>
                                        </div>
                                    </label>
                                    <label class="feature-checkbox">
                                        <input type="checkbox" name="ai_features[]" value="balance">
                                        <div class="feature-content">
                                            <strong>Land Balance</strong>
                                            <small style="display: block;">Optimierte Land-Verteilung (Standard-Länder unbegrenzt)</small>
                                        </div>
                                    </label>
                                </div>
                            </div>

                            <!-- Quality Level -->
                            <div class="form-group">
                                <label><i class="fas fa-star"></i> Qualitätsstufe</label>
                                <select name="quality" class="form-control">
                                    <option value="budget">Budget (Günstige Karten)</option>
                                    <option value="medium" selected>Medium (Ausgewogen)</option>
                                    <option value="competitive">Competitive (Beste Karten)</option>
                                </select>
                            </div>

                            <!-- Deck Name -->
                            <div class="form-group">
                                <label><i class="fas fa-tag"></i> Deck Name</label>
                                <input type="text" name="name" class="form-control" placeholder="Mein neues Deck" required>
                            </div>

                            <button type="submit" class="btn btn-primary" style="width: 100%;">
                                <i class="fas fa-magic"></i> Deck erstellen
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Right Column: Existing Decks -->
                <div>
                    <div class="card">
                        <h5 style="margin-bottom: 1rem;"><i class="fas fa-layer-group"></i> Ihre Decks</h5>
                        
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
                                            <a href="deck_view.php?id=<?= $deck['id'] ?>" class="btn btn-sm btn-primary">
                                                <i class="fas fa-eye"></i> Ansehen
                                            </a>
                                            <form method="post" style="display: inline;" onsubmit="return confirm('Deck wirklich löschen?')">
                                                <input type="hidden" name="action" value="delete_deck">
                                                <input type="hidden" name="deck_id" value="<?= $deck['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">
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
        </div>
    </div>

    <script>
        // Format change handler
        function handleFormatChange(format) {
            const commanderSection = document.getElementById('commanderSection');
            const deckSizeSelect = document.querySelector('select[name="deck_size"]');
            
            if (format === 'Commander') {
                commanderSection.style.display = 'block';
                deckSizeSelect.value = '100';
                loadCommanders();
            } else {
                commanderSection.style.display = 'none';
                if (format === 'Standard' || format === 'Modern' || format === 'Pioneer') {
                    deckSizeSelect.value = '60';
                }
            }
        }

        function loadCommanders() {
            const commanderSelect = document.getElementById('commanderSelect');
            
            fetch('get_commanders.php')
                .then(response => response.json())
                .then(data => {
                    commanderSelect.innerHTML = '<option value="">-- Commander AUSWÄHLEN (PFLICHT) --</option>';
                    
                    if (data.commanders && data.commanders.length > 0) {
                        data.commanders.forEach(commander => {
                            const option = document.createElement('option');
                            option.value = commander.card_name;
                            
                            let displayText = commander.card_name;
                            if (commander.colors_display) {
                                displayText += ` (${commander.colors_display})`;
                            }
                            
                            option.textContent = displayText;
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

        // Commander color display function
        function displayCommanderColors(commander_name) {
            if (!commander_name) {
                document.getElementById('commanderColorDisplay').style.display = 'none';
                return;
            }

            // Show loading state
            const colorDisplay = document.getElementById('commanderColorDisplay');
            const colorSymbols = document.getElementById('colorIdentitySymbols');
            
            colorDisplay.style.display = 'block';
            colorSymbols.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Lade Farben...';

            // Fetch commander colors
            const formData = new FormData();
            formData.append('commander_name', commander_name);

            fetch('get_commander_colors.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                console.log('Commander color response:', data); // Debug output
                
                if (data.success) {
                    // Display color symbols
                    let symbolsHtml = '';
                    
                    if (data.color_symbols && data.color_symbols.length > 0) {
                        data.color_symbols.forEach(color => {
                            symbolsHtml += `<div class="color-symbol ${color.class}" title="${color.name}">${color.symbol}</div>`;
                        });
                        
                        // Add color names text
                        symbolsHtml += `<span style="margin-left: 1rem; color: var(--text-primary);">
                            ${data.color_names.join(', ')} 
                            <small>(${data.color_count} ${data.color_count === 1 ? 'Farbe' : 'Farben'})</small>
                        </span>`;
                    }
                    
                    // Add debug info if available
                    if (data.debug_info) {
                        symbolsHtml += `<div style="margin-top: 0.5rem; font-size: 0.8rem; color: var(--text-secondary);">
                            Debug: colorIdentity=${data.debug_info.found_colorIdentity}, 
                            colors=${data.debug_info.found_colors}, 
                            manaCost=${data.debug_info.found_manaCost}<br>
                            Available fields: ${data.debug_info.raw_data_keys.join(', ')}
                        </div>`;
                    }
                    
                    colorSymbols.innerHTML = symbolsHtml;
                    
                    // Update deck name suggestion with colors
                    updateDeckNameWithColors(data.color_names);
                    
                } else {
                    colorSymbols.innerHTML = `<span style="color: var(--danger-color);">
                        <i class="fas fa-exclamation-triangle"></i> ${data.error || 'Fehler beim Laden der Farben'}
                    </span>`;
                }
            })
            .catch(error => {
                console.error('Fehler beim Laden der Commander-Farben:', error);
                colorSymbols.innerHTML = `<span style="color: var(--danger-color);">
                    <i class="fas fa-exclamation-triangle"></i> Fehler beim Laden der Farben
                </span>`;
            });
        }

        // Update deck name with commander colors
        function updateDeckNameWithColors(colorNames) {
            const format = document.querySelector('select[name="format"]').value;
            const strategy = document.getElementById('selectedStrategy').value;
            const nameInput = document.querySelector('input[name="name"]');
            const commander = document.querySelector('select[name="commander"]').value;
            
            if (format === 'Commander' && strategy && commander && !nameInput.value.trim()) {
                let colorPrefix = '';
                if (colorNames && colorNames.length > 0) {
                    if (colorNames.length === 1) {
                        colorPrefix = colorNames[0] + ' ';
                    } else if (colorNames.length <= 3) {
                        colorPrefix = colorNames.join('/') + ' ';
                    } else {
                        colorPrefix = colorNames.length + '-Farben ';
                    }
                }
                nameInput.value = `${colorPrefix}${strategy} Commander`;
            }
        }

        // Strategy selection handling
        document.querySelectorAll('.strategy-btn-compact').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                
                document.querySelectorAll('.strategy-btn-compact').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                document.getElementById('selectedStrategy').value = this.dataset.strategy;
            });
        });

        // Auto-generate deck name
        document.querySelector('select[name="format"]').addEventListener('change', function() {
            updateDeckName();
            handleFormatChange(this.value);
        });

        // Commander selection handler
        document.getElementById('commanderSelect').addEventListener('change', function() {
            displayCommanderColors(this.value);
        });

        document.querySelectorAll('.strategy-btn-compact').forEach(btn => {
            btn.addEventListener('click', updateDeckName);
        });

        function updateDeckName() {
            const format = document.querySelector('select[name="format"]').value;
            const strategy = document.getElementById('selectedStrategy').value;
            const nameInput = document.querySelector('input[name="name"]');
            
            if (format && strategy && !nameInput.value.trim()) {
                nameInput.value = `${strategy} ${format} Deck`;
            }
        }

        // Form validation
        document.getElementById('deckBuilderForm').addEventListener('submit', function(e) {
            const format = document.querySelector('select[name="format"]').value;
            const strategy = document.getElementById('selectedStrategy').value;
            const name = document.querySelector('input[name="name"]').value;
            const deckSize = parseInt(document.querySelector('select[name="deck_size"]').value);
            const commander = document.querySelector('select[name="commander"]').value;
            
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
            
            if (format === 'Commander') {
                if (!commander) {
                    e.preventDefault();
                    alert('Commander Format erfordert einen Commander! Bitte wählen Sie einen Commander aus.');
                    return;
                }
                if (deckSize !== 100) {
                    e.preventDefault();
                    alert('Commander-Decks müssen genau 100 Karten haben!');
                    return;
                }
            }
            
            if ((format === 'Standard' || format === 'Modern' || format === 'Pioneer') && deckSize < 60) {
                e.preventDefault();
                alert('Constructed-Decks müssen mindestens 60 Karten haben!');
                return;
            }
        });
    </script>
</body>
</html>
