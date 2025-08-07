-- Add Singleton Constraint for Commander Decks
-- This script prevents duplicate non-land cards in Commander format decks

-- Drop trigger if it exists
DROP TRIGGER IF EXISTS prevent_commander_duplicates;

-- Create trigger to enforce singleton rule for Commander decks
DELIMITER $$

CREATE TRIGGER prevent_commander_duplicates
    BEFORE INSERT ON deck_cards
    FOR EACH ROW
BEGIN
    DECLARE deck_format VARCHAR(50);
    DECLARE existing_count INT DEFAULT 0;
    DECLARE is_basic_land BOOLEAN DEFAULT FALSE;
    
    -- Get the deck format
    SELECT format_type INTO deck_format 
    FROM decks 
    WHERE id = NEW.deck_id;
    
    -- Check if the card is a basic land (allowed multiple times)
    SET is_basic_land = (
        NEW.card_name IN ('Plains', 'Island', 'Swamp', 'Mountain', 'Forest', 'Wastes')
    );
    
    -- Only enforce singleton for Commander format, non-commander, non-basic-land cards
    IF deck_format = 'Commander' AND NEW.is_commander = 0 AND NOT is_basic_land THEN
        -- Check if card already exists in deck
        SELECT COUNT(*) INTO existing_count
        FROM deck_cards 
        WHERE deck_id = NEW.deck_id 
          AND card_name = NEW.card_name 
          AND is_commander = 0;
        
        -- If card already exists, raise error
        IF existing_count > 0 THEN
            SIGNAL SQLSTATE '45000' 
            SET MESSAGE_TEXT = 'Commander Singleton Rule: Card already exists in deck';
        END IF;
    END IF;
END$$

DELIMITER ;

-- Add index to improve performance of the trigger
CREATE INDEX IF NOT EXISTS idx_deck_cards_lookup 
ON deck_cards (deck_id, card_name, is_commander);

SELECT 'Commander Singleton Constraint successfully added!' as status;
