<?php
session_start();
require_once 'config/database.php';

// Prüfe ob User eingeloggt ist
if (!isset($_SESSION['user_id'])) {
    echo "❌ Nicht eingeloggt - Session-Daten:\n";
    print_r($_SESSION);
    exit();
}

$user_id = $_SESSION['user_id'];
echo "👤 User ID: {$user_id}\n\n";

try {
    // Zeige Import-Historie
    echo "📋 Import-Historie:\n";
    $stmt = $pdo->prepare("SELECT * FROM import_history WHERE user_id = ? ORDER BY import_date DESC LIMIT 5");
    $stmt->execute([$user_id]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($history)) {
        echo "  ❌ Keine Import-Historie gefunden\n";
    } else {
        foreach ($history as $item) {
            echo "  📅 {$item['import_date']} - Session: {$item['import_session_id']}\n";
            echo "     📊 {$item['total_cards']} Karten, {$item['successful_cards']} erfolgreich, Status: {$item['status']}\n\n";
        }
    }
    
    // Zeige Import-Cards
    echo "\n🃏 Import-Cards (letzte 10):\n";
    $stmt = $pdo->prepare("SELECT * FROM import_cards WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$user_id]);
    $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($cards)) {
        echo "  ❌ Keine Import-Cards gefunden\n";
    } else {
        foreach ($cards as $card) {
            echo "  🃏 {$card['card_name']} - Session: {$card['import_session_id']}, Status: {$card['status']}\n";
        }
    }
    
    // Zeige Collections-Anzahl
    echo "\n📚 Collections-Anzahl:\n";
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM collections WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $count = $stmt->fetch()['count'];
    echo "  📊 {$count} Karten in der Sammlung\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}
?>
