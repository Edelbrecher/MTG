CREATE DATABASE IF NOT EXISTS magic_deck_builder CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE magic_deck_builder;

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_admin BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Collections table
CREATE TABLE IF NOT EXISTS collections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    card_name VARCHAR(255) NOT NULL,
    card_data JSON,
    quantity INT DEFAULT 1,
    condition_card VARCHAR(20) DEFAULT 'NM',
    foil BOOLEAN DEFAULT FALSE,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_cards (user_id, card_name),
    INDEX idx_card_name (card_name)
);

-- Decks table
CREATE TABLE IF NOT EXISTS decks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    format_type VARCHAR(50) DEFAULT 'Standard',
    description TEXT,
    is_public BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_decks (user_id),
    INDEX idx_format (format_type)
);

-- Deck cards table
CREATE TABLE IF NOT EXISTS deck_cards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    deck_id INT NOT NULL,
    card_name VARCHAR(255) NOT NULL,
    quantity INT NOT NULL,
    is_sideboard BOOLEAN DEFAULT FALSE,
    card_data JSON,
    FOREIGN KEY (deck_id) REFERENCES decks(id) ON DELETE CASCADE,
    INDEX idx_deck_cards (deck_id),
    UNIQUE KEY unique_deck_card (deck_id, card_name, is_sideboard)
);

-- User settings table
CREATE TABLE IF NOT EXISTS user_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    setting_key VARCHAR(50) NOT NULL,
    setting_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_setting (user_id, setting_key)
);

-- Card cache table (for API responses)
CREATE TABLE IF NOT EXISTS card_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    card_name VARCHAR(255) UNIQUE NOT NULL,
    card_data JSON NOT NULL,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_card_name (card_name),
    INDEX idx_last_updated (last_updated)
);

-- Tournaments table (for future expansion)
CREATE TABLE IF NOT EXISTS tournaments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    format_type VARCHAR(50) NOT NULL,
    start_date DATE,
    end_date DATE,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tournament participants table
CREATE TABLE IF NOT EXISTS tournament_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tournament_id INT NOT NULL,
    user_id INT NOT NULL,
    deck_id INT,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (deck_id) REFERENCES decks(id) ON DELETE SET NULL,
    UNIQUE KEY unique_participation (tournament_id, user_id)
);

-- Create default admin user (password: admin123)
INSERT IGNORE INTO users (username, email, password_hash, is_admin) 
VALUES ('admin', 'admin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', TRUE);

-- Create some sample data for testing
INSERT IGNORE INTO card_cache (card_name, card_data) VALUES 
('Lightning Bolt', '{"name":"Lightning Bolt","mana_cost":"{R}","cmc":1,"type_line":"Instant","oracle_text":"Lightning Bolt deals 3 damage to any target.","colors":["R"],"color_identity":["R"],"rarity":"common","set_name":"Masters 25","set":"a25","image_url":"https://cards.scryfall.io/normal/front/e/3/e3285e6b-3e79-4d7c-bf96-d920f973b122.jpg"}'),
('Black Lotus', '{"name":"Black Lotus","mana_cost":"","cmc":0,"type_line":"Artifact","oracle_text":"{T}, Sacrifice Black Lotus: Add three mana of any one color.","colors":[],"color_identity":[],"rarity":"mythic","set_name":"Limited Edition Alpha","set":"lea","image_url":"https://cards.scryfall.io/normal/front/b/d/bd8fa327-dd41-4737-8f19-2cf5eb1f7cdd.jpg"}'),
('Counterspell', '{"name":"Counterspell","mana_cost":"{U}{U}","cmc":2,"type_line":"Instant","oracle_text":"Counter target spell.","colors":["U"],"color_identity":["U"],"rarity":"common","set_name":"Masters 25","set":"a25","image_url":"https://cards.scryfall.io/normal/front/c/c/cca8eb95-d071-46a4-885c-3da25b401806.jpg"}');

-- Indexes for performance
CREATE INDEX IF NOT EXISTS idx_collections_user_quantity ON collections(user_id, quantity);
CREATE INDEX IF NOT EXISTS idx_deck_cards_deck_sideboard ON deck_cards(deck_id, is_sideboard);
CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);
CREATE INDEX IF NOT EXISTS idx_users_admin ON users(is_admin);

-- Views for common queries
CREATE OR REPLACE VIEW user_collection_summary AS
SELECT 
    u.id as user_id,
    u.username,
    COUNT(DISTINCT c.id) as unique_cards,
    SUM(c.quantity) as total_cards,
    COUNT(DISTINCT d.id) as deck_count
FROM users u
LEFT JOIN collections c ON u.id = c.user_id
LEFT JOIN decks d ON u.id = d.user_id
GROUP BY u.id, u.username;

CREATE OR REPLACE VIEW deck_summary AS
SELECT 
    d.id as deck_id,
    d.name as deck_name,
    d.format_type,
    u.username as owner,
    COUNT(DISTINCT dc.card_name) as unique_cards,
    SUM(CASE WHEN dc.is_sideboard = 0 THEN dc.quantity ELSE 0 END) as mainboard_count,
    SUM(CASE WHEN dc.is_sideboard = 1 THEN dc.quantity ELSE 0 END) as sideboard_count
FROM decks d
JOIN users u ON d.user_id = u.id
LEFT JOIN deck_cards dc ON d.id = dc.deck_id
GROUP BY d.id, d.name, d.format_type, u.username;
