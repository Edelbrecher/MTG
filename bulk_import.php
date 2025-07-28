<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Kartendaten von Scryfall API holen
function fetchCardData($card_name) {
    $url = "https://api.scryfall.com/cards/named?exact=" . urlencode($card_name);
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'user_agent' => 'MTG Collection Manager/1.0'
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        return null;
    }
    
    $data = json_decode($response, true);
    
    if (!$data || isset($data['object']) && $data['object'] === 'error') {
        return null;
    }
    
    // Relevante Daten extrahieren
    return [
        'name' => $data['name'] ?? '',
        'mana_cost' => $data['mana_cost'] ?? '',
        'type_line' => $data['type_line'] ?? '',
        'oracle_text' => $data['oracle_text'] ?? '',
        'power' => $data['power'] ?? null,
        'toughness' => $data['toughness'] ?? null,
        'colors' => $data['colors'] ?? [],
        'color_identity' => $data['color_identity'] ?? [],
        'cmc' => $data['cmc'] ?? 0,
        'rarity' => $data['rarity'] ?? '',
        'set' => $data['set'] ?? '',
        'set_name' => $data['set_name'] ?? '',
        'image_uris' => $data['image_uris'] ?? null,
        'prices' => $data['prices'] ?? []
    ];
}

$results = [];
$total_processed = 0;
$total_success = 0;
$total_errors = 0;

// Bulk Import verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_import') {
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
                    'message' => "❌ Karte '{$card_name}' nicht in Scryfall API gefunden",
                    'timestamp' => date('H:i:s')
                ];
                $total_errors++;
                continue;
            }
            
            $results[] = [
                'type' => 'success',
                'message' => "✅ API-Daten erhalten für: '{$card_data['name']}'",
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
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
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
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="bulk-import-container">
        <div class="page-header">
            <h1>📦 Bulk-Import</h1>
            <p>Mehrere Karten gleichzeitig zur Sammlung hinzufügen</p>
        </div>
        
        <div class="import-section">
            <h2>Kartennamen eingeben</h2>
            
            <div class="help-text">
                <h4>Anleitung:</h4>
                <ul>
                    <li><strong>Neue Zeile:</strong> Jeden Kartennamen in eine separate Zeile schreiben</li>
                    <li><strong>Komma-getrennt:</strong> Kartennamen mit Kommas trennen</li>
                    <li>Beispiele: "Lightning Bolt", "Counterspell", "Birds of Paradise"</li>
                    <li>Die Karten werden automatisch von der Scryfall API erkannt</li>
                </ul>
            </div>
            
            <form method="POST" class="import-form">
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
                    <button type="submit" class="btn btn-primary">🚀 Import starten</button>
                </div>
            </form>
            
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
    
    <?php include 'includes/footer.php'; ?>
</body>
</html>
