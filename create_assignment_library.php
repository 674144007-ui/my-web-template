<?php
require_once 'auth.php';
requireRole(['teacher','developer']);
require_once 'db.php';

$msg = "";
$msg_type = "";

// ------------------------
// CSRF Token
// ------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ------------------------
// เมื่อกด POST
// ------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ตรวจ CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf) {
        http_response_code(403);
        exit("Invalid CSRF token");
    }

    $teacher_id  = $_SESSION['user_id'];
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($title === "") {
        $msg = "❌ กรุณากรอกชื่องาน";
        $msg_type = "error";
    } else {

        // ค่าเริ่มต้น
        $file_path = NULL;
        $file_type = NULL;

        // ------------------------
        // ตรวจการอัปโหลดไฟล์
        // ------------------------
        if (!empty($_FILES['file_upload']['name'])) {

            $allowedExtensions = ['pdf','doc','docx','ppt','pptx','jpg','jpeg','png'];
            $maxFileSize = 5 * 1024 * 1024; // 5 MB

            $originalName = $_FILES['file_upload']['name'];
            $tmpName      = $_FILES['file_upload']['tmp_name'];
            $fileSize     = $_FILES['file_upload']['size'];

            // เอาเฉพาะ extension (ไม่ใช้ชื่อไฟล์มาโดยตรง)
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            // ตรวจขนาดไฟล์
            if ($fileSize > $maxFileSize) {
                $msg = "❌ ไฟล์ใหญ่เกินกำหนด (สูงสุด 5 MB)";
                $msg_type = "error";
            }
            // ตรวจชนิดไฟล์
            elseif (!in_array($ext, $allowedExtensions)) {
                $msg = "❌ ไฟล์ชนิดนี้ไม่อนุญาต: .$ext";
                $msg_type = "error";
            }
            else {
                // สร้างชื่อใหม่แบบปลอดภัย
                $safeFileName = time() . "_" . bin2hex(random_bytes(8)) . ".$ext";

                // โฟลเดอร์ uploads ต้องไม่มีสิทธิ์ execute (ตั้งใน .htaccess)
                $targetPath = "uploads/" . $safeFileName;

                if (move_uploaded_file($tmpName, $targetPath)) {
                    $file_path = $targetPath;
                    $file_type = $ext;
                } else {
                    $msg = "❌ ไม่สามารถอัปโหลดไฟล์ได้";
                    $msg_type = "error";
                }
            }
        }

        // ------------------------
        // INSERT ลงฐานข้อมูล
        // ------------------------
        if ($msg === "") {
            $stmt = $conn->prepare("
                INSERT INTO assignment_library 
                (teacher_id, title, description, file_path, file_type)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("issss",
                $teacher_id,
                $title,
                $description,
                $file_path,
                $file_type
            );

            try {
                if ($stmt->execute()) {
                    $msg = "✔ เพิ่มงานเข้าคลังสำเร็จแล้ว!";
                    $msg_type = "success";
                } else {
                    $msg = "❌ เกิดข้อผิดพลาดในการบันทึกข้อมูล";
                    $msg_type = "error";
                }
            } catch (Exception $e) {
                error_log("assignment insert error: " . $e->getMessage());
                $msg = "❌ ระบบเกิดข้อผิดพลาด (โปรดดู error_log)";
                $msg_type = "error";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>เพิ่มงานเข้าคลัง</title>
<style>
body { font-family:system-ui; background:#fef3c7; padding:20px; }
.card {
    background:white; padding:20px; border-radius:16px;
    box-shadow:0 10px 25px rgba(0,0,0,0.15); max-width:600px; margin:0 auto;
}
input, textarea {
    width:100%; padding:12px; border-radius:10px; border:1px solid #ccc; margin:10px 0;
}
button {
    padding:12px 20px; background:#2563eb; color:white; border:none;
    border-radius:10px; cursor:pointer;
}
button:hover { background:#1d4ed8; }
.msg { padding:10px; border-radius:10px; margin-bottom:12px; }
.msg.success { background:#dcfce7; color:#166534; }
.msg.error { background:#fee2e2; color:#991b1b; }

.file-btn {
    display:inline-block;
    padding:10px 14px;
    background:#0ea5e9;
    color:white;
    border-radius:10px;
    cursor:pointer;
    margin-top:10px;
}
#fileInput{ display:none; }
</style>
</head>
<body>

<div class="card">
    <h2>📚 เพิ่มงานเข้าคลัง</h2>

    <?php if($msg): ?>
        <div class="msg <?= htmlspecialchars($msg_type) ?>">
            <?= htmlspecialchars($msg) ?>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">

        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

        <label>ชื่องาน</label>
        <input type="text" name="title" required>

        <label>รายละเอียดงาน</label>
        <textarea name="description" rows="4"></textarea>

        <label>ไฟล์แนบ (ไม่บังคับ)</label>

        <button type="button" class="file-btn"
            onclick="document.getElementById('fileInput').click();">
            📁 เลือกไฟล์จากเครื่อง
        </button>

        <input type="file" name="file_upload" id="fileInput">

        <span id="fileName" style="display:block;margin-top:8px;color:#555;">
            ยังไม่ได้เลือกไฟล์
        </span>

        <script>
            document.getElementById('fileInput').addEventListener('change',function(){
                if (this.files.length > 0) {
                    document.getElementById('fileName').innerText =
                        "ไฟล์ที่เลือก: " + this.files[0].name;
                }
            });
        </script>

        <button type="submit">บันทึกงานลงคลัง</button>

    </form>

    <br>
    <a href="assignment_library.php">📚 กลับไปคลังงาน</a>
</div>

</body>
</html>
