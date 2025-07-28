<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Handle card actions (delete and update quantity)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete_card') {
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
    <style>
        /* Enhanced Filter Panel */
        .filter-panel {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .filter-header h3 {
            margin: 0;
            color: var(--primary-color);
        }
        
        .view-controls {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .view-controls label {
            font-weight: 500;
            color: #6b7280;
        }
        
        .view-controls select {
            padding: 6px 12px;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            background: white;
            font-family: 'Inter', sans-serif;
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        
        .filter-group label {
            font-weight: 500;
            color: #374151;
            font-size: 14px;
        }
        
        .filter-group input,
        .filter-group select {
            padding: 8px 12px;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        
        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .filter-stats {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            background: #f9fafb;
            border-radius: 8px;
            font-size: 14px;
            color: #6b7280;
        }
        
        .btn-small {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        /* Dynamic Grid Classes */
        .cards-grid[data-cards-per-row="3"] { grid-template-columns: repeat(3, 1fr); }
        .cards-grid[data-cards-per-row="4"] { grid-template-columns: repeat(4, 1fr); }
        .cards-grid[data-cards-per-row="5"] { grid-template-columns: repeat(5, 1fr); }
        .cards-grid[data-cards-per-row="6"] { grid-template-columns: repeat(6, 1fr); }
        .cards-grid[data-cards-per-row="7"] { grid-template-columns: repeat(7, 1fr); }
        .cards-grid[data-cards-per-row="8"] { grid-template-columns: repeat(8, 1fr); }
        .cards-grid[data-cards-per-row="9"] { grid-template-columns: repeat(9, 1fr); }
        
        /* Responsive adjustments */
        @media (max-width: 1400px) {
            .cards-grid[data-cards-per-row="9"] { grid-template-columns: repeat(7, 1fr); }
            .cards-grid[data-cards-per-row="8"] { grid-template-columns: repeat(6, 1fr); }
        }
        
        @media (max-width: 1200px) {
            .cards-grid[data-cards-per-row="9"],
            .cards-grid[data-cards-per-row="8"],
            .cards-grid[data-cards-per-row="7"] { grid-template-columns: repeat(5, 1fr); }
            .cards-grid[data-cards-per-row="6"] { grid-template-columns: repeat(4, 1fr); }
        }
        
        @media (max-width: 900px) {
            .cards-grid[data-cards-per-row="9"],
            .cards-grid[data-cards-per-row="8"],
            .cards-grid[data-cards-per-row="7"],
            .cards-grid[data-cards-per-row="6"],
            .cards-grid[data-cards-per-row="5"] { grid-template-columns: repeat(3, 1fr); }
        }
        
        @media (max-width: 600px) {
            .cards-grid { grid-template-columns: repeat(2, 1fr) !important; }
        }
        
        /* Hidden card animation */
        .mtg-card.hidden {
            display: none;
        }
        
        .mtg-card {
            transition: opacity 0.3s ease;
        }
        
        /* Filter highlight when active */
        .filter-group select.active {
            border-color: var(--primary-color);
            background-color: #eff6ff;
        }
    </style>
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

            <!-- Quick Actions -->
            <div class="card mb-4">
                <div class="card-body text-center">
                    <div style="display: flex; justify-content: center; gap: 16px; align-items: center;">
                        <div>
                            <h4 style="margin: 0 0 8px 0; color: var(--primary-color);">Karten hinzufügen</h4>
                            <p style="margin: 0; color: #6b7280; font-size: 14px;">Verwende den Bulk-Import für einzelne oder mehrere Karten</p>
                        </div>
                        <a href="bulk_import.php" class="btn btn-primary">📦 Zum Bulk-Import</a>
                    </div>
                </div>
            </div>

            <!-- Enhanced Filters with Live Filtering -->
            <div class="filter-panel">
                <div class="filter-header">
                    <h3>🔍 Filter & Ansicht</h3>
                    <div class="view-controls">
                        <label>Karten pro Reihe:</label>
                        <select id="cards-per-row" onchange="changeCardsPerRow(this.value)">
                            <option value="3">3 Karten</option>
                            <option value="4" selected>4 Karten</option>
                            <option value="5">5 Karten</option>
                            <option value="6">6 Karten</option>
                            <option value="7">7 Karten</option>
                            <option value="8">8 Karten</option>
                            <option value="9">9 Karten</option>
                        </select>
                    </div>
                </div>
                
                <div class="filter-grid">
                    <!-- Text Search -->
                    <div class="filter-group">
                        <label for="search-input">🔍 Kartenname</label>
                        <input type="text" 
                               id="search-input" 
                               placeholder="Nach Kartenname suchen..." 
                               oninput="applyFilters()">
                    </div>
                    
                    <!-- Color Filter -->
                    <div class="filter-group">
                        <label for="color-filter">🎨 Farbe</label>
                        <select id="color-filter" onchange="applyFilters()">
                            <option value="">Alle Farben</option>
                            <option value="W">⚪ Weiß</option>
                            <option value="U">🔵 Blau</option>
                            <option value="B">⚫ Schwarz</option>
                            <option value="R">🔴 Rot</option>
                            <option value="G">🟢 Grün</option>
                            <option value="C">⚪ Farblos</option>
                            <option value="multicolor">🌈 Mehrfarbig</option>
                        </select>
                    </div>
                    
                    <!-- Type Filter -->
                    <div class="filter-group">
                        <label for="type-filter">🏷️ Kartentyp</label>
                        <select id="type-filter" onchange="applyFilters()">
                            <option value="">Alle Typen</option>
                            <option value="Creature">🦁 Kreatur</option>
                            <option value="Instant">⚡ Spontanzauber</option>
                            <option value="Sorcery">🔮 Hexerei</option>
                            <option value="Enchantment">✨ Verzauberung</option>
                            <option value="Artifact">⚙️ Artefakt</option>
                            <option value="Planeswalker">👑 Planeswalker</option>
                            <option value="Land">🌍 Land</option>
                            <option value="Legendary">⭐ Legendär</option>
                        </select>
                    </div>
                    
                    <!-- Rarity Filter -->
                    <div class="filter-group">
                        <label for="rarity-filter">💎 Seltenheit</label>
                        <select id="rarity-filter" onchange="applyFilters()">
                            <option value="">Alle Seltenheiten</option>
                            <option value="common">⚪ Common</option>
                            <option value="uncommon">🔘 Uncommon</option>
                            <option value="rare">🟡 Rare</option>
                            <option value="mythic">🔴 Mythic</option>
                        </select>
                    </div>
                    
                    <!-- CMC Filter -->
                    <div class="filter-group">
                        <label for="cmc-filter">💰 Manakosten</label>
                        <select id="cmc-filter" onchange="applyFilters()">
                            <option value="">Alle Kosten</option>
                            <option value="0">0 Mana</option>
                            <option value="1">1 Mana</option>
                            <option value="2">2 Mana</option>
                            <option value="3">3 Mana</option>
                            <option value="4">4 Mana</option>
                            <option value="5">5 Mana</option>
                            <option value="6">6+ Mana</option>
                        </select>
                    </div>
                    
                    <!-- Special Filters -->
                    <div class="filter-group">
                        <label for="special-filter">⭐ Spezial</label>
                        <select id="special-filter" onchange="applyFilters()">
                            <option value="">Alle Karten</option>
                            <option value="commander">👑 Commander</option>
                            <option value="legendary">⭐ Legendär</option>
                            <option value="tribal">🏹 Tribal</option>
                            <option value="snow">❄️ Snow</option>
                        </select>
                    </div>
                </div>
                
                <!-- Stats Display -->
                <div class="filter-stats" id="filter-stats">
                    <span id="visible-cards">0</span> von <span id="total-cards">0</span> Karten angezeigt
                    <button onclick="resetFilters()" class="btn btn-small btn-secondary">🔄 Filter zurücksetzen</button>
                </div>
            </div>

            <!-- Collection Grid with Dynamic Classes -->
            <div class="cards-grid" id="cards-grid" data-cards-per-row="4">
                <?php foreach ($collection as $card): ?>
                    <?php
                    $card_data = json_decode($card['card_data'], true);
                    $colors = $card_data['colors'] ?? [];
                    $border_class = empty($colors) ? 'colorless' : (count($colors) > 1 ? 'multicolor' : strtolower($colors[0]));
                    ?>
                    <div class="mtg-card" 
                         data-name="<?php echo htmlspecialchars(strtolower($card['card_name'])); ?>"
                         data-colors="<?php echo implode(',', $colors); ?>"
                         data-type="<?php echo htmlspecialchars(strtolower($card_data['type_line'] ?? '')); ?>"
                         data-rarity="<?php echo htmlspecialchars($card_data['rarity'] ?? ''); ?>"
                         data-cmc="<?php echo intval($card_data['cmc'] ?? 0); ?>"
                         data-legendary="<?php echo strpos(strtolower($card_data['type_line'] ?? ''), 'legendary') !== false ? 'true' : 'false'; ?>"
                         data-commander="<?php echo (strpos(strtolower($card_data['type_line'] ?? ''), 'legendary') !== false && strpos(strtolower($card_data['type_line'] ?? ''), 'creature') !== false) ? 'true' : 'false'; ?>"
                         data-tribal="<?php echo strpos(strtolower($card_data['type_line'] ?? ''), 'tribal') !== false ? 'true' : 'false'; ?>"
                         data-snow="<?php echo strpos(strtolower($card_data['type_line'] ?? ''), 'snow') !== false ? 'true' : 'false'; ?>">
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
    
    <script>
        // Live Filtering System
        let allCards = [];
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            allCards = Array.from(document.querySelectorAll('.mtg-card'));
            updateStats();
        });
        
        // Apply all filters
        function applyFilters() {
            const searchTerm = document.getElementById('search-input').value.toLowerCase();
            const colorFilter = document.getElementById('color-filter').value;
            const typeFilter = document.getElementById('type-filter').value.toLowerCase();
            const rarityFilter = document.getElementById('rarity-filter').value;
            const cmcFilter = document.getElementById('cmc-filter').value;
            const specialFilter = document.getElementById('special-filter').value;
            
            let visibleCount = 0;
            
            allCards.forEach(card => {
                let isVisible = true;
                
                // Text search
                if (searchTerm) {
                    const cardName = card.dataset.name || '';
                    if (!cardName.includes(searchTerm)) {
                        isVisible = false;
                    }
                }
                
                // Color filter
                if (colorFilter && isVisible) {
                    const cardColors = card.dataset.colors || '';
                    if (colorFilter === 'C') {
                        // Colorless
                        isVisible = cardColors === '';
                    } else if (colorFilter === 'multicolor') {
                        // Multicolor
                        isVisible = cardColors.split(',').filter(c => c.length > 0).length > 1;
                    } else {
                        // Specific color
                        isVisible = cardColors.includes(colorFilter);
                    }
                }
                
                // Type filter
                if (typeFilter && isVisible) {
                    const cardType = card.dataset.type || '';
                    if (typeFilter === 'legendary') {
                        isVisible = card.dataset.legendary === 'true';
                    } else {
                        isVisible = cardType.includes(typeFilter);
                    }
                }
                
                // Rarity filter
                if (rarityFilter && isVisible) {
                    const cardRarity = card.dataset.rarity || '';
                    isVisible = cardRarity === rarityFilter;
                }
                
                // CMC filter
                if (cmcFilter && isVisible) {
                    const cardCmc = parseInt(card.dataset.cmc || '0');
                    if (cmcFilter === '6') {
                        isVisible = cardCmc >= 6;
                    } else {
                        isVisible = cardCmc === parseInt(cmcFilter);
                    }
                }
                
                // Special filter
                if (specialFilter && isVisible) {
                    switch (specialFilter) {
                        case 'commander':
                            isVisible = card.dataset.commander === 'true';
                            break;
                        case 'legendary':
                            isVisible = card.dataset.legendary === 'true';
                            break;
                        case 'tribal':
                            isVisible = card.dataset.tribal === 'true';
                            break;
                        case 'snow':
                            isVisible = card.dataset.snow === 'true';
                            break;
                    }
                }
                
                // Show/hide card
                if (isVisible) {
                    card.classList.remove('hidden');
                    visibleCount++;
                } else {
                    card.classList.add('hidden');
                }
            });
            
            updateStats();
            highlightActiveFilters();
        }
        
        // Change cards per row
        function changeCardsPerRow(count) {
            const grid = document.getElementById('cards-grid');
            grid.setAttribute('data-cards-per-row', count);
        }
        
        // Update statistics
        function updateStats() {
            const visibleCards = allCards.filter(card => !card.classList.contains('hidden')).length;
            const totalCards = allCards.length;
            
            document.getElementById('visible-cards').textContent = visibleCards;
            document.getElementById('total-cards').textContent = totalCards;
        }
        
        // Highlight active filters
        function highlightActiveFilters() {
            const filters = [
                'search-input',
                'color-filter', 
                'type-filter', 
                'rarity-filter', 
                'cmc-filter', 
                'special-filter'
            ];
            
            filters.forEach(filterId => {
                const filter = document.getElementById(filterId);
                if (filter.value) {
                    filter.classList.add('active');
                } else {
                    filter.classList.remove('active');
                }
            });
        }
        
        // Reset all filters
        function resetFilters() {
            document.getElementById('search-input').value = '';
            document.getElementById('color-filter').value = '';
            document.getElementById('type-filter').value = '';
            document.getElementById('rarity-filter').value = '';
            document.getElementById('cmc-filter').value = '';
            document.getElementById('special-filter').value = '';
            
            allCards.forEach(card => {
                card.classList.remove('hidden');
            });
            
            updateStats();
            highlightActiveFilters();
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + F to focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                document.getElementById('search-input').focus();
            }
            
            // Escape to reset filters
            if (e.key === 'Escape') {
                resetFilters();
            }
        });
        
        // Auto-focus search field when typing
        document.addEventListener('keydown', function(e) {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT') return;
            if (e.ctrlKey || e.metaKey || e.altKey) return;
            
            if (e.key.match(/[a-zA-Z0-9]/)) {
                const searchInput = document.getElementById('search-input');
                searchInput.focus();
            }
        });
    </script>
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
