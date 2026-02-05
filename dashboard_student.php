<?php
require_once 'auth.php';
requireRole(['student','developer']);
$user = currentUser();
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Student Dashboard</title>

<style>
body {
    margin:0;
    padding:24px;
    font-family:system-ui, sans-serif;
    background:linear-gradient(135deg,#3b82f6,#60a5fa,#93c5fd);
    min-height:100vh;
    color:#0f172a;
}

/* ---------- Topbar ---------- */
.topbar {
    background:white;
    padding:14px 20px;
    border-radius:14px;
    box-shadow:0 10px 25px rgba(0,0,0,0.15);
    margin-bottom:20px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.topbar a {
    color:#1d4ed8;
    font-weight:bold;
    text-decoration:none;
    margin-left:12px;
}
.topbar a:hover { text-decoration:underline; }

.badge {
    padding:4px 10px;
    background:#f97316;
    color:white;
    border-radius:999px;
    font-size:0.8rem;
}

/* ---------- Card ---------- */
.card {
    background:white;
    border-radius:16px;
    padding:20px;
    margin-bottom:16px;
    box-shadow:0 10px 25px rgba(0,0,0,0.1);
}

.button-main {
    display:inline-block;
    padding:10px 14px;
    background:#1d4ed8;
    color:white;
    border-radius:8px;
    text-decoration:none;
    margin-top:8px;
}
.button-main:hover {
    background:#2563eb;
}
</style>

</head>
<body>

<!-- ---------- Top Bar ---------- -->
<div class="topbar">
    <div>
        <strong>Student Dashboard</strong><br>
        <small>สวัสดี <?= htmlspecialchars($user['display_name']) ?></small>
    </div>

    <div>
        <?php if ($user['role']=='developer'): ?>
            <span class="badge">Developer Preview</span>
        <?php endif; ?>

        <a href="dashboard_dev.php">Dev</a>
        <a href="logout.php">Logout</a>
    </div>
</div>

<!-- ---------- เมนูหลัก ---------- -->
<div class="card">
    <h3>📘 งานที่ต้องส่ง</h3>
    <p>ดูงานทั้งหมดที่ครูมอบหมายให้ในระดับชั้นของคุณ</p>
    <a href="student_assignments.php" class="button-main">📘 เปิดดูงานทั้งหมด</a>
</div>

<div class="card">
    <h3>📘 สรุปการเรียนวันนี้</h3>
    <ul>
        <li>คาบเรียนวันนี้: คณิต, วิทย์, อังกฤษ</li>
        <li>การบ้านที่ต้องส่ง: ใบงานคณิต</li>
        <li>คะแนนเกมสะสม (GP): 120</li>
    </ul>
</div>

<div class="card">
    <h3>🎮 ภารกิจเกม (Quest)</h3>
    <ul>
        <li>ส่งงานคณิตวันนี้ +10 GP</li>
        <li>เข้าห้องเรียนครบสัปดาห์นี้ +20 GP</li>
    </ul>
</div>

</body>
</html>
