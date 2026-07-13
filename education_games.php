<?php
/**
 * education_games.php - ระบบเกมการศึกษา (Educational Games Hub)
 * แสดงสำหรับ Developer และ Teacher เท่านั้น
 * ครูต้องมีตารางสอนวิชานั้นๆ จึงจะมอบหมายเกมได้
 */
require_once 'config.php';
require_once 'db.php';
require_once 'security.php';
require_once 'auth.php';

requireRole(['teacher', 'developer']);

$page_title = "ระบบเกมการศึกษา";
$user_id    = $_SESSION['user_id'];
$user_role  = $_SESSION['role'];

// ดึงรหัสวิชาที่ครูสอน (จาก teacher_schedules)
$teacher_subjects = [];
if ($user_role === 'teacher') {
    try {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT ts.subject_code, COALESCE(s.subject_name, ts.subject_code) AS subject_name
             FROM teacher_schedules ts
             LEFT JOIN subjects s ON s.subject_code = ts.subject_code
             WHERE ts.teacher_id = ?
             ORDER BY ts.subject_code"
        );
        $stmt->execute([$user_id]);
        $teacher_subjects = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // subject_code => subject_name
    } catch (PDOException $e) {
        $teacher_subjects = [];
    }
}

// คลังเกม (เพิ่มเกมใหม่ที่นี่)
$game_catalog = [
    [
        'id'          => 'acid_base_matching',
        'title'       => 'คู่กรด-เบส',
        'subtitle'    => 'จับคู่สารกับค่า pH ที่ถูกต้อง',
        'subject'     => 'เคมี',
        'subject_key' => 'chemistry', // keyword สำหรับตรวจชื่อวิชา
        'level'       => 'ม.5',
        'chapter'     => 'บทที่ 14: กรด-เบส (สสวท. เล่ม 4)',
        'icon'        => '⚗️',
        'color'       => 'blue',
        'file'        => 'game_acid_base.php',
        'xp'          => 500,
        'duration'    => '10-15 นาที',
        'tags'        => ['กรด-เบส', 'pH', 'สมดุลไอออน'],
    ],
    // ===== เพิ่มเกมถัดไปที่นี่ =====
];

// ตรวจว่าครูสอนวิชาที่ผูกกับเกมนั้นหรือไม่
function canAssignGame(array $game, array $teacherSubjects, string $role): bool {
    if ($role === 'developer') return true;
    foreach (array_keys($teacherSubjects) as $code) {
        if (stripos($code, 'ว') !== false || stripos($code, 'chem') !== false) {
            // subject_code ที่มี ว (วิทยาศาสตร์) หรือ chem
            // ตรวจชื่อวิชาด้วย
        }
        $name = strtolower($teacherSubjects[$code] ?? '');
        if (stripos($name, 'เคมี') !== false || stripos($name, 'chem') !== false) return true;
    }
    return false;
}

