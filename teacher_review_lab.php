<?php
// teacher_review_lab.php - หน้าตรวจ Lab Report สำหรับครู (Phase 4)
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';

requireRole(['teacher', 'developer']);

$page_title = "ตรวจรายงานห้องทดลอง (Lab Reports)";
$teacher_id = $_SESSION['user_id'];

// ดึงรายการรายงานของนักเรียนที่ทำเควสของครูคนนี้
$sql = "
    SELECT lr.*, u.display_name, u.class_level, q.title as quest_title 
    FROM lab_reports lr
    JOIN users u ON lr.student_id = u.id
    JOIN quests q ON lr.quest_id = q.id
    WHERE q.teacher_id = ?
    ORDER BY lr.created_at DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$reports = $stmt->get_result();

// จัดการการส่งคอมเมนต์ของครู
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_comment'])) {
    if (hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $report_id = intval($_POST['report_id']);
        $comment = trim($_POST['teacher_comment']);
        $upd = $conn->prepare("UPDATE lab_reports SET teacher_comment = ? WHERE id = ?");
        $upd->bind_param("si", $comment, $report_id);
        if ($upd->execute()) {
            $success_msg = "บันทึกความคิดเห็นเรียบร้อยแล้ว";
        }
        $upd->close();
        header("Location: teacher_review_lab.php?success=1");
        exit;
    }
}

require_once 'header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&family=Orbitron:wght@700&display=swap" rel="stylesheet">
<style>
    body { background-color: #f8fafc; font-family: 'Sarabun', sans-serif; }
    .report-container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
    .page-header { background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: white; padding: 30px; border-radius: 16px; margin-bottom: 30px; box-shadow: 0 10px 30px rgba(59, 130, 246, 0.2); }
    .page-header h1 { margin: 0; font-size: 2rem; }
    
    .report-card { background: white; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 20px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.02); transition: 0.3s; }
    .report-card:hover { box-shadow: 0 10px 20px rgba(0,0,0,0.08); transform: translateY(-3px); border-color: #cbd5e1; }
    
    .report-summary { display: flex; padding: 20px; cursor: pointer; align-items: center; gap: 20px; }
    .r-grade { width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; font-weight: bold; font-family: 'Orbitron'; color: white; flex-shrink: 0; }
    .grade-A { background: #f59e0b; box-shadow: 0 0 15px rgba(245, 158, 11, 0.5); }
    .grade-B { background: #38bdf8; }
    .grade-C { background: #22c55e; }
    .grade-D { background: #a855f7; }
    .grade-F { background: #ef4444; }

    .r-info { flex: 1; }
    .r-name { font-size: 1.1rem; font-weight: 600; color: #1e293b; margin-bottom: 5px; }
    .r-meta { font-size: 0.9rem; color: #64748b; display: flex; gap: 15px; }
    .badge { background: #f1f5f9; padding: 4px 10px; border-radius: 6px; font-weight: 600; }
    
    /* ส่วนขยายดูรายละเอียด Log */
    .report-details { background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 25px; display: none; }
    .log-box { background: #0f172a; color: #cbd5e1; font-family: monospace; font-size: 0.9rem; padding: 15px; border-radius: 8px; height: 200px; overflow-y: auto; margin-bottom: 20px; }
    .log-line { border-bottom: 1px solid #1e293b; padding: 4px 0; }
    
    .comment-box textarea { width: 100%; padding: 15px; border-radius: 10px; border: 1px solid #cbd5e1; font-family: inherit; margin-bottom: 15px; box-sizing: border-box; }
    .btn-save { background: #10b981; color: white; border: none; padding: 10px 25px; border-radius: 8px; font-weight: bold; cursor: pointer; transition: 0.2s; }
    .btn-save:hover { background: #059669; }
</style>

<div class="report-container">
    <div class="page-header">
        <h1>📑 ตรวจรายงานห้องทดลอง (Lab Reports)</h1>
        <p style="margin-top:10px; opacity: 0.9;">ตรวจสอบการทำภารกิจและประวัติการทดลองของนักเรียน</p>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div style="background: #dcfce7; color: #166534; padding: 15px; border-radius: 10px; margin-bottom: 20px;">✅ บันทึกคอมเมนต์เรียบร้อยแล้ว</div>
    <?php endif; ?>

    <?php if ($reports->num_rows === 0): ?>
        <div style="text-align:center; padding: 50px; background: white; border-radius: 12px; color: #64748b;">ยังไม่มีนักเรียนส่งรายงานเข้ามาครับ</div>
    <?php else: ?>
        <?php while ($r = $reports->fetch_assoc()): 
            $summary = json_decode($r['report_summary'], true);
            $logs = $summary['action_logs'] ?? [];
        ?>
            <div class="report-card">
                <div class="report-summary" onclick="toggleDetails(<?= $r['id'] ?>)">
                    <div class="r-grade grade-<?= $r['grade'] ?>"><?= $r['grade'] ?></div>
                    <div class="r-info">
                        <div class="r-name"><?= htmlspecialchars($r['display_name']) ?> <span style="color:#3b82f6; font-size:0.9rem;">(<?= htmlspecialchars($r['class_level']) ?>)</span></div>
                        <div class="r-meta">
                            <span>ภารกิจ: <strong><?= htmlspecialchars($r['quest_title']) ?></strong></span>
                            <span class="badge" style="color: #ef4444;">❤️ HP: <?= $r['hp_remaining'] ?>%</span>
                            <span class="badge" style="color: #f59e0b;">💦 สารหก: <?= $summary['spill_count'] ?? 0 ?> ครั้ง</span>
                            <span>📅 <?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></span>
                        </div>
                    </div>
                    <div style="color: #cbd5e1; font-size: 1.5rem;">▼</div>
                </div>

                <div class="report-details" id="details-<?= $r['id'] ?>">
                    <h4 style="margin-top:0;">📝 ประวัติการกระทำในห้องปฏิบัติการ (Action Logs)</h4>
                    <div class="log-box">
                        <?php if (empty($logs)): ?>
                            <div style="color:#64748b;">ไม่มีบันทึกการกระทำ</div>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <div class="log-line"><?= htmlspecialchars($log) ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <form method="POST" class="comment-box">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <input type="hidden" name="report_id" value="<?= $r['id'] ?>">
                        <h4 style="margin-bottom: 10px;">💬 ความคิดเห็นและข้อเสนอแนะจากครู</h4>
                        <textarea name="teacher_comment" rows="3" placeholder="พิมพ์คำแนะนำให้นักเรียนที่นี่..."><?= htmlspecialchars($r['teacher_comment'] ?? '') ?></textarea>
                        <button type="submit" name="submit_comment" class="btn-save">💾 บันทึกคอมเมนต์</button>
                    </form>
                </div>
            </div>
        <?php endwhile; ?>
    <?php endif; ?>
</div>

<script>
    function toggleDetails(id) {
        const details = document.getElementById('details-' + id);
        if (details.style.display === 'block') {
            details.style.display = 'none';
        } else {
            // ปิดอันอื่นก่อน
            document.querySelectorAll('.report-details').forEach(el => el.style.display = 'none');
            details.style.display = 'block';
        }
    }
</script>

<?php require_once 'footer.php'; ?>