<?php
require_once 'config/database.php';

try {
    echo "🔨 Erstelle import_history Tabelle...\n";
    
    $sql = "CREATE TABLE IF NOT EXISTS import_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        import_session_id VARCHAR(32) NOT NULL,
        import_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        total_cards INT DEFAULT 0,
        successful_cards INT DEFAULT 0,
        failed_cards INT DEFAULT 0,
        import_summary JSON,
        status ENUM('completed', 'failed', 'undone') DEFAULT 'completed',
        undone_at TIMESTAMP NULL,
        INDEX idx_user_session (user_id, import_session_id),
        INDEX idx_user_date (user_id, import_date DESC),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    
    $pdo->exec($sql);
    echo "✅ import_history Tabelle erstellt\n";
    
    echo "\n🔨 Erstelle import_cards Tabelle für detaillierte Karten-Tracking...\n";
    
    $sql2 = "CREATE TABLE IF NOT EXISTS import_cards (
        id INT AUTO_INCREMENT PRIMARY KEY,
        import_session_id VARCHAR(32) NOT NULL,
        user_id INT NOT NULL,
        card_name VARCHAR(255) NOT NULL,
        quantity INT DEFAULT 1,
        collection_id INT,
        import_order INT,
        status ENUM('success', 'failed') DEFAULT 'success',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_session (import_session_id),
        INDEX idx_user (user_id),
        INDEX idx_collection (collection_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE SET NULL
    )";
    
    $pdo->exec($sql2);
    echo "✅ import_cards Tabelle erstellt\n";
    
    echo "\n✅ Datenbank-Setup für Undo-Funktionalität abgeschlossen!\n";
    
} catch (Exception $e) {
    echo "❌ Fehler beim Erstellen der Tabellen: " . $e->getMessage() . "\n";
}
?>
