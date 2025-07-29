<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once 'config/database.php';

echo "<h1>Decks Test</h1>";

// Check session
if (!isset($_SESSION['user_id'])) {
    echo "<p>No user session - redirecting to index.php</p>";
    // Don't redirect for testing
    $_SESSION['user_id'] = 1; // Set test user
}

echo "<p>User ID: " . $_SESSION['user_id'] . "</p>";

try {
    // Test database connection
    $stmt = $pdo->query("SELECT VERSION()");
    $version = $stmt->fetchColumn();
    echo "<p>Database connected: MySQL version $version</p>";
    
    // Check decks table
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM decks WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $deck_count = $stmt->fetchColumn();
    echo "<p>Decks for user: $deck_count</p>";
    
    // Get actual decks
    $stmt = $pdo->prepare("SELECT * FROM decks WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $decks = $stmt->fetchAll();
    
    echo "<h2>Existing Decks:</h2>";
    if (empty($decks)) {
        echo "<p>No decks found for this user.</p>";
    } else {
        foreach ($decks as $deck) {
            echo "<p>Deck: " . htmlspecialchars($deck['name']) . " (ID: " . $deck['id'] . ")</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}

echo "<p><a href='decks.php'>Go to decks.php</a></p>";
?>
