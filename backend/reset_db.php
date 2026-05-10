<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

$password = '12345'; // Cambia esto por una contraseña super segura
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['pass'] ?? '') === $password) {
        $db = new LotteryDB();
        $db->resetAll();
        $msg = "<div style='color: #10b981; font-weight: bold; margin-bottom: 20px;'>Base de datos reseteada con éxito.</div>";
    } else {
        $msg = "<div style='color: #ef4444; font-weight: bold; margin-bottom: 20px;'>Contraseña incorrecta.</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Seguro de Reset</title>
</head>
<body style="background: #0f0f1a; color: #e2e8f0; font-family: sans-serif; text-align: center; padding: 50px;">
    <h2>⚠️ Borrar Base de Datos ⚠️</h2>
    <p style="color: #94a3b8; margin-bottom: 30px;">Esta acción eliminará de forma irreversible todos los sorteos guardados en MySQL.</p>
    
    <?= $msg ?>
    
    <form method="POST">
        <input type="password" name="pass" placeholder="Contraseña de administrador" required 
               style="padding: 12px; width: 100%; max-width: 300px; margin-bottom: 20px; border-radius: 8px; border: 1px solid #2d2d4a; background: #1a1a2e; color: white;"><br>
        
        <button type="submit" 
                style="padding: 12px 24px; background: rgba(239,68,68,0.2); color: #f87171; border: 1px solid rgba(239,68,68,0.4); border-radius: 8px; cursor: pointer; font-weight: bold;">
            Ejecutar Reset Completo
        </button>
    </form>
</body>
</html>
