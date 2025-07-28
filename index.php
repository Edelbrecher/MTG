<?php
session_start();

// Test der Datenbankverbindung
try {
    require_once 'config/database.php';
} catch (Exception $e) {
    die("Datenbankverbindung fehlgeschlagen. Bitte überprüfen Sie XAMPP MySQL.");
}

// Redirect to dashboard if logged in
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MTG Collection Manager</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="container">
        <div class="auth-container">
            <div class="auth-header">
                <h1>MTG Collection Manager</h1>
                <p>Verwalten Sie Ihre Magic: The Gathering Kartensammlung</p>
            </div>
            
            <div class="auth-tabs">
                <button class="tab-btn active" onclick="showLogin()">Anmelden</button>
                <button class="tab-btn" onclick="showRegister()">Registrieren</button>
            </div>

            <!-- Login Form -->
            <div id="login-form" class="auth-form">
                <form action="auth/login.php" method="POST">
                    <div class="form-group">
                        <label for="email">E-Mail</label>
                        <input type="email" id="email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label for="password">Passwort</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Anmelden</button>
                </form>
            </div>

            <!-- Register Form -->
            <div id="register-form" class="auth-form" style="display: none;">
                <form action="auth/register.php" method="POST">
                    <div class="form-group">
                        <label for="reg-username">Benutzername</label>
                        <input type="text" id="reg-username" name="username" required>
                    </div>
                    <div class="form-group">
                        <label for="reg-email">E-Mail</label>
                        <input type="email" id="reg-email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label for="reg-password">Passwort</label>
                        <input type="password" id="reg-password" name="password" required>
                    </div>
                    <div class="form-group">
                        <label for="reg-password-confirm">Passwort bestätigen</label>
                        <input type="password" id="reg-password-confirm" name="password_confirm" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Registrieren</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function showLogin() {
            document.getElementById('login-form').style.display = 'block';
            document.getElementById('register-form').style.display = 'none';
            document.querySelectorAll('.tab-btn')[0].classList.add('active');
            document.querySelectorAll('.tab-btn')[1].classList.remove('active');
        }

        function showRegister() {
            document.getElementById('login-form').style.display = 'none';
            document.getElementById('register-form').style.display = 'block';
            document.querySelectorAll('.tab-btn')[0].classList.remove('active');
            document.querySelectorAll('.tab-btn')[1].classList.add('active');
        }
    </script>
</body>
</html>
