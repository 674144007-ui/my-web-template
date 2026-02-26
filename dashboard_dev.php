<?php
/**
 * ===================================================================================
 * [SYSTEM INTERFACE] FILE: dashboard_dev.php
 * ===================================================================================
 * โปรเจกต์: ระบบบริหารจัดการสถานศึกษา โรงเรียนบ้านคาวิทยา (Bankha Withaya School)
 * เวอร์ชัน: 10.0.0 (Developer Final Edition)
 * หน้าที่: 
 * 1. ศูนย์ควบคุมกลางสำหรับนักพัฒนาและผู้ดูแลระบบสูงสุด (Central Command Center)
 * 2. แสดงสถิติภาพรวมของฐานข้อมูลแบบ Real-time (System Analytics)
 * 3. ทางเข้าสู่ระบบจำลองบทบาทผู้ใช้งาน (Role Impersonation Gateway)
 * 4. ระบบจัดการข้อมูลเชิงลึกและการทดสอบระบบแล็บเสมือน (Advanced Tools)
 * ===================================================================================
 */

// -----------------------------------------------------------------------------------
// [PART 1] - การจัดการสิทธิ์และการเข้าถึง (ACCESS CONTROL LAYER)
// -----------------------------------------------------------------------------------
// นำเข้าไฟล์ตรวจสอบตัวตนสากลของระบบ
require_once 'auth.php';

/**
 * 🛡️ ระบบรักษาความปลอดภัยระดับ Kernel:
 * บังคับตรวจสอบบทบาท (Role) ต้องเป็น 'developer' เท่านั้น
 * หากไม่ใช่ ระบบจะทำการ Redirect ไปยังหน้าแจ้งเตือนความปลอดภัยทันที
 */
requireRole(['developer']);

// -----------------------------------------------------------------------------------
// [PART 2] - การดึงข้อมูลสมาชิกปัจจุบัน (USER IDENTITY CONTEXT)
// -----------------------------------------------------------------------------------
// ดึงข้อมูล User Profile จากหน่วยความจำ Session ที่ผ่านการยืนยันแล้ว
$user = currentUser();
$user_id = isset($user['id']) ? $user['id'] : 0;
$display_name = isset($user['display_name']) ? $user['display_name'] : 'Unknown Developer';
$user_role = isset($user['role']) ? $user['role'] : 'developer';

// -----------------------------------------------------------------------------------
// [PART 3] - การดึงสถิติเชิงลึกจากฐานข้อมูล (ADVANCED SYSTEM ANALYTICS)
// -----------------------------------------------------------------------------------
// นำเข้าไฟล์เชื่อมต่อฐานข้อมูลหลัก
require_once 'db.php';

/**
 * [ANALYTICS 1] - สถิติผู้ใช้งานในระบบ (User Population)
 */
$user_count = 0;
$admin_count = 0;
$teacher_count = 0;
$student_count = 0;

$res_users = $conn->query("SELECT role, COUNT(id) as total FROM users GROUP BY role");
if ($res_users) {
    while ($row = $res_users->fetch_assoc()) {
        $r = strtolower($row['role']);
        if ($r === 'developer' || $r === 'admin') $admin_count += $row['total'];
        if ($r === 'teacher') $teacher_count = $row['total'];
        if ($r === 'student') $student_count = $row['total'];
        $user_count += $row['total'];
    }
}

/**
 * [ANALYTICS 2] - ข้อมูลทรัพยากรทางการเรียน (Academic Resources)
 */
// นับจำนวนสารเคมีที่ลงทะเบียนใน Virtual Lab
$chem_count = 0;
$res_chem = $conn->query("SELECT COUNT(id) as total FROM chemicals");
if ($res_chem) {
    $row_chem = $res_chem->fetch_assoc();
    $chem_count = $row_chem['total'];
}

// นับจำนวนตารางสอนที่ถูกสร้างขึ้น
$schedule_count = 0;
$res_sched = $conn->query("SELECT COUNT(id) as total FROM teacher_schedules");
if ($res_sched) {
    $row_sched = $res_sched->fetch_assoc();
    $schedule_count = $row_sched['total'];
}

