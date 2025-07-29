<?php
session_start();
require_once 'config/database.php';

echo "<h1>Debug: Decks Page</h1>";
echo "<p>Session ID: " . session_id() . "</p>";
echo "<p>User ID in Session: " . ($_SESSION['user_id'] ?? 'NICHT GESETZT') . "</p>";
echo "<p>Username in Session: " . ($_SESSION['username'] ?? 'NICHT GESETZT') . "</p>";

if (!isset($_SESSION['user_id'])) {
    echo "<p style='color: red;'>❌ Nicht eingeloggt! Werde zur index.php weitergeleitet...</p>";
    echo "<p><a href='index.php'>Zur Login-Seite</a></p>";
    echo "<p><a href='auth/login.php'>Direkter Login</a></p>";
    // Nicht weiterleiten für Debug
    // header('Location: index.php');
    // exit();
} else {
    echo "<p style='color: green;'>✅ Eingeloggt! User ID: " . $_SESSION['user_id'] . "</p>";
}

echo "<h2>Session-Inhalt:</h2>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";
?>
