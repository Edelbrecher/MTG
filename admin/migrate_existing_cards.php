<?php
session_start();
require_once '../config/database.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || !$_SESSION['is_admin']) {
    header('Location: ../index.php');
    exit();
}

// Show existing table structure
echo "<h2>Bestehende Tabellenstruktur analysieren</h2>";

try {
    // Show all tables
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "<h3>Vorhandene Tabellen:</h3><ul>";
    foreach ($tables as $table) {
        echo "<li><strong>$table</strong></li>";
        
        // Show table structure
        $columns = $pdo->query("DESCRIBE $table")->fetchAll();
        echo "<ul>";
        foreach ($columns as $column) {
            echo "<li>{$column['Field']} - {$column['Type']} " . 
                 ($column['Null'] == 'NO' ? '(NOT NULL)' : '') . 
                 ($column['Key'] ? " [{$column['Key']}]" : '') . "</li>";
        }
        echo "</ul>";
        
        // Show row count
        $count = $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        echo "<p>Anzahl Einträge: <strong>$count</strong></p>";
        
        // Show sample data for cards table
        if (strpos(strtolower($table), 'card') !== false && $count > 0) {
            echo "<h4>Beispieldaten aus $table:</h4>";
            $samples = $pdo->query("SELECT * FROM $table LIMIT 3")->fetchAll();
            echo "<pre>" . print_r($samples, true) . "</pre>";
        }
    }
    echo "</ul>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>Fehler: " . $e->getMessage() . "</p>";
}

// Migration form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['migrate'])) {
    $source_table = $_POST['source_table'];
    $card_name_field = $_POST['card_name_field'];
    $user_id = intval($_POST['target_user_id']);
    
    try {
        // Get all cards from source table
        $stmt = $pdo->prepare("SELECT * FROM $source_table");
        $stmt->execute();
        $existing_cards = $stmt->fetchAll();
        
        $migrated = 0;
        foreach ($existing_cards as $card) {
            $card_name = $card[$card_name_field];
            
            // Try to fetch card data from API
            $card_data = fetchCardDataForMigration($card_name);
            
            // Insert into collections table
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO collections (user_id, card_name, card_data, quantity) 
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $user_id, 
                $card_name, 
                json_encode($card_data), 
                $card['quantity'] ?? 1
            ]);
            
            if ($stmt->rowCount() > 0) {
                $migrated++;
            }
        }
        
        echo "<div style='background: green; color: white; padding: 1rem; margin: 1rem 0;'>";
        echo "Migration abgeschlossen! $migrated Karten wurden übertragen.";
        echo "</div>";
        
    } catch (PDOException $e) {
        echo "<div style='background: red; color: white; padding: 1rem; margin: 1rem 0;'>";
        echo "Migration fehlgeschlagen: " . $e->getMessage();
        echo "</div>";
    }
}

function fetchCardDataForMigration($card_name) {
    $url = "https://api.scryfall.com/cards/named?exact=" . urlencode($card_name);
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
            'user_agent' => 'MTG Collection Manager/1.0'
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        // Return basic structure if API call fails
        return [
            'name' => $card_name,
            'mana_cost' => '',
            'type_line' => '',
            'oracle_text' => '',
            'colors' => [],
            'image_url' => ''
        ];
    }
    
    $data = json_decode($response, true);
    
    if (!$data || isset($data['object']) && $data['object'] === 'error') {
        return [
            'name' => $card_name,
            'mana_cost' => '',
            'type_line' => '',
            'oracle_text' => '',
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
    <title>Migration Tool</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        form { background: #f5f5f5; padding: 20px; margin: 20px 0; }
        input, select { padding: 5px; margin: 5px; }
        button { padding: 10px 20px; background: #007cba; color: white; border: none; }
        pre { background: #f0f0f0; padding: 10px; overflow: auto; max-height: 300px; }
    </style>
</head>
<body>
    <h1>Kartenmigration Tool</h1>
    <p><a href="index.php">← Zurück zum Admin Dashboard</a></p>
    
    <form method="POST">
        <h3>Bestehende Karten migrieren</h3>
        <p>Wählen Sie die Quelltabelle und konfigurieren Sie die Migration:</p>
        
        <label>Quelltabelle:</label>
        <select name="source_table" required>
            <?php
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                if (strpos(strtolower($table), 'card') !== false || 
                    strpos(strtolower($table), 'collection') !== false) {
                    echo "<option value='$table'>$table</option>";
                }
            }
            ?>
        </select><br>
        
        <label>Feld für Kartenname:</label>
        <input type="text" name="card_name_field" value="name" required 
               placeholder="z.B. name, card_name, title"><br>
        
        <label>Ziel-Benutzer ID (für collections Tabelle):</label>
        <input type="number" name="target_user_id" value="1" required><br>
        
        <button type="submit" name="migrate">Migration starten</button>
    </form>
    
    <p><strong>Hinweis:</strong> Erstellen Sie ein Backup Ihrer Datenbank vor der Migration!</p>
</body>
</html>
