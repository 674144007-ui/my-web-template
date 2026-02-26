<?php
/**
 * ===================================================================================
 * [CENTRAL HUB] FILE: index.php
 * ===================================================================================
 * โปรเจกต์: Bankha Withaya School - Classroom Management
 * หน้าที่: 
 * 1. ระบบรักษาความปลอดภัยและตรวจสอบ Brute-force
 * 2. ประมวลผลการตรวจสอบสิทธิ์และ Login
 * 3. จัดลำดับทิศทางการนำทาง (Routing) โดยให้สิทธิ์ Developer สำคัญสูงสุด
 * 4. แสดงหน้าจอ Login UI แบบอลังการ (Full CSS Animation)
 * ===================================================================================
 */

// -----------------------------------------------------------------------------------
// [SECTION 1] - การเริ่มต้นทรัพยากรระบบ (SYSTEM REQUIREMENTS)
// -----------------------------------------------------------------------------------
if (ob_get_level() == 0) {
    ob_start();
}

// โหลดไฟล์พื้นฐานสำหรับการทำงาน
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// เปิดรายงานข้อผิดพลาดระหว่างการพัฒนา
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ตัวแปรสำหรับเก็บข้อความแจ้งเตือนสีแดง
$error = "";

// -----------------------------------------------------------------------------------
// [SECTION 2] - ระบบป้องกันการเดารหัสผ่าน (BRUTE-FORCE ATTACK PROTECTION)
// -----------------------------------------------------------------------------------
/**
 * บันทึกประวัติการล็อกอินผิดพลาด เพื่อล็อกระบบชั่วคราว
 * หากผู้ใช้กรอกผิดเกิน 5 ครั้ง ระบบจะสั่งหยุดการทำงาน 30 วินาที
 */
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
}
if (!isset($_SESSION['last_attempt_time'])) {
    $_SESSION['last_attempt_time'] = time();
}

$LOCK_THRESHOLD = 5;  // จำนวนครั้งที่ยอมให้ผิดได้
$LOCK_TIME      = 30; // ระยะเวลาในการล็อก (วินาที)

if ($_SESSION['login_attempts'] >= $LOCK_THRESHOLD) {
    $current_time = time();
    $time_passed  = $current_time - $_SESSION['last_attempt_time'];
    $remaining    = $LOCK_TIME - $time_passed;
    
    if ($remaining > 0) {
        $error = "🚫 ความปลอดภัย: พยายามหลายครั้งเกินไป กรุณารออีก $remaining วินาที";
    } else {
        // หากครบกำหนดเวลา ล็อกระบบจะถูกปลดออกอัตโนมัติ
        $_SESSION['login_attempts'] = 0;
    }
}

// -----------------------------------------------------------------------------------
// [SECTION 3] - การนำทางอัตโนมัติ (AUTO-ROUTING FOR LOGGED-IN USERS)
// -----------------------------------------------------------------------------------
/**
 * ตรวจสอบว่าผู้ใช้ล็อกอินอยู่แล้วหรือไม่ หากมีข้อมูลใน Session อยู่แล้ว 
 * ระบบจะคัดแยกบทบาทและส่งตัวไปยัง Dashboard ที่ถูกต้องทันที
 */
if (isLoggedIn() && empty($error)) {
    $session_role = strtolower(trim($_SESSION['role'] ?? ''));

    // 🔥 DEVELOPER HARD-ROUTING:
    // หากตรวจพบสิทธิ์ 'developer' หรือ 'admin' ให้พุ่งตรงไปที่หน้า Dev Dashboard เท่านั้น
    if ($session_role === 'developer' || $session_role === 'admin' || $session_role === 'dev') {
        // อัปเดตสิทธิ์ให้เป็นมาตรฐานสากลของระบบ
        $_SESSION['role'] = 'developer';
        if (ob_get_length()) ob_clean();
        header("Location: dashboard_dev.php");
        exit;
    }

    // ลำดับการตรวจสอบบทบาทอื่นๆ
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
            // หากไม่พบบทบาทที่รู้จัก ให้ดีดกลับมาที่หน้าโปรไฟล์
            header("Location: profile.php");
            break;
    }
    exit;
}

