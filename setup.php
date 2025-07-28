<?php
require_once 'config/database.php';

echo "<h1>MTG Kartensammlung Setup</h1>";
echo "<p>Dieses Script ordnet Ihre vorhandenen Karten dem Admin-Account zu.</p>";

try {
    // Schritt 1: Admin-User erstellen/prüfen
    $admin_check = $pdo->query("SELECT id FROM users WHERE email = 'admin@mtg.local'")->fetch();
    $admin_id = $admin_check ? $admin_check['id'] : null;
    
    if (!$admin_id) {
        $admin_password = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, is_admin) VALUES (?, ?, ?, ?)");
        $stmt->execute(['admin', 'admin@mtg.local', $admin_password, 1]);
        $admin_id = $pdo->lastInsertId();
        echo "<p style='color: green;'>✓ Admin-Account erstellt (ID: $admin_id)</p>";
    } else {
        echo "<p style='color: blue;'>✓ Admin-Account bereits vorhanden (ID: $admin_id)</p>";
    }
    
    // Schritt 2: Alle Tabellen anzeigen
    echo "<h2>Datenbankanalyse:</h2>";
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($tables as $table) {
        $count = $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        echo "<p><strong>$table:</strong> $count Einträge</p>";
        
        // Zeige Struktur von Tabellen mit "card" im Namen
        if (strpos(strtolower($table), 'card') !== false && $count > 0) {
            echo "<details><summary>Tabellenstruktur von $table</summary>";
            $columns = $pdo->query("DESCRIBE $table")->fetchAll();
            echo "<ul>";
            foreach ($columns as $col) {
                echo "<li><strong>{$col['Field']}</strong> ({$col['Type']})</li>";
            }
            echo "</ul>";
            
            // Zeige erste 3 Einträge
            echo "<p><strong>Beispieldaten:</strong></p>";
            $samples = $pdo->query("SELECT * FROM $table LIMIT 3")->fetchAll();
            echo "<pre style='background: #f5f5f5; padding: 10px; overflow: auto; max-height: 200px;'>";
            foreach ($samples as $sample) {
                print_r($sample);
            }
            echo "</pre>";
            echo "</details>";
        }
    }
    
    // Schritt 3: Migration anbieten
    echo "<h2>Kartenmigration:</h2>";
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['migrate_table'])) {
        $source_table = $_POST['migrate_table'];
        $name_field = $_POST['name_field'];
        $quantity_field = $_POST['quantity_field'] ?: 'quantity';
        
        echo "<h3>Migration von Tabelle: $source_table</h3>";
        
        // Karten aus Quelltabelle holen
        $stmt = $pdo->prepare("SELECT * FROM $source_table");
        $stmt->execute();
        $cards = $stmt->fetchAll();
        
        $migrated = 0;
        $errors = 0;
        
        foreach ($cards as $card) {
            $card_name = $card[$name_field] ?? '';
            $quantity = $card[$quantity_field] ?? 1;
            
            if (empty($card_name)) {
                $errors++;
                continue;
            }
            
            // API-Daten holen (mit Fehlerbehandlung)
            $api_data = fetchCardAPI($card_name);
            
            // In collections Tabelle einfügen
            try {
                $stmt = $pdo->prepare("
                    INSERT IGNORE INTO collections (user_id, card_name, card_data, quantity, added_at) 
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $result = $stmt->execute([$admin_id, $card_name, json_encode($api_data), $quantity]);
                
                if ($stmt->rowCount() > 0) {
                    $migrated++;
                    echo "<span style='color: green;'>✓</span> $card_name (x$quantity)<br>";
                } else {
                    echo "<span style='color: orange;'>~</span> $card_name (bereits vorhanden)<br>";
                }
            } catch (Exception $e) {
                echo "<span style='color: red;'>✗</span> $card_name (Fehler: " . $e->getMessage() . ")<br>";
                $errors++;
            }
            
            // Kurze Pause für API-Limits
            usleep(100000); // 0.1 Sekunden
        }
        
        echo "<div style='background: #d4edda; padding: 15px; margin: 20px 0; border-radius: 5px;'>";
        echo "<h3>Migration abgeschlossen!</h3>";
        echo "<p><strong>$migrated</strong> Karten erfolgreich migriert</p>";
        echo "<p><strong>$errors</strong> Fehler aufgetreten</p>";
        echo "<p><a href='index.php' style='background: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>→ Zum Login</a></p>";
        echo "</div>";
    }
    
    // Migration-Formular
    echo "<form method='POST' style='background: #f8f9fa; padding: 20px; border-radius: 5px;'>";
    echo "<h3>Karten automatisch migrieren</h3>";
    
    echo "<label><strong>Quelltabelle auswählen:</strong></label><br>";
    echo "<select name='migrate_table' required style='padding: 5px; margin: 5px 0; width: 200px;'>";
    foreach ($tables as $table) {
        if (strpos(strtolower($table), 'card') !== false || 
            strpos(strtolower($table), 'collection') !== false ||
            strpos(strtolower($table), 'deck') !== false) {
            echo "<option value='$table'>$table</option>";
        }
    }
    echo "</select><br><br>";
    
    echo "<label><strong>Feld für Kartenname:</strong></label><br>";
    echo "<input type='text' name='name_field' value='name' required style='padding: 5px; margin: 5px 0; width: 200px;' placeholder='z.B. name, card_name'><br><br>";
    
    echo "<label><strong>Feld für Anzahl (optional):</strong></label><br>";
    echo "<input type='text' name='quantity_field' value='quantity' style='padding: 5px; margin: 5px 0; width: 200px;' placeholder='z.B. quantity, count'><br><br>";
    
    echo "<button type='submit' style='background: #28a745; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;'>Migration starten</button>";
    echo "</form>";
    
} catch (Exception $e) {
    echo "<p style='color: red; background: #f8d7da; padding: 15px; border-radius: 5px;'>";
    echo "<strong>Fehler:</strong> " . $e->getMessage();
    echo "</p>";
}

