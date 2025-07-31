<?php
// Database update für Spracheinstellungen
require_once 'config/database.php';

echo "<h2>Datenbankupdate für Spracheinstellungen</h2>";

try {
    // Check if language_preference column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'language_preference'");
    $lang_exists = $stmt->fetch();
    
    if (!$lang_exists) {
        echo "<p>Füge language_preference-Spalte hinzu...</p>";
        $pdo->exec("ALTER TABLE users ADD COLUMN language_preference VARCHAR(5) DEFAULT 'en' AFTER nickname");
        echo "<p style='color: green;'>✓ language_preference-Spalte erfolgreich hinzugefügt</p>";
    } else {
        echo "<p style='color: blue;'>✓ language_preference-Spalte existiert bereits</p>";
    }
    
    echo "<p style='color: green;'><strong>✅ Datenbankupdate für Spracheinstellungen erfolgreich!</strong></p>";
    echo "<p><a href='profile.php'>→ Zum Profil</a></p>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>❌ Fehler beim Datenbankupdate: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
