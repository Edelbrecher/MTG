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
                <li><a href="<?php echo $prefix; ?>collection.php" class="<?php echo navActive('collection.php', $current); ?>">Sammlung</a></li>
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
