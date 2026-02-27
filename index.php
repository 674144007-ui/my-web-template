<?php
require_once 'db.php';
require_once 'auth.php';
require_once 'logger.php'; // เพิ่มไฟล์ Logger

// แสดง error ตอนพัฒนา (ลบเมื่อออนไลน์)
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

$error = "";

// ⚠️ brute-force protection
if (!isset($_SESSION['login_attempts'])) $_SESSION['login_attempts'] = 0;
if (!isset($_SESSION['last_attempt_time'])) $_SESSION['last_attempt_time'] = time();

$LOCK_THRESHOLD = 5;
$LOCK_TIME = 30;

if ($_SESSION['login_attempts'] >= $LOCK_THRESHOLD) {
    $remaining = $LOCK_TIME - (time() - $_SESSION['last_attempt_time']);
    if ($remaining > 0) {
        $error = "พยายามหลายครั้งเกินไป กรุณารอ $remaining วินาที";
    } else {
        $_SESSION['login_attempts'] = 0; // unlock
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {

    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === "" || $password === "") {
        $error = "กรุณากรอกข้อมูลให้ครบ";
    } else {

        // ⭐ ปรับปรุง Query: เพิ่มการดึงค่า is_deleted และ class_id
        $stmt = $conn->prepare("
            SELECT id, username, password, display_name, role, class_level, class_id, is_deleted
            FROM users
            WHERE username = ?
            LIMIT 1
        ");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();

        // Bind ตัวแปรให้ครบตามจำนวนที่ SELECT มา (8 ตัว)
        $stmt->bind_result(
            $id,
            $db_username,
            $db_password,
            $display_name,
            $role,
            $class_level,
            $class_id,
            $is_deleted
        );

        if ($stmt->num_rows === 1) {
            $stmt->fetch();

            // เช็คว่า User โดนลบ (Soft Delete) ไปหรือยัง
            if ($is_deleted == 1) {
                $error = "บัญชีนี้ถูกระงับหรือถูกลบออกจากระบบแล้ว";
                systemLog($id, 'LOGIN_FAILED', "Attempted to login with soft-deleted account: $username");
            } 
            else if (password_verify($password, $db_password)) {

                session_regenerate_id(true);

                $_SESSION['user_id']       = $id;
                $_SESSION['username']      = $db_username;
                $_SESSION['display_name']  = $display_name;
                $_SESSION['role']          = $role;
                $_SESSION['class_level']   = $class_level; 
                $_SESSION['class_id']      = $class_id; // เก็บ class_id ระบบใหม่

                $_SESSION['login_attempts'] = 0;
                $_SESSION['last_attempt_time'] = time();

                // 📝 บันทึก Log การเข้าสู่ระบบสำเร็จ
                systemLog($id, 'LOGIN_SUCCESS', "User $username logged in successfully as $role");

                switch ($role) {
                    case 'developer': header("Location: dashboard_dev.php"); break;
                    case 'teacher':   header("Location: dashboard_teacher.php"); break;
                    case 'student':   header("Location: dashboard_student.php"); break;
                    case 'parent':    header("Location: dashboard_parent.php"); break;
                    default:          header("Location: index.php"); break;
                }
                exit;

            } else {
                $error = "รหัสผ่านไม่ถูกต้อง";
                $_SESSION['login_attempts']++;
                $_SESSION['last_attempt_time'] = time();
                systemLog(null, 'LOGIN_FAILED', "Invalid password for username: $username");
            }

        } else {
            $error = "ไม่พบผู้ใช้งานนี้";
            $_SESSION['login_attempts']++;
            $_SESSION['last_attempt_time'] = time();
            systemLog(null, 'LOGIN_FAILED', "Username not found: $username");
        }

        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Login</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Itim&display=swap" rel="stylesheet">
<style>
body {
    margin:0; padding:0;
    font-family: 'Itim', cursive;
    height:100vh;
    display:flex;
    justify-content:center;
    align-items:center;
    overflow:hidden;

    background:
        linear-gradient(
            135deg,
            rgba(255,222,100,0.35),
            rgba(255,240,180,0.25),
            rgba(255,230,80,0.45)
        ),
        linear-gradient(
            135deg,
            #0048B4,
            #0A60E0,
            #1976FF,
            #FFD000,
            #FFEA55
        ),
        linear-gradient(
            to bottom,
            #0057B7 0%,
            #0057B7 50%,
            #FFD600 50%,
            #FFD600 100%
        ),
        radial-gradient(circle at 65% 70%, rgba(255,255,255,0.18), transparent 70%);

    background-size:
        200% 200%,
        180% 180%,
        100% 100%,
        240% 240%;

    animation:
        goldShine 7s linear infinite,
        techFlow 18s ease-in-out infinite,
        flagWaveSoft 10s ease-in-out infinite,
        glowPulse 9s ease-in-out infinite;
}

@keyframes goldShine {
    0%   { filter: brightness(1) contrast(1); }
    50%  { filter: brightness(1.25) contrast(1.15); }
    100% { filter: brightness(1) contrast(1); }
}

@keyframes techFlow {
    0%   { background-position: 50% 0%; }
    50%  { background-position: 50% 100%; }
    100% { background-position: 50% 0%; }
}

@keyframes flagWaveSoft {
    0%   { transform: skewX(0deg) translateX(0px); }
    25%  { transform: skewX(-1.5deg) translateX(-7px); }
    50%  { transform: skewX(0deg) translateX(0px); }
    75%  { transform: skewX(1.5deg) translateX(7px); }
    100% { transform: skewX(0deg) translateX(0px); }
}

@keyframes glowPulse {
    0%   { opacity:1; }
    50%  { opacity:0.9; }
    100% { opacity:1; }
}

.glass-card {
    width:380px; padding:30px 40px; border-radius:20px;
    backdrop-filter: blur(18px); background: rgba(255,255,255,0.15);
    box-shadow: 0 20px 45px rgba(0,0,0,0.25);
    border:1px solid rgba(255,255,255,0.3); color:white;
}
h2 { text-align:center; margin:0; font-size:2rem; }
.subtitle { text-align:center; margin-bottom:15px; opacity:0.85; }
label { display:block; margin:10px 0 5px; }
input {
    width:100%; padding:12px; border-radius:12px; border:none;
    margin-bottom:15px; font-size:1rem; outline:none; background:rgba(255,255,255,0.7);
    box-sizing: border-box;
}
.btn-login {
    width:100%; padding:12px; border:none; border-radius:12px;
    background:#ffffff; color:#d70040; font-size:1.1rem; cursor:pointer; font-weight:bold;
}
.btn-login:hover { background:#ffecec; }
.error {
    background:rgba(38, 0, 255, 0.4); padding:10px; border-radius:10px;
    margin-bottom:10px; text-align:center; backdrop-filter:blur(5px);
}

.school-logo {
    text-align: center;
    margin-bottom: 25px;
    animation: fadeIn 1.2s ease-out;
}

.school-logo img {
    width: 300px; 
    height: auto;
    filter: drop-shadow(0 6px 10px rgba(0,0,0,0.35));
    animation:
        floatLogo 6s ease-in-out infinite,
        glowPulseLogo 4s ease-in-out infinite;
}

.school-title {
    display: block;
    margin-top: 10px;
    font-size: 1.35rem;
    font-weight: 700;
    color: #ffffff;
    letter-spacing: 1px;
    text-shadow: 0 2px 6px rgba(0,0,0,0.4);
    animation: fadeInText 1.8s ease-out;
}

@keyframes floatLogo {
    0%   { transform: translateY(0px); }
    50%  { transform: translateY(-6px); }
    100% { transform: translateY(0px); }
}

@keyframes glowPulseLogo {
    0%   { filter: drop-shadow(0 6px 12px rgba(255,255,200,0.25)); }
    50%  { filter: drop-shadow(0 8px 15px rgba(255,240,150,0.45)); }
    100% { filter: drop-shadow(0 6px 12px rgba(255,255,200,0.25)); }
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to   { opacity: 1; transform: translateY(0px); }
}

@keyframes fadeInText {
    from { opacity: 0; transform: translateY(6px); }
    to   { opacity: 1; transform: translateY(0px); }
}
</style>
</head>
<body>

<div class="school-logo">
    <img src="logo.png" alt="School Logo" onerror="this.style.display='none'">
    <span class="school-title">Bankha Withaya School</span>
</div>

<div class="glass-card">
    <h2>เข้าสู่ระบบ</h2>
    <div class="subtitle">Classroom Management System</div>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <label>Username :</label>
        <input type="text" name="username" required autocomplete="username">

        <label>Password :</label>
        <input type="password" name="password" required autocomplete="current-password">

        <button class="btn-login" type="submit">เข้าสู่ระบบ</button>
    </form>
</div>
</body>
</html>