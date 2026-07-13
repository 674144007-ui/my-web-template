<?php
/**
 * student_games.php - งานเกมการศึกษาของนักเรียน (ปรับปรุงใหม่ เป็นทางการ)
 * แสดงเฉพาะเกมที่ได้รับมอบหมายตามห้องเรียนของนักเรียน
 */
require_once 'config.php';
require_once 'db.php';
require_once 'security.php';
require_once 'auth.php';

requireRole(['student', 'developer']);

$page_title   = 'งานเกมการศึกษา';
$student_id   = $_SESSION['user_id'];
$class_id     = $_SESSION['class_id'] ?? 0;

// ดึงงานที่ได้รับมอบหมาย พร้อมผลการเล่นล่าสุดของนักเรียนคนนี้
$assignments = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            ga.id                                           AS assignment_id,
            ga.game_id,
            ga.game_name,
            ga.subject_code,
            ga.due_date,
            ga.max_attempts,
            ga.reward_xp,
            ga.created_at                                   AS assigned_at,
            u.display_name                                  AS teacher_name,
            c.class_name,
            COALESCE(s.subject_name, ga.subject_code)       AS subject_name,
            gr.score                                        AS best_score,
            gr.earned_xp                                    AS earned_xp,
            gr.is_passed                                    AS is_passed,
            (SELECT COUNT(*) FROM game_results r2
             WHERE r2.assignment_id = ga.id AND r2.student_id = ?)   AS attempt_count,
            (SELECT MAX(r3.played_at) FROM game_results r3
             WHERE r3.assignment_id = ga.id AND r3.student_id = ?)   AS last_played
        FROM game_assignments ga
        JOIN users u  ON u.id = ga.teacher_id
        JOIN classes c ON c.id = ga.class_id
        LEFT JOIN subjects s ON s.subject_code = ga.subject_code
        LEFT JOIN game_results gr ON (
            gr.assignment_id = ga.id AND gr.student_id = ?
            AND gr.score = (
                SELECT MAX(r4.score) FROM game_results r4
                WHERE r4.assignment_id = ga.id AND r4.student_id = ?
            )
        )
        WHERE ga.class_id = ? AND ga.is_active = 1
        ORDER BY ga.created_at DESC
    ");
    $stmt->execute([$student_id, $student_id, $student_id, $student_id, $class_id]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $assignments = [];
}

// Map game_id → file
$game_files = [
    'acid_base_matching' => 'game_acid_base.php',
];

