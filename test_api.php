<?php
session_start();

// Function to fetch card data from Scryfall API
function fetchCardDataFromAPI($card_name) {
    $base_url = 'https://api.scryfall.com/cards/named';
    $url = $base_url . '?fuzzy=' . urlencode($card_name);
    
    echo "<p>Testing API call to: <code>$url</code></p>";
    
    // Check if allow_url_fopen is enabled
    if (!ini_get('allow_url_fopen')) {
        echo "<p style='color: red;'>ERROR: allow_url_fopen is disabled</p>";
        return ['success' => false, 'error' => 'allow_url_fopen is disabled'];
    }
    
    echo "<p style='color: green;'>allow_url_fopen is enabled</p>";
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'user_agent' => 'MTG Collection Manager/1.0',
            'method' => 'GET'
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        $error = error_get_last();
        echo "<p style='color: red;'>API request failed: " . print_r($error, true) . "</p>";
        return ['success' => false, 'error' => 'API request failed'];
    }
    
    echo "<p style='color: green;'>API response received (" . strlen($response) . " bytes)</p>";
    
    $data = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "<p style='color: red;'>JSON decode error: " . json_last_error_msg() . "</p>";
        return ['success' => false, 'error' => 'JSON decode error'];
    }
    
    if (isset($data['object']) && $data['object'] === 'error') {
        echo "<p style='color: orange;'>Scryfall API error: " . ($data['details'] ?? 'Card not found') . "</p>";
        return ['success' => false, 'error' => $data['details'] ?? 'Card not found'];
    }
    
    if (isset($data['name'])) {
        echo "<p style='color: green;'>Card found: <strong>" . $data['name'] . "</strong></p>";
        return ['success' => true, 'data' => $data];
    }
    
    echo "<p style='color: red;'>Invalid API response</p>";
    return ['success' => false, 'error' => 'Invalid response from API'];
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>API Test</title>
</head>
<body>
    <h1>Scryfall API Test</h1>
    
    <?php if ($_POST && isset($_POST['card_name'])): ?>
        <h2>Testing card: <?= htmlspecialchars($_POST['card_name']) ?></h2>
        <?php
        $result = fetchCardDataFromAPI($_POST['card_name']);
        echo "<pre>" . print_r($result, true) . "</pre>";
        ?>
    <?php endif; ?>
    
    <form method="post">
        <input type="text" name="card_name" placeholder="Enter card name" value="Lightning Bolt">
        <button type="submit">Test API</button>
    </form>
</body>
</html>
