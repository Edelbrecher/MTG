<?php
session_start();
require_once 'config/database.php';

// Simuliere User-Login für Test
if (!isset($_SESSION['user_id'])) {
    $stmt = $pdo->query("SELECT id FROM users LIMIT 1");
    $user = $stmt->fetch();
    if ($user) {
        $_SESSION['user_id'] = $user['id'];
        echo "🔧 Simulierter Login als User ID: {$user['id']}\n";
    }
}

$user_id = $_SESSION['user_id'];

// Simuliere einen echten Bulk-Import
echo "🚀 Simuliere Bulk-Import...\n";

$import_session_id = md5(uniqid($user_id . time(), true));
echo "📝 Session ID: {$import_session_id}\n";

try {
    // Simuliere Karten-Import
    $test_cards = ['Lightning Bolt', 'Counterspell', 'Giant Growth'];
    
    foreach ($test_cards as $index => $card_name) {
        // Simuliere Collection-Insert
        $stmt = $pdo->prepare("INSERT INTO collections (user_id, card_name, card_data, quantity) VALUES (?, ?, ?, 1)");
        $fake_card_data = json_encode([
            'name' => $card_name,
            'mana_cost' => '{R}',
            'type_line' => 'Instant',
            'oracle_text' => 'Test card',
            'cmc' => 1,
            'rarity' => 'common'
        ]);
        $stmt->execute([$user_id, $card_name, $fake_card_data]);
        $collection_id = $pdo->lastInsertId();
        
        // Import-Card tracking
        $stmt = $pdo->prepare("INSERT INTO import_cards (import_session_id, user_id, card_name, quantity, collection_id, import_order, status) VALUES (?, ?, ?, 1, ?, ?, 'success')");
        $stmt->execute([$import_session_id, $user_id, $card_name, $collection_id, $index + 1]);
        
        echo "  ✅ {$card_name} hinzugefügt (Collection ID: {$collection_id})\n";
    }
    
    // Import-History erstellen
    $import_summary = [
        'total_processed' => count($test_cards),
        'total_success' => count($test_cards),
        'total_errors' => 0,
        'failed_cards' => [],
        'session_id' => $import_session_id
    ];
    
    $stmt = $pdo->prepare("INSERT INTO import_history (user_id, import_session_id, total_cards, successful_cards, failed_cards, import_summary, status) VALUES (?, ?, ?, ?, ?, ?, 'completed')");
    $stmt->execute([
        $user_id, 
        $import_session_id, 
        count($test_cards), 
        count($test_cards), 
        0, 
        json_encode($import_summary)
    ]);
    
    echo "✅ Import-History erstellt\n";
    
    // Teste getImportHistory Funktion
    function getImportHistory($pdo, $user_id, $limit = 3) {
        try {
            $limit = (int)$limit;
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
    
    echo "\n📋 Teste Import-Historie nach Import:\n";
    $history = getImportHistory($pdo, $user_id, 3);
    
    echo "  📊 Gefundene Einträge: " . count($history) . "\n";
    foreach ($history as $item) {
        echo "  📅 {$item['import_date']} - Session: {$item['import_session_id']}\n";
        echo "     Status: {$item['status']}, Karten: {$item['total_cards']}\n";
    }
    
    // Teste JSON-Encoding für Frontend
    echo "\n🔧 JSON für Frontend:\n";
    $json = json_encode($history);
    if ($json) {
        echo "✅ JSON erfolgreich (" . strlen($json) . " Zeichen)\n";
        
        // Zeige ersten Eintrag
        if (!empty($history)) {
            $first = $history[0];
            echo "  📄 Erster Eintrag:\n";
            echo "     ID: {$first['id']}\n";
            echo "     Session: {$first['import_session_id']}\n";
            echo "     Datum: {$first['import_date']}\n";
            echo "     Status: {$first['status']}\n";
        }
    } else {
        echo "❌ JSON Encoding fehlgeschlagen\n";
    }
    
    // URL für Frontend-Test
    echo "\n🌐 Frontend-Test URLs:\n";
    echo "  Bulk Import: http://localhost/MTG/bulk_import.php\n";
    echo "  Collection: http://localhost/MTG/collection.php\n";
    
    echo "\n✅ Test abgeschlossen - Import-Historie sollte jetzt im Frontend sichtbar sein!\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}
?>