function fetchCardAPI($card_name) {
    $url = "https://api.scryfall.com/cards/named?exact=" . urlencode($card_name);
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
            'user_agent' => 'MTG Collection Manager/1.0'
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        return [
            'name' => $card_name,
            'mana_cost' => '',
            'type_line' => 'Unknown',
            'colors' => [],
            'image_url' => ''
        ];
    }
    
    $data = json_decode($response, true);
    
    if (!$data || isset($data['object']) && $data['object'] === 'error') {
        return [
            'name' => $card_name,
            'mana_cost' => '',
            'type_line' => 'Unknown', 
            'colors' => [],
            'image_url' => ''
        ];
    }
    
    return [
        'name' => $data['name'] ?? $card_name,
        'mana_cost' => $data['mana_cost'] ?? '',
        'cmc' => $data['cmc'] ?? 0,
        'type_line' => $data['type_line'] ?? '',
        'oracle_text' => $data['oracle_text'] ?? '',
        'colors' => $data['colors'] ?? [],
        'color_identity' => $data['color_identity'] ?? [],
        'power' => $data['power'] ?? null,
        'toughness' => $data['toughness'] ?? null,
        'rarity' => $data['rarity'] ?? '',
        'set_name' => $data['set_name'] ?? '',
        'set' => $data['set'] ?? '',
        'image_url' => $data['image_uris']['normal'] ?? ''
    ];
}

?>

<!DOCTYPE html>
<html>
<head>
    <title>MTG Setup</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; max-width: 1000px; }
        details { margin: 10px 0; padding: 10px; border: 1px solid #ddd; }
        summary { cursor: pointer; font-weight: bold; }
    </style>
</head>
<body>
    <!-- Content wird durch PHP generiert -->
</body>
</html>
