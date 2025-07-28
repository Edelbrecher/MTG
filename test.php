<?php
echo "<h1>PHP Test</h1>";
echo "<p>Datum: " . date('Y-m-d H:i:s') . "</p>";
echo "<p>PHP Version: " . PHP_VERSION . "</p>";

// MySQL Test
try {
    $pdo = new PDO("mysql:host=localhost", "root", "");
    echo "<p style='color: green;'>✓ MySQL Verbindung: OK</p>";
    
    // Test magic_deck_builder Datenbank
    $databases = $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('magic_deck_builder', $databases)) {
        echo "<p style='color: green;'>✓ Datenbank 'magic_deck_builder' gefunden</p>";
        
        $pdo->exec("USE magic_deck_builder");
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        echo "<p><strong>Vorhandene Tabellen:</strong></p><ul>";
        foreach ($tables as $table) {
            $count = $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
            echo "<li>$table ($count Einträge)</li>";
        }
        echo "</ul>";
        
    } else {
        echo "<p style='color: orange;'>⚠ Datenbank 'magic_deck_builder' nicht gefunden</p>";
        echo "<p>Verfügbare Datenbanken: " . implode(', ', $databases) . "</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ MySQL Fehler: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<h2>XAMPP Status prüfen:</h2>";
echo "<p>1. Ist Apache gestartet?</p>";
echo "<p>2. Ist MySQL gestartet?</p>";
echo "<p>3. URL korrekt: <strong>http://localhost/MTG/</strong></p>";

echo "<hr>";
echo "<p><a href='index.php'>→ Zurück zur Hauptseite</a></p>";
echo "<p><a href='setup.php'>→ Setup ausführen</a></p>";
?>
