<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Kartendaten von Scryfall API holen mit verbesserter Suche
function fetchCardData($card_name) {
    // Erst exakte Suche versuchen
    $url = "https://api.scryfall.com/cards/named?exact=" . urlencode($card_name);
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'user_agent' => 'MTG Collection Manager/1.0'
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response !== false) {
        $data = json_decode($response, true);
        
        if ($data && (!isset($data['object']) || $data['object'] !== 'error')) {
            return extractCardData($data);
        }
    }
    
    // Falls exakte Suche fehlschlägt, fuzzy search versuchen
    $fuzzy_url = "https://api.scryfall.com/cards/named?fuzzy=" . urlencode($card_name);
    $fuzzy_response = @file_get_contents($fuzzy_url, false, $context);
    
    if ($fuzzy_response !== false) {
        $fuzzy_data = json_decode($fuzzy_response, true);
        
        if ($fuzzy_data && (!isset($fuzzy_data['object']) || $fuzzy_data['object'] !== 'error')) {
            return extractCardData($fuzzy_data);
        }
    }
    
    // Als letzter Versuch: Autocomplete API für Suggestions
    $autocomplete_url = "https://api.scryfall.com/cards/autocomplete?q=" . urlencode($card_name);
    $autocomplete_response = @file_get_contents($autocomplete_url, false, $context);
    
    if ($autocomplete_response !== false) {
        $autocomplete_data = json_decode($autocomplete_response, true);
        
        if ($autocomplete_data && isset($autocomplete_data['data']) && !empty($autocomplete_data['data'])) {
            // Nehme die erste Suggestion und suche exakt danach
            $suggestion = $autocomplete_data['data'][0];
            $suggestion_url = "https://api.scryfall.com/cards/named?exact=" . urlencode($suggestion);
            $suggestion_response = @file_get_contents($suggestion_url, false, $context);
            
            if ($suggestion_response !== false) {
                $suggestion_data = json_decode($suggestion_response, true);
                
                if ($suggestion_data && (!isset($suggestion_data['object']) || $suggestion_data['object'] !== 'error')) {
                    $card_data = extractCardData($suggestion_data);
                    $card_data['suggested_name'] = $suggestion; // Markiere als Vorschlag
                    return $card_data;
                }
            }
        }
    }
    
    return null;
}

// Hilfsfunktion um Kartendaten zu extrahieren
function extractCardData($data) {
    return [
        'name' => $data['name'] ?? '',
        'mana_cost' => $data['mana_cost'] ?? '',
        'type_line' => $data['type_line'] ?? '',
        'oracle_text' => $data['oracle_text'] ?? '',
        'power' => $data['power'] ?? null,
        'toughness' => $data['toughness'] ?? null,
        'colors' => $data['colors'] ?? [],
        'color_identity' => $data['color_identity'] ?? [],
        'colorIdentity' => $data['color_identity'] ?? [], // Für Kompatibilität
        'cmc' => $data['cmc'] ?? 0,
        'manaValue' => $data['cmc'] ?? 0, // Alternative field
        'rarity' => $data['rarity'] ?? '',
        'set' => $data['set'] ?? '',
        'set_name' => $data['set_name'] ?? '',
        'image_uris' => $data['image_uris'] ?? null,
        'image_url' => isset($data['image_uris']['normal']) ? $data['image_uris']['normal'] : (isset($data['image_uris']['small']) ? $data['image_uris']['small'] : null), // Für Rückwärtskompatibilität
        'prices' => $data['prices'] ?? [],
        'manaCost' => $data['mana_cost'] ?? '', // Für Kompatibilität mit Commander detection
        'raw_data' => $data // Für debugging
    ];
}

$results = [];
$total_processed = 0;
$total_success = 0;
$total_errors = 0;
$failed_cards = []; // Sammle fehlgeschlagene Karten für Feedback

// AJAX-Endpoint für Import-Historie
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_history') {
    header('Content-Type: application/json');
    
    try {
        $history = getImportHistory($pdo, $_SESSION['user_id'], 3);
        echo json_encode([
            'success' => true,
            'history' => $history
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit();
}

// Undo-Funktionalität
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'undo_import') {
    header('Content-Type: application/json');
    
    try {
        $import_session_id = $_POST['import_session_id'] ?? '';
        $user_id = $_SESSION['user_id'];
        
        if (empty($import_session_id)) {
            throw new Exception('Import Session ID fehlt');
        }
        
        // Transaktion starten
        $pdo->beginTransaction();
        
        // Prüfe ob Import existiert und noch nicht rückgängig gemacht wurde
        $stmt = $pdo->prepare("SELECT * FROM import_history WHERE import_session_id = ? AND user_id = ? AND status = 'completed'");
        $stmt->execute([$import_session_id, $user_id]);
        $import_record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$import_record) {
            throw new Exception('Import nicht gefunden oder bereits rückgängig gemacht');
        }
        
        // Hole alle erfolgreich importierten Karten aus dieser Session
        $stmt = $pdo->prepare("SELECT * FROM import_cards WHERE import_session_id = ? AND user_id = ? AND status = 'success' AND collection_id IS NOT NULL");
        $stmt->execute([$import_session_id, $user_id]);
        $imported_cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $removed_count = 0;
        $errors = [];
        
        // Entferne jede Karte aus der Collection
        foreach ($imported_cards as $card) {
            try {
                // Hole aktuellen Zustand der Karte in der Collection
                $stmt = $pdo->prepare("SELECT quantity FROM collections WHERE id = ? AND user_id = ?");
                $stmt->execute([$card['collection_id'], $user_id]);
                $current = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($current) {
                    $new_quantity = $current['quantity'] - $card['quantity'];
                    
                    if ($new_quantity <= 0) {
                        // Karte komplett entfernen
                        $stmt = $pdo->prepare("DELETE FROM collections WHERE id = ? AND user_id = ?");
                        $stmt->execute([$card['collection_id'], $user_id]);
                        $removed_count++;
                    } else {
                        // Menge reduzieren
                        $stmt = $pdo->prepare("UPDATE collections SET quantity = ? WHERE id = ? AND user_id = ?");
                        $stmt->execute([$new_quantity, $card['collection_id'], $user_id]);
                        $removed_count++;
                    }
                } else {
                    $errors[] = "Karte {$card['card_name']} nicht mehr in Collection gefunden";
                }
                
            } catch (Exception $e) {
                $errors[] = "Fehler bei Karte {$card['card_name']}: " . $e->getMessage();
            }
        }
        
        // Markiere Import als rückgängig gemacht
        $stmt = $pdo->prepare("UPDATE import_history SET status = 'undone', undone_at = NOW() WHERE import_session_id = ? AND user_id = ?");
        $stmt->execute([$import_session_id, $user_id]);
        
        // Transaktion bestätigen
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "✅ Import erfolgreich rückgängig gemacht",
            'removed_count' => $removed_count,
            'total_cards' => count($imported_cards),
            'errors' => $errors
        ]);
        
    } catch (Exception $e) {
        $pdo->rollback();
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit();
}

