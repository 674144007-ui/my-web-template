<?php
/**
 * ===================================================================================
 * [SYSTEM ENTRY POINT] FILE: index.php
 * ===================================================================================
 * โปรเจกต์: Bankha Withaya School - Ultimate Classroom Management System
 * เวอร์ชัน: 9.0.0 (Developer Master Command Edition)
 * หน้าที่: 
 * 1. ระบบจัดการการเข้าถึงเบื้องต้น (Traffic Controller)
 * 2. ป้องกันการโจมตีแบบ Brute-force (Advanced Protection)
 * 3. แสดงหน้าจอ Login UI แบบอลังการ (Full CSS Masterclass v2)
 * 4. วิเคราะห์สิทธิ์และนำทางผู้ใช้ (Priority-Based Intelligent Routing)
 * 5. แก้ไขปัญหา: สิทธิ์ Developer/Admin หลุดไปหน้าครูจำลอง (Fixing Simulation Lock)
 * ===================================================================================
 */

// -----------------------------------------------------------------------------------
// [SECTION 1] - การจัดการระบบพื้นฐาน (CORE INITIALIZATION)
// -----------------------------------------------------------------------------------
/**
 * เปิด Buffer บรรทัดแรกสุดเพื่อความเสถียรของการทำ Redirect 
 * ช่วยป้องกันข้อผิดพลาด "Warning: Cannot modify header information" 
 * ที่มักเกิดจากการส่งอักขระแปลกปลอมออกมาก่อนคำสั่ง Location
 */
if (ob_get_level() == 0) {
    ob_start();
}

// นำเข้าไฟล์เชื่อมต่อฐานข้อมูลหลักและไฟล์ระบบตรวจสอบตัวตน
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/**
 * ตั้งค่าการรายงานข้อผิดพลาดระดับสูงสุด (เฉพาะในโหมดการพัฒนา)
 * เพื่อให้นักพัฒนามองเห็นจุดบกพร่องของ Logic ได้ทันทีในหน้าจอสีขาว
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ตัวแปรส่วนกลางสำหรับเก็บสถานะความผิดพลาดเพื่อแสดงบน UI
$error = "";

// -----------------------------------------------------------------------------------
// [SECTION 2] - ระบบป้องกันการเดารหัสผ่าน (BRUTE-FORCE ATTACK DEFENSE)
// -----------------------------------------------------------------------------------
/**
 * อัลกอริทึมป้องกัน Brute-force ขั้นสูง:
 * หากผู้ใช้งานพยายามล็อกอินผิดพลาดเกิน 5 ครั้ง 
 * ระบบจะทำการล็อกการเชื่อมต่อเป็นเวลา 30 วินาที เพื่อลดภาระของฐานข้อมูลและป้องกันบอท
 */
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
}
if (!isset($_SESSION['last_attempt_time'])) {
    $_SESSION['last_attempt_time'] = time();
}

// การกำหนดค่าตัวแปรควบคุมความปลอดภัยระดับองค์กร
$MAX_ATTEMPTS = 5;      // จำนวนครั้งสูงสุดที่อนุญาตให้ป้อนข้อมูลผิด
$LOCKOUT_DURATION = 30; // ระยะเวลาในการล็อกระบบหน่วยเป็นวินาที

if ($_SESSION['login_attempts'] >= $MAX_ATTEMPTS) {
    $time_now = time();
    $time_diff = $time_now - $_SESSION['last_attempt_time'];
    $remaining_lock = $LOCKOUT_DURATION - $time_diff;
    
    if ($remaining_lock > 0) {
        $error = "🚫 ความปลอดภัย: พยายามหลายครั้งเกินไป ระบบถูกล็อกชั่วคราว กรุณารออีก $remaining_lock วินาที";
    } else {
        // ทำการปลดล็อกระบบอัตโนมัติเมื่อครบกำหนดเวลาที่ตั้งไว้
        $_SESSION['login_attempts'] = 0; 
    }
}

// -----------------------------------------------------------------------------------
// [SECTION 3] - ระบบการคัดแยกบทบาทอัจฉริยะ (INTELLIGENT ROLE-BASED ROUTING)
// -----------------------------------------------------------------------------------
/**
 * 🔄 ตรวจสอบสถานะการล็อกอินเดิม:
 * หากผู้ใช้งานมีเซสชันที่ถูกต้องอยู่แล้ว ระบบจะทำการข้ามหน้า Login 
 * และส่งตัวไปยังหน้า Dashboard ที่ตรงตามบทบาท (Role) ของผู้ใช้นั้นๆ ทันที
 */
