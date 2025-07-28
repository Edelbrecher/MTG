<?php
require_once 'config/database.php';

echo "<h2>Quantity-Synchronisation zwischen cards und collections</h2>";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sync'])) {
    echo "<h3>Synchronisation wird durchgeführt...</h3>";
    
    try {
        $pdo->beginTransaction();
        
        // Hole alle Karten aus der cards Tabelle
        $stmt = $pdo->query('SELECT name, quantity FROM cards');
        $old_cards = $stmt->fetchAll();
        
        $updated = 0;
        $missing = 0;
        $errors = [];
        
        foreach ($old_cards as $old_card) {
            // Prüfe ob Karte in collections existiert
            $stmt = $pdo->prepare('SELECT id, quantity FROM collections WHERE card_name = ?');
            $stmt->execute([$old_card['name']]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                // Update quantity wenn unterschiedlich
                if ($existing['quantity'] != $old_card['quantity']) {
                    $stmt = $pdo->prepare('UPDATE collections SET quantity = ? WHERE id = ?');
                    $stmt->execute([$old_card['quantity'], $existing['id']]);
                    $updated++;
                    echo "<p>✅ Aktualisiert: " . htmlspecialchars($old_card['name']) . 
                         " von {$existing['quantity']} auf {$old_card['quantity']}</p>";
                }
            } else {
                $missing++;
                echo "<p>❌ Karte fehlt in collections: " . htmlspecialchars($old_card['name']) . "</p>";
            }
        }
        
        $pdo->commit();
        
        echo "<h3>Synchronisation abgeschlossen!</h3>";
        echo "<p>Aktualisiert: $updated Karten</p>";
        echo "<p>Fehlend: $missing Karten</p>";
        
        // Update der user_collection_summary View
        $stmt = $pdo->prepare('SELECT unique_cards, total_cards FROM user_collection_summary WHERE user_id = ?');
        $stmt->execute([1]);
        $summary = $stmt->fetch();
        
        echo "<p>Neue Statistiken: {$summary['unique_cards']} einzigartige Karten, {$summary['total_cards']} Karten gesamt</p>";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<p style='color: red;'>Fehler: " . $e->getMessage() . "</p>";
    }
} else {
    // Analyse vor Synchronisation
    echo "<h3>Analyse der aktuellen Situation:</h3>";
    
    $stmt = $pdo->query('SELECT COUNT(*) FROM cards');
    $cards_count = $stmt->fetchColumn();
    
    $stmt = $pdo->query('SELECT COUNT(*) FROM collections');
    $collections_count = $stmt->fetchColumn();
    
    $stmt = $pdo->query('SELECT SUM(quantity) FROM cards');
    $cards_total = $stmt->fetchColumn();
    
    $stmt = $pdo->query('SELECT SUM(quantity) FROM collections');
    $collections_total = $stmt->fetchColumn();
    
    echo "<table border='1'>";
    echo "<tr><th>Tabelle</th><th>Anzahl Karten</th><th>Gesamtmenge</th></tr>";
    echo "<tr><td>cards (alt)</td><td>$cards_count</td><td>$cards_total</td></tr>";
    echo "<tr><td>collections (neu)</td><td>$collections_count</td><td>$collections_total</td></tr>";
    echo "</table>";
    
    if ($cards_total != $collections_total) {
        echo "<p style='color: red;'>⚠️ Die Gesamtmengen stimmen nicht überein! Synchronisation empfohlen.</p>";
    } else {
        echo "<p style='color: green;'>✅ Die Gesamtmengen stimmen überein.</p>";
    }
    
    // Prüfe auf Unterschiede bei spezifischen Karten
    echo "<h3>Prüfung von Karten mit Menge > 1:</h3>";
    $stmt = $pdo->query('SELECT name, quantity FROM cards WHERE quantity > 1');
    $multi_cards = $stmt->fetchAll();
    
    if (empty($multi_cards)) {
        echo "<p>Keine Karten mit Menge > 1 gefunden.</p>";
    } else {
        echo "<table border='1'>";
        echo "<tr><th>Kartenname</th><th>cards Menge</th><th>collections Menge</th><th>Status</th></tr>";
        
        foreach ($multi_cards as $card) {
            $stmt = $pdo->prepare('SELECT quantity FROM collections WHERE card_name = ?');
            $stmt->execute([$card['name']]);
            $new_qty = $stmt->fetchColumn();
            
            echo "<tr>";
            echo "<td>" . htmlspecialchars($card['name']) . "</td>";
            echo "<td>" . $card['quantity'] . "</td>";
            echo "<td>" . ($new_qty ?: 'FEHLT') . "</td>";
            if ($new_qty && $card['quantity'] == $new_qty) {
                echo "<td style='color: green;'>✅ OK</td>";
            } else {
                echo "<td style='color: red;'>❌ SYNC NÖTIG</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    }
    
    echo "<h3>Synchronisation starten:</h3>";
    echo "<form method='POST'>";
    echo "<p>Dies wird alle quantity-Werte aus der 'cards' Tabelle in die 'collections' Tabelle übertragen.</p>";
    echo "<button type='submit' name='sync' style='padding: 10px 20px; background: #007cba; color: white; border: none;'>Quantity-Werte synchronisieren</button>";
    echo "</form>";
}
?>
