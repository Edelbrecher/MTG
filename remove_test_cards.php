<?php
require_once 'config/database.php';

echo "<h2>Testkarten-Bereinigung</h2>";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_test_cards'])) {
    echo "<h3>Entferne Testkarten...</h3>";
    
    try {
        $pdo->beginTransaction();
        
        // Definiere Muster für Testkarten
        $test_patterns = [
            '%test%',
            '%Test%', 
            '%TEST%',
            '%dummy%',
            '%sample%',
            '%beispiel%',
            'Test Lightning Bolt',
            '%placeholder%'
        ];
        
        $total_removed = 0;
        
        foreach ($test_patterns as $pattern) {
            // Hole Testkarten mit diesem Muster
            $stmt = $pdo->prepare('SELECT id, card_name, quantity FROM collections WHERE card_name LIKE ?');
            $stmt->execute([$pattern]);
            $test_cards = $stmt->fetchAll();
            
            foreach ($test_cards as $card) {
                echo "<p>🗑️ Entferne: " . htmlspecialchars($card['card_name']) . " (" . $card['quantity'] . "x)</p>";
                
                // Lösche die Karte
                $stmt = $pdo->prepare('DELETE FROM collections WHERE id = ?');
                $stmt->execute([$card['id']]);
                
                $total_removed++;
            }
        }
        
        // Spezielle Behandlung für Lightning Bolt Duplikate (behalte nur normale Version)
        $stmt = $pdo->query('SELECT id, card_name, quantity FROM collections WHERE card_name LIKE "%Lightning Bolt%" ORDER BY card_name');
        $lightning_cards = $stmt->fetchAll();
        
        $keep_normal = false;
        foreach ($lightning_cards as $card) {
            if ($card['card_name'] === 'Lightning Bolt' && !$keep_normal) {
                // Behalte die erste normale Lightning Bolt
                $keep_normal = true;
                echo "<p>✅ Behalte: " . htmlspecialchars($card['card_name']) . " (" . $card['quantity'] . "x)</p>";
            } elseif ($card['card_name'] !== 'Lightning Bolt' || $keep_normal) {
                // Entferne alle anderen Lightning Bolt Varianten
                echo "<p>🗑️ Entferne Lightning Bolt Variante: " . htmlspecialchars($card['card_name']) . " (" . $card['quantity'] . "x)</p>";
                
                $stmt = $pdo->prepare('DELETE FROM collections WHERE id = ?');
                $stmt->execute([$card['id']]);
                
                $total_removed++;
            }
        }
        
        $pdo->commit();
        
        echo "<h3>Bereinigung abgeschlossen! 🎉</h3>";
        echo "<p><strong>$total_removed</strong> Testkarten wurden entfernt</p>";
        
        // Aktualisierte Statistiken
        $stmt = $pdo->query('SELECT COUNT(*) FROM collections');
        $new_count = $stmt->fetchColumn();
        
        $stmt = $pdo->query('SELECT SUM(quantity) FROM collections');
        $new_total = $stmt->fetchColumn();
        
        echo "<p>Neue Statistiken: <strong>$new_count</strong> einzigartige Karten, <strong>$new_total</strong> Karten gesamt</p>";
        
        // Update der View
        $stmt = $pdo->query('DROP VIEW IF EXISTS user_collection_summary');
        $stmt = $pdo->query('
            CREATE VIEW user_collection_summary AS 
            SELECT 
                user_id,
                COUNT(*) as unique_cards,
                SUM(quantity) as total_cards
            FROM collections 
            GROUP BY user_id
        ');
        
        echo "<p>✅ user_collection_summary View wurde aktualisiert</p>";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<p style='color: red;'>❌ Fehler: " . $e->getMessage() . "</p>";
    }
    
} else {
    // Analyse der Testkarten
    echo "<h3>Suche nach Testkarten:</h3>";
    
    // Definiere Suchmuster
    $test_patterns = [
        '%test%' => 'Karten mit "test"',
        '%Test%' => 'Karten mit "Test"', 
        '%TEST%' => 'Karten mit "TEST"',
        '%dummy%' => 'Dummy-Karten',
        '%sample%' => 'Sample-Karten',
        '%beispiel%' => 'Beispiel-Karten',
        '%placeholder%' => 'Placeholder-Karten'
    ];
    
    $total_test_cards = 0;
    $found_any = false;
    
    foreach ($test_patterns as $pattern => $description) {
        $stmt = $pdo->prepare('SELECT card_name, quantity FROM collections WHERE card_name LIKE ?');
        $stmt->execute([$pattern]);
        $test_cards = $stmt->fetchAll();
        
        if (!empty($test_cards)) {
            $found_any = true;
            echo "<h4>$description:</h4>";
            echo "<ul>";
            foreach ($test_cards as $card) {
                echo "<li>" . htmlspecialchars($card['card_name']) . " (" . $card['quantity'] . "x)</li>";
                $total_test_cards += $card['quantity'];
            }
            echo "</ul>";
        }
    }
    
    // Spezielle Suche nach Lightning Bolt Varianten
    echo "<h4>Lightning Bolt Varianten:</h4>";
    $stmt = $pdo->query('SELECT card_name, quantity FROM collections WHERE card_name LIKE "%Lightning Bolt%" ORDER BY card_name');
    $lightning_cards = $stmt->fetchAll();
    
    if (!empty($lightning_cards)) {
        $found_any = true;
        echo "<ul>";
        foreach ($lightning_cards as $card) {
            echo "<li>" . htmlspecialchars($card['card_name']) . " (" . $card['quantity'] . "x)";
            if ($card['card_name'] !== 'Lightning Bolt') {
                echo " <span style='color: red;'>[TESTKARTE?]</span>";
                $total_test_cards += $card['quantity'];
            }
            echo "</li>";
        }
        echo "</ul>";
    }
    
    if (!$found_any) {
        echo "<p style='color: green;'>✅ Keine Testkarten gefunden!</p>";
    } else {
        echo "<div style='background-color: #fff3cd; padding: 15px; border: 1px solid #ffeaa7; border-radius: 5px; margin: 10px 0;'>";
        echo "<h4>⚠️ Gefundene Testkarten:</h4>";
        echo "<p>Insgesamt <strong>$total_test_cards</strong> Testkarten gefunden</p>";
        echo "<p>Diese Operation wird:</p>";
        echo "<ul>";
        echo "<li>🗑️ Alle Karten mit Test-Mustern entfernen</li>";
        echo "<li>🗑️ Lightning Bolt Duplikate/Varianten bereinigen (normale Version beibehalten)</li>";
        echo "<li>✅ Nur echte Sammlungskarten beibehalten</li>";
        echo "<li>✅ Die user_collection_summary View aktualisieren</li>";
        echo "</ul>";
        echo "</div>";
        
        echo "<form method='POST'>";
        echo "<button type='submit' name='remove_test_cards' style='padding: 15px 30px; background: #dc3545; color: white; border: none; border-radius: 5px; font-size: 16px; cursor: pointer;'>";
        echo "🗑️ Testkarten entfernen";
        echo "</button>";
        echo "</form>";
    }
    
    // Aktuelle Statistiken
    $stmt = $pdo->query('SELECT COUNT(*) FROM collections');
    $total_cards = $stmt->fetchColumn();
    
    $stmt = $pdo->query('SELECT SUM(quantity) FROM collections');
    $total_quantity = $stmt->fetchColumn();
    
    echo "<h3>Aktuelle Sammlung:</h3>";
    echo "<p>Gesamte Karten: <strong>$total_cards</strong> Einträge</p>";
    echo "<p>Gesamte Quantity: <strong>$total_quantity</strong> Karten</p>";
}

echo "<p><a href='collection.php'>← Zurück zur Collection</a></p>";
?>
