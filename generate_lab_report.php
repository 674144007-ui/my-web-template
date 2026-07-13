<?php
/**
 * generate_lab_report.php — PDF Report Generator (Phase 4)
 * รองรับทั้ง GET ?id=X (ครูดู) และ POST (นักเรียนส่ง)
 */

require_once __DIR__ . '/env_loader.php';
require_once 'config.php';
require_once 'auth.php';
require_once 'db.php';

requireLogin();

// ── โหลดข้อมูล report ─────────────────────────────────────────
$report = null;
$assignment = null;
$ai_fb = [];
$beaker_snapshot = [];
$breakdown_lines = [];
$hints = [];

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id'])) {
    // ครูหรือ developer ดูรายงานของนักเรียน
    if (!in_array($_SESSION['role'], ['teacher','developer'])) {
        die("ไม่มีสิทธิ์เข้าถึง");
    }
    $report_id = (int)$_GET['id'];
    $stmt = $pdo->prepare("
        SELECT lr.*, u.display_name, c.class_name,
               a.title AS assignment_title, a.description AS assignment_desc
        FROM lab_reports lr
        JOIN users u        ON u.id = lr.student_id
        LEFT JOIN classes c ON c.id = u.class_id
        LEFT JOIN assignments a ON a.id = lr.assignment_id
        WHERE lr.id = ?
    ");
    $stmt->execute([$report_id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$report) die("ไม่พบรายงาน");

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // นักเรียนส่งข้อมูลหลัง submit
    verify_csrf_token($_POST['csrf_token'] ?? '');

    // หา report ล่าสุดของนักเรียนใน assignment นี้
    $assignment_id = (int)($_POST['assignment_id'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT lr.*, u.display_name, c.class_name,
               a.title AS assignment_title, a.description AS assignment_desc
        FROM lab_reports lr
        JOIN users u        ON u.id = lr.student_id
        LEFT JOIN classes c ON c.id = u.class_id
        LEFT JOIN assignments a ON a.id = lr.assignment_id
        WHERE lr.student_id = ? AND lr.assignment_id = ?
        ORDER BY lr.created_at DESC LIMIT 1
    ");
    $stmt->execute([$_SESSION['user_id'], $assignment_id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    // fallback: ข้อมูลจาก POST ถ้าไม่เจอใน DB
    if (!$report) {
        $report = [
            'display_name'     => $_SESSION['display_name'] ?? 'ไม่ระบุ',
            'class_name'       => $_SESSION['class_id'] ?? 'ไม่ระบุ',
            'assignment_title' => 'การทดลองเคมี',
            'final_score'      => 0,
            'grade'            => '-',
            'hp_remaining'     => 100,
            'earned_xp'        => 0,
            'teacher_comment'  => '',
            'report_summary'   => '',
            'ai_feedback'      => '{}',
            'created_at'       => date('Y-m-d H:i:s'),
        ];
    }
} else {
    die("ไม่อนุญาตให้เข้าถึงหน้านี้โดยตรง");
}

// ── Parse AI feedback ─────────────────────────────────────────
$ai_raw = json_decode($report['ai_feedback'] ?? '{}', true) ?: [];
$beaker_snapshot = $ai_raw['beaker_snapshot'] ?? [];
$hints           = $ai_raw['personalized_hints'] ?? [];
$breakdown_lines = array_filter(explode("\n", $report['report_summary'] ?? ''));
$school_name     = 'โรงเรียนบ้านคาวิทยา';
$date_str        = date('d/m/Y เวลา H:i น.', strtotime($report['created_at']));

?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>รายงานผลการทดลอง — <?= h($report['display_name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
* { box-sizing:border-box; }
body { font-family:'Sarabun',sans-serif; color:#000; background:#525659; margin:0; padding:20px; }

.controls {
    max-width:220mm; margin:0 auto 16px; display:flex; gap:10px;
}
.controls button {
    padding:10px 20px; border:none; border-radius:8px; cursor:pointer;
    font-family:'Sarabun',sans-serif; font-size:1rem; font-weight:600;
}
.btn-print { background:#2563eb; color:white; }
.btn-close { background:#e2e8f0; color:#475569; }

.a4-page {
    background:white; width:210mm; min-height:297mm;
    margin:0 auto; padding:18mm 20mm;
    box-shadow:0 0 20px rgba(0,0,0,0.4);
}

/* Header */
.doc-header { text-align:center; border-bottom:3px double #1e3a8a; padding-bottom:14px; margin-bottom:18px; }
.school-name { font-size:13pt; color:#1e3a8a; font-weight:600; margin:0 0 4px; }
.doc-title { font-size:18pt; font-weight:700; margin:6px 0 4px; }
.doc-sub { font-size:11pt; color:#475569; margin:0; }

/* Info table */
.info-table { width:100%; border-collapse:collapse; margin-bottom:18px; font-size:11pt; }
.info-table td { padding:5px 8px; border:1px solid #cbd5e1; }
.info-table td:first-child { background:#f1f5f9; font-weight:600; width:35%; }

/* Score panel */
.score-panel {
    display:flex; gap:16px; margin-bottom:18px;
    background:#f8fafc; border-radius:10px; padding:16px; border:1px solid #e2e8f0;
}
.score-circle {
    width:80px; height:80px; border-radius:50%; display:flex;
    align-items:center; justify-content:center; flex-direction:column;
    font-weight:700; color:white; flex-shrink:0;
}
.score-circle.A { background:#f59e0b; }
.score-circle.B { background:#38bdf8; }
.score-circle.C { background:#10b981; }
.score-circle.D { background:#a855f7; }
.score-circle.F { background:#ef4444; }
.score-pts { font-size:22pt; line-height:1; }
.score-xp  { font-size:9pt; opacity:0.9; }
.score-detail { flex:1; font-size:10.5pt; }
.score-detail div { margin-bottom:4px; }

/* Sections */
.section { margin-bottom:18px; }
.section-title { font-size:12pt; font-weight:700; color:#1e3a8a; border-left:4px solid #3b82f6; padding-left:10px; margin-bottom:10px; }

/* Breakdown */
.breakdown-item { font-size:10pt; padding:3px 0; border-bottom:1px solid #f1f5f9; }
.breakdown-item.pass { color:#166534; }
.breakdown-item.fail { color:#991b1b; }

/* Beaker table */
.chem-table { width:100%; border-collapse:collapse; font-size:10pt; }
.chem-table th { background:#1e3a8a; color:white; padding:6px 10px; text-align:left; }
.chem-table td { padding:5px 10px; border-bottom:1px solid #e2e8f0; }
.chem-table tr:nth-child(even) td { background:#f8fafc; }

/* Hints */
.hint-item { font-size:10pt; background:#fef3c7; border-left:3px solid #f59e0b; padding:6px 10px; margin-bottom:6px; border-radius:0 6px 6px 0; }

/* Teacher comment */
.comment-box { background:#f0f9ff; border:1px solid #bae6fd; border-radius:8px; padding:14px; font-size:10.5pt; min-height:60px; }

/* Write-in lines */
.write-lines { margin-top:10px; }
.write-line { border-bottom:1px solid #94a3b8; height:22px; margin-bottom:4px; }

/* Signature */
.sig-row { display:flex; justify-content:space-between; margin-top:30px; font-size:10.5pt; }
.sig-box { text-align:center; width:45%; }
.sig-line { border-bottom:1px solid #000; margin:0 auto 6px; width:80%; }

/* Print */
@media print {
    body { background:white; padding:0; }
    .a4-page { box-shadow:none; width:auto; min-height:auto; }
    .controls { display:none; }
}
</style>
</head>
<body>

<div class="controls no-print">
    <button class="btn-print" onclick="window.print()">🖨️ พิมพ์ / บันทึก PDF</button>
    <button class="btn-close" onclick="window.close()">✕ ปิด</button>
</div>

<div class="a4-page">

    <!-- Header -->
    <div class="doc-header">
        <div class="school-name">📚 <?= h($school_name) ?></div>
        <div class="doc-title">แบบบันทึกผลการทดลองเคมี (Virtual Lab)</div>
        <div class="doc-sub">ระบบห้องทดลองเสมือนจริง — Smart School Plus</div>
    </div>

    <!-- Info -->
    <table class="info-table">
        <tr><td>ชื่อ-นามสกุล</td><td><?= h($report['display_name']) ?></td></tr>
        <tr><td>ระดับชั้น</td><td><?= h($report['class_name'] ?? 'ไม่ระบุ') ?></td></tr>
        <tr><td>ชื่องาน/ภารกิจ</td><td><?= h($report['assignment_title'] ?? 'ไม่ระบุ') ?></td></tr>
        <tr><td>วันและเวลาที่ทดลอง</td><td><?= $date_str ?></td></tr>
    </table>

    <!-- Score -->
    <div class="score-panel">
        <?php $g = $report['grade'] ?? 'F'; ?>
        <div class="score-circle <?= h($g) ?>">
            <div class="score-pts"><?= (int)$report['final_score'] ?></div>
            <div class="score-xp">เกรด <?= h($g) ?></div>
        </div>
        <div class="score-detail">
            <div>🏆 <strong>คะแนนที่ได้:</strong> <?= (int)$report['final_score'] ?> / 100 คะแนน</div>
            <div>⭐ <strong>XP ที่ได้รับ:</strong> <?= (int)($report['earned_xp']??0) ?> XP</div>
            <div>❤️ <strong>HP คงเหลือ:</strong> <?= (int)$report['hp_remaining'] ?>%</div>
            <div>📅 <strong>วันที่ส่ง:</strong> <?= $date_str ?></div>
        </div>
    </div>

    <!-- Beaker Snapshot -->
    <?php if (!empty($beaker_snapshot)): ?>
    <div class="section">
        <div class="section-title">🧪 สารเคมีที่ใช้ในการทดลอง</div>
        <table class="chem-table">
            <tr><th>ชื่อสาร</th><th>สูตรเคมี</th><th>ปริมาณ</th><th>สถานะ</th></tr>
            <?php foreach ($beaker_snapshot as $item): ?>
            <tr>
                <td><?= h($item['name'] ?? '?') ?></td>
                <td><?= h($item['formula'] ?? '-') ?></td>
                <td><?= number_format(floatval($item['amount']??0),1) ?> <?= ($item['state']??'')==='solid'?'g':'ml' ?></td>
                <td><?= h($item['state'] ?? '-') ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>

    <!-- Breakdown -->
    <div class="section">
        <div class="section-title">📊 ผลการตรวจ (AI Auto-Grading)</div>
        <?php foreach ($breakdown_lines as $line): if (!trim($line)) continue;
            $cls = (strpos($line,'✅') !== false) ? 'pass' : ((strpos($line,'❌') !== false || strpos($line,'💥') !== false) ? 'fail' : '');
        ?>
            <div class="breakdown-item <?= $cls ?>"><?= h($line) ?></div>
        <?php endforeach; ?>
    </div>

    <!-- AI Hints -->
    <?php if (!empty($hints)): ?>
    <div class="section">
        <div class="section-title">💡 คำแนะนำสำหรับการปรับปรุง</div>
        <?php foreach ($hints as $hint): ?>
            <div class="hint-item">• <?= h($hint) ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Teacher Comment -->
    <div class="section">
        <div class="section-title">💬 ความคิดเห็นจากครูผู้สอน</div>
        <div class="comment-box">
            <?php if ($report['teacher_comment'] ?? ''): ?>
                <?= nl2br(h($report['teacher_comment'])) ?>
            <?php else: ?>
                <span style="color:#94a3b8;">ยังไม่มีความคิดเห็นจากครู</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Student write-in section -->
    <div class="section">
        <div class="section-title">📝 สรุปและวิจารณ์ผลการทดลอง (นักเรียนเขียน)</div>
        <div class="write-lines">
            <?php for ($i=0;$i<5;$i++): ?><div class="write-line"></div><?php endfor; ?>
        </div>
    </div>

    <!-- Signatures -->
    <div class="sig-row">
        <div class="sig-box">
            <div class="sig-line"></div>
            <div>ลงชื่อ ผู้ทดลอง</div>
            <div>( <?= h($report['display_name']) ?> )</div>
            <div>วันที่ ...... / ...... / ......</div>
        </div>
        <div class="sig-box">
            <div class="sig-line"></div>
            <div>ลงชื่อ ครูผู้สอน</div>
            <div>( .................................................. )</div>
            <div>วันที่ ...... / ...... / ......</div>
        </div>
    </div>

</div><!-- .a4-page -->

<script>
// Auto print เมื่อมาจากระบบ (ถ้าต้องการเปิดใช้)
// window.onload = () => window.print();
</script>
</body>
</html>