// นับจำนวนงานมอบหมาย (Assignments) ทั้งหมด
$assignment_count = 0;
$res_assign = $conn->query("SELECT COUNT(id) as total FROM assignments");
if ($res_assign) {
    $row_assign = $res_assign->fetch_assoc();
    $assignment_count = $row_assign['total'];
}

/**
 * [ANALYTICS 3] - สถิติกิจกรรม (System Activity)
 */
// ตรวจสอบจำนวนผู้ใช้ที่ออนไลน์ล่าสุด (ภายใน 15 นาที)
$online_count = 0;
try {
    $res_online = $conn->query("SELECT COUNT(id) as total FROM users WHERE last_activity > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    if ($res_online) {
        $row_online = $res_online->fetch_assoc();
        $online_count = $row_online['total'];
    }
} catch (Exception $e) { $online_count = "N/A"; }

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ultimate Developer Console | Central Command Center</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Itim&family=JetBrains+Mono:wght@400;700&family=Prompt:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* ===========================================================================
           [FULL CSS ARCHITECTURE] - ห้ามย่อแม้แต่บรรทัดเดียว
           =========================================================================== */
        
        :root {
            --bg-deep: #020617;
            --bg-surface: #0f172a;
            --bg-card: rgba(30, 41, 59, 0.7);
            --accent-primary: #3b82f6;
            --accent-secondary: #a855f7;
            --accent-success: #22c55e;
            --accent-warning: #fbbf24;
            --accent-danger: #ef4444;
            --text-bright: #f1f5f9;
            --text-muted: #94a3b8;
            --glass-border: rgba(255, 255, 255, 0.1);
        }

        * {
            box-sizing: border-box;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body {
            margin: 0;
            padding: 0;
            font-family: 'Prompt', sans-serif;
            background-color: var(--bg-deep);
            background-image: 
                radial-gradient(circle at 5% 5%, rgba(59, 130, 246, 0.08) 0%, transparent 40%),
                radial-gradient(circle at 95% 95%, rgba(168, 85, 247, 0.08) 0%, transparent 40%);
            background-attachment: fixed;
            color: var(--text-bright);
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* --- Custom Scrollbar --- */
        ::-webkit-scrollbar { width: 10px; }
        ::-webkit-scrollbar-track { background: var(--bg-deep); }
        ::-webkit-scrollbar-thumb { background: #334155; border-radius: 5px; border: 2px solid var(--bg-deep); }
        ::-webkit-scrollbar-thumb:hover { background: #475569; }

        /* --- Global Animations --- */
        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes glowPulse {
            0% { box-shadow: 0 0 5px rgba(59, 130, 246, 0.4); }
            50% { box-shadow: 0 0 20px rgba(59, 130, 246, 0.8); }
            100% { box-shadow: 0 0 5px rgba(59, 130, 246, 0.4); }
        }

        /* --- Top Navigation Bar --- */
        .header-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 50px;
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(25px);
            border-bottom: 1px solid var(--glass-border);
            position: sticky;
            top: 0;
            z-index: 2000;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }

        .brand-container {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .brand-logo-small {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
            border-radius: 12px;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 24px;
            color: white;
            box-shadow: 0 0 15px rgba(59, 130, 246, 0.5);
        }

        .brand-text b {
            font-size: 1.6rem;
            background: linear-gradient(to right, #60a5fa, #c084fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .brand-text span {
            display: block;
            font-size: 0.75rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        .user-identity-box {
            display: flex;
            align-items: center;
            gap: 20px;
            background: rgba(255,255,255,0.03);
            padding: 8px 10px 8px 25px;
            border-radius: 50px;
            border: 1px solid var(--glass-border);
        }

        .status-indicator {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .pulse-dot {
            width: 10px;
            height: 10px;
            background-color: var(--accent-success);
            border-radius: 50%;
            position: relative;
        }

        .pulse-dot::after {
            content: "";
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            background-color: var(--accent-success);
            border-radius: 50%;
            animation: pulseWave 2s infinite;
        }

        @keyframes pulseWave {
            0% { transform: scale(1); opacity: 0.8; }
            100% { transform: scale(3); opacity: 0; }
        }

        .user-name {
            font-weight: 600;
            font-size: 1rem;
            color: var(--text-bright);
        }

        .role-chip {
            padding: 6px 15px;
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 800;
            text-transform: uppercase;
            box-shadow: 0 5px 15px rgba(124, 58, 237, 0.3);
        }

        .btn-exit {
            background: rgba(239, 68, 68, 0.1);
            color: #f87171;
            padding: 10px 22px;
            border-radius: 14px;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.9rem;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .btn-exit:hover {
            background: var(--accent-danger);
            color: white;
            box-shadow: 0 8px 20px rgba(239, 68, 68, 0.4);
            transform: translateY(-2px);
        }

        /* --- Main Layout Grid --- */
        .content-container {
            max-width: 1500px;
            margin: 40px auto;
            padding: 0 40px;
            animation: fadeInUp 1s ease-out;
        }

        /* --- Stats Overview Row --- */
        .analytics-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 50px;
        }

        .analytic-card {
            background: var(--bg-card);
            padding: 30px;
            border-radius: 24px;
            border: 1px solid var(--glass-border);
            display: flex;
            align-items: center;
            gap: 25px;
            backdrop-filter: blur(20px);
            position: relative;
            overflow: hidden;
        }

        .analytic-card::after {
            content: "";
            position: absolute;
            bottom: 0; right: 0; width: 100px; height: 100px;
            background: linear-gradient(135deg, transparent, rgba(255,255,255,0.03));
            border-radius: 50% 0 0 0;
        }

        .icon-circle {
            width: 70px; height: 70px;
            border-radius: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 30px;
        }

        .ic-users { background: rgba(59, 130, 246, 0.15); color: #60a5fa; }
        .ic-chems { background: rgba(168, 85, 247, 0.15); color: #c084fc; }
        .ic-sched { background: rgba(34, 197, 94, 0.15); color: #4ade80; }
        .ic-assign { background: rgba(251, 191, 36, 0.15); color: #fbbf24; }

        .analytic-info span {
            display: block;
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-bottom: 5px;
        }

        .analytic-info strong {
            font-size: 2.2rem;
            font-weight: 800;
            color: white;
            font-family: 'JetBrains Mono', monospace;
        }

        /* --- Section Titles --- */
        .grid-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin: 40px 0 30px 0;
        }

        .grid-header h2 {
            font-size: 1.8rem;
            margin: 0;
            font-weight: 700;
            color: white;
            white-space: nowrap;
        }

        .grid-header .line {
            flex-grow: 1;
            height: 1px;
            background: linear-gradient(to right, rgba(255,255,255,0.1), transparent);
        }

        /* --- Major Tool Cards --- */
        .control-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
            gap: 30px;
        }

        .tool-card {
            background: var(--bg-card);
            border-radius: 28px;
            padding: 40px;
            border: 1px solid var(--glass-border);
            position: relative;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .tool-card:hover {
            transform: translateY(-12px) scale(1.02);
            background: rgba(30, 41, 59, 0.9);
            border-color: var(--accent-primary);
            box-shadow: 0 30px 60px rgba(0,0,0,0.5);
        }

        .tool-badge {
            position: absolute;
            top: 20px; right: 20px;
            padding: 5px 12px;
            background: rgba(255,255,255,0.05);
            border-radius: 8px;
            font-size: 0.7rem;
            font-weight: bold;
            color: var(--text-muted);
        }

        .tool-card h3 {
            font-size: 1.6rem;
            margin: 15px 0;
            color: white;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .tool-card p {
            color: var(--text-dim);
            font-size: 1rem;
            line-height: 1.7;
            margin-bottom: 30px;
        }

        .tool-action {
            display: flex;
            gap: 15px;
        }

        .btn-tool {
            flex-grow: 1;
            padding: 16px;
            border-radius: 18px;
            text-align: center;
            text-decoration: none;
            font-weight: 700;
            font-size: 1rem;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
        }

        .btn-primary { background: var(--accent-primary); color: white; }
        .btn-primary:hover { background: #2563eb; box-shadow: 0 10px 25px rgba(37, 99, 235, 0.4); }

        .btn-ghost { background: rgba(255,255,255,0.05); color: white; border: 1px solid rgba(255,255,255,0.1); }
        .btn-ghost:hover { background: rgba(255,255,255,0.15); }

        /* --- Simulation Feature Section --- */
        .simulation-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 25px;
        }

        .sim-box {
            background: linear-gradient(145deg, #1e293b, #0f172a);
            border-radius: 24px;
            padding: 30px;
            border: 1px solid rgba(255,255,255,0.05);
            text-align: center;
            position: relative;
        }

        .sim-box:hover {
            border-color: rgba(255,255,255,0.2);
            transform: translateY(-5px);
        }

        .sim-icon {
            font-size: 45px;
            margin-bottom: 20px;
            display: block;
        }

        .sim-box h4 { font-size: 1.3rem; margin: 10px 0; }
        .sim-box p { font-size: 0.9rem; color: var(--text-dim); margin-bottom: 25px; height: 45px; }

        .btn-sim {
            display: block;
            width: 100%;
            padding: 12px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: bold;
            font-size: 0.95rem;
            background: rgba(255,255,255,0.08);
            color: white;
            border: 1px solid rgba(255,255,255,0.1);
        }

        .btn-sim:hover {
            background: white;
            color: black;
        }

        /* --- Footer Info --- */
        .system-footer {
            margin-top: 100px;
            padding: 60px 40px;
            border-top: 1px solid rgba(255,255,255,0.05);
            text-align: center;
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        .debug-table {
            margin: 30px auto;
            max-width: 600px;
            background: #000;
            border-radius: 12px;
            padding: 15px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.75rem;
            text-align: left;
            border: 1px solid #1e293b;
        }

        .debug-table div { margin-bottom: 5px; }
        .dbg-label { color: #4ade80; }
        .dbg-val { color: #fff; }

        /* --- Animations for elements --- */
        .entrance-1 { animation-delay: 0.1s; }
        .entrance-2 { animation-delay: 0.2s; }
        .entrance-3 { animation-delay: 0.3s; }
        .entrance-4 { animation-delay: 0.4s; }

        /* --- Badge Styles --- */
        .status-tag {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            margin-bottom: 10px;
            display: inline-block;
        }

        .st-dev { background: rgba(168, 85, 247, 0.2); color: #c084fc; }
        .st-test { background: rgba(59, 130, 246, 0.2); color: #60a5fa; }
        .st-db { background: rgba(34, 197, 94, 0.2); color: #4ade80; }

    </style>
</head>
<body>

    <nav class="header-nav">
        <div class="brand-container">
            <div class="brand-logo-small">
                <i class="fas fa-terminal"></i>
            </div>
            <div class="brand-text">
                <b>DEV CONSOLE</b>
                <span>Bankha Withaya School Platform</span>
            </div>
        </div>

        <div class="user-identity-box">
            <div class="status-indicator">
                <div class="pulse-dot"></div>
                <span class="user-name"><?= htmlspecialchars($display_name) ?></span>
            </div>
            <div class="role-chip">ROOT ACCESS</div>
            <a href="logout.php" class="btn-exit" onclick="return confirm('ยืนยันการออกจากระบบนักพัฒนาหรือไม่?')">
                <i class="fas fa-sign-out-alt"></i> ออกจากระบบ
            </a>
        </div>
    </nav>

    <div class="content-container">

        <div class="analytics-row">
            <div class="analytic-card entrance-1">
                <div class="icon-circle ic-users">
                    <i class="fas fa-users"></i>
                </div>
                <div class="analytic-info">
                    <span>ผู้ใช้งานทั้งหมด</span>
                    <strong><?= number_format($user_count) ?></strong>
                </div>
            </div>

            <div class="analytic-card entrance-2">
                <div class="icon-circle ic-chems">
                    <i class="fas fa-flask"></i>
                </div>
                <div class="analytic-info">
                    <span>คลังเคมีในระบบ</span>
                    <strong><?= number_format($chem_count) ?></strong>
                </div>
            </div>

            <div class="analytic-card entrance-3">
                <div class="icon-circle ic-sched">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="analytic-info">
                    <span>ตารางสอนที่ลงทะเบียน</span>
                    <strong><?= number_format($schedule_count) ?></strong>
                </div>
            </div>

            <div class="analytic-card entrance-4">
                <div class="icon-circle ic-assign">
                    <i class="fas fa-book"></i>
                </div>
                <div class="analytic-info">
                    <span>งานมอบหมายทั้งหมด</span>
                    <strong><?= number_format($assignment_count) ?></strong>
                </div>
            </div>
        </div>

        <div class="grid-header">
            <i class="fas fa-microchip" style="font-size: 24px; color: var(--accent-primary);"></i>
            <h2>ระบบหลักและตัวจัดการฐานข้อมูล</h2>
            <div class="line"></div>
        </div>

        <div class="control-grid">
            <div class="tool-card entrance-1">
                <div class="tool-badge">LAB ENGINE</div>
                <div>
                    <div class="status-tag st-dev">Engine v4.0</div>
                    <h3><i class="fas fa-vial" style="color:var(--accent-purple);"></i> Virtual Lab Simulator</h3>
                    <p>ระบบจำลองการผสมสารเคมีในรูปแบบ 3D นักพัฒนาสามารถเข้าไปทดสอบระบบ Logic การระเบิด, การเปลี่ยนสีของสาร และระบบ Damage ตรวจสอบสถานะ Beaker และการตอบสนองของเซิร์ฟเวอร์แบบ Real-time</p>
                </div>
                <div class="tool-action">
                    <a href="dev_lab.php" class="btn-tool btn-primary">
                        <i class="fas fa-rocket"></i> รันระบบจำลอง (Open Lab)
                    </a>
                    <a href="get_chemicals.php" class="btn-tool btn-ghost">คลังเคมี</a>
                </div>
            </div>

            <div class="tool-card entrance-2">
                <div class="tool-badge">USER DB</div>
                <div>
                    <div class="status-tag st-db">Master Access</div>
                    <h3><i class="fas fa-user-shield" style="color:var(--accent-success);"></i> User Account Manager</h3>
                    <p>จัดการบัญชีผู้ใช้งานในระบบทั้งหมด ไม่ว่าจะเป็นครู นักเรียน หรือผู้ปกครอง นักพัฒนาสามารถทำการ Reset รหัสผ่าน, ปรับแต่งสิทธิ์ (Privileges), ลบ User ที่มีปัญหา หรือเพิ่ม Admin คนใหม่เข้าสู่ระบบได้ที่นี่</p>
                </div>
                <div class="tool-action">
                    <a href="user_manager.php" class="btn-tool btn-primary">
                        <i class="fas fa-users-cog"></i> เปิดตัวจัดการบัญชี
                    </a>
                    <a href="register.php" class="btn-tool btn-ghost">เพิ่มสมาชิกใหม่</a>
                </div>
            </div>

            <div class="tool-card entrance-3">
                <div class="tool-badge">ACADEMIC</div>
                <div>
                    <div class="status-tag st-test">Force Mode</div>
                    <h3><i class="fas fa-calendar-alt" style="color:var(--accent-warning);"></i> Schedule Master Control</h3>
                    <p>ควบคุมตารางสอนของโรงเรียนทั้งหมด นักพัฒนาสามารถใช้โหมด Force Add เพื่อแทรกตารางสอนให้กับครูรายบุคคลโดยไม่ผ่านขั้นตอนการตรวจสอบปกติ หรือทำการล้างตารางสอนทั้งระบบเมื่อจบปีการศึกษา</p>
                </div>
                <div class="tool-action">
                    <a href="dev_view_schedule.php" class="btn-tool btn-primary">
                        <i class="fas fa-search"></i> ดูตารางสอนทั้งหมด
                    </a>
                    <a href="dev_add_schedule.php" class="btn-tool btn-ghost">แทรกตารางสอน</a>
                </div>
            </div>
        </div>

        <div class="grid-header">
            <i class="fas fa-eye" style="font-size: 24px; color: var(--accent-secondary);"></i>
            <h2>ระบบจำลองบทบาทผู้ใช้งาน (Impersonation Mode)</h2>
            <div class="line"></div>
        </div>

        <div class="simulation-grid">
            <div class="sim-box">
                <span class="sim-icon">👩‍🏫</span>
                <h4>มุมมองของคุณครู</h4>
                <p>ตรวจสอบระบบตารางสอน, การมอบหมายงาน และการจัดการห้องแล็บเคมี</p>
                <a href="switch_mode.php?role=teacher" class="btn-sim">Preview as Teacher</a>
            </div>

            <div class="sim-box">
                <span class="sim-icon">👨‍🎓</span>
                <h4>มุมมองของนักเรียน</h4>
                <p>ตรวจสอบเควสประจำวัน, สถานะคะแนน XP/GP และการส่งงานมอบหมาย</p>
                <a href="switch_mode.php?role=student" class="btn-sim">Preview as Student</a>
            </div>

            <div class="sim-box">
                <span class="sim-icon">👨‍👩‍👧</span>
                <h4>มุมมองของผู้ปกครอง</h4>
                <p>ตรวจสอบพฤติกรรมของบุตรหลาน, ผลคะแนน และการเข้าเรียนแบบเรียลไทม์</p>
                <a href="switch_mode.php?role=parent" class="btn-sim">Preview as Parent</a>
            </div>
        </div>

        <footer class="system-footer">
            <p><i class="fas fa-code"></i> Developed with passion for Bankha Withaya School Platform</p>
            <div class="debug-table">
                <div>[<span class="dbg-label">SYSTEM_STATUS</span>] : <span class="dbg-val">STABLE_V10.0</span></div>
                <div>[<span class="dbg-label">SERVER_TIME</span>] : <span class="dbg-val"><?= date('Y-m-d H:i:s') ?></span></div>
                <div>[<span class="dbg-label">ACTIVE_SESSION_ID</span>] : <span class="dbg-val"><?= session_id() ?></span></div>
                <div>[<span class="dbg-label">PHP_ENVIRONMENT</span>] : <span class="dbg-val">PHP <?= phpversion() ?></span></div>
                <div>[<span class="dbg-label">DB_CONNECTION</span>] : <span class="dbg-val">MYSQLI_ACTIVE</span></div>
                <div>[<span class="dbg-label">ONLINE_USERS</span>] : <span class="dbg-val"><?= $online_count ?> Active Users</span></div>
            </div>
            <p>© 2026 Developer Control Center | All Rights Reserved.</p>
        </footer>

    </div>

    <script>
        // ระบบแจ้งเตือนเมื่อเข้าสู่โหมดจำลอง
        document.querySelectorAll('.btn-sim').forEach(button => {
            button.addEventListener('click', function(e) {
                if(!confirm('คุณกำลังจะเข้าสู่โหมดจำลองบทบาท ซึ่งระบบจะเปลี่ยน UI ตามระดับที่เลือก ยืนยันหรือไม่?')) {
                    e.preventDefault();
                }
            });
        });
        
        // Log สำหรับ Developer Console
        console.log("%c[DEVELOPER SYSTEM]%c ระบบเริ่มต้นเรียบร้อยแล้ว สิทธิ์การใช้งาน: ROOT", "background:#3b82f6;color:white;padding:5px;border-radius:5px;", "color:#3b82f6;");
    </script>

</body>
</html>