<?php
session_start();
require_once '../config/database.php';
require_once '../includes/card_translator.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Nicht angemeldet']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Nur POST erlaubt']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['action'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Aktion fehlt']);
    exit();
}

try {
    switch ($input['action']) {
        case 'translate_card':
            $cardId = intval($input['card_id'] ?? 0);
            $targetLang = $input['target_lang'] ?? 'en';
            
            if (!in_array($targetLang, ['en', 'de'])) {
                throw new Exception('Ungültige Sprache');
            }
            
            // Lade Kartendaten
            $stmt = $pdo->prepare("SELECT card_name, card_data FROM collections WHERE id = ? AND user_id = ?");
            $stmt->execute([$cardId, $_SESSION['user_id']]);
            $card = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$card) {
                throw new Exception('Karte nicht gefunden');
            }
            
            $cardData = json_decode($card['card_data'], true);
            
            $response = [
                'success' => true,
                'card_id' => $cardId,
                'language' => $targetLang,
                'translations' => []
            ];
            
            // Übersetze verschiedene Textfelder
            if (isset($cardData['oracle_text'])) {
                $response['translations']['oracle_text'] = CardTranslator::translateCardText($cardData['oracle_text'], $targetLang);
            }
            
            if (isset($cardData['type_line'])) {
                $response['translations']['type_line'] = CardTranslator::translateCardType($cardData['type_line'], $targetLang);
            }
            
            if (isset($cardData['flavor_text'])) {
                $response['translations']['flavor_text'] = CardTranslator::translateCardText($cardData['flavor_text'], $targetLang);
            }
            
            // Kartenname (bleibt meist englisch, aber wir können es trotzdem versuchen)
            $response['translations']['name'] = CardTranslator::translateCardName($card['card_name'], $targetLang);
            
            echo json_encode($response);
            break;
            
        case 'set_language_preference':
            $language = $input['language'] ?? 'en';
            
            if (!in_array($language, ['en', 'de'])) {
                throw new Exception('Ungültige Sprache');
            }
            
            $stmt = $pdo->prepare("UPDATE users SET language_preference = ? WHERE id = ?");
            $stmt->execute([$language, $_SESSION['user_id']]);
            
            echo json_encode([
                'success' => true,
                'language' => $language,
                'message' => 'Spracheinstellung gespeichert'
            ]);
            break;
            
        default:
            throw new Exception('Unbekannte Aktion');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
?>
