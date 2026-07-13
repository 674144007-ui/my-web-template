<?php
/**
 * lab_analytics.php — Lab Analytics Dashboard (Phase 6)
 * แสดงสถิติ: สารที่ใช้บ่อย, ปฏิกิริยายอดนิยม, fail rate,
 *            เวลาเฉลี่ยต่อ assignment, safety violations trend
 * ต้องการสิทธิ์: developer หรือ teacher
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';

requireRole(['developer', 'teacher']);

$page_title = 'Lab Analytics';
$is_dev = ($_SESSION['role'] === 'developer');

// ── helper: query with fallback ──────────────────────────────
function qFetch(PDO $pdo, string $sql, array $params = []): array {
    try {
        $s = $pdo->prepare($sql); $s->execute($params);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[analytics] ' . $e->getMessage());
        return [];
    }
}
function qVal(PDO $pdo, string $sql, array $params = [], $default = 0) {
    try { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn() ?? $default; }
    catch (PDOException $e) { return $default; }
}

// ── 1. Summary Stats ─────────────────────────────────────────
$total_reports  = qVal($pdo, "SELECT COUNT(*) FROM lab_reports");
$avg_score      = round((float)qVal($pdo, "SELECT AVG(final_score) FROM lab_reports"), 1);
$pass_rate      = $total_reports > 0
    ? round(qVal($pdo, "SELECT COUNT(*) FROM lab_reports WHERE final_score >= 50") / $total_reports * 100, 1)
    : 0;
$avg_hp         = round((float)qVal($pdo, "SELECT AVG(hp_remaining) FROM lab_reports"), 1);

// ── 2. เกรด distribution ─────────────────────────────────────
$grade_rows = qFetch($pdo, "SELECT grade, COUNT(*) AS cnt FROM lab_reports GROUP BY grade ORDER BY grade");
$grade_dist = [];
foreach ($grade_rows as $r) $grade_dist[$r['grade']] = (int)$r['cnt'];

// ── 3. Top assignments by fail rate ──────────────────────────
$hard_assigns = qFetch($pdo, "
    SELECT a.title, COUNT(*) AS total,
           SUM(CASE WHEN lr.final_score < 50 THEN 1 ELSE 0 END) AS failed,
           ROUND(AVG(lr.final_score),1) AS avg_score
    FROM lab_reports lr
    JOIN assignments a ON a.id = lr.assignment_id
    GROUP BY a.id, a.title
    HAVING total >= 2
    ORDER BY (failed/total) DESC, total DESC
    LIMIT 8
");

// ── 4. Score trend (รายวัน 30 วันล่าสุด) ───────────────────
$score_trend = qFetch($pdo, "
    SELECT DATE(created_at) AS day,
           ROUND(AVG(final_score),1) AS avg_score,
           COUNT(*) AS cnt
    FROM lab_reports
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(created_at)
    ORDER BY day ASC
");

// ── 5. Safety violations trend (จาก ai_feedback JSON) ───────
$recent_reports = qFetch($pdo, "
    SELECT ai_feedback, created_at FROM lab_reports
    WHERE ai_feedback IS NOT NULL
    ORDER BY created_at DESC LIMIT 50
");
$safety_total = 0; $toxic_total = 0; $explosion_total = 0;
foreach ($recent_reports as $r) {
    $fb = json_decode($r['ai_feedback'], true);
    $raw = $fb['raw_telemetry']['actions'] ?? [];
    $safety_total    += (int)($raw['safety_violations'] ?? 0);
    $toxic_total     += (int)($raw['toxicExposures']    ?? 0);
    $explosion_total += (int)($raw['boilingExplosions'] ?? 0);
}

// ── 6. Assignment completion time (avg) ──────────────────────
$time_data = qFetch($pdo, "
    SELECT a.title,
           COUNT(*) AS submissions,
           ROUND(AVG(lr.final_score),1) AS avg_score
    FROM lab_reports lr
    JOIN assignments a ON a.id = lr.assignment_id
    GROUP BY a.id, a.title
    ORDER BY submissions DESC
    LIMIT 8
");

// ── 7. Cross-tenant analytics (developer only) ───────────────
$cross_stats = [];
if ($is_dev && isset($master_pdo)) {
    try {
        $tenants = $master_pdo->query("SELECT school_code, school_name, db_name FROM sys_tenants WHERE is_active=1")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tenants as $t) {
            try {
                $tpdo = new PDO(
                    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                        env('MASTER_DB_HOST','localhost'), (int)env('MASTER_DB_PORT',3306), $t['db_name']),
                    env('MASTER_DB_USER','root'), env('MASTER_DB_PASS',''),
                    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
                );
                $cross_stats[] = [
                    'school'      => $t['school_name'],
                    'code'        => $t['school_code'],
                    'reports'     => (int)$tpdo->query("SELECT COUNT(*) FROM lab_reports")->fetchColumn(),
                    'avg_score'   => round((float)$tpdo->query("SELECT AVG(final_score) FROM lab_reports")->fetchColumn(), 1),
                    'online'      => (int)$tpdo->query("SELECT COUNT(DISTINCT user_id) FROM user_sessions WHERE last_seen_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)")->fetchColumn(),
                ];
            } catch (PDOException $e) { /* tenant ไม่พร้อม */ }
        }
    } catch (PDOException $e) { error_log('[analytics] cross: '.$e->getMessage()); }
}

