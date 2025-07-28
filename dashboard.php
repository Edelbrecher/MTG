<?php
// Error reporting für Debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

try {
    require_once 'config/database.php';
} catch (Exception $e) {
    die("Datenbankfehler: " . $e->getMessage());
}

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Get user statistics mit Fehlerbehandlung
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_cards FROM collections WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $total_cards = $stmt->fetchColumn() ?: 0;
} catch (Exception $e) {
    $total_cards = 0;
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_decks FROM decks WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $total_decks = $stmt->fetchColumn() ?: 0;
} catch (Exception $e) {
    $total_decks = 0;
}

try {
    $stmt = $pdo->prepare("SELECT SUM(quantity) as total_quantity FROM collections WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $total_quantity = $stmt->fetchColumn() ?: 0;
} catch (Exception $e) {
    $total_quantity = 0;
}

// Get recent cards
try {
    $stmt = $pdo->prepare("
        SELECT card_name, card_data, quantity, added_at 
        FROM collections 
        WHERE user_id = ? 
        ORDER BY added_at DESC 
        LIMIT 6
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $recent_cards = $stmt->fetchAll();
} catch (Exception $e) {
    $recent_cards = [];
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - MTG Collection Manager</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="container">
        <div class="main-content">
            <div class="page-header">
                <h1 class="page-title">Dashboard</h1>
                <p class="page-subtitle">Willkommen zurück, <?php echo htmlspecialchars($_SESSION['username']); ?>!</p>
            </div>

            <!-- Statistics Cards -->
            <div class="grid grid-3 mb-4">
                <div class="card">
                    <div class="card-body text-center">
                        <h3 style="color: var(--primary-color); font-size: 2rem; margin-bottom: 0.5rem;"><?php echo $total_cards; ?></h3>
                        <p>Verschiedene Karten</p>
                    </div>
                </div>
                <div class="card">
                    <div class="card-body text-center">
                        <h3 style="color: var(--success-color); font-size: 2rem; margin-bottom: 0.5rem;"><?php echo $total_quantity; ?></h3>
                        <p>Karten insgesamt</p>
                    </div>
                </div>
                <div class="card">
                    <div class="card-body text-center">
                        <h3 style="color: var(--warning-color); font-size: 2rem; margin-bottom: 0.5rem;"><?php echo $total_decks; ?></h3>
                        <p>Decks erstellt</p>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card mb-4">
                <div class="card-header">
                    <h3>Schnellaktionen</h3>
                </div>
                <div class="card-body">
                    <div class="flex gap-4">
                        <a href="collection.php" class="btn btn-primary">Sammlung verwalten</a>
                        <a href="decks.php" class="btn btn-secondary">Decks erstellen</a>
                        <a href="settings.php" class="btn btn-secondary">Einstellungen</a>
                    </div>
                </div>
            </div>

            <!-- Recent Cards -->
            <?php if (!empty($recent_cards)): ?>
            <div class="card">
                <div class="card-header">
                    <h3>Zuletzt hinzugefügte Karten</h3>
                </div>
                <div class="card-body">
                    <div class="cards-grid">
                        <?php foreach ($recent_cards as $card): ?>
                            <?php
                            $card_data = json_decode($card['card_data'], true);
                            $colors = $card_data['colors'] ?? [];
                            $border_class = empty($colors) ? 'colorless' : (count($colors) > 1 ? 'multicolor' : strtolower($colors[0]));
                            ?>
                            <div class="mtg-card">
                                <div class="mtg-card-border <?php echo $border_class; ?>"></div>
                                <img src="<?php echo htmlspecialchars($card_data['image_url'] ?? 'assets/images/card-back.jpg'); ?>" 
                                     alt="<?php echo htmlspecialchars($card['card_name']); ?>" 
                                     class="mtg-card-image">
                                <div class="mtg-card-content">
                                    <div class="mtg-card-name"><?php echo htmlspecialchars($card['card_name']); ?></div>
                                    <div class="mtg-card-cost">
                                        <?php if (isset($card_data['mana_cost'])): ?>
                                            <?php echo renderManaCost($card_data['mana_cost']); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mtg-card-type"><?php echo htmlspecialchars($card_data['type_line'] ?? ''); ?></div>
                                    <div class="text-muted" style="font-size: 0.75rem;">Anzahl: <?php echo $card['quantity']; ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php include 'includes/footer.php'; ?>
</body>
</html>

<?php
function renderManaCost($mana_cost) {
    $symbols = str_split(str_replace(['{', '}'], ['', ''], $mana_cost));
    $html = '';
    foreach ($symbols as $symbol) {
        $class = 'mana-c';
        switch (strtolower($symbol)) {
            case 'w': $class = 'mana-w'; break;
            case 'u': $class = 'mana-u'; break;
            case 'b': $class = 'mana-b'; break;
            case 'r': $class = 'mana-r'; break;
            case 'g': $class = 'mana-g'; break;
        }
        $html .= "<span class='mana-symbol $class'>" . strtoupper($symbol) . "</span>";
    }
    return $html;
}
?>
