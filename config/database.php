<?php
// Basis-Datenbankverbindung
$host = 'localhost';
$dbname = 'magic_deck_builder';
$username = 'root';
$password = '';

try {
    // Erst ohne Datenbank verbinden (mit Socket-Pfad für XAMPP)
    $pdo = new PDO("mysql:host=$host;unix_socket=/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Datenbank erstellen falls nicht vorhanden
    $pdo->exec("CREATE DATABASE IF NOT EXISTS $dbname CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE $dbname");
    
    // Basis-Tabellen erstellen
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            is_admin BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS collections (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            card_name VARCHAR(255) NOT NULL,
            card_data JSON,
            quantity INT DEFAULT 1,
            condition_card VARCHAR(20) DEFAULT 'NM',
            added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_cards (user_id, card_name)
        )
    ");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS decks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            format_type VARCHAR(50) DEFAULT 'Standard',
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS deck_cards (
            id INT AUTO_INCREMENT PRIMARY KEY,
            deck_id INT NOT NULL,
            card_name VARCHAR(255) NOT NULL,
            quantity INT NOT NULL,
            is_sideboard BOOLEAN DEFAULT FALSE
        )
    ");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            setting_key VARCHAR(50) NOT NULL,
            setting_value TEXT,
            UNIQUE KEY unique_user_setting (user_id, setting_key)
        )
    ");
    
    // Admin-User erstellen falls nicht vorhanden
    $check = $pdo->query("SELECT COUNT(*) FROM users WHERE email = 'admin@mtg.local'")->fetchColumn();
    if ($check == 0) {
        $admin_password = password_hash('admin123', PASSWORD_DEFAULT);
        $pdo->exec("INSERT INTO users (username, email, password_hash, is_admin) VALUES ('admin', 'admin@mtg.local', '$admin_password', 1)");
    }
    
} catch(PDOException $e) {
    die("<h2>Datenbankfehler:</h2><p>" . $e->getMessage() . "</p><p><a href='test.php'>→ System-Test ausführen</a></p>");
}
?>
