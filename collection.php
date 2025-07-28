<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Handle card search and addition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_card') {
        $card_name = trim($_POST['card_name']);
        $quantity = intval($_POST['quantity']) ?: 1;
        
        if (!empty($card_name)) {
            // Fetch card data from Scryfall API
            $card_data = fetchCardData($card_name);
            if ($card_data) {
                // Check if card already exists in collection
                $stmt = $pdo->prepare("SELECT id, quantity FROM collections WHERE user_id = ? AND card_name = ?");
                $stmt->execute([$_SESSION['user_id'], $card_name]);
                $existing = $stmt->fetch();
                
                if ($existing) {
                    // Update quantity
                    $new_quantity = $existing['quantity'] + $quantity;
                    $stmt = $pdo->prepare("UPDATE collections SET quantity = ? WHERE id = ?");
                    $stmt->execute([$new_quantity, $existing['id']]);
                } else {
                    // Add new card
                    $stmt = $pdo->prepare("INSERT INTO collections (user_id, card_name, card_data, quantity) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$_SESSION['user_id'], $card_name, json_encode($card_data), $quantity]);
                }
                
                $message = "Karte '{$card_name}' wurde zur Sammlung hinzugefügt!";
            } else {
                $error = "Karte '{$card_name}' konnte nicht gefunden werden.";
            }
        }
    } elseif ($_POST['action'] === 'delete_card') {
        $card_id = intval($_POST['card_id']);
        $stmt = $pdo->prepare("DELETE FROM collections WHERE id = ? AND user_id = ?");
        $stmt->execute([$card_id, $_SESSION['user_id']]);
        $message = "Karte wurde aus der Sammlung entfernt.";
    } elseif ($_POST['action'] === 'update_quantity') {
        $card_id = intval($_POST['card_id']);
        $quantity = intval($_POST['quantity']);
        if ($quantity > 0) {
            $stmt = $pdo->prepare("UPDATE collections SET quantity = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$quantity, $card_id, $_SESSION['user_id']]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM collections WHERE id = ? AND user_id = ?");
            $stmt->execute([$card_id, $_SESSION['user_id']]);
        }
        $message = "Kartenanzahl wurde aktualisiert.";
    }
}

// Get filters
$color_filter = $_GET['color'] ?? '';
$type_filter = $_GET['type'] ?? '';
$search_filter = $_GET['search'] ?? '';

// Build query with filters
$where_conditions = ["user_id = ?"];
$params = [$_SESSION['user_id']];

if (!empty($color_filter)) {
    $where_conditions[] = "JSON_CONTAINS(card_data, ?, '$.colors')";
    $params[] = json_encode($color_filter);
}

if (!empty($type_filter)) {
    $where_conditions[] = "JSON_EXTRACT(card_data, '$.type_line') LIKE ?";
    $params[] = "%{$type_filter}%";
}

if (!empty($search_filter)) {
    $where_conditions[] = "card_name LIKE ?";
    $params[] = "%{$search_filter}%";
}

$where_clause = implode(' AND ', $where_conditions);

