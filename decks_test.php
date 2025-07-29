<?php
// Temporäre Version ohne Login-Zwang für Tests
session_start();

// Prüfe ob Datenbank-Verbindung funktioniert
try {
    require_once 'config/database.php';
    $db_connected = true;
} catch (Exception $e) {
    $db_connected = false;
    $db_error = $e->getMessage();
}

// Fake user session für Tests
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1; // Dummy User ID
    $_SESSION['username'] = 'test_user';
}

$success_message = '';
$existing_decks = [];

// Handle deck creation nur wenn DB verbunden
if ($db_connected && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create_deck') {
        $name = trim($_POST['name']);
        $format = $_POST['format'];
        $strategy = $_POST['strategy'] ?? '';
        
        if (!empty($name) && !empty($format)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO decks (name, format_type, user_id, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$name, $format, $_SESSION['user_id']]);
                $success_message = "Deck wurde erfolgreich erstellt!";
            } catch (Exception $e) {
                $success_message = "Fehler beim Erstellen: " . $e->getMessage();
            }
        }
    }
}

// Get existing decks nur wenn DB verbunden
if ($db_connected) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM decks WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->execute([$_SESSION['user_id']]);
        $existing_decks = $stmt->fetchAll();
    } catch (Exception $e) {
        // Ignore errors for test
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Deck Builder - MTG Collection Manager (TEST VERSION)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .deck-builder-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .strategy-btn {
            width: 100%;
            height: 80px;
            margin-bottom: 10px;
            border-radius: 10px;
            border: 2px solid transparent;
            transition: all 0.3s ease;
        }
        .strategy-btn.active {
            border-color: #667eea;
            background-color: #667eea;
            color: white;
        }
        .strategy-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        .ai-features {
            background: linear-gradient(135deg, #ffeaa7 0%, #fab1a0 100%);
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="#">MTG Collection Manager (TEST)</a>
        </div>
    </nav>
    
    <div class="container mt-4">
        <?php if (!$db_connected): ?>
            <div class="alert alert-danger">
                <strong>Datenbankfehler:</strong> <?= htmlspecialchars($db_error ?? 'Unbekannter Fehler') ?>
            </div>
        <?php endif; ?>
        
        <div class="alert alert-warning">
            <strong>TEST VERSION:</strong> Diese Version funktioniert ohne Login. 
            <a href="auth/login.php">Zum normalen Login</a>
        </div>
        
        <div class="row">
            <!-- Left Column: Deck Builder -->
            <div class="col-md-6">
                <div class="card deck-builder-card">
                    <div class="card-body">
                        <h3><i class="fas fa-brain"></i> Intelligenter Deck Builder</h3>
                        
                        <?php if (isset($success_message)): ?>
                            <div class="alert alert-success"><?= $success_message ?></div>
                        <?php endif; ?>
                        
                        <form method="post" id="deckBuilderForm">
                            <input type="hidden" name="action" value="create_deck">
                            
                            <!-- Format Selection -->
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-layer-group"></i> Format wählen</label>
                                <select name="format" class="form-select" required>
                                    <option value="">-- Format wählen --</option>
                                    <option value="Standard">Standard</option>
                                    <option value="Modern">Modern</option>
                                    <option value="Legacy">Legacy</option>
                                    <option value="Commander">Commander</option>
                                    <option value="Casual">Casual</option>
                                </select>
                            </div>
                            
                            <!-- Strategy Selection -->
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-chess"></i> Deck-Strategie</label>
                                <div class="row">
                                    <div class="col-6">
                                        <button type="button" class="btn btn-outline-light strategy-btn" data-strategy="Aggro">
                                            <i class="fas fa-fire"></i><br>
                                            <strong>Aggro</strong><br>
                                            <small>Schnell, aggressiv</small>
                                        </button>
                                    </div>
                                    <div class="col-6">
                                        <button type="button" class="btn btn-outline-light strategy-btn" data-strategy="Control">
                                            <i class="fas fa-shield-alt"></i><br>
                                            <strong>Control</strong><br>
                                            <small>Defensive</small>
                                        </button>
                                    </div>
                                </div>
                                <input type="hidden" name="strategy" id="selectedStrategy">
                            </div>
                            
                            <!-- AI Features Info -->
                            <div class="ai-features">
                                <h6><i class="fas fa-robot"></i> AI-Features</h6>
                                <ul class="mb-0">
                                    <li>Automatische Mana-Kurve Optimierung</li>
                                    <li>Synergie-Analyse zwischen Karten</li>
                                    <li>Meta-Game Abgleich</li>
                                    <li>Format-spezifische Optimierungen</li>
                                </ul>
                            </div>
                            
                            <!-- Deck Name -->
                            <div class="mb-3">
                                <label class="form-label">Deck-Name</label>
                                <input type="text" name="name" class="form-control" placeholder="Mein neues Deck" required>
                            </div>
                            
                            <button type="submit" class="btn btn-warning btn-lg w-100">
                                <i class="fas fa-magic"></i> Optimiertes Deck generieren
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Right Column: Existing Decks -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-layer-group"></i> Ihre Decks</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($existing_decks)): ?>
                            <div class="text-center text-muted">
                                <i class="fas fa-plus-circle fa-3x mb-3"></i>
                                <p>Noch keine Decks erstellt</p>
                                <p class="small">Nutzen Sie den Deck Builder links!</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($existing_decks as $deck): ?>
                                <div class="card mb-3">
                                    <div class="card-body">
                                        <h6><?= htmlspecialchars($deck['name']) ?></h6>
                                        <span class="badge bg-primary"><?= htmlspecialchars($deck['format_type']) ?></span>
                                        <?php if ($deck['strategy']): ?>
                                            <span class="badge bg-secondary"><?= htmlspecialchars($deck['strategy']) ?></span>
                                        <?php endif; ?>
                                        <p class="small text-muted mt-2">
                                            Erstellt: <?= date('d.m.Y', strtotime($deck['created_at'])) ?>
                                        </p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Strategy selection handling
        document.querySelectorAll('.strategy-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                document.querySelectorAll('.strategy-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                document.getElementById('selectedStrategy').value = this.dataset.strategy;
            });
        });
    </script>
</body>
</html>
