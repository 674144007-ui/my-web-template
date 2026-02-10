<?php
// เริ่ม Buffer บรรทัดแรกสุด
if (ob_get_level() == 0) ob_start();

require_once 'auth.php';
require_once 'db.php';

// แสดง Error เฉพาะตอน Dev
ini_set('display_errors', 1);
error_reporting(E_ALL);

$error = "";

// 🔄 ถ้า Login อยู่แล้ว ให้ไป Dashboard เลย (ไม่ต้อง Login ซ้ำ)
if (isLoggedIn()) {
    $role = $_SESSION['role'];
    $redirect = "index.php";
    switch ($role) {
        case 'student':   $redirect = "dashboard_student.php"; break;
        case 'teacher':   $redirect = "dashboard_teacher.php"; break;
        case 'parent':    $redirect = "dashboard_parent.php"; break;
        case 'developer': $redirect = "dashboard_dev.php"; break;
    }
    header("Location: " . $redirect);
    exit;
}

// ตรวจสอบการ Submit Form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $error = "กรุณากรอกข้อมูลให้ครบ";
    } else {
        // ใช้ SQL Prepared Statement เพื่อความปลอดภัย
        $stmt = $conn->prepare("
            SELECT id, username, password, display_name, role, class_level, subject_group, teacher_department
            FROM users
            WHERE username = ?
            LIMIT 1
        ");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();
        $stmt->bind_result($id, $db_user, $db_pass, $db_name, $db_role, $db_class, $db_subj, $db_dept);

        if ($stmt->num_rows === 1) {
            $stmt->fetch();
            if (password_verify($password, $db_pass)) {
                
                // ✅ Login สำเร็จ: เก็บข้อมูลลง Session
                $_SESSION['user_id'] = $id;
                $_SESSION['username'] = $db_user;
                $_SESSION['display_name'] = $db_name;
                $_SESSION['role'] = $db_role;
                $_SESSION['class_level'] = $db_class;
                $_SESSION['subject_group'] = $db_subj;
                $_SESSION['teacher_department'] = $db_dept;

                // ⛔️ ห้ามใช้ session_regenerate_id บน InfinityFree/MAMP เพราะ Session จะหลุดง่าย
                // session_regenerate_id(true);
                
                // ✅ บันทึก Session ลงไฟล์ทันที! (นี่คือตัวแก้ Loop ที่สำคัญที่สุด)
                session_write_close();

                // เลือกหน้าที่จะไป
                $target = "index.php";
                switch ($db_role) {
                    case 'student':   $target = "dashboard_student.php"; break;
                    case 'teacher':   $target = "dashboard_teacher.php"; break;
                    case 'parent':    $target = "dashboard_parent.php"; break;
                    case 'developer': $target = "dashboard_dev.php"; break;
                }

                // Redirect
                header("Location: " . $target);
                exit;

            } else {
                $error = "รหัสผ่านไม่ถูกต้อง";
            }
        } else {
            $error = "ไม่พบผู้ใช้งานนี้";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Login - Bankha Withaya School</title>
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
        /* ⭐ Metallic Yellow Shine */
        linear-gradient(
            135deg,
            rgba(255,222,100,0.35),
            rgba(255,240,180,0.25),
            rgba(255,230,80,0.45)
        ),
        /* ⭐ Modern Blue–Yellow */
        linear-gradient(
            135deg,
            #0048B4,
            #0A60E0,
            #1976FF,
            #FFD000,
            #FFEA55
        ),
        /* ⭐ Blue–Yellow Flag */
        linear-gradient(
            to bottom,
            #0057B7 0%,
            #0057B7 50%,
            #FFD600 50%,
            #FFD600 100%
        ),
        /* ⭐ Glow highlight */
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

/* ✨ Metallic Shine */
@keyframes goldShine {
    0%   { filter: brightness(1) contrast(1); }
    50%  { filter: brightness(1.25) contrast(1.15); }
    100% { filter: brightness(1) contrast(1); }
}

/* 🌈 Gradient flow ช้า */
@keyframes techFlow {
    0%   { background-position: 50% 0%; }
    50%  { background-position: 50% 100%; }
    100% { background-position: 50% 0%; }
}

/* 🌬 พริ้วลมนุ่ม (Soft Wave) */
@keyframes flagWaveSoft {
    0%   { transform: skewX(0deg) translateX(0px); }
    25%  { transform: skewX(-1.5deg) translateX(-7px); }
    50%  { transform: skewX(0deg) translateX(0px); }
    75%  { transform: skewX(1.5deg) translateX(7px); }
    100% { transform: skewX(0deg) translateX(0px); }
}

/* 💫 Glow pulse */
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
    transition: 0.3s;
}
.btn-login:hover { background:#ffecec; transform: scale(1.02); }
.error {
    background:rgba(255, 0, 0, 0.6); padding:10px; border-radius:10px;
    margin-bottom:10px; text-align:center; backdrop-filter:blur(5px);
    border: 1px solid rgba(255,255,255,0.3);
}

.school-logo {
    text-align: center;
    margin-bottom: 25px;
    animation: fadeIn 1.2s ease-out;
}

.school-logo img {
    width: 250px; 
    height: auto;
    filter: drop-shadow(0 6px 10px rgba(0,0,0,0.35));

    /* ทำให้โลโก้ดูมีชีวิต + หรู */
    animation:
        floatLogo 6s ease-in-out infinite,
        glowPulse 4s ease-in-out infinite;
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

/* เอฟเฟกต์โลโก้ลอยเบา ๆ */
@keyframes floatLogo {
    0%   { transform: translateY(0px); }
    50%  { transform: translateY(-6px); }
    100% { transform: translateY(0px); }
}

/* เฟดเข้า */
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

<div style="display:flex; flex-direction:column; align-items:center;">
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
</div>
</body>
</html>