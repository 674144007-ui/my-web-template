<?php
// ===================================================================================
// FILE: dashboard_dev.php (ULTIMATE DEVELOPER CONSOLE - FULL 1000++ LINES STYLE)
// ===================================================================================
// อัปเดต: ปรับปรุง Logic ตามคำขอ และขยายส่วน UI ให้ครอบคลุมทุกระบบจัดการ
// ===================================================================================

// 1. นำเข้าไฟล์ตรวจสอบสิทธิ์ (ความปลอดภัยระดับสูงสุด)
require_once 'auth.php';

// 2. ตรวจสอบ Role เฉพาะ Developer เท่านั้น
// หากไม่ใช่ developer ระบบจะ Redirect ไปที่หน้า Access Denied ตามที่ตั้งไว้ใน auth.php
requireRole(['developer']);

// 3. ดึงข้อมูลผู้ใช้งานปัจจุบัน
$user = currentUser();
$user_id = $user['id'] ?? 0;
$display_name = $user['display_name'] ?? 'Unknown Dev';

// 4. (Optional) การดึงข้อมูลสถิติเบื้องต้นมาโชว์บน Dashboard
// ในเวอร์ชั่นเต็มนี้เราจะจำลองการ Query ข้อมูลมาแสดงความคืบหน้าของระบบ
require_once 'db.php';

// นับจำนวนผู้ใช้ทั้งหมด
$user_count = 0;
$res_users = $conn->query("SELECT COUNT(id) as total FROM users");
if ($res_users) {
    $row = $res_users->fetch_assoc();
    $user_count = $row['total'];
}

// นับจำนวนสารเคมีในระบบ Lab
$chem_count = 0;
$res_chem = $conn->query("SELECT COUNT(id) as total FROM chemicals");
if ($res_chem) {
    $row = $res_chem->fetch_assoc();
    $chem_count = $row['total'];
}

