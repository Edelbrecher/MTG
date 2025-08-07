<nav class="navbar">
    <div class="container">
        <div class="navbar-content">
            <?php
            // Basisverzeichnis automatisch erkennen
            $base = dirname($_SERVER['PHP_SELF']);
            $isAdmin = strpos($base, '/admin') !== false;
            $prefix = $isAdmin ? '../' : '';
            $current = basename($_SERVER['PHP_SELF']);
            function navActive($file, $current) {
                return $file === $current ? 'active' : '';
            }
            ?>
            <a href="<?php echo $prefix; ?>dashboard.php" class="navbar-brand">MTG Collection Manager</a>
            <ul class="navbar-nav">
                <li><a href="<?php echo $prefix; ?>dashboard.php" class="<?php echo navActive('dashboard.php', $current); ?>">Dashboard</a></li>
                <li class="dropdown">
                    <a href="#" class="<?php echo in_array($current, ['collection.php', 'new_deck.php', 'rate_it.php']) ? 'active' : ''; ?> dropdown-toggle" onclick="return false;">
                        Sammlung <span class="dropdown-arrow">▼</span>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a href="<?php echo $prefix; ?>collection.php">📚 Meine Sammlung</a></li>
                        <li><a href="<?php echo $prefix; ?>new_deck.php">🎯 New Deck</a></li>
                        <li><a href="<?php echo $prefix; ?>rate_it.php">⭐ Rate-It</a></li>
                    </ul>
                </li>
                <li><a href="<?php echo $prefix; ?>bulk_import.php" class="<?php echo navActive('bulk_import.php', $current); ?>">📦 Bulk-Import</a></li>
                <li><a href="<?php echo $prefix; ?>decks.php" class="<?php echo navActive('decks.php', $current); ?>">Decks</a></li>
                <li><a href="<?php echo $prefix; ?>settings.php" class="<?php echo navActive('settings.php', $current); ?>">Einstellungen</a></li>
                <li><a href="<?php echo $prefix; ?>profile.php" class="<?php echo navActive('profile.php', $current); ?>">👤 Profil</a></li>
                <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']): ?>
                <li><a href="<?php echo $prefix; ?>admin/index.php" class="<?php echo navActive('index.php', $current) && $isAdmin ? 'active' : ''; ?>">Admin</a></li>
                <?php endif; ?>
            </ul>
            <div class="navbar-actions">
                <a href="<?php echo $prefix; ?>auth/logout.php" class="logout-btn" title="Abmelden">Abmelden</a>
            </div>
        </div>
    </div>
</nav>

<script>
// Standard dropdown functionality
document.addEventListener('DOMContentLoaded', function() {
    const dropdownToggles = document.querySelectorAll('.dropdown-toggle');
    
    dropdownToggles.forEach(function(toggle) {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const dropdown = this.closest('.dropdown');
            const isOpen = dropdown.classList.contains('open');
            
            // Close all other dropdowns
            document.querySelectorAll('.dropdown').forEach(function(d) {
                d.classList.remove('open');
            });
            
            // Toggle current dropdown
            if (!isOpen) {
                dropdown.classList.add('open');
            }
        });
    });
    
    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.dropdown')) {
            document.querySelectorAll('.dropdown').forEach(function(dropdown) {
                dropdown.classList.remove('open');
            });
        }
    });
    
    // Close dropdown when pressing Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.dropdown').forEach(function(dropdown) {
                dropdown.classList.remove('open');
            });
        }
    });
});
</script>
