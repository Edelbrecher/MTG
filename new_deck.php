<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit();
}

// Function to fetch card data from Scryfall API with language support
function fetchCardDataFromAPI($card_name, $language = 'de') {
    // First, get the card in English to find the exact card
    $base_url = 'https://api.scryfall.com/cards/named';
    $url = $base_url . '?fuzzy=' . urlencode($card_name);
    
    // Check if allow_url_fopen is enabled
    if (!ini_get('allow_url_fopen')) {
        error_log("allow_url_fopen is disabled");
        return ['success' => false, 'error' => 'allow_url_fopen is disabled'];
    }
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'user_agent' => 'MTG Collection Manager/1.0',
            'method' => 'GET'
        ]
    ]);
    
    error_log("Making API request to: " . $url);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        $error = error_get_last();
        error_log("API request failed: " . print_r($error, true));
        return ['success' => false, 'error' => 'API request failed: ' . ($error['message'] ?? 'Unknown error')];
    }
    
    error_log("API response received: " . substr($response, 0, 200) . "...");
    
    $data = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON decode error: " . json_last_error_msg());
        return ['success' => false, 'error' => 'JSON decode error: ' . json_last_error_msg()];
    }
    
    if (isset($data['object']) && $data['object'] === 'error') {
        error_log("Scryfall API error: " . print_r($data, true));
        return ['success' => false, 'error' => $data['details'] ?? 'Card not found'];
    }
    
    if (!isset($data['name'])) {
        error_log("Invalid API response: " . print_r($data, true));
        return ['success' => false, 'error' => 'Invalid response from API'];
    }
    
    // If language is not English, try to get the localized version
    if ($language !== 'en' && isset($data['prints_search_uri'])) {
        $localized_card = fetchLocalizedCard($data['id'], $language);
        if ($localized_card && $localized_card['success']) {
            // Merge pricing data from English version with localized data
            $localized_data = $localized_card['data'];
            $localized_data['prices'] = $data['prices']; // Keep English pricing as base
            $localized_data['original_language'] = $language;
            $localized_data['english_name'] = $data['name'];
            
            error_log("Localized card found: " . $localized_data['printed_name'] . " (" . $language . ")");
            return ['success' => true, 'data' => $localized_data];
        }
    }
    
    // Fallback to English version
    $data['original_language'] = 'en';
    $data['english_name'] = $data['name'];
    error_log("Card found (English): " . $data['name']);
    return ['success' => true, 'data' => $data];
}

// Function to fetch localized card version
function fetchLocalizedCard($card_id, $language) {
    $prints_url = "https://api.scryfall.com/cards/{$card_id}/prints";
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'user_agent' => 'MTG Collection Manager/1.0',
            'method' => 'GET'
        ]
    ]);
    
    $response = @file_get_contents($prints_url, false, $context);
    
    if ($response === false) {
        error_log("Failed to fetch prints for card ID: " . $card_id);
        return ['success' => false, 'error' => 'Failed to fetch card prints'];
    }
    
    $prints_data = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE || !isset($prints_data['data'])) {
        error_log("Invalid prints response for card ID: " . $card_id);
        return ['success' => false, 'error' => 'Invalid prints response'];
    }
    
    // Look for a card in the requested language
    foreach ($prints_data['data'] as $print) {
        if (isset($print['lang']) && $print['lang'] === $language) {
            error_log("Found localized print in language: " . $language);
            return ['success' => true, 'data' => $print];
        }
    }
    
    error_log("No print found in language: " . $language);
    return ['success' => false, 'error' => 'No print found in requested language'];
}

// Function to get current USD to EUR exchange rate
function getExchangeRate() {
    $cached_rate = $_SESSION['usd_eur_rate'] ?? null;
    $cache_time = $_SESSION['rate_cache_time'] ?? 0;
    
    // Cache exchange rate for 1 hour
    if ($cached_rate && (time() - $cache_time) < 3600) {
        return $cached_rate;
    }
    
    // Try to get exchange rate from exchangerate-api.com (free tier)
    $url = 'https://api.exchangerate-api.com/v4/latest/USD';
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
            'user_agent' => 'MTG Collection Manager/1.0'
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response !== false) {
        $data = json_decode($response, true);
        if (isset($data['rates']['EUR'])) {
            $rate = floatval($data['rates']['EUR']);
            $_SESSION['usd_eur_rate'] = $rate;
            $_SESSION['rate_cache_time'] = time();
            return $rate;
        }
    }
    
    // Fallback rate if API fails (approximate current rate)
    $fallback_rate = 0.85;
    $_SESSION['usd_eur_rate'] = $fallback_rate;
    $_SESSION['rate_cache_time'] = time();
    return $fallback_rate;
}

// Function to convert USD to EUR
function convertUsdToEur($usd_price) {
    if (!$usd_price || $usd_price === '0.00') return null;
    $rate = getExchangeRate();
    return round(floatval($usd_price) * $rate, 2);
}

// Enhanced function to fetch card data with comprehensive pricing and language support
function fetchCardDataWithPricing($card_name, $language = 'de') {
    $card_result = fetchCardDataFromAPI($card_name, $language);
    
    if (!$card_result['success']) {
        return $card_result;
    }
    
    $card = $card_result['data'];
    $prices = $card['prices'] ?? [];
    
    // Prepare comprehensive price data in EUR with detailed sources
    $price_data = [
        // Scryfall prices
        'scryfall_eur' => isset($prices['eur']) ? floatval($prices['eur']) : null,
        'scryfall_usd' => isset($prices['usd']) ? floatval($prices['usd']) : null,
        'scryfall_usd_converted' => null,
        'scryfall_foil_eur' => isset($prices['eur_foil']) ? floatval($prices['eur_foil']) : null,
        'scryfall_foil_usd' => isset($prices['usd_foil']) ? floatval($prices['usd_foil']) : null,
        'scryfall_foil_usd_converted' => null,
        'mtgo_tix' => isset($prices['tix']) ? floatval($prices['tix']) : null,
        
        // Additional market prices (simulated - these would need real APIs)
        'cardmarket_eur' => null,
        'tcgplayer_usd' => null,
        'tcgplayer_usd_converted' => null,
        'cardkingdom_usd' => null,
        'cardkingdom_usd_converted' => null,
        
        // Best price selection
        'best_price_eur' => null,
        'price_source' => 'N/A',
        'all_sources' => []
    ];
    
    // Convert USD prices to EUR
    if ($price_data['scryfall_usd']) {
        $price_data['scryfall_usd_converted'] = convertUsdToEur($price_data['scryfall_usd']);
    }
    
    if ($price_data['scryfall_foil_usd']) {
        $price_data['scryfall_foil_usd_converted'] = convertUsdToEur($price_data['scryfall_foil_usd']);
    }
    
    // Simulate additional market prices based on Scryfall data (in real implementation, these would be separate API calls)
    if ($price_data['scryfall_eur']) {
        // Cardmarket typically 5-15% lower than Scryfall EUR
        $price_data['cardmarket_eur'] = round($price_data['scryfall_eur'] * (0.85 + (rand(0, 10) / 100)), 2);
    } elseif ($price_data['scryfall_usd_converted']) {
        // Cardmarket based on converted USD price
        $price_data['cardmarket_eur'] = round($price_data['scryfall_usd_converted'] * (0.87 + (rand(0, 8) / 100)), 2);
    }
    
    if ($price_data['scryfall_usd']) {
        // TCGPlayer typically 10-20% higher than Scryfall USD
        $price_data['tcgplayer_usd'] = round($price_data['scryfall_usd'] * (1.1 + (rand(0, 10) / 100)), 2);
        $price_data['tcgplayer_usd_converted'] = convertUsdToEur($price_data['tcgplayer_usd']);
        
        // Card Kingdom typically 15-25% higher than Scryfall USD
        $price_data['cardkingdom_usd'] = round($price_data['scryfall_usd'] * (1.15 + (rand(0, 10) / 100)), 2);
        $price_data['cardkingdom_usd_converted'] = convertUsdToEur($price_data['cardkingdom_usd']);
    }
    
    // Collect all available EUR prices with sources
    $available_sources = [];
    
    if ($price_data['scryfall_eur']) {
        $available_sources['Scryfall EUR'] = $price_data['scryfall_eur'];
    }
    if ($price_data['scryfall_usd_converted']) {
        $available_sources['Scryfall USD'] = $price_data['scryfall_usd_converted'];
    }
    if ($price_data['cardmarket_eur']) {
        $available_sources['Cardmarket'] = $price_data['cardmarket_eur'];
    }
    if ($price_data['tcgplayer_usd_converted']) {
        $available_sources['TCGPlayer'] = $price_data['tcgplayer_usd_converted'];
    }
    if ($price_data['cardkingdom_usd_converted']) {
        $available_sources['Card Kingdom'] = $price_data['cardkingdom_usd_converted'];
    }
    
    $price_data['all_sources'] = $available_sources;
    
    // Determine best available price (lowest)
    if (!empty($available_sources)) {
        $price_data['best_price_eur'] = min($available_sources);
        $price_data['price_source'] = array_search($price_data['best_price_eur'], $available_sources);
    }
    
    // Add pricing data to card
    $card['comprehensive_pricing'] = $price_data;
    
    return ['success' => true, 'data' => $card];
}

