<?php
// mix.php - ห้องแล็บเสมือนจริง (Virtual Lab) พร้อมระบบอุปกรณ์ความปลอดภัย
require_once __DIR__ . '/env_loader.php';
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';
require_once 'logger.php';

requireLogin(); // ทุก Role เข้าดูได้ แต่นักเรียนจะได้คะแนน

$page_title = "ห้องทดลองเคมี (Virtual Lab)";
$student_id = $_SESSION['user_id'];
$csrf = generate_csrf_token();

// ดึงสารเคมีทั้งหมด
$chemicals = [];
$c_res = $pdo->query("SELECT * FROM chemicals ORDER BY name ASC");
while ($row = $c_res->fetch()) {
    $chemicals[] = $row;
}

$reaction_result = null;

// เมื่อมีการกด "ผสมสารเคมี"
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $chem1_id = intval($_POST['chem1'] ?? 0);
    $chem2_id = intval($_POST['chem2'] ?? 0);
    
    // ตรวจสอบสถานะการใส่อุปกรณ์เซฟตี้ (Checkbox)
    $wear_goggles = isset($_POST['wear_goggles']) ? true : false;
    $wear_gloves = isset($_POST['wear_gloves']) ? true : false;

    if ($chem1_id > 0 && $chem2_id > 0) {
        
        // ค้นหาปฏิกิริยาในตาราง reactions (ลองสลับตำแหน่ง 1-2 เผื่อเด็กใส่สลับกัน)
        $stmt = $pdo->prepare("
            SELECT * FROM reactions 
            WHERE (chem1_id = ? AND chem2_id = ?) 
               OR (chem1_id = ? AND chem2_id = ?)
            LIMIT 1
        ");
        $stmt->execute([$chem1_id, $chem2_id, $chem2_id, $chem1_id]);
        $stmt->execute();
        $res = $stmt;

        if ($res->rowCount() > 0) {
            $reaction_result = $res->fetch();
            
            // --- ระบบ SAFETY CHECK ---
            $safety_failed = false;
            $damage_msg = "";

            // ถ้าสารระเบิด (is_explosive = 1) และไม่ใส่แว่น
            if ($reaction_result['is_explosive'] == 1 && !$wear_goggles) {
                $safety_failed = true;
                $damage_msg .= "💥 เกิดการระเบิด! คุณไม่ได้สวมแว่นตานิรภัย ดวงตาของคุณได้รับอันตราย! ";
            }
            // ถ้ามีความร้อนสูง (heat > 50) หรือเป็นพิษ และไม่ใส่ถุงมือ
            if (($reaction_result['heat_level'] > 50 || $reaction_result['toxicity_bonus'] > 30) && !$wear_gloves) {
                $safety_failed = true;
                $damage_msg .= "🔥 สารมีความร้อน/เป็นพิษสูง! คุณไม่ได้สวมถุงมือ มือของคุณได้รับบาดเจ็บ! ";
            }

            if ($safety_failed) {
                $reaction_result['safety_warning'] = $damage_msg;
                $reaction_result['status'] = 'danger';
                systemLog($student_id, 'LAB_ACCIDENT', "Accident with Chem1:$chem1_id, Chem2:$chem2_id. No safety gear.");
            } else {
                $reaction_result['status'] = 'success';
                systemLog($student_id, 'LAB_SUCCESS', "Successfully mixed Chem1:$chem1_id, Chem2:$chem2_id safely.");
            }

        } else {
            // กรณีไม่เกิดปฏิกิริยา
            $reaction_result = [
                'status' => 'neutral',
                'product_name' => 'ไม่มีปฏิกิริยาเกิดขึ้น (No Reaction)',
                'result_color' => '#e2e8f0',
                'result_precipitate' => 'ไม่มี',
                'result_gas' => 'ไม่มี'
            ];
            systemLog($student_id, 'LAB_MIX', "Mixed Chem1:$chem1_id, Chem2:$chem2_id. No reaction.");
        }
        
    }
}

