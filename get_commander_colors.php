<?php
session_start();
require_once 'config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Nicht eingeloggt']);
    exit();
}

if (!isset($_POST['commander_name']) || empty($_POST['commander_name'])) {
    echo json_encode(['error' => 'Commander-Name nicht angegeben']);
    exit();
}

$commander_name = trim($_POST['commander_name']);

try {
    // Get commander's color identity from user's collection
    $stmt = $pdo->prepare("SELECT card_data FROM collections WHERE user_id = ? AND card_name = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id'], $commander_name]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result && !empty($result['card_data'])) {
        $card_data = json_decode($result['card_data'], true);
        
        $color_identity = [];
        $color_names = [];
        $color_symbols = [];
        
        // Debug: Log the raw card data to see what's available
        error_log("Commander data for {$commander_name}: " . print_r($card_data, true));
        
        // Try multiple possible fields for color identity
        if (isset($card_data['colorIdentity']) && is_array($card_data['colorIdentity'])) {
            $color_identity = $card_data['colorIdentity'];
            error_log("Found colorIdentity: " . print_r($color_identity, true));
        } elseif (isset($card_data['color_identity']) && is_array($card_data['color_identity'])) {
            $color_identity = $card_data['color_identity'];
            error_log("Found color_identity: " . print_r($color_identity, true));
        } elseif (isset($card_data['colors']) && is_array($card_data['colors'])) {
            $color_identity = $card_data['colors'];
            error_log("Found colors: " . print_r($color_identity, true));
        } elseif (isset($card_data['manaCost'])) {
            // Extract colors from mana cost if no color identity field
            $mana_cost = $card_data['manaCost'];
            $color_identity = [];
            if (strpos($mana_cost, '{W}') !== false || strpos($mana_cost, 'W') !== false) $color_identity[] = 'W';
            if (strpos($mana_cost, '{U}') !== false || strpos($mana_cost, 'U') !== false) $color_identity[] = 'U';
            if (strpos($mana_cost, '{B}') !== false || strpos($mana_cost, 'B') !== false) $color_identity[] = 'B';
            if (strpos($mana_cost, '{R}') !== false || strpos($mana_cost, 'R') !== false) $color_identity[] = 'R';
            if (strpos($mana_cost, '{G}') !== false || strpos($mana_cost, 'G') !== false) $color_identity[] = 'G';
            error_log("Extracted from manaCost {$mana_cost}: " . print_r($color_identity, true));
        }
        
        // Map colors to names and symbols
        $color_map = [
            'W' => ['name' => 'Weiß', 'symbol' => 'W', 'class' => 'white'],
            'U' => ['name' => 'Blau', 'symbol' => 'U', 'class' => 'blue'],
            'B' => ['name' => 'Schwarz', 'symbol' => 'B', 'class' => 'black'],
            'R' => ['name' => 'Rot', 'symbol' => 'R', 'class' => 'red'],
            'G' => ['name' => 'Grün', 'symbol' => 'G', 'class' => 'green']
        ];
        
        foreach ($color_identity as $color) {
            if (isset($color_map[$color])) {
                $color_names[] = $color_map[$color]['name'];
                $color_symbols[] = $color_map[$color];
            }
        }
        
        // If no colors, it's colorless
        if (empty($color_identity)) {
            $color_names = ['Farblos'];
            $color_symbols = [['name' => 'Farblos', 'symbol' => 'C', 'class' => 'colorless']];
        }
        
        echo json_encode([
            'success' => true,
            'commander_name' => $commander_name,
            'color_identity' => $color_identity,
            'color_names' => $color_names,
            'color_symbols' => $color_symbols,
            'color_count' => count($color_identity),
            'debug_info' => [
                'found_colorIdentity' => isset($card_data['colorIdentity']),
                'found_colors' => isset($card_data['colors']),
                'found_manaCost' => isset($card_data['manaCost']),
                'raw_data_keys' => array_keys($card_data)
            ]
        ]);
        
    } else {
        echo json_encode([
            'error' => 'Commander nicht in Ihrer Sammlung gefunden',
            'commander_name' => $commander_name
        ]);
    }
    
} catch (Exception $e) {
    error_log("Error getting commander colors: " . $e->getMessage());
    echo json_encode([
        'error' => 'Fehler beim Ermitteln der Commander-Farben: ' . $e->getMessage()
    ]);
}
?>
