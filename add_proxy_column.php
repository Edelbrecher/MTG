<?php
require_once 'config/database.php';

try {
    // Prüfen welche Spalten existieren
    $stmt = $pdo->query("SHOW COLUMNS FROM collections");
    $columns = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $columns[] = $row['Field'];
    }
    
    echo "Aktuelle Spalten: " . implode(', ', $columns) . "\n\n";
    
    // Prüfen ob die Foil-Spalte existiert
    $foilExists = in_array('foil', $columns);
    $proxyExists = in_array('proxy', $columns);
    
    if (!$foilExists) {
        echo "Füge Foil-Spalte hinzu...\n";
        $pdo->exec("ALTER TABLE collections ADD COLUMN foil BOOLEAN DEFAULT FALSE");
        echo "✅ Foil-Spalte erfolgreich hinzugefügt\n";
    } else {
        echo "⚠️ Foil-Spalte existiert bereits\n";
    }
    
    if (!$proxyExists) {
        echo "Füge Proxy-Spalte hinzu...\n";
        $pdo->exec("ALTER TABLE collections ADD COLUMN proxy BOOLEAN DEFAULT FALSE");
        echo "✅ Proxy-Spalte erfolgreich hinzugefügt\n";
    } else {
        echo "⚠️ Proxy-Spalte existiert bereits\n";
    }
    
    // Nur Indizes erstellen wenn sie noch nicht existieren
    if (!$foilExists || !$proxyExists) {
        try {
            // Index für Foil-Spalte
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_collections_foil ON collections(foil)");
            echo "✅ Index für Foil-Spalte erstellt\n";
            
            // Index für Proxy-Spalte
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_collections_proxy ON collections(proxy)");
            echo "✅ Index für Proxy-Spalte erstellt\n";
            
            // Kombinierter Index für user_id, foil, proxy
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_collections_user_foil_proxy ON collections(user_id, foil, proxy)");
            echo "✅ Kombinierter Index erstellt\n";
        } catch (Exception $indexError) {
            echo "⚠️ Index-Erstellung übersprungen (möglicherweise bereits vorhanden): " . $indexError->getMessage() . "\n";
        }
    }
    
    // Aktuelle Tabellenstruktur anzeigen
    echo "\nAktuelle collections-Tabelle Struktur:\n";
    $stmt = $pdo->query("DESCRIBE collections");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "- {$row['Field']}: {$row['Type']} {$row['Null']} {$row['Default']}\n";
    }
    
    echo "\n✅ Migration erfolgreich abgeschlossen!\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
    exit(1);
}
?>
