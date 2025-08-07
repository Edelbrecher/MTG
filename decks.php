<?php
// Debug: Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// Debug: Check session
echo "<!-- DEBUG: Session started -->";
if (isset($_SESSION['user_id'])) {
    echo "<!-- DEBUG: User ID: " . $_SESSION['user_id'] . " -->";
} else {
    echo "<!-- DEBUG: No user session found -->";
}

try {
    require_once 'config/database.php';
    echo "<!-- DEBUG: Database connected -->";
} catch (Exception $e) {
    echo "<!-- DEBUG: Database error: " . $e->getMessage() . " -->";
    die("Database connection failed: " . $e->getMessage());
}

if (!isset($_SESSION['user_id'])) {
    echo "<!-- DEBUG: Redirecting to login -->";
    header('Location: auth/login.php');
    exit();
}

$success_message = '';
$error_message = '';

echo "<!-- DEBUG: Variables initialized -->";

// Debug: Show if we have a session
if (!isset($_SESSION['user_id'])) {
    $error_message = 'Debug: Keine Session gefunden';
    echo "<!-- DEBUG: No session error set -->";
} else {
    echo "<!-- DEBUG: Session OK, User ID: " . $_SESSION['user_id'] . " -->";
}

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
        $deck_colors = $_POST['deck_colors'] ?? [];
        
        if (empty($name)) {
            throw new Exception('Deck-Name ist erforderlich');
        }
        
        // Format-spezifische Validierung
        if ($format === 'Commander' && $deck_size != 100) {
            throw new Exception('Commander-Decks müssen genau 100 Karten haben');
        }
        
        if (in_array($format, ['Modern', 'Standard', 'Pioneer']) && $deck_size < 60) {
            throw new Exception($format . '-Decks müssen mindestens 60 Karten haben');
        }
        
        if ($format === 'Commander' && empty($commander)) {
            throw new Exception('Commander-Format erfordert einen Commander');
        }
        
        // Validate deck colors for Modern, Standard, Pioneer
        if (in_array($format, ['Modern', 'Standard', 'Pioneer']) && empty($deck_colors)) {
            throw new Exception($format . '-Format erfordert die Auswahl mindestens einer Deck-Farbe');
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
                'color_focus' => $color_focus,
                'deck_colors' => $deck_colors
            ], $ai_log);
            
            // Prüfe ob Deck-Generierung erfolgreich war
            if (!$generation_result['success']) {
                // Lösche fehlerhaftes Deck
                $stmt = $pdo->prepare("DELETE FROM deck_cards WHERE deck_id = ?");
                $stmt->execute([$deck_id]);
                $stmt = $pdo->prepare("DELETE FROM decks WHERE id = ?");
                $stmt->execute([$deck_id]);
                
                throw new Exception("Deck-Generierung fehlgeschlagen: " . ($generation_result['error'] ?? 'Unbekannter Fehler'));
            }
            
            // Erstelle detaillierte Erfolgsmeldung
            $feature_count = count($ai_features);
            $ai_summary = implode(', ', $ai_log);
            $success_message = "Deck wurde erfolgreich erstellt mit {$feature_count} AI-Features! 🤖 " . $ai_summary;
            
            // Zeige Validierungsergebnis
            if (isset($generation_result['validation']) && !$generation_result['validation']['success']) {
                $success_message .= " ⚠️ Validierung: " . $generation_result['validation']['error'];
            } elseif (isset($generation_result['validation']['stats'])) {
                $success_message .= " ✅ Validiert: " . $generation_result['validation']['stats'];
            }
            
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
    
    // Debug output
    if (empty($existing_decks)) {
        $error_message .= ' Debug: Keine Decks gefunden für User ID ' . $_SESSION['user_id'];
    } else {
        $success_message .= ' Debug: ' . count($existing_decks) . ' Decks gefunden';
    }
    
} catch (Exception $e) {
    $existing_decks = [];
    $error_message .= ' Debug: Datenbankfehler beim Laden der Decks: ' . $e->getMessage();
}

