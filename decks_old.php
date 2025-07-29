<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

echo "<!DOCTYPE html>";
echo "<html><head><title>Decks Test</title></head><body>";
echo "<h1>Decks Page Test</h1>";

try {
    // Get existing decks
    $stmt = $pdo->prepare("
        SELECT d.*, 
               COUNT(dc.id) as card_count,
               SUM(dc.quantity) as total_cards
        FROM decks d
        LEFT JOIN deck_cards dc ON d.id = dc.deck_id AND dc.is_sideboard = 0
        WHERE d.user_id = ?
        GROUP BY d.id
        ORDER BY d.created_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $existing_decks = $stmt->fetchAll();
    
    echo "<h2>Deine Decks (" . count($existing_decks) . ")</h2>";
    
    if (empty($existing_decks)) {
        echo "<p>Keine Decks gefunden.</p>";
    } else {
        foreach ($existing_decks as $deck) {
            echo "<div style='border: 1px solid #ccc; margin: 10px; padding: 10px;'>";
            echo "<h3>" . htmlspecialchars($deck['name']) . "</h3>";
            echo "<p>Format: " . htmlspecialchars($deck['format_type'] ?? 'N/A') . "</p>";
            echo "<p>Karten: " . ($deck['total_cards'] ?? 0) . "</p>";
            echo "<p><a href='deck_view.php?id=" . $deck['id'] . "'>Anzeigen</a></p>";
            echo "</div>";
        }
    }
    
    echo "<h2>Neues Deck erstellen</h2>";
    echo "<p><a href='#'>Später implementiert...</a></p>";

} catch (Exception $e) {
    echo "<p style='color: red;'>Fehler: " . $e->getMessage() . "</p>";
}

echo "</body></html>";
?>
