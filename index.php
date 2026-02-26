<?php
/**
 * ===================================================================================
 * FILE: index.php (CENTRAL AUTHENTICATION & ROUTING SYSTEM)
 * ===================================================================================
 * โปรเจกต์: ระบบบริหารจัดการชั้นเรียน โรงเรียนบ้านคาวิทยา (Bankha Withaya School)
 * หน้าที่: 
 * 1. แสดงหน้าจอ Login (UI)
 * 2. ประมวลผลการตรวจสอบสิทธิ์ (Authentication)
 * 3. คัดแยกผู้ใช้งานไปยัง Dashboard ที่ถูกต้องตามบทบาท (Role Routing)
 * 4. แก้ไขปัญหาสิทธิ์ Admin ค้าง/ไม่ตรง โดยการเปลี่ยนเป็น Developer อัตโนมัติ
 * ===================================================================================
 */

// [SECTION 1] - การตั้งค่าเริ่มต้นและการจัดการหน่วยความจำ (BUFFER MANAGEMENT)
// -----------------------------------------------------------------------------------
// ใช้ ob_start() บรรทัดแรกสุด เพื่อป้องกันข้อผิดพลาด "Warning: Cannot modify header information"
// ซึ่งมักเกิดจากการส่ง Output หรือช่องว่างออกมาจากไฟล์ require_once ก่อนการเรียกใช้ header()
if (ob_get_level() == 0) {
    ob_start();
}

// โหลดไฟล์ที่จำเป็น
require_once 'auth.php'; // ระบบจัดการ Session และฟังก์ชัน isLoggedIn()
require_once 'db.php';   // การเชื่อมต่อฐานข้อมูลหลัก ($conn)

// ตั้งค่าการรายงานข้อผิดพลาด (เปิดเฉพาะระหว่างการพัฒนา)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ตัวแปรสำหรับเก็บข้อความแจ้งเตือนข้อผิดพลาด
$error = "";

/**
 * ===================================================================================
 * [FUNCTION] - getDashboardByRole($role)
 * ทำหน้าที่คืนค่าชื่อไฟล์ Dashboard ที่เหมาะสมตามบทบาทของผู้ใช้
 * ===================================================================================
 */
function getDashboardByRole($role) {
    // ล้างค่าว่างและแปลงเป็นตัวพิมพ์เล็กทั้งหมดเพื่อความแม่นยำ
    $role = strtolower(trim($role));
    
    switch ($role) {
        case 'student':
            return "dashboard_student.php";
        case 'teacher':
            return "dashboard_teacher.php";
        case 'parent':
            return "dashboard_parent.php";
        case 'developer':
            return "dashboard_dev.php";
        case 'admin':
            // 🔥 กฎเหล็ก: หากสิทธิ์เป็น admin ให้ย้ายไปใช้หน้า dashboard_dev.php เช่นกัน
            return "dashboard_dev.php"; 
        default:
            // หากไม่พบบทบาทที่รู้จัก ให้ทำลาย Session และส่งออกไปหน้า Logout
            return "logout.php"; 
    }
}

/**
 * ===================================================================================
 * [LOGIC] - ตรวจสอบสถานะการล็อกอินปัจจุบัน (SESSION VALIDATION)
 * ===================================================================================
 * หากผู้ใช้งานมี Session เดิมอยู่แล้ว ระบบจะทำการคัดแยกไปยัง Dashboard ทันที
 * โดยไม่มีการแสดงหน้า Login ซ้ำ (ป้องกันอาการ Login Loop)
 */
if (isLoggedIn()) {
    $role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';
    
    // 🔥 AUTO-FIX LOGIC:
    // หากพบว่า Session ค้างเป็นบทบาท 'admin' ระบบจะอัปเดตเป็น 'developer' ให้ทันที
    if ($role === 'admin') {
        $_SESSION['role'] = 'developer';
        $role = 'developer';
    }

    $redirect_page = getDashboardByRole($role);
    
    // ล้างบัฟเฟอร์ก่อนการย้ายหน้า
    if (ob_get_length()) ob_clean();
    header("Location: " . $redirect_page);
    exit;
}