if (isLoggedIn() && empty($error)) {
    
    /**
     * เรียกใช้ฟังก์ชัน currentUser() จาก auth.php ที่ได้รับการซ่อมแซมแล้ว
     * ฟังก์ชันนี้จะไป Query ข้อมูลสดจาก Database เพื่อยืนยัน Identity ที่แท้จริง
     */
    $user_context = currentUser();
    $session_role = strtolower(trim($user_context['role'] ?? ''));
    $true_identity = strtolower(trim($user_context['true_role'] ?? ''));

    /**
     * 🔴 🔴 [CRITICAL FIX: DEVELOPER SUPREME PRIORITY] 🔴 🔴
     * แก้ไขปัญหา "ไอดี Dev ไปหน้าครู": ระบบจะทำการตรวจสอบสิทธิ์ Developer จากฐานข้อมูลก่อนเป็นลำดับแรกสุด 
     * หากตรวจพบว่าเป็นไอดีนักพัฒนาตัวจริง ระบบจะสั่ง "ทำลายสถานะจำลอง" (Unset Simulation) 
     * และบังคับดีดไปที่หน้า dashboard_dev.php ทันทีโดยไม่มีเงื่อนไขอื่นมาแทรกแซง
     */
    if ($true_identity === 'developer' || $true_identity === 'admin') {
        
        // บังคับคืนร่างจริง: ล้างค่าตัวแปรที่ใช้ในโหมดจำลอง (Simulation Mode) ทั้งหมด
        unset($_SESSION['dev_simulation_mode']);
        unset($_SESSION['original_identity']);
        unset($_SESSION['subject_group']);
        unset($_SESSION['teacher_department']);
        unset($_SESSION['class_level']);
        
        // ยืนยันสิทธิ์ในหน่วยความจำสำรองอีกครั้งให้เป็นมาตรฐาน
        $_SESSION['role'] = 'developer';
        
        // บันทึกการเปลี่ยนแปลงและย้ายหน้าทันที
        session_write_close();
        if (ob_get_length()) ob_clean();
        header("Location: dashboard_dev.php");
        exit;
    }

    /**
     * ลำดับการ Redirect สำหรับบทบาทผู้ใช้งานทั่วไป 
     * (จะทำงานก็ต่อเมื่อไม่ใช่ไอดีนักพัฒนา)
     */
    switch ($session_role) {
        case 'teacher':
            header("Location: dashboard_teacher.php");
            break;
        case 'student':
            header("Location: dashboard_student.php");
            break;
        case 'parent':
            header("Location: dashboard_parent.php");
            break;
        default:
            // กรณีไม่ทราบสถานะบทบาท หรือบทบาทแปลกปลอม ให้ส่งไปหน้าโปรไฟล์เพื่อตรวจสอบ
            header("Location: profile.php?msg=unknown_identity_detected");
            break;
    }
    exit;
}

