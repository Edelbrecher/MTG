<?php
session_start();
require_once 'config/database.php';

// Simuliere User-Login
if (!isset($_SESSION['user_id'])) {
    // Hole ersten User aus der Datenbank für Test
    $stmt = $pdo->query("SELECT id FROM users LIMIT 1");
    $user = $stmt->fetch();
    if ($user) {
        $_SESSION['user_id'] = $user['id'];
        echo "🔧 Test-Login als User ID: {$user['id']}\n";
    } else {
        echo "❌ Keine User in der Datenbank gefunden\n";
        exit();
    }
}

$user_id = $_SESSION['user_id'];

try {
    // Teste Import-Session Creation
    $import_session_id = md5(uniqid($user_id . time(), true));
    echo "🆔 Test Session ID: {$import_session_id}\n";
    
    // Teste Import-Cards Insert
    echo "\n📝 Teste import_cards Insert...\n";
    $stmt = $pdo->prepare("INSERT INTO import_cards (import_session_id, user_id, card_name, quantity, collection_id, import_order, status) VALUES (?, ?, ?, 1, NULL, 1, 'success')");
    $result = $stmt->execute([$import_session_id, $user_id, 'Test Card']);
    echo "✅ Import-Card Insert: " . ($result ? "Erfolgreich" : "Fehlgeschlagen") . "\n";
    
    // Teste Import-History Insert
    echo "\n📝 Teste import_history Insert...\n";
    $import_summary = [
        'total_processed' => 1,
        'total_success' => 1,
        'total_errors' => 0,
        'failed_cards' => [],
        'session_id' => $import_session_id
    ];
    
    $stmt = $pdo->prepare("INSERT INTO import_history (user_id, import_session_id, total_cards, successful_cards, failed_cards, import_summary, status) VALUES (?, ?, ?, ?, ?, ?, 'completed')");
    $result = $stmt->execute([
        $user_id, 
        $import_session_id, 
        1, 
        1, 
        0, 
        json_encode($import_summary)
    ]);
    echo "✅ Import-History Insert: " . ($result ? "Erfolgreich" : "Fehlgeschlagen") . "\n";
    
    // Teste getImportHistory Funktion
    echo "\n📋 Teste getImportHistory Funktion...\n";
    
    function getImportHistory($pdo, $user_id, $limit = 3) {
        try {
            $limit = (int)$limit; // Sicherheit: Cast zu Integer
            if ($limit <= 0) $limit = 3;
            
            echo "🔧 Debug: user_id={$user_id}, limit={$limit}\n";
            
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
                
            echo "🔧 Debug SQL: " . $sql . "\n";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$user_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            echo "❌ SQL Error: " . $e->getMessage() . "\n";
            error_log("Error loading import history: " . $e->getMessage());
            return [];
        }
    }
    
    $history = getImportHistory($pdo, $user_id, 3);
    echo "📊 Gefundene Historie-Einträge: " . count($history) . "\n";
    
    foreach ($history as $item) {
        echo "  📅 {$item['import_date']} - Session: {$item['import_session_id']}\n";
        echo "     📊 {$item['total_cards']} total, {$item['successful_cards']} erfolgreich, Status: {$item['status']}\n";
    }
    
    // Test JSON Encoding für Frontend
    echo "\n🔧 Teste JSON Encoding für Frontend...\n";
    $json = json_encode($history);
    if ($json === false) {
        echo "❌ JSON Encoding fehlgeschlagen: " . json_last_error_msg() . "\n";
    } else {
        echo "✅ JSON Encoding erfolgreich (" . strlen($json) . " Zeichen)\n";
        echo "📄 JSON Preview: " . substr($json, 0, 200) . "...\n";
    }
    
    // Cleanup Test-Daten
    echo "\n🧹 Cleanup Test-Daten...\n";
    $stmt = $pdo->prepare("DELETE FROM import_cards WHERE import_session_id = ?");
    $stmt->execute([$import_session_id]);
    
    $stmt = $pdo->prepare("DELETE FROM import_history WHERE import_session_id = ?");
    $stmt->execute([$import_session_id]);
    
    echo "✅ Test-Daten gelöscht\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}
?>