// Import-Historie laden
function getImportHistory($pdo, $user_id, $limit = 3) {
    try {
        $limit = (int)$limit; // Sicherheit: Cast zu Integer
        if ($limit <= 0) $limit = 3;
        
        $sql = "
            SELECT 
                ih.*,
                COUNT(ic.id) as total_imported_cards,
                SUM(CASE WHEN ic.status = 'success' THEN 1 ELSE 0 END) as successful_imported
            FROM import_history ih
            LEFT JOIN import_cards ic ON ih.import_session_id = ic.import_session_id
            WHERE ih.user_id = ?
            GROUP BY ih.id
            ORDER BY ih.import_date DESC
            LIMIT " . $limit;
            
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Error loading import history: " . $e->getMessage());
        return [];
    }
}

// Import-Historie für aktuellen User laden
$import_history = getImportHistory($pdo, $_SESSION['user_id'], 3);

// Debug: Log die Import-Historie
error_log("Import History Debug: " . json_encode($import_history));

// Bulk Import verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_import') {
    // Für AJAX-Requests: Live-Update-Modus
    if (isset($_POST['live_mode']) && $_POST['live_mode'] === 'true') {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        
        $card_input = trim($_POST['card_input']);
        $separator = $_POST['separator'] ?? 'newline';
        
        if (!empty($card_input)) {
            // Generiere eindeutige Import-Session-ID
            $import_session_id = md5(uniqid($_SESSION['user_id'] . time(), true));
            
            // Console-Log starten
            echo "data: " . json_encode([
                'type' => 'info',
                'message' => "🚀 Bulk-Import gestartet...",
                'timestamp' => date('H:i:s'),
                'session_id' => $import_session_id
            ]) . "\n\n";
            flush();
            
            // Datenbankverbindung testen
            try {
                $test_stmt = $pdo->query("SELECT 1");
                echo "data: " . json_encode([
                    'type' => 'success',
                    'message' => "✅ Datenbankverbindung erfolgreich",
                    'timestamp' => date('H:i:s')
                ]) . "\n\n";
                flush();
            } catch (Exception $e) {
                echo "data: " . json_encode([
                    'type' => 'error',
                    'message' => "❌ Datenbankfehler: " . $e->getMessage(),
                    'timestamp' => date('H:i:s')
                ]) . "\n\n";
                flush();
                exit;
            }
            
            // Kartennamen aufteilen
            if ($separator === 'comma') {
                $card_names = array_map('trim', explode(',', $card_input));
            } else {
                $card_names = array_map('trim', explode("\n", $card_input));
            }
            
            // Leere Namen entfernen
            $card_names = array_filter($card_names, function($name) {
                return !empty($name);
            });
            
            $total_processed = count($card_names);
            $total_success = 0;
            $total_errors = 0;
            $failed_cards = []; // Sammle fehlgeschlagene Karten
            
            echo "data: " . json_encode([
                'type' => 'info',
                'message' => "📋 {$total_processed} Kartennamen gefunden",
                'timestamp' => date('H:i:s')
            ]) . "\n\n";
            flush();
            
            foreach ($card_names as $index => $card_name) {
                $card_name = trim($card_name);
                if (empty($card_name)) continue;
                
                echo "data: " . json_encode([
                    'type' => 'info',
                    'message' => "🔍 Verarbeite: '{$card_name}' (" . ($index + 1) . "/{$total_processed})...",
                    'timestamp' => date('H:i:s'),
                    'progress' => round((($index + 1) / $total_processed) * 100, 1)
                ]) . "\n\n";
                flush();
                
                // API-Anfrage
                $card_data = fetchCardData($card_name);
                
                if (!$card_data) {
                    echo "data: " . json_encode([
                        'type' => 'error',
                        'message' => "❌ Karte '{$card_name}' konnte auch mit verbesserter Suche nicht gefunden werden",
                        'timestamp' => date('H:i:s')
                    ]) . "\n\n";
                    flush();
                    $total_errors++;
                    $failed_cards[] = [
                        'input' => $card_name,
                        'reason' => 'Nicht in Scryfall API gefunden',
                        'suggestions' => []
                    ];
                    
                    // Import-Tracking für fehlgeschlagene Karte
                    try {
                        $stmt = $pdo->prepare("INSERT INTO import_cards (import_session_id, user_id, card_name, quantity, collection_id, import_order, status) VALUES (?, ?, ?, 1, NULL, ?, 'failed')");
                        $stmt->execute([$import_session_id, $_SESSION['user_id'], $card_name, $index + 1]);
                    } catch (Exception $e) {
                        // Tracking-Fehler nicht kritisch
                        error_log("Import tracking error: " . $e->getMessage());
                    }
                    
                    continue;
                }
                
                // Prüfe ob es ein Suggestion Match war
                $message = "✅ API-Daten erhalten für: '{$card_data['name']}'";
                if (isset($card_data['suggested_name']) && $card_data['suggested_name'] !== $card_name) {
                    $message = "🔍 Ähnliche Karte gefunden: '{$card_name}' → '{$card_data['name']}'";
                } elseif ($card_data['name'] !== $card_name) {
                    $message = "🔧 Fuzzy-Match gefunden: '{$card_name}' → '{$card_data['name']}'";
                }
                
                echo "data: " . json_encode([
                    'type' => 'success',
                    'message' => $message,
                    'timestamp' => date('H:i:s')
                ]) . "\n\n";
                flush();
                
                try {
                    // Prüfen ob Karte bereits in Sammlung existiert
                    $stmt = $pdo->prepare("SELECT id, quantity FROM collections WHERE user_id = ? AND card_name = ?");
                    $stmt->execute([$_SESSION['user_id'], $card_data['name']]);
                    $existing = $stmt->fetch();
                    
                    $collection_id = null;
                    
                    if ($existing) {
                        // Anzahl erhöhen
                        $new_quantity = $existing['quantity'] + 1;
                        $stmt = $pdo->prepare("UPDATE collections SET quantity = ?, card_data = ? WHERE id = ?");
                        $stmt->execute([$new_quantity, json_encode($card_data), $existing['id']]);
                        $collection_id = $existing['id'];
                        
                        echo "data: " . json_encode([
                            'type' => 'success',
                            'message' => "📈 Anzahl erhöht: '{$card_data['name']}' (jetzt {$new_quantity}x)",
                            'timestamp' => date('H:i:s')
                        ]) . "\n\n";
                        flush();
                    } else {
                        // Neue Karte hinzufügen
                        $stmt = $pdo->prepare("INSERT INTO collections (user_id, card_name, card_data, quantity) VALUES (?, ?, ?, 1)");
                        $stmt->execute([$_SESSION['user_id'], $card_data['name'], json_encode($card_data)]);
                        $collection_id = $pdo->lastInsertId();
                        
                        echo "data: " . json_encode([
                            'type' => 'success',
                            'message' => "➕ Neue Karte hinzugefügt: '{$card_data['name']}'",
                            'timestamp' => date('H:i:s')
                        ]) . "\n\n";
                        flush();
                    }
                    
                    // Import-Tracking hinzufügen
                    $stmt = $pdo->prepare("INSERT INTO import_cards (import_session_id, user_id, card_name, quantity, collection_id, import_order, status) VALUES (?, ?, ?, 1, ?, ?, 'success')");
                    $stmt->execute([$import_session_id, $_SESSION['user_id'], $card_data['name'], $collection_id, $index + 1]);
                    
                    $total_success++;
                    
                } catch (Exception $e) {
                    echo "data: " . json_encode([
                        'type' => 'error',
                        'message' => "❌ Datenbankfehler bei '{$card_name}': " . $e->getMessage(),
                        'timestamp' => date('H:i:s')
                    ]) . "\n\n";
                    flush();
                    $total_errors++;
                    
                    // Fehlgeschlagene Karte für Feedback sammeln
                    $failed_cards[] = [
                        'input' => $card_name,
                        'reason' => 'Datenbankfehler: ' . $e->getMessage(),
                        'suggestions' => []
                    ];
                    
                    // Import-Tracking für fehlgeschlagene Karte
                    try {
                        $stmt = $pdo->prepare("INSERT INTO import_cards (import_session_id, user_id, card_name, quantity, collection_id, import_order, status) VALUES (?, ?, ?, 1, NULL, ?, 'failed')");
                        $stmt->execute([$import_session_id, $_SESSION['user_id'], $card_name, $index + 1]);
                    } catch (Exception $e2) {
                        // Tracking-Fehler nicht kritisch
                        error_log("Import tracking error: " . $e2->getMessage());
                    }
                }
                
                // Kleine Pause zwischen API-Anfragen (Rate Limiting respektieren)
                usleep(100000); // 0.1 Sekunden
            }
            
            // Zusammenfassung
            echo "data: " . json_encode([
                'type' => 'info',
                'message' => "🎯 Import abgeschlossen!",
                'timestamp' => date('H:i:s')
            ]) . "\n\n";
            flush();
            
            // Import-Historie in Datenbank speichern
            try {
                $import_summary = [
                    'total_processed' => $total_processed,
                    'total_success' => $total_success,
                    'total_errors' => $total_errors,
                    'failed_cards' => $failed_cards,
                    'session_id' => $import_session_id
                ];
                
                $stmt = $pdo->prepare("INSERT INTO import_history (user_id, import_session_id, total_cards, successful_cards, failed_cards, import_summary, status) VALUES (?, ?, ?, ?, ?, ?, 'completed')");
                $stmt->execute([
                    $_SESSION['user_id'], 
                    $import_session_id, 
                    $total_processed, 
                    $total_success, 
                    $total_errors, 
                    json_encode($import_summary)
                ]);
                
                echo "data: " . json_encode([
                    'type' => 'success',
                    'message' => "💾 Import-Historie gespeichert",
                    'timestamp' => date('H:i:s')
                ]) . "\n\n";
                flush();
                
            } catch (Exception $e) {
                echo "data: " . json_encode([
                    'type' => 'warning',
                    'message' => "⚠️ Import-Historie konnte nicht gespeichert werden: " . $e->getMessage(),
                    'timestamp' => date('H:i:s')
                ]) . "\n\n";
                flush();
            }
            
            // Zusammenfassung und Feedback
            $summary_data = [
                'total_processed' => $total_processed,
                'total_success' => $total_success,
                'total_errors' => $total_errors,
                'failed_cards' => $failed_cards ?? [],
                'import_session_id' => $import_session_id
            ];
            
            echo "data: " . json_encode([
                'type' => 'summary',
                'message' => "📊 Zusammenfassung: {$total_success} erfolgreich, {$total_errors} fehlerhaft von {$total_processed} Karten",
                'timestamp' => date('H:i:s'),
                'progress' => 100,
                'summary' => $summary_data,
                'completed' => true
            ]) . "\n\n";
            flush();
        }
        exit;
    }
    
    // Normale POST-Verarbeitung (Fallback)
    $card_input = trim($_POST['card_input']);
    $separator = $_POST['separator'] ?? 'newline';
    
    if (!empty($card_input)) {
        // Console-Log starten
        $results[] = [
            'type' => 'info',
            'message' => "🚀 Bulk-Import gestartet...",
            'timestamp' => date('H:i:s')
        ];
        
        // Datenbankverbindung testen
        try {
            $test_stmt = $pdo->query("SELECT 1");
            $results[] = [
                'type' => 'success',
                'message' => "✅ Datenbankverbindung erfolgreich",
                'timestamp' => date('H:i:s')
            ];
        } catch (Exception $e) {
            $results[] = [
                'type' => 'error',
                'message' => "❌ Datenbankfehler: " . $e->getMessage(),
                'timestamp' => date('H:i:s')
            ];
            $total_errors++;
        }
        
        // Kartennamen aufteilen
        if ($separator === 'comma') {
            $card_names = array_map('trim', explode(',', $card_input));
        } else {
            $card_names = array_map('trim', explode("\n", $card_input));
        }
        
        // Leere Namen entfernen
        $card_names = array_filter($card_names, function($name) {
            return !empty($name);
        });
        
        $total_processed = count($card_names);
        
        $results[] = [
            'type' => 'info',
            'message' => "📋 {$total_processed} Kartennamen gefunden",
            'timestamp' => date('H:i:s')
        ];
        
        foreach ($card_names as $index => $card_name) {
            $card_name = trim($card_name);
            if (empty($card_name)) continue;
            
            $results[] = [
                'type' => 'info',
                'message' => "🔍 Verarbeite: '{$card_name}'...",
                'timestamp' => date('H:i:s')
            ];
            
            // API-Anfrage
            $card_data = fetchCardData($card_name);
            
            if (!$card_data) {
                $results[] = [
                    'type' => 'error',
                    'message' => "❌ Karte '{$card_name}' konnte auch mit verbesserter Suche nicht gefunden werden",
                    'timestamp' => date('H:i:s')
                ];
                $total_errors++;
                $failed_cards[] = [
                    'input' => $card_name,
                    'reason' => 'Nicht in Scryfall API gefunden',
                    'suggestions' => []
                ];
                continue;
            }
            
            // Prüfe ob es ein Suggestion Match war
            $message = "✅ API-Daten erhalten für: '{$card_data['name']}'";
            if (isset($card_data['suggested_name']) && $card_data['suggested_name'] !== $card_name) {
                $message = "🔍 Ähnliche Karte gefunden: '{$card_name}' → '{$card_data['name']}'";
            } elseif ($card_data['name'] !== $card_name) {
                $message = "🔧 Fuzzy-Match gefunden: '{$card_name}' → '{$card_data['name']}'";
            }
            
            $results[] = [
                'type' => 'success',
                'message' => $message,
                'timestamp' => date('H:i:s')
            ];
            
            try {
                // Prüfen ob Karte bereits in Sammlung existiert
                $stmt = $pdo->prepare("SELECT id, quantity FROM collections WHERE user_id = ? AND card_name = ?");
                $stmt->execute([$_SESSION['user_id'], $card_data['name']]);
                $existing = $stmt->fetch();
                
                if ($existing) {
                    // Anzahl erhöhen
                    $new_quantity = $existing['quantity'] + 1;
                    $stmt = $pdo->prepare("UPDATE collections SET quantity = ?, card_data = ? WHERE id = ?");
                    $stmt->execute([$new_quantity, json_encode($card_data), $existing['id']]);
                    
                    $results[] = [
                        'type' => 'success',
                        'message' => "📈 Anzahl erhöht: '{$card_data['name']}' (jetzt {$new_quantity}x)",
                        'timestamp' => date('H:i:s')
                    ];
                } else {
                    // Neue Karte hinzufügen
                    $stmt = $pdo->prepare("INSERT INTO collections (user_id, card_name, card_data, quantity) VALUES (?, ?, ?, 1)");
                    $stmt->execute([$_SESSION['user_id'], $card_data['name'], json_encode($card_data)]);
                    
                    $results[] = [
                        'type' => 'success',
                        'message' => "➕ Neue Karte hinzugefügt: '{$card_data['name']}'",
                        'timestamp' => date('H:i:s')
                    ];
                }
                
                $total_success++;
                
            } catch (Exception $e) {
                $results[] = [
                    'type' => 'error',
                    'message' => "❌ Datenbankfehler bei '{$card_name}': " . $e->getMessage(),
                    'timestamp' => date('H:i:s')
                ];
                $total_errors++;
            }
            
            // Kleine Pause zwischen API-Anfragen (Rate Limiting respektieren)
            usleep(100000); // 0.1 Sekunden
        }
        
        // Zusammenfassung
        $results[] = [
            'type' => 'info',
            'message' => "🎯 Import abgeschlossen!",
            'timestamp' => date('H:i:s')
        ];
        $results[] = [
            'type' => 'info',
            'message' => "📊 Verarbeitet: {$total_processed} | Erfolgreich: {$total_success} | Fehler: {$total_errors}",
            'timestamp' => date('H:i:s')
        ];
    }
}

