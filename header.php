<?php
// header.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($page_title) ? h($page_title) . " - Bankha Lab" : "Bankha Lab Management System" ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Itim&display=swap" rel="stylesheet">
<style>
    /* CSS พื้นฐานใช้ร่วมกัน */
    body { 
        font-family: 'Itim', cursive, system-ui; 
        background: #f8fafc; 
        margin: 0; 
        padding: 0; 
        color: #334155;
    }
    .navbar {
        background: #0ea5e9;
        color: white;
        padding: 15px 30px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }
    .navbar a { color: white; text-decoration: none; font-weight: bold; margin-left: 15px; }
    .navbar a:hover { text-decoration: underline; }
    .container {
        max-width: 1000px;
        margin: 30px auto;
        padding: 0 20px;
    }
    .card {
        background: white; 
        padding: 25px; 
        border-radius: 16px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.05); 
    }
    .msg { padding: 12px 15px; border-radius: 10px; margin-bottom: 15px; font-weight: bold; }
    .msg.success { background: #dcfce7; color: #166534; border-left: 5px solid #16a34a; }
    .msg.error { background: #fee2e2; color: #991b1b; border-left: 5px solid #dc2626; }
    
    /* Form Styles */
    label { display: block; margin: 10px 0 5px; font-weight: bold; }
    input[type="text"], input[type="password"], textarea, select {
        width: 100%; padding: 12px; border-radius: 10px; border: 1px solid #cbd5e1; 
        margin-bottom: 15px; font-family: inherit; box-sizing: border-box;
    }
    button.btn-primary {
        padding: 12px 20px; background: #2563eb; color: white; border: none;
        border-radius: 10px; cursor: pointer; font-size: 1rem; font-family: inherit; transition: 0.3s;
    }
    button.btn-primary:hover { background: #1d4ed8; }
</style>
</head>
<body>

<?php if (isset($_SESSION['user_id'])): ?>
<div class="navbar">
    <div>
        <strong>🏫 Bankha Lab System</strong>
        <span style="font-size: 0.9em; opacity: 0.8; margin-left: 10px;">
            ยินดีต้อนรับ, <?= h($_SESSION['display_name'] ?? 'ผู้ใช้งาน') ?> (<?= h($_SESSION['role'] ?? '') ?>)
        </span>
    </div>
    <div>
        <a href="logout.php">🚪 ออกจากระบบ</a>
    </div>
</div>
<?php endif; ?>

<div class="container">