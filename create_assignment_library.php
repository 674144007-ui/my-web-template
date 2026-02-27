<?php
// create_assignment_library.php
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';

// บังคับสิทธิ์เฉพาะครูและผู้พัฒนา
requireRole(['teacher', 'developer']);

$msg = "";
$msg_type = "";
$page_title = "เพิ่มงานเข้าคลัง";

// สร้าง CSRF Token
$csrf = generate_csrf_token();

// ------------------------
// เมื่อฟอร์มถูก Submit
// ------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. ตรวจสอบ CSRF Token (ป้องกันการยิง Request จากเว็บอื่น)
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $teacher_id  = $_SESSION['user_id'];
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');

    // 2. ตรวจสอบค่าว่าง
    if (empty($title)) {
        $msg = "❌ กรุณากรอกชื่องาน";
        $msg_type = "error";
    } else {

        $file_path = NULL;
        $file_type = NULL;
        $upload_success = true; // Flag สำหรับเช็คสถานะการอัปโหลด

        // 3. จัดการระบบอัปโหลดไฟล์อย่างปลอดภัย
        if (isset($_FILES['file_upload']) && $_FILES['file_upload']['error'] !== UPLOAD_ERR_NO_FILE) {
            
            $tmpName  = $_FILES['file_upload']['tmp_name'];
            $fileSize = $_FILES['file_upload']['size'];
            $originalName = $_FILES['file_upload']['name'];
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            // 3.1 ตรวจสอบ Error จาก PHP Upload
            if ($_FILES['file_upload']['error'] !== UPLOAD_ERR_OK) {
                $msg = "❌ เกิดข้อผิดพลาดในการอัปโหลดไฟล์ (Error Code: " . $_FILES['file_upload']['error'] . ")";
                $msg_type = "error";
                $upload_success = false;
            }
            // 3.2 ตรวจสอบขนาดไฟล์
            elseif ($fileSize > MAX_UPLOAD_SIZE) {
                $msg = "❌ ไฟล์ใหญ่เกินกำหนด (สูงสุด 5 MB)";
                $msg_type = "error";
                $upload_success = false;
            }
            else {
                // 3.3 ตรวจสอบ MIME Type เชิงลึกจากเนื้อหาไฟล์จริง (MIME Sniffing)
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_file($finfo, $tmpName);
                finfo_close($finfo);

                // รายการ MIME Types ที่อนุญาต
                $allowedMimeTypes = [
                    'application/pdf' => 'pdf',
                    'application/msword' => 'doc',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                    'application/vnd.ms-powerpoint' => 'ppt',
                    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png'
                ];

                if (!array_key_exists($mime_type, $allowedMimeTypes)) {
                    $msg = "❌ ชนิดไฟล์ไม่อนุญาต (ตรวจพบเนื้อหาไฟล์เป็น: $mime_type)";
                    $msg_type = "error";
                    $upload_success = false;
                } else {
                    // 3.4 สร้างชื่อไฟล์ใหม่ ป้องกันการเดาชื่อและการรันสคริปต์ (Hash filename)
                    $safeFileName = date('Ymd_His') . "_" . bin2hex(random_bytes(8)) . "." . $allowedMimeTypes[$mime_type];
                    $targetPath = UPLOAD_DIR . $safeFileName;

                    // ย้ายไฟล์จาก Temp ไปยังโฟลเดอร์ Upload
                    if (move_uploaded_file($tmpName, $targetPath)) {
                        $file_path = "uploads/" . $safeFileName; // เก็บแค่ Relative path ลง DB
                        $file_type = $allowedMimeTypes[$mime_type];
                    } else {
                        $msg = "❌ ระบบไม่สามารถบันทึกไฟล์ลงเซิร์ฟเวอร์ได้";
                        $msg_type = "error";
                        $upload_success = false;
                    }
                }
            }
        }

        // 4. INSERT ข้อมูลลงฐานข้อมูล (ทำเมื่ออัปโหลดไฟล์ผ่าน หรือไม่มีการอัปโหลดไฟล์)
        if ($msg === "" && $upload_success) {
            $stmt = $conn->prepare("
                INSERT INTO assignment_library (teacher_id, title, description, file_path, file_type)
                VALUES (?, ?, ?, ?, ?)
            ");
            
            $stmt->bind_param("issss", $teacher_id, $title, $description, $file_path, $file_type);

            if ($stmt->execute()) {
                $msg = "✔ เพิ่มงานเข้าคลังสำเร็จแล้ว!";
                $msg_type = "success";
                
                // ล้างค่าฟอร์มหลังบันทึกเสร็จ
                $_POST = array(); 
            } else {
                error_log("Insert Assignment Error: " . $stmt->error);
                $msg = "❌ เกิดข้อผิดพลาดในการบันทึกข้อมูลลงฐานข้อมูล";
                $msg_type = "error";
            }
            $stmt->close();
        }
    }
}