/**
 * ===================================================================================
 * [LOGIC] - การประมวลผลเมื่อมีการส่งฟอร์ม (POST METHOD HANDLING)
 * ===================================================================================
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // รับค่าและทำความสะอาดข้อมูลเบื้องต้น
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';

    if (empty($username) || empty($password)) {
        $error = "❌ กรุณากรอกชื่อผู้ใช้และรหัสผ่านให้ครบถ้วน";
    } else {
        /**
         * การ Query ข้อมูลผู้ใช้งาน
         * ใช้ Prepared Statements เพื่อป้องกัน SQL Injection (ความปลอดภัยสูงสุด)
         */
        $sql = "SELECT id, username, password, display_name, role, class_level, subject_group, teacher_department 
                FROM users 
                WHERE username = ? 
                LIMIT 1";

        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $stmt->store_result();
            
            // ตรวจสอบว่าพบชื่อผู้ใช้งานนี้ในระบบหรือไม่
            if ($stmt->num_rows === 1) {
                // ผูกตัวแปรเพื่อรับค่าจาก Database
                $stmt->bind_result($db_id, $db_user, $db_pass, $db_display, $db_role, $db_class, $db_subj, $db_dept);
                $stmt->fetch();

                // ตรวจสอบรหัสผ่านที่เข้ารหัสไว้ (Password Hashing)
                if (password_verify($password, $db_pass)) {
                    
                    // 🔴 การจัดการความปลอดภัย Session
                    // เปลี่ยน Session ID ใหม่ทุกครั้งที่ Login เพื่อป้องกัน Session Fixation
                    session_regenerate_id(true);
                    
                    // 🔥 AUTO-FIX LOGIC (DB Level):
                    // หากในฐานข้อมูลยังบันทึกเป็น 'admin' ให้ถือว่าเป็น 'developer'
                    $final_role = strtolower(trim($db_role));
                    if ($final_role === 'admin') {
                        $final_role = 'developer';
                    }

                    // ✅ สร้างหน่วยความจำ Session สำหรับการใช้งานตลอดทั้งระบบ
                    $_SESSION['user_id']            = $db_id;
                    $_SESSION['username']           = $db_user;
                    $_SESSION['display_name']       = $db_display;
                    $_SESSION['role']               = $final_role;
                    $_SESSION['class_level']        = $db_class;
                    $_SESSION['subject_group']      = $db_subj;
                    $_SESSION['teacher_department'] = $db_dept;
                    $_SESSION['login_time']         = time();

                    // บันทึก Session ลงพื้นที่เก็บข้อมูลทันที (ป้องกันปัญหากับบาง Browser)
                    session_write_close();

                    // ค้นหาเป้าหมายและทำการ Redirect
                    $target_dashboard = getDashboardByRole($final_role);
                    
                    if (ob_get_length()) ob_clean();
                    header("Location: " . $target_dashboard);
                    exit;

                } else {
                    $error = "❌ รหัสผ่านไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง";
                }
            } else {
                $error = "❌ ไม่พบชื่อผู้ใช้งานนี้ในระบบ";
            }
            $stmt->close();
        } else {
            // กรณีเกิดความผิดพลาดในระดับ Query หรือการเชื่อมต่อ Database
            $error = "⚠️ ระบบขัดข้อง: ไม่สามารถเตรียมคำสั่ง SQL ได้";
            error_log("SQL Prepare Error: " . $conn->error);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ - โรงเรียนบ้านคาวิทยา | Bankha Withaya School</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Itim&display=swap" rel="stylesheet">
    
    <style>
        /* ===========================================================================
           [CSS DESIGN] - ปรับปรุงความหรูหราและความยาวของโค้ดสไตล์ UI/UX สมัยใหม่
           =========================================================================== */
        
        :root {
            --blue-primary: #0057B7;
            --yellow-primary: #FFD600;
            --danger: #d70040;
            --glass-bg: rgba(255, 255, 255, 0.15);
            --glass-border: rgba(255, 255, 255, 0.3);
        }

        body {
            margin: 0; padding: 0;
            font-family: 'Itim', cursive;
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            overflow: hidden;
            background-color: var(--blue-primary);

            /* 🌟 พื้นหลังแบบ Multi-Layered Animation (เหมือนธงปลิวและประกายทอง) */
            background:
                linear-gradient(135deg, rgba(255,222,100,0.35), rgba(255,240,180,0.25), rgba(255,230,80,0.45)),
                linear-gradient(135deg, #0048B4, #0A60E0, #1976FF, #FFD000, #FFEA55),
                linear-gradient(to bottom, #0057B7 0%, #0057B7 50%, #FFD600 50%, #FFD600 100%),
                radial-gradient(circle at 65% 70%, rgba(255,255,255,0.18), transparent 70%);

            background-size: 200% 200%, 180% 180%, 100% 100%, 240% 240%;

            animation:
                goldShine 7s linear infinite,
                techFlow 18s ease-in-out infinite,
                flagWaveSoft 10s ease-in-out infinite,
                glowPulse 9s ease-in-out infinite;
        }

        /* --- Keyframes สำหรับความพริ้วไหวของ UI --- */
        @keyframes goldShine {
            0% { filter: brightness(1) contrast(1); }
            50% { filter: brightness(1.25) contrast(1.15); }
            100% { filter: brightness(1) contrast(1); }
        }

        @keyframes techFlow {
            0% { background-position: 50% 0%; }
            50% { background-position: 50% 100%; }
            100% { background-position: 50% 0%; }
        }

        @keyframes flagWaveSoft {
            0% { transform: skewX(0deg) translateX(0px); }
            25% { transform: skewX(-1.5deg) translateX(-7px); }
            50% { transform: skewX(0deg) translateX(0px); }
            75% { transform: skewX(1.5deg) translateX(7px); }
            100% { transform: skewX(0deg) translateX(0px); }
        }

        @keyframes glowPulse {
            0% { opacity: 1; }
            50% { opacity: 0.9; }
            100% { opacity: 1; }
        }

        /* --- Glassmorphism Card Design --- */
        .glass-card {
            width: 380px;
            padding: 30px 40px;
            border-radius: 25px;
            backdrop-filter: blur(20px);
            background: var(--glass-bg);
            box-shadow: 0 25px 50px rgba(0,0,0,0.3);
            border: 1px solid var(--glass-border);
            color: white;
            transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            animation: fadeIn 1s ease-out;
        }

        .glass-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 35px 65px rgba(0,0,0,0.4);
        }

        .main-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            z-index: 10;
        }

        .school-logo {
            text-align: center;
            margin-bottom: 30px;
            animation: fadeInLogo 1.5s ease-out;
        }

        .school-logo img {
            width: 150px; /* ปรับขนาดโลโก้ให้พอดี */
            height: auto;
            filter: drop-shadow(0 10px 15px rgba(0,0,0,0.4));
            animation: floatLogo 5s ease-in-out infinite;
        }

        .school-title {
            display: block;
            margin-top: 15px;
            font-size: 1.5rem;
            font-weight: bold;
            color: #ffffff;
            text-transform: uppercase;
            letter-spacing: 1px;
            text-shadow: 2px 2px 10px rgba(0,0,0,0.5);
        }

        @keyframes floatLogo {
            0% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-10px) rotate(2deg); }
            100% { transform: translateY(0px) rotate(0deg); }
        }

        @keyframes fadeInLogo {
            from { opacity: 0; transform: scale(0.8); }
            to { opacity: 1; transform: scale(1); }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* --- Form Elements --- */
        h2 { text-align: center; margin: 0; font-size: 2.2rem; font-weight: 600; letter-spacing: -1px; }
        .subtitle { text-align: center; margin-bottom: 25px; opacity: 0.9; font-size: 1rem; color: var(--yellow-primary); }

        .input-group { position: relative; margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: bold; font-size: 1.1rem; }
        
        input {
            width: 100%;
            padding: 14px 18px;
            border-radius: 15px;
            border: 2px solid transparent;
            font-size: 1rem;
            outline: none;
            background: rgba(255, 255, 255, 0.85);
            color: #333;
            box-sizing: border-box;
            transition: all 0.3s;
            font-family: 'Itim', cursive;
        }

        input:focus {
            border-color: var(--yellow-primary);
            background: #ffffff;
            box-shadow: 0 0 15px rgba(255, 214, 0, 0.4);
        }

        .btn-login {
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 15px;
            background: var(--yellow-primary);
            color: #000;
            font-size: 1.25rem;
            cursor: pointer;
            font-weight: bold;
            transition: all 0.3s;
            box-shadow: 0 8px 15px rgba(0,0,0,0.2);
            font-family: 'Itim', cursive;
            margin-top: 10px;
        }

        .btn-login:hover {
            background: #ffffff;
            transform: scale(1.03);
            box-shadow: 0 12px 25px rgba(0,0,0,0.3);
        }

        .btn-login:active {
            transform: scale(0.98);
        }

        .error {
            background: var(--danger);
            color: white;
            padding: 12px;
            border-radius: 12px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: bold;
            border: 1px solid rgba(255,255,255,0.4);
            animation: shake 0.5s ease-in-out;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-5px); }
            40%, 80% { transform: translateX(5px); }
        }

        /* Footer */
        .footer-text {
            margin-top: 30px;
            font-size: 0.85rem;
            color: rgba(255,255,255,0.6);
            text-align: center;
        }

    </style>
</head>
<body>

<div class="main-wrapper">
    <div class="school-logo">
        <img src="logo.png" alt="School Logo" onerror="this.style.display='none'">
        <span class="school-title">Bankha Withaya School</span>
    </div>

    <div class="glass-card">
        <h2>เข้าสู่ระบบ</h2>
        <div class="subtitle">ระบบบริหารจัดการชั้นเรียนอัตโนมัติ</div>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="index.php">
            <div class="input-group">
                <label for="username">ชื่อผู้ใช้งาน (Username)</label>
                <input type="text" id="username" name="username" placeholder="ระบุชื่อผู้ใช้งาน..." required autocomplete="username">
            </div>

            <div class="input-group">
                <label for="password">รหัสผ่าน (Password)</label>
                <input type="password" id="password" name="password" placeholder="ระบุรหัสผ่าน..." required autocomplete="current-password">
            </div>

            <button class="btn-login" type="submit">ยืนยันการเข้าสู่ระบบ</button>
        </form>

        <div class="footer-text">
            &copy; 2026 Bankha Withaya School Admin System
        </div>
    </div>
</div>

</body>
</html>
<?php
/**
 * ===================================================================================
 * END OF FILE
 * ===================================================================================
 */
ob_end_flush(); 
?>