// Enhanced AI Deck Generation Function with intelligent monitoring
function generateEnhancedAIDeck($pdo, $deck_id, $strategy, $format, $quality, $user_id, $options = [], &$ai_log = []) {
    $commander = $options['commander'] ?? '';
    $ai_features = $options['ai_features'] ?? [];
    $deck_size = $options['deck_size'] ?? 60;
    $color_focus = $options['color_focus'] ?? '';
    $deck_colors = $options['deck_colors'] ?? [];
    
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
        } else {
            // For Modern, Standard, Pioneer - use selected deck colors
            if (!empty($deck_colors)) {
                $commander_colors = $deck_colors; // Use selected colors as color restriction
                $ai_log[] = "🎨 Deck-Farben: " . implode('', $commander_colors) . " (gewählt für {$format})";
            } else {
                $commander_colors = []; // No color restrictions for other formats
                $ai_log[] = "🌈 Keine Farbbeschränkungen für {$format}-Format";
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
        } elseif ($format === 'Commander') {
            // Commander IMMER mit Ländern - auch ohne Balance-Feature
            $land_count = 36;
            $ai_log[] = "🏞️ Commander-Format: {$land_count} Länder automatisch hinzugefügt";
        } else {
            // Für andere Formate: Standard-Länder auch ohne Balance-Feature
            $land_count = round($actual_deck_size * 0.4); // 24 Länder für 60-Karten-Deck
            $ai_log[] = "🏞️ {$format}-Format: {$land_count} Länder automatisch hinzugefügt";
        }
        
        $non_land_slots = $actual_deck_size - $land_count;
        $spell_target = min($non_land_slots, count($user_cards) * 2);
        $added_cards = 0;
        
        // Add lands first if balance feature is enabled or for any format
        if ($land_count > 0) {
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
            } elseif (in_array($format, ['Modern', 'Standard', 'Pioneer']) && !empty($commander_colors)) {
                // For Modern/Standard/Pioneer with selected colors
                $available_basics = [];
                if (in_array('W', $commander_colors)) $available_basics[] = 'Plains';
                if (in_array('U', $commander_colors)) $available_basics[] = 'Island';
                if (in_array('B', $commander_colors)) $available_basics[] = 'Swamp';
                if (in_array('R', $commander_colors)) $available_basics[] = 'Mountain';
                if (in_array('G', $commander_colors)) $available_basics[] = 'Forest';
                
                $basic_lands = $available_basics;
                $ai_log[] = "🎨 {$format} Deck-Farben: " . implode(', ', $available_basics);
            } else {
                // Für andere Formate: Alle Standard-Länder verfügbar
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
                    } elseif (in_array($format, ['Modern', 'Standard', 'Pioneer']) && !empty($commander_colors)) {
                        // Color restriction for Modern/Standard/Pioneer based on selected colors
                        $is_legal = isCardLegalInCommander($random_card['card_data'] ?? null, $commander_colors);
                        if (!$is_legal) {
                            $cards_rejected_color++;
                            $reject_reason = 'deck_colors';
                            continue; // Skip card that doesn't match selected colors
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
                        $quantity = ($format === 'Commander') ? 1 : min(4, $spell_target - $added_cards);
                        $stmt = $pdo->prepare("INSERT INTO deck_cards (deck_id, card_name, quantity, is_sideboard) VALUES (?, ?, ?, 0)");
                        $stmt->execute([$deck_id, $random_card['card_name'], $quantity]);
                        $added_cards += $quantity;
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
        
        // Validate deck creation and format rules
        $validation_result = validateCreatedDeck($pdo, $deck_id, $format, $actual_deck_size + ($format === 'Commander' ? 1 : 0), $ai_log);
        
        if (!$validation_result['success']) {
            $warnings[] = "Deck-Validierung fehlgeschlagen: " . $validation_result['error'];
            $ai_log[] = "❌ Deck-Validierung: " . $validation_result['error'];
        } else {
            $ai_log[] = "✅ Deck-Validierung erfolgreich: " . $validation_result['stats'];
        }
        
        return [
            'success' => true,
            'cards_added' => $added_cards,
            'lands_added' => $land_count,
            'total_cards' => $final_count,
            'warnings' => $warnings,
            'mana_curve_analysis' => $mana_curve_analysis,
            'ai_steps' => $ai_log,
            'validation' => $validation_result
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

// Deck validation function to check if deck was created successfully and follows format rules
function validateCreatedDeck($pdo, $deck_id, $format, $expected_size, &$ai_log) {
    try {
        // Check if deck exists
        $stmt = $pdo->prepare("SELECT COUNT(*) as deck_exists FROM decks WHERE id = ?");
        $stmt->execute([$deck_id]);
        $deck_exists = $stmt->fetchColumn();
        
        if (!$deck_exists) {
            return [
                'success' => false,
                'error' => 'Deck wurde nicht in der Datenbank erstellt'
            ];
        }
        
        // Get deck cards count
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(DISTINCT card_name) as unique_cards,
                SUM(quantity) as total_cards,
                COUNT(CASE WHEN is_commander = 1 THEN 1 END) as commanders
            FROM deck_cards 
            WHERE deck_id = ? AND is_sideboard = 0
        ");
        $stmt->execute([$deck_id]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $validation_errors = [];
        
        // Format-specific validation
        switch ($format) {
            case 'Commander':
                // Must have exactly 100 cards
                if ($stats['total_cards'] != 100) {
                    $validation_errors[] = "Commander-Deck muss 100 Karten haben (aktuell: {$stats['total_cards']})";
                }
                // Must have exactly 1 commander
                if ($stats['commanders'] != 1) {
                    $validation_errors[] = "Commander-Deck muss genau 1 Commander haben (aktuell: {$stats['commanders']})";
                }
                break;
                
            case 'Modern':
            case 'Standard':
            case 'Pioneer':
                // Must have at least 60 cards
                if ($stats['total_cards'] < 60) {
                    $validation_errors[] = "{$format}-Deck muss mindestens 60 Karten haben (aktuell: {$stats['total_cards']})";
                }
                // Should not have commander
                if ($stats['commanders'] > 0) {
                    $validation_errors[] = "{$format}-Deck darf keinen Commander haben";
                }
                break;
                
            default:
                // Generic validation
                if ($stats['total_cards'] < 40) {
                    $validation_errors[] = "Deck zu klein (aktuell: {$stats['total_cards']})";
                }
        }
        
        // Check if deck has cards at all
        if ($stats['total_cards'] == 0) {
            return [
                'success' => false,
                'error' => 'Deck wurde ohne Karten erstellt'
            ];
        }
        
        $stats_text = "{$stats['unique_cards']} unique, {$stats['total_cards']} total";
        if ($stats['commanders'] > 0) {
            $stats_text .= ", {$stats['commanders']} commander";
        }
        
        if (!empty($validation_errors)) {
            return [
                'success' => false,
                'error' => implode('; ', $validation_errors),
                'stats' => $stats_text
            ];
        }
        
        return [
            'success' => true,
            'stats' => $stats_text,
            'details' => $stats
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'Validierung fehlgeschlagen: ' . $e->getMessage()
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
    <title>Deck Builder - MTG Collection Manager</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/svg+xml" href="assets/images/favicon.svg">
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Debug: Test if CSS is loading */
        body { 
            background-color: #f8fafc !important; 
        }
        
        /* Deck-specific styles to complement the main style.css */
        .strategy-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .strategy-btn-compact {
            background: var(--surface-color);
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
            background: rgba(37, 99, 235, 0.05);
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
            background: var(--surface-color);
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
            background: var(--surface-color);
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
            background: var(--warning-color);
            color: white;
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

        .color-symbol.white { background: var(--white-mana); color: #000; }
        .color-symbol.blue { background: var(--blue-mana); color: #fff; }
        .color-symbol.black { background: var(--black-mana); color: #fff; }
        .color-symbol.red { background: var(--red-mana); color: #fff; }
        .color-symbol.green { background: var(--green-mana); color: #fff; }
        .color-symbol.colorless { background: var(--colorless); color: #000; }

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
            background: rgba(5, 150, 105, 0.1);
            border-color: var(--success-color);
            color: var(--success-color);
        }

        .alert-danger {
            background: rgba(220, 38, 38, 0.1);
            border-color: var(--danger-color);
            color: var(--danger-color);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        .color-checkbox:hover {
            border-color: var(--primary-color);
            background: rgba(37, 99, 235, 0.05);
        }

        .color-checkbox input[type="checkbox"]:checked + .color-symbol + span {
            color: var(--primary-color);
            font-weight: 600;
        }

        .color-checkbox input[type="checkbox"]:checked {
            accent-color: var(--primary-color);
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="container">
        <div class="main-content">
            <!-- DEBUG OUTPUT -->
            <div style="background: #ffeb3b; padding: 1rem; margin: 1rem 0; border-radius: 4px;">
                <strong>DEBUG INFO:</strong><br>
                Session User ID: <?= $_SESSION['user_id'] ?? 'NICHT GESETZT' ?><br>
                Existing Decks Count: <?= count($existing_decks ?? []) ?><br>
                Success Message: <?= htmlspecialchars($success_message) ?><br>
                Error Message: <?= htmlspecialchars($error_message) ?><br>
                PHP Version: <?= phpversion() ?>
            </div>
            
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">Intelligent Deck Builder</h1>
                <p class="page-subtitle">Erstellen Sie optimierte MTG-Decks mit KI-unterstützter Analyse</p>
            </div>

            <!-- Success/Error Messages -->
            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <?= htmlspecialchars($success_message) ?>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-danger">
                    <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>

            <div class="two-column">
                <!-- Left Column: Deck Builder -->
                <div>
                    <div class="card">
                        <div class="card-header">
                            AI Deck Builder
                        </div>
                        <div class="card-body">
                        
                        <form method="post" id="deckBuilderForm">
                            <input type="hidden" name="action" value="create_deck">
                            
                            <!-- Format Selection -->
                            <div class="form-group">
                                <label>Format</label>
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
                                <label>Deck-Größe</label>
                                <select name="deck_size" class="form-control">
                                    <option value="60">60 Karten (Standard)</option>
                                    <option value="40">40 Karten (Limited)</option>
                                    <option value="100">100 Karten (Commander)</option>
                                </select>
                            </div>

            <!-- Commander Selection (shown when Commander format is selected) -->
            <div class="commander-select" id="commanderSection" style="display: none;">
                <label>Commander <span style="color: var(--danger-color);">*</span></label>
                <select name="commander" id="commanderSelect" class="form-control">
                    <option value="">-- Commander AUSWÄHLEN (PFLICHT) --</option>
                </select>
                <small style="color: var(--warning-color); margin-top: 0.5rem; display: block;">
                    Commander bestimmt die erlaubten Farben für alle Karten im Deck!
                </small>
                
                <!-- Commander Color Identity Display -->
                <div id="commanderColorDisplay" style="display: none; margin-top: 1rem; padding: 1rem; background: rgba(37, 99, 235, 0.1); border-radius: 6px; border: 1px solid var(--primary-color);">
                    <h6 style="margin: 0 0 0.5rem 0; color: var(--primary-color);">
                        Erlaubte Farben für dieses Deck:
                    </h6>
                    <div id="colorIdentitySymbols" style="display: flex; gap: 0.5rem; align-items: center;">
                        <!-- Color symbols will be inserted here -->
                    </div>
                    <small style="color: var(--text-secondary); margin-top: 0.5rem; display: block;">
                        Nur Karten mit diesen Farben können dem Deck hinzugefügt werden.
                    </small>
                </div>
            </div>

            <!-- Color Selection (shown for Modern/Standard/Pioneer formats) -->
            <div class="color-selection" id="colorSelectionSection" style="display: none;">
                <label>Deck-Farben <span style="color: var(--danger-color);">*</span></label>
                <div class="color-options" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 0.5rem; margin-bottom: 1rem;">
                    <label class="color-checkbox" style="display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 6px; cursor: pointer; transition: all 0.2s;">
                        <input type="checkbox" name="deck_colors[]" value="W" style="margin: 0;">
                        <div class="color-symbol white" style="width: 25px; height: 25px; font-size: 0.8rem;">W</div>
                        <span>Weiß</span>
                    </label>
                    <label class="color-checkbox" style="display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 6px; cursor: pointer; transition: all 0.2s;">
                        <input type="checkbox" name="deck_colors[]" value="U" style="margin: 0;">
                        <div class="color-symbol blue" style="width: 25px; height: 25px; font-size: 0.8rem;">U</div>
                        <span>Blau</span>
                    </label>
                    <label class="color-checkbox" style="display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 6px; cursor: pointer; transition: all 0.2s;">
                        <input type="checkbox" name="deck_colors[]" value="B" style="margin: 0;">
                        <div class="color-symbol black" style="width: 25px; height: 25px; font-size: 0.8rem;">B</div>
                        <span>Schwarz</span>
                    </label>
                    <label class="color-checkbox" style="display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 6px; cursor: pointer; transition: all 0.2s;">
                        <input type="checkbox" name="deck_colors[]" value="R" style="margin: 0;">
                        <div class="color-symbol red" style="width: 25px; height: 25px; font-size: 0.8rem;">R</div>
                        <span>Rot</span>
                    </label>
                    <label class="color-checkbox" style="display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 6px; cursor: pointer; transition: all 0.2s;">
                        <input type="checkbox" name="deck_colors[]" value="G" style="margin: 0;">
                        <div class="color-symbol green" style="width: 25px; height: 25px; font-size: 0.8rem;">G</div>
                        <span>Grün</span>
                    </label>
                </div>
                <small style="color: var(--text-secondary); margin-top: 0.5rem; display: block;">
                    Wählen Sie mindestens eine Farbe für Ihr Deck aus. Sie können mehrere Farben kombinieren.
                </small>
            </div>                            <!-- Strategy Selection -->
                            <div class="form-group">
                                <label>Strategie</label>
                                <div class="strategy-grid">
                                    <a href="#" class="strategy-btn-compact" data-strategy="Aggro">
                                        <strong>Aggro</strong>
                                        <small style="display: block; margin-top: 0.5rem;">Schnell & aggressiv</small>
                                    </a>
                                    <a href="#" class="strategy-btn-compact" data-strategy="Control">
                                        <strong>Control</strong>
                                        <small style="display: block; margin-top: 0.5rem;">Kontrolle & Card Draw</small>
                                    </a>
                                    <a href="#" class="strategy-btn-compact" data-strategy="Midrange">
                                        <strong>Midrange</strong>
                                        <small style="display: block; margin-top: 0.5rem;">Ausgewogen</small>
                                    </a>
                                    <a href="#" class="strategy-btn-compact" data-strategy="Combo">
                                        <strong>Combo</strong>
                                        <small style="display: block; margin-top: 0.5rem;">Synergien & Combos</small>
                                    </a>
                                </div>
                                <input type="hidden" name="strategy" id="selectedStrategy">
                            </div>

                            <!-- AI Features -->
                            <div class="form-group">
                                <label>AI Features</label>
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
                                <label>Qualitätsstufe</label>
                                <select name="quality" class="form-control">
                                    <option value="budget">Budget (Günstige Karten)</option>
                                    <option value="medium" selected>Medium (Ausgewogen)</option>
                                    <option value="competitive">Competitive (Beste Karten)</option>
                                </select>
                            </div>

                            <!-- Deck Name -->
                            <div class="form-group">
                                <label>Deck Name</label>
                                <input type="text" name="name" class="form-control" placeholder="Mein neues Deck" required>
                            </div>

                            <button type="submit" class="btn btn-primary" style="width: 100%;">
                                Deck erstellen
                            </button>
                        </form>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Existing Decks -->
                <div>
                    <div class="card">
                        <div class="card-header">
                            Ihre Decks
                        </div>
                        <div class="card-body">
                        
                        <?php if (empty($existing_decks)): ?>
                            <div style="text-align: center; padding: 2rem; color: var(--text-secondary);">
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
                                            <span><?= $deck['card_count'] ?: 0 ?> unique</span>
                                            <span><?= $deck['total_cards'] ?: 0 ?> total</span>
                                            <span><?= date('d.m.Y', strtotime($deck['created_at'])) ?></span>
                                        </div>
                                        
                                        <div class="deck-actions">
                                            <a href="deck_view.php?id=<?= $deck['id'] ?>" class="btn btn-sm btn-primary">
                                                Ansehen
                                            </a>
                                            <form method="post" style="display: inline;" onsubmit="return confirm('Deck wirklich löschen?')">
                                                <input type="hidden" name="action" value="delete_deck">
                                                <input type="hidden" name="deck_id" value="<?= $deck['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">
                                                    Löschen
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
    </div>

    <script>
        console.log('Deck Builder JavaScript lädt...');
        
        // Format change handler
        function handleFormatChange(format) {
            const commanderSection = document.getElementById('commanderSection');
            const commanderSelect = document.getElementById('commanderSelect');
            const colorSelectionSection = document.getElementById('colorSelectionSection');
            const deckSizeSelect = document.querySelector('select[name="deck_size"]');
            
            if (format === 'Commander') {
                commanderSection.style.display = 'block';
                commanderSelect.setAttribute('required', 'required');
                colorSelectionSection.style.display = 'none';
                clearColorSelection();
                deckSizeSelect.value = '100';
                loadCommanders();
            } else if (format === 'Modern' || format === 'Standard' || format === 'Pioneer') {
                commanderSection.style.display = 'none';
                commanderSelect.removeAttribute('required');
                commanderSelect.value = '';
                colorSelectionSection.style.display = 'block';
                deckSizeSelect.value = '60';
            } else {
                // Legacy, Vintage, Pauper - keine spezifischen Anforderungen
                commanderSection.style.display = 'none';
                commanderSelect.removeAttribute('required');
                commanderSelect.value = '';
                colorSelectionSection.style.display = 'none';
                clearColorSelection();
                if (format) {
                    deckSizeSelect.value = '60';
                }
            }
        }

        // Clear color selection
        function clearColorSelection() {
            const colorCheckboxes = document.querySelectorAll('input[name="deck_colors[]"]');
            colorCheckboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
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
            
            // Color validation for Modern, Standard, Pioneer
            if (format === 'Modern' || format === 'Standard' || format === 'Pioneer') {
                const selectedColors = document.querySelectorAll('input[name="deck_colors[]"]:checked');
                if (selectedColors.length === 0) {
                    e.preventDefault();
                    alert('Bitte wählen Sie mindestens eine Farbe für Ihr ' + format + '-Deck aus!');
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
