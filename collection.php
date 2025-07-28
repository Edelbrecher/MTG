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

// Get all cards for current user (JavaScript handles filtering)
$stmt = $pdo->prepare("SELECT * FROM collections WHERE user_id = ? ORDER BY added_at DESC");
$stmt->execute([$_SESSION['user_id']]);
$collection = $stmt->fetchAll();

// Get collection summary using the optimized view
$stmt = $pdo->prepare("SELECT unique_cards, total_cards FROM user_collection_summary WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$collection_summary = $stmt->fetch();

// Fallback if view doesn't return data (for new users)
$total_quantity = $collection_summary ? $collection_summary['total_cards'] : 0;
$unique_cards = $collection_summary ? $collection_summary['unique_cards'] : count($collection);

// Get user information
$stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();
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
        
        /* Dynamic Grid Classes - Responsive Card Sizing within normal container */
        .cards-grid {
            gap: 16px;
            padding: 0;
            width: 100%;
        }
        
        .cards-grid[data-cards-per-row="3"] { 
            grid-template-columns: repeat(3, 1fr);
        }
        .cards-grid[data-cards-per-row="4"] { 
            grid-template-columns: repeat(4, 1fr);
        }
        .cards-grid[data-cards-per-row="5"] { 
            grid-template-columns: repeat(5, 1fr);
        }
        .cards-grid[data-cards-per-row="6"] { 
            grid-template-columns: repeat(6, 1fr);
        }
        .cards-grid[data-cards-per-row="7"] { 
            grid-template-columns: repeat(7, 1fr);
        }
        .cards-grid[data-cards-per-row="8"] { 
            grid-template-columns: repeat(8, 1fr);
        }
        .cards-grid[data-cards-per-row="9"] { 
            grid-template-columns: repeat(9, 1fr);
        }
        
        /* Card scaling for better fit within container */
        .mtg-card {
            max-width: none; /* Let grid control the width */
            min-width: 150px; /* Minimum usable size */
            width: 100%;
            height: auto; /* Allow cards to grow with content */
        }
        
        /* Smaller card images to make room for text */
        .mtg-card-image {
            height: 120px; /* Reduced from default */
            width: auto;
            object-fit: cover;
        }
        
        /* Enhanced card content area */
        .mtg-card-content {
            padding: 8px;
            flex-grow: 1;
        }
        
        /* Card text styling */
        .mtg-card-text {
            font-size: 11px;
            line-height: 1.3;
            color: #374151;
            margin: 6px 0;
            max-height: 60px;
            overflow-y: auto;
            text-align: left;
            background: #f9fafb;
            padding: 4px 6px;
            border-radius: 4px;
            border-left: 3px solid var(--primary-color);
        }
        
        .mtg-card-text:empty {
            display: none;
        }
        
        /* Responsive adjustments - reduce columns on smaller screens */
        @media (max-width: 1200px) {
            .cards-grid[data-cards-per-row="9"] { grid-template-columns: repeat(6, 1fr); }
            .cards-grid[data-cards-per-row="8"] { grid-template-columns: repeat(5, 1fr); }
            .cards-grid[data-cards-per-row="7"] { grid-template-columns: repeat(4, 1fr); }
        }
        
        @media (max-width: 900px) {
            .cards-grid[data-cards-per-row="9"],
            .cards-grid[data-cards-per-row="8"],
            .cards-grid[data-cards-per-row="7"],
            .cards-grid[data-cards-per-row="6"] { grid-template-columns: repeat(4, 1fr); }
        }
        
        @media (max-width: 700px) {
            .cards-grid[data-cards-per-row="9"],
            .cards-grid[data-cards-per-row="8"],
            .cards-grid[data-cards-per-row="7"],
            .cards-grid[data-cards-per-row="6"],
            .cards-grid[data-cards-per-row="5"] { grid-template-columns: repeat(3, 1fr); }
        }
        
        @media (max-width: 600px) {
            .cards-grid { grid-template-columns: repeat(2, 1fr) !important; }
        }
        
        /* List View Styles */
        .list-view {
            display: none;
        }
        
        .list-view.active {
            display: block;
        }
        
        .cards-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .cards-table th,
        .cards-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .cards-table th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .cards-table tr:hover {
            background: #f9fafb;
        }
        
        .cards-table tr.hidden {
            display: none;
        }
        
        .card-image-small {
            width: 40px;
            height: 56px;
            object-fit: cover;
            border-radius: 4px;
            border: 1px solid #e5e7eb;
        }
        
        .card-name-cell {
            font-weight: 600;
            color: var(--primary-color);
            min-width: 180px;
        }
        
        .card-cost-cell {
            min-width: 80px;
        }
        
        .card-type-cell {
            color: #6b7280;
            min-width: 150px;
        }
        
        .card-stats-cell {
            text-align: center;
            font-weight: 600;
            min-width: 60px;
        }
        
        .card-rarity-cell {
            text-align: center;
            min-width: 80px;
        }
        
        .card-set-cell {
            color: #6b7280;
            font-size: 12px;
            min-width: 100px;
        }
        
        .card-quantity-cell {
            text-align: center;
            min-width: 80px;
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .rarity-common { color: #6b7280; }
        .rarity-uncommon { color: #9ca3af; }
        .rarity-rare { color: #fbbf24; }
        .rarity-mythic { color: #ef4444; }
        
        /* View mode toggle styles */
        #grid-controls {
            transition: opacity 0.3s ease;
        }
        
        #grid-controls.hidden {
            opacity: 0.5;
            pointer-events: none;
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
        
        /* Floating Sidebar for Bulk Import */
        .bulk-import-sidebar {
            position: fixed;
            left: 20px;
            top: 50%;
            transform: translateY(-50%);
            width: 300px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
            z-index: 1000;
            transition: all 0.3s ease;
            border: 2px solid var(--primary-color);
        }
        
        .bulk-import-sidebar.collapsed {
            width: 60px;
            height: 60px;
            border-radius: 50%;
        }
        
        .sidebar-toggle {
            position: absolute;
            top: 12px;
            right: 12px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            cursor: pointer;
            font-size: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }
        
        .bulk-import-sidebar.collapsed .sidebar-toggle {
            top: 12px;
            right: 12px;
            transform: rotate(180deg);
        }
        
        .sidebar-content {
            padding: 20px;
            transition: opacity 0.3s ease;
        }
        
        .bulk-import-sidebar.collapsed .sidebar-content {
            opacity: 0;
            pointer-events: none;
        }
        
        .sidebar-header {
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .sidebar-header h4 {
            margin: 0;
            color: var(--primary-color);
            font-size: 16px;
        }
        
        .quick-add-form {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .quick-add-input {
            width: 100%;
            padding: 8px;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            font-size: 14px;
            resize: vertical;
            min-height: 80px;
        }
        
        .quick-add-input:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .sidebar-stats {
            font-size: 12px;
            color: #6b7280;
            margin-top: 8px;
            text-align: center;
        }
        
        /* Layout adjustment for main content - Same as other pages */
        .container {
            /* Use default container styles like other pages */
        }
        
        .main-content {
            /* Use default main-content styles like other pages */
        }
        
        /* Sidebar should not affect main content layout */
        @media (min-width: 1400px) {
            /* No special layout changes needed */
        }
        
        @media (max-width: 1399px) {
            .bulk-import-sidebar {
                left: 10px;
                width: 280px;
            }
            
            .bulk-import-sidebar.collapsed {
                width: 50px;
                height: 50px;
            }
        }
        
        @media (max-width: 900px) {
            .bulk-import-sidebar {
                position: relative;
                left: auto;
                top: auto;
                transform: none;
                width: 100%;
                margin-bottom: 20px;
            }
            
            .bulk-import-sidebar.collapsed {
                width: 100%;
                height: auto;
                border-radius: 12px;
            }
            
            .main-content {
                margin-left: 0;
            }
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

            <!-- Floating Bulk Import Sidebar -->
            <div class="bulk-import-sidebar" id="bulk-sidebar">
                <button class="sidebar-toggle" onclick="toggleSidebar()">📦</button>
                <div class="sidebar-content">
                    <div class="sidebar-header">
                        <h4>📦 Quick Add</h4>
                    </div>
                    <form onsubmit="quickAddCards(event)" class="quick-add-form">
                        <textarea 
                            id="quick-add-input" 
                            class="quick-add-input" 
                            placeholder="Lightning Bolt&#10;Counterspell&#10;Birds of Paradise"
                            rows="4"></textarea>
                        <button type="submit" class="btn btn-primary btn-small">Hinzufügen</button>
                        <a href="bulk_import.php" class="btn btn-secondary btn-small">Vollständiger Import</a>
                    </form>
                    <div class="sidebar-stats" id="sidebar-stats">
                        Bereit für Quick-Add
                    </div>
                </div>
            </div>

            <!-- Enhanced Filters with Live Filtering -->
            <div class="filter-panel">
                <div class="filter-header">
                    <h3>🔍 Filter & Ansicht</h3>
                    <div class="view-controls">
                        <div style="display: flex; gap: 12px; align-items: center;">
                            <div>
                                <label>Ansicht:</label>
                                <select id="view-mode" onchange="changeViewMode(this.value)">
                                    <option value="grid">🎴 Karten-Grid</option>
                                    <option value="list">📋 Listen-Ansicht</option>
                                </select>
                            </div>
                            <div id="grid-controls">
                                <label>Karten pro Reihe:</label>
                                <select id="cards-per-row" onchange="changeCardsPerRow(this.value)">
                                    <option value="3">3 Karten</option>
                                    <option value="4">4 Karten</option>
                                    <option value="5" selected>5 Karten</option>
                                    <option value="6">6 Karten</option>
                                    <option value="7">7 Karten</option>
                                    <option value="8">8 Karten</option>
                                    <option value="9">9 Karten</option>
                                </select>
                            </div>
                        </div>
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
                    <span style="margin-left: 12px; color: #9ca3af;">
                        (Gesamt: <span id="total-quantity"><?php echo $total_quantity; ?></span> Karten inkl. Duplikate)
                    </span>
                    <button onclick="resetFilters()" class="btn btn-small btn-secondary">🔄 Filter zurücksetzen</button>
                </div>
            </div>

            <!-- Grid View -->
            <div class="cards-grid grid-view" id="cards-grid" data-cards-per-row="5">
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
                            
                            <!-- Card Text -->
                            <?php if (!empty($card_data['oracle_text'])): ?>
                                <div class="mtg-card-text">
                                    <?php echo nl2br(htmlspecialchars($card_data['oracle_text'])); ?>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Quantity Display (read-only) -->
                            <div class="mtg-card-quantity" style="text-align: center; margin-top: 8px; font-weight: 600; color: var(--primary-color);">
                                <?php echo $card['quantity']; ?>x vorhanden
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- List View -->
            <div class="list-view" id="list-view">
                <table class="cards-table">
                    <thead>
                        <tr>
                            <th>Bild</th>
                            <th>Name</th>
                            <th>Manakosten</th>
                            <th>Typ</th>
                            <th>Kartentext</th>
                            <th>Stärke/Widerstand</th>
                            <th>Seltenheit</th>
                            <th>Set</th>
                            <th>Anzahl</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($collection as $card): ?>
                            <?php $card_data = json_decode($card['card_data'], true); ?>
                            <tr class="card-row"
                                data-name="<?php echo htmlspecialchars(strtolower($card['card_name'])); ?>"
                                data-colors="<?php echo implode(',', $card_data['colors'] ?? []); ?>"
                                data-type="<?php echo htmlspecialchars(strtolower($card_data['type_line'] ?? '')); ?>"
                                data-rarity="<?php echo htmlspecialchars($card_data['rarity'] ?? ''); ?>"
                                data-cmc="<?php echo intval($card_data['cmc'] ?? 0); ?>"
                                data-legendary="<?php echo strpos(strtolower($card_data['type_line'] ?? ''), 'legendary') !== false ? 'true' : 'false'; ?>"
                                data-commander="<?php echo (strpos(strtolower($card_data['type_line'] ?? ''), 'legendary') !== false && strpos(strtolower($card_data['type_line'] ?? ''), 'creature') !== false) ? 'true' : 'false'; ?>"
                                data-tribal="<?php echo strpos(strtolower($card_data['type_line'] ?? ''), 'tribal') !== false ? 'true' : 'false'; ?>"
                                data-snow="<?php echo strpos(strtolower($card_data['type_line'] ?? ''), 'snow') !== false ? 'true' : 'false'; ?>">
                                <td>
                                    <img src="<?php echo htmlspecialchars($card_data['image_url'] ?? 'assets/images/card-back.jpg'); ?>" 
                                         alt="<?php echo htmlspecialchars($card['card_name']); ?>" 
                                         class="card-image-small">
                                </td>
                                <td class="card-name-cell"><?php echo htmlspecialchars($card['card_name']); ?></td>
                                <td class="card-cost-cell">
                                    <?php if (isset($card_data['mana_cost'])): ?>
                                        <?php echo renderManaCost($card_data['mana_cost']); ?>
                                    <?php endif; ?>
                                </td>
                                <td class="card-type-cell"><?php echo htmlspecialchars($card_data['type_line'] ?? ''); ?></td>
                                <td class="card-text-cell" style="max-width: 200px; font-size: 11px; line-height: 1.3;">
                                    <?php if (!empty($card_data['oracle_text'])): ?>
                                        <?php echo nl2br(htmlspecialchars(substr($card_data['oracle_text'], 0, 100) . (strlen($card_data['oracle_text']) > 100 ? '...' : ''))); ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td class="card-stats-cell">
                                    <?php if (isset($card_data['power'], $card_data['toughness'])): ?>
                                        <?php echo $card_data['power']; ?>/<?php echo $card_data['toughness']; ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td class="card-rarity-cell">
                                    <span class="rarity-<?php echo $card_data['rarity'] ?? 'common'; ?>">
                                        <?php 
                                        $rarity_icons = [
                                            'common' => '⚪',
                                            'uncommon' => '🔘',
                                            'rare' => '🟡',
                                            'mythic' => '🔴'
                                        ];
                                        echo $rarity_icons[$card_data['rarity'] ?? 'common'] ?? '⚪';
                                        ?>
                                        <?php echo ucfirst($card_data['rarity'] ?? 'common'); ?>
                                    </span>
                                </td>
                                <td class="card-set-cell">
                                    <?php echo htmlspecialchars($card_data['set_name'] ?? ''); ?><br>
                                    <small><?php echo htmlspecialchars(strtoupper($card_data['set'] ?? '')); ?></small>
                                </td>
                                <td class="card-quantity-cell">
                                    <strong><?php echo $card['quantity']; ?>x</strong>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (empty($collection)): ?>
                <div class="card text-center">
                    <div class="card-body">
                        <h3>Keine Karten gefunden</h3>
                        <p>Fügen Sie Ihre erste Karte über den Bulk-Import hinzu!</p>
                        <a href="bulk_import.php" class="btn btn-primary">📦 Karten hinzufügen</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php include 'includes/footer.php'; ?>
    
    <script>
        // Live Filtering System
        let allCards = [];
        let allRows = [];
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            allCards = Array.from(document.querySelectorAll('.mtg-card'));
            allRows = Array.from(document.querySelectorAll('.card-row'));
            updateStats();
        });
        
        // Change view mode
        function changeViewMode(mode) {
            const gridView = document.querySelector('.grid-view');
            const listView = document.querySelector('.list-view');
            const gridControls = document.getElementById('grid-controls');
            
            if (mode === 'list') {
                gridView.style.display = 'none';
                listView.classList.add('active');
                gridControls.classList.add('hidden');
            } else {
                gridView.style.display = 'grid';
                listView.classList.remove('active');
                gridControls.classList.remove('hidden');
            }
            
            // Reapply filters to current view
            applyFilters();
        }
        
        // Apply all filters
        function applyFilters() {
            const searchTerm = document.getElementById('search-input').value.toLowerCase();
            const colorFilter = document.getElementById('color-filter').value;
            const typeFilter = document.getElementById('type-filter').value.toLowerCase();
            const rarityFilter = document.getElementById('rarity-filter').value;
            const cmcFilter = document.getElementById('cmc-filter').value;
            const specialFilter = document.getElementById('special-filter').value;
            
            const isListView = document.getElementById('view-mode').value === 'list';
            const itemsToFilter = isListView ? allRows : allCards;
            
            let visibleCount = 0;
            
            itemsToFilter.forEach(item => {
                let isVisible = true;
                
                // Text search
                if (searchTerm) {
                    const cardName = item.dataset.name || '';
                    if (!cardName.includes(searchTerm)) {
                        isVisible = false;
                    }
                }
                
                // Color filter
                if (colorFilter && isVisible) {
                    const cardColors = item.dataset.colors || '';
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
                    const cardType = item.dataset.type || '';
                    if (typeFilter === 'legendary') {
                        isVisible = item.dataset.legendary === 'true';
                    } else {
                        isVisible = cardType.includes(typeFilter);
                    }
                }
                
                // Rarity filter
                if (rarityFilter && isVisible) {
                    const cardRarity = item.dataset.rarity || '';
                    isVisible = cardRarity === rarityFilter;
                }
                
                // CMC filter
                if (cmcFilter && isVisible) {
                    const cardCmc = parseInt(item.dataset.cmc || '0');
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
                            isVisible = item.dataset.commander === 'true';
                            break;
                        case 'legendary':
                            isVisible = item.dataset.legendary === 'true';
                            break;
                        case 'tribal':
                            isVisible = item.dataset.tribal === 'true';
                            break;
                        case 'snow':
                            isVisible = item.dataset.snow === 'true';
                            break;
                    }
                }
                
                // Show/hide item
                if (isVisible) {
                    item.classList.remove('hidden');
                    visibleCount++;
                } else {
                    item.classList.add('hidden');
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
            const isListView = document.getElementById('view-mode').value === 'list';
            const itemsToCount = isListView ? allRows : allCards;
            const visibleItems = itemsToCount.filter(item => !item.classList.contains('hidden'));
            
            const visibleCount = visibleItems.length;
            const totalItems = itemsToCount.length;
            
            // Calculate total quantity of visible cards
            let visibleQuantity = 0;
            visibleItems.forEach(item => {
                // Get quantity from the card data
                const quantityElement = item.querySelector('.mtg-card-quantity, .card-quantity-cell strong');
                if (quantityElement) {
                    const quantityText = quantityElement.textContent || quantityElement.innerText;
                    const quantity = parseInt(quantityText.replace(/[^\d]/g, '')) || 0;
                    visibleQuantity += quantity;
                }
            });
            
            document.getElementById('visible-cards').textContent = visibleCount;
            document.getElementById('total-cards').textContent = totalItems;
            
            // Update the total quantity display to show filtered quantity
            const totalQuantitySpan = document.getElementById('total-quantity');
            if (visibleCount < totalItems) {
                totalQuantitySpan.textContent = visibleQuantity + ' / <?php echo $total_quantity; ?>';
                totalQuantitySpan.parentElement.innerHTML = totalQuantitySpan.parentElement.innerHTML.replace(
                    'Gesamt:', 'Gefiltert/Gesamt:'
                );
            } else {
                totalQuantitySpan.textContent = '<?php echo $total_quantity; ?>';
                totalQuantitySpan.parentElement.innerHTML = totalQuantitySpan.parentElement.innerHTML.replace(
                    'Gefiltert/Gesamt:', 'Gesamt:'
                );
            }
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
            
            allRows.forEach(row => {
                row.classList.remove('hidden');
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
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.target.tagName === 'TEXTAREA') return;
            if (e.ctrlKey || e.metaKey || e.altKey) return;
            
            if (e.key.match(/[a-zA-Z0-9]/)) {
                const searchInput = document.getElementById('search-input');
                searchInput.focus();
            }
        });
        
        // Sidebar functionality
        function toggleSidebar() {
            const sidebar = document.getElementById('bulk-sidebar');
            sidebar.classList.toggle('collapsed');
            
            // Store state in localStorage
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
        }
        
        // Restore sidebar state on page load
        document.addEventListener('DOMContentLoaded', function() {
            const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
            if (isCollapsed) {
                document.getElementById('bulk-sidebar').classList.add('collapsed');
            }
        });
        
        // Quick add cards functionality
        async function quickAddCards(event) {
            event.preventDefault();
            
            const input = document.getElementById('quick-add-input');
            const statsDiv = document.getElementById('sidebar-stats');
            const cardNames = input.value.trim().split('\n').filter(name => name.trim());
            
            if (cardNames.length === 0) {
                statsDiv.textContent = 'Keine Kartennamen eingegeben';
                return;
            }
            
            statsDiv.textContent = `Verarbeite ${cardNames.length} Karte(n)...`;
            
            try {
                // Create form data
                const formData = new FormData();
                formData.append('action', 'bulk_import');
                formData.append('card_input', input.value);
                formData.append('separator', 'newline');
                
                // Send to bulk_import.php
                const response = await fetch('bulk_import.php', {
                    method: 'POST',
                    body: formData
                });
                
                if (response.ok) {
                    statsDiv.textContent = `✅ ${cardNames.length} Karte(n) hinzugefügt!`;
                    input.value = '';
                    
                    // Reload page to show new cards
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    throw new Error('Network response was not ok');
                }
                
            } catch (error) {
                statsDiv.textContent = '❌ Fehler beim Hinzufügen';
                console.error('Error:', error);
            }
        }
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
