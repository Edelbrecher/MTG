<?php
header('Content-Type: application/json');

if (!isset($_GET['q']) || empty(trim($_GET['q']))) {
    echo json_encode([]);
    exit();
}

$query = trim($_GET['q']);

// Use Scryfall API for card search
$base_url = 'https://api.scryfall.com/cards/autocomplete';
$url = $base_url . '?q=' . urlencode($query);

$context = stream_context_create([
    'http' => [
        'timeout' => 5,
        'user_agent' => 'MTG Collection Manager/1.0'
    ]
]);

$response = @file_get_contents($url, false, $context);

if ($response === false) {
    echo json_encode([]);
    exit();
}

$data = json_decode($response, true);

if (isset($data['data']) && is_array($data['data'])) {
    // Return the card names from Scryfall
    echo json_encode($data['data']);
} else {
    echo json_encode([]);
}
?>
