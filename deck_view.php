<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$deck_id = $_GET['id'] ?? '';

// Get deck info
$stmt = $pdo->prepare("SELECT * FROM decks WHERE id = ? AND user_id = ?");
$stmt->execute([$deck_id, $_SESSION['user_id']]);
$deck = $stmt->fetch();

if (!$deck) {
    header('Location: decks.php?error=deck_not_found');
    exit();
}

// Handle card removal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_card') {
    $card_name = $_POST['card_name'];
    $is_sideboard = intval($_POST['is_sideboard']);
    
    $stmt = $pdo->prepare("DELETE FROM deck_cards WHERE deck_id = ? AND card_name = ? AND is_sideboard = ?");
    $stmt->execute([$deck_id, $card_name, $is_sideboard]);
    $success_message = "Karte wurde aus dem Deck entfernt.";
}

// Get deck cards with collection data
$stmt = $pdo->prepare("
    SELECT dc.*, c.card_data 
    FROM deck_cards dc
    LEFT JOIN collections c ON dc.card_name = c.card_name AND c.user_id = ?
    WHERE dc.deck_id = ?
    ORDER BY dc.is_sideboard, dc.card_name
");
$stmt->execute([$_SESSION['user_id'], $deck_id]);
$deck_cards = $stmt->fetchAll();

// Separate mainboard and sideboard
$mainboard = array_filter($deck_cards, function($card) { return !$card['is_sideboard']; });
$sideboard = array_filter($deck_cards, function($card) { return $card['is_sideboard']; });

// Calculate statistics
$mainboard_count = array_sum(array_column($mainboard, 'quantity'));
$sideboard_count = array_sum(array_column($sideboard, 'quantity'));

// Helper functions
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

// Advanced deck analysis
function analyzeDeck($cards) {
    $analysis = [
        'mana_curve' => [],
        'color_distribution' => [],
        'type_distribution' => [],
        'average_cmc' => 0,
        'total_lands' => 0,
        'total_creatures' => 0,
        'total_spells' => 0,
        'rarity_distribution' => [],
        'strengths' => [],
        'weaknesses' => [],
        'competitiveness_score' => 0
    ];
    
    $total_cmc = 0;
    $total_cards = 0;
    
    foreach ($cards as $card) {
        if (!$card['card_data']) continue;
        
        $card_data = json_decode($card['card_data'], true);
        $quantity = intval($card['quantity']);
        $cmc = intval($card_data['cmc'] ?? 0);
        $type_line = strtolower($card_data['type_line'] ?? '');
        $colors = $card_data['colors'] ?? [];
        $rarity = $card_data['rarity'] ?? 'common';
        
        // Mana curve
        $cmc_bracket = $cmc >= 7 ? '7+' : (string)$cmc;
        $analysis['mana_curve'][$cmc_bracket] = ($analysis['mana_curve'][$cmc_bracket] ?? 0) + $quantity;
        
        // Color distribution
        foreach ($colors as $color) {
            $analysis['color_distribution'][$color] = ($analysis['color_distribution'][$color] ?? 0) + $quantity;
        }
        if (empty($colors)) {
            $analysis['color_distribution']['Colorless'] = ($analysis['color_distribution']['Colorless'] ?? 0) + $quantity;
        }
        
        // Type distribution
        if (strpos($type_line, 'land') !== false) {
            $analysis['total_lands'] += $quantity;
            $analysis['type_distribution']['Lands'] = ($analysis['type_distribution']['Lands'] ?? 0) + $quantity;
        } elseif (strpos($type_line, 'creature') !== false) {
            $analysis['total_creatures'] += $quantity;
            $analysis['type_distribution']['Creatures'] = ($analysis['type_distribution']['Creatures'] ?? 0) + $quantity;
        } else {
            $analysis['total_spells'] += $quantity;
            // Detailed spell types
            if (strpos($type_line, 'instant') !== false) {
                $analysis['type_distribution']['Instants'] = ($analysis['type_distribution']['Instants'] ?? 0) + $quantity;
            } elseif (strpos($type_line, 'sorcery') !== false) {
                $analysis['type_distribution']['Sorceries'] = ($analysis['type_distribution']['Sorceries'] ?? 0) + $quantity;
            } elseif (strpos($type_line, 'enchantment') !== false) {
                $analysis['type_distribution']['Enchantments'] = ($analysis['type_distribution']['Enchantments'] ?? 0) + $quantity;
            } elseif (strpos($type_line, 'artifact') !== false) {
                $analysis['type_distribution']['Artifacts'] = ($analysis['type_distribution']['Artifacts'] ?? 0) + $quantity;
            } elseif (strpos($type_line, 'planeswalker') !== false) {
                $analysis['type_distribution']['Planeswalkers'] = ($analysis['type_distribution']['Planeswalkers'] ?? 0) + $quantity;
            } else {
                $analysis['type_distribution']['Other Spells'] = ($analysis['type_distribution']['Other Spells'] ?? 0) + $quantity;
            }
        }
        
        // Rarity distribution
        $analysis['rarity_distribution'][$rarity] = ($analysis['rarity_distribution'][$rarity] ?? 0) + $quantity;
        
        $total_cmc += $cmc * $quantity;
        $total_cards += $quantity;
    }
    
    // Calculate average CMC
    $analysis['average_cmc'] = $total_cards > 0 ? round($total_cmc / $total_cards, 2) : 0;
    
    // Analyze strengths and weaknesses
    analyzeStrengthsWeaknesses($analysis);
    
    // Calculate competitiveness score
    $analysis['competitiveness_score'] = calculateCompetitivenessScore($analysis);
    
    return $analysis;
}

function analyzeStrengthsWeaknesses(&$analysis) {
    $strengths = [];
    $weaknesses = [];
    
    // Mana curve analysis
    $total_cards = array_sum($analysis['mana_curve']);
    if ($total_cards > 0) {
        $low_cmc = ($analysis['mana_curve']['0'] ?? 0) + ($analysis['mana_curve']['1'] ?? 0) + ($analysis['mana_curve']['2'] ?? 0);
        $low_percentage = ($low_cmc / $total_cards) * 100;
        
        if ($low_percentage >= 40) {
            $strengths[] = "Gute frühe Spielphase (viele günstige Zauber)";
        } elseif ($low_percentage < 20) {
            $weaknesses[] = "Schwache frühe Spielphase (zu wenig günstige Zauber)";
        }
        
        if ($analysis['average_cmc'] <= 3.0) {
            $strengths[] = "Effiziente Mana-Kurve (durchschnittlich " . $analysis['average_cmc'] . " CMC)";
        } elseif ($analysis['average_cmc'] >= 4.5) {
            $weaknesses[] = "Hohe Mana-Kosten (durchschnittlich " . $analysis['average_cmc'] . " CMC)";
        }
    }
    
    // Land ratio analysis
    $total_mainboard = $analysis['total_lands'] + $analysis['total_creatures'] + $analysis['total_spells'];
    if ($total_mainboard > 0) {
        $land_percentage = ($analysis['total_lands'] / $total_mainboard) * 100;
        
        if ($land_percentage >= 35 && $land_percentage <= 45) {
            $strengths[] = "Optimale Land-Verteilung (" . round($land_percentage, 1) . "%)";
        } elseif ($land_percentage < 30) {
            $weaknesses[] = "Zu wenig Länder (" . round($land_percentage, 1) . "% - Mana-Probleme möglich)";
        } elseif ($land_percentage > 50) {
            $weaknesses[] = "Zu viele Länder (" . round($land_percentage, 1) . "% - weniger Spielzauber)";
        }
    }
    
    // Color distribution analysis
    $color_count = count(array_filter($analysis['color_distribution'], function($count, $color) {
        return $color !== 'Colorless' && $count > 0;
    }, ARRAY_FILTER_USE_BOTH));
    
    if ($color_count <= 2) {
        $strengths[] = "Stabile Mana-Basis (" . ($color_count == 1 ? "Mono-Color" : "Zwei-Farben") . ")";
    } elseif ($color_count >= 4) {
        $weaknesses[] = "Komplexe Mana-Basis (" . $color_count . " Farben - Mana-Fixing nötig)";
    }
    
    // Rarity analysis (power level indicator)
    $rare_mythic = ($analysis['rarity_distribution']['rare'] ?? 0) + ($analysis['rarity_distribution']['mythic'] ?? 0);
    $total_rarity = array_sum($analysis['rarity_distribution']);
    if ($total_rarity > 0 && $rare_mythic >= $total_rarity * 0.3) {
        $strengths[] = "Hochwertige Kartenbasis (viele Rare/Mythic Rare)";
    }
    
    $analysis['strengths'] = $strengths;
    $analysis['weaknesses'] = $weaknesses;
}

function calculateCompetitivenessScore($analysis) {
    $score = 50; // Base score
    
    // Mana curve bonus/penalty
    if ($analysis['average_cmc'] >= 2.5 && $analysis['average_cmc'] <= 3.5) {
        $score += 15; // Optimal CMC range
    } elseif ($analysis['average_cmc'] < 2.0 || $analysis['average_cmc'] > 5.0) {
        $score -= 10; // Suboptimal CMC
    }
    
    // Land ratio
    $total_cards = $analysis['total_lands'] + $analysis['total_creatures'] + $analysis['total_spells'];
    if ($total_cards > 0) {
        $land_ratio = $analysis['total_lands'] / $total_cards;
        if ($land_ratio >= 0.35 && $land_ratio <= 0.45) {
            $score += 10;
        } elseif ($land_ratio < 0.25 || $land_ratio > 0.55) {
            $score -= 15;
        }
    }
    
    // Color complexity
    $colors = count(array_filter($analysis['color_distribution'], function($count, $color) {
        return $color !== 'Colorless' && $count > 0;
    }, ARRAY_FILTER_USE_BOTH));
    
    if ($colors <= 2) {
        $score += 10; // Stable mana base
    } elseif ($colors >= 4) {
        $score -= 15; // Complex mana base
    }
    
    // Rare/Mythic bonus
    $rare_mythic = ($analysis['rarity_distribution']['rare'] ?? 0) + ($analysis['rarity_distribution']['mythic'] ?? 0);
    $score += min(20, $rare_mythic * 2); // Up to 20 points for powerful cards
    
    return max(0, min(100, $score));
}

$analysis = analyzeDeck($mainboard);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($deck['name']); ?> - MTG Collection</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .analysis-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            margin: 1rem 0;
        }
        
        .competitiveness-score {
            text-align: center;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
        }
        
        .score-excellent { background: linear-gradient(135deg, #10b981, #059669); color: white; }
        .score-good { background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; }
        .score-average { background: linear-gradient(135deg, #f59e0b, #d97706); color: white; }
        .score-poor { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; }
        
        .strength-weakness-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin: 1rem 0;
        }
        
        .strength-item, .weakness-item {
            padding: 0.5rem;
            border-radius: 8px;
            margin-bottom: 0.5rem;
        }
        
        .strength-item {
            background: rgba(16, 185, 129, 0.1);
            border-left: 4px solid #10b981;
        }
        
        .weakness-item {
            background: rgba(239, 68, 68, 0.1);
            border-left: 4px solid #ef4444;
        }
        
        .mana-symbol {
            display: inline-block;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            text-align: center;
            line-height: 16px;
            font-size: 10px;
            font-weight: bold;
            color: white;
            margin: 0 1px;
        }
        
        .mana-w { background: #fffbd5; color: #8b7355; }
        .mana-u { background: #0e68ab; }
        .mana-b { background: #150b00; }
        .mana-r { background: #d3202a; }
        .mana-g { background: #00733e; }
        .mana-c { background: #ccc2c0; color: #333; }
        
        .deck-stats-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
        }
        
        .stat-box {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
            text-align: center;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            color: var(--primary-color);
        }
        
        .stat-label {
            color: var(--text-muted);
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="container">
        <div class="content">
            <div class="page-header">
                <h1 class="page-title"><?php echo htmlspecialchars($deck['name']); ?></h1>
                <p class="page-subtitle">
                    <?php echo htmlspecialchars($deck['format_type']); ?> • 
                    Erstellt am <?php echo date('d.m.Y', strtotime($deck['created_at'])); ?>
                    <?php if ($deck['strategy']): ?>
                        • Strategie: <?php echo htmlspecialchars($deck['strategy']); ?>
                    <?php endif; ?>
                </p>
            </div>

            <?php if (isset($success_message)): ?>
                <div class="card mb-3" style="background: var(--success-color); color: white;">
                    <div class="card-body"><?php echo htmlspecialchars($success_message); ?></div>
                </div>
            <?php endif; ?>

            <!-- Competitiveness Score -->
            <?php
            $score = $analysis['competitiveness_score'];
            $score_class = '';
            $score_text = '';
            if ($score >= 80) {
                $score_class = 'score-excellent';
                $score_text = 'Ausgezeichnet - Wettbewerbsfähig';
            } elseif ($score >= 65) {
                $score_class = 'score-good';
                $score_text = 'Gut - Starkes Deck';
            } elseif ($score >= 50) {
                $score_class = 'score-average';
                $score_text = 'Durchschnittlich - Verbesserbar';
            } else {
                $score_class = 'score-poor';
                $score_text = 'Schwach - Überarbeitung nötig';
            }
            ?>
            <div class="competitiveness-score <?php echo $score_class; ?>">
                <div class="stat-value"><?php echo $score; ?>/100</div>
                <div class="stat-label"><?php echo $score_text; ?></div>
            </div>

            <!-- Quick Stats -->
            <div class="deck-stats-overview">
                <div class="stat-box">
                    <div class="stat-value"><?php echo $mainboard_count; ?></div>
                    <div class="stat-label">Hauptdeck</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?php echo $sideboard_count; ?></div>
                    <div class="stat-label">Sideboard</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?php echo $analysis['average_cmc']; ?></div>
                    <div class="stat-label">Durchschn. CMC</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?php echo count(array_filter($analysis['color_distribution'], function($count, $color) {
                        return $color !== 'Colorless' && $count > 0;
                    }, ARRAY_FILTER_USE_BOTH)); ?></div>
                    <div class="stat-label">Farben</div>
                </div>
            </div>

            <!-- Analysis Charts -->
            <div class="analysis-grid">
                <!-- Mana Curve -->
                <div class="card">
                    <div class="card-header">
                        <h3>Mana-Kurve</h3>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="manaCurveChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Type Distribution -->
                <div class="card">
                    <div class="card-header">
                        <h3>Kartentypen</h3>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="typeChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Color Distribution -->
            <div class="card mb-4">
                <div class="card-header">
                    <h3>Farbverteilung</h3>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="colorChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Strengths and Weaknesses -->
            <div class="card mb-4">
                <div class="card-header">
                    <h3>Deck-Analyse</h3>
                </div>
                <div class="card-body">
                    <div class="strength-weakness-grid">
                        <div>
                            <h4 style="color: #10b981; margin-bottom: 1rem;">Stärken</h4>
                            <?php if (!empty($analysis['strengths'])): ?>
                                <?php foreach ($analysis['strengths'] as $strength): ?>
                                    <div class="strength-item"><?php echo htmlspecialchars($strength); ?></div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted">Keine spezifischen Stärken identifiziert</p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <h4 style="color: #ef4444; margin-bottom: 1rem;">Schwächen</h4>
                            <?php if (!empty($analysis['weaknesses'])): ?>
                                <?php foreach ($analysis['weaknesses'] as $weakness): ?>
                                    <div class="weakness-item"><?php echo htmlspecialchars($weakness); ?></div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted">Keine spezifischen Schwächen identifiziert</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Deck Lists -->
            <div class="analysis-grid">
                <!-- Mainboard -->
                <div class="card">
                    <div class="card-header">
                        <h3>Hauptdeck (<?php echo $mainboard_count; ?>)</h3>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($mainboard)): ?>
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
                        <?php else: ?>
                            <p class="text-muted">Kein Hauptdeck vorhanden</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Sideboard -->
                <div class="card">
                    <div class="card-header">
                        <h3>Sideboard (<?php echo $sideboard_count; ?>)</h3>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($sideboard)): ?>
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
                        <?php else: ?>
                            <p class="text-muted">Kein Sideboard vorhanden</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="mt-4">
                <a href="decks.php" class="btn btn-secondary">← Zurück zu Decks</a>
                <a href="decks.php?action=edit&id=<?php echo $deck_id; ?>" class="btn btn-primary">Deck bearbeiten</a>
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
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });

        // Type Distribution Chart
        const typeCtx = document.getElementById('typeChart').getContext('2d');
        new Chart(typeCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_keys($analysis['type_distribution'])); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_values($analysis['type_distribution'])); ?>,
                    backgroundColor: [
                        '#10b981', // Lands - Green
                        '#3b82f6', // Creatures - Blue
                        '#f59e0b', // Instants - Amber
                        '#ef4444', // Sorceries - Red
                        '#8b5cf6', // Enchantments - Purple
                        '#6b7280', // Artifacts - Gray
                        '#ec4899', // Planeswalkers - Pink
                        '#14b8a6'  // Other - Teal
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // Color Distribution Chart
        const colorCtx = document.getElementById('colorChart').getContext('2d');
        const colorData = <?php echo json_encode($analysis['color_distribution']); ?>;
        const colorLabels = Object.keys(colorData);
        const colorValues = Object.values(colorData);
        
        new Chart(colorCtx, {
            type: 'bar',
            data: {
                labels: colorLabels.map(color => {
                    const colorNames = {
                        'W': 'Weiß',
                        'U': 'Blau', 
                        'B': 'Schwarz',
                        'R': 'Rot',
                        'G': 'Grün',
                        'Colorless': 'Farblos'
                    };
                    return colorNames[color] || color;
                }),
                datasets: [{
                    label: 'Anzahl Symbole',
                    data: colorValues,
                    backgroundColor: colorLabels.map(color => {
                        const colorMap = {
                            'W': '#fffbd5',
                            'U': '#0e68ab',
                            'B': '#150b00', 
                            'R': '#d3202a',
                            'G': '#00733e',
                            'Colorless': '#ccc2c0'
                        };
                        return colorMap[color] || '#6b7280';
                    }),
                    borderColor: colorLabels.map(color => {
                        const colorMap = {
                            'W': '#8b7355',
                            'U': '#0e68ab',
                            'B': '#150b00',
                            'R': '#d3202a', 
                            'G': '#00733e',
                            'Colorless': '#6b7280'
                        };
                        return colorMap[color] || '#374151';
                    }),
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });
    </script>

    <?php include 'includes/footer.php'; ?>
</body>
</html>
