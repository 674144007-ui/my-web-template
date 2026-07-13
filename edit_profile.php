<?php
/**
 * edit_profile.php — แก้ไขโปรไฟล์ส่วนตัว
 * ────────────────────────────────────────────────────────────
 * สิทธิ์:
 *   - student/teacher: แก้ไขข้อมูลตัวเองได้ (email, social, รูปโปรไฟล์)
 *   - developer: แก้ไขได้ทุก user ผ่าน ?user_id=xxx
 *   - ไม่มีปุ่มเปลี่ยนรหัสผ่านสำหรับ teacher/student
 * Security:
 *   - MIME Type ตรวจด้วย getimagesize() ไม่เชื่อ extension
 *   - Rename ไฟล์เป็น uid_{id}_{timestamp}.webp
 *   - Validate URL ด้วย filter_var
 *   - .htaccess ใน uploads/ บล็อก PHP execution อยู่แล้ว
 * ────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logger.php';

requireLogin();

$my_role  = $_SESSION['role'];
$my_id    = $_SESSION['user_id'];
$is_dev   = ($my_role === 'developer');
$csrf     = generate_csrf_token();

// ── ตรวจสอบว่าจะแก้ไข user ไหน ─────────────────────────────
$target_id = $my_id;
if ($is_dev && isset($_GET['user_id'])) {
    $target_id = (int)$_GET['user_id'];
}

// developer เท่านั้นที่แก้ไข user อื่นได้
if (!$is_dev && $target_id !== $my_id) {
    header('Location: edit_profile.php');
    exit;
}

$message   = '';
$msg_type  = '';

// ── โหลดข้อมูล user ─────────────────────────────────────────
$user    = null;
$profile = null;
try {
    $stmt = $pdo->prepare('
        SELECT u.id, u.username, u.display_name, r.role_key AS role
        FROM users u
        JOIN sys_roles r ON r.id = u.role_id
        WHERE u.id = ? AND u.is_deleted = 0
    ');
    $stmt->execute([$target_id]);
    $user = $stmt->fetch();

    if (!$user) {
        header('Location: dashboard_dev.php');
        exit;
    }

    // โหลด/สร้าง student_profiles row
    $stmt = $pdo->prepare('SELECT * FROM student_profiles WHERE user_id = ?');
    $stmt->execute([$target_id]);
    $profile = $stmt->fetch();

    if (!$profile) {
        // สร้าง profile row ถ้ายังไม่มี
        $pdo->prepare('INSERT IGNORE INTO student_profiles (user_id) VALUES (?)')
            ->execute([$target_id]);
        $profile = ['user_id' => $target_id, 'profile_picture' => null,
                    'email' => '', 'phone' => '', 'nickname' => '',
                    'line_id' => '', 'facebook_url' => '', 'tiktok_url' => '',
                    'bio' => '', 'avatar_key' => 'default'];
    }

} catch (PDOException $e) {
    error_log('[edit_profile] load: ' . $e->getMessage());
    die('เกิดข้อผิดพลาดในการโหลดข้อมูล');
}

$page_title = 'แก้ไขโปรไฟล์ — ' . h($user['display_name']);

// ── POST Handler ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $action = $_POST['action'] ?? 'update_info';

    // ════════════════════════════════════════════
    // ACTION 1: อัปเดตข้อมูลส่วนตัว
    // ════════════════════════════════════════════
    if ($action === 'update_info') {
        $nickname     = trim(substr($_POST['nickname']     ?? '', 0, 50));
        $email        = trim(substr($_POST['email']        ?? '', 0, 150));
        $phone        = trim(substr($_POST['phone']        ?? '', 0, 20));
        $line_id      = trim(substr($_POST['line_id']      ?? '', 0, 100));
        $facebook_url = trim(substr($_POST['facebook_url'] ?? '', 0, 255));
        $tiktok_url   = trim(substr($_POST['tiktok_url']   ?? '', 0, 255));
        $bio          = trim(substr($_POST['bio']          ?? '', 0, 255));

        // Validate email
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message  = '❌ รูปแบบอีเมลไม่ถูกต้อง';
            $msg_type = 'error';
        }
        // Validate URLs
        elseif ($facebook_url && !filter_var($facebook_url, FILTER_VALIDATE_URL)) {
            $message  = '❌ URL Facebook ไม่ถูกต้อง (ต้องเริ่มด้วย https://)';
            $msg_type = 'error';
        }
        elseif ($tiktok_url && !filter_var($tiktok_url, FILTER_VALIDATE_URL)) {
            $message  = '❌ URL TikTok ไม่ถูกต้อง (ต้องเริ่มด้วย https://)';
            $msg_type = 'error';
        }
        // Validate phone (ตัวเลข, -, + เท่านั้น)
        elseif ($phone && !preg_match('/^[0-9\-\+\s]{7,20}$/', $phone)) {
            $message  = '❌ รูปแบบเบอร์โทรศัพท์ไม่ถูกต้อง';
            $msg_type = 'error';
        }
        else {
            try {
                $pdo->prepare('
                    INSERT INTO student_profiles
                        (user_id, nickname, email, phone, line_id, facebook_url, tiktok_url, bio)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        nickname     = VALUES(nickname),
                        email        = VALUES(email),
                        phone        = VALUES(phone),
                        line_id      = VALUES(line_id),
                        facebook_url = VALUES(facebook_url),
                        tiktok_url   = VALUES(tiktok_url),
                        bio          = VALUES(bio)
                ')->execute([$target_id, $nickname, $email, $phone,
                             $line_id, $facebook_url, $tiktok_url, $bio]);

                systemLog($my_id, 'PROFILE_UPDATE', "Updated profile of user ID: {$target_id}");
                $message  = '✅ บันทึกข้อมูลสำเร็จ';
                $msg_type = 'success';

                // reload profile
                $stmt = $pdo->prepare('SELECT * FROM student_profiles WHERE user_id = ?');
                $stmt->execute([$target_id]);
                $profile = $stmt->fetch();

            } catch (PDOException $e) {
                error_log('[edit_profile] update_info: ' . $e->getMessage());
                $message  = '❌ เกิดข้อผิดพลาดในการบันทึก';
                $msg_type = 'error';
            }
        }
    }

    // ════════════════════════════════════════════
    // ACTION 2: อัปโหลดรูปโปรไฟล์
    // ════════════════════════════════════════════
    elseif ($action === 'upload_avatar') {
        $file = $_FILES['avatar'] ?? null;

        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $err_map = [
                UPLOAD_ERR_INI_SIZE   => 'ไฟล์ใหญ่เกินที่ server กำหนด',
                UPLOAD_ERR_FORM_SIZE  => 'ไฟล์ใหญ่เกินที่ฟอร์มกำหนด',
                UPLOAD_ERR_NO_FILE    => 'กรุณาเลือกไฟล์',
            ];
            $message  = '❌ ' . ($err_map[$file['error'] ?? 0] ?? 'เกิดข้อผิดพลาดในการอัปโหลด');
            $msg_type = 'error';
        }
        // จำกัดขนาด 2MB
        elseif ($file['size'] > 2 * 1024 * 1024) {
            $message  = '❌ ไฟล์ต้องมีขนาดไม่เกิน 2MB';
            $msg_type = 'error';
        }
        else {
            // ตรวจ MIME Type จริงด้วย getimagesize (ไม่เชื่อ extension)
            $img_info = @getimagesize($file['tmp_name']);
            $allowed_mime = ['image/jpeg', 'image/png', 'image/webp'];

            if (!$img_info || !in_array($img_info['mime'], $allowed_mime)) {
                $message  = '❌ อนุญาตเฉพาะไฟล์รูปภาพ .jpg, .png, .webp เท่านั้น';
                $msg_type = 'error';
            } else {
                // สร้างชื่อไฟล์ใหม่ป้องกันชนกัน
                $ext      = match($img_info['mime']) {
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp',
                };
                $filename  = "uid_{$target_id}_" . time() . ".{$ext}";
                $dest_path = UPLOAD_DIR . 'avatars/' . $filename;

                // สร้างโฟลเดอร์ถ้ายังไม่มี
                if (!is_dir(UPLOAD_DIR . 'avatars/')) {
                    mkdir(UPLOAD_DIR . 'avatars/', 0755, true);
                }

                if (move_uploaded_file($file['tmp_name'], $dest_path)) {
                    // ลบรูปเก่า
                    $old_pic = $profile['profile_picture'] ?? '';
                    if ($old_pic && file_exists(UPLOAD_DIR . 'avatars/' . $old_pic)) {
                        @unlink(UPLOAD_DIR . 'avatars/' . $old_pic);
                    }

                    $pdo->prepare('
                        INSERT INTO student_profiles (user_id, profile_picture)
                        VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE profile_picture = VALUES(profile_picture)
                    ')->execute([$target_id, $filename]);

                    systemLog($my_id, 'AVATAR_UPLOAD', "Avatar updated for user ID: {$target_id}");
                    $message  = '✅ อัปโหลดรูปโปรไฟล์สำเร็จ';
                    $msg_type = 'success';

                    // reload
                    $stmt = $pdo->prepare('SELECT * FROM student_profiles WHERE user_id = ?');
                    $stmt->execute([$target_id]);
                    $profile = $stmt->fetch();
                } else {
                    $message  = '❌ ไม่สามารถบันทึกไฟล์ได้ กรุณาตรวจสอบสิทธิ์โฟลเดอร์ uploads/';
                    $msg_type = 'error';
                }
            }
        }
    }

    // ════════════════════════════════════════════
    // ACTION 3: เปลี่ยนรหัสผ่าน (developer เท่านั้น)
    // ════════════════════════════════════════════
    elseif ($action === 'change_password') {
        if (!$is_dev) {
            $message  = '❌ ไม่มีสิทธิ์เปลี่ยนรหัสผ่านตรงนี้';
            $msg_type = 'error';
        } else {
            $new_pw  = $_POST['new_password']     ?? '';
            $conf_pw = $_POST['confirm_password'] ?? '';

            if (empty($new_pw) || $new_pw !== $conf_pw) {
                $message  = '❌ รหัสผ่านไม่ตรงกันหรือว่างเปล่า';
                $msg_type = 'error';
            } elseif (
                strlen($new_pw) < 8 ||
                !preg_match('/[A-Z]/', $new_pw) ||
                !preg_match('/[a-z]/', $new_pw) ||
                !preg_match('/[0-9]/', $new_pw) ||
                !preg_match('/[^a-zA-Z0-9]/', $new_pw)
            ) {
                $message  = '❌ รหัสผ่านไม่ผ่านเกณฑ์ความปลอดภัย';
                $msg_type = 'error';
            } else {
                $hashed = password_hash($new_pw, PASSWORD_ARGON2ID);
                $pdo->prepare('UPDATE users SET password = ?, is_first_login = 0 WHERE id = ?')
                    ->execute([$hashed, $target_id]);
                systemLog($my_id, 'PASSWORD_CHANGE', "Changed password for user ID: {$target_id}");
                $message  = '✅ เปลี่ยนรหัสผ่านสำเร็จ';
                $msg_type = 'success';
            }
        }
    }
}

// ── Helper: Avatar URL ───────────────────────────────────────
function avatarUrl(?string $filename): string {
    if ($filename && file_exists(UPLOAD_DIR . 'avatars/' . $filename)) {
        return BASE_URL . 'uploads/avatars/' . rawurlencode($filename);
    }
    return BASE_URL . 'images/default_avatar.png';
}

require_once 'header.php';
?>

<style>
.profile-wrap {
    max-width: 680px;
    margin: 30px auto;
    padding: 0 16px;
}
.profile-card {
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 4px 20px rgba(0,0,0,.08);
    overflow: hidden;
    margin-bottom: 20px;
}
.profile-card-header {
    background: #1e3a8a;
    color: #fff;
    padding: 18px 24px;
    font-size: 1.05rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}
.profile-card-body { padding: 24px; }

/* Avatar */
.avatar-section {
    display: flex;
    align-items: center;
    gap: 20px;
    margin-bottom: 24px;
}
.avatar-img {
    width: 90px;
    height: 90px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid #1e3a8a;
}
.avatar-upload-btn {
    display: inline-block;
    padding: 8px 16px;
    background: #f1f5f9;
    border: 1px dashed #94a3b8;
    border-radius: 8px;
    cursor: pointer;
    font-size: .9rem;
    color: #475569;
    transition: all .2s;
}
.avatar-upload-btn:hover { background: #e2e8f0; border-color: #64748b; }
#avatar-file { display: none; }

/* Form */
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
    margin-bottom: 14px;
}
.form-group { display: flex; flex-direction: column; gap: 5px; }
.form-group label { font-size: .85rem; color: #64748b; font-weight: 500; }
.form-group input, .form-group textarea {
    padding: 10px 12px !important;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    font-family: 'Prompt', sans-serif;
    font-size: .95rem !important;
    width: 100% !important;
    box-sizing: border-box;
    transition: border-color .2s;
}
.form-group input:focus, .form-group textarea:focus {
    outline: none;
    border-color: #1e3a8a;
    box-shadow: 0 0 0 3px rgba(30,58,138,.1);
}
.form-group.full { grid-column: 1 / -1; }

.btn-save {
    width: 100%;
    padding: 13px;
    background: #1e3a8a;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-family: 'Prompt', sans-serif;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    margin-top: 6px;
    transition: opacity .2s;
}
.btn-save:hover { opacity: .88; }

.alert {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 16px;
    font-size: .9rem;
}
.alert-success { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
.alert-error   { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }

.social-prefix {
    font-size: .78rem;
    color: #94a3b8;
    margin-top: 2px;
}

/* Password section */
.pw-rules { font-size: .8rem; color: #94a3b8; margin: 6px 0 12px; line-height: 1.7; }

@media(max-width: 500px) {
    .form-row { grid-template-columns: 1fr; }
    .avatar-section { flex-direction: column; text-align: center; }
}
</style>

<div class="profile-wrap">
    <a href="javascript:history.back()"
       style="display:inline-flex;align-items:center;gap:6px;color:#64748b;font-size:.9rem;margin-bottom:16px;text-decoration:none;">
       ← กลับ
    </a>

    <h2 style="margin:0 0 20px;color:#1e3a8a;">
        ✏️ แก้ไขโปรไฟล์ — <?= h($user['display_name']) ?>
    </h2>

    <?php if ($message): ?>
    <div class="alert alert-<?= $msg_type === 'success' ? 'success' : 'error' ?>">
        <?= h($message) ?>
    </div>
    <?php endif; ?>

    <!-- ── ส่วนที่ 1: รูปโปรไฟล์ ─────────────────────────── -->
    <div class="profile-card">
        <div class="profile-card-header">🖼️ รูปโปรไฟล์</div>
        <div class="profile-card-body">
            <form method="POST" enctype="multipart/form-data"
                  action="edit_profile.php<?= $is_dev ? '?user_id='.$target_id : '' ?>">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action"     value="upload_avatar">

                <div class="avatar-section">
                    <img src="<?= avatarUrl($profile['profile_picture'] ?? null) ?>"
                         alt="รูปโปรไฟล์" class="avatar-img" id="avatar-preview">
                    <div>
                        <label class="avatar-upload-btn" for="avatar-file">
                            📁 เลือกรูปใหม่
                        </label>
                        <input type="file" id="avatar-file" name="avatar"
                               accept="image/jpeg,image/png,image/webp"
                               onchange="previewAvatar(this)">
                        <p style="font-size:.78rem;color:#94a3b8;margin:6px 0 0;">
                            รองรับ .jpg .png .webp — ขนาดไม่เกิน 2MB
                        </p>
                        <button type="submit" class="btn-save"
                                style="margin-top:10px;padding:8px 20px;width:auto;">
                            อัปโหลด
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- ── ส่วนที่ 2: ข้อมูลส่วนตัว ──────────────────────── -->
    <div class="profile-card">
        <div class="profile-card-header">👤 ข้อมูลส่วนตัว</div>
        <div class="profile-card-body">
            <form method="POST"
                  action="edit_profile.php<?= $is_dev ? '?user_id='.$target_id : '' ?>">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action"     value="update_info">

                <div class="form-row">
                    <div class="form-group">
                        <label>ชื่อเล่น (Nickname)</label>
                        <input type="text" name="nickname" maxlength="50"
                               value="<?= h($profile['nickname'] ?? '') ?>"
                               placeholder="ชื่อเล่น">
                    </div>
                    <div class="form-group">
                        <label>อีเมล</label>
                        <input type="email" name="email" maxlength="150"
                               value="<?= h($profile['email'] ?? '') ?>"
                               placeholder="example@email.com">
                    </div>
                    <div class="form-group">
                        <label>เบอร์โทรศัพท์</label>
                        <input type="text" name="phone" maxlength="20"
                               value="<?= h($profile['phone'] ?? '') ?>"
                               placeholder="08x-xxx-xxxx">
                    </div>
                    <div class="form-group">
                        <label>LINE ID</label>
                        <input type="text" name="line_id" maxlength="100"
                               value="<?= h($profile['line_id'] ?? '') ?>"
                               placeholder="@lineid">
                    </div>
                    <div class="form-group">
                        <label>Facebook URL</label>
                        <input type="url" name="facebook_url" maxlength="255"
                               value="<?= h($profile['facebook_url'] ?? '') ?>"
                               placeholder="https://facebook.com/...">
                        <span class="social-prefix">ต้องเป็น URL เต็ม https://</span>
                    </div>
                    <div class="form-group">
                        <label>TikTok URL</label>
                        <input type="url" name="tiktok_url" maxlength="255"
                               value="<?= h($profile['tiktok_url'] ?? '') ?>"
                               placeholder="https://tiktok.com/@...">
                        <span class="social-prefix">ต้องเป็น URL เต็ม https://</span>
                    </div>
                    <div class="form-group full">
                        <label>แนะนำตัวเอง (Bio)</label>
                        <textarea name="bio" rows="3" maxlength="255"
                                  placeholder="แนะนำตัวเองสั้นๆ..."><?= h($profile['bio'] ?? '') ?></textarea>
                    </div>
                </div>

                <button type="submit" class="btn-save">💾 บันทึกข้อมูล</button>
            </form>
        </div>
    </div>

    <?php if ($is_dev): ?>
    <!-- ── ส่วนที่ 3: เปลี่ยนรหัสผ่าน (developer เท่านั้น) ── -->
    <div class="profile-card">
        <div class="profile-card-header">🔒 เปลี่ยนรหัสผ่าน</div>
        <div class="profile-card-body">
            <form method="POST"
                  action="edit_profile.php<?= $is_dev ? '?user_id='.$target_id : '' ?>">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action"     value="change_password">

                <p class="pw-rules">
                    ✅ ความยาวอย่างน้อย 8 ตัว &nbsp;|&nbsp;
                    ✅ ตัวพิมพ์ใหญ่-เล็ก &nbsp;|&nbsp;
                    ✅ ตัวเลข &nbsp;|&nbsp;
                    ✅ อักขระพิเศษ (เช่น !@#$)
                </p>

                <div class="form-row">
                    <div class="form-group">
                        <label>รหัสผ่านใหม่</label>
                        <input type="password" name="new_password"
                               placeholder="รหัสผ่านใหม่" autocomplete="new-password">
                    </div>
                    <div class="form-group">
                        <label>ยืนยันรหัสผ่าน</label>
                        <input type="password" name="confirm_password"
                               placeholder="พิมพ์อีกครั้ง" autocomplete="new-password">
                    </div>
                </div>

                <button type="submit" class="btn-save"
                        style="background:#f59e0b;">
                    🔑 เปลี่ยนรหัสผ่าน
                </button>
            </form>
        </div>
    </div>
    <?php else: ?>
    <!-- ข้อความสำหรับ teacher/student -->
    <div style="text-align:center;padding:16px;color:#94a3b8;font-size:.85rem;">
        🔒 หากต้องการเปลี่ยนรหัสผ่าน กรุณาติดต่อผู้ดูแลระบบ
    </div>
    <?php endif; ?>
</div>

<script>
function previewAvatar(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            document.getElementById('avatar-preview').src = e.target.result;
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

<?php require_once 'footer.php'; ?>
