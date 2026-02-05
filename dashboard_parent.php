<?php
require_once 'auth.php';
requireRole(['parent','developer']);
$user = currentUser();
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Parent Dashboard</title>

<style>
body {
    margin:0;
    padding:24px;
    font-family:system-ui, sans-serif;
    background:linear-gradient(135deg,#86efac,#4ade80,#22c55e);
    min-height:100vh;
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
    color:#15803d;
    font-weight:bold;
    text-decoration:none;
    margin-left:12px;
}
.topbar a:hover { text-decoration:underline; }

.badge {
    padding:4px 10px;
    background:#16a34a;
    color:white;
    border-radius:999px;
    font-size:0.8rem;
}

/* ---------- Card ---------- */
.card {
    background:white;
    padding:20px;
    border-radius:16px;
    margin-bottom:16px;
    box-shadow:0 10px 25px rgba(0,0,0,0.1);
}

.button-main {
    display:inline-block;
    padding:10px 14px;
    background:#16a34a;
    color:white;
    border-radius:8px;
    text-decoration:none;
    margin-top:8px;
}
.button-main:hover {
    background:#22c55e;
}
</style>

</head>
<body>

<!-- ---------- Top Bar ---------- -->
<div class="topbar">
    <div>
        <strong>Parent Dashboard</strong><br>
        <small>ยินดีต้อนรับ <?= htmlspecialchars($user['display_name']) ?></small>
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
    <h3>📘 งานของบุตรหลาน</h3>
    <p>ดูงานที่ครูมอบหมายตามระดับชั้นของบุตรหลาน</p>
    <a href="parent_assignments.php" class="button-main">📘 เปิดดูงานทั้งหมด</a>
</div>

<div class="card">
    <h3>📊 ผลการเรียนบุตรหลาน</h3>
    <ul>
        <li>คะแนนเฉลี่ย: 3.50</li>
        <li>การบ้านค้าง: 2 งาน</li>
        <li>ประวัติการมาเรียน: ขาด 1 / สาย 2</li>
    </ul>
</div>

<div class="card">
    <h3>🎗 พฤติกรรมและความก้าวหน้า</h3>
    <ul>
        <li>ช่วยงานครู: +5 GP</li>
        <li>เข้าร่วมกิจกรรมวิทยาศาสตร์: +10 GP</li>
    </ul>
</div>

</body>
</html>
