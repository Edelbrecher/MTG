<?php
/**
 * Commander Deck Cleanup Utility
 * Removes duplicate cards from existing Commander decks
 */

require_once 'config/database.php';

function cleanupCommanderDecks($pdo) {
    try {
        echo "🧹 Starte Bereinigung aller Commander-Decks...\n";
        
        // Get all Commander decks
        $stmt = $pdo->query("SELECT id, name FROM decks WHERE format_type = 'Commander'");
        $commander_decks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "📋 Gefundene Commander-Decks: " . count($commander_decks) . "\n";
        
        $total_cleaned = 0;
        
        foreach ($commander_decks as $deck) {
            echo "\n🔍 Bereinige Deck: {$deck['name']} (ID: {$deck['id']})\n";
            
            // Remove duplicates (except basic lands)
            $cleanup_sql = "
                DELETE dc1 FROM deck_cards dc1
                INNER JOIN deck_cards dc2 
                WHERE dc1.id > dc2.id 
                  AND dc1.deck_id = ? 
                  AND dc1.card_name = dc2.card_name 
                  AND dc1.is_commander = 0 
                  AND dc2.is_commander = 0
                  AND dc1.card_name NOT IN ('Plains', 'Island', 'Swamp', 'Mountain', 'Forest', 'Wastes')
            ";
            
            $stmt = $pdo->prepare($cleanup_sql);
            $stmt->execute([$deck['id']]);
            $cleaned = $stmt->rowCount();
            
            if ($cleaned > 0) {
                echo "  🧹 {$cleaned} Duplikate entfernt\n";
                $total_cleaned += $cleaned;
            } else {
                echo "  ✅ Keine Duplikate gefunden\n";
            }
            
            // Show final stats
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total_cards,
                    COUNT(DISTINCT card_name) as unique_cards
                FROM deck_cards 
                WHERE deck_id = ? AND is_commander = 0
            ");
            $stmt->execute([$deck['id']]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo "  📊 Finales Deck: {$stats['total_cards']} Karten ({$stats['unique_cards']} einzigartig)\n";
        }
        
        echo "\n🎉 Bereinigung abgeschlossen!\n";
        echo "📈 Gesamt entfernte Duplikate: {$total_cleaned}\n";
        
        return $total_cleaned;
        
    } catch (Exception $e) {
        echo "❌ Fehler bei der Bereinigung: " . $e->getMessage() . "\n";
        return false;
    }
}

// Run cleanup if called directly
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    cleanupCommanderDecks($pdo);
}
?>