require_once 'header.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
body { background: #f8fafc; font-family: 'Prompt', sans-serif; }
.pg-wrap { max-width: 1000px; margin: 36px auto; padding: 0 20px 60px; }

/* Page header */
.pg-header { margin-bottom: 30px; }
.pg-header h1 { font-size: 1.8rem; font-weight: 700; color: #0f172a; margin: 0 0 4px; }
.pg-header p  { color: #64748b; margin: 0; }

/* Stats row */
.stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; margin-bottom: 30px; }
.stat-box { background: #fff; border-radius: 14px; border: 1px solid #e2e8f0; padding: 18px 20px; display: flex; align-items: center; gap: 14px; box-shadow: 0 2px 8px rgba(0,0,0,.02); }
.stat-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; flex-shrink: 0; }
.stat-val  { font-size: 1.5rem; font-weight: 700; color: #0f172a; line-height: 1; }
.stat-lbl  { font-size: .8rem; color: #64748b; margin-top: 3px; }
.bg-blue   { background: #eff6ff; }
.bg-green  { background: #f0fdf4; }
.bg-amber  { background: #fffbeb; }

/* Section label */
.sec-label { font-size: .8rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 14px; }

/* Assignment card */
.asgn-card {
    background: #fff; border-radius: 16px; border: 1px solid #e2e8f0;
    box-shadow: 0 3px 12px rgba(0,0,0,.03); margin-bottom: 18px;
    overflow: hidden; transition: .2s;
}
.asgn-card:hover { box-shadow: 0 8px 24px rgba(0,0,0,.07); }

.asgn-top  { padding: 20px 22px; display: flex; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.game-icon { font-size: 2rem; flex-shrink: 0; margin-top: 2px; }
.asgn-info { flex: 1; min-width: 200px; }
.asgn-title  { font-size: 1.1rem; font-weight: 700; color: #0f172a; margin: 0 0 5px; }
.asgn-sub    { font-size: .85rem; color: #64748b; margin: 0 0 10px; }

.badge-row { display: flex; flex-wrap: wrap; gap: 7px; margin-top: 2px; }
.badge { padding: 3px 11px; border-radius: 20px; font-size: .78rem; font-weight: 600; }
.b-subj  { background: #eff6ff; color: #1d4ed8; }
.b-xp    { background: #fef3c7; color: #92400e; }
.b-due   { background: #fef2f2; color: #991b1b; }
.b-due-ok{ background: #f0fdf4; color: #166534; }
.b-done  { background: #f0fdf4; color: #166534; }

/* Score side */
.asgn-score { text-align: center; min-width: 90px; flex-shrink: 0; }
.score-circle { width: 72px; height: 72px; border-radius: 50%; display: flex; flex-direction: column; align-items: center; justify-content: center; margin: 0 auto 6px; border: 3px solid; }
.score-pass   { border-color: #10b981; background: #f0fdf4; }
.score-fail   { border-color: #f59e0b; background: #fffbeb; }
.score-none   { border-color: #e2e8f0; background: #f8fafc; }
.score-pct    { font-size: 1rem; font-weight: 700; color: #0f172a; line-height: 1; }
.score-tiny   { font-size: .7rem; color: #64748b; }

/* Bottom bar */
.asgn-bottom { padding: 14px 22px; border-top: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; background: #fafafa; }
.attempts-txt { font-size: .82rem; color: #64748b; }
.btn-play { padding: 9px 24px; border-radius: 10px; font-weight: 700; font-size: .9rem; text-decoration: none; display: inline-block; transition: .2s; }
.btn-play-primary { background: linear-gradient(135deg,#3b82f6,#2563eb); color: #fff; }
.btn-play-primary:hover { transform: scale(1.03); box-shadow: 0 4px 14px rgba(59,130,246,.3); color: #fff; }
.btn-play-retry   { background: #f0fdf4; color: #166534; border: 1.5px solid #86efac; }
.btn-play-retry:hover { background: #dcfce7; }
.btn-play-max     { background: #f1f5f9; color: #94a3b8; cursor: not-allowed; }

/* Empty state */
.empty-box { text-align: center; padding: 60px 20px; color: #94a3b8; }
.empty-box .empty-icon { font-size: 3.5rem; margin-bottom: 16px; }
.empty-box h3 { font-size: 1.2rem; color: #64748b; margin: 0 0 8px; }
.empty-box p  { font-size: .9rem; }
</style>

<div class="pg-wrap">
    <!-- Page header -->
    <div class="pg-header">
        <h1>🎮 งานเกมการศึกษา</h1>
        <p>รายการเกมที่ครูมอบหมาย — <?= htmlspecialchars($_SESSION['display_name']) ?></p>
    </div>

    <?php
    // คำนวณ stats
    $total_tasks  = count($assignments);
    $total_passed = count(array_filter($assignments, fn($a)=>$a['is_passed']));
    $total_xp     = array_sum(array_column($assignments, 'earned_xp'));
    ?>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-box">
            <div class="stat-icon bg-blue">🕹️</div>
            <div><div class="stat-val"><?= $total_tasks ?></div><div class="stat-lbl">งานทั้งหมด</div></div>
        </div>
        <div class="stat-box">
            <div class="stat-icon bg-green">✔</div>
            <div><div class="stat-val"><?= $total_passed ?></div><div class="stat-lbl">ผ่านแล้ว</div></div>
        </div>
        <div class="stat-box">
            <div class="stat-icon bg-amber">⭐</div>
            <div><div class="stat-val"><?= number_format($total_xp) ?></div><div class="stat-lbl">XP ที่ได้รับ</div></div>
        </div>
    </div>

    <!-- รายการงาน -->
    <?php if (empty($assignments)): ?>
        <div class="empty-box">
            <div class="empty-icon">📭</div>
            <h3>ยังไม่มีงานเกมที่ได้รับมอบหมาย</h3>
            <p>เมื่อครูมอบหมายเกมให้ห้องเรียนของคุณ รายการจะปรากฏที่นี่</p>
        </div>
    <?php else: ?>
        <div class="sec-label">รายการงาน (<?= $total_tasks ?> งาน)</div>
        <?php foreach ($assignments as $a):
            $file = $game_files[$a['game_id']] ?? '#';
            $attempt_count = intval($a['attempt_count']);
            $max_attempts  = intval($a['max_attempts']);
            $is_passed     = (bool)$a['is_passed'];
            $best_score    = $a['best_score'];
            $is_maxed      = ($max_attempts !== 999 && $attempt_count >= $max_attempts);
            $has_due       = !empty($a['due_date']);
            $is_overdue    = $has_due && strtotime($a['due_date']) < strtotime('today');
            $game_url = "$file?assignment_id=" . $a['assignment_id'];
        ?>
        <div class="asgn-card">
            <div class="asgn-top">
                <span class="game-icon">⚗️</span>
                <div class="asgn-info">
                    <div class="asgn-title"><?= htmlspecialchars($a['game_name']) ?></div>
                    <div class="asgn-sub">
                        ครู<?= htmlspecialchars($a['teacher_name']) ?> · <?= htmlspecialchars($a['class_name']) ?>
                    </div>
                    <div class="badge-row">
                        <span class="badge b-subj">📖 <?= htmlspecialchars($a['subject_name']) ?></span>
                        <span class="badge b-xp">⭐ +<?= $a['reward_xp'] ?> XP</span>
                        <?php if ($has_due): ?>
                            <span class="badge <?= $is_overdue ? 'b-due' : 'b-due-ok' ?>">
                                <?= $is_overdue ? '⚠️ เลยกำหนด ' : '📅 ' ?>
                                <?= date('d/m/Y', strtotime($a['due_date'])) ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($is_passed): ?>
                            <span class="badge b-done">✔ ผ่านแล้ว</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Score -->
                <div class="asgn-score">
                    <?php if ($best_score !== null): ?>
                        <div class="score-circle <?= $is_passed ? 'score-pass' : 'score-fail' ?>">
                            <span class="score-pct"><?= $best_score ?>%</span>
                            <span class="score-tiny">คะแนนสูงสุด</span>
                        </div>
                        <div style="font-size:.78rem;color:#64748b;">+<?= intval($a['earned_xp']) ?> XP</div>
                    <?php else: ?>
                        <div class="score-circle score-none">
                            <span class="score-pct" style="color:#94a3b8;">-</span>
                            <span class="score-tiny">ยังไม่เล่น</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="asgn-bottom">
                <div class="attempts-txt">
                    เล่นแล้ว <?= $attempt_count ?> / <?= $max_attempts === 999 ? '∞' : $max_attempts ?> ครั้ง
                    <?php if ($a['last_played']): ?>
                        · เล่นล่าสุด <?= date('d/m/Y H:i', strtotime($a['last_played'])) ?>
                    <?php endif; ?>
                </div>

                <?php if ($is_maxed): ?>
                    <span class="btn-play btn-play-max">🔒 ใช้ครบจำนวนครั้งแล้ว</span>
                <?php elseif ($is_overdue && !$is_passed): ?>
                    <span class="btn-play btn-play-max">⏰ เลยกำหนดส่งแล้ว</span>
                <?php elseif ($best_score !== null): ?>
                    <a href="<?= $game_url ?>" class="btn-play btn-play-retry">🔄 เล่นอีกครั้ง</a>
                <?php else: ?>
                    <a href="<?= $game_url ?>" class="btn-play btn-play-primary">▶ เริ่มเล่น</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div style="margin-top:24px;">
        <a href="dashboard_student.php" style="color:#64748b;font-weight:700;text-decoration:none;">⬅ กลับ Dashboard</a>
    </div>
</div>

<?php require_once 'footer.php'; ?>