require_once 'header.php';
?>

<link rel="stylesheet" href="css/lab_styles.css">

<style>
    /* สไตล์เฉพาะสำหรับหน้า Lab */
    .lab-container { display: flex; gap: 30px; flex-wrap: wrap; }
    .lab-panel { flex: 1; min-width: 300px; background: white; padding: 25px; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
    
    .beaker-container { height: 250px; border: 3px solid #cbd5e1; border-top: none; border-bottom-left-radius: 30px; border-bottom-right-radius: 30px; position: relative; overflow: hidden; background: rgba(255,255,255,0.5); margin: 20px auto; width: 180px; box-shadow: inset 0 -10px 20px rgba(0,0,0,0.05); }
    .liquid { position: absolute; bottom: 0; width: 100%; height: 10%; background: #e2e8f0; transition: height 2s ease-in-out, background-color 2s ease-in-out; }
    
    .safety-gear { background: #f8fafc; padding: 15px; border-radius: 10px; border: 2px dashed #94a3b8; margin-bottom: 20px; }
    .safety-gear label { cursor: pointer; display: flex; align-items: center; gap: 10px; font-size: 1.1rem; }
    .safety-gear input[type="checkbox"] { width: 20px; height: 20px; cursor: pointer; }

    .result-box { margin-top: 20px; padding: 20px; border-radius: 12px; display: none; }
    .result-box.success { background: #dcfce7; border: 2px solid #22c55e; display: block; }
    .result-box.danger { background: #fee2e2; border: 2px solid #ef4444; display: block; animation: shake 0.5s; }
    .result-box.neutral { background: #f1f5f9; border: 2px solid #cbd5e1; display: block; }

    @keyframes shake { 0% { transform: translateX(0); } 25% { transform: translateX(-10px); } 50% { transform: translateX(10px); } 75% { transform: translateX(-10px); } 100% { transform: translateX(0); } }
    
    /* เอฟเฟกต์แก๊สลอย */
    .bubble { position: absolute; bottom: 0; background: rgba(255,255,255,0.6); border-radius: 50%; animation: rise 2s infinite ease-in; opacity: 0; }
    @keyframes rise { 0% { bottom: 0; transform: translateX(0); opacity: 1; } 100% { bottom: 100%; transform: translateX(-20px); opacity: 0; } }
</style>

<div class="lab-container">
    
    <div class="lab-panel">
        <h2 style="color: #0f172a;">🧪 โต๊ะผสมสารเคมี</h2>
        <p style="color: #64748b;">เลือกสารเคมี 2 ชนิด และอย่าลืมสวมอุปกรณ์ความปลอดภัย!</p>

        <form method="post" id="labForm">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            
            <div class="safety-gear">
                <h4 style="margin-top:0; color:#475569;">🛡️ อุปกรณ์ความปลอดภัย (Safety Gear)</h4>
                <label>
                    <input type="checkbox" name="wear_goggles" id="wear_goggles"> 🥽 สวมแว่นตานิรภัย (Goggles)
                </label>
                <label>
                    <input type="checkbox" name="wear_gloves" id="wear_gloves"> 🧤 สวมถุงมือกันความร้อน (Gloves)
                </label>
            </div>

            <label>หลอดทดลองที่ 1 (สารตั้งต้น A)</label>
            <select name="chem1" required>
                <option value="">-- เลือกสารเคมี --</option>
                <?php foreach ($chemicals as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <label>หลอดทดลองที่ 2 (สารตั้งต้น B)</label>
            <select name="chem2" required>
                <option value="">-- เลือกสารเคมี --</option>
                <?php foreach ($chemicals as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn-primary" style="width: 100%; background: #3b82f6; font-size: 1.2rem; padding: 15px;">
                ⚗️ เทสารผสมกัน (Mix!)
            </button>
        </form>
    </div>

    <div class="lab-panel" style="text-align: center;">
        <h2>🔬 ผลลัพธ์การทดลอง</h2>
        
        <div class="beaker-container" id="beaker">
            <div class="liquid" id="liquid"></div>
        </div>

        <?php if ($reaction_result): ?>
            
            <div class="result-box <?= $reaction_result['status'] ?>" id="resultBox">
                <?php if (isset($reaction_result['safety_warning'])): ?>
                    <h3 style="color: #b91c1c; margin-top:0;">⚠️ เกิดอุบัติเหตุในห้องแล็บ!</h3>
                    <p style="font-weight: bold;"><?= h($reaction_result['safety_warning']) ?></p>
                <?php else: ?>
                    <h3 style="color: #15803d; margin-top:0;">✅ การทดลองสำเร็จอย่างปลอดภัย</h3>
                <?php endif; ?>

                <div style="text-align: left; background: rgba(255,255,255,0.5); padding: 15px; border-radius: 8px; margin-top: 15px;">
                    <p><strong>ผลิตภัณฑ์ที่ได้:</strong> <?= h($reaction_result['product_name']) ?></p>
                    <p><strong>ตะกอน:</strong> <?= h($reaction_result['result_precipitate']) ?></p>
                    <p><strong>แก๊สที่เกิด:</strong> <?= h($reaction_result['result_gas']) ?></p>
                </div>

                <?php if ($reaction_result['status'] === 'success' || $reaction_result['status'] === 'neutral'): ?>
                    <form action="generate_lab_report.php" method="post" target="_blank" style="margin-top: 15px;">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="product_name" value="<?= h($reaction_result['product_name']) ?>">
                        <input type="hidden" name="precipitate" value="<?= h($reaction_result['result_precipitate']) ?>">
                        <input type="hidden" name="gas" value="<?= h($reaction_result['result_gas']) ?>">
                        <input type="hidden" name="color" value="<?= h($reaction_result['result_color']) ?>">
                        
                        <button type="submit" class="btn-primary" style="background: #10b981; width: 100%;">
                            📄 สร้างรายงานผลการทดลอง (PDF)
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <script>
                // สคริปต์ควบคุมแอนิเมชันของบีกเกอร์หลังจากกดผสมสาร
                window.onload = function() {
                    const liquid = document.getElementById('liquid');
                    const beaker = document.getElementById('beaker');
                    
                    // ปรับระดับน้ำและสี
                    setTimeout(() => {
                        liquid.style.height = "70%";
                        liquid.style.backgroundColor = "<?= h($reaction_result['result_color']) ?>";
                        
                        // ถ้ามีแก๊ส ให้สร้างฟองอากาศ
                        <?php if ($reaction_result['result_gas'] !== 'ไม่มีแก๊ส' && $reaction_result['result_gas'] !== 'ไม่มี'): ?>
                            for(let i=0; i<15; i++) {
                                let bubble = document.createElement('div');
                                bubble.className = 'bubble';
                                bubble.style.width = Math.random() * 15 + 5 + 'px';
                                bubble.style.height = bubble.style.width;
                                bubble.style.left = Math.random() * 80 + 10 + '%';
                                bubble.style.animationDuration = (Math.random() * 1 + 1) + 's';
                                bubble.style.animationDelay = Math.random() + 's';
                                beaker.appendChild(bubble);
                            }
                        <?php endif; ?>

                        // ถ้าเกิดการระเบิด (หน้าจอจะกระพริบแดง)
                        <?php if (isset($reaction_result['safety_warning'])): ?>
                            document.body.style.animation = "shake 0.5s";
                            setTimeout(() => { document.body.style.background = "#fee2e2"; }, 500);
                        <?php endif; ?>

                    }, 500); // ดีเลย์นิดนึงให้ดูเหมือนกำลังเท
                };
            </script>
        <?php else: ?>
            <p style="color: #94a3b8; margin-top: 50px;">บีกเกอร์ว่างเปล่า... กรุณาเลือกสารเคมีแล้วกดผสม</p>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'footer.php'; ?>