// เรียกใช้ Header
require_once 'header.php';
?>

<div class="card" style="max-width: 600px; margin: 0 auto;">
    <h2>📚 เพิ่มงานเข้าคลัง</h2>
    <p style="color: #64748b;">สร้างชิ้นงานหรือเอกสารเพื่อมอบหมายให้นักเรียน</p>

    <?php if ($msg): ?>
        <div class="msg <?= h($msg_type) ?>">
            <?= h($msg) ?>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

        <label for="title">ชื่องาน <span style="color:red;">*</span></label>
        <input type="text" id="title" name="title" value="<?= h($_POST['title'] ?? '') ?>" required placeholder="เช่น ใบงานเคมีบทที่ 1">

        <label for="description">รายละเอียด / คำสั่ง</label>
        <textarea id="description" name="description" rows="5" placeholder="อธิบายสิ่งที่นักเรียนต้องทำ..."><?= h($_POST['description'] ?? '') ?></textarea>

        <label>ไฟล์แนบประกอบการสอน (ไม่บังคับ)</label>
        <div style="background: #f1f5f9; padding: 15px; border-radius: 10px; border: 1px dashed #cbd5e1; text-align: center;">
            <button type="button" class="btn-primary" style="background:#0ea5e9; margin-bottom: 10px;" onclick="document.getElementById('fileInput').click();">
                📁 เลือกไฟล์จากเครื่อง
            </button>
            <input type="file" name="file_upload" id="fileInput" style="display:none;" accept=".pdf,.doc,.docx,.ppt,.pptx,.jpg,.jpeg,.png">
            <span id="fileName" style="display:block; color:#64748b; font-size: 0.9em;">
                ยังไม่ได้เลือกไฟล์ (รองรับ PDF, Word, Powerpoint, JPG, PNG ขนาดไม่เกิน 5MB)
            </span>
        </div>

        <button type="submit" class="btn-primary" style="width: 100%; margin-top: 20px; font-size: 1.1rem; padding: 15px;">
            💾 บันทึกงานลงคลัง
        </button>
    </form>

    <div style="text-align: center; margin-top: 20px;">
        <a href="assignment_library.php" style="color: #475569; text-decoration: none; font-weight: bold;">
            ⬅️ กลับไปหน้าคลังงาน
        </a>
    </div>
</div>

<script>
    // สคริปต์สำหรับแสดงชื่อไฟล์เมื่อเลือกไฟล์เสร็จ
    document.getElementById('fileInput').addEventListener('change', function() {
        const fileSpan = document.getElementById('fileName');
        if (this.files.length > 0) {
            const file = this.files[0];
            const sizeKB = (file.size / 1024).toFixed(2);
            fileSpan.innerHTML = `<strong style="color:#0f172a;">${file.name}</strong> (${sizeKB} KB)`;
            fileSpan.style.color = "#10b981"; // สีเขียวเมื่อเลือกไฟล์แล้ว
        } else {
            fileSpan.innerText = "ยังไม่ได้เลือกไฟล์";
            fileSpan.style.color = "#64748b";
        }
    });
</script>

<?php
// เรียกใช้ Footer
require_once 'footer.php';
?>