require_once 'header.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
body { background: #f8fafc; font-family: 'Prompt', sans-serif; }
.hub-container { max-width: 1100px; margin: 36px auto; padding: 0 20px; }

.hub-header { background: linear-gradient(135deg, #1e293b, #0f172a); color: #fff; border-radius: 20px; padding: 40px; margin-bottom: 32px; position: relative; overflow: hidden; }
.hub-header::after { content: '🎮'; position: absolute; right: 30px; top: 50%; transform: translateY(-50%); font-size: 7rem; opacity: 0.08; pointer-events: none; }
.hub-header h1 { margin: 0 0 8px; font-size: 2rem; color: #38bdf8; }
.hub-header p  { margin: 0; color: #94a3b8; font-size: 1rem; }

.section-title { font-size: 1.2rem; font-weight: 700; color: #1e293b; margin: 0 0 18px; display: flex; align-items: center; gap: 10px; }

/* Subject chips */
.subject-bar { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 28px; }
.subject-chip { padding: 6px 16px; border-radius: 20px; font-size: 0.85rem; font-weight: 600; border: 1.5px solid; cursor: default; }
.chip-has  { background: #dcfce7; color: #166534; border-color: #86efac; }
.chip-none { background: #f1f5f9; color: #64748b; border-color: #cbd5e1; }
.chip-dev  { background: #eff6ff; color: #1d4ed8; border-color: #93c5fd; }

/* Game grid */
.game-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 24px; }

.game-card { background: #fff; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 4px 16px rgba(0,0,0,0.03); overflow: hidden; transition: .25s; display: flex; flex-direction: column; }
.game-card:hover { transform: translateY(-4px); box-shadow: 0 12px 30px rgba(0,0,0,0.07); }

.card-cover { padding: 28px 24px 20px; position: relative; }
.cover-blue   { background: linear-gradient(135deg, #eff6ff, #dbeafe); }
.cover-purple { background: linear-gradient(135deg, #f5f3ff, #ede9fe); }
.card-icon { font-size: 2.8rem; margin-bottom: 12px; display: block; }
.card-title   { font-size: 1.3rem; font-weight: 700; color: #0f172a; margin: 0 0 4px; }
.card-subtitle { font-size: 0.9rem; color: #64748b; margin: 0; }
.card-lock { position: absolute; top: 16px; right: 16px; background: #fef3c7; color: #d97706; font-size: 0.75rem; font-weight: 700; padding: 4px 10px; border-radius: 8px; border: 1px solid #fde68a; }
.card-unlock { position: absolute; top: 16px; right: 16px; background: #dcfce7; color: #166534; font-size: 0.75rem; font-weight: 700; padding: 4px 10px; border-radius: 8px; border: 1px solid #bbf7d0; }

.card-body { padding: 16px 24px; flex: 1; }
.meta-row { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
.meta-tag { font-size: 0.78rem; font-weight: 600; padding: 3px 10px; border-radius: 6px; }
.tag-blue   { background: #eff6ff; color: #1d4ed8; }
.tag-green  { background: #f0fdf4; color: #166534; }
.tag-gray   { background: #f1f5f9; color: #475569; }
.card-chapter { font-size: 0.82rem; color: #64748b; line-height: 1.5; }

.card-footer { padding: 14px 24px; border-top: 1px solid #f1f5f9; display: flex; gap: 10px; }
.btn-preview { flex: 1; padding: 10px; border-radius: 10px; font-weight: 700; font-size: 0.9rem; text-decoration: none; text-align: center; background: #f8fafc; color: #475569; border: 1.5px solid #e2e8f0; transition: .2s; }
.btn-preview:hover { background: #f1f5f9; }
.btn-assign { flex: 1; padding: 10px; border-radius: 10px; font-weight: 700; font-size: 0.9rem; text-decoration: none; text-align: center; background: linear-gradient(135deg, #10b981, #059669); color: #fff; border: none; cursor: pointer; transition: .2s; display: inline-block; }
.btn-assign:hover { transform: scale(1.02); box-shadow: 0 4px 12px rgba(16,185,129,.3); }
.btn-disabled { flex: 1; padding: 10px; border-radius: 10px; font-weight: 700; font-size: 0.9rem; text-align: center; background: #f1f5f9; color: #94a3b8; border: 1.5px dashed #cbd5e1; cursor: not-allowed; }

/* Coming soon card */
.card-soon { opacity: .55; pointer-events: none; }
</style>

<div class="hub-container">
    <div class="hub-header">
        <h1>🎮 ระบบเกมการศึกษา</h1>
        <p>คลังเกมเพื่อการเรียนรู้ — วิชาเคมี ระดับชั้น ม.5 (หลักสูตร สสวท.)</p>
    </div>

    <!-- วิชาที่ครูสอน -->
    <?php if ($user_role === 'teacher'): ?>
    <div class="section-title">📋 วิชาที่คุณสอน (จากตารางสอน)</div>
    <div class="subject-bar">
        <?php if (empty($teacher_subjects)): ?>
            <span class="subject-chip chip-none">⚠️ ยังไม่มีตารางสอนในระบบ</span>
        <?php else: ?>
            <?php foreach ($teacher_subjects as $code => $name): ?>
                <span class="subject-chip chip-has">✔ <?= htmlspecialchars($name ?: $code) ?></span>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php elseif ($user_role === 'developer'): ?>
    <div class="subject-bar">
        <span class="subject-chip chip-dev">🛠️ Developer — เข้าถึงทุกเกมได้</span>
    </div>
    <?php endif; ?>

    <!-- คลังเกม -->
    <div class="section-title">🕹️ เกมที่มีในระบบ</div>
    <div class="game-grid">
        <?php foreach ($game_catalog as $game):
            $canAssign = canAssignGame($game, $teacher_subjects, $user_role);
        ?>
        <div class="game-card">
            <div class="card-cover cover-<?= $game['color'] ?>">
                <span class="card-icon"><?= $game['icon'] ?></span>
                <div class="card-title"><?= htmlspecialchars($game['title']) ?></div>
                <div class="card-subtitle"><?= htmlspecialchars($game['subtitle']) ?></div>
                <?php if ($canAssign): ?>
                    <span class="card-unlock">✔ มอบหมายได้</span>
                <?php else: ?>
                    <span class="card-lock">🔒 ไม่ได้สอนวิชานี้</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="meta-row">
                    <span class="meta-tag tag-blue"><?= htmlspecialchars($game['level']) ?></span>
                    <span class="meta-tag tag-green">+<?= $game['xp'] ?> XP</span>
                    <span class="meta-tag tag-gray">⏱ <?= htmlspecialchars($game['duration']) ?></span>
                </div>
                <div class="card-chapter">📖 <?= htmlspecialchars($game['chapter']) ?></div>
                <div style="margin-top:10px; display:flex; flex-wrap:wrap; gap:6px;">
                    <?php foreach ($game['tags'] as $tag): ?>
                        <span class="meta-tag tag-gray">#<?= htmlspecialchars($tag) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="card-footer">
                <a href="<?= htmlspecialchars($game['file']) ?>?preview=1" class="btn-preview">▶ ทดลองเล่น</a>
                <?php if ($canAssign): ?>
                    <a href="assign_game.php?game_id=<?= urlencode($game['id']) ?>" class="btn-assign">📤 มอบหมาย</a>
                <?php else: ?>
                    <span class="btn-disabled" title="คุณไม่ได้สอนวิชา<?= htmlspecialchars($game['subject']) ?>">🔒 มอบหมายไม่ได้</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Coming soon placeholder -->
        <div class="game-card card-soon">
            <div class="card-cover cover-purple" style="background:linear-gradient(135deg,#f5f3ff,#ede9fe);">
                <span class="card-icon">🔬</span>
                <div class="card-title">สมดุลเคมี</div>
                <div class="card-subtitle">เร็วๆ นี้</div>
            </div>
            <div class="card-body">
                <div class="meta-row"><span class="meta-tag tag-gray">ม.5</span><span class="meta-tag tag-gray">กำลังพัฒนา</span></div>
                <div class="card-chapter">📖 บทที่ 13: สมดุลเคมี (สสวท. เล่ม 4)</div>
            </div>
            <div class="card-footer">
                <span class="btn-disabled" style="flex:2">🚧 กำลังพัฒนา</span>
            </div>
        </div>
    </div>

    <div style="margin-top:28px;">
        <a href="<?= $user_role === 'developer' ? 'dashboard_dev.php' : 'dashboard_teacher.php' ?>" style="color:#64748b;font-weight:700;text-decoration:none;">⬅ กลับ Dashboard</a>
    </div>
</div>

<?php require_once 'footer.php'; ?>