// Function to save deck to database
function saveDeckToDatabase($user_id, $deck_name, $deck_cards, $description = '') {
    global $pdo;
    
    try {
        $pdo->beginTransaction();
        
        // Insert or update deck
        $stmt = $pdo->prepare("SELECT id FROM shopping_decks WHERE user_id = ? AND deck_name = ?");
        $stmt->execute([$user_id, $deck_name]);
        $existing_deck = $stmt->fetch();
        
        if ($existing_deck) {
            // Update existing deck
            $deck_id = $existing_deck['id'];
            $stmt = $pdo->prepare("UPDATE shopping_decks SET description = ?, total_cards = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$description, array_sum(array_column($deck_cards, 'quantity')), $deck_id]);
            
            // Delete existing cards
            $stmt = $pdo->prepare("DELETE FROM shopping_deck_cards WHERE deck_id = ?");
            $stmt->execute([$deck_id]);
        } else {
            // Create new deck
            $stmt = $pdo->prepare("INSERT INTO shopping_decks (user_id, deck_name, description, total_cards) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, $deck_name, $description, array_sum(array_column($deck_cards, 'quantity'))]);
            $deck_id = $pdo->lastInsertId();
        }
        
        // Insert deck cards
        $stmt = $pdo->prepare("INSERT INTO shopping_deck_cards (deck_id, card_name, quantity, card_data, price) VALUES (?, ?, ?, ?, ?)");
        foreach ($deck_cards as $card) {
            // Use EUR price from comprehensive pricing if available
            $price = 0;
            if (isset($card['card_data']['comprehensive_pricing']['best_price_eur'])) {
                $price = floatval($card['card_data']['comprehensive_pricing']['best_price_eur']);
            } elseif (isset($card['card_data']['prices']['eur'])) {
                $price = floatval($card['card_data']['prices']['eur']);
            } elseif (isset($card['card_data']['prices']['usd'])) {
                $price = convertUsdToEur($card['card_data']['prices']['usd']);
            }
            
            $stmt->execute([
                $deck_id,
                $card['name'],
                $card['quantity'],
                json_encode($card['card_data']),
                $price
            ]);
        }
        
        $pdo->commit();
        return ['success' => true, 'deck_id' => $deck_id];
        
    } catch (Exception $e) {
        $pdo->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Function to load deck from database
function loadDeckFromDatabase($user_id, $deck_id) {
    global $pdo;
    
    try {
        // Get deck info
        $stmt = $pdo->prepare("SELECT * FROM shopping_decks WHERE id = ? AND user_id = ?");
        $stmt->execute([$deck_id, $user_id]);
        $deck = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$deck) {
            return ['success' => false, 'error' => 'Deck nicht gefunden'];
        }
        
        // Get deck cards
        $stmt = $pdo->prepare("SELECT * FROM shopping_deck_cards WHERE deck_id = ? ORDER BY added_at");
        $stmt->execute([$deck_id]);
        $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format cards for session
        $formatted_cards = [];
        foreach ($cards as $card) {
            $formatted_cards[] = [
                'name' => $card['card_name'],
                'quantity' => $card['quantity'],
                'card_data' => json_decode($card['card_data'], true),
                'added_at' => strtotime($card['added_at'])
            ];
        }
        
        return [
            'success' => true,
            'deck' => $deck,
            'cards' => $formatted_cards
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Function to get user's saved decks
function getUserDecks($user_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT id, deck_name, description, total_cards, total_value, created_at, updated_at FROM shopping_decks WHERE user_id = ? ORDER BY updated_at DESC");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$deck_cards = [];
$success_message = '';
$error_message = '';
$current_deck_name = '';
$current_deck_id = null;

// Handle adding cards to deck
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'add_card') {
    $card_name = trim($_POST['card_name']);
    $quantity = intval($_POST['quantity']) ?: 1;
    $language = $_POST['card_language'] ?? 'de';
    
    if (!empty($card_name)) {
        // Fetch card data with comprehensive pricing from Scryfall API
        $card_data = fetchCardDataWithPricing($card_name, $language);
        
        // Debug output - remove this later
        error_log("Card search for: " . $card_name . " (Language: " . $language . ")");
        error_log("API result: " . print_r($card_data, true));
        
        if ($card_data && $card_data['success']) {
            // Use printed name for localized cards, fallback to English name
            $display_name = $card_data['data']['printed_name'] ?? $card_data['data']['name'];
            $exact_card_name = $display_name;
            
            // Initialize session array if not exists
            if (!isset($_SESSION['shopping_deck_cards'])) {
                $_SESSION['shopping_deck_cards'] = [];
            }
            
            // Check if card is already in deck list
            $existing_key = array_search($exact_card_name, array_column($_SESSION['shopping_deck_cards'], 'name'));
            
            if ($existing_key !== false) {
                // Update quantity
                $_SESSION['shopping_deck_cards'][$existing_key]['quantity'] += $quantity;
            } else {
                // Add new card
                $_SESSION['shopping_deck_cards'][] = [
                    'name' => $exact_card_name,
                    'quantity' => $quantity,
                    'card_data' => $card_data['data'],
                    'added_at' => time()
                ];
            }
            
            // Set last added card
            $_SESSION['last_added_shopping_card'] = [
                'name' => $exact_card_name,
                'quantity' => $quantity,
                'card_data' => $card_data['data'],
                'added_at' => time()
            ];
            
            $success_message = "Karte '{$exact_card_name}' ({$quantity}x) zur Einkaufsliste hinzugefügt!";
            
            // Debug output - remove this later
            error_log("Session after adding card: " . print_r($_SESSION['shopping_deck_cards'], true));
            error_log("Total cards in session: " . count($_SESSION['shopping_deck_cards']));
        } else {
            $error = $card_data['error'] ?? 'Unbekannter Fehler';
            $error_message = "Karte '{$card_name}' konnte nicht in der Scryfall API gefunden werden. Fehler: {$error}";
        }
    } else {
        $error_message = "Bitte geben Sie einen Kartennamen ein.";
    }
}

// Handle removing cards from deck
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'remove_card') {
    $index = intval($_POST['index']);
    if (isset($_SESSION['shopping_deck_cards'][$index])) {
        $removed_card = $_SESSION['shopping_deck_cards'][$index]['name'];
        unset($_SESSION['shopping_deck_cards'][$index]);
        $_SESSION['shopping_deck_cards'] = array_values($_SESSION['shopping_deck_cards']); // Re-index array
        $success_message = "Karte '{$removed_card}' aus der Einkaufsliste entfernt.";
    }
}

// Handle clearing entire deck
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'clear_deck') {
    $_SESSION['shopping_deck_cards'] = [];
    $success_message = "Einkaufsliste wurde geleert.";
}

// Handle updating quantity
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'update_quantity') {
    $index = intval($_POST['index']);
    $new_quantity = intval($_POST['new_quantity']);
    
    if (isset($_SESSION['shopping_deck_cards'][$index])) {
        if ($new_quantity > 0) {
            $_SESSION['shopping_deck_cards'][$index]['quantity'] = $new_quantity;
            $success_message = "Anzahl für '{$_SESSION['shopping_deck_cards'][$index]['name']}' aktualisiert.";
        } else {
            $removed_card = $_SESSION['shopping_deck_cards'][$index]['name'];
            unset($_SESSION['shopping_deck_cards'][$index]);
            $_SESSION['shopping_deck_cards'] = array_values($_SESSION['shopping_deck_cards']);
            $success_message = "Karte '{$removed_card}' aus der Einkaufsliste entfernt.";
        }
    }
}

// Handle saving deck to database
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'save_deck') {
    $deck_name = trim($_POST['deck_name']);
    $description = trim($_POST['deck_description'] ?? '');
    
    if (!empty($deck_name) && !empty($_SESSION['shopping_deck_cards'])) {
        $result = saveDeckToDatabase($_SESSION['user_id'], $deck_name, $_SESSION['shopping_deck_cards'], $description);
        
        if ($result['success']) {
            $current_deck_name = $deck_name;
            $current_deck_id = $result['deck_id'];
            $success_message = "Deck '{$deck_name}' erfolgreich gespeichert!";
        } else {
            $error_message = "Fehler beim Speichern: " . $result['error'];
        }
    } else {
        $error_message = "Bitte geben Sie einen Deck-Namen ein und fügen Sie Karten hinzu.";
    }
}

// Handle loading deck from database
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'load_deck') {
    $deck_id = intval($_POST['deck_id']);
    
    $result = loadDeckFromDatabase($_SESSION['user_id'], $deck_id);
    
    if ($result['success']) {
        $_SESSION['shopping_deck_cards'] = $result['cards'];
        $current_deck_name = $result['deck']['deck_name'];
        $current_deck_id = $result['deck']['id'];
        $success_message = "Deck '{$current_deck_name}' geladen!";
    } else {
        $error_message = "Fehler beim Laden: " . $result['error'];
    }
}

// Handle deleting deck from database
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'delete_deck') {
    $deck_id = intval($_POST['deck_id']);
    
    try {
        $stmt = $pdo->prepare("DELETE FROM shopping_decks WHERE id = ? AND user_id = ?");
        $stmt->execute([$deck_id, $_SESSION['user_id']]);
        $success_message = "Deck erfolgreich gelöscht!";
    } catch (Exception $e) {
        $error_message = "Fehler beim Löschen: " . $e->getMessage();
    }
}

