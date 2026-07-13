<?php
/**
 * test_pre_post.php - ระบบแบบทดสอบก่อนเรียนและหลังเรียน (Phase 10: Research Exam System)
 * สอดคล้องกับบทที่ 3: "การเก็บรวบรวมข้อมูลและทดสอบสมมติฐาน"
 * ระบบบริหารจัดการห้องเรียน โรงเรียนบ้านคาวิทยา
 */
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';
require_once 'logger.php';

requireRole(['student']);
$page_title = "แบบทดสอบวิชาการ (Research Examination)";

$student_id = $_SESSION['user_id'];
$exam_type = isset($_GET['type']) && $_GET['type'] === 'post_test' ? 'post_test' : 'pre_test';

$message = '';
$msg_type = '';
$exam_data = null;
$questions = [];
$already_taken = false;
$past_score = 0;
$past_total = 0;

try {
    // 1. ตรวจสอบว่านักเรียนเคยทำแบบทดสอบประเภทนี้ไปแล้วหรือไม่ (O1 หรือ O2)
    $stmt_check = $pdo->prepare("SELECT score, total_items FROM research_results WHERE student_id = ? AND exam_type = ? LIMIT 1");
    $stmt_check->execute([$student_id, $exam_type]);
    $result = $stmt_check->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        $already_taken = true;
        $past_score = $result['score'];
        $past_total = $result['total_items'];
    } else {
        // 2. ดึงข้อมูลหัวข้อสอบ
        $stmt_exam = $pdo->prepare("SELECT id, title FROM research_exams WHERE exam_type = ? AND is_active = 1 LIMIT 1");
        $stmt_exam->execute([$exam_type]);
        $exam_data = $stmt_exam->fetch(PDO::FETCH_ASSOC);

        if ($exam_data) {
            // 3. ดึงข้อสอบทั้งหมด (พร้อมสลับข้อ Random เพื่อป้องกันการลอก)
            $stmt_q = $pdo->prepare("SELECT * FROM research_questions WHERE exam_id = ? ORDER BY RAND()");
            $stmt_q->execute([$exam_data['id']]);
            $questions = $stmt_q->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // 4. ประมวลผลเมื่อนักเรียนกดส่งข้อสอบ (Auto-Grading)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$already_taken) {
        verify_csrf_token($_POST['csrf_token'] ?? '');
        
        $answers = $_POST['answers'] ?? []; // Array ของ [question_id => answer]
        $score = 0;
        $total_items = count($questions);

        // ตรวจคำตอบเทียบกับฐานข้อมูลทีละข้อ
        foreach ($questions as $q) {
            $q_id = $q['id'];
            $correct_ans = strtoupper($q['correct_opt']);
            $student_ans = isset($answers[$q_id]) ? strtoupper(trim($answers[$q_id])) : '';

            if ($student_ans === $correct_ans) {
                $score++;
            }
        }

        // บันทึกผลคะแนนลงฐานข้อมูล
        $stmt_save = $pdo->prepare("INSERT INTO research_results (student_id, exam_type, score, total_items, created_at) VALUES (?, ?, ?, ?, NOW())");
        if ($stmt_save->execute([$student_id, $exam_type, $score, $total_items])) {
            systemLog($student_id, 'SUBMIT_EXAM', "Submitted {$exam_type}. Score: {$score}/{$total_items}");
            
            $already_taken = true;
            $past_score = $score;
            $past_total = $total_items;
            
            $message = "✅ ส่งกระดาษคำตอบสำเร็จ! คุณทำแบบทดสอบเสร็จเรียบร้อย";
            $msg_type = "success";
        }
    }

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

require_once 'header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
    body { background-color: #f8fafc; font-family: 'Prompt', sans-serif; }
    .exam-container { max-width: 900px; margin: 40px auto; padding: 0 20px; }
    
    .exam-header { background: linear-gradient(135deg, #1e293b, #0f172a); color: white; padding: 40px; border-radius: 20px; box-shadow: 0 15px 30px rgba(0,0,0,0.1); margin-bottom: 30px; text-align: center;}
    .exam-header h1 { margin: 0 0 10px 0; font-size: 2.2rem; color: #38bdf8;}
    .exam-header p { margin: 0; color: #cbd5e1; font-size: 1.1rem;}

    .result-card { background: white; border-radius: 20px; padding: 50px; text-align: center; border: 1px solid #e2e8f0; box-shadow: 0 15px 40px rgba(0,0,0,0.05); animation: slideUp 0.5s ease;}
    .score-circle { width: 150px; height: 150px; border-radius: 50%; background: #eff6ff; border: 5px solid #3b82f6; display: flex; flex-direction: column; justify-content: center; align-items: center; margin: 0 auto 20px auto; color: #1e40af;}
    .score-circle span { font-size: 3rem; font-weight: bold; line-height: 1;}
    .score-circle small { font-size: 1rem; color: #64748b; font-weight: bold;}

    .btn-nav { background: #3b82f6; color: white; padding: 15px 30px; border: none; border-radius: 12px; font-weight: bold; font-size: 1.1rem; text-decoration: none; display: inline-block; transition: 0.3s; margin-top: 20px;}
    .btn-nav:hover { background: #2563eb; transform: translateY(-3px); box-shadow: 0 10px 20px rgba(37,99,235,0.3);}

    /* Question Styles */
    .question-card { background: white; border-radius: 16px; padding: 30px; border: 1px solid #e2e8f0; box-shadow: 0 5px 15px rgba(0,0,0,0.02); margin-bottom: 25px; transition: 0.3s;}
    .question-card:hover { border-color: #cbd5e1; box-shadow: 0 10px 25px rgba(0,0,0,0.05);}
    .q-number { font-weight: bold; color: #3b82f6; font-size: 1.1rem; margin-bottom: 15px; display: block; border-bottom: 1px dashed #e2e8f0; padding-bottom: 10px;}
    .q-text { font-size: 1.2rem; color: #0f172a; margin-bottom: 20px; font-weight: 600; line-height: 1.5;}
    
    .options-grid { display: flex; flex-direction: column; gap: 12px; }
    .opt-label { display: flex; align-items: center; padding: 15px 20px; border: 2px solid #e2e8f0; border-radius: 10px; cursor: pointer; transition: 0.2s; font-size: 1.05rem; color: #334155; font-weight: 500;}
    .opt-label:hover { background: #f8fafc; border-color: #cbd5e1; }
    .opt-label input[type="radio"] { margin-right: 15px; transform: scale(1.5); accent-color: #3b82f6;}
    
    /* Checked state style using adjacent sibling selector is tricky with inline HTML, so we rely on accent-color */
    
    .btn-submit { background: linear-gradient(135deg, #10b981, #059669); color: white; padding: 20px; border: none; border-radius: 12px; font-weight: bold; font-size: 1.3rem; width: 100%; cursor: pointer; box-shadow: 0 10px 25px rgba(16, 185, 129, 0.4); transition: 0.3s; margin-top: 20px;}
    .btn-submit:hover { transform: translateY(-3px); box-shadow: 0 15px 35px rgba(16, 185, 129, 0.5); }

    .alert-msg { padding: 15px; border-radius: 10px; margin-bottom: 20px; font-weight: bold; font-size: 1.1rem; text-align: center; background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;}
    
    @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
</style>

<div class="exam-container">
    <div style="margin-bottom: 20px;">
        <a href="dashboard_student.php" style="color: #64748b; font-weight: bold; text-decoration: none;">⬅ กลับหน้าหลัก</a>
    </div>

    <div class="exam-header">
        <h1>📝 <?= $exam_type === 'pre_test' ? 'แบบทดสอบก่อนเรียน (Pre-test)' : 'แบบทดสอบหลังเรียน (Post-test)' ?></h1>
        <p>เครื่องมือวิจัยประเมินผลสัมฤทธิ์ทางการเรียน</p>
    </div>

    <?php if ($message): ?>
        <div class="alert-msg"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($already_taken): ?>
        <div class="result-card">
            <h2 style="color: #0f172a; margin-top: 0;">🎉 บันทึกผลการทดสอบเรียบร้อยแล้ว</h2>
            <p style="color: #64748b; margin-bottom: 30px;">คุณได้ทำแบบทดสอบฉบับนี้ไปแล้ว ระบบได้บันทึกคะแนนของคุณลงในฐานข้อมูลงานวิจัย</p>
            
            <div class="score-circle">
                <span><?= $past_score ?></span>
                <small>จาก <?= $past_total ?> คะแนน</small>
            </div>
            
            <?php if ($exam_type === 'pre_test'): ?>
                <p style="color: #10b981; font-weight: bold;">🔓 ปลดล็อกห้องปฏิบัติการเสมือนจริงแล้ว!</p>
                <a href="lab_realistic.php" class="btn-nav">🚀 เข้าสู่ Virtual Lab</a>
            <?php else: ?>
                <p style="color: #10b981; font-weight: bold;">🏁 ขอบคุณที่เข้าร่วมการประเมินผลการเรียนรู้</p>
                <a href="survey_satisfaction.php" class="btn-nav" style="background:#8b5cf6;">📋 ทำแบบประเมินความพึงพอใจ</a>
            <?php endif; ?>
        </div>

    <?php elseif (empty($questions)): ?>
        <div class="result-card">
            <h2>⏳ ยังไม่มีแบบทดสอบในระบบ</h2>
            <p>ครูผู้สอนยังไม่ได้เปิดใช้งานแบบทดสอบชุดนี้ กรุณารอหรือติดต่อครูผู้สอน</p>
            <a href="dashboard_student.php" class="btn-nav">กลับหน้าหลัก</a>
        </div>

    <?php else: ?>
        <form method="POST" id="examForm" onsubmit="return confirmSubmit()">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            
            <?php foreach ($questions as $index => $q): ?>
                <div class="question-card">
                    <span class="q-number">ข้อที่ <?= $index + 1 ?> / <?= count($questions) ?></span>
                    <div class="q-text"><?= nl2br(htmlspecialchars($q['question_text'])) ?></div>
                    
                    <div class="options-grid">
                        <label class="opt-label">
                            <input type="radio" name="answers[<?= $q['id'] ?>]" value="A" required>
                            <span>ก. <?= htmlspecialchars($q['opt_a']) ?></span>
                        </label>
                        <label class="opt-label">
                            <input type="radio" name="answers[<?= $q['id'] ?>]" value="B" required>
                            <span>ข. <?= htmlspecialchars($q['opt_b']) ?></span>
                        </label>
                        <label class="opt-label">
                            <input type="radio" name="answers[<?= $q['id'] ?>]" value="C" required>
                            <span>ค. <?= htmlspecialchars($q['opt_c']) ?></span>
                        </label>
                        <label class="opt-label">
                            <input type="radio" name="answers[<?= $q['id'] ?>]" value="D" required>
                            <span>ง. <?= htmlspecialchars($q['opt_d']) ?></span>
                        </label>
                    </div>
                </div>
            <?php endforeach; ?>

            <div style="background: #fef3c7; border: 1px solid #fde68a; padding: 20px; border-radius: 12px; color: #92400e; font-weight: bold; text-align: center;">
                ⚠️ กรุณาตรวจสอบคำตอบให้ครบทุกข้อก่อนกดส่ง เพราะไม่สามารถทำซ้ำได้ (One-time Attempt)
            </div>

            <button type="submit" class="btn-submit" id="btnSubmit">📤 ส่งกระดาษคำตอบ</button>
        </form>

        <script>
            function confirmSubmit() {
                const isConfirmed = confirm("คุณแน่ใจหรือไม่ว่าต้องการส่งกระดาษคำตอบ?\n\n(หากส่งแล้วจะไม่สามารถแก้ไขได้)");
                if (isConfirmed) {
                    const btn = document.getElementById('btnSubmit');
                    btn.innerHTML = '⏳ กำลังตรวจคำตอบ...';
                    btn.disabled = true;
                    btn.style.background = '#94a3b8';
                    return true;
                }
                return false;
            }
        </script>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>