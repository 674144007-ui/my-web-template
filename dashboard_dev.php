<?php
require_once 'auth.php';
requireRole(['developer']);

$user = currentUser();
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Developer Dashboard</title>

<style>
body {
    margin:0;
    padding:30px;
    font-family:"Segoe UI",sans-serif;
    background:#0A0F24;
    color:#E2E8F0;
}

/* ---------- Top Bar ---------- */
.topbar {
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:30px;
}

.topbar-left strong {
    font-size:2rem;
}
.topbar-left small {
    color:#94a3b8;
}

.logout {
    color:#FF6060;
    font-weight:bold;
    text-decoration:none;
    margin-left:12px;
}
.logout:hover { color:#ff8c8c; }

/* ---------- Grid Cards ---------- */
.card-grid {
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(300px,1fr));
    gap:20px;
}

/* ---------- Card Style ---------- */
.card {
    background:rgba(30,41,59,0.6);
    padding:22px;
    border-radius:16px;
    border:1px solid rgba(148,163,184,0.15);
    box-shadow:0 15px 30px rgba(0,0,0,0.35);
    backdrop-filter:blur(12px);
    transition:0.3s;
}
.card:hover {
    transform:translateY(-5px);
    box-shadow:0 25px 40px rgba(0,0,0,0.45);
}

/* ---------- Tags ---------- */
.tag {
    display:inline-block;
    padding:3px 10px;
    background:#3b82f6;
    border-radius:999px;
    color:white;
    font-size:0.7rem;
    margin-bottom:8px;
}

/* ---------- Buttons ---------- */
.btn {
    display:inline-block;
    margin-top:10px;
    padding:10px 14px;
    border-radius:10px;
    font-weight:bold;
    text-decoration:none;
    color:#0f172a;
    background:#22c55e;
    transition:0.25s;
}
.btn:hover { background:#4ade80; }

/* ---------- Developer Badge on topbar ---------- */
.dev-badge {
    padding:6px 12px;
    background:#3b82f6;
    color:white;
    border-radius:999px;
    font-weight:bold;
    margin-right:12px;
}
</style>

</head>
<body>

<!-- ---------- TOPBAR ---------- -->
<div class="topbar">
    <div class="topbar-left">
        <strong>Developer Dashboard</strong><br>
        <small>ยินดีต้อนรับ <?= htmlspecialchars($user['display_name']) ?></small>
    </div>

    <div>
        <span class="dev-badge">Developer Mode</span>
        <a class="logout" href="logout.php">Logout</a>
    </div>
</div>


<!-- ---------- MAIN GRID ---------- -->
<div class="card-grid">

    <div class="card">
        <span class="tag">Preview Only</span>
        <h3>👩‍🏫 มุมครู</h3>
        <p>ดูหน้าจอของครู เช่น ตารางสอน งานที่มอบหมาย และคลังงาน</p>
        <a class="btn" href="dashboard_teacher.php">เปิด Teacher Dashboard</a>
    </div>

    <div class="card">
        <span class="tag">Preview Only</span>
        <h3>👨‍🎓 มุมนักเรียน</h3>
        <p>ดูหน้าจอนักเรียน เช่น งานที่ต้องส่ง เควส และ GP</p>
        <a class="btn" href="dashboard_student.php">เปิด Student Dashboard</a>
    </div>

    <div class="card">
        <span class="tag">Preview Only</span>
        <h3>👨‍👩‍👧 มุมผู้ปกครอง</h3>
        <p>ดูหน้าจอผู้ปกครอง เช่น ผลการเรียน การบ้านค้าง และพฤติกรรม</p>
        <a class="btn" href="dashboard_parent.php">เปิด Parent Dashboard</a>
    </div>

    <div class="card">
        <h3>🛠 เครื่องมือผู้พัฒนา</h3>
        <p>
            สามารถใส่เมนูเพิ่มในอนาคต เช่น:<br>
            - Log Viewer<br>
            - Quest Manager<br>
            - API Testing Tools<br>
            - System Monitor
        </p>
    </div>

    <div class="card">
        <h3>👥 จัดการผู้ใช้</h3>
        <p>เพิ่ม แก้ไข ลบ ผู้ใช้ทั้งหมดในระบบ</p>
        <a class="btn" href="user_manager.php">เปิด User Manager</a>
    </div>

    <div class="card">
        <h3>📅 เพิ่มตารางสอนครู</h3>
        <p>Developer สามารถเพิ่มตารางสอนให้ครูทุกคนได้</p>
        <a class="btn" href="dev_add_schedule.php">เพิ่มตารางสอน</a>
    </div>

    <div class="card">
        <h3>📅 ตารางสอนของครูทั้งหมด</h3>
        <p>ดูตารางสอนทั้งหมด + แก้ไข + ลบ</p>
        <a class="btn" href="dev_view_schedule.php">ดูตารางสอน</a>
    </div>

</div>

</body>
</html>
