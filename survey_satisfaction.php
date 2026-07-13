<?php
/**
 * survey_satisfaction.php - แบบประเมินความพึงพอใจ (Phase 10: Satisfaction Survey Tool)
 * สอดคล้องกับบทที่ 3: "เครื่องมือที่ใช้ในการเก็บรวบรวมข้อมูล"
 * ระบบบริหารจัดการห้องเรียน โรงเรียนบ้านคาวิทยา
 */
require_once 'config.php';
require_once 'db.php';
require_once 'security.php';
require_once 'auth.php';
require_once 'logger.php';

requireRole(['student']);
$page_title = "แบบประเมินความพึงพอใจ (Satisfaction Survey)";

$student_id = $_SESSION['user_id'];
$already_submitted = false;
$message = '';
$msg_type = '';

try {
    // 1. ตรวจสอบว่าเคยทำแบบประเมินไปแล้วหรือไม่
    $stmt_check = $pdo->prepare("SELECT id FROM research_surveys WHERE student_id = ? LIMIT 1");
    $stmt_check->execute([$student_id]);
    if ($stmt_check->fetch()) {
        $already_submitted = true;
    }

    // 2. รับข้อมูลการประเมิน
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$already_submitted) {
        verify_csrf_token($_POST['csrf_token'] ?? '');
        
        $q1 = intval($_POST['q1_ui'] ?? 0);
        $q2 = intval($_POST['q2_ux'] ?? 0);
        $q3 = intval($_POST['q3_realism'] ?? 0);
        $q4 = intval($_POST['q4_knowledge'] ?? 0);
        $q5 = intval($_POST['q5_gamification'] ?? 0);
        $suggestion = trim($_POST['suggestion'] ?? '');

        // Validation
        if ($q1 == 0 || $q2 == 0 || $q3 == 0 || $q4 == 0 || $q5 == 0) {
            $message = "❌ กรุณาตอบแบบประเมินให้ครบทุกข้อ";
            $msg_type = "error";
        } else {
            $sql_insert = "INSERT INTO research_surveys (student_id, q1_ui, q2_ux, q3_realism, q4_knowledge, q5_gamification, suggestion, created_at) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt_ins = $pdo->prepare($sql_insert);
            
            if ($stmt_ins->execute([$student_id, $q1, $q2, $q3, $q4, $q5, $suggestion])) {
                systemLog($student_id, 'SUBMIT_SURVEY', "Submitted satisfaction survey.");
                $already_submitted = true;
                $message = "✅ ขอขอบคุณที่ร่วมประเมินความพึงพอใจ ข้อมูลของคุณเป็นประโยชน์ต่องานวิจัยมากครับ!";
                $msg_type = "success";
            }
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
    .survey-container { max-width: 800px; margin: 40px auto; padding: 0 20px; }
    
    .survey-header { background: linear-gradient(135deg, #8b5cf6, #6d28d9); color: white; padding: 40px; border-radius: 20px; box-shadow: 0 15px 30px rgba(0,0,0,0.1); margin-bottom: 30px; text-align: center;}
    .survey-header h1 { margin: 0 0 10px 0; font-size: 2rem;}
    .survey-header p { margin: 0; color: #e2e8f0; font-size: 1.1rem; line-height: 1.5;}

    .success-card { background: white; border-radius: 20px; padding: 50px; text-align: center; border: 1px solid #e2e8f0; box-shadow: 0 15px 40px rgba(0,0,0,0.05);}
    .success-card .icon { font-size: 5rem; margin-bottom: 20px; display: block;}

    .btn-nav { background: #3b82f6; color: white; padding: 15px 30px; border: none; border-radius: 12px; font-weight: bold; font-size: 1.1rem; text-decoration: none; display: inline-block; transition: 0.3s; margin-top: 20px;}
    .btn-nav:hover { background: #2563eb; transform: translateY(-3px);}

    /* Survey Form */
    .question-card { background: white; border-radius: 16px; padding: 30px; border: 1px solid #e2e8f0; box-shadow: 0 4px 15px rgba(0,0,0,0.02); margin-bottom: 20px;}
    .q-title { font-size: 1.15rem; color: #1e293b; font-weight: 600; margin-bottom: 20px; border-bottom: 1px dashed #cbd5e1; padding-bottom: 15px;}
    
    .rating-group { display: flex; justify-content: space-between; gap: 10px;}
    .rating-item { flex: 1; text-align: center; }
    
    /* ซ่อน Radio button แท้ๆ แล้วทำปุ่มจำลองแทน */
    .rating-item input[type="radio"] { display: none; }
    .rating-label { display: block; background: #f1f5f9; border: 2px solid #e2e8f0; border-radius: 12px; padding: 15px 10px; cursor: pointer; transition: 0.2s; font-weight: bold; color: #64748b;}
    .rating-item:hover .rating-label { background: #e0e7ff; border-color: #a5b4fc;}
    
    /* สีของแต่ละระดับเมื่อถูกเลือก */
    .rating-item input[type="radio"]:checked + .rating-label { color: white; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.1);}
    input[value="5"]:checked + .rating-label { background: #10b981; border-color: #059669; } /* มากที่สุด */
    input[value="4"]:checked + .rating-label { background: #3b82f6; border-color: #2563eb; } /* มาก */
    input[value="3"]:checked + .rating-label { background: #f59e0b; border-color: #d97706; } /* ปานกลาง */
    input[value="2"]:checked + .rating-label { background: #f97316; border-color: #ea580c; } /* น้อย */
    input[value="1"]:checked + .rating-label { background: #ef4444; border-color: #dc2626; } /* น้อยที่สุด */

    .level-desc { display: block; font-size: 0.8rem; margin-top: 5px; font-weight: normal; opacity: 0.9;}

    textarea.form-control { width: 100%; padding: 15px; border: 1px solid #cbd5e1; border-radius: 12px; font-family: inherit; font-size: 1rem; box-sizing: border-box; min-height: 120px; resize: vertical; background: #f8fafc;}
    textarea.form-control:focus { outline: none; border-color: #8b5cf6; background: white;}

    .btn-submit { background: linear-gradient(135deg, #8b5cf6, #6d28d9); color: white; padding: 20px; border: none; border-radius: 12px; font-weight: bold; font-size: 1.2rem; width: 100%; cursor: pointer; box-shadow: 0 10px 25px rgba(139, 92, 246, 0.4); transition: 0.3s; margin-top: 20px;}
    .btn-submit:hover { transform: translateY(-3px); box-shadow: 0 15px 35px rgba(139, 92, 246, 0.5); }

    .alert-msg { padding: 15px; border-radius: 10px; margin-bottom: 20px; font-weight: bold; font-size: 1.1rem; text-align: center; background: #fee2e2; color: #991b1b;}

    @media (max-width: 768px) {
        .rating-group { flex-direction: column; gap: 8px;}
        .rating-label { display: flex; justify-content: space-between; align-items: center; padding: 12px 20px;}
        .level-desc { margin-top: 0; }
    }
</style>

<div class="survey-container">
    <div style="margin-bottom: 20px;">
        <a href="dashboard_student.php" style="color: #64748b; font-weight: bold; text-decoration: none;">⬅ กลับหน้าหลัก</a>
    </div>

    <div class="survey-header">
        <h1>📊 แบบประเมินความพึงพอใจ</h1>
        <p>การพัฒนาระบบบริหารจัดการอัจฉริยะร่วมกับแพลตฟอร์มการเรียนรู้ด้วยแนวคิดเกมมิฟิเคชัน รายวิชาเคมี</p>
    </div>

    <?php if ($message && $msg_type === 'error'): ?>
        <div class="alert-msg"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($already_submitted): ?>
        <div class="success-card">
            <span class="icon">💖</span>
            <h2 style="color: #0f172a; margin-top: 0;">ขอบพระคุณอย่างยิ่งสำหรับการประเมิน</h2>
            <p style="color: #64748b; margin-bottom: 30px; font-size: 1.1rem;">ข้อมูลของคุณได้ถูกบันทึกลงในฐานข้อมูลเพื่อนำไปวิเคราะห์ในบทที่ 4 เรียบร้อยแล้วครับ</p>
            <a href="dashboard_student.php" class="btn-nav">🏠 กลับสู่ศูนย์บัญชาการ</a>
        </div>
    <?php else: ?>
        
        <div style="background: #eff6ff; border-left: 4px solid #3b82f6; padding: 15px 20px; border-radius: 0 12px 12px 0; margin-bottom: 25px; color: #1e40af; line-height: 1.6;">
            <strong>คำชี้แจง:</strong> โปรดเลือกคะแนนที่ตรงกับระดับความพึงพอใจของท่านมากที่สุด<br>
            (5 = มากที่สุด, 4 = มาก, 3 = ปานกลาง, 2 = น้อย, 1 = น้อยที่สุด)
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">

            <?php 
            $questions = [
                'q1_ui' => '1. ความสวยงามและความน่าสนใจของส่วนติดต่อผู้ใช้งาน (UI)',
                'q2_ux' => '2. ความสะดวกและความง่ายในการใช้งานระบบ (UX)',
                'q3_realism' => '3. ความสมจริงของห้องปฏิบัติการเคมีเสมือนจริงแบบ 3 มิติ',
                'q4_knowledge' => '4. ความรู้และความเข้าใจในปฏิกิริยาเคมีที่ได้รับจากระบบ',
                'q5_gamification' => '5. การมีระดับชั้น (Level) และเหรียญตรา (Badges) ช่วยกระตุ้นให้อยากเรียนรู้มากขึ้น'
            ];

            foreach ($questions as $name => $text): 
            ?>
            <div class="question-card">
                <div class="q-title"><?= $text ?></div>
                <div class="rating-group">
                    <?php 
                    $levels = [5 => 'มากที่สุด', 4 => 'มาก', 3 => 'ปานกลาง', 2 => 'น้อย', 1 => 'น้อยที่สุด'];
                    foreach ($levels as $val => $desc): 
                    ?>
                        <div class="rating-item">
                            <input type="radio" name="<?= $name ?>" id="<?= $name ?>_<?= $val ?>" value="<?= $val ?>" required>
                            <label class="rating-label" for="<?= $name ?>_<?= $val ?>">
                                <?= $val ?>
                                <span class="level-desc"><?= $desc ?></span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <div class="question-card">
                <div class="q-title">6. ข้อเสนอแนะเพิ่มเติมเพื่อการพัฒนาระบบ (ถ้ามี)</div>
                <textarea name="suggestion" class="form-control" placeholder="พิมพ์ข้อเสนอแนะของคุณที่นี่..."></textarea>
            </div>

            <button type="submit" class="btn-submit">💾 บันทึกผลการประเมินความพึงพอใจ</button>
        </form>

    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>