require_once 'header.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&family=Orbitron:wght@700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
:root { --blue:#3b82f6; --green:#10b981; --amber:#f59e0b; --red:#ef4444; --purple:#a855f7; }
body { background:#f8fafc; font-family:'Sarabun',sans-serif; }
.wrap { max-width:1200px; margin:28px auto; padding:0 20px 60px; }
.page-header { background:linear-gradient(135deg,#7c3aed,#4f46e5); color:white; padding:24px 28px; border-radius:14px; margin-bottom:24px; }
.page-header h1 { margin:0; font-size:1.7rem; }

/* Stats */
.stats-row { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:24px; }
.stat-card { background:white; border-radius:12px; border:1px solid #e2e8f0; padding:20px; text-align:center; box-shadow:0 2px 4px rgba(0,0,0,.04); }
.stat-num { font-size:2rem; font-weight:700; font-family:'Orbitron'; }
.stat-label { font-size:0.88rem; color:#64748b; margin-top:4px; }

.grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px; }
.grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; margin-bottom:20px; }

.card { background:white; border-radius:12px; border:1px solid #e2e8f0; padding:22px; box-shadow:0 2px 4px rgba(0,0,0,.04); }
.card h3 { margin:0 0 16px; font-size:1.05rem; color:#1e293b; }

/* Grade dist */
.grade-row { display:flex; align-items:center; gap:10px; margin-bottom:8px; }
.grade-lbl { width:28px; font-weight:700; text-align:center; }
.grade-bar { flex:1; background:#f1f5f9; border-radius:4px; height:20px; overflow:hidden; }
.grade-fill { height:100%; border-radius:4px; transition:width 0.8s ease; }
.grade-cnt { font-size:0.85rem; color:#64748b; min-width:36px; text-align:right; }

/* Table */
.data-table { width:100%; border-collapse:collapse; font-size:0.9rem; }
.data-table th { background:#f8fafc; padding:8px 12px; text-align:left; border-bottom:2px solid #e2e8f0; color:#475569; font-weight:600; }
.data-table td { padding:8px 12px; border-bottom:1px solid #f1f5f9; }
.data-table tr:hover td { background:#f8fafc; }
.badge { padding:3px 8px; border-radius:6px; font-size:0.8rem; font-weight:600; }
.badge-red   { background:#fee2e2; color:#991b1b; }
.badge-amber { background:#fef9c3; color:#854d0e; }
.badge-green { background:#dcfce7; color:#166534; }

/* Safety cards */
.safety-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
.safety-card { background:#f8fafc; border-radius:10px; padding:16px; text-align:center; border:1px solid #e2e8f0; }
.safety-num { font-size:1.8rem; font-weight:700; font-family:'Orbitron'; }

/* Cross-tenant */
.school-row { display:flex; align-items:center; gap:12px; padding:10px 0; border-bottom:1px solid #f1f5f9; }
.school-row:last-child { border:none; }
.school-badge { background:#ede9fe; color:#7c3aed; padding:3px 10px; border-radius:6px; font-size:0.8rem; font-weight:600; }
</style>

<div class="wrap">
    <div class="page-header">
        <h1>📊 Lab Analytics Dashboard</h1>
        <p style="margin:6px 0 0; opacity:0.85;">วิเคราะห์ผลการทดลองและพฤติกรรมนักเรียน</p>
    </div>

    <!-- Summary Stats -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-num" style="color:var(--blue);"><?= $total_reports ?></div>
            <div class="stat-label">รายงานทั้งหมด</div>
        </div>
        <div class="stat-card">
            <div class="stat-num" style="color:var(--green);"><?= $avg_score ?></div>
            <div class="stat-label">คะแนนเฉลี่ย</div>
        </div>
        <div class="stat-card">
            <div class="stat-num" style="color:var(--amber);"><?= $pass_rate ?>%</div>
            <div class="stat-label">อัตราผ่าน (≥50)</div>
        </div>
        <div class="stat-card">
            <div class="stat-num" style="color:var(--purple);"><?= $avg_hp ?>%</div>
            <div class="stat-label">HP เฉลี่ยที่เหลือ</div>
        </div>
    </div>

    <div class="grid-2">
        <!-- Grade Distribution -->
        <div class="card">
            <h3>🎓 การกระจายเกรด</h3>
            <?php
            $gcolors = ['A'=>'#f59e0b','B'=>'#38bdf8','C'=>'#10b981','D'=>'#a855f7','F'=>'#ef4444'];
            $max_g = max(array_values($grade_dist) ?: [1]);
            foreach (['A','B','C','D','F'] as $g):
                $cnt = $grade_dist[$g] ?? 0;
                $w   = $max_g > 0 ? round($cnt/$max_g*100) : 0;
            ?>
            <div class="grade-row">
                <div class="grade-lbl" style="color:<?= $gcolors[$g] ?>;"><?= $g ?></div>
                <div class="grade-bar">
                    <div class="grade-fill" style="width:<?= $w ?>%;background:<?= $gcolors[$g] ?>;"></div>
                </div>
                <div class="grade-cnt"><?= $cnt ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Safety Stats -->
        <div class="card">
            <h3>⛑️ สถิติความปลอดภัย (50 รายงานล่าสุด)</h3>
            <div class="safety-grid">
                <div class="safety-card">
                    <div class="safety-num" style="color:var(--amber);"><?= $safety_total ?></div>
                    <div style="font-size:0.85rem;color:#64748b;margin-top:4px;">🚫 ละเมิดความปลอดภัย</div>
                </div>
                <div class="safety-card">
                    <div class="safety-num" style="color:var(--red);"><?= $explosion_total ?></div>
                    <div style="font-size:0.85rem;color:#64748b;margin-top:4px;">💥 บีกเกอร์ระเบิด</div>
                </div>
                <div class="safety-card">
                    <div class="safety-num" style="color:var(--purple);"><?= $toxic_total ?></div>
                    <div style="font-size:0.85rem;color:#64748b;margin-top:4px;">☣️ สัมผัสสารพิษ</div>
                </div>
            </div>
            <canvas id="safetyChart" height="120" style="margin-top:16px;"></canvas>
        </div>
    </div>

    <!-- Score Trend Chart -->
    <div class="card" style="margin-bottom:20px;">
        <h3>📈 คะแนนเฉลี่ยรายวัน (30 วันล่าสุด)</h3>
        <canvas id="trendChart" height="80"></canvas>
    </div>

    <div class="grid-2">
        <!-- Hard Assignments -->
        <div class="card">
            <h3>🔥 งานที่ยากที่สุด (Fail Rate สูง)</h3>
            <?php if (empty($hard_assigns)): ?>
                <p style="color:#94a3b8;">ยังไม่มีข้อมูลเพียงพอ</p>
            <?php else: ?>
            <table class="data-table">
                <tr><th>ชื่องาน</th><th>ส่ง</th><th>ไม่ผ่าน</th><th>เฉลี่ย</th></tr>
                <?php foreach ($hard_assigns as $h):
                    $fail_pct = $h['total'] > 0 ? round($h['failed']/$h['total']*100) : 0;
                    $badge = $fail_pct >= 50 ? 'badge-red' : ($fail_pct >= 25 ? 'badge-amber' : 'badge-green');
                ?>
                <tr>
                    <td><?= htmlspecialchars(mb_strimwidth($h['title'],0,30,'…')) ?></td>
                    <td><?= $h['total'] ?></td>
                    <td><span class="badge <?= $badge ?>"><?= $fail_pct ?>%</span></td>
                    <td><?= $h['avg_score'] ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>
        </div>

        <!-- Assignment Stats -->
        <div class="card">
            <h3>📚 งานที่ส่งมากที่สุด</h3>
            <?php if (empty($time_data)): ?>
                <p style="color:#94a3b8;">ยังไม่มีข้อมูล</p>
            <?php else: ?>
            <table class="data-table">
                <tr><th>ชื่องาน</th><th>ส่ง</th><th>เฉลี่ย</th></tr>
                <?php foreach ($time_data as $t): ?>
                <tr>
                    <td><?= htmlspecialchars(mb_strimwidth($t['title'],0,30,'…')) ?></td>
                    <td><?= $t['submissions'] ?></td>
                    <td><?= $t['avg_score'] ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Cross-Tenant (Developer only) -->
    <?php if ($is_dev && !empty($cross_stats)): ?>
    <div class="card">
        <h3>🏫 สถิติข้ามโรงเรียน (Platform Overview)</h3>
        <?php foreach ($cross_stats as $s): ?>
        <div class="school-row">
            <span class="school-badge"><?= htmlspecialchars($s['code']) ?></span>
            <div style="flex:1;font-weight:600;"><?= htmlspecialchars($s['school']) ?></div>
            <div style="text-align:right;">
                <span style="color:var(--blue);font-weight:600;"><?= $s['reports'] ?></span> รายงาน ·
                เฉลี่ย <span style="color:var(--green);font-weight:600;"><?= $s['avg_score'] ?></span> ·
                <span style="color:var(--green);">🟢 <?= $s['online'] ?> online</span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script>
// Score Trend Chart
const trendCtx = document.getElementById('trendChart');
if (trendCtx) {
    const trendData = <?= json_encode($score_trend, JSON_UNESCAPED_UNICODE) ?>;
    new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: trendData.map(d => d.day),
            datasets: [{
                label: 'คะแนนเฉลี่ย',
                data: trendData.map(d => d.avg_score),
                borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.1)',
                tension: 0.4, fill: true, pointRadius: 3,
            },{
                label: 'จำนวนรายงาน',
                data: trendData.map(d => d.cnt),
                borderColor: '#10b981', borderDash: [4,4],
                tension: 0.3, fill: false, pointRadius: 2, yAxisID: 'y2',
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'bottom' } },
            scales: {
                y:  { min:0, max:100, title:{ display:true, text:'คะแนน' } },
                y2: { position:'right', title:{ display:true, text:'จำนวน' }, grid:{ drawOnChartArea:false } }
            }
        }
    });
}

// Safety pie chart
const safetyCtx = document.getElementById('safetyChart');
if (safetyCtx) {
    new Chart(safetyCtx, {
        type: 'doughnut',
        data: {
            labels: ['ละเมิดความปลอดภัย','ระเบิด','สารพิษ'],
            datasets: [{
                data: [<?= $safety_total ?>, <?= $explosion_total ?>, <?= $toxic_total ?>],
                backgroundColor: ['#f59e0b','#ef4444','#a855f7'],
                borderWidth: 0,
            }]
        },
        options: {
            responsive: true, cutout: '65%',
            plugins: { legend: { position: 'bottom', labels: { font: { family: 'Sarabun' } } } }
        }
    });
}
</script>

<?php require_once 'footer.php'; ?>
