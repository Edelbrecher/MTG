<?php
require_once dirname(__DIR__) . '/config/database.php';

try {
    // Create format_rules table
    $sql = "CREATE TABLE IF NOT EXISTS format_rules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        format_name VARCHAR(100) NOT NULL UNIQUE,
        rules_json TEXT NOT NULL,
        is_custom BOOLEAN DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_format_name (format_name),
        INDEX idx_is_custom (is_custom)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $pdo->exec($sql);
    
    echo "✅ Tabelle 'format_rules' wurde erfolgreich erstellt!\n";
    
    // Insert default rules for Commander if not exists
    $commander_rules = [
        'deck_size' => 100,
        'min_deck_size' => 100,
        'max_deck_size' => 100,
        'singleton' => true,
        'commander_required' => true,
        'starting_life' => 40,
        'max_copies' => 1,
        'description' => 'Multiplayer-Format mit 100-Karten Singleton-Decks.',
        'rules' => [
            'Genau 100 Karten (inklusive Commander)',
            'Genau 1 Commander (Legendary Creature oder Planeswalker)',
            'Alle Karten sind Singleton (maximal 1 Kopie)',
            'Farbidentität des Commanders bestimmt erlaubte Farben',
            '40 Startlebenspunkte',
            'Commander-Schaden: 21 Schaden = eliminiert'
        ]
    ];
    
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO format_rules (format_name, rules_json, is_custom) 
        VALUES ('Commander', ?, 0)
    ");
    $stmt->execute([json_encode($commander_rules)]);
    
    echo "✅ Standard Commander-Regeln wurden hinzugefügt!\n";
    echo "🎉 Setup abgeschlossen!\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}
?>
