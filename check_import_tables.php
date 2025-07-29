<?php
require_once 'config/database.php';

try {
    // Prüfe ob import_history Tabelle bereits existiert
    $stmt = $pdo->query("SHOW TABLES LIKE 'import_history'");
    $exists = $stmt->rowCount() > 0;
    
    if ($exists) {
        echo "✅ import_history Tabelle existiert bereits\n";
        
        // Zeige Struktur
        $stmt = $pdo->query("DESCRIBE import_history");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "📋 Aktuelle Tabellenstruktur:\n";
        foreach ($columns as $column) {
            echo "  - {$column['Field']}: {$column['Type']}\n";
        }
    } else {
        echo "❌ import_history Tabelle existiert nicht - muss erstellt werden\n";
    }
    
    // Prüfe collections Tabelle für Referenz
    $stmt = $pdo->query("DESCRIBE collections");
    $collections_columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\n📋 collections Tabelle:\n";
    foreach (array_slice($collections_columns, 0, 8) as $column) {
        echo "  - {$column['Field']}: {$column['Type']}\n";
    }
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}
?>