// -----------------------------------------------------------------------------------
// [SECTION 4] - การประมวลผลข้อมูลการล็อกอิน (AUTHENTICATION PROCESSOR)
// -----------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {

    // รับค่าจากแบบฟอร์มและทำความสะอาดข้อมูลเพื่อป้องกันอักขระส่วนเกิน
    $username_input = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password_input = isset($_POST['password']) ? trim($_POST['password']) : '';

    if ($username_input === "" || $password_input === "") {
        $error = "⚠️ ข้อมูลไม่ครบ: กรุณาระบุชื่อผู้ใช้งานและรหัสผ่าน";
    } else {

        /**
         * 🟢 การเข้าถึงฐานข้อมูล: ใช้เทคนิค Prepared Statements เพื่อความปลอดภัยระดับสูงสุด
         * ป้องกันการโจมตีประเภท SQL Injection อย่างสมบูรณ์แบบ
         */
        $auth_sql = "SELECT id, username, password, display_name, role, class_level
                    FROM users
                    WHERE username = ?
                    LIMIT 1";

        if ($stmt = $conn->prepare($auth_sql)) {
            // ผูกค่าพารามิเตอร์แบบ String
            $stmt->bind_param("s", $username_input);
            $stmt->execute();
            $stmt->store_result();

            /**
             * การผูกผลลัพธ์ข้อมูล (Data Binding Results):
             * ตัวแปรเหล่านี้จะทำหน้าที่รับข้อมูลจาก Database Row มาเก็บไว้ในหน่วยความจำ PHP
             */
            $stmt->bind_result(
                $user_id_db,
                $username_db,
                $password_db,
                $display_name_db,
                $role_db,
                $class_level_db
            );

            // ตรวจสอบว่าพบบัญชีผู้ใช้งานที่ระบุหรือไม่
            if ($stmt->num_rows === 1) {
                $stmt->fetch();

                /**
                 * ตรวจสอบความถูกต้องของรหัสผ่าน:
                 * เปรียบเทียบรหัสผ่านที่ป้อนเข้ามา กับ Hash ในฐานข้อมูล
                 */
                if (password_verify($password_input, $password_db)) {

                    /**
                     * 🛡️ ระบบความปลอดภัยระดับสูง:
                     * สร้างรหัสเซสชันใหม่ (Regenerate ID) ทันทีที่ยืนยันตัวตนสำเร็จ
                     * เพื่อป้องกันการขโมย Session เดิมไปสวมรอย
                     */
                    session_regenerate_id(true);

                    // บันทึกตัวตนผู้ใช้งานลงในหน่วยความจำเซสชันสากล
                    $_SESSION['user_id']      = $user_id_db;
                    $_SESSION['username']     = $username_db;
                    $_SESSION['display_name'] = $display_name_db;
                    $_SESSION['class_level']  = $class_level_db;
                    
                    // ทำความสะอาดบทบาทก่อนตัดสินใจส่งตัว
                    $assigned_role = strtolower(trim($role_db));
                    
                    // 🔴 แก้ไขสิทธิ์ ADMIN/DEV ทันทีให้เป็น DEVELOPER ตัวเล็กทั้งหมด
                    if ($assigned_role === 'admin' || $assigned_role === 'dev' || $assigned_role === 'developer') {
                        $assigned_role = 'developer';
                    }
                    $_SESSION['role'] = $assigned_role;

                    // เคลียร์โหมดจำลองทิ้งทันทีที่ล็อกอินใหม่ (Hard Reset)
                    unset($_SESSION['dev_simulation_mode']);
                    unset($_SESSION['original_role']);

                    // รีเซ็ตการนับความผิดพลาดของ Brute-force
                    $_SESSION['login_attempts'] = 0;
                    $_SESSION['last_attempt_time'] = time();

                    /**
                     * 🔄 [FINAL REDIRECTION CONTROL]
                     * ขั้นตอนการส่งผู้ใช้เข้าสู่หน้า Dashboard หลัก
                     */
                    if ($assigned_role === 'developer') {
                        header("Location: dashboard_dev.php");
                    } elseif ($assigned_role === 'teacher') {
                        header("Location: dashboard_teacher.php");
                    } elseif ($assigned_role === 'student') {
                        header("Location: dashboard_student.php");
                    } elseif ($assigned_role === 'parent') {
                        header("Location: dashboard_parent.php");
                    } else {
                        header("Location: index.php?error=access_denied_role");
                    }
                    
                    // บันทึกและหยุดการทำงานส่วน PHP ทันทีหลังสั่ง Redirect
                    session_write_close();
                    exit;

                } else {
                    // กรณีรหัสผ่านผิด: เพิ่มจำนวนครั้งการพยายาม และบันทึกเวลา
                    $error = "❌ รหัสผ่านไม่ถูกต้อง โปรดตรวจสอบอีกครั้ง";
                    $_SESSION['login_attempts']++;
                    $_SESSION['last_attempt_time'] = time();
                }

            } else {
                // กรณีไม่พบบัญชี: เพิ่มจำนวนครั้งการพยายาม เพื่อป้องกันการสุ่มชื่อผู้ใช้
                $error = "❌ ไม่พบชื่อผู้ใช้งานนี้ในระบบของเรา";
                $_SESSION['login_attempts']++;
                $_SESSION['last_attempt_time'] = time();
            }

            $stmt->close();
        } else {
            // กรณีระบบฐานข้อมูลขัดข้อง (Critical Error)
            $error = "⚠️ เกิดข้อผิดพลาดของระบบฐานข้อมูล (SQL Error)";
            error_log("Database Execution Failure: " . $conn->error);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ - โรงเรียนบ้านคาวิทยา | Bankha Withaya School Portal</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Itim&display=swap" rel="stylesheet">
    
    <style>
        /* ===========================================================================
           [MEGA CSS INTERFACE DESIGN] - ระบบตกแต่งอลังการระดับพรีเมียม
           =========================================================================== */
        
        :root {
            --bankha-blue: #0057B7;
            --bankha-yellow: #FFD600;
            --error-red: #d70040;
            --glass-bg: rgba(255, 255, 255, 0.18);
            --glass-border: rgba(255, 255, 255, 0.38);
            --text-white: #ffffff;
        }

        * {
            box-sizing: border-box;
            transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body {
            margin: 0; padding: 0;
            font-family: 'Itim', cursive;
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            overflow: hidden;

            /**
             * ✨ [ULTIMATE BACKGROUND ANIMATION]
             * พื้นหลังแอนิเมชันระดับ 4K: รวม Metallic Shine, Gradient Flow และธงประจำโรงเรียน
             */
            background:
                linear-gradient(135deg, rgba(255,222,100,0.4), rgba(255,240,180,0.25), rgba(255,230,80,0.5)),
                linear-gradient(135deg, #0048B4, #0A60E0, #1976FF, #FFD000, #FFEA55),
                linear-gradient(to bottom, #0057B7 0%, #0057B7 50%, #FFD600 50%, #FFD600 100%),
                radial-gradient(circle at 65% 70%, rgba(255,255,255,0.22), transparent 75%);

            background-size: 200% 200%, 180% 180%, 100% 100%, 250% 250%;

            animation:
                shineAnimation 7s linear infinite,
                gradientFlow 18s ease-in-out infinite,
                softWaveMotion 10s ease-in-out infinite,
                glowPulseEffect 9s ease-in-out infinite;
        }

        /* --- Keyframes สำหรับความอลังการของหน้าจอ --- */
        @keyframes shineAnimation {
            0% { filter: brightness(1) contrast(1); }
            50% { filter: brightness(1.22) contrast(1.15); }
            100% { filter: brightness(1) contrast(1); }
        }

        @keyframes gradientFlow {
            0% { background-position: 50% 0%; }
            50% { background-position: 50% 100%; }
            100% { background-position: 50% 0%; }
        }

        @keyframes softWaveMotion {
            0% { transform: skewX(0deg) translateX(0px); }
            25% { transform: skewX(-1.8deg) translateX(-8px); }
            50% { transform: skewX(0deg) translateX(0px); }
            75% { transform: skewX(1.8deg) translateX(8px); }
            100% { transform: skewX(0deg) translateX(0px); }
        }

        @keyframes glowPulseEffect {
            0% { opacity: 1; }
            50% { opacity: 0.85; }
            100% { opacity: 1; }
        }

        /* --- โครงสร้างคอมโพเนนต์หลัก --- */
        .page-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            z-index: 1000;
            width: 100%;
            max-width: 500px;
            padding: 20px;
        }

        /* โลโก้โรงเรียนพร้อมแอนิเมชันลอยตัวแบบพรีเมียม */
        .identity-logo {
            text-align: center;
            margin-bottom: 35px;
            animation: entranceAnimation 1.5s ease-out;
        }

        .identity-logo img {
            width: 280px;
            height: auto;
            filter: drop-shadow(0 15px 30px rgba(0,0,0,0.5));
            animation: floatMotion 6s ease-in-out infinite, logoGlow 4s ease-in-out infinite;
        }

        .identity-title {
            display: block;
            margin-top: 20px;
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--text-white);
            letter-spacing: 2px;
            text-shadow: 0 5px 15px rgba(0,0,0,0.6);
            animation: entranceText 2s ease-out;
        }

        @keyframes floatMotion {
            0% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-15px) rotate(1deg); }
            100% { transform: translateY(0px) rotate(0deg); }
        }

        @keyframes logoGlow {
            0% { filter: drop-shadow(0 6px 12px rgba(255,255,200,0.3)); }
            50% { filter: drop-shadow(0 15px 30px rgba(255,240,150,0.55)); }
            100% { filter: drop-shadow(0 6px 12px rgba(255,255,200,0.3)); }
        }

        @keyframes entranceAnimation {
            from { opacity: 0; transform: translateY(-40px); }
            to { opacity: 1; transform: translateY(0px); }
        }

        @keyframes entranceText {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0px); }
        }

        /* การ์ดกระจกโปร่งแสงดีไซน์ล้ำสมัย (Glassmorphism Concept) */
        .glass-login-box {
            width: 100%;
            padding: 55px 60px;
            border-radius: 45px;
            backdrop-filter: blur(25px);
            background: var(--glass-bg);
            box-shadow: 0 40px 90px rgba(0,0,0,0.5);
            border: 1px solid var(--glass-border);
            color: var(--text-white);
            position: relative;
            overflow: hidden;
            animation: cardReveal 1.2s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        @keyframes cardReveal {
            from { opacity: 0; transform: scale(0.8) translateY(80px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }

        .glass-login-box::after {
            content: "";
            position: absolute;
            top: -50%; left: -50%;
            width: 200%; height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.05), transparent);
            transform: rotate(45deg);
            animation: shineSweep 10s infinite;
            pointer-events: none;
        }

        @keyframes shineSweep {
            0% { transform: translateX(-100%) rotate(45deg); }
            20% { transform: translateX(100%) rotate(45deg); }
            100% { transform: translateX(100%) rotate(45deg); }
        }

        h2 { text-align: center; margin: 0; font-size: 2.7rem; letter-spacing: -2px; text-shadow: 2px 2px 6px rgba(0,0,0,0.35); }
        .login-desc { text-align: center; margin-bottom: 45px; opacity: 0.95; font-size: 1.2rem; color: #fff; font-weight: 300; }

        /* การออกแบบกลุ่มฟอร์ม */
        .form-control-group { margin-bottom: 35px; text-align: left; }
        label { display: block; margin-bottom: 15px; font-weight: bold; font-size: 1.35rem; text-shadow: 1px 1px 4px rgba(0,0,0,0.4); }
        
        input {
            width: 100%;
            padding: 20px 28px;
            border-radius: 25px;
            border: 2px solid transparent;
            font-size: 1.25rem;
            outline: none;
            background: rgba(255, 255, 255, 0.94);
            color: #0f172a;
            box-sizing: border-box;
            transition: all 0.4s;
            font-family: 'Itim', cursive;
            box-shadow: 0 8px 15px rgba(0,0,0,0.12);
        }

        input:focus {
            border-color: var(--bankha-yellow);
            background: #ffffff;
            box-shadow: 0 0 35px rgba(255, 214, 0, 0.6);
            transform: scale(1.04);
        }

        /* ปุ่มเข้าสู่ระบบแบบไดนามิก */
        .btn-action-login {
            width: 100%;
            padding: 22px;
            border: none;
            border-radius: 25px;
            background: #ffffff;
            color: var(--error-red);
            font-size: 1.7rem;
            cursor: pointer;
            font-weight: bold;
            box-shadow: 0 15px 35px rgba(0,0,0,0.35);
            font-family: 'Itim', cursive;
            margin-top: 20px;
            position: relative;
            z-index: 1;
        }

        .btn-action-login:hover {
            background: var(--bankha-yellow);
            color: #000;
            transform: translateY(-8px) scale(1.04);
            box-shadow: 0 25px 50px rgba(255, 214, 0, 0.5);
        }

        .btn-action-login:active {
            transform: translateY(4px) scale(0.98);
        }

        /* การจัดการ UI ข้อความแสดงความผิดพลาด */
        .alert-error-panel {
            background: rgba(255, 255, 255, 0.28);
            color: #fff;
            padding: 20px;
            border-radius: 22px;
            margin-bottom: 40px;
            text-align: center;
            backdrop-filter: blur(15px);
            border-left: 10px solid var(--bankha-yellow);
            font-weight: bold;
            font-size: 1.15rem;
            animation: shakeUI 0.6s ease-in-out;
            box-shadow: 0 15px 30px rgba(0,0,0,0.25);
        }

        @keyframes shakeUI {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-10px); }
            40%, 80% { transform: translateX(10px); }
        }

        .dev-footer-credits {
            margin-top: 60px;
            font-size: 1rem;
            color: rgba(255,255,255,0.8);
            text-align: center;
            letter-spacing: 2px;
            text-shadow: 1px 1px 4px rgba(0,0,0,0.6);
        }

    </style>
