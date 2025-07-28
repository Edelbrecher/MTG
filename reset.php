<?php
session_start();
session_destroy();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Session Reset</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; text-align: center; }
        .box { background: #f5f5f5; padding: 20px; border-radius: 10px; max-width: 500px; margin: 0 auto; }
        .btn { background: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="box">
        <h1>🔄 Session Reset</h1>
        <p>Die Session wurde zurückgesetzt.</p>
        <p><strong>Jetzt können Sie sich neu anmelden:</strong></p>
        
        <h3>Admin-Login-Daten:</h3>
        <p><strong>E-Mail:</strong> admin@mtg.local</p>
        <p><strong>Passwort:</strong> admin123</p>
        
        <p><a href="index.php" class="btn">→ Zur Anmeldung</a></p>
        <p><a href="test.php" class="btn">→ System-Test</a></p>
        <p><a href="setup.php" class="btn">→ Setup ausführen</a></p>
    </div>
</body>
</html>