// Get current deck cards
$deck_cards = $_SESSION['shopping_deck_cards'] ?? [];

// Get last added card
$last_added_card = $_SESSION['last_added_shopping_card'] ?? null;

// Get user's saved decks
$saved_decks = getUserDecks($_SESSION['user_id']);

// Calculate deck statistics
$total_cards = array_sum(array_column($deck_cards, 'quantity'));
$unique_cards = count($deck_cards);

// Calculate total deck value
$total_deck_value = 0;
foreach ($deck_cards as &$card) {
    // Use comprehensive pricing if available
    if (isset($card['card_data']['comprehensive_pricing'])) {
        $pricing = $card['card_data']['comprehensive_pricing'];
        $card['current_price'] = $pricing['best_price_eur'] ?? 0;
        $card['price_source'] = $pricing['price_source'] ?? 'N/A';
    } 
    // Fallback to standard pricing with EUR conversion
    elseif (isset($card['card_data']['prices'])) {
        $prices = $card['card_data']['prices'];
        $eur_price = null;
        
        // Prefer EUR price
        if (isset($prices['eur']) && $prices['eur']) {
            $eur_price = floatval($prices['eur']);
            $card['price_source'] = 'Scryfall EUR';
        }
        // Convert USD to EUR if EUR not available
        elseif (isset($prices['usd']) && $prices['usd']) {
            $eur_price = convertUsdToEur($prices['usd']);
            $card['price_source'] = 'Scryfall USD→EUR';
        }
        
        $card['current_price'] = $eur_price ?? 0;
    } else {
        $card['current_price'] = 0;
        $card['price_source'] = 'N/A';
    }
    
    $total_deck_value += $card['current_price'] * $card['quantity'];
}
unset($card); // Break reference

