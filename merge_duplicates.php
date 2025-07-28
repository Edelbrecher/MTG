<?php
require_once 'config/database.php';

echo "<h2>Duplikate-Bereinigung: Quantities zusammenfassen</h2>";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['merge_duplicates'])) {
    echo "<h3>Bereinigung wird durchgeführt...</h3>";
    
    try {
        $pdo->beginTransaction();
        
        // Finde alle Karten mit Duplikaten
        $stmt = $pdo->query('
            SELECT card_name, COUNT(*) as count, SUM(quantity) as total_quantity
            FROM collections 
            GROUP BY card_name 
            HAVING COUNT(*) > 1
        ');
        $duplicates = $stmt->fetchAll();
        
        $merged_count = 0;
        $total_removed = 0;
        
        foreach ($duplicates as $duplicate) {
            $card_name = $duplicate['card_name'];
            $total_quantity = $duplicate['total_quantity'];
            
            echo "<p>🔄 Verarbeite: " . htmlspecialchars($card_name) . " (" . $duplicate['count'] . " Duplikate, " . $total_quantity . " Gesamtmenge)</p>";
            
            // Hole alle Einträge für diese Karte
            $stmt = $pdo->prepare('SELECT id, quantity, card_data FROM collections WHERE card_name = ? ORDER BY id ASC');
            $stmt->execute([$card_name]);
            $entries = $stmt->fetchAll();
            
            if (count($entries) > 1) {
                // Behalte den ersten Eintrag und aktualisiere seine Quantity
                $keep_entry = $entries[0];
                
                // Aktualisiere die Quantity des ersten Eintrags mit der Gesamtsumme
                $stmt = $pdo->prepare('UPDATE collections SET quantity = ? WHERE id = ?');
                $stmt->execute([$total_quantity, $keep_entry['id']]);
                
                // Lösche alle anderen Einträge
                $ids_to_delete = [];
                for ($i = 1; $i < count($entries); $i++) {
                    $ids_to_delete[] = $entries[$i]['id'];
                }
                
                if (!empty($ids_to_delete)) {
                    $placeholders = str_repeat('?,', count($ids_to_delete) - 1) . '?';
                    $stmt = $pdo->prepare("DELETE FROM collections WHERE id IN ($placeholders)");
                    $stmt->execute($ids_to_delete);
                    
                    $total_removed += count($ids_to_delete);
                }
                
                $merged_count++;
                echo "<p>✅ Zusammengefasst: ID " . $keep_entry['id'] . " behält " . $total_quantity . " Karten, " . count($ids_to_delete) . " Duplikate entfernt</p>";
            }
        }
        
        $pdo->commit();
        
        echo "<h3>Bereinigung abgeschlossen! 🎉</h3>";
        echo "<p><strong>$merged_count</strong> Karten wurden zusammengefasst</p>";
        echo "<p><strong>$total_removed</strong> doppelte Einträge wurden entfernt</p>";
        
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
    // Analyse der Duplikate
    echo "<h3>Aktuelle Duplikate-Analyse:</h3>";
    
    // Gesamtstatistik vor Bereinigung
    $stmt = $pdo->query('SELECT COUNT(*) FROM collections');
    $total_entries = $stmt->fetchColumn();
    
    $stmt = $pdo->query('SELECT SUM(quantity) FROM collections');
    $total_cards = $stmt->fetchColumn();
    
    $stmt = $pdo->query('SELECT COUNT(DISTINCT card_name) FROM collections');
    $unique_names = $stmt->fetchColumn();
    
    echo "<p><strong>Aktuelle Situation:</strong></p>";
    echo "<ul>";
    echo "<li>Gesamte Einträge in collections: <strong>$total_entries</strong></li>";
    echo "<li>Einzigartige Kartennamen: <strong>$unique_names</strong></li>";
    echo "<li>Gesamte Karten (mit Quantities): <strong>$total_cards</strong></li>";
    echo "<li>Doppelte Einträge: <strong>" . ($total_entries - $unique_names) . "</strong></li>";
    echo "</ul>";
    
    if ($total_entries > $unique_names) {
        echo "<p style='color: orange;'>⚠️ Es gibt " . ($total_entries - $unique_names) . " doppelte Einträge, die bereinigt werden sollten.</p>";
    } else {
        echo "<p style='color: green;'>✅ Keine Duplikate gefunden!</p>";
    }
    
    // Zeige die Top-Duplikate
    echo "<h3>Top 15 Karten mit Duplikaten:</h3>";
    $stmt = $pdo->query('
        SELECT card_name, COUNT(*) as entries, SUM(quantity) as total_quantity
        FROM collections 
        GROUP BY card_name 
        HAVING COUNT(*) > 1
        ORDER BY COUNT(*) DESC, SUM(quantity) DESC
        LIMIT 15
    ');
    $duplicates = $stmt->fetchAll();
    
    if (empty($duplicates)) {
        echo "<p>Keine Duplikate gefunden! 🎉</p>";
    } else {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr style='background-color: #f0f0f0;'>";
        echo "<th>Kartenname</th><th>Anzahl Einträge</th><th>Gesamte Quantity</th><th>Wird zu</th>";
        echo "</tr>";
        
        foreach ($duplicates as $dup) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($dup['card_name']) . "</td>";
            echo "<td style='text-align: center;'>" . $dup['entries'] . "</td>";
            echo "<td style='text-align: center;'>" . $dup['total_quantity'] . "</td>";
            echo "<td style='text-align: center; color: green;'><strong>1 Eintrag mit " . $dup['total_quantity'] . " Quantity</strong></td>";
            echo "</tr>";
        }
        echo "</table>";
        
        echo "<h3>Bereinigung starten:</h3>";
        echo "<div style='background-color: #fff3cd; padding: 15px; border: 1px solid #ffeaa7; border-radius: 5px; margin: 10px 0;'>";
        echo "<h4>⚠️ Wichtiger Hinweis:</h4>";
        echo "<p>Diese Operation wird:</p>";
        echo "<ul>";
        echo "<li>✅ <strong>Alle Quantities korrekt addieren</strong> (keine Karten gehen verloren)</li>";
        echo "<li>✅ <strong>Duplikate zu einem Eintrag zusammenfassen</strong></li>";
        echo "<li>✅ <strong>Die älteste Karten-ID beibehalten</strong> (für Referenzen)</li>";
        echo "<li>✅ <strong>Ein Backup ist empfohlen</strong>, aber die Operation ist sicher</li>";
        echo "</ul>";
        echo "</div>";
        
        echo "<form method='POST'>";
        echo "<button type='submit' name='merge_duplicates' style='padding: 15px 30px; background: #28a745; color: white; border: none; border-radius: 5px; font-size: 16px; cursor: pointer;'>";
        echo "🔧 Duplikate zusammenfassen (Quantities addieren)";
        echo "</button>";
        echo "</form>";
    }
}

echo "<p><a href='collection.php'>← Zurück zur Collection</a></p>";
?>
