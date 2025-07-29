<?php
require_once 'config/database.php';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>Debug: Bildstruktur in der Datenbank</h2>";
    
    // Abfrage für ältere Karten (wahrscheinlich mit Bildern)
    echo "<h3>ÄLTERE KARTEN (mit Bildern):</h3>";
    $stmt = $pdo->prepare("SELECT card_name, card_data FROM collections ORDER BY id ASC LIMIT 3");
    $stmt->execute();
    $old_cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Abfrage für neuere Karten (wahrscheinlich ohne Bilder)
    echo "<h3>NEUERE KARTEN (möglicherweise ohne Bilder):</h3>";
    $stmt = $pdo->prepare("SELECT card_name, card_data FROM collections ORDER BY id DESC LIMIT 3");
    $stmt->execute();
    $new_cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $all_cards = array_merge($old_cards, $new_cards);
    
    
    foreach ($all_cards as $index => $card) {
        if ($index == 3) echo "<hr><h3>--- NEUERE KARTEN ---</h3>";
        
        echo "<h4>Karte: " . htmlspecialchars($card['card_name']) . "</h4>";
        
        $card_data = json_decode($card['card_data'], true);
        
        echo "<strong>Verfügbare Bildfelder:</strong><br>";
        
        // Prüfe verschiedene mögliche Bildfelder
        if (isset($card_data['image_url'])) {
            echo "✅ image_url: " . htmlspecialchars($card_data['image_url']) . "<br>";
        } else {
            echo "❌ image_url: NICHT VORHANDEN<br>";
        }
        
        if (isset($card_data['image_uris'])) {
            echo "✅ image_uris: <pre>" . print_r($card_data['image_uris'], true) . "</pre>";
        } else {
            echo "❌ image_uris: NICHT VORHANDEN<br>";
        }
        
        if (isset($card_data['image'])) {
            echo "✅ image: " . htmlspecialchars($card_data['image']) . "<br>";
        } else {
            echo "❌ image: NICHT VORHANDEN<br>";
        }
        
        // Zeige alle verfügbaren Schlüssel
        echo "<strong>Alle verfügbaren JSON-Schlüssel:</strong><br>";
        echo "<small>" . implode(', ', array_keys($card_data)) . "</small>";
        
        echo "<hr>";
    }
    
} catch (PDOException $e) {
    echo "Fehler: " . $e->getMessage();
}
?>
