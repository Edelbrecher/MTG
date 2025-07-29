<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Nicht angemeldet']);
    exit();
}

try {
    require_once 'config/database.php';
    
    // Suche nach legendären Kreaturen in der Sammlung des Benutzers
    $stmt = $pdo->prepare("
        SELECT DISTINCT card_name, card_data 
        FROM collections 
        WHERE user_id = ? 
        AND (
            LOWER(card_data) LIKE '%legendary%creature%' 
            OR LOWER(card_data) LIKE '%legendary%planeswalker%'
            OR LOWER(card_data) LIKE '%can be your commander%'
        )
        ORDER BY card_name
    ");
    
    $stmt->execute([$_SESSION['user_id']]);
    $potential_commanders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Filtere und erweitere durch card_data Parsing
    $commanders = [];
    foreach ($potential_commanders as $commander) {
        $card_data = $commander['card_data'] ? json_decode($commander['card_data'], true) : null;
        
        if ($card_data) {
            // Prüfe ob es eine legendäre Kreatur oder Planeswalker ist
            $type_line = strtolower($card_data['type_line'] ?? '');
            $is_legendary = strpos($type_line, 'legendary') !== false;
            $is_creature_or_pw = (strpos($type_line, 'creature') !== false || strpos($type_line, 'planeswalker') !== false);
            
            if ($is_legendary && $is_creature_or_pw) {
                // Extrahiere Color Identity für Anzeige
                $color_identity = [];
                if (isset($card_data['color_identity']) && is_array($card_data['color_identity'])) {
                    $color_identity = $card_data['color_identity'];
                } else {
                    // Fallback: extract from mana_cost
                    $mana_cost = $card_data['mana_cost'] ?? '';
                    if (strpos($mana_cost, 'W') !== false) $color_identity[] = 'W';
                    if (strpos($mana_cost, 'U') !== false) $color_identity[] = 'U';
                    if (strpos($mana_cost, 'B') !== false) $color_identity[] = 'B';
                    if (strpos($mana_cost, 'R') !== false) $color_identity[] = 'R';
                    if (strpos($mana_cost, 'G') !== false) $color_identity[] = 'G';
                }
                
                $commanders[] = [
                    'card_name' => $commander['card_name'],
                    'color_identity' => $color_identity,
                    'colors_display' => implode('', $color_identity)
                ];
            }
        } else {
            // Fallback: Verwende den Namen wenn keine Daten verfügbar sind
            $commanders[] = [
                'card_name' => $commander['card_name'],
                'color_identity' => [],
                'colors_display' => ''
            ];
        }
    }
    
    echo json_encode(['commanders' => $commanders]);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
