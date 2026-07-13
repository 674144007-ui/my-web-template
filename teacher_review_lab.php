<?php
/**
 * teacher_review_lab.php — ตรวจรายงานห้องทดลอง (Phase 4)
 * แก้ไข: JOIN ให้ตรงกับ schema จริง, Live Monitor tab, PDF export link
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';

requireRole(['teacher', 'developer']);

$page_title = 'ตรวจรายงานห้องทดลอง';
$teacher_id = (int)$_SESSION['user_id'];
$is_dev     = ($_SESSION['role'] === 'developer');
$csrf       = generate_csrf_token();
$school_code = $_SESSION['school_code'] ?? 'BKW';

// ── POST: บันทึกคอมเมนต์ครู ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_comment'])) {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $report_id = (int)($_POST['report_id'] ?? 0);
    $comment   = trim($_POST['teacher_comment'] ?? '');
    if ($report_id > 0) {
        $pdo->prepare("UPDATE lab_reports SET teacher_comment=? WHERE id=?")
            ->execute([$comment, $report_id]);
    }
    header('Location: teacher_review_lab.php?success=1');
    exit;
}

// ── ดึงรายงาน (JOIN ตรงกับ schema จริง) ──────────────────────
$reports = [];
try {
    $sql_base = "
        SELECT lr.*,
               u.display_name,
               c.class_name,
               a.title AS assignment_title,
               a.game_settings
        FROM lab_reports lr
        JOIN users u        ON u.id = lr.student_id
        LEFT JOIN classes c ON c.id = u.class_id
        LEFT JOIN assignments a ON a.id = lr.assignment_id
    ";

    if ($is_dev) {
        $stmt = $pdo->query($sql_base . " ORDER BY lr.created_at DESC LIMIT 200");
    } else {
        $stmt = $pdo->prepare($sql_base . " WHERE a.created_by = ? ORDER BY lr.created_at DESC LIMIT 200");
        $stmt->execute([$teacher_id]);
    }
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[teacher_review_lab] ' . $e->getMessage());
}

// ── ดึง Online Students (Live Monitor) ───────────────────────
$online_students = [];
try {
    $stmt_online = $pdo->prepare("
        SELECT us.user_id, us.display_name, us.page_current,
               us.last_seen_at, u.class_id, c.class_name
        FROM user_sessions us
        JOIN users u        ON u.id = us.user_id
        LEFT JOIN classes c ON c.id = u.class_id
        WHERE us.school_code = ?
          AND us.role_key = 'student'
          AND us.last_seen_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
        ORDER BY us.last_seen_at DESC
    ");
    $stmt_online->execute([$school_code]);
    $online_students = $stmt_online->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[teacher_review_lab] online: ' . $e->getMessage());
}

// สถิติด่วน
$total_reports  = count($reports);
$avg_score      = $total_reports > 0 ? round(array_sum(array_column($reports,'final_score')) / $total_reports, 1) : 0;
$grade_dist     = array_count_values(array_column($reports,'grade'));

require_once 'header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&family=Orbitron:wght@700&display=swap" rel="stylesheet">
<style>
:root { --blue:#3b82f6; --dark-blue:#1d4ed8; --green:#10b981; --red:#ef4444; --amber:#f59e0b; }
body { background:#f8fafc; font-family:'Sarabun',sans-serif; }
.wrap { max-width:1200px; margin:30px auto; padding:0 20px; }

.page-header {
    background:linear-gradient(135deg,var(--blue),var(--dark-blue));
    color:white; padding:30px; border-radius:16px;
    margin-bottom:24px; box-shadow:0 10px 30px rgba(59,130,246,0.2);
}
.page-header h1 { margin:0; font-size:1.8rem; }

/* Tabs */
.tabs { display:flex; gap:8px; margin-bottom:20px; }
.tab-btn {
    padding:10px 20px; border-radius:10px; border:none; cursor:pointer;
    font-family:'Sarabun',sans-serif; font-size:1rem; font-weight:600;
    background:#e2e8f0; color:#475569; transition:0.2s;
}
.tab-btn.active { background:var(--blue); color:white; box-shadow:0 4px 12px rgba(59,130,246,0.3); }
.tab-pane { display:none; }
.tab-pane.active { display:block; }

