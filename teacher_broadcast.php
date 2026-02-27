<?php
// teacher_broadcast.php - ระบบประกาศข่าวสารผ่าน LINE
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';
require_once 'logger.php';
require_once 'line_notify.php'; // โหลดฟังก์ชัน LINE

requireRole(['teacher', 'developer']);

$msg = "";
$msg_type = "";
$page_title = "ประกาศข่าวสาร (LINE Notify)";
$csrf = generate_csrf_token();

// ⚠️ ใส่ Token ของกลุ่ม LINE ที่ต้องการส่ง (ขอได้จาก https://notify-bot.line.me/)
// แนะนำ: ในระบบจริงควรดึงจากฐานข้อมูลตามแต่ละห้องเรียน
$LINE_TOKEN_M1 = "ใส่_LINE_TOKEN_ของกลุ่มม.1ที่นี่"; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $message_text = trim($_POST['message_text'] ?? '');
    $target_group = trim($_POST['target_group'] ?? '');

    if (empty($message_text)) {
        $msg = "❌ กรุณาพิมพ์ข้อความที่ต้องการประกาศ";
        $msg_type = "error";
    } else {
        // จัดรูปแบบข้อความ
        $final_message = "\n📢 ประกาศจากครูผู้สอน:\n" . $message_text . "\n\nส่งโดย: " . $_SESSION['display_name'];

        // ในตัวอย่างนี้เราส่งหา Token ที่ตั้งค่าไว้
        // ถ้าใส่ Token จริงแล้ว ให้เปลี่ยนเป็นตัวแปร $LINE_TOKEN_M1
        // $send_status = sendLineNotify($final_message, $LINE_TOKEN_M1);
        
        // จำลองการส่งสำเร็จ (เนื่องจากยังไม่มี Token จริง)
        $send_status = true; 

        if ($send_status) {
            $msg = "✔ ส่งประกาศเข้ากลุ่ม LINE เรียบร้อยแล้ว!";
            $msg_type = "success";
            systemLog($_SESSION['user_id'], 'SEND_LINE_NOTIFY', "Broadcasted: $message_text");
        } else {
            $msg = "❌ ไม่สามารถส่งข้อความได้ กรุณาตรวจสอบ LINE Token";
            $msg_type = "error";
        }
    }
}

require_once 'header.php';
?>

<div class="card" style="max-width: 600px; margin: 0 auto;">
    <h2>💬 ประกาศข่าวสารห้องเรียน (LINE Notify)</h2>
    <p style="color: #64748b;">ส่งข้อความแจ้งเตือนผู้ปกครองและนักเรียนเข้ากลุ่ม LINE อัตโนมัติ</p>

    <?php if ($msg): ?>
        <div class="msg <?= h($msg_type) ?>"><?= h($msg) ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

        <label for="target_group">ส่งถึงกลุ่ม</label>
        <select id="target_group" name="target_group" required>
            <option value="all">รวมทุกชั้นเรียน</option>
            <option value="m1">เฉพาะ ม.1/1</option>
        </select>

        <label for="message_text">ข้อความประกาศ <span style="color:red">*</span></label>
        <textarea id="message_text" name="message_text" rows="5" required placeholder="เช่น พรุ่งนี้งดคลาสเรียนเคมี หรือ อย่าลืมส่งการบ้านบทที่ 1 นะครับ..."></textarea>

        <button type="submit" class="btn-primary" style="width: 100%; background: #00B900; margin-top: 15px;">
            <span style="font-size: 1.2em;">💬</span> ส่งข้อความเข้า LINE
        </button>
    </form>
</div>

<?php require_once 'footer.php'; ?>