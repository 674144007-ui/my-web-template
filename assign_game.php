<?php
/**
 * assign_game.php - ครู/Dev มอบหมายเกมให้ห้องเรียน
 * บังคับตรวจ: ครูต้องมีวิชาเคมีในตารางสอน จึงจะมอบหมายได้
 */
require_once 'config.php';
require_once 'db.php';
require_once 'security.php';
require_once 'auth.php';
require_once 'logger.php';

requireRole(['teacher', 'developer']);

$user_id   = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$game_id   = trim($_GET['game_id'] ?? '');

if (empty($game_id)) { header('Location: education_games.php'); exit; }

// คลังเกม (ซิงค์กับ education_games.php)
$game_catalog = [
    'acid_base_matching' => [
        'title'   => 'เกมคู่กรด-เบส',
        'subject_keywords' => ['เคมี','chem'],
        'reward_xp' => 500,
        'file'    => 'game_acid_base.php',
    ],
];

if (!isset($game_catalog[$game_id])) { header('Location: education_games.php'); exit; }
$game_info = $game_catalog[$game_id];

// ดึงวิชาที่ครูสอนจากตารางสอน
$teacher_subjects = [];
try {
    $stmt = $pdo->prepare(
        "SELECT DISTINCT ts.subject_code, COALESCE(s.subject_name, ts.subject_code) AS subject_name
         FROM teacher_schedules ts
         LEFT JOIN subjects s ON s.subject_code = ts.subject_code
         WHERE ts.teacher_id = ?
         ORDER BY subject_name"
    );
    $stmt->execute([$user_id]);
    $teacher_subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $teacher_subjects = []; }

// ตรวจว่าครูมีวิชาที่อนุญาตหรือไม่ (developer ข้ามได้เสมอ)
$has_permission = ($user_role === 'developer');
$matched_subjects = [];
if (!$has_permission) {
    foreach ($teacher_subjects as $row) {
        $name = strtolower($row['subject_name'] ?? '');
        foreach ($game_info['subject_keywords'] as $kw) {
            if (stripos($name, $kw) !== false) {
                $has_permission = true;
                $matched_subjects[] = $row;
                break;
            }
        }
    }
}

// ดึงห้องเรียนทั้งหมด (teacher ดึงเฉพาะห้องที่ตัวเองสอน, dev ดึงทั้งหมด)
$classes = [];
try {
    if ($user_role === 'developer') {
        $stmt = $pdo->query("SELECT id, class_name FROM classes WHERE is_active=1 ORDER BY class_name");
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // ดึงห้องจาก teacher_schedules (ห้องที่ครูสอน)
        $stmt = $pdo->prepare(
            "SELECT DISTINCT c.id, c.class_name
             FROM teacher_schedules ts
             JOIN classes c ON c.class_name = ts.class_room
             WHERE ts.teacher_id = ? AND c.is_active = 1
             ORDER BY c.class_name"
        );
        $stmt->execute([$user_id]);
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) { $classes = []; }

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$message = ''; $msg_type = '';

// บันทึกการมอบหมาย
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        http_response_code(403); exit('CSRF Error');
    }
    if (!$has_permission) {
        $message = '❌ คุณไม่มีสิทธิ์มอบหมายเกมนี้ เนื่องจากไม่ได้สอนวิชาเคมี';
        $msg_type = 'error';
    } else {
        $class_id    = intval($_POST['class_id'] ?? 0);
        $subject_code= trim($_POST['subject_code'] ?? '');
        $due_date    = trim($_POST['due_date'] ?? '');
        $max_attempts= intval($_POST['max_attempts'] ?? 3);

        // Validate
        if ($class_id <= 0) { $message='❌ กรุณาเลือกห้องเรียน'; $msg_type='error'; }
        elseif (empty($subject_code)) { $message='❌ กรุณาเลือกวิชา'; $msg_type='error'; }
        elseif (!empty($due_date) && strtotime($due_date) < strtotime('today')) {
            $message='❌ กำหนดส่งต้องไม่ย้อนหลัง'; $msg_type='error';
        } else {
            try {
                // ตรวจซ้ำ (ครูคนเดียวกัน เกมเดียวกัน ห้องเดียวกัน)
                $ck = $pdo->prepare("SELECT id FROM game_assignments WHERE teacher_id=? AND game_id=? AND class_id=? AND is_active=1");
                $ck->execute([$user_id, $game_id, $class_id]);
                if ($ck->fetch()) {
                    $message = '⚠️ คุณได้มอบหมายเกมนี้ให้ห้องนี้ไปแล้ว';
                    $msg_type = 'warning';
                } else {
                    $stmt = $pdo->prepare(
                        "INSERT INTO game_assignments (game_id, game_name, subject_code, teacher_id, class_id, due_date, max_attempts, reward_xp)
                         VALUES (?,?,?,?,?,?,?,?)"
                    );
                    $stmt->execute([
                        $game_id,
                        $game_info['title'],
                        $subject_code,
                        $user_id,
                        $class_id,
                        $due_date ?: null,
                        $max_attempts,
                        $game_info['reward_xp'],
                    ]);
                    systemLog($user_id, 'ASSIGN_GAME', "Game: $game_id → class_id: $class_id");
                    $message = '🎉 มอบหมายเกมสำเร็จแล้ว! นักเรียนจะเห็นงานในหน้า "งานของฉัน"';
                    $msg_type = 'success';
                }
            } catch (PDOException $e) {
                $message = '❌ เกิดข้อผิดพลาด: ' . ($user_role==='developer' ? $e->getMessage() : 'กรุณาลองใหม่');
                $msg_type = 'error';
            }
        }
    }
}

