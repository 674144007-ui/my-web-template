<?php
/**
 * student_reports.php - หน้ารายงานผลการปฏิบัติการทดลอง (Student Feedback Loop)
 * สถาปัตยกรรม: Unified Ecosystem Phase 7 (AI Feedback & Telemetry Viewer)
 */

require_once __DIR__ . '/env_loader.php';
require_once 'config.php';
require_once 'security.php';
require_once 'auth.php';
require_once 'db.php';
requireRole(['student','developer']);

// =========================================================================
// 🔐 ตรวจสอบสิทธิ์ (เฉพาะ Student เท่านั้น)
// =========================================================================
$username = $_SESSION['username'];
$role = $_SESSION['role'];
$student_id = $_SESSION['user_id'];

// role checked by requireRole above

$page_title = "รายงานผลการทดลอง (AI Feedback) - Smart School Plus";

// =========================================================================
// 📊 ดึงข้อมูลสถิติภาพรวมของนักเรียน
// =========================================================================
$stats = [
    'total_labs' => 0,
    'total_xp' => 0,
    'avg_score' => 0,
    'explosions' => 0
];

try {
    $stmt_stats = $pdo->prepare("
        SELECT 
            COUNT(id) as total_labs, 
            SUM(earned_xp) as total_xp, 
            AVG(final_score) as avg_score 
        FROM lab_reports 
        WHERE student_id = ?
    ");
    $stmt_stats->execute([$student_id]);
    $res_stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
    
    if ($res_stats) {
        $stats['total_labs'] = intval($res_stats['total_labs']);
        $stats['total_xp'] = intval($res_stats['total_xp']);
        $stats['avg_score'] = floatval($res_stats['avg_score']);
    }
} catch (PDOException $e) {
    error_log("Student Stats Error: " . $e->getMessage());
}

// =========================================================================
// 📥 ดึงประวัติการทำแล็บทั้งหมดของนักเรียน
// =========================================================================
$reports = [];
try {
    $stmt_reports = $pdo->prepare("
        SELECT 
            lr.id as report_id, 
            lr.final_score, 
            lr.earned_xp, 
            lr.grade, 
            lr.hp_remaining, 
            lr.report_summary, 
            lr.ai_feedback, 
            lr.created_at,
            a.title as assignment_title,
            s.subject_name,
            s.color_theme,
            s.icon
        FROM lab_reports lr
        JOIN assignments a ON lr.assignment_id = a.id
        JOIN subjects s ON a.subject_id = s.id
        WHERE lr.student_id = ?
        ORDER BY lr.created_at DESC
    ");
    $stmt_reports->execute([$student_id]);
    $reports = $stmt_reports->fetchAll(PDO::FETCH_ASSOC);
    
    // นับจำนวนการระเบิดจากข้อมูล JSON Telemetry
    foreach ($reports as $r) {
        if (!empty($r['ai_feedback'])) {
            $feedback_data = json_decode($r['ai_feedback'], true);
            if (isset($feedback_data['raw_telemetry']['actions']['exploded']) && $feedback_data['raw_telemetry']['actions']['exploded'] == true) {
                $stats['explosions']++;
            }
        }
    }

} catch (PDOException $e) {
    error_log("Student Reports Error: " . $e->getMessage());
}

require_once 'header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&family=Prompt:wght@300;400;600;700&family=Share+Tech+Mono&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    /* ============================================================
       📍 UI ARCHITECTURE (STUDENT REPORT THEME)
       ============================================================ */
    body {
        background-color: #0f172a; /* Dark LMS Theme */
        font-family: 'Prompt', sans-serif;
        color: #f8fafc;
        margin: 0;
    }
    .page-container {
        max-width: 1200px;
        margin: 40px auto;
        padding: 0 20px;
    }
    
    /* 🌟 Header Section */
    .report-header {
        background: linear-gradient(135deg, #1e293b 0%, #020617 100%);
        border-radius: 20px;
        padding: 40px;
        border: 1px solid #334155;
        margin-bottom: 40px;
        box-shadow: 0 15px 30px rgba(0,0,0,0.5);
        position: relative;
        overflow: hidden;
    }
    .report-header::after {
        content: '';
        position: absolute;
        right: -50px;
        top: -50px;
        width: 300px;
        height: 300px;
        background: radial-gradient(circle, rgba(56, 189, 248, 0.15) 0%, transparent 70%);
        border-radius: 50%;
        pointer-events: none;
    }
    .ph-title {
        font-size: 2.2rem;
        font-weight: 700;
        margin: 0 0 10px 0;
        display: flex;
        align-items: center;
        gap: 15px;
    }
    .ph-subtitle {
        color: #94a3b8;
        font-size: 1.1rem;
        margin: 0;
    }
    .badge-xp {
        background: rgba(245, 158, 11, 0.1);
        color: #fcd34d;
        border: 1px solid #f59e0b;
        padding: 8px 15px;
        border-radius: 30px;
        font-family: 'Orbitron', sans-serif;
        font-weight: bold;
        letter-spacing: 1px;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        box-shadow: 0 0 15px rgba(245, 158, 11, 0.2);
    }

    /* 📊 Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 20px;
        margin-top: 30px;
    }
    .stat-box {
        background: #020617;
        border: 1px solid #334155;
        border-radius: 15px;
        padding: 20px;
        text-align: center;
        transition: transform 0.3s, border-color 0.3s;
    }
    .stat-box:hover {
        transform: translateY(-5px);
        border-color: #38bdf8;
    }
    .stat-value {
        font-family: 'Share Tech Mono', monospace;
        font-size: 2.5rem;
        font-weight: bold;
        color: #38bdf8;
        line-height: 1;
        margin-bottom: 5px;
    }
    .stat-label {
        font-size: 0.9rem;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    /* 📋 Report Cards List */
    .section-title {
        font-size: 1.5rem;
        font-weight: 700;
        color: white;
        margin-bottom: 25px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid #334155;
        padding-bottom: 10px;
    }
    .report-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
        gap: 25px;
    }
    .report-card {
        background: #1e293b;
        border: 1px solid #334155;
        border-radius: 16px;
        overflow: hidden;
        transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        display: flex;
        flex-direction: column;
        position: relative;
    }
    .report-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 15px 30px rgba(0,0,0,0.5);
        border-color: #475569;
    }
    .rc-header {
        padding: 20px;
        border-bottom: 1px solid rgba(255,255,255,0.05);
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
    }
    .rc-subject {
        font-size: 0.8rem;
        color: #cbd5e1;
        background: rgba(255,255,255,0.1);
        padding: 4px 10px;
        border-radius: 20px;
        display: inline-block;
        margin-bottom: 10px;
    }
    .rc-title {
        font-size: 1.1rem;
        font-weight: bold;
        color: white;
        margin: 0;
        line-height: 1.4;
    }
    .rc-grade-badge {
        font-family: 'Orbitron', sans-serif;
        font-size: 2rem;
        font-weight: bold;
        line-height: 1;
        text-shadow: 0 0 15px currentColor;
    }
    .grade-A { color: #10b981; }
    .grade-B { color: #38bdf8; }
    .grade-C { color: #f59e0b; }
    .grade-D { color: #f97316; }
    .grade-F { color: #ef4444; }

    .rc-body {
        padding: 20px;
        flex: 1;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }
    .rc-stat-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 10px;
        font-size: 0.95rem;
    }
    .rc-stat-label { color: #94a3b8; }
    .rc-stat-value { font-weight: bold; color: white; font-family: 'Share Tech Mono', monospace;}

    .rc-footer {
        padding: 15px 20px;
        background: rgba(0,0,0,0.2);
    }
    .btn-view-feedback {
        width: 100%;
        background: linear-gradient(135deg, #3b82f6, #2563eb);
        color: white;
        border: none;
        padding: 12px;
        border-radius: 10px;
        font-family: 'Prompt', sans-serif;
        font-weight: bold;
        font-size: 1rem;
        cursor: pointer;
        transition: 0.3s;
        box-shadow: 0 5px 15px rgba(59,130,246,0.3);
    }
    .btn-view-feedback:hover {
        background: linear-gradient(135deg, #2563eb, #1d4ed8);
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(59,130,246,0.5);
    }

    /* ============================================================
       🚨 AI FEEDBACK MODAL (The Learning Loop)
       ============================================================ */
    .ai-modal-backdrop {
        position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
        background: rgba(2, 6, 23, 0.9); z-index: 9999;
        display: none; align-items: center; justify-content: center;
        backdrop-filter: blur(10px); opacity: 0; transition: opacity 0.3s;
    }
    .ai-modal-backdrop.show { opacity: 1; }
    
    .ai-modal-box {
        background: #0f172a; width: 90%; max-width: 800px; max-height: 90vh;
        border-radius: 20px; border: 1px solid #334155;
        box-shadow: 0 25px 50px rgba(0,0,0,0.8);
        display: flex; flex-direction: column; overflow: hidden;
        transform: translateY(50px) scale(0.95); transition: 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }
    .ai-modal-box.show { transform: translateY(0) scale(1); }

    .ai-modal-header {
        background: linear-gradient(to right, #1e293b, #0f172a);
        padding: 25px 30px;
        border-bottom: 1px solid #334155;
        display: flex; justify-content: space-between; align-items: center;
    }
    .ai-modal-title { margin: 0; font-size: 1.4rem; color: #38bdf8; display: flex; align-items: center; gap: 10px; font-weight: bold;}
    .btn-close-modal { background: none; border: none; color: #64748b; font-size: 1.8rem; cursor: pointer; transition: 0.2s; line-height: 1;}
    .btn-close-modal:hover { color: #ef4444; transform: rotate(90deg); }

    .ai-modal-body {
        padding: 30px; overflow-y: auto; flex: 1;
        /* Custom Scrollbar */
        scrollbar-width: thin; scrollbar-color: #475569 transparent;
    }
    .ai-modal-body::-webkit-scrollbar { width: 8px; }
    .ai-modal-body::-webkit-scrollbar-thumb { background-color: #475569; border-radius: 4px; }

    /* Score & Grade Display in Modal */
    .modal-score-board {
        display: flex; align-items: center; justify-content: center; gap: 40px;
        background: #020617; padding: 25px; border-radius: 16px; border: 1px solid #1e293b;
        margin-bottom: 30px; box-shadow: inset 0 0 20px rgba(0,0,0,0.5);
    }
    .modal-grade { font-family: 'Orbitron'; font-size: 5rem; font-weight: bold; line-height: 1; text-shadow: 0 0 20px currentColor;}
    .modal-score-details { text-align: left; }
    .score-big { font-family: 'Share Tech Mono'; font-size: 3.5rem; font-weight: bold; color: white; line-height: 1;}
    .score-small { font-size: 1.2rem; color: #64748b; }

    /* HP Remaining Bar */
    .hp-survive-box { margin-bottom: 30px; }
    .hp-survive-label { font-size: 0.9rem; color: #94a3b8; margin-bottom: 8px; font-weight: bold;}
    .hp-track { width: 100%; height: 15px; background: #1e293b; border-radius: 10px; overflow: hidden; border: 1px solid #000; box-shadow: inset 0 0 5px #000;}
    .hp-fill { height: 100%; transition: width 1s ease-out; }

    /* Telemetry Blackbox Stats */
    .telemetry-grid {
        display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 30px;
    }
    .tel-card {
        background: rgba(255,255,255,0.02); border: 1px dashed #334155; border-radius: 12px; padding: 15px; text-align: center;
    }
    .tel-icon { font-size: 2rem; margin-bottom: 5px; }
    .tel-val { font-family: 'Share Tech Mono'; font-size: 1.5rem; font-weight: bold; color: white;}
    .tel-name { font-size: 0.8rem; color: #64748b; text-transform: uppercase;}

    /* AI Feedback Checklist */
    .feedback-section-title { font-size: 1.2rem; color: white; border-bottom: 1px solid #334155; padding-bottom: 10px; margin-bottom: 15px; display: flex; align-items: center; gap: 8px;}
    .feedback-list { list-style: none; padding: 0; margin: 0; }
    .feedback-item {
        padding: 15px; border-radius: 12px; margin-bottom: 10px; font-size: 1rem;
        display: flex; align-items: flex-start; gap: 12px; line-height: 1.5;
        border: 1px solid transparent;
    }
    .fb-good { background: rgba(16, 185, 129, 0.05); border-color: rgba(16, 185, 129, 0.2); color: #cbd5e1; }
    .fb-bad { background: rgba(239, 68, 68, 0.05); border-color: rgba(239, 68, 68, 0.2); color: #fca5a5; }
    .fb-icon { font-size: 1.2rem; flex-shrink: 0; margin-top: -2px;}

    /* Empty State */
    .empty-state { text-align: center; padding: 80px 20px; background: #1e293b; border-radius: 20px; border: 1px dashed #475569; }
    .empty-icon { font-size: 5rem; opacity: 0.5; margin-bottom: 20px;}

</style>

<div class="page-container">

    <div class="report-header">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h1 class="ph-title"><span>📊</span> รายงานผลและ AI Feedback</h1>
                <p class="ph-subtitle">ตรวจสอบข้อผิดพลาดจากการปฏิบัติการทดลองเพื่อพัฒนาทักษะทางเคมีของคุณ</p>
            </div>
            <div class="badge-xp">
                <span style="font-size: 1.2rem;">⭐</span> รวม XP สะสม: <?= number_format($stats['total_xp']) ?>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-box">
                <div class="stat-value"><?= number_format($stats['total_labs']) ?></div>
                <div class="stat-label">แล็บที่ทำสำเร็จ</div>
            </div>
            <div class="stat-box">
                <div class="stat-value"><?= number_format($stats['avg_score'], 1) ?></div>
                <div class="stat-label">คะแนนเฉลี่ย (%)</div>
            </div>
            <div class="stat-box" style="<?= $stats['explosions'] > 0 ? 'border-color: #ef4444;' : '' ?>">
                <div class="stat-value" style="color: <?= $stats['explosions'] > 0 ? '#ef4444' : '#10b981' ?>;">
                    <?= number_format($stats['explosions']) ?>
                </div>
                <div class="stat-label">จำนวนครั้งที่ทำระเบิด</div>
            </div>
        </div>
    </div>

    <h2 class="section-title"><span>📂</span> ประวัติการทำห้องปฏิบัติการเคมี</h2>

    <?php if(empty($reports)): ?>
        <div class="empty-state">
            <div class="empty-icon">🧪</div>
            <h3 style="color: white; font-weight: bold;">ยังไม่มีข้อมูลการทดลอง</h3>
            <p style="color: #94a3b8; font-size: 1.1rem; max-width: 500px; margin: 0 auto 20px auto;">
                คุณยังไม่ได้ทำการส่งรายงานจากระบบ Virtual Chemistry Lab เลย ลองเข้าไปเล่นและทำภารกิจให้สำเร็จเพื่อดู AI Feedback ที่นี่สิ!
            </p>
            <a href="dashboard_student.php" class="btn" style="background: var(--primary); color: #020617; font-weight: bold; padding: 12px 30px; border-radius: 10px;">⬅️ กลับไปหน้าหลัก</a>
        </div>
    <?php else: ?>
        <div class="report-grid">
            <?php foreach($reports as $r): ?>
                <div class="report-card">
                    <div class="rc-header">
                        <div>
                            <div class="rc-subject">
                                <?= $r['icon'] ?> <?= h($r['subject_name']) ?>
                            </div>
                            <h3 class="rc-title"><?= h($r['assignment_title']) ?></h3>
                        </div>
                        <div class="rc-grade-badge grade-<?= $r['grade'] ?>"><?= $r['grade'] ?></div>
                    </div>
                    <div class="rc-body">
                        <div class="rc-stat-row">
                            <span class="rc-stat-label">คะแนนที่ได้ (Score):</span>
                            <span class="rc-stat-value" style="color: <?= $r['final_score'] >= 50 ? '#10b981' : '#ef4444' ?>; font-size: 1.2rem;">
                                <?= number_format($r['final_score']) ?>/100
                            </span>
                        </div>
                        <div class="rc-stat-row">
                            <span class="rc-stat-label">XP ที่ได้รับ:</span>
                            <span class="rc-stat-value" style="color: #fcd34d;">+<?= number_format($r['earned_xp']) ?></span>
                        </div>
                        <div class="rc-stat-row">
                            <span class="rc-stat-label">วันที่ส่ง:</span>
                            <span class="rc-stat-value" style="font-size: 0.85rem;"><?= date('d M Y, H:i', strtotime($r['created_at'])) ?></span>
                        </div>
                        
                        <div style="margin-top: 15px;">
                            <div style="display:flex; justify-content:space-between; font-size:0.8rem; color:#94a3b8; margin-bottom:5px;">
                                <span>ความปลอดภัย (HP)</span>
                                <span><?= $r['hp_remaining'] ?>%</span>
                            </div>
                            <div style="width:100%; height:6px; background:#020617; border-radius:3px; overflow:hidden;">
                                <?php 
                                    $hp_color = '#10b981';
                                    if($r['hp_remaining'] < 50) $hp_color = '#f59e0b';
                                    if($r['hp_remaining'] < 30) $hp_color = '#ef4444';
                                ?>
                                <div style="height:100%; width:<?= $r['hp_remaining'] ?>%; background:<?= $hp_color ?>;"></div>
                            </div>
                        </div>
                    </div>
                    <div class="rc-footer">
                        <button class="btn-view-feedback" onclick="openReportModal(<?= htmlspecialchars(json_encode($r)) ?>)">
                            🔍 ดูรายละเอียดการประเมิน
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<div class="ai-modal-backdrop" id="aiModalBackdrop">
    <div class="ai-modal-box" id="aiModalBox">
        <div class="ai-modal-header">
            <h3 class="ai-modal-title">🤖 AI Evaluation Report</h3>
            <button class="btn-close-modal" onclick="closeReportModal()">✖</button>
        </div>
        <div class="ai-modal-body">
            
            <div class="modal-score-board">
                <div class="modal-grade" id="mdGrade">A</div>
                <div class="modal-score-details">
                    <div style="color:#94a3b8; font-size:0.9rem; text-transform:uppercase; letter-spacing:2px; margin-bottom:5px;">Final Score</div>
                    <div class="score-big"><span id="mdScore">100</span><span class="score-small">/100</span></div>
                    <div style="color:#fcd34d; font-family:'Prompt'; font-weight:bold; margin-top:5px;" id="mdXp">⭐ ได้รับ +1500 XP</div>
                </div>
            </div>

            <h4 class="feedback-section-title">📹 บันทึกพฤติกรรมจากกล่องดำ (Telemetry)</h4>
            <div class="telemetry-grid">
                <div class="tel-card">
                    <div class="tel-icon">🥽</div>
                    <div class="tel-val" id="mdTelSafety">0</div>
                    <div class="tel-name">ละเมิดกฎความปลอดภัย</div>
                </div>
                <div class="tel-card">
                    <div class="tel-icon">💧</div>
                    <div class="tel-val" id="mdTelSpill" style="color:#38bdf8;">0</div>
                    <div class="tel-name">ทำสารเคมีหก (ครั้ง)</div>
                </div>
                <div class="tel-card" id="mdTelExpCard">
                    <div class="tel-icon">💥</div>
                    <div class="tel-val" id="mdTelExp" style="color:#ef4444;">No</div>
                    <div class="tel-name">ทำอุปกรณ์ระเบิด</div>
                </div>
            </div>

            <div class="hp-survive-box">
                <div class="hp-survive-label d-flex justify-content-between">
                    <span>พลังชีวิตที่เหลือรอด (HP Remaining)</span>
                    <span id="mdHpText">100%</span>
                </div>
                <div class="hp-track">
                    <div class="hp-fill" id="mdHpFill" style="width:0%; background:#10b981;"></div>
                </div>
            </div>

            <h4 class="feedback-section-title">📋 ข้อเสนอแนะจากระบบ AI (Feedback Details)</h4>
            <ul class="feedback-list" id="mdFeedbackList">
                </ul>

        </div>
    </div>
</div>

<script>
    const backdrop = document.getElementById('aiModalBackdrop');
    const modalBox = document.getElementById('aiModalBox');

    function openReportModal(reportData) {
        // 1. Set Basic Data
        const gradeEl = document.getElementById('mdGrade');
        gradeEl.innerText = reportData.grade;
        
        // Reset colors
        gradeEl.style.color = '#fff';
        if(reportData.grade === 'A') gradeEl.style.color = '#10b981';
        if(reportData.grade === 'B') gradeEl.style.color = '#38bdf8';
        if(reportData.grade === 'C') gradeEl.style.color = '#f59e0b';
        if(reportData.grade === 'D') gradeEl.style.color = '#f97316';
        if(reportData.grade === 'F') gradeEl.style.color = '#ef4444';

        document.getElementById('mdScore').innerText = reportData.final_score;
        document.getElementById('mdXp').innerText = `⭐ ได้รับ +${reportData.earned_xp} XP`;

        // 2. Set HP Bar
        const hp = parseInt(reportData.hp_remaining);
        document.getElementById('mdHpText').innerText = hp + '%';
        const hpFill = document.getElementById('mdHpFill');
        hpFill.style.width = '0%'; // reset for animation
        setTimeout(() => {
            hpFill.style.width = hp + '%';
            if (hp >= 50) hpFill.style.background = '#10b981';
            else if (hp >= 30) hpFill.style.background = '#f59e0b';
            else hpFill.style.background = '#ef4444';
        }, 300); // delay to let modal open first

        // 3. Parse JSON Telemetry (The Blackbox)
        let telSafety = 0, telSpill = 0, telExp = false;
        let feedbackArray = [];

        if (reportData.ai_feedback) {
            try {
                const aiData = JSON.parse(reportData.ai_feedback);
                feedbackArray = aiData.details || [];
                
                if (aiData.raw_telemetry && aiData.raw_telemetry.actions) {
                    telSafety = aiData.raw_telemetry.actions.safety_violations || 0;
                    telSpill = aiData.raw_telemetry.actions.spill_count || 0;
                    telExp = aiData.raw_telemetry.actions.exploded || false;
                }
            } catch(e) {
                console.error("Failed to parse JSON feedback", e);
                // Fallback to text summary if JSON fails
                feedbackArray = reportData.report_summary ? reportData.report_summary.split("\n") : ["ไม่มีข้อมูลรายละเอียด"];
            }
        } else {
            // Fallback for older records
            feedbackArray = reportData.report_summary ? reportData.report_summary.split("\n") : ["ไม่มีข้อมูลรายละเอียด"];
        }

        // Set Telemetry UI
        document.getElementById('mdTelSafety').innerText = telSafety;
        document.getElementById('mdTelSafety').style.color = telSafety > 0 ? '#ef4444' : '#10b981';
        
        document.getElementById('mdTelSpill').innerText = telSpill;
        document.getElementById('mdTelSpill').style.color = telSpill > 0 ? '#f59e0b' : '#38bdf8';
        
        const expCard = document.getElementById('mdTelExpCard');
        if (telExp) {
            document.getElementById('mdTelExp').innerText = "YES";
            document.getElementById('mdTelExp').style.color = "#ef4444";
            expCard.style.borderColor = "rgba(239, 68, 68, 0.5)";
            expCard.style.background = "rgba(239, 68, 68, 0.1)";
        } else {
            document.getElementById('mdTelExp').innerText = "NO";
            document.getElementById('mdTelExp').style.color = "#10b981";
            expCard.style.borderColor = "#334155";
            expCard.style.background = "rgba(255,255,255,0.02)";
        }

        // 4. Render Feedback Checklist
        const listEl = document.getElementById('mdFeedbackList');
        listEl.innerHTML = '';
        
        feedbackArray.forEach(line => {
            if (line.trim() === '') return;
            
            let li = document.createElement('li');
            let cssClass = 'fb-good';
            let icon = 'ℹ️';

            // Check content to assign color
            if (line.includes('✅') || line.includes('🌟')) {
                cssClass = 'fb-good';
                icon = line.includes('🌟') ? '🌟' : '✅';
                line = line.replace('✅', '').replace('🌟', '').trim(); // Remove raw icon to use styled one
            } 
            else if (line.includes('❌') || line.includes('💥') || line.includes('💧') || line.includes('⚖️') || line.includes('🥄') || line.includes('⚗️')) {
                cssClass = 'fb-bad';
                if(line.includes('❌')) icon = '❌';
                else if(line.includes('💥')) icon = '💥';
                else if(line.includes('💧')) icon = '💧';
                else if(line.includes('⚖️')) icon = '⚖️';
                else if(line.includes('🥄')) icon = '🥄';
                else if(line.includes('⚗️')) icon = '⚗️';
                
                // Strip raw icons
                line = line.replace('❌','').replace('💥','').replace('💧','').replace('⚖️','').replace('🥄','').replace('⚗️','').trim();
            }

            li.className = `feedback-item ${cssClass}`;
            li.innerHTML = `<span class="fb-icon">${icon}</span> <span>${line}</span>`;
            listEl.appendChild(li);
        });

        // 5. Show Modal
        backdrop.style.display = 'flex';
        setTimeout(() => {
            backdrop.classList.add('show');
            modalBox.classList.add('show');
        }, 10);
    }

    function closeReportModal() {
        backdrop.classList.remove('show');
        modalBox.classList.remove('show');
        setTimeout(() => {
            backdrop.style.display = 'none';
        }, 300);
    }

    // Close on backdrop click
    backdrop.addEventListener('click', (e) => {
        if (e.target === backdrop) closeReportModal();
    });
</script>

<?php require_once 'footer.php'; ?>