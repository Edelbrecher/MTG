<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit();
}

// Function to fetch comprehensive card data from Scryfall API
function fetchCardAnalysisData($card_name) {
    $base_url = 'https://api.scryfall.com/cards/named';
    $url = $base_url . '?fuzzy=' . urlencode($card_name);
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 15,
            'user_agent' => 'MTG Collection Manager/1.0',
            'method' => 'GET'
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        return ['success' => false, 'error' => 'API request failed'];
    }
    
    $data = json_decode($response, true);
    
    if (isset($data['object']) && $data['object'] === 'error') {
        return ['success' => false, 'error' => $data['details'] ?? 'Card not found'];
    }
    
    if (isset($data['name'])) {
        return ['success' => true, 'data' => $data];
    }
    
    return ['success' => false, 'error' => 'Invalid response from API'];
}

// Function to analyze card power level and playability
function analyzeCard($card_data) {
    $analysis = [
        'power_level' => 'Unknown',
        'commander_potential' => 'No',
        'format_playability' => [],
        'deck_archetype' => [],
        'strengths' => [],
        'weaknesses' => [],
        'overall_rating' => 'N/A'
    ];
    
    if (!$card_data) return $analysis;
    
    $card = $card_data['data'];
    $mana_cost = $card['mana_cost'] ?? '';
    $cmc = $card['cmc'] ?? 0;
    $type_line = strtolower($card['type_line'] ?? '');
    $oracle_text = strtolower($card['oracle_text'] ?? '');
    $power = $card['power'] ?? null;
    $toughness = $card['toughness'] ?? null;
    $colors = $card['colors'] ?? [];
    $color_identity = $card['color_identity'] ?? [];
    $legalities = $card['legalities'] ?? [];
    $edhrec_rank = $card['edhrec_rank'] ?? null;
    $rarity = $card['rarity'] ?? '';
    
    // Commander Potential Analysis
    if (strpos($type_line, 'legendary') !== false && strpos($type_line, 'creature') !== false) {
        $analysis['commander_potential'] = 'Excellent';
        $analysis['strengths'][] = 'Legendary Creature - Commander geeignet';
    } elseif (strpos($type_line, 'planeswalker') !== false && strpos($oracle_text, 'can be your commander') !== false) {
        $analysis['commander_potential'] = 'Excellent';
        $analysis['strengths'][] = 'Planeswalker Commander';
    } elseif (strpos($type_line, 'legendary') !== false) {
        $analysis['commander_potential'] = 'Limited';
        $analysis['strengths'][] = 'Legendary - spezielle Rollen möglich';
    }
    
    // Power Level Analysis based on various factors
    $power_score = 0;
    
    // EDHREC Rank analysis (lower is better)
    if ($edhrec_rank !== null) {
        if ($edhrec_rank < 1000) {
            $power_score += 4;
            $analysis['strengths'][] = 'Sehr populär in EDH (Rank: #' . $edhrec_rank . ')';
        } elseif ($edhrec_rank < 5000) {
            $power_score += 3;
            $analysis['strengths'][] = 'Populär in EDH (Rank: #' . $edhrec_rank . ')';
        } elseif ($edhrec_rank < 15000) {
            $power_score += 2;
        } else {
            $power_score += 1;
            $analysis['weaknesses'][] = 'Wenig gespielt in EDH';
        }
    }
    
    // Mana Cost Analysis
    if ($cmc <= 1) {
        $power_score += 3;
        $analysis['strengths'][] = 'Sehr niedrige Manakosten';
    } elseif ($cmc <= 3) {
        $power_score += 2;
        $analysis['strengths'][] = 'Niedrige Manakosten';
    } elseif ($cmc >= 7) {
        $power_score -= 1;
        $analysis['weaknesses'][] = 'Hohe Manakosten';
    }
    
    // Creature Analysis
    if (strpos($type_line, 'creature') !== false && $power !== null && $toughness !== null) {
        $power_num = intval($power);
        $toughness_num = intval($toughness);
        $total_stats = $power_num + $toughness_num;
        
        if ($total_stats >= $cmc + 3) {
            $power_score += 2;
            $analysis['strengths'][] = 'Exzellente Stat/Mana Ratio';
        } elseif ($total_stats >= $cmc) {
            $power_score += 1;
            $analysis['strengths'][] = 'Gute Stat/Mana Ratio';
        } else {
            $analysis['weaknesses'][] = 'Schwache Stats für Manakosten';
        }
    }
    
    // Keyword Analysis
    $powerful_keywords = ['flying', 'trample', 'haste', 'vigilance', 'hexproof', 'indestructible', 'flash', 'storm', 'cascade'];
    foreach ($powerful_keywords as $keyword) {
        if (strpos($oracle_text, $keyword) !== false) {
            $power_score += 1;
            $analysis['strengths'][] = 'Hat ' . ucfirst($keyword);
        }
    }
    
    // Rarity bonus
    if ($rarity === 'mythic') {
        $power_score += 2;
    } elseif ($rarity === 'rare') {
        $power_score += 1;
    }
    
    // Format Playability
    foreach ($legalities as $format => $status) {
        if ($status === 'legal') {
            $analysis['format_playability'][] = ucfirst($format);
        }
    }
    
    // Deck Archetype Analysis
    if (strpos($oracle_text, 'draw') !== false || strpos($oracle_text, 'card') !== false) {
        $analysis['deck_archetype'][] = 'Card Draw/Control';
    }
    if (strpos($oracle_text, 'damage') !== false || strpos($oracle_text, 'burn') !== false) {
        $analysis['deck_archetype'][] = 'Aggro/Burn';
    }
    if (strpos($oracle_text, 'graveyard') !== false || strpos($oracle_text, 'exile') !== false) {
        $analysis['deck_archetype'][] = 'Graveyard Value';
    }
    if (strpos($oracle_text, 'token') !== false || strpos($oracle_text, 'create') !== false) {
        $analysis['deck_archetype'][] = 'Token Strategy';
    }
    if (strpos($oracle_text, 'artifact') !== false) {
        $analysis['deck_archetype'][] = 'Artifact Synergy';
    }
    if (strpos($oracle_text, 'tribal') !== false || strpos($type_line, 'human') !== false || strpos($type_line, 'elf') !== false) {
        $analysis['deck_archetype'][] = 'Tribal/Synergy';
    }
    
    // Power Level Rating
    if ($power_score >= 8) {
        $analysis['power_level'] = 'Excellent';
        $analysis['overall_rating'] = '⭐⭐⭐⭐⭐';
    } elseif ($power_score >= 6) {
        $analysis['power_level'] = 'Very Good';
        $analysis['overall_rating'] = '⭐⭐⭐⭐';
    } elseif ($power_score >= 4) {
        $analysis['power_level'] = 'Good';
        $analysis['overall_rating'] = '⭐⭐⭐';
    } elseif ($power_score >= 2) {
        $analysis['power_level'] = 'Playable';
        $analysis['overall_rating'] = '⭐⭐';
    } else {
        $analysis['power_level'] = 'Weak';
        $analysis['overall_rating'] = '⭐';
        $analysis['weaknesses'][] = 'Generell schwache Karte';
    }
    
    return $analysis;
}