$page_title = 'มอบหมายเกม — ' . $game_info['title'];
require_once 'header.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
body { background: #f8fafc; font-family: 'Prompt', sans-serif; }
.assign-wrap { max-width: 700px; margin: 40px auto; padding: 0 20px 60px; }

.back-link { color: #64748b; font-weight: 600; text-decoration: none; font-size: .9rem; display: inline-flex; align-items: center; gap: 6px; margin-bottom: 20px; }
.back-link:hover { color: #1e293b; }

.page-title { font-size: 1.7rem; font-weight: 700; color: #0f172a; margin: 0 0 4px; }
.page-sub   { color: #64748b; font-size: .95rem; margin-bottom: 28px; }

/* Permission alert */
.perm-ok   { background: #f0fdf4; border: 1.5px solid #86efac; color: #166534; padding: 14px 18px; border-radius: 12px; font-weight: 600; margin-bottom: 20px; }
.perm-deny { background: #fef2f2; border: 1.5px solid #fca5a5; color: #991b1b; padding: 16px 18px; border-radius: 12px; font-weight: 600; margin-bottom: 20px; }

.alert-msg { padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; }
.alert-success { background: #f0fdf4; color: #166534; border: 1.5px solid #86efac; }
.alert-warning { background: #fffbeb; color: #92400e; border: 1.5px solid #fde68a; }
.alert-error   { background: #fef2f2; color: #991b1b; border: 1.5px solid #fca5a5; }

/* Form card */
.form-card { background: #fff; border-radius: 20px; border: 1px solid #e2e8f0; box-shadow: 0 6px 24px rgba(0,0,0,0.04); padding: 32px; }

.game-preview { display: flex; align-items: center; gap: 16px; background: #eff6ff; border-radius: 14px; padding: 18px 20px; margin-bottom: 28px; }
.game-icon-lg { font-size: 2.4rem; }
.game-prev-title { font-size: 1.1rem; font-weight: 700; color: #1d4ed8; }
.game-prev-sub   { font-size: .85rem; color: #3b82f6; }

.form-group { margin-bottom: 22px; }
.form-label { font-weight: 600; color: #374151; font-size: .95rem; display: block; margin-bottom: 8px; }
.form-label span { color: #ef4444; }
.form-control {
    width: 100%; padding: 11px 14px; border: 1.5px solid #d1d5db;
    border-radius: 10px; font-size: .95rem; font-family: 'Prompt', sans-serif;
    color: #0f172a; background: #fafafa; transition: .2s; box-sizing: border-box;
}
.form-control:focus { outline: none; border-color: #3b82f6; background: #fff; box-shadow: 0 0 0 3px rgba(59,130,246,.12); }
.form-hint { font-size: .8rem; color: #64748b; margin-top: 5px; }

.section-divider { border: none; border-top: 1.5px solid #f1f5f9; margin: 24px 0; }

.btn-submit { width: 100%; padding: 14px; background: linear-gradient(135deg,#10b981,#059669); color: #fff; border: none; border-radius: 12px; font-size: 1rem; font-weight: 700; cursor: pointer; font-family: 'Prompt', sans-serif; transition: .2s; }
.btn-submit:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(16,185,129,.3); }
.btn-submit:disabled { background: #cbd5e1; cursor: not-allowed; }
</style>

<div class="assign-wrap">
    <a href="education_games.php" class="back-link">⬅ กลับคลังเกม</a>
    <h1 class="page-title">📤 มอบหมายเกม</h1>
    <p class="page-sub">กำหนดห้องเรียนและวิชาที่ต้องการให้นักเรียนเล่นเกมนี้</p>

    <!-- ตรวจสิทธิ์ -->
    <?php if ($user_role === 'developer'): ?>
        <div class="perm-ok">🛠️ Developer Mode — สามารถมอบหมายเกมทุกวิชาได้</div>
    <?php elseif ($has_permission): ?>
        <div class="perm-ok">
            ✔ คุณสอนวิชาเคมี —
            <?= implode(', ', array_map(fn($r) => htmlspecialchars($r['subject_name']), $matched_subjects)) ?>
        </div>
    <?php else: ?>
        <div class="perm-deny">
            🔒 คุณไม่มีสิทธิ์มอบหมายเกมนี้<br>
            <small>เกมนี้ต้องใช้กับวิชาเคมี แต่ตารางสอนของคุณไม่มีวิชาเคมี<br>
            หากเป็นข้อผิดพลาด กรุณาติดต่อ Developer เพื่อตรวจสอบตารางสอน</small>
        </div>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="alert-msg alert-<?= $msg_type ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="form-card">
        <!-- Game Preview -->
        <div class="game-preview">
            <span class="game-icon-lg">⚗️</span>
            <div>
                <div class="game-prev-title"><?= htmlspecialchars($game_info['title']) ?></div>
                <div class="game-prev-sub">เคมี ม.5 · บทที่ 14 กรด-เบส · +<?= $game_info['reward_xp'] ?> XP</div>
            </div>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

            <!-- เลือกวิชา -->
            <div class="form-group">
                <label class="form-label">วิชาที่ใช้มอบหมาย <span>*</span></label>
                <select name="subject_code" class="form-control" required <?= !$has_permission ? 'disabled' : '' ?>>
                    <option value="">— เลือกวิชา —</option>
                    <?php if ($user_role === 'developer'): ?>
                        <?php
                        $all_sub = $pdo->query("SELECT subject_code, subject_name FROM subjects WHERE is_active=1 ORDER BY subject_name")->fetchAll();
                        foreach ($all_sub as $s):
                        ?>
                            <option value="<?= htmlspecialchars($s['subject_code']) ?>">
                                <?= htmlspecialchars($s['subject_name']) ?> (<?= htmlspecialchars($s['subject_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php foreach ($matched_subjects as $row): ?>
                            <option value="<?= htmlspecialchars($row['subject_code']) ?>">
                                <?= htmlspecialchars($row['subject_name']) ?> (<?= htmlspecialchars($row['subject_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <div class="form-hint">แสดงเฉพาะวิชาที่ตรงกับเกมและอยู่ในตารางสอนของคุณ</div>
            </div>

            <!-- เลือกห้อง -->
            <div class="form-group">
                <label class="form-label">ห้องเรียนที่มอบหมาย <span>*</span></label>
                <select name="class_id" class="form-control" required <?= !$has_permission ? 'disabled' : '' ?>>
                    <option value="">— เลือกห้องเรียน —</option>
                    <?php foreach ($classes as $cls): ?>
                        <option value="<?= $cls['id'] ?>"><?= htmlspecialchars($cls['class_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($classes)): ?>
                    <div class="form-hint" style="color:#ef4444;">⚠️ ไม่พบห้องเรียนในตารางสอนของคุณ</div>
                <?php endif; ?>
            </div>

            <hr class="section-divider">

            <!-- กำหนดส่ง -->
            <div class="form-group">
                <label class="form-label">กำหนดส่ง</label>
                <input type="date" name="due_date" class="form-control"
                       min="<?= date('Y-m-d') ?>"
                       <?= !$has_permission ? 'disabled' : '' ?>>
                <div class="form-hint">ไม่บังคับ — ถ้าไม่ระบุ นักเรียนสามารถเล่นได้ตลอดเวลา</div>
            </div>

            <!-- จำนวนครั้ง -->
            <div class="form-group">
                <label class="form-label">จำนวนครั้งที่เล่นได้</label>
                <select name="max_attempts" class="form-control" <?= !$has_permission ? 'disabled' : '' ?>>
                    <option value="1">1 ครั้ง</option>
                    <option value="3" selected>3 ครั้ง (แนะนำ)</option>
                    <option value="5">5 ครั้ง</option>
                    <option value="999">ไม่จำกัด</option>
                </select>
            </div>

            <button type="submit" class="btn-submit" <?= !$has_permission ? 'disabled' : '' ?>>
                📤 มอบหมายเกมนี้
            </button>
        </form>
    </div>
</div>

<?php require_once 'footer.php'; ?>
