<?php
session_start();
require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $password_confirm = $_POST['password_confirm'];
    
    // Validation
    if (empty($username) || empty($email) || empty($password) || empty($password_confirm)) {
        header('Location: ../index.php?error=Bitte alle Felder ausfüllen');
        exit();
    }
    
    if ($password !== $password_confirm) {
        header('Location: ../index.php?error=Passwörter stimmen nicht überein');
        exit();
    }
    
    if (strlen($password) < 6) {
        header('Location: ../index.php?error=Passwort muss mindestens 6 Zeichen lang sein');
        exit();
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header('Location: ../index.php?error=Ungültige E-Mail-Adresse');
        exit();
    }
    
    try {
        // Check if username or email already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        
        if ($stmt->fetch()) {
            header('Location: ../index.php?error=Benutzername oder E-Mail bereits vergeben');
            exit();
        }
        
        // Create new user
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
        $stmt->execute([$username, $email, $password_hash]);
        
        $user_id = $pdo->lastInsertId();
        
        // Log in the user
        $_SESSION['user_id'] = $user_id;
        $_SESSION['username'] = $username;
        $_SESSION['is_admin'] = false;
        
        header('Location: ../dashboard.php');
        exit();
        
    } catch (PDOException $e) {
        header('Location: ../index.php?error=Registrierung fehlgeschlagen');
        exit();
    }
} else {
    header('Location: ../index.php');
    exit();
}
?>
