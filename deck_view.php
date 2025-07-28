<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$deck_id = intval($_GET['id'] ?? 0);

// Verify deck ownership
$stmt = $pdo->prepare("SELECT * FROM decks WHERE id = ? AND user_id = ?");
$stmt->execute([$deck_id, $_SESSION['user_id']]);
$deck = $stmt->fetch();

if (!$deck) {
    header('Location: decks.php');
    exit();
}

// Handle card removal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_card') {
    $card_name = $_POST['card_name'];
    $is_sideboard = intval($_POST['is_sideboard']);
    
    $stmt = $pdo->prepare("DELETE FROM deck_cards WHERE deck_id = ? AND card_name = ? AND is_sideboard = ?");
    $stmt->execute([$deck_id, $card_name, $is_sideboard]);
    $message = "Karte wurde aus dem Deck entfernt.";
}

// Get deck cards
$stmt = $pdo->prepare("
    SELECT dc.*, c.card_data 
    FROM deck_cards dc
    LEFT JOIN collections c ON dc.card_name = c.card_name AND c.user_id = ?
    WHERE dc.deck_id = ?
    ORDER BY dc.is_sideboard, dc.card_name
");
$stmt->execute([$_SESSION['user_id'], $deck_id]);
$deck_cards = $stmt->fetchAll();

$mainboard = array_filter($deck_cards, function($card) { return !$card['is_sideboard']; });
$sideboard = array_filter($deck_cards, function($card) { return $card['is_sideboard']; });

$mainboard_count = array_sum(array_column($mainboard, 'quantity'));
$sideboard_count = array_sum(array_column($sideboard, 'quantity'));

// Analyze deck
$analysis = analyzeDeck($mainboard);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($deck['name']); ?> - MTG Collection Manager</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="container">
        <div class="main-content">
            <div class="page-header">
                <h1 class="page-title"><?php echo htmlspecialchars($deck['name']); ?></h1>
                <p class="page-subtitle">
                    <?php echo htmlspecialchars($deck['format_type']); ?> • 
                    <?php echo $mainboard_count; ?> Karten (Hauptdeck) • 
                    <?php echo $sideboard_count; ?> Karten (Sideboard)
                </p>
            </div>

            <?php if (isset($message)): ?>
                <div class="card mb-3" style="background: var(--success-color); color: white;">
                    <div class="card-body"><?php echo htmlspecialchars($message); ?></div>
                </div>
            <?php endif; ?>

            <div class="grid grid-3 gap-4 mb-4">
                <!-- Deck Stats -->
                <div class="card">
                    <div class="card-header">
                        <h3>Deck-Statistiken</h3>
                    </div>
                    <div class="card-body">
                        <div class="mb-2">
                            <strong>Hauptdeck:</strong> <?php echo $mainboard_count; ?> Karten
                        </div>
                        <div class="mb-2">
                            <strong>Sideboard:</strong> <?php echo $sideboard_count; ?> Karten
                        </div>
                        <div class="mb-2">
                            <strong>Durchschnittliche Manakosten:</strong> 
                            <?php echo number_format($analysis['avg_cmc'], 1); ?>
                        </div>
                        <div>
                            <strong>Farben:</strong> 
                            <?php 
                            $color_names = ['W' => 'Weiß', 'U' => 'Blau', 'B' => 'Schwarz', 'R' => 'Rot', 'G' => 'Grün'];
                            $deck_colors = array_keys(array_filter($analysis['colors'], function($count) { return $count > 0; }));
                            echo !empty($deck_colors) ? implode(', ', array_map(function($c) use ($color_names) { 
                                return $color_names[$c] ?? $c; 
                            }, $deck_colors)) : 'Farblos';
                            ?>
                        </div>
                    </div>
                </div>

                <!-- Mana Curve -->
                <div class="card">
                    <div class="card-header">
                        <h3>Mana-Kurve</h3>
                    </div>
                    <div class="card-body">
                        <canvas id="manaCurveChart" width="300" height="200"></canvas>
                    </div>
                </div>

                <!-- Type Distribution -->
                <div class="card">
                    <div class="card-header">
                        <h3>Kartentypen</h3>
                    </div>
                    <div class="card-body">
                        <canvas id="typeChart" width="300" height="200"></canvas>
                    </div>
                </div>
            </div>

            <!-- Deck Lists -->
            <div class="grid grid-2 gap-4">
                <!-- Mainboard -->
                <div class="card">
                    <div class="card-header">
                        <h3>Hauptdeck (<?php echo $mainboard_count; ?>)</h3>
                    </div>
                    <div class="card-body">
                        <?php foreach ($mainboard as $card): ?>
                            <?php $card_data = json_decode($card['card_data'] ?? '{}', true); ?>
                            <div class="flex justify-between items-center mb-2 p-2" 
                                 style="border: 1px solid var(--border-color); border-radius: 8px;">
                                <div class="flex items-center gap-2">
                                    <span class="font-weight-bold"><?php echo $card['quantity']; ?>x</span>
                                    <span><?php echo htmlspecialchars($card['card_name']); ?></span>
                                    <?php if (isset($card_data['mana_cost'])): ?>
                                        <span class="text-muted"><?php echo renderManaCost($card_data['mana_cost']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="remove_card">
                                    <input type="hidden" name="card_name" value="<?php echo htmlspecialchars($card['card_name']); ?>">
                                    <input type="hidden" name="is_sideboard" value="0">
                                    <button type="submit" class="btn btn-danger" 
                                            style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">×</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Sideboard -->
                <div class="card">
                    <div class="card-header">
                        <h3>Sideboard (<?php echo $sideboard_count; ?>)</h3>
                    </div>
                    <div class="card-body">
                        <?php foreach ($sideboard as $card): ?>
                            <?php $card_data = json_decode($card['card_data'] ?? '{}', true); ?>
                            <div class="flex justify-between items-center mb-2 p-2" 
                                 style="border: 1px solid var(--border-color); border-radius: 8px;">
                                <div class="flex items-center gap-2">
                                    <span class="font-weight-bold"><?php echo $card['quantity']; ?>x</span>
                                    <span><?php echo htmlspecialchars($card['card_name']); ?></span>
                                    <?php if (isset($card_data['mana_cost'])): ?>
                                        <span class="text-muted"><?php echo renderManaCost($card_data['mana_cost']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="remove_card">
                                    <input type="hidden" name="card_name" value="<?php echo htmlspecialchars($card['card_name']); ?>">
                                    <input type="hidden" name="is_sideboard" value="1">
                                    <button type="submit" class="btn btn-danger" 
                                            style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">×</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                        
                        <?php if (empty($sideboard)): ?>
                            <p class="text-muted">Kein Sideboard vorhanden</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="mt-4">
                <a href="decks.php" class="btn btn-secondary">← Zurück zu Decks</a>
            </div>
        </div>
    </div>

    <script>
        // Mana Curve Chart
        const manaCurveCtx = document.getElementById('manaCurveChart').getContext('2d');
        new Chart(manaCurveCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_keys($analysis['mana_curve'])); ?>,
                datasets: [{
                    label: 'Anzahl Karten',
                    data: <?php echo json_encode(array_values($analysis['mana_curve'])); ?>,
                    backgroundColor: 'rgba(37, 99, 235, 0.6)',
                    borderColor: 'rgba(37, 99, 235, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        // Type Distribution Chart
        const typeCtx = document.getElementById('typeChart').getContext('2d');
        new Chart(typeCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_keys($analysis['types'])); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_values($analysis['types'])); ?>,
                    backgroundColor: [
                        'rgba(37, 99, 235, 0.8)',
                        'rgba(16, 185, 129, 0.8)',
                        'rgba(245, 158, 11, 0.8)',
                        'rgba(239, 68, 68, 0.8)',
                        'rgba(139, 92, 246, 0.8)',
                        'rgba(236, 72, 153, 0.8)'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    </script>

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

function analyzeDeck($cards) {
    $analysis = [
        'colors' => ['W' => 0, 'U' => 0, 'B' => 0, 'R' => 0, 'G' => 0],
        'mana_curve' => ['0' => 0, '1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0, '6+' => 0],
        'types' => [],
        'avg_cmc' => 0
    ];
    
    $total_cmc = 0;
    $total_cards = 0;
    
    foreach ($cards as $card) {
        $card_data = json_decode($card['card_data'] ?? '{}', true);
        $quantity = $card['quantity'];
        
        // Count colors
        if (isset($card_data['colors'])) {
            foreach ($card_data['colors'] as $color) {
                $analysis['colors'][$color] += $quantity;
            }
        }
        
        // Count mana curve
        $cmc = intval($card_data['cmc'] ?? 0);
        $cmc_key = $cmc >= 6 ? '6+' : strval($cmc);
        $analysis['mana_curve'][$cmc_key] += $quantity;
        
        $total_cmc += $cmc * $quantity;
        $total_cards += $quantity;
        
        // Count types
        $type_line = $card_data['type_line'] ?? '';
        $primary_type = 'Other';
        
        if (strpos($type_line, 'Land') !== false) $primary_type = 'Land';
        elseif (strpos($type_line, 'Creature') !== false) $primary_type = 'Creature';
        elseif (strpos($type_line, 'Instant') !== false) $primary_type = 'Instant';
        elseif (strpos($type_line, 'Sorcery') !== false) $primary_type = 'Sorcery';
        elseif (strpos($type_line, 'Enchantment') !== false) $primary_type = 'Enchantment';
        elseif (strpos($type_line, 'Artifact') !== false) $primary_type = 'Artifact';
        elseif (strpos($type_line, 'Planeswalker') !== false) $primary_type = 'Planeswalker';
        
        $analysis['types'][$primary_type] = ($analysis['types'][$primary_type] ?? 0) + $quantity;
    }
    
    $analysis['avg_cmc'] = $total_cards > 0 ? $total_cmc / $total_cards : 0;
    
    return $analysis;
}
?>
