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
    $commanders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Filtere zusätzlich durch card_data Parsing wenn vorhanden
    $filtered_commanders = [];
    foreach ($commanders as $commander) {
        $card_data = $commander['card_data'] ? json_decode($commander['card_data'], true) : null;
        
        if ($card_data) {
            // Prüfe ob es eine legendäre Kreatur oder Planeswalker ist
            $type_line = strtolower($card_data['type_line'] ?? '');
            if (strpos($type_line, 'legendary') !== false && 
                (strpos($type_line, 'creature') !== false || strpos($type_line, 'planeswalker') !== false)) {
                $filtered_commanders[] = $commander;
            }
        } else {
            // Fallback: Verwende den Namen wenn keine Daten verfügbar sind
            $filtered_commanders[] = $commander;
        }
    }
    
    echo json_encode(['commanders' => $filtered_commanders]);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