</head>
<body>

<div class="page-wrapper">
    <div class="identity-logo">
        <img src="logo.png" alt="Bankha Withaya School Logo" onerror="this.style.display='none'">
        <span class="identity-title">Bankha Withaya School Portal</span>
    </div>

    <div class="glass-login-box">
        <h2>เข้าสู่ระบบ</h2>
        <div class="login-desc">Classroom Management Core Gateway v9.0</div>

        <?php if ($error): ?>
            <div class="alert-error-panel">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="index.php">
            <div class="form-control-group">
                <label for="username">Username (ชื่อผู้ใช้)</label>
                <input type="text" id="username" name="username" placeholder="ระบุชื่อบัญชีผู้ใช้งานของคุณ..." required autocomplete="username">
            </div>

            <div class="form-control-group">
                <label for="password">Password (รหัสผ่าน)</label>
                <input type="password" id="password" name="password" placeholder="ระบุรหัสผ่านส่วนตัวของคุณ..." required autocomplete="current-password">
            </div>

            <button class="btn-action-login" type="submit">ล็อกอินเข้าสู่ระบบ</button>
        </form>
    </div>

    <div class="dev-footer-credits">
        &copy; 2026 Developer Supreme Console | Bankha Wittaya School
    </div>
</div>

</body>
</html>
<?php
/**
 * ===================================================================================
 * END OF FILE: index.php
 * ===================================================================================
 */
ob_end_flush(); 
?>