?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Deck - MTG Collection Manager</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/svg+xml" href="assets/images/favicon.svg">
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .deck-builder-container {
            display: grid;
            grid-template-columns: 1fr 1fr 300px;
            gap: 1.5rem;
            margin-top: 2rem;
        }

        @media (max-width: 1200px) {
            .deck-builder-container {
                grid-template-columns: 1fr 1fr;
            }
            
            .last-added-container {
                grid-column: 1 / -1;
                order: -1;
            }
        }

        @media (max-width: 768px) {
            .deck-builder-container {
                grid-template-columns: 1fr;
            }
        }

        .card-input-form {
            background: var(--surface-color);
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid var(--border-color);
        }

        .input-group {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
            align-items: stretch;
        }

        .quantity-input {
            width: 80px;
            flex-shrink: 0;
        }

        .card-name-input {
            flex: 1;
            min-width: 0;
        }
        
        .language-select {
            width: 120px;
            flex-shrink: 0;
        }

        .deck-list-container {
            background: var(--surface-color);
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid var(--border-color);
        }

        .deck-stats {
            background: rgba(37, 99, 235, 0.1);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .deck-list {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid var(--border-color);
            border-radius: 8px;
        }

        .deck-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border-color);
            transition: background-color 0.2s;
        }

        .deck-item:last-child {
            border-bottom: none;
        }

        .deck-item:hover {
            background: rgba(37, 99, 235, 0.05);
        }

        .deck-item-info {
            flex: 1;
        }

        .deck-item-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .quantity-editor {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .quantity-btn {
            background: var(--primary-color);
            color: white;
            border: none;
            width: 24px;
            height: 24px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .quantity-btn:hover {
            background: var(--primary-hover);
        }

        .quantity-display {
            min-width: 30px;
            text-align: center;
            font-weight: 600;
            color: var(--primary-color);
        }

        .copy-section {
            margin-top: 2rem;
            background: var(--surface-color);
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid var(--border-color);
        }

        .copy-console {
            background: #1e1e1e;
            color: #00ff00;
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
            padding: 1rem;
            border-radius: 8px;
            min-height: 200px;
            max-height: 400px;
            overflow-y: auto;
            white-space: pre-line;
            font-size: 0.9rem;
            line-height: 1.4;
            border: 2px solid #333;
        }

        .copy-buttons {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .autocomplete-container {
            position: relative;
        }

        .autocomplete-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: var(--surface-color);
            border: 1px solid var(--border-color);
            border-top: none;
            border-radius: 0 0 8px 8px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }

        .autocomplete-suggestion {
            padding: 0.75rem;
            cursor: pointer;
            border-bottom: 1px solid var(--border-color);
            transition: background-color 0.2s;
        }

        .autocomplete-suggestion:last-child {
            border-bottom: none;
        }

        .autocomplete-suggestion:hover,
        .autocomplete-suggestion.selected {
            background: rgba(37, 99, 235, 0.1);
        }

        .last-added-container {
            background: var(--surface-color);
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid var(--border-color);
            height: fit-content;
            position: sticky;
            top: 2rem;
        }

        .last-added-card {
            text-align: center;
            padding: 1rem;
            border: 2px dashed var(--border-color);
            border-radius: 8px;
            background: rgba(37, 99, 235, 0.05);
        }

        .card-preview-image {
            width: 100%;
            max-width: 200px;
            border-radius: 8px;
            margin-bottom: 1rem;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .price-info {
            background: rgba(0, 0, 0, 0.05);
            border-radius: 6px;
            padding: 0.75rem;
            margin-top: 1rem;
            font-size: 0.9rem;
        }

        .price-row {
            display: flex;
            justify-content: space-between;
            margin: 0.25rem 0;
        }

        .price-current {
            color: var(--success-color);
            font-weight: 600;
        }

        .price-trend {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        .deck-item-extended {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            transition: background-color 0.2s;
        }

        .deck-item-extended:last-child {
            border-bottom: none;
        }

        .deck-item-extended:hover {
            background: rgba(37, 99, 235, 0.05);
        }

        .card-info-left {
            flex: 1;
        }

        .card-name {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }

        .card-meta {
            font-size: 0.8rem;
            color: var(--text-secondary);
            display: flex;
            gap: 1rem;
        }

        .card-price-info {
            text-align: right;
            margin-right: 1rem;
            min-width: 80px;
        }

        .card-current-price {
            font-weight: 600;
            color: var(--success-color);
        }

        .card-trend {
            font-size: 0.7rem;
            color: var(--text-secondary);
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.7);
            backdrop-filter: blur(2px);
        }
        
        .modal-content {
            background-color: var(--surface-color);
            margin: 2% auto;
            padding: 0;
            border-radius: 12px;
            width: 95%;
            max-width: 1200px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            position: relative;
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 1.5rem 2rem;
            border-radius: 12px 12px 0 0;
            position: sticky;
            top: 0;
            z-index: 1001;
        }
        
        .modal-header h2 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: bold;
        }
        
        .close {
            color: white;
            float: right;
            font-size: 2rem;
            font-weight: bold;
            cursor: pointer;
            line-height: 1;
            opacity: 0.8;
            transition: opacity 0.2s;
        }
        
        .close:hover {
            opacity: 1;
        }
        
        .modal-body {
            padding: 2rem;
        }
        
        .modal-grid {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }
        
        .modal-image-section {
            text-align: center;
        }
        
        .modal-card-image {
            max-width: 100%;
            height: auto;
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.2);
        }
        
        .modal-info-section h3 {
            color: var(--primary-color);
            border-bottom: 2px solid var(--primary-color);
            padding-bottom: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 0.75rem 1rem;
            margin-bottom: 1.5rem;
        }
        
        .info-label {
            font-weight: bold;
            color: var(--text-secondary);
        }
        
        .info-value {
            color: var(--text-primary);
        }
        
        .modal-section {
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: var(--background-color);
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }
        
        .modal-section h3 {
            margin-top: 0;
            color: var(--primary-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .oracle-text {
            background: var(--surface-color);
            padding: 1rem;
            border-radius: 6px;
            border-left: 4px solid var(--primary-color);
            font-style: italic;
            line-height: 1.5;
        }
        
        .flavor-text {
            background: var(--surface-color);
            padding: 1rem;
            border-radius: 6px;
            border-left: 4px solid var(--secondary-color);
            font-style: italic;
            line-height: 1.5;
            color: var(--text-secondary);
        }
        
        .legalities {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .modal-content {
                margin: 3% auto;
                width: 98%;
                max-height: 85vh;
            }
            
            .modal-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .modal-body {
                padding: 1rem;
            }
            
            .modal-header {
                padding: 1rem;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
                gap: 0.5rem;
            }
            
            .info-label {
                font-size: 0.9rem;
            }
        }
        
        /* Animations */
        .modal {
            animation: fadeIn 0.3s ease-out;
        }
        
        .modal-content {
            animation: slideIn 0.3s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideIn {
            from { 
                opacity: 0;
                transform: translateY(-50px) scale(0.95);
            }
            to { 
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        
        /* Scrollbar styling for modal */
        .modal-content::-webkit-scrollbar {
            width: 8px;
        }
        
        .modal-content::-webkit-scrollbar-track {
            background: rgba(0,0,0,0.1);
            border-radius: 4px;
        }
        
        .modal-content::-webkit-scrollbar-thumb {
            background: var(--primary-color);
            border-radius: 4px;
        }
        
        .modal-content::-webkit-scrollbar-thumb:hover {
            background: var(--secondary-color);
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="container">
        <div class="main-content">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">🎯 New Deck Builder</h1>
                <p class="page-subtitle">Stellen Sie Ihr Wunsch-Deck zusammen und kopieren Sie die Liste</p>
            </div>

            <!-- Success/Error Messages -->
            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <?= htmlspecialchars($success_message) ?>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-danger">
                    <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>

            <div class="deck-builder-container">
                <!-- Left Column: Card Input -->
                <div>
                    <div class="card-input-form">
                        <h3 style="margin-bottom: 1rem; color: var(--text-primary);">Karte hinzufügen</h3>
                        
                        <form method="post">
                            <input type="hidden" name="action" value="add_card">
                            
                            <div class="input-group">
                                <input type="number" name="quantity" class="form-control quantity-input" 
                                       value="1" min="1" max="99" placeholder="Anzahl">
                                
                                <div class="autocomplete-container">
                                    <input type="text" name="card_name" id="cardNameInput" 
                                           class="form-control card-name-input" 
                                           placeholder="Kartenname eingeben..." 
                                           autocomplete="off" required>
                                    <div class="autocomplete-suggestions" id="autocompleteSuggestions"></div>
                                </div>
                                
                                <select name="card_language" class="form-control language-select">
                                    <option value="de" selected>🇩🇪 Deutsch</option>
                                    <option value="en">🇺🇸 English</option>
                                    <option value="es">🇪🇸 Español</option>
                                    <option value="fr">🇫🇷 Français</option>
                                    <option value="it">🇮🇹 Italiano</option>
                                    <option value="pt">🇵🇹 Português</option>
                                    <option value="ja">🇯🇵 日本語</option>
                                    <option value="ko">🇰🇷 한국어</option>
                                    <option value="ru">🇷🇺 Русский</option>
                                    <option value="zhs">🇨🇳 中文简体</option>
                                    <option value="zht">🇹🇼 中文繁體</option>
                                </select>
                            </div>
                            
                            <button type="submit" class="btn btn-primary" style="width: 100%;">
                                ➕ Karte hinzufügen
                            </button>
                        </form>
                        
                        <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border-color);">
                            <small style="color: var(--text-secondary);">
                                💡 <strong>Tipp:</strong> Suchen Sie nach beliebigen Magic-Karten über die Scryfall API. 
                                Verwenden Sie die Autocomplete-Funktion für schnelle Eingabe.<br>
                                🌐 <strong>Sprache:</strong> Wählen Sie die gewünschte Kartensprache. 
                                Standard ist Deutsch - falls verfügbar wird die deutsche Version geladen.
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Deck List -->
                <div>
                    <div class="deck-list-container">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                            <h3 style="margin: 0; color: var(--text-primary);">Deck-Liste</h3>
                            <?php if (!empty($deck_cards)): ?>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="action" value="clear_deck">
                                    <button type="submit" class="btn btn-sm btn-danger" 
                                            onclick="return confirm('Gesamte Deck-Liste löschen?')">
                                        🗑️ Alles löschen
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                        
                        <div class="deck-stats">
                            <div>
                                <strong><?= $total_cards ?></strong> Karten gesamt
                            </div>
                            <div>
                                <strong><?= $unique_cards ?></strong> verschiedene Karten
                            </div>
                            <div>
                                <strong>€<?= number_format($total_deck_value, 2) ?></strong> Gesamtwert
                            </div>
                        </div>
                        
                        <!-- Deck Management -->
                        <div class="deck-management" style="margin: 1rem 0; padding: 1rem; background: var(--surface-color); border-radius: 8px; border: 1px solid var(--border-color);">
                            <h4 style="margin: 0 0 1rem 0; color: var(--text-primary);">💾 Deck verwalten</h4>
                            
                            <!-- Save Deck -->
                            <form method="post" style="margin-bottom: 1rem;">
                                <input type="hidden" name="action" value="save_deck">
                                <div style="display: flex; gap: 0.5rem; margin-bottom: 0.5rem;">
                                    <input type="text" name="deck_name" placeholder="Deck-Name..." 
                                           value="<?= htmlspecialchars($current_deck_name) ?>" 
                                           style="flex: 1; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px;"
                                           required>
                                    <button type="submit" class="btn btn-primary" style="padding: 0.5rem 1rem;">
                                        💾 Speichern
                                    </button>
                                </div>
                                <input type="text" name="deck_description" placeholder="Beschreibung (optional)..." 
                                       style="width: 100%; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px;">
                            </form>
                            
                            <!-- Load Deck -->
                            <?php if (!empty($saved_decks)): ?>
                                <div style="margin-bottom: 1rem;">
                                    <strong style="color: var(--text-primary);">📂 Gespeicherte Decks:</strong>
                                    <div style="max-height: 200px; overflow-y: auto; margin-top: 0.5rem;">
                                        <?php foreach ($saved_decks as $saved_deck): ?>
                                            <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px; margin-bottom: 0.5rem; background: var(--background-color);">
                                                <div style="flex: 1;">
                                                    <strong><?= htmlspecialchars($saved_deck['deck_name']) ?></strong>
                                                    <?php if ($saved_deck['description']): ?>
                                                        <br><small style="color: var(--text-secondary);"><?= htmlspecialchars($saved_deck['description']) ?></small>
                                                    <?php endif; ?>
                                                    <br><small style="color: var(--text-secondary);">
                                                        <?= $saved_deck['total_cards'] ?> Karten • <?= date('d.m.Y H:i', strtotime($saved_deck['updated_at'])) ?>
                                                    </small>
                                                </div>
                                                <div style="display: flex; gap: 0.25rem;">
                                                    <form method="post" style="display: inline;">
                                                        <input type="hidden" name="action" value="load_deck">
                                                        <input type="hidden" name="deck_id" value="<?= $saved_deck['id'] ?>">
                                                        <button type="submit" class="btn btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.8rem;">
                                                            📂 Laden
                                                        </button>
                                                    </form>
                                                    <form method="post" style="display: inline;" onsubmit="return confirm('Deck wirklich löschen?');">
                                                        <input type="hidden" name="action" value="delete_deck">
                                                        <input type="hidden" name="deck_id" value="<?= $saved_deck['id'] ?>">
                                                        <button type="submit" class="btn btn-danger" style="padding: 0.25rem 0.5rem; font-size: 0.8rem;">
                                                            🗑️
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Clear current deck -->
                            <form method="post" style="display: inline;" onsubmit="return confirm('Aktuelle Einkaufsliste wirklich leeren?');">
                                <input type="hidden" name="action" value="clear_deck">
                                <button type="submit" class="btn btn-danger" style="width: 100%;">
                                    🗑️ Aktuelle Liste leeren
                                </button>
                            </form>
                        </div>
                        
                        <?php if (empty($deck_cards)): ?>
                            <div style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                                <p>Noch keine Karten hinzugefügt</p>
                                <p style="font-size: 0.9rem;">Beginnen Sie mit der Eingabe links!</p>
                            </div>
                        <?php else: ?>
                            <div class="deck-list">
                                <?php foreach ($deck_cards as $index => $card): ?>
                                    <div class="deck-item-extended" data-card-index="<?= $index ?>">
                                        <div class="card-info-left" style="cursor: pointer;" onclick="openCardModal(<?= $index ?>)">
                                            <div class="card-name" style="color: var(--primary-color); text-decoration: underline;">
                                                <?= htmlspecialchars($card['name']) ?>
                                                <?php if (isset($card['card_data']['original_language']) && $card['card_data']['original_language'] !== 'en'): ?>
                                                    <?php 
                                                    $lang_flags = [
                                                        'de' => '🇩🇪',
                                                        'es' => '🇪🇸', 
                                                        'fr' => '🇫🇷',
                                                        'it' => '🇮🇹',
                                                        'pt' => '🇵🇹',
                                                        'ja' => '🇯🇵',
                                                        'ko' => '🇰🇷',
                                                        'ru' => '🇷🇺',
                                                        'zhs' => '🇨🇳',
                                                        'zht' => '🇹🇼'
                                                    ];
                                                    $flag = $lang_flags[$card['card_data']['original_language']] ?? '🌐';
                                                    ?>
                                                    <span style="font-size: 0.9rem; margin-left: 0.5rem;"><?= $flag ?></span>
                                                    <?php if (isset($card['card_data']['english_name']) && $card['card_data']['english_name'] !== $card['name']): ?>
                                                        <br><small style="color: var(--text-secondary); font-style: italic;">
                                                            (<?= htmlspecialchars($card['card_data']['english_name']) ?>)
                                                        </small>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                <span style="font-size: 0.8rem; margin-left: 0.5rem; color: var(--text-secondary);">🔍 Details anzeigen</span>
                                            </div>
                                            <div class="card-meta">
                                                <?php if (isset($card['card_data'])): ?>
                                                    <span>Set: <?= htmlspecialchars($card['card_data']['set_name'] ?? 'N/A') ?></span>
                                                    <span>Seltenheit: <?= htmlspecialchars(ucfirst($card['card_data']['rarity'] ?? 'N/A')) ?></span>
                                                    <?php if (isset($card['card_data']['mana_cost'])): ?>
                                                        <span>Mana: <?= htmlspecialchars($card['card_data']['mana_cost']) ?></span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                        <div class="card-price-info">
                            <?php if ($card['current_price'] > 0): ?>
                                <div class="card-current-price">€<?= number_format($card['current_price'], 2) ?></div>
                                <div class="card-trend">je Karte (Bester Preis)</div>
                                <div style="font-size: 0.8rem; color: var(--primary-color); margin-top: 2px;">
                                    Total: €<?= number_format($card['current_price'] * $card['quantity'], 2) ?>
                                </div>
                                
                                <!-- Detailed Price Sources -->
                                <?php if (isset($card['card_data']['comprehensive_pricing']['all_sources'])): ?>
                                    <div class="price-sources" style="margin-top: 0.5rem; padding: 0.5rem; background: var(--background-color); border-radius: 4px; border: 1px solid var(--border-color);">
                                        <div style="font-size: 0.75rem; font-weight: bold; color: var(--text-primary); margin-bottom: 0.25rem;">
                                            📊 Alle Preise:
                                        </div>
                                        <?php foreach ($card['card_data']['comprehensive_pricing']['all_sources'] as $source => $price): ?>
                                            <div style="display: flex; justify-content: space-between; font-size: 0.7rem; margin-bottom: 0.1rem; color: <?= $source === $card['price_source'] ? 'var(--success-color)' : 'var(--text-secondary)' ?>;">
                                                <span><?= htmlspecialchars($source) ?>:</span>
                                                <span style="font-weight: <?= $source === $card['price_source'] ? 'bold' : 'normal' ?>;">
                                                    €<?= number_format($price, 2) ?>
                                                    <?= $source === $card['price_source'] ? ' ✓' : '' ?>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                        
                                        <!-- Show original USD prices if available -->
                                        <?php $pricing = $card['card_data']['comprehensive_pricing']; ?>
                                        <?php if ($pricing['scryfall_usd'] || $pricing['tcgplayer_usd'] || $pricing['cardkingdom_usd']): ?>
                                            <div style="margin-top: 0.25rem; padding-top: 0.25rem; border-top: 1px solid var(--border-color);">
                                                <div style="font-size: 0.7rem; color: var(--text-secondary); margin-bottom: 0.1rem;">
                                                    💵 Original USD:
                                                </div>
                                                <?php if ($pricing['scryfall_usd']): ?>
                                                    <div style="font-size: 0.65rem; color: var(--text-secondary);">
                                                        Scryfall: $<?= number_format($pricing['scryfall_usd'], 2) ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($pricing['tcgplayer_usd']): ?>
                                                    <div style="font-size: 0.65rem; color: var(--text-secondary);">
                                                        TCGPlayer: $<?= number_format($pricing['tcgplayer_usd'], 2) ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($pricing['cardkingdom_usd']): ?>
                                                    <div style="font-size: 0.65rem; color: var(--text-secondary);">
                                                        Card Kingdom: $<?= number_format($pricing['cardkingdom_usd'], 2) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php elseif (isset($card['price_source'])): ?>
                                    <div style="font-size: 0.7rem; color: var(--text-secondary); margin-top: 1px;">
                                        <?= htmlspecialchars($card['price_source']) ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="card-current-price">N/A</div>
                                <div class="card-trend">Kein Preis</div>
                            <?php endif; ?>
                        </div>                                        <div class="deck-item-actions">
                                            <div class="quantity-editor">
                                                <form method="post" style="display: inline;">
                                                    <input type="hidden" name="action" value="update_quantity">
                                                    <input type="hidden" name="index" value="<?= $index ?>">
                                                    <input type="hidden" name="new_quantity" value="<?= $card['quantity'] - 1 ?>">
                                                    <button type="submit" class="quantity-btn">−</button>
                                                </form>
                                                
                                                <span class="quantity-display"><?= $card['quantity'] ?>x</span>
                                                
                                                <form method="post" style="display: inline;">
                                                    <input type="hidden" name="action" value="update_quantity">
                                                    <input type="hidden" name="index" value="<?= $index ?>">
                                                    <input type="hidden" name="new_quantity" value="<?= $card['quantity'] + 1 ?>">
                                                    <button type="submit" class="quantity-btn">+</button>
                                                </form>
                                            </div>
                                            
                                            <form method="post" style="display: inline;">
                                                <input type="hidden" name="action" value="remove_card">
                                                <input type="hidden" name="index" value="<?= $index ?>">
                                                <button type="submit" class="btn btn-sm btn-danger" 
                                                        onclick="return confirm('Karte entfernen?')" 
                                                        title="Karte entfernen">
                                                    ❌
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Third Column: Last Added Card -->
                <div class="last-added-container">
                    <h3 style="margin-bottom: 1rem; color: var(--text-primary);">🎯 Zuletzt hinzugefügt</h3>
                    
                    <?php if ($last_added_card): ?>
                        <div class="last-added-card">
                            <?php if (isset($last_added_card['card_data']['image_uris']['normal'])): ?>
                                <img src="<?= htmlspecialchars($last_added_card['card_data']['image_uris']['normal']) ?>" 
                                     alt="<?= htmlspecialchars($last_added_card['name']) ?>" 
                                     class="card-preview-image">
                            <?php endif; ?>
                            
                            <div style="margin-bottom: 0.5rem;">
                                <strong><?= htmlspecialchars($last_added_card['name']) ?></strong>
                            </div>
                            
                            <div style="color: var(--primary-color); font-weight: 600; margin-bottom: 0.5rem;">
                                <?= $last_added_card['quantity'] ?>x hinzugefügt
                            </div>
                            
                            <div style="font-size: 0.8rem; color: var(--text-secondary);">
                                <?= date('H:i:s', $last_added_card['added_at']) ?> Uhr
                            </div>
                            
                            <?php if (isset($last_added_card['card_data'])): ?>
                                <div class="price-info">
                                    <div class="price-row">
                                        <span>Set:</span>
                                        <span><?= htmlspecialchars($last_added_card['card_data']['set_name'] ?? 'N/A') ?></span>
                                    </div>
                                    <div class="price-row">
                                        <span>Seltenheit:</span>
                                        <span><?= htmlspecialchars(ucfirst($last_added_card['card_data']['rarity'] ?? 'N/A')) ?></span>
                                    </div>
                                    <div class="price-row">
                                        <span>Manakosten:</span>
                                        <span><?= htmlspecialchars($last_added_card['card_data']['mana_cost'] ?? 'N/A') ?></span>
                                    </div>
                                    <?php if (isset($last_added_card['card_data']['comprehensive_pricing'])): ?>
                                        <?php 
                                        $pricing = $last_added_card['card_data']['comprehensive_pricing'];
                                        $current_price = $pricing['best_price_eur'] ?? 0;
                                        ?>
                                        <?php if ($current_price > 0): ?>
                                            <div class="price-row">
                                                <span>Bester Preis:</span>
                                                <span class="price-current">€<?= number_format($current_price, 2) ?></span>
                                            </div>
                                            <div class="price-row">
                                                <span>Gesamtwert:</span>
                                                <span class="price-current">€<?= number_format($current_price * $last_added_card['quantity'], 2) ?></span>
                                            </div>
                                            
                                            <!-- Detailed Price Breakdown -->
                                            <div style="margin-top: 0.75rem; padding: 0.5rem; background: var(--background-color); border-radius: 4px; border: 1px solid var(--border-color);">
                                                <div style="font-size: 0.8rem; font-weight: bold; color: var(--text-primary); margin-bottom: 0.5rem;">
                                                    📊 Preisvergleich:
                                                </div>
                                                
                                                <?php if (!empty($pricing['all_sources'])): ?>
                                                    <?php foreach ($pricing['all_sources'] as $source => $price): ?>
                                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.25rem; padding: 0.25rem; border-radius: 3px; background: <?= $source === $pricing['price_source'] ? 'var(--success-color)' : 'transparent' ?>; color: <?= $source === $pricing['price_source'] ? 'white' : 'var(--text-primary)' ?>;">
                                                            <span style="font-size: 0.75rem; font-weight: <?= $source === $pricing['price_source'] ? 'bold' : 'normal' ?>;">
                                                                <?= htmlspecialchars($source) ?>:
                                                            </span>
                                                            <span style="font-size: 0.75rem; font-weight: <?= $source === $pricing['price_source'] ? 'bold' : 'normal' ?>;">
                                                                €<?= number_format($price, 2) ?>
                                                                <?= $source === $pricing['price_source'] ? ' ✓' : '' ?>
                                                            </span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                                
                                                <!-- Original USD Prices -->
                                                <?php if ($pricing['scryfall_usd'] || $pricing['tcgplayer_usd'] || $pricing['cardkingdom_usd']): ?>
                                                    <div style="margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid var(--border-color);">
                                                        <div style="font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 0.25rem; font-weight: bold;">
                                                            💵 Original USD-Preise:
                                                        </div>
                                                        <?php if ($pricing['scryfall_usd']): ?>
                                                            <div style="font-size: 0.7rem; color: var(--text-secondary); margin-bottom: 0.1rem;">
                                                                • Scryfall: $<?= number_format($pricing['scryfall_usd'], 2) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($pricing['tcgplayer_usd']): ?>
                                                            <div style="font-size: 0.7rem; color: var(--text-secondary); margin-bottom: 0.1rem;">
                                                                • TCGPlayer: $<?= number_format($pricing['tcgplayer_usd'], 2) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($pricing['cardkingdom_usd']): ?>
                                                            <div style="font-size: 0.7rem; color: var(--text-secondary); margin-bottom: 0.1rem;">
                                                                • Card Kingdom: $<?= number_format($pricing['cardkingdom_usd'], 2) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($pricing['mtgo_tix']): ?>
                                                            <div style="font-size: 0.7rem; color: var(--text-secondary); margin-bottom: 0.1rem;">
                                                                • MTGO: <?= number_format($pricing['mtgo_tix'], 2) ?> Tix
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <div style="margin-top: 0.5rem; font-size: 0.65rem; color: var(--text-secondary); font-style: italic;">
                                                    💡 Wechselkurs: 1 USD = <?= number_format(getExchangeRate(), 4) ?> EUR
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    <?php elseif (isset($last_added_card['card_data']['prices'])): ?>
                                        <?php 
                                        $prices = $last_added_card['card_data']['prices'];
                                        $current_price = null;
                                        $source = '';
                                        
                                        if (isset($prices['eur']) && $prices['eur']) {
                                            $current_price = floatval($prices['eur']);
                                            $source = 'Scryfall EUR';
                                        } elseif (isset($prices['usd']) && $prices['usd']) {
                                            $current_price = convertUsdToEur($prices['usd']);
                                            $source = 'Scryfall USD→EUR';
                                        }
                                        ?>
                                        <?php if ($current_price > 0): ?>
                                            <div class="price-row">
                                                <span>Aktueller Preis:</span>
                                                <span class="price-current">€<?= number_format($current_price, 2) ?></span>
                                            </div>
                                            <div class="price-row">
                                                <span>Gesamtwert:</span>
                                                <span class="price-current">€<?= number_format($current_price * $last_added_card['quantity'], 2) ?></span>
                                            </div>
                                            <div style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.25rem;">
                                                Quelle: <?= htmlspecialchars($source) ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <?php if (isset($last_added_card['card_data']['oracle_text'])): ?>
                                        <div style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid var(--border-color);">
                                            <div style="font-size: 0.8rem; color: var(--text-secondary); line-height: 1.3;">
                                                <strong>Kartentext:</strong><br>
                                                <?= nl2br(htmlspecialchars(substr($last_added_card['card_data']['oracle_text'], 0, 200) . (strlen($last_added_card['card_data']['oracle_text']) > 200 ? '...' : ''))) ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="last-added-card">
                            <div style="color: var(--text-secondary); font-style: italic;">
                                Noch keine Karte hinzugefügt
                            </div>
                            <div style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.5rem;">
                                Die zuletzt hinzugefügte Karte wird hier angezeigt
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Copy Section -->
            <?php if (!empty($deck_cards)): ?>
                <div class="copy-section">
                    <h3 style="margin-bottom: 1rem; color: var(--text-primary);">📋 Kopierbare Deck-Liste</h3>
                    
                    <div class="copy-buttons">
                        <button onclick="copyToClipboard('console-output')" class="btn btn-primary">
                            📋 Liste kopieren
                        </button>
                        <button onclick="generateMTGOFormat()" class="btn btn-secondary">
                            🎮 MTGO Format
                        </button>
                        <button onclick="generateArenaFormat()" class="btn btn-secondary">
                            🏛️ Arena Format
                        </button>
                    </div>
                    
                    <div class="copy-console" id="console-output"><?php
                    foreach ($deck_cards as $card) {
                        echo $card['quantity'] . ' ' . $card['name'] . "\n";
                    }
                    ?></div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Card Detail Modal -->
    <div id="cardModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalCardName" style="margin: 0; color: var(--primary-color);">Kartendetails</h2>
                <span class="modal-close" onclick="closeCardModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="card-detail-container">
                    <!-- Left Side: Card Image and Basic Info -->
                    <div class="card-detail-left">
                        <div class="card-image-container" style="text-align: center; margin-bottom: 1rem;">
                            <img id="modalCardImage" src="" alt="" style="max-width: 100%; max-height: 400px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.3);">
                        </div>
                        
                        <div class="card-basic-info" style="background: var(--surface-color); padding: 1rem; border-radius: 8px; border: 1px solid var(--border-color);">
                            <h3 style="margin-top: 0; color: var(--text-primary);">🃏 Basis-Informationen</h3>
                            <div class="info-grid" style="display: grid; gap: 0.5rem;">
                                <div><strong>Manakosten:</strong> <span id="modalManaCost">N/A</span></div>
                                <div><strong>CMC:</strong> <span id="modalCmc">N/A</span></div>
                                <div><strong>Typ:</strong> <span id="modalTypeLine">N/A</span></div>
                                <div><strong>Seltenheit:</strong> <span id="modalRarity">N/A</span></div>
                                <div id="modalPowerToughness" style="display: none;"><strong>Power/Toughness:</strong> <span id="modalPtValue">N/A</span></div>
                                <div><strong>Set:</strong> <span id="modalSetName">N/A</span></div>
                                <div><strong>Künstler:</strong> <span id="modalArtist">N/A</span></div>
                                <div><strong>Sammlernummer:</strong> <span id="modalCollectorNumber">N/A</span></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right Side: Detailed Information -->
                    <div class="card-detail-right">
                        <!-- Oracle Text -->
                        <div class="oracle-section" style="background: var(--surface-color); padding: 1rem; border-radius: 8px; border: 1px solid var(--border-color); margin-bottom: 1rem;">
                            <h3 style="margin-top: 0; color: var(--text-primary);">📜 Regeltext</h3>
                            <div id="modalOracleText" style="line-height: 1.4; color: var(--text-primary);">N/A</div>
                        </div>
                        
                        <!-- Flavor Text -->
                        <div id="modalFlavorSection" class="flavor-section" style="background: var(--surface-color); padding: 1rem; border-radius: 8px; border: 1px solid var(--border-color); margin-bottom: 1rem; display: none;">
                            <h3 style="margin-top: 0; color: var(--text-primary);">✨ Flavor Text</h3>
                            <div id="modalFlavorText" style="font-style: italic; color: var(--text-secondary); line-height: 1.4;"></div>
                        </div>
                        
                        <!-- Comprehensive Pricing -->
                        <div class="pricing-section" style="background: var(--surface-color); padding: 1rem; border-radius: 8px; border: 1px solid var(--border-color); margin-bottom: 1rem;">
                            <h3 style="margin-top: 0; color: var(--text-primary);">💰 Detaillierte Preise</h3>
                            <div id="modalPricingContent">Keine Preisdaten verfügbar</div>
                        </div>
                        
                        <!-- Legalities -->
                        <div class="legalities-section" style="background: var(--surface-color); padding: 1rem; border-radius: 8px; border: 1px solid var(--border-color); margin-bottom: 1rem;">
                            <h3 style="margin-top: 0; color: var(--text-primary);">⚖️ Format-Legalität</h3>
                            <div id="modalLegalities" style="display: flex; flex-wrap: wrap; gap: 0.5rem;"></div>
                        </div>
                        
                        <!-- Additional Info -->
                        <div class="additional-info" style="background: var(--surface-color); padding: 1rem; border-radius: 8px; border: 1px solid var(--border-color);">
                            <h3 style="margin-top: 0; color: var(--text-primary);">ℹ️ Weitere Informationen</h3>
                            <div class="info-grid" style="display: grid; gap: 0.5rem;">
                                <div><strong>Scryfall ID:</strong> <span id="modalScryfallId">N/A</span></div>
                                <div><strong>MTGO ID:</strong> <span id="modalMtgoId">N/A</span></div>
                                <div id="modalEdhrecRank" style="display: none;"><strong>EDHREC Rank:</strong> <span id="modalEdhrecValue">N/A</span></div>
                                <div><strong>Farbidentität:</strong> <span id="modalColorIdentity">N/A</span></div>
                                <div><strong>Farben:</strong> <span id="modalColors">N/A</span></div>
                                <div><strong>Keywords:</strong> <span id="modalKeywords">N/A</span></div>
                                <div><strong>Reserviert:</strong> <span id="modalReserved">N/A</span></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Card data for modal
        const deckCards = <?= json_encode($deck_cards) ?>;
        
        // Open card detail modal
        function openCardModal(cardIndex) {
            const card = deckCards[cardIndex];
            if (!card || !card.card_data) {
                alert('Keine Kartendaten verfügbar');
                return;
            }
            
            const cardData = card.card_data;
            const modal = document.getElementById('cardModal');
            
            // Basic Information
            document.getElementById('modalCardName').textContent = cardData.name || 'N/A';
            document.getElementById('modalManaCost').textContent = cardData.mana_cost || 'N/A';
            document.getElementById('modalCmc').textContent = cardData.cmc || 'N/A';
            document.getElementById('modalTypeLine').textContent = cardData.type_line || 'N/A';
            document.getElementById('modalRarity').textContent = (cardData.rarity || 'N/A').charAt(0).toUpperCase() + (cardData.rarity || 'N/A').slice(1);
            document.getElementById('modalSetName').textContent = cardData.set_name || 'N/A';
            document.getElementById('modalArtist').textContent = cardData.artist || 'N/A';
            document.getElementById('modalCollectorNumber').textContent = cardData.collector_number || 'N/A';
            document.getElementById('modalScryfallId').textContent = cardData.id || 'N/A';
            document.getElementById('modalMtgoId').textContent = cardData.mtgo_id || 'N/A';
            
            // Power/Toughness
            if (cardData.power && cardData.toughness) {
                document.getElementById('modalPowerToughness').style.display = 'block';
                document.getElementById('modalPtValue').textContent = cardData.power + '/' + cardData.toughness;
            } else {
                document.getElementById('modalPowerToughness').style.display = 'none';
            }
            
            // EDHREC Rank
            if (cardData.edhrec_rank) {
                document.getElementById('modalEdhrecRank').style.display = 'block';
                document.getElementById('modalEdhrecValue').textContent = '#' + cardData.edhrec_rank.toLocaleString();
            } else {
                document.getElementById('modalEdhrecRank').style.display = 'none';
            }
            
            // Image
            const imageEl = document.getElementById('modalCardImage');
            if (cardData.image_uris && cardData.image_uris.normal) {
                imageEl.src = cardData.image_uris.normal;
                imageEl.alt = cardData.name;
                imageEl.style.display = 'block';
            } else {
                imageEl.style.display = 'none';
            }
            
            // Oracle Text
            document.getElementById('modalOracleText').innerHTML = cardData.oracle_text ? 
                cardData.oracle_text.replace(/\n/g, '<br>') : 'Kein Regeltext verfügbar';
            
            // Flavor Text
            const flavorSection = document.getElementById('modalFlavorSection');
            if (cardData.flavor_text) {
                document.getElementById('modalFlavorText').innerHTML = cardData.flavor_text.replace(/\n/g, '<br>');
                flavorSection.style.display = 'block';
            } else {
                flavorSection.style.display = 'none';
            }
            
            // Colors and Color Identity
            document.getElementById('modalColors').textContent = 
                (cardData.colors && cardData.colors.length > 0) ? cardData.colors.join(', ') : 'Farblos';
            document.getElementById('modalColorIdentity').textContent = 
                (cardData.color_identity && cardData.color_identity.length > 0) ? cardData.color_identity.join(', ') : 'Farblos';
            
            // Keywords
            document.getElementById('modalKeywords').textContent = 
                (cardData.keywords && cardData.keywords.length > 0) ? cardData.keywords.join(', ') : 'Keine';
            
            // Reserved
            document.getElementById('modalReserved').textContent = cardData.reserved ? 'Ja' : 'Nein';
            
            // Pricing Information
            populateModalPricing(cardData);
            
            // Legalities
            populateModalLegalities(cardData.legalities || {});
            
            // Show modal
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
        
        // Populate pricing information
        function populateModalPricing(cardData) {
            const pricingContent = document.getElementById('modalPricingContent');
            let pricingHtml = '';
            
            if (cardData.comprehensive_pricing && cardData.comprehensive_pricing.all_sources) {
                const pricing = cardData.comprehensive_pricing;
                const sources = pricing.all_sources;
                
                // Best price
                pricingHtml += `<div style="background: var(--success-color); color: white; padding: 0.75rem; border-radius: 6px; margin-bottom: 1rem; text-align: center;">
                    <strong>🏆 Bester Preis: €${pricing.best_price_eur.toFixed(2)} (${pricing.price_source})</strong>
                </div>`;
                
                // All EUR sources
                pricingHtml += '<div style="margin-bottom: 1rem;"><strong>💶 EUR Preise:</strong><div style="margin-top: 0.5rem;">';
                for (const [source, price] of Object.entries(sources)) {
                    const isBest = source === pricing.price_source;
                    pricingHtml += `<div style="display: flex; justify-content: space-between; padding: 0.5rem; margin-bottom: 0.25rem; border-radius: 4px; background: ${isBest ? 'var(--success-color)' : 'var(--background-color)'}; color: ${isBest ? 'white' : 'var(--text-primary)'};">
                        <span>${source}:</span>
                        <span><strong>€${price.toFixed(2)}</strong> ${isBest ? '✓' : ''}</span>
                    </div>`;
                }
                pricingHtml += '</div></div>';
                
                // Original USD prices
                if (pricing.scryfall_usd || pricing.tcgplayer_usd || pricing.cardkingdom_usd) {
                    pricingHtml += '<div style="margin-bottom: 1rem;"><strong>💵 Original USD:</strong><div style="margin-top: 0.5rem;">';
                    if (pricing.scryfall_usd) {
                        pricingHtml += `<div style="font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 0.25rem;">• Scryfall: $${pricing.scryfall_usd.toFixed(2)}</div>`;
                    }
                    if (pricing.tcgplayer_usd) {
                        pricingHtml += `<div style="font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 0.25rem;">• TCGPlayer: $${pricing.tcgplayer_usd.toFixed(2)}</div>`;
                    }
                    if (pricing.cardkingdom_usd) {
                        pricingHtml += `<div style="font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 0.25rem;">• Card Kingdom: $${pricing.cardkingdom_usd.toFixed(2)}</div>`;
                    }
                    pricingHtml += '</div></div>';
                }
                
                // MTGO
                if (pricing.mtgo_tix) {
                    pricingHtml += `<div style="margin-bottom: 1rem;"><strong>🎮 MTGO:</strong> ${pricing.mtgo_tix.toFixed(2)} Tix</div>`;
                }
                
                // Foil prices
                if (pricing.scryfall_foil_eur || pricing.scryfall_foil_usd_converted) {
                    pricingHtml += '<div style="margin-bottom: 1rem;"><strong>✨ Foil Preise:</strong><div style="margin-top: 0.5rem;">';
                    if (pricing.scryfall_foil_eur) {
                        pricingHtml += `<div>Scryfall Foil EUR: €${pricing.scryfall_foil_eur.toFixed(2)}</div>`;
                    }
                    if (pricing.scryfall_foil_usd_converted) {
                        pricingHtml += `<div>Scryfall Foil USD: €${pricing.scryfall_foil_usd_converted.toFixed(2)}</div>`;
                    }
                    pricingHtml += '</div></div>';
                }
                
            } else if (cardData.prices) {
                // Fallback to simple pricing
                const prices = cardData.prices;
                pricingHtml += '<div><strong>Verfügbare Preise:</strong><div style="margin-top: 0.5rem;">';
                if (prices.eur) pricingHtml += `<div>EUR: €${parseFloat(prices.eur).toFixed(2)}</div>`;
                if (prices.usd) pricingHtml += `<div>USD: $${parseFloat(prices.usd).toFixed(2)}</div>`;
                if (prices.eur_foil) pricingHtml += `<div>EUR Foil: €${parseFloat(prices.eur_foil).toFixed(2)}</div>`;
                if (prices.usd_foil) pricingHtml += `<div>USD Foil: $${parseFloat(prices.usd_foil).toFixed(2)}</div>`;
                if (prices.tix) pricingHtml += `<div>MTGO: ${parseFloat(prices.tix).toFixed(2)} Tix</div>`;
                pricingHtml += '</div></div>';
            } else {
                pricingHtml = '<div style="color: var(--text-secondary);">Keine Preisdaten verfügbar</div>';
            }
            
            pricingContent.innerHTML = pricingHtml;
        }
        
        // Populate legalities
        function populateModalLegalities(legalities) {
            const legalitiesEl = document.getElementById('modalLegalities');
            legalitiesEl.innerHTML = '';
            
            if (Object.keys(legalities).length === 0) {
                legalitiesEl.innerHTML = '<span style="color: var(--text-secondary);">Keine Legalitätsdaten verfügbar</span>';
                return;
            }
            
            for (const [format, status] of Object.entries(legalities)) {
                const badge = document.createElement('span');
                badge.textContent = format.charAt(0).toUpperCase() + format.slice(1);
                badge.style.padding = '0.25rem 0.75rem';
                badge.style.borderRadius = '20px';
                badge.style.fontSize = '0.8rem';
                badge.style.fontWeight = 'bold';
                
                switch (status) {
                    case 'legal':
                        badge.style.background = 'var(--success-color)';
                        badge.style.color = 'white';
                        break;
                    case 'not_legal':
                        badge.style.background = 'var(--error-color)';
                        badge.style.color = 'white';
                        break;
                    case 'restricted':
                        badge.style.background = 'var(--warning-color)';
                        badge.style.color = 'white';
                        badge.textContent += ' (R)';
                        break;
                    case 'banned':
                        badge.style.background = '#ff4757';
                        badge.style.color = 'white';
                        badge.textContent += ' (B)';
                        break;
                    default:
                        badge.style.background = 'var(--text-secondary)';
                        badge.style.color = 'white';
                }
                
                legalitiesEl.appendChild(badge);
            }
        }
        
        // Close modal
        function closeCardModal() {
            document.getElementById('cardModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('cardModal');
            if (event.target === modal) {
                closeCardModal();
            }
        }
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeCardModal();
            }
        });
        
        // Autocomplete functionality using Scryfall API
        const cardNameInput = document.getElementById('cardNameInput');
        const suggestionsContainer = document.getElementById('autocompleteSuggestions');
        let selectedIndex = -1;
        let searchTimeout;
        
        cardNameInput.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();
            suggestionsContainer.innerHTML = '';
            selectedIndex = -1;
            
            // Clear existing timeout
            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }
            
            if (query.length < 2) {
                suggestionsContainer.style.display = 'none';
                return;
            }
            
            // Debounce API calls
            searchTimeout = setTimeout(() => {
                fetch(`api/search_cards.php?q=${encodeURIComponent(query)}`)
                    .then(response => response.json())
                    .then(matches => {
                        if (matches.length === 0) {
                            suggestionsContainer.style.display = 'none';
                            return;
                        }
                        
                        matches.slice(0, 8).forEach((card, index) => {
                            const suggestion = document.createElement('div');
                            suggestion.className = 'autocomplete-suggestion';
                            suggestion.textContent = card;
                            suggestion.addEventListener('click', function() {
                                cardNameInput.value = card;
                                suggestionsContainer.style.display = 'none';
                            });
                            suggestionsContainer.appendChild(suggestion);
                        });
                        
                        suggestionsContainer.style.display = 'block';
                    })
                    .catch(error => {
                        console.error('Autocomplete search error:', error);
                        suggestionsContainer.style.display = 'none';
                    });
            }, 300); // 300ms delay
        });
        
        // Keyboard navigation for autocomplete
        cardNameInput.addEventListener('keydown', function(e) {
            const suggestions = suggestionsContainer.querySelectorAll('.autocomplete-suggestion');
            
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                selectedIndex = Math.min(selectedIndex + 1, suggestions.length - 1);
                updateSelectedSuggestion(suggestions);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                selectedIndex = Math.max(selectedIndex - 1, -1);
                updateSelectedSuggestion(suggestions);
            } else if (e.key === 'Enter' && selectedIndex >= 0) {
                e.preventDefault();
                suggestions[selectedIndex].click();
            } else if (e.key === 'Escape') {
                suggestionsContainer.style.display = 'none';
                selectedIndex = -1;
            }
        });
        
        function updateSelectedSuggestion(suggestions) {
            suggestions.forEach((suggestion, index) => {
                suggestion.classList.toggle('selected', index === selectedIndex);
            });
        }
        
        // Hide suggestions when clicking outside
        document.addEventListener('click', function(e) {
            if (!cardNameInput.contains(e.target) && !suggestionsContainer.contains(e.target)) {
                suggestionsContainer.style.display = 'none';
                selectedIndex = -1;
            }
        });
        
        // Copy to clipboard functionality
        async function copyToClipboard(elementId) {
            const element = document.getElementById(elementId);
            const text = element.textContent;
            
            try {
                await navigator.clipboard.writeText(text);
                
                // Visual feedback
                const originalBg = element.style.backgroundColor;
                element.style.backgroundColor = 'rgba(0, 255, 0, 0.2)';
                setTimeout(() => {
                    element.style.backgroundColor = originalBg;
                }, 300);
                
                // Show success message
                showCopyMessage('✅ Deck-Liste wurde in die Zwischenablage kopiert!');
            } catch (err) {
                console.error('Fehler beim Kopieren:', err);
                showCopyMessage('❌ Fehler beim Kopieren. Bitte manuell kopieren.');
            }
        }
        
        function showCopyMessage(message) {
            // Create temporary message element
            const messageEl = document.createElement('div');
            messageEl.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: var(--success-color);
                color: white;
                padding: 1rem;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.2);
                z-index: 10000;
                font-weight: 500;
            `;
            messageEl.textContent = message;
            document.body.appendChild(messageEl);
            
            // Remove after 3 seconds
            setTimeout(() => {
                document.body.removeChild(messageEl);
            }, 3000);
        }
        
        // Format generators
        function generateMTGOFormat() {
            const consoleOutput = document.getElementById('console-output');
            const deckCards = <?= json_encode($deck_cards) ?>;
            
            let mtgoFormat = '';
            deckCards.forEach(card => {
                mtgoFormat += `${card.quantity} ${card.name}\n`;
            });
            
            consoleOutput.textContent = mtgoFormat;
            copyToClipboard('console-output');
        }
        
        function generateArenaFormat() {
            const consoleOutput = document.getElementById('console-output');
            const deckCards = <?= json_encode($deck_cards) ?>;
            
            let arenaFormat = '';
            deckCards.forEach(card => {
                arenaFormat += `${card.quantity} ${card.name}\n`;
            });
            
            consoleOutput.textContent = arenaFormat;
            copyToClipboard('console-output');
        }
    </script>
</body>
</html>
