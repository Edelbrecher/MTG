-- Shopping Decks Table
CREATE TABLE IF NOT EXISTS shopping_decks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    deck_name VARCHAR(255) NOT NULL,
    description TEXT,
    total_cards INT DEFAULT 0,
    total_value DECIMAL(10,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_deck (user_id, deck_name)
);

-- Shopping Deck Cards Table
CREATE TABLE IF NOT EXISTS shopping_deck_cards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    deck_id INT NOT NULL,
    card_name VARCHAR(255) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    card_data JSON,
    price DECIMAL(8,2) DEFAULT 0.00,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (deck_id) REFERENCES shopping_decks(id) ON DELETE CASCADE,
    INDEX idx_deck_card (deck_id, card_name)
);
