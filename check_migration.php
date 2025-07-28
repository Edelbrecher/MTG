<?php
require_once 'config/database.php';

echo "<h2>Migration Überprüfung</h2>";

// Grundstatistiken
$cards_count = $pdo->query('SELECT COUNT(*) FROM cards')->fetchColumn();
$collections_count = $pdo->query('SELECT COUNT(*) FROM collections')->fetchColumn();
$cards_total = $pdo->query('SELECT SUM(quantity) FROM cards')->fetchColumn();
$collections_total = $pdo->query('SELECT SUM(quantity) FROM collections')->fetchColumn();

echo "<h3>Grundstatistiken:</h3>";
echo "<p>Anzahl Einträge - cards: $cards_count, collections: $collections_count</p>";
echo "<p>Gesamtmengen - cards: $cards_total, collections: $collections_total</p>";

// Überprüfung der Übertragung
echo "<h3>Detailprüfung (erste 10 Karten aus cards):</h3>";
$stmt = $pdo->query('SELECT name, quantity FROM cards LIMIT 10');
$old_cards = $stmt->fetchAll();

echo "<table border='1'>";
echo "<tr><th>Kartenname</th><th>Alte Menge</th><th>Neue Menge</th><th>Status</th></tr>";

foreach ($old_cards as $old_card) {
    $stmt = $pdo->prepare('SELECT card_name, quantity FROM collections WHERE card_name = ?');
    $stmt->execute([$old_card['name']]);
    $new_card = $stmt->fetch();
    
    echo "<tr>";
    echo "<td>" . htmlspecialchars($old_card['name']) . "</td>";
    echo "<td>" . $old_card['quantity'] . "</td>";
    
    if ($new_card) {
        echo "<td>" . $new_card['quantity'] . "</td>";
        if ($old_card['quantity'] == $new_card['quantity']) {
            echo "<td style='color: green;'>✅ OK</td>";
        } else {
            echo "<td style='color: red;'>❌ UNTERSCHIED</td>";
        }
    } else {
        echo "<td>-</td>";
        echo "<td style='color: red;'>❌ FEHLT</td>";
    }
    echo "</tr>";
}
echo "</table>";

// Prüfe spezifisch auf Karten mit quantity > 1
echo "<h3>Karten mit Menge > 1:</h3>";
$stmt = $pdo->query('SELECT name, quantity FROM cards WHERE quantity > 1');
$multi_cards = $stmt->fetchAll();

if (empty($multi_cards)) {
    echo "<p>Keine Karten mit Menge > 1 in der cards Tabelle gefunden.</p>";
} else {
    echo "<table border='1'>";
    echo "<tr><th>Kartenname</th><th>Alte Menge</th><th>Neue Menge</th><th>Status</th></tr>";
    
    foreach ($multi_cards as $card) {
        $stmt = $pdo->prepare('SELECT quantity FROM collections WHERE card_name = ?');
        $stmt->execute([$card['name']]);
        $new_qty = $stmt->fetchColumn();
        
        echo "<tr>";
        echo "<td>" . htmlspecialchars($card['name']) . "</td>";
        echo "<td>" . $card['quantity'] . "</td>";
        echo "<td>" . ($new_qty ?: 'FEHLT') . "</td>";
        if ($new_qty && $card['quantity'] == $new_qty) {
            echo "<td style='color: green;'>✅ OK</td>";
        } else {
            echo "<td style='color: red;'>❌ PROBLEM</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
}

?>
