<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || !$_SESSION['is_admin']) {
    header('Location: ../index.php');
    exit();
}

$success_message = '';
$error_message = '';

// Handle form submissions
if ($_POST) {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'update_rules':
                    $format = $_POST['format'];
                    $rules = $_POST['rules'];
                    
                    // Update or insert format rules
                    $stmt = $pdo->prepare("
                        INSERT INTO format_rules (format_name, rules_json, updated_at) 
                        VALUES (?, ?, NOW()) 
                        ON DUPLICATE KEY UPDATE 
                        rules_json = VALUES(rules_json), 
                        updated_at = NOW()
                    ");
                    $stmt->execute([$format, json_encode($rules)]);
                    
                    $success_message = "Regelwerk für {$format} wurde erfolgreich aktualisiert!";
                    break;
                    
                case 'create_custom_format':
                    $name = trim($_POST['custom_name']);
                    $rules = $_POST['custom_rules'];
                    
                    if (empty($name)) {
                        throw new Exception('Format-Name ist erforderlich');
                    }
                    
                    // Check if format already exists
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM format_rules WHERE format_name = ?");
                    $stmt->execute([$name]);
                    if ($stmt->fetchColumn() > 0) {
                        throw new Exception('Ein Format mit diesem Namen existiert bereits');
                    }
                    
                    // Insert custom format
                    $stmt = $pdo->prepare("
                        INSERT INTO format_rules (format_name, rules_json, is_custom, created_at, updated_at) 
                        VALUES (?, ?, 1, NOW(), NOW())
                    ");
                    $stmt->execute([$name, json_encode($rules)]);
                    
                    $success_message = "Sonderformat '{$name}' wurde erfolgreich erstellt!";
                    break;
                    
                case 'delete_format':
                    $format = $_POST['format'];
                    
                    $stmt = $pdo->prepare("DELETE FROM format_rules WHERE format_name = ? AND is_custom = 1");
                    $stmt->execute([$format]);
                    
                    $success_message = "Sonderformat '{$format}' wurde gelöscht!";
                    break;
            }
        }
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Load existing format rules
try {
    $stmt = $pdo->query("SELECT * FROM format_rules ORDER BY is_custom ASC, format_name ASC");
    $format_rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $format_rules = [];
}

// Default rules for standard formats
$default_formats = [
    'Standard' => [
        'deck_size' => 60,
        'min_deck_size' => 60,
        'max_deck_size' => null,
        'singleton' => false,
        'commander_required' => false,
        'starting_life' => 20,
        'max_copies' => 4,
        'description' => 'Das aktuelle Standard-Format mit Karten der letzten ~2 Jahre.',
        'rules' => [
            'Mindestens 60 Karten im Hauptdeck',
            'Maximal 4 Kopien jeder Karte (außer Standardländern)',
            '15-Karten Sideboard erlaubt',
            'Nur Karten aus den aktuellen Standard-Sets',
            '20 Startlebenspunkte'
        ]
    ],
    'Modern' => [
        'deck_size' => 60,
        'min_deck_size' => 60,
        'max_deck_size' => null,
        'singleton' => false,
        'commander_required' => false,
        'starting_life' => 20,
        'max_copies' => 4,
        'description' => 'Ewiges Format mit Karten ab 8th Edition/Mirrodin.',
        'rules' => [
            'Mindestens 60 Karten im Hauptdeck',
            'Maximal 4 Kopien jeder Karte (außer Standardländern)',
            '15-Karten Sideboard erlaubt',
            'Karten ab 8th Edition/Mirrodin erlaubt',
            '20 Startlebenspunkte',
            'Eigene Banned List'
        ]
    ],
    'Commander' => [
        'deck_size' => 100,
        'min_deck_size' => 100,
        'max_deck_size' => 100,
        'singleton' => true,
        'commander_required' => true,
        'starting_life' => 40,
        'max_copies' => 1,
        'description' => 'Multiplayer-Format mit 100-Karten Singleton-Decks.',
        'rules' => [
            'Genau 100 Karten (inklusive Commander)',
            'Genau 1 Commander (Legendary Creature oder Planeswalker)',
            'Alle Karten sind Singleton (maximal 1 Kopie)',
            'Farbidentität des Commanders bestimmt erlaubte Farben',
            '40 Startlebenspunkte',
            'Commander-Schaden: 21 Schaden = eliminiert'
        ]
    ],
    'Pioneer' => [
        'deck_size' => 60,
        'min_deck_size' => 60,
        'max_deck_size' => null,
        'singleton' => false,
        'commander_required' => false,
        'starting_life' => 20,
        'max_copies' => 4,
        'description' => 'Non-rotating Format ab Return to Ravnica.',
        'rules' => [
            'Mindestens 60 Karten im Hauptdeck',
            'Maximal 4 Kopien jeder Karte (außer Standardländern)',
            '15-Karten Sideboard erlaubt',
            'Karten ab Return to Ravnica erlaubt',
            '20 Startlebenspunkte'
        ]
    ],
    'Legacy' => [
        'deck_size' => 60,
        'min_deck_size' => 60,
        'max_deck_size' => null,
        'singleton' => false,
        'commander_required' => false,
        'starting_life' => 20,
        'max_copies' => 4,
        'description' => 'Ewiges Format mit fast allen Magic-Karten.',
        'rules' => [
            'Mindestens 60 Karten im Hauptdeck',
            'Maximal 4 Kopien jeder Karte (außer Standardländern)',
            '15-Karten Sideboard erlaubt',
            'Fast alle Magic-Karten erlaubt',
            '20 Startlebenspunkte',
            'Extensive Banned/Restricted List'
        ]
    ],
    'Vintage' => [
        'deck_size' => 60,
        'min_deck_size' => 60,
        'max_deck_size' => null,
        'singleton' => false,
        'commander_required' => false,
        'starting_life' => 20,
        'max_copies' => 4,
        'description' => 'Das mächtigste Format - fast alles ist erlaubt.',
        'rules' => [
            'Mindestens 60 Karten im Hauptdeck',
            'Maximal 4 Kopien jeder Karte (außer Standardländern)',
            '15-Karten Sideboard erlaubt',
            'Alle Magic-Karten erlaubt (außer Un-Sets)',
            '20 Startlebenspunkte',
            'Restricted List statt Banned List'
        ]
    ],
    'Pauper' => [
        'deck_size' => 60,
        'min_deck_size' => 60,
        'max_deck_size' => null,
        'singleton' => false,
        'commander_required' => false,
        'starting_life' => 20,
        'max_copies' => 4,
        'description' => 'Nur Common-Karten erlaubt.',
        'rules' => [
            'Mindestens 60 Karten im Hauptdeck',
            'Maximal 4 Kopien jeder Karte (außer Standardländern)',
            '15-Karten Sideboard erlaubt',
            'Nur Karten mit Common-Seltenheit',
            '20 Startlebenspunkte'
        ]
    ]
];

include '../includes/navbar.php';
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Format-Regelwerk Verwaltung - Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .rules-editor {
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .rules-list {
            list-style: none;
            padding: 0;
        }
        
        .rules-list li {
            background: white;
            margin-bottom: 10px;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #667eea;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .format-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            margin-bottom: 25px;
        }
        
        .format-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e9ecef;
        }
        
        .custom-format {
            border-left: 5px solid #e74c3c;
        }
        
        .standard-format {
            border-left: 5px solid #667eea;
        }
        
        .rule-input {
            margin-bottom: 10px;
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .rule-input input {
            flex: 1;
        }
        
        .add-rule-btn, .remove-rule-btn {
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
        }
        
        .add-rule-btn {
            background: #2ecc71;
            color: white;
        }
        
        .remove-rule-btn {
            background: #e74c3c;
            color: white;
        }
        
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="main-content">
            <div class="page-header">
                <h1><i class="fas fa-book-open"></i> Format-Regelwerk Verwaltung</h1>
                <p>Verwalten Sie die Regelwerke aller Formate und erstellen Sie Sonderformate</p>
            </div>

            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>

            <!-- Create Custom Format -->
            <div class="format-card custom-format">
                <div class="format-header">
                    <h3><i class="fas fa-plus-circle"></i> Neues Sonderformat erstellen</h3>
                </div>
                
                <form method="post">
                    <input type="hidden" name="action" value="create_custom_format">
                    
                    <div class="form-group">
                        <label><i class="fas fa-tag"></i> Format-Name</label>
                        <input type="text" name="custom_name" class="form-control" placeholder="z.B. Highlander, Cube, Peasant..." required>
                    </div>
                    
                    <div class="settings-grid">
                        <div class="form-group">
                            <label>Deck-Größe</label>
                            <input type="number" name="custom_rules[deck_size]" class="form-control" value="60" min="1">
                        </div>
                        <div class="form-group">
                            <label>Min. Deck-Größe</label>
                            <input type="number" name="custom_rules[min_deck_size]" class="form-control" value="60" min="1">
                        </div>
                        <div class="form-group">
                            <label>Max. Deck-Größe</label>
                            <input type="number" name="custom_rules[max_deck_size]" class="form-control" placeholder="Leer = unbegrenzt">
                        </div>
                        <div class="form-group">
                            <label>Startlebenspunkte</label>
                            <input type="number" name="custom_rules[starting_life]" class="form-control" value="20" min="1">
                        </div>
                        <div class="form-group">
                            <label>Max. Kopien pro Karte</label>
                            <input type="number" name="custom_rules[max_copies]" class="form-control" value="4" min="1">
                        </div>
                        <div class="form-group">
                            <label style="display: flex; align-items: center; gap: 10px;">
                                <input type="checkbox" name="custom_rules[singleton]" value="1">
                                Singleton (nur 1 Kopie)
                            </label>
                        </div>
                        <div class="form-group">
                            <label style="display: flex; align-items: center; gap: 10px;">
                                <input type="checkbox" name="custom_rules[commander_required]" value="1">
                                Commander erforderlich
                            </label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-info-circle"></i> Beschreibung</label>
                        <textarea name="custom_rules[description]" class="form-control" rows="3" 
                                  placeholder="Kurze Beschreibung des Formats..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-list"></i> Regeln</label>
                        <div id="customRulesContainer">
                            <div class="rule-input">
                                <input type="text" name="custom_rules[rules][]" class="form-control" 
                                       placeholder="Regel eingeben...">
                                <button type="button" class="add-rule-btn" onclick="addRule('customRulesContainer')">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-plus"></i> Sonderformat erstellen
                    </button>
                </form>
            </div>

            <!-- Existing Formats -->
            <?php foreach ($default_formats as $format_name => $default_rules): 
                $existing_rules = null;
                foreach ($format_rules as $rule) {
                    if ($rule['format_name'] === $format_name) {
                        $existing_rules = json_decode($rule['rules_json'], true);
                        break;
                    }
                }
                $current_rules = $existing_rules ?: $default_rules;
            ?>
                <div class="format-card standard-format">
                    <div class="format-header">
                        <h3><i class="fas fa-gamepad"></i> <?= htmlspecialchars($format_name) ?></h3>
                        <small style="color: #7f8c8d;">Standard-Format</small>
                    </div>
                    
                    <form method="post">
                        <input type="hidden" name="action" value="update_rules">
                        <input type="hidden" name="format" value="<?= htmlspecialchars($format_name) ?>">
                        
                        <div class="settings-grid">
                            <div class="form-group">
                                <label>Deck-Größe</label>
                                <input type="number" name="rules[deck_size]" class="form-control" 
                                       value="<?= $current_rules['deck_size'] ?>" min="1">
                            </div>
                            <div class="form-group">
                                <label>Min. Deck-Größe</label>
                                <input type="number" name="rules[min_deck_size]" class="form-control" 
                                       value="<?= $current_rules['min_deck_size'] ?>" min="1">
                            </div>
                            <div class="form-group">
                                <label>Max. Deck-Größe</label>
                                <input type="number" name="rules[max_deck_size]" class="form-control" 
                                       value="<?= $current_rules['max_deck_size'] ?: '' ?>" placeholder="Unbegrenzt">
                            </div>
                            <div class="form-group">
                                <label>Startlebenspunkte</label>
                                <input type="number" name="rules[starting_life]" class="form-control" 
                                       value="<?= $current_rules['starting_life'] ?>" min="1">
                            </div>
                            <div class="form-group">
                                <label>Max. Kopien pro Karte</label>
                                <input type="number" name="rules[max_copies]" class="form-control" 
                                       value="<?= $current_rules['max_copies'] ?>" min="1">
                            </div>
                            <div class="form-group">
                                <label style="display: flex; align-items: center; gap: 10px;">
                                    <input type="checkbox" name="rules[singleton]" value="1" 
                                           <?= !empty($current_rules['singleton']) ? 'checked' : '' ?>>
                                    Singleton (nur 1 Kopie)
                                </label>
                            </div>
                            <div class="form-group">
                                <label style="display: flex; align-items: center; gap: 10px;">
                                    <input type="checkbox" name="rules[commander_required]" value="1" 
                                           <?= !empty($current_rules['commander_required']) ? 'checked' : '' ?>>
                                    Commander erforderlich
                                </label>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-info-circle"></i> Beschreibung</label>
                            <textarea name="rules[description]" class="form-control" rows="3"><?= htmlspecialchars($current_rules['description'] ?? '') ?></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-list"></i> Regeln</label>
                            <div id="rulesContainer<?= $format_name ?>">
                                <?php foreach ($current_rules['rules'] as $rule): ?>
                                    <div class="rule-input">
                                        <input type="text" name="rules[rules][]" class="form-control" 
                                               value="<?= htmlspecialchars($rule) ?>">
                                        <button type="button" class="remove-rule-btn" onclick="removeRule(this)">
                                            <i class="fas fa-minus"></i>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                                <div class="rule-input">
                                    <input type="text" name="rules[rules][]" class="form-control" 
                                           placeholder="Neue Regel hinzufügen...">
                                    <button type="button" class="add-rule-btn" onclick="addRule('rulesContainer<?= $format_name ?>')">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Regelwerk speichern
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>

            <!-- Custom Formats -->
            <?php foreach ($format_rules as $rule): 
                if (!$rule['is_custom']) continue;
                $custom_rules = json_decode($rule['rules_json'], true);
            ?>
                <div class="format-card custom-format">
                    <div class="format-header">
                        <h3><i class="fas fa-star"></i> <?= htmlspecialchars($rule['format_name']) ?></h3>
                        <div>
                            <small style="color: #e74c3c; margin-right: 15px;">Sonderformat</small>
                            <form method="post" style="display: inline;">
                                <input type="hidden" name="action" value="delete_format">
                                <input type="hidden" name="format" value="<?= htmlspecialchars($rule['format_name']) ?>">
                                <button type="submit" class="btn btn-danger btn-sm" 
                                        onclick="return confirm('Sonderformat wirklich löschen?')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <form method="post">
                        <input type="hidden" name="action" value="update_rules">
                        <input type="hidden" name="format" value="<?= htmlspecialchars($rule['format_name']) ?>">
                        
                        <div class="settings-grid">
                            <div class="form-group">
                                <label>Deck-Größe</label>
                                <input type="number" name="rules[deck_size]" class="form-control" 
                                       value="<?= $custom_rules['deck_size'] ?? 60 ?>" min="1">
                            </div>
                            <div class="form-group">
                                <label>Min. Deck-Größe</label>
                                <input type="number" name="rules[min_deck_size]" class="form-control" 
                                       value="<?= $custom_rules['min_deck_size'] ?? 60 ?>" min="1">
                            </div>
                            <div class="form-group">
                                <label>Max. Deck-Größe</label>
                                <input type="number" name="rules[max_deck_size]" class="form-control" 
                                       value="<?= $custom_rules['max_deck_size'] ?: '' ?>" placeholder="Unbegrenzt">
                            </div>
                            <div class="form-group">
                                <label>Startlebenspunkte</label>
                                <input type="number" name="rules[starting_life]" class="form-control" 
                                       value="<?= $custom_rules['starting_life'] ?? 20 ?>" min="1">
                            </div>
                            <div class="form-group">
                                <label>Max. Kopien pro Karte</label>
                                <input type="number" name="rules[max_copies]" class="form-control" 
                                       value="<?= $custom_rules['max_copies'] ?? 4 ?>" min="1">
                            </div>
                            <div class="form-group">
                                <label style="display: flex; align-items: center; gap: 10px;">
                                    <input type="checkbox" name="rules[singleton]" value="1" 
                                           <?= !empty($custom_rules['singleton']) ? 'checked' : '' ?>>
                                    Singleton (nur 1 Kopie)
                                </label>
                            </div>
                            <div class="form-group">
                                <label style="display: flex; align-items: center; gap: 10px;">
                                    <input type="checkbox" name="rules[commander_required]" value="1" 
                                           <?= !empty($custom_rules['commander_required']) ? 'checked' : '' ?>>
                                    Commander erforderlich
                                </label>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-info-circle"></i> Beschreibung</label>
                            <textarea name="rules[description]" class="form-control" rows="3"><?= htmlspecialchars($custom_rules['description'] ?? '') ?></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-list"></i> Regeln</label>
                            <div id="rulesContainer<?= $rule['format_name'] ?>">
                                <?php if (!empty($custom_rules['rules'])): ?>
                                    <?php foreach ($custom_rules['rules'] as $custom_rule): ?>
                                        <div class="rule-input">
                                            <input type="text" name="rules[rules][]" class="form-control" 
                                                   value="<?= htmlspecialchars($custom_rule) ?>">
                                            <button type="button" class="remove-rule-btn" onclick="removeRule(this)">
                                                <i class="fas fa-minus"></i>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <div class="rule-input">
                                    <input type="text" name="rules[rules][]" class="form-control" 
                                           placeholder="Neue Regel hinzufügen...">
                                    <button type="button" class="add-rule-btn" onclick="addRule('rulesContainer<?= $rule['format_name'] ?>')">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Sonderformat speichern
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
        function addRule(containerId) {
            const container = document.getElementById(containerId);
            const newRule = document.createElement('div');
            newRule.className = 'rule-input';
            newRule.innerHTML = `
                <input type="text" name="${containerId.includes('custom') ? 'custom_rules[rules][]' : 'rules[rules][]'}" 
                       class="form-control" placeholder="Regel eingeben...">
                <button type="button" class="remove-rule-btn" onclick="removeRule(this)">
                    <i class="fas fa-minus"></i>
                </button>
            `;
            
            // Insert before the last child (the add button row)
            container.insertBefore(newRule, container.lastElementChild);
        }

        function removeRule(button) {
            button.parentElement.remove();
        }
    </script>
</body>
</html>
