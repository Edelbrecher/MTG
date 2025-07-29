<?php
session_start();

// Set test session for user ID 1 (admin)
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'admin';
$_SESSION['is_admin'] = 1;

echo "<p>Session gesetzt für User ID 1 (admin)</p>";
echo "<p><a href='decks.php'>Zu den Decks</a></p>";
echo "<p><a href='collection.php'>Zur Collection</a></p>";
echo "<p><a href='dashboard.php'>Zum Dashboard</a></p>";
?>