// Get collection
$stmt = $pdo->prepare("
    SELECT id, card_name, card_data, quantity, added_at 
    FROM collections 
    WHERE {$where_clause}
    ORDER BY card_name ASC
");
$stmt->execute($params);
$collection = $stmt->fetchAll();

function fetchCardData($card_name) {
    $url = "https://api.scryfall.com/cards/named?exact=" . urlencode($card_name);
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'user_agent' => 'MTG Collection Manager/1.0'
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        return null;
    }
    
    $data = json_decode($response, true);
    
    if (!$data || isset($data['object']) && $data['object'] === 'error') {
        return null;
    }
    
    // Extract relevant data
    return [
        'name' => $data['name'] ?? '',
        'mana_cost' => $data['mana_cost'] ?? '',
        'cmc' => $data['cmc'] ?? 0,
        'type_line' => $data['type_line'] ?? '',
        'oracle_text' => $data['oracle_text'] ?? '',
        'colors' => $data['colors'] ?? [],
        'color_identity' => $data['color_identity'] ?? [],
        'power' => $data['power'] ?? null,
        'toughness' => $data['toughness'] ?? null,
        'rarity' => $data['rarity'] ?? '',
        'set_name' => $data['set_name'] ?? '',
        'set' => $data['set'] ?? '',
        'image_url' => $data['image_uris']['normal'] ?? ''
    ];
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sammlung - MTG Collection Manager</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="container">
        <div class="main-content">
            <div class="page-header">
                <h1 class="page-title">Meine Sammlung</h1>
                <p class="page-subtitle">Verwalten Sie Ihre Magic: The Gathering Kartensammlung</p>
            </div>

            <?php if (isset($message)): ?>
                <div class="card mb-3" style="background: var(--success-color); color: white;">
                    <div class="card-body"><?php echo htmlspecialchars($message); ?></div>
                </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="card mb-3" style="background: var(--danger-color); color: white;">
                    <div class="card-body"><?php echo htmlspecialchars($error); ?></div>
                </div>
            <?php endif; ?>

            <!-- Add Card Form -->
            <div class="card mb-4">
                <div class="card-header">
                    <h3>Karte hinzufügen</h3>
                </div>
                <div class="card-body">
                    <form method="POST" class="flex gap-4 items-center">
                        <input type="hidden" name="action" value="add_card">
                        <div class="form-group" style="flex: 1; margin-bottom: 0;">
                            <input type="text" name="card_name" placeholder="Kartenname (exakt)" required 
                                   style="width: 100%;">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <input type="number" name="quantity" value="1" min="1" max="100" 
                                   style="width: 80px;">
                        </div>
                        <button type="submit" class="btn btn-primary">Hinzufügen</button>
                    </form>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters">
                <form method="GET" class="filter-group">
                    <div class="filter-item">
                        <label>Suchbegriff</label>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search_filter); ?>" 
                               placeholder="Kartenname suchen...">
                    </div>
                    <div class="filter-item">
                        <label>Farbe</label>
                        <select name="color">
                            <option value="">Alle Farben</option>
                            <option value="W" <?php echo $color_filter === 'W' ? 'selected' : ''; ?>>Weiß</option>
                            <option value="U" <?php echo $color_filter === 'U' ? 'selected' : ''; ?>>Blau</option>
                            <option value="B" <?php echo $color_filter === 'B' ? 'selected' : ''; ?>>Schwarz</option>
                            <option value="R" <?php echo $color_filter === 'R' ? 'selected' : ''; ?>>Rot</option>
                            <option value="G" <?php echo $color_filter === 'G' ? 'selected' : ''; ?>>Grün</option>
                        </select>
                    </div>
                    <div class="filter-item">
                        <label>Typ</label>
                        <select name="type">
                            <option value="">Alle Typen</option>
                            <option value="Creature" <?php echo $type_filter === 'Creature' ? 'selected' : ''; ?>>Kreatur</option>
                            <option value="Instant" <?php echo $type_filter === 'Instant' ? 'selected' : ''; ?>>Spontanzauber</option>
                            <option value="Sorcery" <?php echo $type_filter === 'Sorcery' ? 'selected' : ''; ?>>Hexerei</option>
                            <option value="Enchantment" <?php echo $type_filter === 'Enchantment' ? 'selected' : ''; ?>>Verzauberung</option>
                            <option value="Artifact" <?php echo $type_filter === 'Artifact' ? 'selected' : ''; ?>>Artefakt</option>
                            <option value="Planeswalker" <?php echo $type_filter === 'Planeswalker' ? 'selected' : ''; ?>>Planeswalker</option>
                            <option value="Land" <?php echo $type_filter === 'Land' ? 'selected' : ''; ?>>Land</option>
                        </select>
                    </div>
                    <div class="filter-item" style="display: flex; align-items: end;">
                        <button type="submit" class="btn btn-secondary">Filtern</button>
                    </div>
                </form>
            </div>

            <!-- Collection Grid -->
            <div class="cards-grid">
                <?php foreach ($collection as $card): ?>
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
                            <?php if (isset($card_data['power'], $card_data['toughness'])): ?>
                                <div class="text-muted" style="font-size: 0.75rem;">
                                    <?php echo $card_data['power']; ?>/<?php echo $card_data['toughness']; ?>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Quantity Controls -->
                            <div class="flex items-center justify-between mt-2" style="gap: 0.5rem;">
                                <form method="POST" style="display: flex; align-items: center; gap: 0.25rem;">
                                    <input type="hidden" name="action" value="update_quantity">
                                    <input type="hidden" name="card_id" value="<?php echo $card['id']; ?>">
                                    <input type="number" name="quantity" value="<?php echo $card['quantity']; ?>" 
                                           min="0" max="100" style="width: 60px; padding: 0.25rem; font-size: 0.75rem;">
                                    <button type="submit" class="btn btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">✓</button>
                                </form>
                                
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="delete_card">
                                    <input type="hidden" name="card_id" value="<?php echo $card['id']; ?>">
                                    <button type="submit" class="btn btn-danger" 
                                            style="padding: 0.25rem 0.5rem; font-size: 0.75rem;"
                                            onclick="return confirm('Karte wirklich löschen?')">🗑</button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (empty($collection)): ?>
                <div class="card text-center">
                    <div class="card-body">
                        <h3>Keine Karten gefunden</h3>
                        <p>Fügen Sie Ihre erste Karte zur Sammlung hinzu!</p>
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
    if (empty($mana_cost)) return '';
    
    preg_match_all('/\{([^}]+)\}/', $mana_cost, $matches);
    $html = '';
    
    foreach ($matches[1] as $symbol) {
        $class = 'mana-c';
        $display = $symbol;
        
        switch (strtoupper($symbol)) {
            case 'W': $class = 'mana-w'; break;
            case 'U': $class = 'mana-u'; break;
            case 'B': $class = 'mana-b'; break;
            case 'R': $class = 'mana-r'; break;
            case 'G': $class = 'mana-g'; break;
            default:
                if (is_numeric($symbol)) {
                    $class = 'mana-c';
                    $display = $symbol;
                }
                break;
        }
        
        $html .= "<span class='mana-symbol $class'>" . htmlspecialchars($display) . "</span>";
    }
    
    return $html;
}
?>
