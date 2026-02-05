<?php
require_once 'auth.php';
requireRole(['teacher','developer']);
$user = currentUser();
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Teacher Dashboard</title>

<style>
/* ---------- พื้นหลังรวม ---------- */
body {
    font-family: 'Segoe UI', sans-serif;
    background: #F0F4FF;
    margin: 0;
    padding: 20px;
}

/* ---------- Top bar ---------- */
.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #1E3A8A;
    color: white;
    padding: 14px 20px;
    border-radius: 12px;
    margin-bottom: 20px;
}

.topbar a {
    color: #FACC15;
    font-weight: bold;
    text-decoration: none;
    margin-left: 10px;
}

.topbar a:hover {
    text-decoration: underline;
}

.badge {
    background: #FACC15;
    color: #1E3A8A;
    padding: 4px 8px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: bold;
}

/* ---------- ตำแหน่งหัวข้อหน้าครู ---------- */
.teacher-title {
    font-size: 28px;
    font-weight: bold;
    color: #1E3A8A;
    margin-bottom: 20px;
}

/* ---------- Grid ของเมนู ---------- */
.teacher-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 20px;
}

/* ---------- กล่องเมนู ---------- */
.teacher-card {
    background: white;
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.08);
    border-left: 8px solid #3B82F6;
    transition: 0.25s;
    word-wrap: break-word;
    overflow-wrap: break-word;
}

.teacher-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.15);
}

/* ---------- หัวข้อในกล่อง ---------- */
.teacher-card h3 {
    margin: 0 0 10px;
    font-size: 20px;
    color: #1E3A8A;
}

/* ---------- ปุ่มในกล่อง ---------- */
.teacher-card a {
    display: inline-block;
    margin-top: 10px;
    padding: 10px 14px;
    background: #FACC15;
    color: #1E3A8A;
    font-weight: bold;
    border-radius: 8px;
    text-decoration: none;
    transition: 0.2s;
}

.teacher-card a:hover {
    background: #FFE55D;
}

</style>
</head>

<body>

<!-- ---------- Top bar ---------- -->
<div class="topbar">
    <div>
        <strong>Teacher Dashboard</strong><br>
        <small>ยินดีต้อนรับ <?= htmlspecialchars($user['display_name']) ?></small>
    </div>

    <div>
        <?php if ($user['role'] === 'developer'): ?>
            <span class="badge">Developer Preview</span>
        <?php endif; ?>

        <a href="dashboard_dev.php">Dev</a>
        |
        <a href="logout.php">Logout</a>
    </div>
</div>

<!-- ---------- Title ---------- -->
<div class="teacher-title">เมนูจัดการของครู</div>

<!-- ---------- Grid Menu ---------- -->
<div class="teacher-grid">

    <!-- ตารางสอน -->
    <div class="teacher-card">
        <h3>📅 ตารางสอน</h3>
        <p>ดูตารางสอนของครูในเทอมนี้</p>
        <a href="teacher_schedule.php">เปิดตารางสอน</a>
    </div>

    <!-- สร้างงานมอบหมาย -->
    <div class="teacher-card">
        <h3>✏️ งานมอบหมาย</h3>
        <p>สร้างงานใหม่ให้ชั้นเรียนของคุณ</p>
        <a href="create_assignment.php">➕ สร้างงาน</a>
    </div>

    <!-- ดูงานที่มอบหมาย -->
    <div class="teacher-card">
        <h3>📘 งานที่มอบหมายแล้ว</h3>
        <p>รายการงานที่ครูเคยมอบหมายทั้งหมด</p>
        <a href="teacher_assignments.php">📘 เปิดดู</a>
    </div>

    <!-- คลังงาน -->
    <div class="teacher-card">
        <h3>📚 คลังงาน</h3>
        <p>จัดการไฟล์แบบฝึกหัดของครู</p>
        <a href="assignment_library.php">📚 เปิดคลัง</a>
        <a href="create_assignment_library.php" style="background:#4ade80;color:#065f46;margin-left:6px;">
            ➕ เพิ่มเข้าคลัง
        </a>
    </div>

    <!-- ภารกิจ/เควส -->
    <div class="teacher-card">
        <h3>🎮 ภารกิจ / เควส</h3>
        <p>สร้างกิจกรรม gamification ให้ชั้นเรียน</p>
        <a href="create_quest.php">🎮 สร้างเควส</a>
    </div>

</div><!-- end teacher-grid -->

</body>
</html>
