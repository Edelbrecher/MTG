<?php
session_start();
require_once 'config/database.php';
require_once 'includes/card_translator.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Get user language preference
$userLanguage = CardTranslator::getUserLanguage($pdo, $_SESSION['user_id']);

// Hilfsfunktion um die richtige Bild-URL zu ermitteln
function getCardImageUrl($card_data) {
    // Direkte image_url (für ältere Karten)
    if (isset($card_data['image_url']) && !empty($card_data['image_url'])) {
        return $card_data['image_url'];
    }
    
    // image_uris Objekt (für neuere Karten aus Scryfall API)
    if (isset($card_data['image_uris']) && is_array($card_data['image_uris'])) {
        // Bevorzuge normal, dann small, dann large als Fallback
        if (isset($card_data['image_uris']['normal'])) {
            return $card_data['image_uris']['normal'];
        } elseif (isset($card_data['image_uris']['small'])) {
            return $card_data['image_uris']['small'];
        } elseif (isset($card_data['image_uris']['large'])) {
            return $card_data['image_uris']['large'];
        }
    }
    
    // Fallback für Karten ohne Bild
    return 'assets/images/card-back.jpg';
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
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/svg+xml" href="assets/images/favicon.svg">
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
            display: block;
            margin: 0 auto; /* Center the image */
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
        
        .translate-btn {
            font-size: 0.7rem;
            padding: 4px 8px;
            border: 1px solid #ccc;
            background: #f9f9f9;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .translate-btn:hover {
            background: #e9ecef;
            border-color: #adb5bd;
        }
        
        .translate-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .card-modal-translation {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #e9ecef;
        }
        
        .card-modal-translation .translate-btn {
            padding: 8px 16px;
            font-size: 0.9rem;
            border-radius: 6px;
            border: 1px solid var(--primary-color);
            background: var(--surface-color);
            color: var(--primary-color);
            transition: all 0.2s;
        }
        
        .card-modal-translation .translate-btn:hover {
            background: var(--primary-color);
            color: white;
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
            display: block;
            margin: 0 auto; /* Center the image */
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
            cursor: pointer;
        }
        
        .mtg-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        /* Karten-Detail-Modal */
        .card-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 10000;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .card-modal.active {
            opacity: 1;
            visibility: visible;
        }
        
        .card-modal-content {
            background: white;
            border-radius: 15px;
            max-width: 800px;
            max-height: 90vh;
            width: 95%;
            overflow-y: auto;
            position: relative;
            transform: scale(0.9);
            transition: transform 0.3s ease;
        }
        
        .card-modal.active .card-modal-content {
            transform: scale(1);
        }
        
        .card-modal-header {
            padding: 20px 20px 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        
        .card-modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #999;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.2s;
        }
        
        .card-modal-close:hover {
            background: #f0f0f0;
            color: #333;
        }
        
        .card-modal-body {
            padding: 20px;
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 30px;
        }
        
        @media (max-width: 768px) {
            .card-modal-body {
                grid-template-columns: 1fr;
                gap: 20px;
            }
        }
        
        .card-modal-image {
            width: 100%;
            border-radius: 12px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }
        
        .card-modal-details {
            color: #333;
        }
        
        .card-modal-title {
            font-size: 1.8rem;
            font-weight: 700;
            margin: 0 0 10px 0;
            color: var(--primary-color);
        }
        
        .card-modal-mana-cost {
            margin-bottom: 15px;
        }
        
        .card-modal-type {
            font-weight: 600;
            color: #666;
            margin-bottom: 15px;
            font-size: 1.1rem;
        }
        
        .card-modal-text {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            line-height: 1.6;
            border-left: 4px solid var(--primary-color);
        }
        
        .card-modal-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .card-modal-stat {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 8px;
            text-align: center;
        }
        
        .card-modal-stat-label {
            font-size: 0.85rem;
            color: #666;
            margin-bottom: 5px;
        }
        
        .card-modal-stat-value {
            font-weight: 600;
            color: var(--primary-color);
            font-size: 1.1rem;
        }
        
        .card-modal-actions {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 0 0 15px 15px;
            display: flex;
            gap: 15px;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
        }
        
        .quantity-controls {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .quantity-btn {
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 50%;
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            font-weight: bold;
        }
        
        .quantity-btn:hover {
            background: var(--primary-light);
            transform: scale(1.1);
        }
        
        .quantity-display {
            background: white;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 8px 15px;
            font-weight: 600;
            min-width: 60px;
            text-align: center;
        }
        
        .btn-delete {
            background: #dc3545;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            font-weight: 600;
        }
        
        .btn-delete:hover {
            background: #c82333;
            transform: translateY(-1px);
        }
        
        .card-modal-footer-text {
            font-size: 0.9rem;
            color: #666;
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
                         data-card-id="<?php echo $card['id']; ?>"
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
                        <img src="<?php echo htmlspecialchars(getCardImageUrl($card_data)); ?>" 
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
                                    <?php 
                                    $displayText = $userLanguage === 'de' 
                                        ? CardTranslator::translateCardText($card_data['oracle_text'], 'de')
                                        : $card_data['oracle_text'];
                                    echo nl2br(htmlspecialchars($displayText)); 
                                    ?>
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
                                data-card-id="<?php echo $card['id']; ?>"
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
                                    <img src="<?php echo htmlspecialchars(getCardImageUrl($card_data)); ?>" 
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
        
        // Hilfsfunktion um die richtige Bild-URL in JavaScript zu ermitteln
        function getCardImageUrl(cardData) {
            // Direkte image_url (für ältere Karten)
            if (cardData.image_url) {
                return cardData.image_url;
            }
            
            // image_uris Objekt (für neuere Karten aus Scryfall API)
            if (cardData.image_uris && typeof cardData.image_uris === 'object') {
                // Bevorzuge normal, dann small, dann large als Fallback
                if (cardData.image_uris.normal) {
                    return cardData.image_uris.normal;
                } else if (cardData.image_uris.small) {
                    return cardData.image_uris.small;
                } else if (cardData.image_uris.large) {
                    return cardData.image_uris.large;
                }
            }
            
            // Fallback für Karten ohne Bild
            return 'assets/images/card-back.jpg';
        }
        
        // Karten-Detail-Modal Funktionalität
        function openCardModal(cardElement) {
            const cardId = cardElement.dataset.cardId;
            const cardName = cardElement.dataset.name;
            
            // Sammle Kartendaten aus den data-Attributen
            const cardData = {
                id: cardId,
                name: cardName,
                colors: cardElement.dataset.colors,
                type: cardElement.dataset.type,
                rarity: cardElement.dataset.rarity,
                cmc: cardElement.dataset.cmc,
                legendary: cardElement.dataset.legendary === 'true',
                commander: cardElement.dataset.commander === 'true'
            };
            
            // Finde die vollständigen Kartendaten aus dem PHP-Array
            const allCards = <?php echo json_encode($collection); ?>;
            const fullCardData = allCards.find(card => card.id == cardId);
            
            if (!fullCardData) {
                console.error('Kartendaten nicht gefunden');
                return;
            }
            
            const parsedCardData = JSON.parse(fullCardData.card_data);
            
            // Modal HTML generieren
            const modal = document.getElementById('cardModal');
            const modalContent = modal.querySelector('.card-modal-content');
            
            modalContent.innerHTML = `
                <div class="card-modal-header">
                    <div></div>
                    <button class="card-modal-close" onclick="closeCardModal()">&times;</button>
                </div>
                <div class="card-modal-body">
                    <div>
                        <img src="${getCardImageUrl(parsedCardData)}" 
                             alt="${fullCardData.card_name}" 
                             class="card-modal-image">
                    </div>
                    <div class="card-modal-details">
                        <h2 class="card-modal-title">${fullCardData.card_name}</h2>
                        
                        ${parsedCardData.mana_cost ? `
                            <div class="card-modal-mana-cost">
                                ${renderManaCostJS(parsedCardData.mana_cost)}
                            </div>
                        ` : ''}
                        
                        <div class="card-modal-type">${parsedCardData.type_line || 'Unbekannter Typ'}</div>
                        
                        ${parsedCardData.oracle_text ? `
                            <div class="card-modal-text" id="modalCardText">
                                ${parsedCardData.oracle_text.replace(/\\n/g, '<br>')}
                            </div>
                            <div class="card-modal-translation" style="margin-top: 10px;">
                                <button class="translate-btn" 
                                        data-card-id="${fullCardData.id}" 
                                        data-target-lang="de"
                                        data-original-text="${parsedCardData.oracle_text.replace(/"/g, '&quot;')}"
                                        data-original-type="${parsedCardData.type_line ? parsedCardData.type_line.replace(/"/g, '&quot;') : ''}"
                                        id="modalTranslateBtn">
                                    🇩🇪 Auf Deutsch
                                </button>
                            </div>
                        ` : ''}
                        
                        <div class="card-modal-stats">
                            <div class="card-modal-stat">
                                <div class="card-modal-stat-label">Mana-Kosten</div>
                                <div class="card-modal-stat-value">${parsedCardData.cmc || 0}</div>
                            </div>
                            <div class="card-modal-stat">
                                <div class="card-modal-stat-label">Seltenheit</div>
                                <div class="card-modal-stat-value">${getRarityName(parsedCardData.rarity)}</div>
                            </div>
                            ${parsedCardData.power !== undefined && parsedCardData.toughness !== undefined ? `
                                <div class="card-modal-stat">
                                    <div class="card-modal-stat-label">Stärke/Widerstandskraft</div>
                                    <div class="card-modal-stat-value">${parsedCardData.power}/${parsedCardData.toughness}</div>
                                </div>
                            ` : ''}
                            <div class="card-modal-stat">
                                <div class="card-modal-stat-label">Set</div>
                                <div class="card-modal-stat-value">${parsedCardData.set_name || parsedCardData.set || 'Unbekannt'}</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-modal-actions">
                    <div class="quantity-controls">
                        <button class="quantity-btn" id="modalMinusBtn">-</button>
                        <div class="quantity-display" id="modalQuantity">${fullCardData.quantity}x</div>
                        <button class="quantity-btn" id="modalPlusBtn">+</button>
                    </div>
                    <div class="card-modal-footer-text">
                        In Sammlung seit: ${new Date(fullCardData.added_at).toLocaleDateString('de-DE')}
                    </div>
                    <button class="btn-delete" onclick="deleteCard(${fullCardData.id})">
                        🗑️ Aus Sammlung entfernen
                    </button>
                </div>
            `;
            
            // Modal anzeigen
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';

            // Set correct translation button and initial text based on user language preference
            const userLang = '<?php echo $userLanguage; ?>';
            const translateBtn = document.getElementById('modalTranslateBtn');
            const modalCardText = document.getElementById('modalCardText');
            const modalTypeElement = document.querySelector('.card-modal-type');
            
            if (translateBtn) {
                // If user prefers German, show German text and offer English translation
                if (userLang === 'de') {
                    translateBtn.dataset.targetLang = 'en';
                    translateBtn.textContent = '🇺🇸 Auf Englisch';
                    
                    // Translate text to German immediately if we have text
                    if (parsedCardData.oracle_text) {
                        translateCard(fullCardData.id, 'de', true); // true = silent mode
                    }
                } else {
                    translateBtn.dataset.targetLang = 'de';
                    translateBtn.textContent = '🇩🇪 Auf Deutsch';
                }
            }

            // Event-Listener für Plus/Minus Buttons
            setTimeout(() => {
                const minusBtn = document.getElementById('modalMinusBtn');
                const plusBtn = document.getElementById('modalPlusBtn');
                const modalQuantity = document.getElementById('modalQuantity');
                if (minusBtn && plusBtn && modalQuantity) {
                    minusBtn.onclick = function() {
                        let current = parseInt(modalQuantity.textContent);
                        if (isNaN(current)) current = fullCardData.quantity;
                        if (current > 1) {
                            updateCardQuantity(fullCardData.id, current - 1);
                        }
                    };
                    plusBtn.onclick = function() {
                        let current = parseInt(modalQuantity.textContent);
                        if (isNaN(current)) current = fullCardData.quantity;
                        updateCardQuantity(fullCardData.id, current + 1);
                    };
                }
            }, 100);
        }
        
        function closeCardModal() {
            const modal = document.getElementById('cardModal');
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }
        
        function renderManaCostJS(manaCost) {
            // Vereinfachte JS-Version der Mana-Cost-Darstellung
            return manaCost.replace(/\\{([WUBRG0-9XYZ]+)\\}/g, '<span class="mana-symbol mana-$1">$1</span>');
        }
        
        function getRarityName(rarity) {
            const rarityNames = {
                'common': 'Häufig',
                'uncommon': 'Ungewöhnlich', 
                'rare': 'Selten',
                'mythic': 'Sagenhaft',
                'special': 'Speziell',
                'bonus': 'Bonus'
            };
            return rarityNames[rarity] || rarity || 'Unbekannt';
        }
        
        function updateCardQuantity(cardId, newQuantity) {
            if (newQuantity < 0) newQuantity = 0;
            
            // AJAX-Request zur Aktualisierung
            const formData = new FormData();
            formData.append('action', 'update_quantity');
            formData.append('card_id', cardId);
            formData.append('quantity', newQuantity);
            
            fetch('collection.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                // Update Modal-Anzeige
                const modalQuantity = document.getElementById('modalQuantity');
                if (modalQuantity) {
                    if (newQuantity === 0) {
                        closeCardModal();
                        location.reload(); // Seite neu laden um entfernte Karte zu verstecken
                    } else {
                        modalQuantity.textContent = newQuantity + 'x';
                        
                        // Update auch die Karte in der Grid-Ansicht
                        const cardElement = document.querySelector(`[data-card-id="${cardId}"]`);
                        if (cardElement) {
                            const quantityDisplay = cardElement.querySelector('.mtg-card-quantity');
                            if (quantityDisplay) {
                                quantityDisplay.textContent = newQuantity + 'x vorhanden';
                            }
                        }
                    }
                }
            })
            .catch(error => {
                console.error('Fehler beim Aktualisieren der Menge:', error);
                alert('Fehler beim Aktualisieren der Kartenmenge');
            });
        }
        
        function deleteCard(cardId) {
            if (!confirm('Sind Sie sicher, dass Sie diese Karte aus Ihrer Sammlung entfernen möchten?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'delete_card');
            formData.append('card_id', cardId);
            
            fetch('collection.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                closeCardModal();
                location.reload(); // Seite neu laden
            })
            .catch(error => {
                console.error('Fehler beim Löschen der Karte:', error);
                alert('Fehler beim Löschen der Karte');
            });
        }
        
        // Event-Listener für Karten-Klicks hinzufügen
        document.addEventListener('DOMContentLoaded', function() {
            // Füge Click-Listener zu allen Karten hinzu (Grid-Ansicht)
            document.querySelectorAll('.mtg-card').forEach(card => {
                card.addEventListener('click', function(e) {
                    e.preventDefault();
                    openCardModal(this);
                });
            });
            
            // Füge Click-Listener zu allen Tabellenzeilen hinzu
            document.querySelectorAll('.card-row').forEach(row => {
                row.addEventListener('click', function(e) {
                    e.preventDefault();
                    openCardModal(this);
                });
                
                // Füge Cursor-Pointer hinzu
                row.style.cursor = 'pointer';
            });
            
            // Modal schließen bei Klick außerhalb
            document.getElementById('cardModal').addEventListener('click', function(e) {
                if (e.target === this) {
                    closeCardModal();
                }
            });
            
            // Escape-Taste zum Schließen
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeCardModal();
                }
            });
        });

        // Translation functionality
        function translateCard(cardId, targetLang, silent = false) {
            const button = document.querySelector(`[data-card-id="${cardId}"].translate-btn`);
            if (button && !silent) {
                button.disabled = true;
                button.textContent = 'Übersetze...';
            }

            fetch('api/translate.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'translate_card',
                    card_id: cardId,
                    target_lang: targetLang
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update modal text elements
                    const modalCardText = document.getElementById('modalCardText');
                    const modalTypeElement = document.querySelector('.card-modal-type');
                    
                    if (data.translations.oracle_text && modalCardText) {
                        modalCardText.innerHTML = data.translations.oracle_text.replace(/\n/g, '<br>');
                    }
                    
                    if (data.translations.type_line && modalTypeElement) {
                        modalTypeElement.textContent = data.translations.type_line;
                    }
                    
                    // Update button
                    const newTargetLang = targetLang === 'en' ? 'de' : 'en';
                    const newButtonText = targetLang === 'en' ? '�� Auf Englisch' : '�� Auf Deutsch';
                    
                    if (button) {
                        button.textContent = newButtonText;
                        button.dataset.targetLang = newTargetLang;
                        button.disabled = false;
                    }
                } else {
                    console.error('Translation error:', data.error);
                    if (button && !silent) {
                        button.disabled = false;
                        const originalText = targetLang === 'en' ? '🇩🇪 Auf Deutsch' : '🇺🇸 Auf Englisch';
                        button.textContent = originalText;
                    }
                }
            })
            .catch(error => {
                console.error('Network error:', error);
                if (button && !silent) {
                    button.disabled = false;
                    const originalText = targetLang === 'en' ? '🇩🇪 Auf Deutsch' : '🇺🇸 Auf Englisch';
                    button.textContent = originalText;
                }
            });
        }

        // Add click handlers for translate buttons
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('translate-btn')) {
                e.preventDefault();
                const cardId = e.target.dataset.cardId;
                const targetLang = e.target.dataset.targetLang;
                translateCard(cardId, targetLang);
            }
        });
    </script>
    
    <!-- Karten-Detail-Modal -->
    <div id="cardModal" class="card-modal">
        <div class="card-modal-content">
            <!-- Wird dynamisch mit JavaScript gefüllt -->
        </div>
    </div>
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
