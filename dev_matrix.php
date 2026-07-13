<?php
// dev_matrix.php - Matrix Emulation System & Sandbox (Phase 4)
// ระบบสวมรอยตัวตนผู้ใช้และเข้าสู่โหมด Sandbox สำหรับ Developer
require_once __DIR__ . '/env_loader.php';
require_once 'config.php';
require_once 'db.php';
require_once 'security.php';
require_once 'auth.php';
require_once 'logger.php';

// บังคับสิทธิ์ต้องเป็น Developer เท่านั้น
requireRole(['developer']);
$dev_id = $_SESSION['user_id'];
$dev_name = $_SESSION['username'];
$csrf = generate_csrf_token();
$message = '';

// =========================================================
// 1. จัดการ POST Requests (ระบบสวมรอย - Impersonation)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("CSRF Token Verification Failed.");
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'impersonate') {
        $target_id = intval($_POST['target_id']);
        
        // ดึงข้อมูล User ที่ต้องการจะสวมรอย
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role != 'developer' AND is_deleted = 0");
        $stmt->execute([$target_id]);
        $stmt->execute();
        $target_user = $stmt->fetch();
        

        if ($target_user) {
            // 1. บันทึก Log การกระทำของ Developer ว่าเข้าไปสวมรอยใคร (เพื่อความโปร่งใส)
            systemLog($dev_id, 'MATRIX_EMULATION', "Developer [{$dev_name}] initiated Matrix override to user ID: {$target_user['id']} ({$target_user['username']} - {$target_user['role']})");

            // 2. เขียนทับ Session ปัจจุบันให้กลายเป็น User คนนั้น
            $_SESSION['user_id'] = $target_user['id'];
            $_SESSION['username'] = $target_user['username'];
            $_SESSION['role'] = $target_user['role'];
            $_SESSION['display_name'] = $target_user['display_name'];
            $_SESSION['class_id'] = $target_user['class_level'];
            $_SESSION['class_id'] = $target_user['class_id'];
            
            // เพิ่ม Flag พิเศษเผื่อนำไปใช้แสดงป้ายเตือนในหน้า Dashboard ของ User ว่า "กำลังถูกจำลองโดย Dev" (ถ้าต้องการ)
            $_SESSION['is_emulating'] = true;
            $_SESSION['emulated_by'] = $dev_name;

            // 3. เปลี่ยนเส้นทางไปยังหน้า Dashboard ของ Role นั้นๆ ทันที
            if ($target_user['role'] === 'student') {
                header("Location: dashboard_student.php");
            } elseif ($target_user['role'] === 'teacher') {
                header("Location: dashboard_teacher.php");
            } elseif ($target_user['role'] === 'parent') {
                header("Location: dashboard_parent.php");
            }
            exit;
        } else {
            $message = "❌ ไม่พบผู้ใช้งานที่ระบุ หรือบัญชีถูกระงับ";
        }
    }
}

// =========================================================
// 2. ดึงข้อมูล User ทั้งหมดมาแสดงผลเพื่อเลือกสวมรอย
// =========================================================
$users = [
    'student' => [],
    'teacher' => [],
    'parent' => []
];