// -----------------------------------------------------------------------------------
// [SECTION 4] - การประมวลผลคำขอเข้าสู่ระบบ (POST DATA PROCESSING)
// -----------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {

    // รับค่าจากฟอร์มและทำความสะอาดข้อมูล (Data Sanitization)
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';

    if ($username === "" || $password === "") {
        $error = "❌ กรุณากรอกชื่อผู้ใช้งานและรหัสผ่านให้ครบถ้วน";
    } else {

        /**
         * [DB QUERY] - ใช้ Prepared Statement เพื่อความปลอดภัยสูงสุด
         * เราดึงข้อมูล id, username, password, display_name, role และ class_level
         */
        $sql_auth = "SELECT id, username, password, display_name, role, class_level
                    FROM users
                    WHERE username = ?
                    LIMIT 1";

        if ($stmt = $conn->prepare($sql_auth)) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $stmt->store_result();

            // ตรวจสอบความถูกต้องของการผูกตัวแปร (Binding Result - ต้องครบ 6 ตัว)
            $stmt->bind_result(
                $u_id,
                $u_username,
                $u_password,
                $u_display,
                $u_role,
                $u_class
            );

            // หากพบชื่อผู้ใช้ในฐานข้อมูลเพียง 1 ราย
            if ($stmt->num_rows === 1) {
                $stmt->fetch();

                // ตรวจสอบความถูกต้องของรหัสผ่านที่เข้ารหัสไว้ (Verify Hash)
                if (password_verify($password, $u_password)) {

                    // [SECURITY] สร้าง Session ID ใหม่เพื่อป้องกันการโจมตี Session Fixation
                    session_regenerate_id(true);

                    // บันทึกข้อมูลประจำตัวลงในหน่วยความจำเซสชัน (จัดเต็มทุกฟิลด์ตามโครงสร้างเดิม)
                    $_SESSION['user_id']      = $u_id;
                    $_SESSION['username']     = $u_username;
                    $_SESSION['display_name'] = $u_display;
                    $_SESSION['class_level']  = $u_class;
                    $_SESSION['initiated_at'] = time();
                    
                    // ทำความสะอาดสิทธิ์และบันทึก
                    $final_r = strtolower(trim($u_role));
                    if ($final_r === 'admin' || $final_r === 'dev') {
                        $final_r = 'developer';
                    }
                    $_SESSION['role'] = $final_r;

                    // รีเซ็ตประวัติการพยายามล็อกอินเมื่อสำเร็จ
                    $_SESSION['login_attempts'] = 0;
                    $_SESSION['last_attempt_time'] = time();

                    /**
                     * 🔄 [FINAL GATEWAY]
                     * ตัดสินใจส่งตัวผู้ใช้งานไปยังหน้าปลายทางตามบทบาท
                     */
                    switch ($final_r) {
                        case 'developer':
                            header("Location: dashboard_dev.php");
                            break;
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
                            header("Location: index.php");
                            break;
                    }
                    exit;

                } else {
                    // รหัสผ่านผิดพลาด
                    $error = "❌ รหัสผ่านไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง";
                    $_SESSION['login_attempts']++;
                    $_SESSION['last_attempt_time'] = time();
                }

            } else {
                // ไม่พบชื่อผู้ใช้งาน
                $error = "❌ ไม่พบชื่อผู้ใช้งานนี้ในระบบ";
                $_SESSION['login_attempts']++;
                $_SESSION['last_attempt_time'] = time();
            }

            $stmt->close();
        } else {
            $error = "⚠️ ระบบขัดข้อง: ฐานข้อมูลไม่ตอบสนอง";
            error_log("Login Query Error: " . $conn->error);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ - Bankha Withaya School Portal</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Itim&display=swap" rel="stylesheet">
    
    <style>
        /* ===========================================================================
           [FULL CSS INTERFACE DESIGN] - ห้ามย่อแม้แต่บรรทัดเดียว
           =========================================================================== */
        
        :root {
            --primary-navy: #0048B4;
            --bright-blue: #1976FF;
            --gold-main: #FFD000;
            --gold-soft: #FFEA55;
            --danger-red: #d70040;
            --glass-white: rgba(255, 255, 255, 0.15);
            --glass-border: rgba(255, 255, 255, 0.3);
        }

        * {
            box-sizing: border-box;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
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
             * ระบบแอนิเมชันพื้นหลังหลายชั้น: ทอง Metallic, แสง Glow, และธงฟ้าเหลือง
             */
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

        /* --- Keyframes สำหรับความอลังการของหน้าจอ --- */
        @keyframes goldShine {
            0% { filter: brightness(1) contrast(1); }
            50% { filter: brightness(1.2) contrast(1.1); }
            100% { filter: brightness(1) contrast(1); }
        }

        @keyframes techFlow {
            0% { background-position: 50% 0%; }
            50% { background-position: 50% 100%; }
            100% { background-position: 50% 0%; }
        }

        @keyframes flagWaveSoft {
            0% { transform: skewX(0deg) translateX(0px); }
            25% { transform: skewX(-1.5deg) translateX(-5px); }
            50% { transform: skewX(0deg) translateX(0px); }
            75% { transform: skewX(1.5deg) translateX(5px); }
            100% { transform: skewX(0deg) translateX(0px); }
        }

        @keyframes glowPulse {
            0% { opacity: 1; }
            50% { opacity: 0.9; }
            100% { opacity: 1; }
        }

        /* --- โครงสร้างคอมโพเนนต์หลัก --- */
        .wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            z-index: 50;
            width: 100%;
            max-width: 480px;
            padding: 20px;
        }

        /* โลโก้โรงเรียนแบบอลังการพรีเมียม */
        .school-logo {
            text-align: center;
            margin-bottom: 30px;
            animation: fadeInEntrance 1.2s ease-out;
        }

        .school-logo img {
            width: 280px;
            height: auto;
            filter: drop-shadow(0 12px 25px rgba(0,0,0,0.4));
            animation: floatLogo 6s ease-in-out infinite, logoGlowPulse 4s ease-in-out infinite;
        }

        .school-title {
            display: block;
            margin-top: 15px;
            font-size: 1.6rem;
            font-weight: 700;
            color: #ffffff;
            letter-spacing: 2px;
            text-shadow: 0 4px 10px rgba(0,0,0,0.5);
            animation: fadeInTextEntrance 1.8s ease-out;
        }

        @keyframes floatLogo {
            0% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-10px) rotate(1deg); }
            100% { transform: translateY(0px) rotate(0deg); }
        }

        @keyframes logoGlowPulse {
            0% { filter: drop-shadow(0 6px 12px rgba(255,255,200,0.25)); }
            50% { filter: drop-shadow(0 10px 20px rgba(255,240,150,0.45)); }
            100% { filter: drop-shadow(0 6px 12px rgba(255,255,200,0.25)); }
        }

        @keyframes fadeInEntrance {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0px); }
        }

        @keyframes fadeInTextEntrance {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0px); }
        }

        /* การ์ดกระจกโปร่งแสง (Glassmorphism Concept) */
        .glass-card {
            width: 100%;
            padding: 45px 50px;
            border-radius: 35px;
            backdrop-filter: blur(20px);
            background: var(--glass-white);
            box-shadow: 0 35px 70px rgba(0,0,0,0.4);
            border: 1px solid var(--glass-border);
            color: white;
            position: relative;
            overflow: hidden;
            animation: cardEntranceEffect 1s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        @keyframes cardEntranceEffect {
            from { opacity: 0; transform: scale(0.9) translateY(50px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }

        h2 { text-align: center; margin: 0; font-size: 2.4rem; letter-spacing: -1px; text-shadow: 2px 2px 4px rgba(0,0,0,0.2); }
        .subtitle { text-align: center; margin-bottom: 35px; opacity: 0.9; font-size: 1.1rem; color: #fff; font-weight: 400; }

        /* การออกแบบฟอร์ม */
        .form-group { margin-bottom: 25px; text-align: left; }
        label { display: block; margin-bottom: 12px; font-weight: bold; font-size: 1.2rem; text-shadow: 1px 1px 2px rgba(0,0,0,0.3); }
        
        input {
            width: 100%;
            padding: 18px 22px;
            border-radius: 20px;
            border: 2px solid transparent;
            font-size: 1.1rem;
            outline: none;
            background: rgba(255, 255, 255, 0.9);
            color: #1e293b;
            box-sizing: border-box;
            transition: all 0.3s;
            font-family: 'Itim', cursive;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }

        input:focus {
            border-color: var(--gold-main);
            background: #ffffff;
            box-shadow: 0 0 25px rgba(255, 208, 0, 0.5);
            transform: scale(1.02);
        }

        .btn-login {
            width: 100%;
            padding: 18px;
            border: none;
            border-radius: 20px;
            background: #ffffff;
            color: var(--danger-red);
            font-size: 1.5rem;
            cursor: pointer;
            font-weight: bold;
            box-shadow: 0 10px 25px rgba(0,0,0,0.25);
            font-family: 'Itim', cursive;
            margin-top: 15px;
            transition: all 0.4s;
        }

        .btn-login:hover {
            background: var(--gold-main);
            color: #000;
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(255, 208, 0, 0.4);
        }

        .btn-login:active {
            transform: translateY(2px);
        }

        /* กล่องแจ้งเตือนข้อผิดพลาด */
        .error-container {
            background: rgba(255, 255, 255, 0.2);
            color: #fff;
            padding: 15px;
            border-radius: 18px;
            margin-bottom: 30px;
            text-align: center;
            backdrop-filter: blur(10px);
            border-left: 6px solid var(--gold-main);
            font-weight: bold;
            animation: shakeAlert 0.5s ease-in-out;
        }

        @keyframes shakeAlert {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-7px); }
            40%, 80% { transform: translateX(7px); }
        }

        .footer-info {
            margin-top: 50px;
            font-size: 0.9rem;
            color: rgba(255,255,255,0.7);
            text-align: center;
            letter-spacing: 1.2px;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.4);
        }

    </style>
</head>
<body>

<div class="wrapper">
    <div class="school-logo">
        <img src="logo.png" alt="School Logo" onerror="this.style.display='none'">
        <span class="school-title">Bankha Withaya School</span>
    </div>

    <div class="glass-card">
        <h2>เข้าสู่ระบบ</h2>
        <div class="subtitle">Classroom Management System v7.0</div>

        <?php if ($error): ?>
            <div class="error-container">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="index.php">
            <div class="form-group">
                <label for="username">Username (ชื่อผู้ใช้)</label>
                <input type="text" id="username" name="username" placeholder="ระบุชื่อผู้ใช้งานของคุณ..." required autocomplete="username">
            </div>

            <div class="form-group">
                <label for="password">Password (รหัสผ่าน)</label>
                <input type="password" id="password" name="password" placeholder="ระบุรหัสผ่านของคุณ..." required autocomplete="current-password">
            </div>

            <button class="btn-login" type="submit">ยืนยันและเข้าสู่ระบบ</button>
        </form>
    </div>

    <div class="footer-info">
        &copy; 2026 Developer Control Center | Bankha Wittaya School Platform
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