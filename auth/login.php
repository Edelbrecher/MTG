<?php
session_start();
require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    
    if (empty($email) || empty($password)) {
        header('Location: ../index.php?error=Bitte alle Felder ausfüllen');
        exit();
    }
    
    try {
        $stmt = $pdo->prepare("SELECT id, username, password_hash, is_admin FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['is_admin'] = $user['is_admin'];
            
            header('Location: ../dashboard.php');
            exit();
        } else {
            header('Location: ../index.php?error=Ungültige Anmeldedaten');
            exit();
        }
    } catch (PDOException $e) {
        header('Location: ../index.php?error=Datenbankfehler');
        exit();
    }
} else {
    header('Location: ../index.php');
    exit();
}
?>