// ดึงข้อมูลพร้อมชื่อห้องเรียน (ถ้ามี)
$res = $pdo->query("
    SELECT u.*, c.class_name 
    FROM users u 
    LEFT JOIN classes c ON u.class_id = c.id 
    WHERE u.role != 'developer' AND u.is_deleted = 0 
    ORDER BY u.role ASC, u.username ASC
");

if ($res) {
    while ($row = $res->fetch()) {
        $users[$row['role']][] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Matrix Emulation | Developer</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;600;700&family=Orbitron:wght@500;700&family=Share+Tech+Mono&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --bg-dark: #020617;
            --bg-panel: rgba(15, 23, 42, 0.7);
            --border-glass: rgba(148, 163, 184, 0.2);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --neon-blue: #38bdf8;
            --neon-purple: #c084fc;
            --neon-green: #4ade80;
            --neon-red: #f87171;
            --neon-yellow: #facc15;
        }

        body {
            margin: 0; padding: 0; font-family: 'Prompt', sans-serif;
            background-color: var(--bg-dark); color: var(--text-main);
            background-image: 
                linear-gradient(rgba(56, 189, 248, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(56, 189, 248, 0.03) 1px, transparent 1px);
            background-size: 30px 30px;
            min-height: 100vh; display: flex; flex-direction: column;
        }

        /* Topbar */
        .topbar {
            height: 70px; background: rgba(2, 6, 23, 0.9); backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--neon-blue);
            box-shadow: 0 0 20px rgba(56,189,248,0.2);
            display: flex; justify-content: space-between; align-items: center; padding: 0 30px;
            position: sticky; top: 0; z-index: 100;
        }
        .topbar h1 { margin: 0; font-family: 'Orbitron', sans-serif; font-size: 1.5rem; color: var(--neon-blue); letter-spacing: 2px;}
        .topbar h1 span { color: var(--text-main); font-family: 'Share Tech Mono'; font-size: 1.2rem;}
        
        .btn-back { color: var(--text-muted); text-decoration: none; font-weight: bold; border: 1px solid var(--border-glass); padding: 8px 15px; border-radius: 8px; transition: 0.3s; font-family: 'Share Tech Mono';}
        .btn-back:hover { background: rgba(255,255,255,0.1); color: white; }

        .container { max-width: 1400px; margin: 30px auto; padding: 0 20px; flex: 1; width: 100%; box-sizing: border-box; }
        
        .alert-error { background: rgba(248, 113, 113, 0.1); color: var(--neon-red); border: 1px solid var(--neon-red); padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; font-weight: bold;}

        /* Sandbox Launcher Banner */
        .sandbox-banner {
            background: linear-gradient(135deg, rgba(192, 132, 252, 0.2), rgba(15, 23, 42, 0.9));
            border: 1px solid var(--neon-purple);
            border-radius: 16px;
            padding: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
            box-shadow: 0 10px 30px rgba(192,132,252,0.1);
        }
        .sb-info h2 { margin: 0 0 10px 0; color: var(--neon-purple); font-family: 'Orbitron'; font-size: 1.8rem; display: flex; align-items: center; gap: 10px;}
        .sb-info p { margin: 0; color: var(--text-muted); font-size: 1.05rem; line-height: 1.5;}
        
        .btn-sandbox {
            background: var(--neon-purple);
            color: var(--bg-dark);
            border: none;
            padding: 15px 30px;
            border-radius: 12px;
            font-size: 1.2rem;
            font-family: 'Orbitron', sans-serif;
            font-weight: bold;
            cursor: pointer;
            transition: 0.3s;
            box-shadow: 0 0 20px rgba(192,132,252,0.4);
            text-decoration: none;
            display: inline-block;
        }
        .btn-sandbox:hover {
            transform: scale(1.05);
            box-shadow: 0 0 30px rgba(192,132,252,0.7);
            background: #d8b4fe;
        }

        /* Matrix Users Section */
        .matrix-header {
            display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;
            border-bottom: 1px dashed var(--border-glass); padding-bottom: 15px;
        }
        .matrix-header h3 { margin: 0; color: var(--neon-blue); font-family: 'Orbitron'; font-size: 1.4rem; }
        
        .search-box {
            background: rgba(0,0,0,0.5); border: 1px solid var(--neon-blue); border-radius: 8px;
            padding: 10px 20px; width: 300px; color: var(--neon-green); font-family: 'Share Tech Mono';
            outline: none; box-shadow: inset 0 0 10px rgba(56,189,248,0.1);
        }
        .search-box:focus { box-shadow: inset 0 0 15px rgba(56,189,248,0.3); }
        .search-box::placeholder { color: #475569; }

        .role-section { margin-bottom: 40px; }
        .role-title { 
            color: var(--text-main); font-size: 1.2rem; margin-bottom: 15px; 
            display: flex; align-items: center; gap: 10px;
        }
        .role-title span { background: rgba(255,255,255,0.1); padding: 3px 10px; border-radius: 20px; font-size: 0.9rem; font-family: 'Share Tech Mono';}

        .user-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }

        .user-card {
            background: var(--bg-panel);
            border: 1px solid var(--border-glass);
            border-radius: 12px;
            padding: 20px;
            display: flex; flex-direction: column;
            transition: 0.3s;
            position: relative;
            overflow: hidden;
        }

        .user-card::before {
            content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%;
        }
        .user-card.r-student::before { background: var(--neon-blue); }
        .user-card.r-teacher::before { background: var(--neon-yellow); }
        .user-card.r-parent::before { background: var(--neon-green); }

        .user-card:hover {
            border-color: rgba(255,255,255,0.3);
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.5);
            background: rgba(30, 41, 59, 0.9);
        }

        .uc-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
        .uc-username { font-family: 'Share Tech Mono'; font-size: 1.2rem; color: var(--text-main); font-weight: bold; margin: 0;}
        .uc-badge { font-size: 0.75rem; padding: 3px 8px; border-radius: 4px; background: rgba(255,255,255,0.1); color: var(--text-muted); font-weight: bold;}
        
        .uc-name { color: var(--text-muted); font-size: 0.95rem; margin-bottom: 15px; flex: 1; }

        .btn-impersonate {
            background: transparent;
            color: var(--neon-green);
            border: 1px solid var(--neon-green);
            padding: 10px;
            border-radius: 8px;
            font-family: 'Share Tech Mono', monospace;
            font-weight: bold;
            cursor: pointer;
            transition: 0.3s;
            width: 100%;
            display: flex; justify-content: center; align-items: center; gap: 8px;
        }
        .btn-impersonate:hover {
            background: var(--neon-green);
            color: var(--bg-dark);
            box-shadow: 0 0 15px rgba(74,222,128,0.4);
        }

        .no-data { grid-column: 1 / -1; text-align: center; padding: 40px; background: rgba(0,0,0,0.3); border-radius: 12px; border: 1px dashed var(--border-glass); color: var(--text-muted); }

    </style>
</head>
<body>

    <header class="topbar">
        <h1>MATRIX <span>// EMULATION SYSTEM</span></h1>
        <a href="dashboard_dev.php" class="btn-back">⬅ Exit Matrix</a>
    </header>

    <div class="container">
        
        <?php if ($message): ?>
            <div class="alert-error"><?= $message ?></div>
        <?php endif; ?>

        <div class="sandbox-banner">
            <div class="sb-info">
                <h2>🧪 LAB SANDBOX / GOD MODE</h2>
                <p>เข้าสู่โหมดจำลองห้องทดลองแบบไร้ขีดจำกัด ไม่ต้องรับเควส มีสารเคมีครบทุกตัวในระบบ<br>และเลือด (HP) จะไม่มีวันหมด เหมาะสำหรับการทดสอบฟิสิกส์และการเกิดอนิเมชันระเบิด</p>
            </div>
            <a href="dev_lab.php" class="btn-sandbox">🚀 LAUNCH SANDBOX</a>
        </div>

        <div class="matrix-header">
            <h3>👁️ สวมรอยตัวตน (Impersonate Users)</h3>
            <input type="text" id="searchInput" class="search-box" placeholder="> search_user(name, id)..." onkeyup="filterUsers()">
        </div>

        <div class="role-section">
            <div class="role-title">👨‍🎓 Students <span><?= count($users['student']) ?> users</span></div>
            <div class="user-grid">
                <?php if (empty($users['student'])): ?>
                    <div class="no-data">ไม่พบข้อมูลนักเรียนในระบบ</div>
                <?php else: ?>
                    <?php foreach ($users['student'] as $u): ?>
                        <div class="user-card r-student searchable" data-name="<?= strtolower($u['username'] . ' ' . $u['display_name']) ?>">
                            <div class="uc-header">
                                <h4 class="uc-username">@<?= htmlspecialchars($u['username']) ?></h4>
                                <span class="uc-badge"><?= htmlspecialchars($u['class_name'] ?? 'ไม่มีห้อง') ?></span>
                            </div>
                            <div class="uc-name"><?= htmlspecialchars($u['display_name']) ?></div>
                            <form method="POST" style="margin:0;" onsubmit="return confirm('คุณกำลังจะสวมรอยเป็นนักเรียนคนนี้ ระบบจะนำคุณไปที่หน้าจอของนักเรียนทันที หากต้องการกลับมาให้ทำการ Logout');">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="action" value="impersonate">
                                <input type="hidden" name="target_id" value="<?= $u['id'] ?>">
                                <button type="submit" class="btn-impersonate">⚡ OVERRIDE</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="role-section">
            <div class="role-title">👩‍🏫 Teachers <span><?= count($users['teacher']) ?> users</span></div>
            <div class="user-grid">
                <?php if (empty($users['teacher'])): ?>
                    <div class="no-data">ไม่พบข้อมูลครูในระบบ</div>
                <?php else: ?>
                    <?php foreach ($users['teacher'] as $u): ?>
                        <div class="user-card r-teacher searchable" data-name="<?= strtolower($u['username'] . ' ' . $u['display_name']) ?>">
                            <div class="uc-header">
                                <h4 class="uc-username">@<?= htmlspecialchars($u['username']) ?></h4>
                                <span class="uc-badge">Teacher</span>
                            </div>
                            <div class="uc-name"><?= htmlspecialchars($u['display_name']) ?></div>
                            <form method="POST" style="margin:0;" onsubmit="return confirm('เข้าสู่หน้าจอของครูคนนี้? (Logout เพื่อกลับสู่ Dev)');">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="action" value="impersonate">
                                <input type="hidden" name="target_id" value="<?= $u['id'] ?>">
                                <button type="submit" class="btn-impersonate" style="color:var(--neon-yellow); border-color:var(--neon-yellow);">⚡ OVERRIDE</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="role-section">
            <div class="role-title">👨‍👩‍👧 Parents <span><?= count($users['parent']) ?> users</span></div>
            <div class="user-grid">
                <?php if (empty($users['parent'])): ?>
                    <div class="no-data">ไม่พบข้อมูลผู้ปกครองในระบบ</div>
                <?php else: ?>
                    <?php foreach ($users['parent'] as $u): ?>
                        <div class="user-card r-parent searchable" data-name="<?= strtolower($u['username'] . ' ' . $u['display_name']) ?>">
                            <div class="uc-header">
                                <h4 class="uc-username">@<?= htmlspecialchars($u['username']) ?></h4>
                                <span class="uc-badge">Parent</span>
                            </div>
                            <div class="uc-name"><?= htmlspecialchars($u['display_name']) ?></div>
                            <form method="POST" style="margin:0;" onsubmit="return confirm('เข้าสู่หน้าจอของผู้ปกครองคนนี้? (Logout เพื่อกลับสู่ Dev)');">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="action" value="impersonate">
                                <input type="hidden" name="target_id" value="<?= $u['id'] ?>">
                                <button type="submit" class="btn-impersonate" style="color:var(--neon-blue); border-color:var(--neon-blue);">⚡ OVERRIDE</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <script>
        // ระบบค้นหาผู้ใช้อย่างรวดเร็ว
        function filterUsers() {
            const query = document.getElementById('searchInput').value.toLowerCase();
            const cards = document.querySelectorAll('.searchable');
            
            cards.forEach(card => {
                const nameData = card.getAttribute('data-name');
                if (nameData.includes(query)) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>