/* Stats */
.stats-row { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:24px; }
.stat-card { background:white; border-radius:12px; padding:20px; text-align:center; border:1px solid #e2e8f0; }
.stat-num { font-size:2rem; font-weight:700; font-family:'Orbitron'; color:var(--blue); }
.stat-label { font-size:0.9rem; color:#64748b; margin-top:4px; }

/* Report cards */
.report-card { background:white; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:16px; overflow:hidden; box-shadow:0 2px 4px rgba(0,0,0,0.03); transition:0.2s; }
.report-card:hover { box-shadow:0 8px 20px rgba(0,0,0,0.08); transform:translateY(-2px); }
.report-summary { display:flex; padding:18px 20px; cursor:pointer; align-items:center; gap:16px; }
.r-grade { width:56px; height:56px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:1.6rem; font-weight:700; font-family:'Orbitron'; color:white; flex-shrink:0; }
.grade-A { background:var(--amber); box-shadow:0 0 12px rgba(245,158,11,0.4); }
.grade-B { background:#38bdf8; }
.grade-C { background:var(--green); }
.grade-D { background:#a855f7; }
.grade-F { background:var(--red); }
.r-info { flex:1; }
.r-name { font-size:1.05rem; font-weight:600; color:#1e293b; margin-bottom:4px; }
.r-meta { font-size:0.88rem; color:#64748b; display:flex; flex-wrap:wrap; gap:10px; }
.badge { background:#f1f5f9; padding:3px 8px; border-radius:6px; font-weight:600; font-size:0.85rem; }
.r-actions { display:flex; gap:8px; }
.btn-sm { padding:6px 12px; border-radius:8px; border:none; cursor:pointer; font-size:0.85rem; font-family:'Sarabun',sans-serif; font-weight:600; }
.btn-pdf { background:#fef3c7; color:#92400e; }
.btn-pdf:hover { background:#fde68a; }

/* Details panel */
.report-details { border-top:1px solid #e2e8f0; padding:20px; display:none; }
.breakdown-list { background:#0f172a; border-radius:10px; padding:16px; margin-bottom:16px; }
.breakdown-list div { font-family:monospace; font-size:0.9rem; color:#cbd5e1; padding:3px 0; border-bottom:1px solid #1e293b; }
.hints-box { background:#fef3c7; border-radius:10px; padding:14px; margin-bottom:16px; }
.hints-box h5 { margin:0 0 8px; color:#92400e; }
.hints-box ul { margin:0; padding-left:18px; color:#78350f; }

.comment-box textarea { width:100%; padding:12px; border-radius:10px; border:1px solid #cbd5e1; font-family:'Sarabun',sans-serif; font-size:1rem; box-sizing:border-box; margin-bottom:12px; }
.btn-save { background:var(--green); color:white; border:none; padding:10px 24px; border-radius:8px; font-weight:600; cursor:pointer; font-family:'Sarabun',sans-serif; }
.btn-save:hover { background:#059669; }

/* Live Monitor */
.live-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:16px; }
.student-card { background:white; border-radius:12px; border:2px solid #dcfce7; padding:16px; }
.student-card.in-lab { border-color:var(--green); background:#f0fdf4; }
.s-name { font-weight:600; color:#1e293b; margin-bottom:4px; }
.s-page { font-size:0.85rem; color:#64748b; }
.online-dot { display:inline-block; width:8px; height:8px; border-radius:50%; background:var(--green); margin-right:6px; animation:pulse 2s infinite; }
@keyframes pulse { 0%,100% { opacity:1; } 50% { opacity:0.4; } }

/* Grade dist */
.grade-bar { display:flex; align-items:center; gap:8px; margin-bottom:8px; }
.grade-bar-fill { height:20px; border-radius:4px; min-width:4px; transition:width 0.5s; }
</style>

<div class="wrap">
    <div class="page-header">
        <h1>📑 ตรวจรายงานห้องทดลอง</h1>
        <p style="margin:8px 0 0; opacity:0.85;">ดูผลการทดลองและตรวจให้คะแนนนักเรียน</p>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div style="background:#dcfce7;color:#166534;padding:12px 18px;border-radius:10px;margin-bottom:16px;">✅ บันทึกคอมเมนต์เรียบร้อยแล้ว</div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-num"><?= $total_reports ?></div>
            <div class="stat-label">รายงานทั้งหมด</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?= $avg_score ?></div>
            <div class="stat-label">คะแนนเฉลี่ย</div>
        </div>
        <div class="stat-card">
            <div class="stat-num" style="color:var(--green);"><?= count($online_students) ?></div>
            <div class="stat-label">นักเรียน Online ตอนนี้</div>
        </div>
        <div class="stat-card">
            <div class="stat-num" style="color:var(--amber);"><?= $grade_dist['A'] ?? 0 ?></div>
            <div class="stat-label">ได้เกรด A</div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="tabs">
        <button class="tab-btn active" onclick="switchTab('reports',this)">📋 รายงาน (<?= $total_reports ?>)</button>
        <button class="tab-btn" onclick="switchTab('live',this)">🟢 Live Monitor (<?= count($online_students) ?>)</button>
        <button class="tab-btn" onclick="switchTab('stats',this)">📊 สถิติ</button>
    </div>

    <!-- Tab: Reports -->
    <div class="tab-pane active" id="tab-reports">
        <?php if (empty($reports)): ?>
            <div style="text-align:center;padding:50px;background:white;border-radius:12px;color:#64748b;">
                ยังไม่มีนักเรียนส่งรายงานเข้ามา
            </div>
        <?php else: ?>
            <?php foreach ($reports as $r):
                $ai_fb    = json_decode($r['ai_feedback'] ?? '{}', true) ?: [];
                $hints    = $ai_fb['personalized_hints'] ?? [];
                $snapshot = $ai_fb['beaker_snapshot'] ?? [];
                $breakdown_arr = explode("\n", $r['report_summary'] ?? '');
            ?>
            <div class="report-card">
                <div class="report-summary" onclick="toggleDetails(<?= $r['id'] ?>)">
                    <div class="r-grade grade-<?= htmlspecialchars($r['grade']) ?>"><?= htmlspecialchars($r['grade']) ?></div>
                    <div class="r-info">
                        <div class="r-name">
                            <?= htmlspecialchars($r['display_name']) ?>
                            <span style="color:#94a3b8;font-weight:400;font-size:0.9rem;">
                                <?= htmlspecialchars($r['class_name'] ?? 'ไม่ระบุชั้น') ?>
                            </span>
                        </div>
                        <div class="r-meta">
                            <span>📝 <?= htmlspecialchars($r['assignment_title'] ?? 'ไม่ระบุชิ้นงาน') ?></span>
                            <span class="badge" style="color:var(--red);">❤️ HP <?= (int)$r['hp_remaining'] ?>%</span>
                            <span class="badge" style="color:var(--blue);">⭐ <?= (int)$r['final_score'] ?> คะแนน</span>
                            <span class="badge" style="color:var(--green);">+<?= (int)$r['earned_xp'] ?> XP</span>
                            <span>📅 <?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></span>
                        </div>
                    </div>
                    <div class="r-actions" onclick="event.stopPropagation()">
                        <a href="generate_lab_report.php?id=<?= $r['id'] ?>" target="_blank">
                            <button class="btn-sm btn-pdf">🖨️ PDF</button>
                        </a>
                    </div>
                    <div style="color:#cbd5e1;font-size:1.2rem;">▼</div>
                </div>

                <div class="report-details" id="details-<?= $r['id'] ?>">
                    <!-- Breakdown log -->
                    <h4 style="margin:0 0 10px;">📊 ผลการตรวจ (AI Grading)</h4>
                    <div class="breakdown-list">
                        <?php foreach ($breakdown_arr as $line): if (!trim($line)) continue; ?>
                            <div><?= htmlspecialchars($line) ?></div>
                        <?php endforeach; ?>
                    </div>

                    <!-- AI Hints -->
                    <?php if (!empty($hints)): ?>
                    <div class="hints-box">
                        <h5>💡 คำแนะนำส่วนตัวสำหรับนักเรียน</h5>
                        <ul>
                            <?php foreach ($hints as $h): ?>
                                <li><?= htmlspecialchars($h) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <!-- Beaker snapshot -->
                    <?php if (!empty($snapshot)): ?>
                    <h4 style="margin:0 0 8px;">🧪 สารในบีกเกอร์ ณ เวลาส่ง</h4>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
                        <?php foreach ($snapshot as $item): ?>
                            <span style="background:#f1f5f9;padding:4px 10px;border-radius:8px;font-size:0.85rem;">
                                <?= htmlspecialchars($item['name'] ?? '?') ?>
                                <strong style="color:var(--blue);"><?= number_format(floatval($item['amount']??0),1) ?></strong>
                                <?= htmlspecialchars($item['state']==='solid'?'g':'ml') ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Teacher comment -->
                    <form method="POST" class="comment-box">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                        <h4 style="margin:0 0 10px;">💬 ความคิดเห็นจากครู</h4>
                        <textarea name="teacher_comment" rows="3" placeholder="พิมพ์คำแนะนำให้นักเรียน..."><?= htmlspecialchars($r['teacher_comment'] ?? '') ?></textarea>
                        <button type="submit" name="submit_comment" class="btn-save">💾 บันทึกคอมเมนต์</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Tab: Live Monitor -->
    <div class="tab-pane" id="tab-live">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h3 style="margin:0;">🟢 นักเรียน Online ตอนนี้</h3>
            <button onclick="location.reload()" style="background:var(--blue);color:white;border:none;padding:8px 16px;border-radius:8px;cursor:pointer;font-family:'Sarabun',sans-serif;">🔄 Refresh</button>
        </div>
        <?php if (empty($online_students)): ?>
            <div style="text-align:center;padding:40px;background:white;border-radius:12px;color:#64748b;">ไม่มีนักเรียน Online ขณะนี้</div>
        <?php else: ?>
            <div class="live-grid">
                <?php foreach ($online_students as $s):
                    $in_lab = strpos($s['page_current'] ?? '', 'lab_realistic') !== false;
                ?>
                <div class="student-card <?= $in_lab ? 'in-lab' : '' ?>">
                    <div class="s-name">
                        <span class="online-dot"></span>
                        <?= htmlspecialchars($s['display_name']) ?>
                    </div>
                    <div class="s-page" style="margin-bottom:6px;">
                        <?= htmlspecialchars($s['class_name'] ?? 'ไม่ระบุชั้น') ?>
                    </div>
                    <div style="font-size:0.82rem;color:#94a3b8;">
                        <?php if ($in_lab): ?>
                            <span style="color:var(--green);font-weight:600;">🔬 กำลังทดลองอยู่</span>
                        <?php else: ?>
                            📄 <?= htmlspecialchars(basename($s['page_current'] ?? 'ไม่ระบุ')) ?>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:0.78rem;color:#94a3b8;margin-top:4px;">
                        Last seen: <?= date('H:i:s', strtotime($s['last_seen_at'])) ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Tab: Stats -->
    <div class="tab-pane" id="tab-stats">
        <div style="background:white;border-radius:12px;padding:24px;border:1px solid #e2e8f0;">
            <h3 style="margin:0 0 20px;">📊 การกระจายเกรด</h3>
            <?php
            $grade_colors = ['A'=>'#f59e0b','B'=>'#38bdf8','C'=>'#10b981','D'=>'#a855f7','F'=>'#ef4444'];
            $max_g = max(array_values($grade_dist) ?: [1]);
            foreach (['A','B','C','D','F'] as $g):
                $cnt = $grade_dist[$g] ?? 0;
                $pct = $total_reports > 0 ? round($cnt/$total_reports*100) : 0;
                $w   = $max_g > 0 ? round($cnt/$max_g*300) : 0;
            ?>
            <div class="grade-bar">
                <div style="width:30px;font-weight:700;color:<?= $grade_colors[$g] ?>;"><?= $g ?></div>
                <div class="grade-bar-fill" style="width:<?= $w ?>px;background:<?= $grade_colors[$g] ?>;"></div>
                <div style="font-size:0.9rem;color:#64748b;"><?= $cnt ?> คน (<?= $pct ?>%)</div>
            </div>
            <?php endforeach; ?>

            <hr style="margin:24px 0;border-color:#e2e8f0;">
            <h3 style="margin:0 0 16px;">📚 งานที่ส่งมากที่สุด</h3>
            <?php
            $title_counts = array_count_values(array_column($reports,'assignment_title'));
            arsort($title_counts);
            foreach (array_slice($title_counts, 0, 5, true) as $title => $cnt):
            ?>
            <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f1f5f9;">
                <span><?= htmlspecialchars($title ?? 'ไม่ระบุ') ?></span>
                <span class="badge"><?= $cnt ?> รายงาน</span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
function switchTab(name, btn) {
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-'+name).classList.add('active');
    btn.classList.add('active');
}
function toggleDetails(id) {
    const el = document.getElementById('details-'+id);
    const isOpen = el.style.display === 'block';
    document.querySelectorAll('.report-details').forEach(e => e.style.display='none');
    if (!isOpen) el.style.display = 'block';
}
// Auto-refresh Live Monitor ทุก 60 วิ ถ้ากำลัง active อยู่
let liveActive = false;
document.querySelector('[onclick*="live"]')?.addEventListener('click', () => {
    liveActive = true;
    if (!window._liveTimer) window._liveTimer = setInterval(() => { if(liveActive) location.reload(); }, 60000);
});
</script>

<?php require_once 'footer.php'; ?>