$card_data = null;
$analysis = null;
$success_message = '';
$error_message = '';

// Handle card analysis request
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'analyze_card') {
    $card_name = trim($_POST['card_name']);
    
    if (!empty($card_name)) {
        $result = fetchCardAnalysisData($card_name);
        
        if ($result['success']) {
            $card_data = $result;
            $analysis = analyzeCard($result);
            $success_message = "Analyse für '{$result['data']['name']}' abgeschlossen!";
        } else {
            $error_message = "Karte '{$card_name}' konnte nicht gefunden werden: " . $result['error'];
        }
    } else {
        $error_message = "Bitte geben Sie einen Kartennamen ein.";
    }
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rate-It - MTG Collection Manager</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="icon" href="favicon.ico" type="image/x-icon">
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="container">
        <div class="main-content">
            <div class="page-header">
                <h1>⭐ Rate-It - Karten-Analyse</h1>
                <p>Analysieren Sie Magic-Karten umfassend mit detaillierten Bewertungen und Empfehlungen.</p>
            </div>

            <?php if ($success_message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <!-- Card Search Form -->
            <div class="card-analysis-form" style="margin-bottom: 2rem;">
                <form method="post" style="max-width: 600px; margin: 0 auto;">
                    <input type="hidden" name="action" value="analyze_card">
                    
                    <div style="display: flex; gap: 1rem; align-items: end;">
                        <div style="flex: 1;">
                            <label for="card_name" style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--text-primary);">
                                🔍 Kartenname eingeben:
                            </label>
                            <div class="autocomplete-container">
                                <input type="text" name="card_name" id="cardAnalysisInput" 
                                       class="form-control" 
                                       placeholder="z.B. Lightning Bolt, Black Lotus, Sol Ring..." 
                                       autocomplete="off" required
                                       value="<?= htmlspecialchars($_POST['card_name'] ?? '') ?>">
                                <div class="autocomplete-suggestions" id="analysisAutocompleteSuggestions"></div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary" style="padding: 1rem 2rem; height: fit-content;">
                            ⚡ Analysieren
                        </button>
                    </div>
                </form>
            </div>

            <?php if ($card_data && $analysis): ?>
                <div class="analysis-results">
                    <div class="analysis-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2rem;">
                        
                        <!-- Card Information -->
                        <div class="card-info-panel">
                            <div class="panel">
                                <h2 style="margin-bottom: 1rem; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem;">
                                    🃏 Karten-Information
                                </h2>
                                
                                <div class="card-display" style="display: flex; gap: 1rem;">
                                    <?php if (isset($card_data['data']['image_uris']['normal'])): ?>
                                        <img src="<?= $card_data['data']['image_uris']['normal'] ?>" 
                                             alt="<?= htmlspecialchars($card_data['data']['name']) ?>"
                                             style="width: 200px; height: auto; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.2);">
                                    <?php endif; ?>
                                    
                                    <div class="card-details" style="flex: 1;">
                                        <h3 style="margin: 0 0 1rem 0; color: var(--primary-color);">
                                            <?= htmlspecialchars($card_data['data']['name']) ?>
                                        </h3>
                                        
                                        <div class="detail-grid" style="display: grid; gap: 0.5rem;">
                                            <div><strong>Manakosten:</strong> <?= htmlspecialchars($card_data['data']['mana_cost'] ?? 'N/A') ?></div>
                                            <div><strong>CMC:</strong> <?= $card_data['data']['cmc'] ?? 'N/A' ?></div>
                                            <div><strong>Typ:</strong> <?= htmlspecialchars($card_data['data']['type_line'] ?? 'N/A') ?></div>
                                            <div><strong>Seltenheit:</strong> 
                                                <span style="color: <?= 
                                                    $card_data['data']['rarity'] === 'mythic' ? '#ff6b35' : 
                                                    ($card_data['data']['rarity'] === 'rare' ? '#ffd700' : 
                                                    ($card_data['data']['rarity'] === 'uncommon' ? '#c0c0c0' : '#8b4513')) 
                                                ?>;">
                                                    <?= ucfirst($card_data['data']['rarity'] ?? 'N/A') ?>
                                                </span>
                                            </div>
                                            <?php if ($power !== null && $toughness !== null): ?>
                                                <div><strong>Power/Toughness:</strong> <?= $power ?>/<?= $toughness ?></div>
                                            <?php endif; ?>
                                            <div><strong>Farben:</strong> 
                                                <?= !empty($colors) ? implode(', ', $colors) : 'Farblos' ?>
                                            </div>
                                            <div><strong>Farbidentität:</strong> 
                                                <?= !empty($color_identity) ? implode(', ', $color_identity) : 'Farblos' ?>
                                            </div>
                                            <?php if ($edhrec_rank): ?>
                                                <div><strong>EDHREC Rank:</strong> #<?= number_format($edhrec_rank) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if (!empty($card_data['data']['oracle_text'])): ?>
                                    <div class="oracle-text" style="margin-top: 1rem; padding: 1rem; background: var(--background-color); border-radius: 6px; border-left: 4px solid var(--primary-color);">
                                        <strong>Regeltext:</strong><br>
                                        <?= nl2br(htmlspecialchars($card_data['data']['oracle_text'])) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Price Information -->
                        <div class="price-info-panel">
                            <div class="panel">
                                <h2 style="margin-bottom: 1rem; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem;">
                                    💰 Preis-Information
                                </h2>
                                
                                <?php $prices = $card_data['data']['prices'] ?? []; ?>
                                <div class="price-grid" style="display: grid; gap: 1rem;">
                                    <?php if (isset($prices['usd'])): ?>
                                        <div class="price-item" style="padding: 1rem; background: var(--surface-color); border-radius: 8px; border: 1px solid var(--border-color);">
                                            <div style="font-size: 0.9rem; color: var(--text-secondary);">USD (Normal)</div>
                                            <div style="font-size: 1.5rem; font-weight: bold; color: var(--primary-color);">
                                                $<?= $prices['usd'] ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (isset($prices['usd_foil'])): ?>
                                        <div class="price-item" style="padding: 1rem; background: var(--surface-color); border-radius: 8px; border: 1px solid var(--border-color);">
                                            <div style="font-size: 0.9rem; color: var(--text-secondary);">USD (Foil)</div>
                                            <div style="font-size: 1.5rem; font-weight: bold; color: #ffd700;">
                                                $<?= $prices['usd_foil'] ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (isset($prices['eur'])): ?>
                                        <div class="price-item" style="padding: 1rem; background: var(--surface-color); border-radius: 8px; border: 1px solid var(--border-color);">
                                            <div style="font-size: 0.9rem; color: var(--text-secondary);">EUR (Normal)</div>
                                            <div style="font-size: 1.5rem; font-weight: bold; color: var(--accent-color);">
                                                €<?= $prices['eur'] ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (isset($prices['tix'])): ?>
                                        <div class="price-item" style="padding: 1rem; background: var(--surface-color); border-radius: 8px; border: 1px solid var(--border-color);">
                                            <div style="font-size: 0.9rem; color: var(--text-secondary);">MTGO Tix</div>
                                            <div style="font-size: 1.5rem; font-weight: bold; color: var(--text-primary);">
                                                <?= $prices['tix'] ?> Tix
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div style="margin-top: 1rem; padding: 1rem; background: var(--background-color); border-radius: 6px;">
                                    <small style="color: var(--text-secondary);">
                                        💡 Preise werden von Scryfall bereitgestellt und können variieren.
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Analysis Results -->
                    <div class="analysis-panel">
                        <div class="panel">
                            <h2 style="margin-bottom: 1.5rem; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem;">
                                📊 Detaillierte Analyse
                            </h2>
                            
                            <div class="analysis-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem;">
                                
                                <!-- Overall Rating -->
                                <div class="rating-section">
                                    <h3 style="color: var(--primary-color); margin-bottom: 1rem;">🏆 Gesamtbewertung</h3>
                                    <div style="text-align: center; padding: 1.5rem; background: var(--surface-color); border-radius: 8px; border: 2px solid var(--primary-color);">
                                        <div style="font-size: 2rem; margin-bottom: 0.5rem;"><?= $analysis['overall_rating'] ?></div>
                                        <div style="font-size: 1.2rem; font-weight: bold; color: var(--primary-color);">
                                            <?= $analysis['power_level'] ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Commander Potential -->
                                <div class="commander-section">
                                    <h3 style="color: var(--accent-color); margin-bottom: 1rem;">👑 Commander-Potential</h3>
                                    <div style="padding: 1rem; background: var(--surface-color); border-radius: 8px; border: 1px solid var(--border-color);">
                                        <div style="font-size: 1.2rem; font-weight: bold; margin-bottom: 0.5rem; color: <?= 
                                            $analysis['commander_potential'] === 'Excellent' ? 'var(--success-color)' : 
                                            ($analysis['commander_potential'] === 'Limited' ? 'var(--warning-color)' : 'var(--error-color)') 
                                        ?>;">
                                            <?= $analysis['commander_potential'] ?>
                                        </div>
                                        <div style="font-size: 0.9rem; color: var(--text-secondary);">
                                            <?php if ($analysis['commander_potential'] === 'Excellent'): ?>
                                                Diese Karte eignet sich hervorragend als Commander!
                                            <?php elseif ($analysis['commander_potential'] === 'Limited'): ?>
                                                Begrenzte Commander-Nutzung möglich.
                                            <?php else: ?>
                                                Nicht als Commander geeignet.
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Format Playability -->
                                <div class="format-section">
                                    <h3 style="color: var(--success-color); margin-bottom: 1rem;">🎮 Format-Spielbarkeit</h3>
                                    <div style="padding: 1rem; background: var(--surface-color); border-radius: 8px; border: 1px solid var(--border-color);">
                                        <?php if (!empty($analysis['format_playability'])): ?>
                                            <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                                                <?php foreach ($analysis['format_playability'] as $format): ?>
                                                    <span style="padding: 0.25rem 0.75rem; background: var(--success-color); color: white; border-radius: 20px; font-size: 0.8rem;">
                                                        <?= htmlspecialchars($format) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: var(--text-secondary);">Keine legalen Formate</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Deck Archetypes -->
                                <div class="archetype-section">
                                    <h3 style="color: var(--warning-color); margin-bottom: 1rem;">🏗️ Deck-Archetypen</h3>
                                    <div style="padding: 1rem; background: var(--surface-color); border-radius: 8px; border: 1px solid var(--border-color);">
                                        <?php if (!empty($analysis['deck_archetype'])): ?>
                                            <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                                                <?php foreach ($analysis['deck_archetype'] as $archetype): ?>
                                                    <span style="padding: 0.25rem 0.75rem; background: var(--warning-color); color: white; border-radius: 20px; font-size: 0.8rem;">
                                                        <?= htmlspecialchars($archetype) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: var(--text-secondary);">Universell einsetzbar</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Strengths and Weaknesses -->
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-top: 1.5rem;">
                                
                                <!-- Strengths -->
                                <div class="strengths-section">
                                    <h3 style="color: var(--success-color); margin-bottom: 1rem;">✅ Stärken</h3>
                                    <div style="padding: 1rem; background: var(--surface-color); border-radius: 8px; border: 1px solid var(--success-color);">
                                        <?php if (!empty($analysis['strengths'])): ?>
                                            <ul style="margin: 0; padding-left: 1.5rem; color: var(--text-primary);">
                                                <?php foreach ($analysis['strengths'] as $strength): ?>
                                                    <li style="margin-bottom: 0.5rem;"><?= htmlspecialchars($strength) ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php else: ?>
                                            <span style="color: var(--text-secondary);">Keine besonderen Stärken erkannt</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Weaknesses -->
                                <div class="weaknesses-section">
                                    <h3 style="color: var(--error-color); margin-bottom: 1rem;">❌ Schwächen</h3>
                                    <div style="padding: 1rem; background: var(--surface-color); border-radius: 8px; border: 1px solid var(--error-color);">
                                        <?php if (!empty($analysis['weaknesses'])): ?>
                                            <ul style="margin: 0; padding-left: 1.5rem; color: var(--text-primary);">
                                                <?php foreach ($analysis['weaknesses'] as $weakness): ?>
                                                    <li style="margin-bottom: 0.5rem;"><?= htmlspecialchars($weakness) ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php else: ?>
                                            <span style="color: var(--text-secondary);">Keine großen Schwächen erkannt</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Recommendation -->
                            <div class="recommendation-section" style="margin-top: 1.5rem;">
                                <h3 style="color: var(--primary-color); margin-bottom: 1rem;">💡 Empfehlung</h3>
                                <div style="padding: 1.5rem; background: linear-gradient(135deg, var(--primary-color), var(--accent-color)); color: white; border-radius: 8px;">
                                    <?php
                                    $recommendation = '';
                                    switch ($analysis['power_level']) {
                                        case 'Excellent':
                                            $recommendation = '🔥 Diese Karte ist ein absolutes Muss! Hohe Spielstärke und vielseitig einsetzbar. Perfekt für kompetitive Decks.';
                                            break;
                                        case 'Very Good':
                                            $recommendation = '⚡ Sehr starke Karte! Definitiv einen Platz in entsprechenden Decks wert. Gutes Preis-Leistungs-Verhältnis.';
                                            break;
                                        case 'Good':
                                            $recommendation = '👍 Solide Karte mit klarem Nutzen. Gut für thematische Decks oder als Budget-Option.';
                                            break;
                                        case 'Playable':
                                            $recommendation = '🤔 Situativ spielbar. Könnte in sehr spezifischen Decks oder Casual-Runden funktionieren.';
                                            break;
                                        default:
                                            $recommendation = '💔 Eher schwache Karte. Hauptsächlich für Sammler oder sehr spezielle Nischen-Strategien interessant.';
                                    }
                                    ?>
                                    <p style="margin: 0; font-size: 1.1rem; line-height: 1.4;">
                                        <?= $recommendation ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (!$card_data): ?>
                <div class="getting-started" style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                    <div style="font-size: 4rem; margin-bottom: 1rem;">⭐</div>
                    <h2 style="color: var(--text-primary); margin-bottom: 1rem;">Karten-Analyse starten</h2>
                    <p>Geben Sie einen Kartennamen ein, um eine umfassende Analyse zu erhalten.</p>
                    <p style="font-size: 0.9rem;">Entdecken Sie Stärken, Schwächen, Commander-Potential und mehr!</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Autocomplete functionality for analysis
        document.addEventListener('DOMContentLoaded', function() {
            const cardInput = document.getElementById('cardAnalysisInput');
            const suggestionsContainer = document.getElementById('analysisAutocompleteSuggestions');
            let selectedIndex = -1;
            let searchTimeout;
            
            if (cardInput && suggestionsContainer) {
                cardInput.addEventListener('input', function() {
                    const query = this.value.toLowerCase().trim();
                    suggestionsContainer.innerHTML = '';
                    selectedIndex = -1;
                    
                    if (searchTimeout) {
                        clearTimeout(searchTimeout);
                    }
                    
                    if (query.length < 2) {
                        suggestionsContainer.style.display = 'none';
                        return;
                    }
                    
                    searchTimeout = setTimeout(() => {
                        fetch(`api/search_cards.php?q=${encodeURIComponent(query)}`)
                            .then(response => response.json())
                            .then(matches => {
                                if (matches.length === 0) {
                                    suggestionsContainer.style.display = 'none';
                                    return;
                                }
                                
                                matches.slice(0, 8).forEach((card, index) => {
                                    const suggestion = document.createElement('div');
                                    suggestion.className = 'autocomplete-suggestion';
                                    suggestion.textContent = card;
                                    suggestion.addEventListener('click', function() {
                                        cardInput.value = card;
                                        suggestionsContainer.style.display = 'none';
                                    });
                                    suggestionsContainer.appendChild(suggestion);
                                });
                                
                                suggestionsContainer.style.display = 'block';
                            })
                            .catch(error => {
                                console.error('Autocomplete search error:', error);
                                suggestionsContainer.style.display = 'none';
                            });
                    }, 300);
                });
                
                // Keyboard navigation
                cardInput.addEventListener('keydown', function(e) {
                    const suggestions = suggestionsContainer.querySelectorAll('.autocomplete-suggestion');
                    
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        selectedIndex = Math.min(selectedIndex + 1, suggestions.length - 1);
                        updateSelectedSuggestion(suggestions);
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        selectedIndex = Math.max(selectedIndex - 1, -1);
                        updateSelectedSuggestion(suggestions);
                    } else if (e.key === 'Enter' && selectedIndex >= 0) {
                        e.preventDefault();
                        suggestions[selectedIndex].click();
                    } else if (e.key === 'Escape') {
                        suggestionsContainer.style.display = 'none';
                        selectedIndex = -1;
                    }
                });
                
                function updateSelectedSuggestion(suggestions) {
                    suggestions.forEach((suggestion, index) => {
                        suggestion.classList.toggle('selected', index === selectedIndex);
                    });
                }
            }
        });
    </script>

    <?php include 'includes/footer.php'; ?>
</body>
</html>
