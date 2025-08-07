<?php
/**
 * Complete Incomplete Commander Decks
 * Fills incomplete Commander decks to exactly 100 cards
 */

require_once 'config/database.php';

function completeCommanderDeck($pdo, $deck_id) {
    try {
        // Get deck info
        $stmt = $pdo->prepare("SELECT * FROM decks WHERE id = ? AND format_type = 'Commander'");
        $stmt->execute([$deck_id]);
        $deck = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$deck) {
            return "Deck not found or not Commander format";
        }
        
        // Count current cards
        $stmt = $pdo->prepare("SELECT SUM(quantity) as total FROM deck_cards WHERE deck_id = ?");
        $stmt->execute([$deck_id]);
        $current_total = $stmt->fetchColumn() ?: 0;
        
        echo "Current deck size: {$current_total} cards\n";
        
        if ($current_total >= 100) {
            return "Deck already complete";
        }
        
        $needed = 100 - $current_total;
        echo "Need to add: {$needed} cards\n";
        
        // Get commander color identity
        $commander = $deck['commander'];
        if (empty($commander)) {
            return "No commander specified";
        }
        
        // Get user's collection
        $stmt = $pdo->prepare("SELECT DISTINCT card_name, card_data FROM collections WHERE user_id = ? LIMIT 200");
        $stmt->execute([$deck['user_id']]);
        $user_cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($user_cards)) {
            echo "No user collection found - using fallback cards\n";
            $user_cards = [
                ['card_name' => 'Lightning Bolt'], ['card_name' => 'Counterspell'],
                ['card_name' => 'Serra Angel'], ['card_name' => 'Giant Growth'],
                ['card_name' => 'Dark Ritual'], ['card_name' => 'Shock'],
                ['card_name' => 'Healing Salve'], ['card_name' => 'Ancestral Recall']
            ];
        }
        
        // Get existing cards to avoid duplicates
        $stmt = $pdo->prepare("SELECT card_name FROM deck_cards WHERE deck_id = ? AND is_commander = 0");
        $stmt->execute([$deck_id]);
        $existing_cards = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Get commander colors (simplified)
        $commander_colors = ['W']; // Default to White for now
        if (strpos(strtolower($commander), 'blue') !== false) $commander_colors[] = 'U';
        if (strpos(strtolower($commander), 'black') !== false) $commander_colors[] = 'B';
        if (strpos(strtolower($commander), 'red') !== false) $commander_colors[] = 'R';
        if (strpos(strtolower($commander), 'green') !== false) $commander_colors[] = 'G';
        
        echo "Commander colors: " . implode('', $commander_colors) . "\n";
        
        $added = 0;
        $attempts = 0;
        $max_attempts = count($user_cards) * 20;
        
        while ($added < $needed && $attempts < $max_attempts) {
            $random_card = $user_cards[array_rand($user_cards)];
            $attempts++;
            
            // Skip if already in deck or is commander
            if (in_array($random_card['card_name'], $existing_cards) || $random_card['card_name'] === $commander) {
                continue;
            }
            
            // Skip basic lands (they should already be in the deck)
            $basic_lands = ['Plains', 'Island', 'Swamp', 'Mountain', 'Forest', 'Wastes'];
            if (in_array($random_card['card_name'], $basic_lands)) {
                continue;
            }
            
            // Add the card
            $stmt = $pdo->prepare("INSERT INTO deck_cards (deck_id, card_name, quantity, is_sideboard) VALUES (?, ?, 1, 0)");
            $stmt->execute([$deck_id, $random_card['card_name']]);
            
            $existing_cards[] = $random_card['card_name'];
            $added++;
            
            if ($added % 10 === 0) {
                echo "Added {$added} cards...\n";
            }
        }
        
        echo "Successfully added {$added} cards\n";
        
        // Final count
        $stmt = $pdo->prepare("SELECT SUM(quantity) as total FROM deck_cards WHERE deck_id = ?");
        $stmt->execute([$deck_id]);
        $final_total = $stmt->fetchColumn();
        
        echo "Final deck size: {$final_total} cards\n";
        
        return "Deck completion finished";
        
    } catch (Exception $e) {
        return "Error: " . $e->getMessage();
    }
}

// Complete the latest incomplete deck
try {
    $stmt = $pdo->query("
        SELECT d.id, d.name, SUM(dc.quantity) as card_count
        FROM decks d
        LEFT JOIN deck_cards dc ON d.id = dc.deck_id
        WHERE d.format_type = 'Commander'
        GROUP BY d.id, d.name
        HAVING card_count < 100
        ORDER BY d.id DESC
        LIMIT 1
    ");
    $incomplete_deck = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($incomplete_deck) {
        echo "Found incomplete deck: {$incomplete_deck['name']} ({$incomplete_deck['card_count']} cards)\n";
        $result = completeCommanderDeck($pdo, $incomplete_deck['id']);
        echo $result . "\n";
    } else {
        echo "No incomplete Commander decks found\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