// Aktuelle Benutzerinformationen abrufen
$stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Import - MTG Collection Manager</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .bulk-import-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .import-layout {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 20px;
            margin-top: 20px;
        }

        @media (max-width: 1200px) {
            .import-layout {
                grid-template-columns: 1fr;
            }
        }

        .main-import-section {
            grid-column: 1;
        }

        .undo-panel {
            grid-column: 2;
            position: sticky;
            top: 20px;
            height: fit-content;
        }
        
        .import-section {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .import-form {
            display: grid;
            gap: 20px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 12px;
            align-items: end;
        }
        
        .card-input {
            width: 100%;
            min-height: 200px;
            padding: 12px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            resize: vertical;
            transition: border-color 0.2s;
        }
        
        .card-input:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .separator-select {
            padding: 8px 12px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            background: white;
            font-family: 'Inter', sans-serif;
        }
        
        .console {
            background: #1e1e1e;
            color: #fff;
            border-radius: 8px;
            padding: 16px;
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
            font-size: 13px;
            max-height: 400px;
            overflow-y: auto;
            margin-top: 20px;
        }
        
        .console-line {
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .console-timestamp {
            color: #666;
            font-size: 11px;
            min-width: 60px;
        }
        
        .console-message {
            flex: 1;
        }
        
        .console-line.success .console-message {
            color: #10b981;
        }
        
        .console-line.error .console-message {
            color: #ef4444;
        }
        
        .console-line.info .console-message {
            color: #3b82f6;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
            margin-top: 16px;
        }
        
        .stat-card {
            background: #f9fafb;
            border-radius: 8px;
            padding: 16px;
            text-align: center;
        }
        
        .stat-number {
            font-size: 24px;
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .stat-label {
            font-size: 12px;
            color: #6b7280;
            margin-top: 4px;
        }
        
        .help-text {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 16px;
            font-size: 14px;
            color: #1e40af;
        }
        
        .help-text h4 {
            margin: 0 0 8px 0;
            color: #1e3a8a;
        }
        
        .help-text ul {
            margin: 8px 0 0 16px;
        }
        
        .progress-bar {
            background: #e5e7eb;
            border-radius: 8px;
            height: 20px;
            margin: 12px 0;
            overflow: hidden;
        }
        
        .progress-fill {
            background: linear-gradient(90deg, var(--primary-color), #10b981);
            height: 100%;
            width: 0%;
            transition: width 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 12px;
            font-weight: 600;
        }
        
        /* Console Styles */
        .console-container {
            background: var(--card-background);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            margin-top: 2rem;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }

        .console-header {
            background: linear-gradient(135deg, #1a1a1a, #2d2d2d);
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            transition: all 0.3s ease;
            user-select: none;
        }

        .console-header:hover {
            background: linear-gradient(135deg, #2a2a2a, #3d3d3d);
        }

        .toggle-btn {
            background: none;
            border: none;
            color: var(--text-primary);
            font-size: 1.2rem;
            cursor: pointer;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            transition: all 0.3s ease;
        }

        .toggle-btn:hover {
            background: rgba(255,255,255,0.1);
        }

        .console-content {
            transition: max-height 0.3s ease, opacity 0.3s ease;
            max-height: 400px;
            opacity: 1;
            overflow: hidden;
        }

        .console-content.collapsed {
            max-height: 0;
            opacity: 0;
        }

        .console-output {
            background: #000;
            color: #00ff00;
            font-family: 'Courier New', monospace;
            padding: 1rem;
            height: 300px;
            overflow-y: auto;
            font-size: 0.9rem;
            line-height: 1.4;
        }

        .console-line {
            margin-bottom: 0.5rem;
            display: flex;
            gap: 1rem;
            opacity: 0;
            animation: fadeInLine 0.3s ease forwards;
        }

        @keyframes fadeInLine {
            to {
                opacity: 1;
            }
        }

        .console-timestamp {
            color: #888;
            font-weight: bold;
            min-width: 60px;
        }

        .console-message {
            flex: 1;
        }

        .console-line.success .console-message {
            color: #00ff00;
        }

        .console-line.error .console-message {
            color: #ff4444;
        }

        .console-line.warning .console-message {
            color: #ffaa00;
        }

        .console-line.info .console-message {
            color: #4da6ff;
        }

        .console-line.debug .console-message {
            color: #888;
        }

        .progress-bar {
            background: #333;
            height: 8px;
            position: relative;
            overflow: hidden;
        }

        .progress-fill {
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            height: 100%;
            width: 0%;
            transition: width 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: bold;
            color: white;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.8);
        }

        .console-status {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .console-status.running {
            background: linear-gradient(135deg, #f39c12, #e67e22);
            color: white;
            animation: pulse 2s infinite;
        }

        .console-status.completed {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
        }

        .console-status.error {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white;
        }

        .console-status.ready {
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        
        /* Undo Panel Styles */
        .undo-panel-card {
            background: var(--card-background);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }

        .undo-panel-header {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
            border-radius: 12px 12px 0 0;
            color: white;
        }

        .undo-panel-header h5 {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .undo-panel-content {
            padding: 1rem;
            max-height: 600px;
            overflow-y: auto;
        }

        .undo-item {
            background: rgba(0,0,0,0.3);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            transition: all 0.2s;
        }

        .undo-item:hover {
            border-color: var(--primary-color);
            background: rgba(44, 95, 65, 0.1);
        }

        .undo-item.undone {
            opacity: 0.6;
            background: rgba(114, 114, 114, 0.1);
            border-color: #666;
        }

        .undo-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .undo-item-date {
            font-size: 0.9rem;
            color: var(--text-secondary);
        }

        .undo-item-stats {
            display: flex;
            gap: 1rem;
            margin-bottom: 0.75rem;
            font-size: 0.85rem;
        }

        .undo-item-stat {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .undo-item-stat.success {
            color: #10b981;
        }

        .undo-item-stat.error {
            color: #ef4444;
        }

        .undo-item-actions {
            display: flex;
            gap: 0.5rem;
        }

        .btn-undo {
            background: var(--warning-color);
            color: white;
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .btn-undo:hover:not(:disabled) {
            background: #e67e22;
            transform: translateY(-1px);
        }

        .btn-undo:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-view-details {
            background: var(--secondary-color);
            color: var(--background-color);
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-view-details:hover {
            background: #c39c12;
        }

        .undo-empty {
            text-align: center;
            padding: 2rem;
            color: var(--text-secondary);
        }

        .undo-empty i {
            font-size: 3rem;
            opacity: 0.3;
            margin-bottom: 1rem;
        }

        /* Korrektur-Interface Styles */
        .correction-input:focus {
            outline: none;
            border-color: var(--warning-color);
            box-shadow: 0 0 0 2px rgba(243, 156, 18, 0.2);
        }
        
        .btn-warning {
            background: var(--warning-color);
            color: white;
        }
        
        .btn-warning:hover {
            background: #e67e22;
            transform: translateY(-1px);
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="bulk-import-container">
        <div class="page-header">
            <h1>📦 Bulk-Import</h1>
            <p>Mehrere Karten gleichzeitig zur Sammlung hinzufügen</p>
        </div>
        
        <div class="import-layout">
            <div class="main-import-section">
                <div class="import-section">
                    <h2>Kartennamen eingeben</h2>
            
            <div class="help-text">
                <h4>Anleitung:</h4>
                <ul>
                    <li><strong>Neue Zeile:</strong> Jeden Kartennamen in eine separate Zeile schreiben</li>
                    <li><strong>Komma-getrennt:</strong> Kartennamen mit Kommas trennen</li>
                    <li>Beispiele: "Lightning Bolt", "Counterspell", "Birds of Paradise"</li>
                    <li><strong>🔍 Intelligente Suche:</strong> Auch bei Tippfehlern wird versucht, die richtige Karte zu finden</li>
                    <li><strong>📡 Scryfall API:</strong> Alle Karten werden über die offizielle MTG-Datenbank erkannt</li>
                </ul>
                
                <div style="margin-top: 1rem; padding: 1rem; background: rgba(74, 124, 89, 0.1); border-radius: 6px; border: 1px solid var(--primary-color);">
                    <h5 style="margin: 0 0 0.5rem 0; color: var(--primary-color);">
                        <i class="fas fa-lightbulb"></i> Schnelltest mit Beispielen:
                    </h5>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.5rem;">
                        <textarea readonly onclick="this.select(); document.getElementById('card_input').value = this.value; document.getElementById('card_input').focus();" 
                                  style="padding: 0.5rem; cursor: pointer; background: rgba(0,0,0,0.3); border: 1px solid var(--border-color); border-radius: 4px; font-size: 0.8rem; height: 80px; resize: none;">Lightning bolt
Counterspel
Serra angel
Giant growth</textarea>
                        <textarea readonly onclick="this.select(); document.getElementById('card_input').value = this.value; document.getElementById('card_input').focus();" 
                                  style="padding: 0.5rem; cursor: pointer; background: rgba(0,0,0,0.3); border: 1px solid var(--border-color); border-radius: 4px; font-size: 0.8rem; height: 80px; resize: none;">Sol ring
Command tower
Reliquary tower
Arcane signet</textarea>
                        <textarea readonly onclick="this.select(); document.getElementById('card_input').value = this.value; document.getElementById('card_input').focus();" 
                                  style="padding: 0.5rem; cursor: pointer; background: rgba(0,0,0,0.3); border: 1px solid var(--border-color); border-radius: 4px; font-size: 0.8rem; height: 80px; resize: none;">Anafenza kin tree
Rhystic study
Birds of pradise
Pathbreaker ibex</textarea>
                    </div>
                    <small style="color: var(--text-secondary); margin-top: 0.5rem; display: block;">
                        <i class="fas fa-mouse-pointer"></i> Klicken Sie auf ein Beispiel um es zu verwenden (enthält absichtlich Tippfehler zum Testen der intelligenten Suche)
                    </small>
                </div>
            </div>
            
            <form method="POST" class="import-form" id="importForm">
                <input type="hidden" name="action" value="bulk_import">
                
                <div>
                    <label for="card_input">Kartennamen:</label>
                    <textarea 
                        id="card_input" 
                        name="card_input" 
                        class="card-input" 
                        placeholder="Lightning Bolt&#10;Counterspell&#10;Birds of Paradise&#10;&#10;oder&#10;&#10;Lightning Bolt, Counterspell, Birds of Paradise"
                        required
                    ><?php echo htmlspecialchars($_POST['card_input'] ?? ''); ?></textarea>
                </div>
                
                <div class="form-row">
                    <div>
                        <label for="separator">Trennzeichen:</label>
                        <select id="separator" name="separator" class="separator-select">
                            <option value="newline" <?php echo ($_POST['separator'] ?? '') === 'newline' ? 'selected' : ''; ?>>
                                Neue Zeile (empfohlen)
                            </option>
                            <option value="comma" <?php echo ($_POST['separator'] ?? '') === 'comma' ? 'selected' : ''; ?>>
                                Komma-getrennt
                            </option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary" id="importBtn">🚀 Import starten</button>
                </div>
            </form>
            
            <!-- Dauerhaft sichtbare Console mit Toggle -->
            <div class="console-container" id="consoleContainer">
                <div class="console-header" onclick="toggleConsole()">
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <span><i class="fas fa-terminal"></i> Import Console</span>
                        <span class="console-status" id="consoleStatus">Bereit</span>
                    </div>
                    <button type="button" class="toggle-btn" id="consoleToggle">
                        <i class="fas fa-chevron-down" id="consoleIcon"></i>
                    </button>
                </div>
                <div class="console-content" id="consoleContent">
                    <div class="progress-bar">
                        <div class="progress-fill" id="progressFill">0%</div>
                    </div>
                    <div class="console-output" id="consoleOutput">
                        <div class="console-line info">
                            <span class="console-timestamp"><?= date('H:i:s') ?></span>
                            <span class="console-message">💡 Console bereit - Starten Sie einen Import um Live-Updates zu sehen</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($results)): ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_processed; ?></div>
                    <div class="stat-label">Verarbeitet</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #10b981;"><?php echo $total_success; ?></div>
                    <div class="stat-label">Erfolgreich</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #ef4444;"><?php echo $total_errors; ?></div>
                    <div class="stat-label">Fehler</div>
                </div>
            </div>
            
            <div class="console">
                <div style="color: #10b981; margin-bottom: 12px;">🖥️ Console Output:</div>
                <?php foreach ($results as $result): ?>
                <div class="console-line <?php echo $result['type']; ?>">
                    <span class="console-timestamp"><?php echo $result['timestamp']; ?></span>
                    <span class="console-message"><?php echo htmlspecialchars($result['message']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="import-section">
            <h3>📋 Schnellstart-Beispiele</h3>
            <p>Kopiere eines dieser Beispiele zum Testen:</p>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 16px;">
                <div>
                    <h4>🔥 Beliebte Karten:</h4>
                    <textarea readonly style="width: 100%; height: 120px; font-family: monospace; font-size: 12px;">Lightning Bolt
Counterspell
Birds of Paradise
Sol Ring
Path to Exile</textarea>
                </div>
                <div>
                    <h4>🏛️ Klassiker:</h4>
                    <textarea readonly style="width: 100%; height: 120px; font-family: monospace; font-size: 12px;">Black Lotus
Ancestral Recall
Time Walk
Mox Sapphire
Mox Ruby</textarea>
                </div>
            </div>
        </div>
            </div>
            
            <!-- Undo Panel rechts von der Console -->
            <div class="undo-panel">
                <div class="undo-panel-card">
                    <div class="undo-panel-header">
                        <h5><i class="fas fa-history"></i> Import-Historie</h5>
                        <small>Letzte 3 Bulk-Imports rückgängig machen</small>
                    </div>
                    <div class="undo-panel-content" id="undoHistoryContent">
                        <!-- Wird dynamisch mit JavaScript gefüllt -->
                        <div class="undo-empty">
                            <i class="fas fa-history"></i>
                            <p>Noch keine Imports durchgeführt</p>
                            <small>Nach einem Bulk-Import erscheinen hier die Undo-Optionen</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Console Toggle Funktion
        function toggleConsole() {
            const content = document.getElementById('consoleContent');
            const icon = document.getElementById('consoleIcon');
            
            if (content.classList.contains('collapsed')) {
                content.classList.remove('collapsed');
                icon.className = 'fas fa-chevron-down';
            } else {
                content.classList.add('collapsed');
                icon.className = 'fas fa-chevron-up';
            }
        }

        // Console Status updaten
        function updateConsoleStatus(status, text) {
            const statusElement = document.getElementById('consoleStatus');
            statusElement.className = `console-status ${status}`;
            statusElement.textContent = text || status;
        }

        // Console-Zeile hinzufügen
        function addConsoleMessage(type, message, showProgress = false, progress = 0) {
            const consoleOutput = document.getElementById('consoleOutput');
            const timestamp = new Date().toLocaleTimeString();
            
            const consoleLine = document.createElement('div');
            consoleLine.className = `console-line ${type}`;
            
            consoleLine.innerHTML = `
                <span class="console-timestamp">${timestamp}</span>
                <span class="console-message">${message}</span>
            `;
            
            consoleOutput.appendChild(consoleLine);
            consoleOutput.scrollTop = consoleOutput.scrollHeight;
            
            // Progress Bar aktualisieren
            if (showProgress) {
                const progressFill = document.getElementById('progressFill');
                progressFill.style.width = progress + '%';
                progressFill.textContent = progress + '%';
            }
        }

        // Form Submit Handler
        document.getElementById('importForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            const consoleOutput = document.getElementById('consoleOutput');
            const progressFill = document.getElementById('progressFill');
            const importBtn = document.getElementById('importBtn');
            
            // Console sichtbar machen falls zugeklappt
            const content = document.getElementById('consoleContent');
            const icon = document.getElementById('consoleIcon');
            if (content.classList.contains('collapsed')) {
                content.classList.remove('collapsed');
                icon.className = 'fas fa-chevron-down';
            }
            
            // Console zurücksetzen
            consoleOutput.innerHTML = '';
            progressFill.style.width = '0%';
            progressFill.textContent = '0%';
            importBtn.disabled = true;
            importBtn.textContent = '⏳ Import läuft...';
            
            updateConsoleStatus('running', 'Läuft...');
            addConsoleMessage('info', '🚀 Import gestartet - Verbindung zur Scryfall API...');
            
            // AJAX-Request für Live-Updates
            formData.append('live_mode', 'true');
            
            fetch('bulk_import.php', {
                method: 'POST',
                body: formData
            }).then(response => {
                const reader = response.body.getReader();
                const decoder = new TextDecoder();
                
                function readStream() {
                    return reader.read().then(({ done, value }) => {
                        if (done) {
                            updateConsoleStatus('completed', 'Abgeschlossen');
                            importBtn.disabled = false;
                            importBtn.textContent = '🚀 Import starten';
                            
                            addConsoleMessage('success', '✅ Import erfolgreich abgeschlossen!');
                            addConsoleMessage('info', '� Console-Verlauf bleibt zur Nachverfolgung sichtbar');
                            
                            // KEINE automatische Seitenneulad - Console bleibt sichtbar
                            return;
                        }
                        
                        const chunk = decoder.decode(value);
                        const lines = chunk.split('\n');
                        
                        lines.forEach(line => {
                            if (line.startsWith('data: ')) {
                                try {
                                    const data = JSON.parse(line.substring(6));
                                    
                                    // Console-Zeile hinzufügen
                                    addConsoleMessage(data.type, data.message, data.progress !== undefined, data.progress);
                                    
                                    // Import abgeschlossen mit Zusammenfassung?
                                    if (data.completed && data.summary) {
                                        showImportSummary(data.summary);
                                    }
                                    
                                } catch (e) {
                                    console.log('Parse error for line:', line);
                                }
                            }
                        });
                        
                        return readStream();
                    });
                }
                
                return readStream();
                
            }).catch(error => {
                console.error('Fetch error:', error);
                updateConsoleStatus('error', 'Fehler');
                importBtn.disabled = false;
                importBtn.textContent = '🚀 Import starten';
                
                addConsoleMessage('error', '❌ Verbindungsfehler - Fallback zur normalen Übertragung');
                
                // Fallback zur normalen Form-Übertragung
                setTimeout(() => {
                    const form = document.getElementById('importForm');
                    const liveMode = form.querySelector('input[name="live_mode"]');
                    if (liveMode) {
                        liveMode.remove();
                    }
                    addConsoleMessage('info', '🔄 Wechsle zu Standard-Import...');
                    form.submit();
                }, 2000);
            });
        });
        
        // Import-Zusammenfassung anzeigen
        function showImportSummary(summary) {
            const consoleOutput = document.getElementById('consoleOutput');
            
            // Trennlinie für bessere Übersicht
            addConsoleMessage('info', '─'.repeat(80));
            addConsoleMessage('success', `🎯 IMPORT ABGESCHLOSSEN - ZUSAMMENFASSUNG:`);
            addConsoleMessage('info', `📊 Verarbeitet: ${summary.total_processed} Karten`);
            addConsoleMessage('success', `✅ Erfolgreich: ${summary.total_success} Karten`);
            
            if (summary.total_errors > 0) {
                addConsoleMessage('error', `❌ Fehlerhaft: ${summary.total_errors} Karten`);
                
                if (summary.failed_cards && summary.failed_cards.length > 0) {
                    addConsoleMessage('warning', '🔧 Fehlgeschlagene Karten:');
                    summary.failed_cards.forEach((failedCard, index) => {
                        addConsoleMessage('error', `   ${index + 1}. "${failedCard.input}" - ${failedCard.reason}`);
                    });
                    
                    // Korrektur-Interface anzeigen
                    setTimeout(() => {
                        showCorrectionInterface(summary.failed_cards);
                    }, 1000);
                }
            } else {
                addConsoleMessage('success', '🎉 Alle Karten erfolgreich importiert!');
            }
            
            addConsoleMessage('info', '─'.repeat(80));
            
            // Update Import-Historie nach erfolgreichem Import
            updateImportHistoryAfterImport(summary);
        }

        // Korrektur-Interface für fehlgeschlagene Karten
        function showCorrectionInterface(failedCards) {
            if (failedCards.length === 0) return;
            
            // Korrektur-Panel erstellen
            const correctionPanel = document.createElement('div');
            correctionPanel.id = 'correctionPanel';
            correctionPanel.style.cssText = `
                margin-top: 1rem;
                padding: 1rem;
                background: linear-gradient(135deg, rgba(231, 76, 60, 0.1), rgba(192, 57, 43, 0.1));
                border: 1px solid #e74c3c;
                border-radius: 8px;
                animation: slideIn 0.3s ease;
            `;
            
            correctionPanel.innerHTML = `
                <h6 style="margin: 0 0 1rem 0; color: #e74c3c; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fas fa-tools"></i> Korrektur-Modus
                    <small style="color: var(--text-secondary); font-weight: normal;">
                        (${failedCards.length} Karte${failedCards.length !== 1 ? 'n' : ''} korrigieren)
                    </small>
                </h6>
                <p style="margin: 0 0 1rem 0; color: var(--text-secondary); font-size: 0.9rem;">
                    Überprüfen Sie die fehlgeschlagenen Kartennamen und korrigieren Sie Tippfehler:
                </p>
                <div id="correctionList"></div>
                <div style="margin-top: 1rem; display: flex; gap: 0.5rem;">
                    <button type="button" id="retryBtn" class="btn btn-warning" style="flex: 1;">
                        <i class="fas fa-redo"></i> Korrigierte Karten erneut importieren
                    </button>
                    <button type="button" id="dismissBtn" class="btn" style="background: #6c757d; color: white;">
                        <i class="fas fa-times"></i> Schließen
                    </button>
                </div>
            `;
            
            // Korrektur-Liste erstellen
            const correctionList = correctionPanel.querySelector('#correctionList');
            failedCards.forEach((failedCard, index) => {
                const correctionItem = document.createElement('div');
                correctionItem.style.cssText = `
                    display: flex;
                    align-items: center;
                    gap: 0.5rem;
                    margin-bottom: 0.5rem;
                    padding: 0.5rem;
                    background: rgba(0,0,0,0.2);
                    border-radius: 4px;
                `;
                
                correctionItem.innerHTML = `
                    <span style="min-width: 20px; color: #e74c3c; font-weight: bold;">${index + 1}.</span>
                    <input type="text" 
                           value="${failedCard.input}" 
                           data-original="${failedCard.input}"
                           class="correction-input"
                           style="flex: 1; padding: 0.25rem 0.5rem; border: 1px solid var(--border-color); border-radius: 4px; background: var(--background-color); color: var(--text-primary);"
                           placeholder="Kartennamen korrigieren...">
                    <small style="color: var(--text-secondary); min-width: 150px; font-size: 0.8rem;">
                        ${failedCard.reason}
                    </small>
                `;
                
                correctionList.appendChild(correctionItem);
            });
            
            // Panel nach Console einfügen
            const consoleContainer = document.getElementById('consoleContainer');
            consoleContainer.appendChild(correctionPanel);
            
            // Event-Listener für Buttons
            document.getElementById('retryBtn').addEventListener('click', function() {
                const correctedCards = [];
                const inputs = correctionPanel.querySelectorAll('.correction-input');
                
                inputs.forEach(input => {
                    const corrected = input.value.trim();
                    const original = input.dataset.original;
                    
                    if (corrected && corrected !== original) {
                        correctedCards.push(corrected);
                    }
                });
                
                if (correctedCards.length > 0) {
                    // Korrigierte Karten in Textarea einsetzen und Import starten
                    document.getElementById('card_input').value = correctedCards.join('\\n');
                    correctionPanel.remove();
                    
                    addConsoleMessage('info', '🔄 Starte Korrektur-Import mit ' + correctedCards.length + ' korrigierten Karten...');
                    
                    // Import-Formular automatisch absenden
                    setTimeout(() => {
                        document.getElementById('importForm').dispatchEvent(new Event('submit'));
                    }, 500);
                } else {
                    alert('Keine Korrekturen gefunden! Bitte ändern Sie mindestens einen Kartennamen.');
                }
            });
            
            document.getElementById('dismissBtn').addEventListener('click', function() {
                correctionPanel.remove();
                addConsoleMessage('info', '❌ Korrektur-Modus geschlossen');
            });
            
            addConsoleMessage('warning', '🔧 Korrektur-Interface geladen - Überprüfen Sie die Kartennamen oberhalb');
        }

        // Initialisierung
        document.addEventListener('DOMContentLoaded', function() {
            updateConsoleStatus('ready', 'Bereit');
            addConsoleMessage('info', '💡 Console bereit - Starten Sie einen Import um Live-Updates zu sehen');
            addConsoleMessage('info', '🔗 Karten werden über die Scryfall API gesucht und validiert');
            
            // Lade Import-Historie
            loadImportHistory();
            
            // Beispiel-Buttons für Schnellstart
            document.querySelectorAll('textarea[readonly]').forEach(textarea => {
                textarea.addEventListener('click', function() {
                    document.getElementById('card_input').value = this.value;
                    document.getElementById('card_input').focus();
                });
            });
        });

        // Import-Historie laden und anzeigen
        function loadImportHistory() {
            const historyContent = document.getElementById('undoHistoryContent');
            
            // PHP-Daten aus Server-Side einbetten
            const importHistory = <?php echo json_encode($import_history); ?>;
            
            // Debug-Output
            console.log('Import History Data:', importHistory);
            
            if (!importHistory || importHistory.length === 0) {
                console.log('No import history found');
                historyContent.innerHTML = `
                    <div class="undo-empty">
                        <i class="fas fa-history"></i>
                        <p>Noch keine Imports durchgeführt</p>
                        <small>Nach einem Bulk-Import erscheinen hier die Undo-Optionen</small>
                    </div>
                `;
                return;
            }
            
            console.log(`Loading ${importHistory.length} import history items`);
            
            let historyHtml = '';
            importHistory.forEach((item, index) => {
                console.log(`Processing item ${index}:`, item);
                
                const date = new Date(item.import_date).toLocaleString('de-DE', {
                    day: '2-digit',
                    month: '2-digit', 
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
                
                console.log(`Formatted date: ${date}`);
                
                const isUndone = item.status === 'undone';
                const undoneClass = isUndone ? 'undone' : '';
                
                historyHtml += `
                    <div class="undo-item ${undoneClass}">
                        <div class="undo-item-header">
                            <div class="undo-item-date">
                                <i class="fas fa-clock"></i> ${date}
                            </div>
                            ${isUndone ? '<span style="color: #666; font-size: 0.8rem;"><i class="fas fa-undo"></i> Rückgängig gemacht</span>' : ''}
                        </div>
                        <div class="undo-item-stats">
                            <div class="undo-item-stat success">
                                <i class="fas fa-check"></i> ${item.successful_cards} erfolgreich
                            </div>
                            <div class="undo-item-stat error">
                                <i class="fas fa-times"></i> ${item.failed_cards} fehlerhaft
                            </div>
                            <div class="undo-item-stat">
                                <i class="fas fa-layer-group"></i> ${item.total_cards} gesamt
                            </div>
                        </div>
                        <div class="undo-item-actions">
                            <button type="button" 
                                    class="btn-undo" 
                                    onclick="undoImport('${item.import_session_id}')"
                                    ${isUndone ? 'disabled' : ''}>
                                <i class="fas fa-undo"></i> 
                                ${isUndone ? 'Bereits rückgängig' : 'Rückgängig machen'}
                            </button>
                            <button type="button" 
                                    class="btn-view-details" 
                                    onclick="viewImportDetails('${item.import_session_id}')">
                                <i class="fas fa-info-circle"></i> Details
                            </button>
                        </div>
                    </div>
                `;
            });
            
            console.log('Generated HTML length:', historyHtml.length);
            historyContent.innerHTML = historyHtml;
            console.log('History content updated');
        }

        // Import rückgängig machen
        function undoImport(sessionId) {
            if (!confirm('Sind Sie sicher, dass Sie diesen Import rückgängig machen möchten?\\n\\nAlle Karten aus diesem Import werden aus Ihrer Sammlung entfernt.')) {
                return;
            }
            
            const button = event.target.closest('.btn-undo');
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Wird rückgängig gemacht...';
            
            const formData = new FormData();
            formData.append('action', 'undo_import');
            formData.append('import_session_id', sessionId);
            
            fetch('bulk_import.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    addConsoleMessage('success', '✅ ' + data.message);
                    addConsoleMessage('info', `📊 ${data.removed_count} von ${data.total_cards} Karten entfernt`);
                    
                    if (data.errors && data.errors.length > 0) {
                        data.errors.forEach(error => {
                            addConsoleMessage('warning', '⚠️ ' + error);
                        });
                    }
                    
                    // Historie neu laden
                    setTimeout(() => {
                        location.reload(); // Einfache Lösung: Seite neu laden
                    }, 1500);
                    
                } else {
                    addConsoleMessage('error', '❌ Fehler beim Rückgängigmachen: ' + data.error);
                    button.disabled = false;
                    button.innerHTML = '<i class="fas fa-undo"></i> Rückgängig machen';
                }
            })
            .catch(error => {
                console.error('Undo error:', error);
                addConsoleMessage('error', '❌ Verbindungsfehler beim Rückgängigmachen');
                button.disabled = false;
                button.innerHTML = '<i class="fas fa-undo"></i> Rückgängig machen';
            });
        }

        // Import-Details anzeigen
        function viewImportDetails(sessionId) {
            addConsoleMessage('info', `🔍 Lade Details für Import-Session: ${sessionId}`);
            
            // Hier könnte eine detaillierte Ansicht implementiert werden
            // Für jetzt zeigen wir nur die Session-ID
            const historyItem = <?php echo json_encode($import_history); ?>.find(item => item.import_session_id === sessionId);
            if (historyItem && historyItem.import_summary) {
                try {
                    const summary = JSON.parse(historyItem.import_summary);
                    addConsoleMessage('info', `📋 Import-Details für ${new Date(historyItem.import_date).toLocaleString('de-DE')}:`);
                    addConsoleMessage('info', `  📊 ${summary.total_processed} Karten verarbeitet`);
                    addConsoleMessage('success', `  ✅ ${summary.total_success} erfolgreich hinzugefügt`);
                    if (summary.total_errors > 0) {
                        addConsoleMessage('error', `  ❌ ${summary.total_errors} fehlgeschlagen`);
                    }
                } catch (e) {
                    addConsoleMessage('warning', '⚠️ Import-Details konnten nicht geladen werden');
                }
            }
        }

        // Update Import-Historie nach erfolgreichem Import
        function updateImportHistoryAfterImport(summaryData) {
            if (summaryData && summaryData.import_session_id) {
                addConsoleMessage('info', '🔄 Aktualisiere Import-Historie...');
                
                // Nach kurzem Delay die Historie neu laden via AJAX
                setTimeout(() => {
                    fetch('bulk_import.php?action=get_history')
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                // Aktualisiere das Undo-Panel mit neuen Daten
                                updateUndoPanel(data.history);
                                addConsoleMessage('success', '✅ Import-Historie aktualisiert - Undo-Option verfügbar');
                            } else {
                                addConsoleMessage('warning', '⚠️ Historie konnte nicht aktualisiert werden');
                            }
                        })
                        .catch(error => {
                            console.error('History update error:', error);
                            addConsoleMessage('warning', '⚠️ Historie-Update fehlgeschlagen');
                        });
                }, 2000);
            }
        }

        // Undo-Panel mit neuen Daten aktualisieren
        function updateUndoPanel(newHistory) {
            const historyContent = document.getElementById('undoHistoryContent');
            
            if (!newHistory || newHistory.length === 0) {
                historyContent.innerHTML = `
                    <div class="undo-empty">
                        <i class="fas fa-history"></i>
                        <p>Noch keine Imports durchgeführt</p>
                        <small>Nach einem Bulk-Import erscheinen hier die Undo-Optionen</small>
                    </div>
                `;
                return;
            }
            
            let historyHtml = '';
            newHistory.forEach((item, index) => {
                const date = new Date(item.import_date).toLocaleString('de-DE', {
                    day: '2-digit',
                    month: '2-digit', 
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
                
                const isUndone = item.status === 'undone';
                const undoneClass = isUndone ? 'undone' : '';
                
                historyHtml += `
                    <div class="undo-item ${undoneClass}">
                        <div class="undo-item-header">
                            <div class="undo-item-date">
                                <i class="fas fa-clock"></i> ${date}
                            </div>
                            ${isUndone ? '<span style="color: #666; font-size: 0.8rem;"><i class="fas fa-undo"></i> Rückgängig gemacht</span>' : ''}
                        </div>
                        <div class="undo-item-stats">
                            <div class="undo-item-stat success">
                                <i class="fas fa-check"></i> ${item.successful_cards} erfolgreich
                            </div>
                            <div class="undo-item-stat error">
                                <i class="fas fa-times"></i> ${item.failed_cards} fehlerhaft
                            </div>
                            <div class="undo-item-stat">
                                <i class="fas fa-layer-group"></i> ${item.total_cards} gesamt
                            </div>
                        </div>
                        <div class="undo-item-actions">
                            <button type="button" 
                                    class="btn-undo" 
                                    onclick="undoImport('${item.import_session_id}')"
                                    ${isUndone ? 'disabled' : ''}>
                                <i class="fas fa-undo"></i> 
                                ${isUndone ? 'Bereits rückgängig' : 'Rückgängig machen'}
                            </button>
                            <button type="button" 
                                    class="btn-view-details" 
                                    onclick="viewImportDetails('${item.import_session_id}')">
                                <i class="fas fa-info-circle"></i> Details
                            </button>
                        </div>
                    </div>
                `;
            });
            
            historyContent.innerHTML = historyHtml;
        }
    </script>
    
    <?php include 'includes/footer.php'; ?>
</body>
</html>
