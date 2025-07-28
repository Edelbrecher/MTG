<nav class="navbar">
    <div class="container">
        <div class="navbar-content">
            <a href="dashboard.php" class="navbar-brand">MTG Collection Manager</a>
            
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : ''; ?>">Dashboard</a></li>
                <li><a href="collection.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'collection.php' ? 'active' : ''; ?>">Sammlung</a></li>
                <li><a href="bulk_import.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'bulk_import.php' ? 'active' : ''; ?>">📦 Bulk-Import</a></li>
                <li><a href="decks.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'decks.php' ? 'active' : ''; ?>">Decks</a></li>
                <li><a href="settings.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'settings.php' ? 'active' : ''; ?>">Einstellungen</a></li>
                <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']): ?>
                <li><a href="admin/" class="<?php echo strpos($_SERVER['PHP_SELF'], 'admin/') !== false ? 'active' : ''; ?>">Admin</a></li>
                <?php endif; ?>
                <li><a href="auth/logout.php">Abmelden</a></li>
            </ul>
        </div>
    </div>
</nav>
