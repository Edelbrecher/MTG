<?php
// Database update script für Profil-Features
require_once 'config/database.php';

echo "<h2>Datenbankupdate für Profil-Features</h2>";

try {
    // Check if nickname column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'nickname'");
    $nickname_exists = $stmt->fetch();
    
    if (!$nickname_exists) {
        echo "<p>Füge nickname-Spalte hinzu...</p>";
        $pdo->exec("ALTER TABLE users ADD COLUMN nickname VARCHAR(100) DEFAULT NULL AFTER username");
        echo "<p style='color: green;'>✓ nickname-Spalte erfolgreich hinzugefügt</p>";
    } else {
        echo "<p style='color: blue;'>✓ nickname-Spalte existiert bereits</p>";
    }
    
    // Check if created_at column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'created_at'");
    $created_at_exists = $stmt->fetch();
    
    if (!$created_at_exists) {
        echo "<p>Füge created_at-Spalte hinzu...</p>";
        $pdo->exec("ALTER TABLE users ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER is_admin");
        echo "<p style='color: green;'>✓ created_at-Spalte erfolgreich hinzugefügt</p>";
    } else {
        echo "<p style='color: blue;'>✓ created_at-Spalte existiert bereits</p>";
    }
    
    echo "<p style='color: green;'><strong>✅ Datenbankupdate erfolgreich abgeschlossen!</strong></p>";
    echo "<p><a href='profile.php'>→ Zum Profil</a></p>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>❌ Fehler beim Datenbankupdate: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