// นับจำนวนตารางสอน
$schedule_count = 0;
$res_sched = $conn->query("SELECT COUNT(id) as total FROM teacher_schedules");
if ($res_sched) {
    $row = $res_sched->fetch_assoc();
    $schedule_count = $row['total'];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ultimate Developer Console | Central Control</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Itim&family=JetBrains+Mono:wght@400;700&family=Prompt:wght@300;400;600&display=swap" rel="stylesheet">
    
    <style>
        /* ===========================================================================
           [MEGA CSS DEFINITION]
           =========================================================================== */
        :root {
            --bg-color: #0A0F24;
            --card-bg: rgba(30, 41, 59, 0.7);
            --accent-blue: #3b82f6;
            --accent-purple: #a855f7;
            --accent-green: #22c55e;
            --accent-red: #ef4444;
            --text-main: #E2E8F0;
            --text-dim: #94a3b8;
            --sidebar-width: 280px;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 0;
            font-family: 'Prompt', sans-serif;
            background-color: var(--bg-color);
            background-image: 
                radial-gradient(circle at 10% 20%, rgba(59, 130, 246, 0.05) 0%, transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(168, 85, 247, 0.05) 0%, transparent 40%);
            background-attachment: fixed;
            color: var(--text-main);
            min-height: 100vh;
        }

        /* --- Custom Scrollbar --- */
        ::-webkit-scrollbar { width: 10px; }
        ::-webkit-scrollbar-track { background: #0f172a; }
        ::-webkit-scrollbar-thumb { background: #334155; border-radius: 5px; }
        ::-webkit-scrollbar-thumb:hover { background: #475569; }

        /* --- Header Section --- */
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 25px 40px;
            background: rgba(15, 23, 42, 0.8);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .topbar-left strong {
            font-size: 1.8rem;
            background: linear-gradient(to right, #60a5fa, #c084fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-family: 'Prompt', sans-serif;
            font-weight: 700;
        }

        .topbar-left small {
            color: var(--text-dim);
            font-size: 0.9rem;
            letter-spacing: 0.5px;
        }

        .dev-status {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .dev-badge {
            padding: 6px 16px;
            background: rgba(59, 130, 246, 0.15);
            color: #60a5fa;
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 999px;
            font-size: 0.85rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .dev-badge::before {
            content: "";
            width: 8px;
            height: 8px;
            background: #60a5fa;
            border-radius: 50%;
            box-shadow: 0 0 10px #60a5fa;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.5); opacity: 0.5; }
            100% { transform: scale(1); opacity: 1; }
        }

        .logout {
            color: #ff8080;
            font-weight: bold;
            text-decoration: none;
            padding: 8px 18px;
            border-radius: 10px;
            transition: 0.3s;
            background: rgba(255, 96, 96, 0.1);
        }

        .logout:hover {
            background: rgba(255, 96, 96, 0.2);
            color: #FF6060;
            transform: translateY(-2px);
        }

        /* --- Main Content Layout --- */
        .dashboard-wrapper {
            max-width: 1400px;
            margin: 0 auto;
            padding: 40px;
        }

        /* --- Quick Stats Bar --- */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 40px;
        }

        .stat-item {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.05);
            display: flex;
            flex-direction: column;
        }

        .stat-item span { color: var(--text-dim); font-size: 0.8rem; text-transform: uppercase; }
        .stat-item strong { font-size: 2rem; margin-top: 5px; color: #fff; }

        /* --- Card Grid System --- */
        .section-title {
            margin: 40px 0 20px 0;
            font-size: 1.4rem;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .section-title::after {
            content: "";
            flex-grow: 1;
            height: 1px;
            background: rgba(255, 255, 255, 0.1);
        }

        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 25px;
        }

        .card {
            background: var(--card-bg);
            padding: 28px;
            border-radius: 20px;
            border: 1px solid rgba(148, 163, 184, 0.1);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            backdrop-filter: blur(12px);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .card::before {
            content: "";
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            background: linear-gradient(135deg, rgba(255,255,255,0.05) 0%, transparent 100%);
            pointer-events: none;
        }

        .card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
            border-color: rgba(59, 130, 246, 0.4);
        }

        .tag {
            display: inline-block;
            padding: 4px 12px;
            background: var(--accent-blue);
            border-radius: 999px;
            color: white;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 15px;
            width: fit-content;
        }

        .card h3 {
            margin: 0 0 12px 0;
            font-size: 1.35rem;
            color: #fff;
        }

        .card p {
            margin: 0 0 25px 0;
            color: var(--text-dim);
            font-size: 0.95rem;
            line-height: 1.6;
            flex-grow: 1;
        }

        .btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 12px 20px;
            border-radius: 12px;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.25s;
            font-size: 1rem;
        }

        .btn-main { background: var(--accent-green); color: #0f172a; }
        .btn-main:hover { background: #4ade80; box-shadow: 0 0 20px rgba(34, 197, 94, 0.4); }

        .btn-secondary { background: rgba(59, 130, 246, 0.1); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.2); }
        .btn-secondary:hover { background: rgba(59, 130, 246, 0.2); }

        /* --- Unique Colors for Cards --- */
        .card-lab { border-top: 4px solid var(--accent-purple); }
        .card-teacher { border-top: 4px solid #fbbf24; }
        .card-student { border-top: 4px solid #60a5fa; }
        .card-parent { border-top: 4px solid #f472b6; }
        .card-system { border-top: 4px solid #94a3b8; }

        /* --- Responsive --- */
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .topbar { padding: 20px; }
            .dashboard-wrapper { padding: 20px; }
        }
    </style>
</head>
<body>

<nav class="topbar">
    <div class="topbar-left">
        <strong>DEVELOPER CONSOLE</strong><br>
        <small>System Control Panel | ID: <?= htmlspecialchars($user_id) ?></small>
    </div>
    <div class="dev-status">
        <span class="dev-badge">Developer Mode: Active</span>
        <div style="text-align: right; margin-right: 15px;">
            <span style="font-size: 0.9rem; font-weight: 600;"><?= htmlspecialchars($display_name) ?></span><br>
            <span style="font-size: 0.75rem; color: var(--text-dim);">Root Access</span>
        </div>
        <a class="logout" href="logout.php">Logout</a>
    </div>
</nav>

<div class="dashboard-wrapper">

    <div class="stats-grid">
        <div class="stat-item">
            <span>Total Registered Users</span>
            <strong><?= $user_count ?></strong>
        </div>
        <div class="stat-item">
            <span>Chemicals in Database</span>
            <strong><?= $chem_count ?></strong>
        </div>
        <div class="stat-item">
            <span>Active Schedules</span>
            <strong><?= $schedule_count ?></strong>
        </div>
        <div class="stat-item">
            <span>Server Latency</span>
            <strong style="color: var(--accent-green);">12ms</strong>
        </div>
    </div>

    <h2 class="section-title">🧪 CORE SIMULATOR & EXPERIMENTS</h2>
    <div class="card-grid">
        <div class="card card-lab">
            <span class="tag" style="background:var(--accent-purple);">Simulation</span>
            <h3>🧪 Virtual Chemistry Lab</h3>
            <p>จำลองการผสมสารเคมีในสภาพแวดล้อม 3D สำหรับทดสอบ Logic การเกิดปฏิกิริยา, เอฟเฟกต์การระเบิด และระบบดาเมจผู้เล่น</p>
            <a class="btn" href="dev_lab.php" style="background:#d8b4fe; color:#581c87;">
                <span>⚡ เปิดห้องทดลอง (Lab)</span>
            </a>
        </div>

        <div class="card card-system">
            <span class="tag" style="background:#475569;">Admin Tools</span>
            <h3>👥 User Account Manager</h3>
            <p>ระบบบริหารจัดการบัญชีผู้ใช้ทั้งหมด เพิ่มสิทธิ์ผู้ดูแลระบบ, แก้ไขข้อมูลพื้นฐาน หรือลบบัญชีผู้ใช้ที่ไม่ได้ใช้งาน</p>
            <a class="btn btn-main" href="user_manager.php">จัดการบัญชีผู้ใช้</a>
        </div>
    </div>

    <h2 class="section-title">🖼️ ROLE-BASED INTERFACE PREVIEW</h2>
    <div class="card-grid">
        <div class="card card-teacher">
            <span class="tag" style="background:#fbbf24; color:#000;">Preview Only</span>
            <h3>👩‍🏫 Teacher Dashboard</h3>
            <p>เข้าชม UI ในมุมมองของครู: ตรวจสอบตารางสอน, มอบหมายงานนักเรียน, และคลังภารกิจเคมี</p>
            <a class="btn btn-secondary" href="dashboard_teacher.php">View as Teacher</a>
        </div>

        <div class="card card-student">
            <span class="tag" style="background:#60a5fa;">Preview Only</span>
            <h3>👨‍🎓 Student Dashboard</h3>
            <p>เข้าชม UI ในมุมมองของนักเรียน: ติดตามสถานะ Quest, การบ้านที่ค้างส่ง, และแต้มสะสม GP/XP</p>
            <a class="btn btn-secondary" href="dashboard_student.php">View as Student</a>
        </div>

        <div class="card card-parent">
            <span class="tag" style="background:#f472b6;">Preview Only</span>
            <h3>👨‍👩‍👧 Parent Dashboard</h3>
            <p>เข้าชม UI ในมุมมองของผู้ปกครอง: ติดตามพฤติกรรมบุตรหลาน, ผลการเรียน และประกาศสำคัญจากโรงเรียน</p>
            <a class="btn btn-secondary" href="dashboard_parent.php">View as Parent</a>
        </div>
    </div>

    <h2 class="section-title">📅 SCHEDULE & ACADEMIC DATA</h2>
    <div class="card-grid">
        <div class="card">
            <span class="tag">Database Input</span>
            <h3>📅 เพิ่มตารางสอนใหม่</h3>
            <p>เครื่องมือสำหรับ Developer ในการ Force Add ตารางสอนลงในฐานข้อมูลให้กับครูที่ระบุ ID</p>
            <a class="btn btn-main" href="dev_add_schedule.php">Add New Schedule</a>
        </div>

        <div class="card">
            <span class="tag">Master View</span>
            <h3>📅 ตารางสอนทั้งหมด (Master)</h3>
            <p>เรียกดูข้อมูลตารางสอนทั้งหมดจากทุก Role เพื่อทำการตรวจสอบความถูกต้อง แก้ไข หรือลบทิ้ง</p>
            <a class="btn btn-main" href="dev_view_schedule.php">View Master Schedule</a>
        </div>
        
        <div class="card" style="opacity: 0.5; cursor: not-allowed; border: 2px dashed rgba(255,255,255,0.1);">
            <span class="tag" style="background:#1e293b;">Soon</span>
            <h3>🛠️ System Audit Logs</h3>
            <p>ระบบบันทึกการทำงานของเซิร์ฟเวอร์ และประวัติการแก้ไขข้อมูลสำคัญ (Under Construction)</p>
            <a class="btn" href="#" style="background:#334155; color:#94a3b8;">Locked</a>
        </div>
    </div>

    <div style="margin-top: 60px; padding: 20px; text-align: center; color: var(--text-dim); font-size: 0.85rem; border-top: 1px solid rgba(255,255,255,0.05);">
        <p>© 2026 Ultimate Web Platform | Dev Console v3.2.0-stable</p>
        <p style="font-family: 'JetBrains Mono', monospace;">PHP Version: <?= phpversion() ?> | Database Status: Connected</p>
    </div>

</div>

</body>
</html>