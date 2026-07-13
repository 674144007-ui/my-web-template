<?php
/**
 * user_manager.php - จัดการบัญชีผู้ใช้ (Clean Architecture)
 * ────────────────────────────────────────────────────────────
 * Bug ที่แก้:
 *   - เปลี่ยน INSERT/SELECT/UPDATE u.role → role_id (JOIN sys_roles)
 *   - ลบ error_reporting / display_errors / isset($pdo) guard
 *   - ลบ requireRole ซ้ำ
 *   - bulk_reset ใช้ JOIN sys_roles แทน WHERE role = 'student'
 * ────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logger.php';

requireRole(['developer']);

$page_title = 'จัดการผู้ใช้งาน (User Management)';
$csrf       = generate_csrf_token();
$message    = '';
$msg_type   = '';
$db_error_msg = null;

// ── Helper: แปลง role_key → role_id ────────────────────────
function getRoleId(PDO $pdo, string $role_key): ?int {
    $stmt = $pdo->prepare('SELECT id FROM sys_roles WHERE role_key = ? LIMIT 1');
    $stmt->execute([$role_key]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

// ── POST Handler ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $action = $_POST['action'] ?? '';

    // ── เพิ่มผู้ใช้ 1 คน ─────────────────────────────────────
    if ($action === 'create_user') {
        $uname    = trim($_POST['username'] ?? '');
        $dname    = trim($_POST['display_name'] ?? '');
        $role_key = $_POST['role'] ?? 'student';
        $class_id = !empty($_POST['class_id']) ? (int)$_POST['class_id'] : null;

        if (empty($uname) || empty($dname)) {
            $message = '❌ กรุณากรอกข้อมูลให้ครบถ้วน';
            $msg_type = 'error';
        } else {
            try {
                $role_id = getRoleId($pdo, $role_key);
                if (!$role_id) {
                    $message = '❌ Role ไม่ถูกต้อง';
                    $msg_type = 'error';
                } else {
                    $chk = $pdo->prepare('SELECT id FROM users WHERE username = ?');
                    $chk->execute([$uname]);
                    if ($chk->fetch()) {
                        $message = '❌ Username นี้ถูกใช้งานแล้ว';
                        $msg_type = 'error';
                    } else {
                        $pdo->prepare('INSERT INTO users (username, password, display_name, role_id, class_id, is_first_login) VALUES (?, ?, ?, ?, ?, 1)')
                            ->execute([$uname, 'PENDING_FIRST_LOGIN', $dname, $role_id, $class_id]);
                        systemLog($_SESSION['user_id'], 'USER_CREATE', "Created: @{$uname}");
                        $message = '✅ สร้างบัญชีผู้ใช้งานใหม่สำเร็จ';
                        $msg_type = 'success';
                    }
                }
            } catch (PDOException $e) {
                $message = '❌ เกิดข้อผิดพลาด: ' . $e->getMessage();
                $msg_type = 'error';
            }
        }
    }

    // ── รีเซ็ตรหัสรายคน ─────────────────────────────────────
    elseif ($action === 'reset_password') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid > 0) {
            try {
                $stmt = $pdo->prepare('SELECT username FROM users WHERE id = ?');
                $stmt->execute([$uid]);
                $u = $stmt->fetch();
                if ($u) {
                    $pdo->prepare('UPDATE users SET password = ?, is_first_login = 1, failed_login_attempts = 0, locked_until = NULL WHERE id = ?')
                        ->execute(['PENDING_FIRST_LOGIN', $uid]);
                    systemLog($_SESSION['user_id'], 'USER_RESET_PASS', "Reset ID: {$uid}");
                    $message  = "✅ รีเซ็ตรหัสผ่านสำเร็จ ระบบจะบังคับให้ @{$u['username']} ตั้งรหัสใหม่";
                    $msg_type = 'success';
                }
            } catch (PDOException $e) {
                $message = '❌ เกิดข้อผิดพลาด: ' . $e->getMessage();
                $msg_type = 'error';
            }
        }
    }

    // ── ล้างรหัสยกห้อง ───────────────────────────────────────
    elseif ($action === 'bulk_reset') {
        $target_class_id = (int)($_POST['bulk_class_id'] ?? 0);
        if ($target_class_id > 0) {
            try {
                // JOIN sys_roles แทน WHERE role = 'student'
                $stmt = $pdo->prepare('
                    UPDATE users u
                    JOIN sys_roles r ON r.id = u.role_id
                    SET u.password = ?, u.is_first_login = 1
                    WHERE u.class_id = ? AND r.role_key = ?
                ');
                $stmt->execute(['PENDING_FIRST_LOGIN', $target_class_id, 'student']);
                $affected = $stmt->rowCount();
                systemLog($_SESSION['user_id'], 'USER_BULK_RESET', "Bulk reset class ID: {$target_class_id}");
                $message  = "✅ รีเซ็ตรหัสผ่านนักเรียนสำเร็จ จำนวน {$affected} คน";
                $msg_type = 'success';
            } catch (PDOException $e) {
                $message  = '❌ เกิดข้อผิดพลาด: ' . $e->getMessage();
                $msg_type = 'error';
            }
        }
    }

    // ── Import CSV ───────────────────────────────────────────
    elseif ($action === 'import_excel') {
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
            $file_ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
            if ($file_ext !== 'csv') {
                $message  = '❌ รองรับเฉพาะไฟล์ .csv เท่านั้น';
                $msg_type = 'error';
            } else {
                $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
                // ล้าง BOM
                $bom = fread($handle, 3);
                if ($bom !== "\xEF\xBB\xBF") rewind($handle);

                $success_count = $duplicate_count = $row_count = 0;
                $stmt_check  = $pdo->prepare('SELECT id FROM users WHERE username = ?');

                while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                    $row_count++;
                    if ($row_count === 1) continue; // ข้าม header
                    if (count($data) < 2 || empty(trim($data[0]))) continue;

                    $uname    = trim($data[0]);
                    $dname    = trim($data[1]);
                    $role_key = isset($data[2]) && !empty(trim($data[2])) ? trim($data[2]) : 'student';
                    $class_id = isset($data[3]) && (int)$data[3] > 0 ? (int)$data[3] : null;

                    $role_id  = getRoleId($pdo, $role_key);
                    if (!$role_id) continue; // ข้าม row ที่ role ไม่รู้จัก

                    $stmt_check->execute([$uname]);
                    if ($stmt_check->fetch()) {
                        $duplicate_count++;
                    } else {
                        $pdo->prepare('INSERT INTO users (username, password, display_name, role_id, class_id, is_first_login) VALUES (?, ?, ?, ?, ?, 1)')
                            ->execute([$uname, 'PENDING_FIRST_LOGIN', $dname, $role_id, $class_id]);
                        $success_count++;
                    }
                }
                fclose($handle);
                systemLog($_SESSION['user_id'], 'USER_BULK_IMPORT', "Imported {$success_count} users.");
                $message  = "✅ นำเข้าสำเร็จ {$success_count} บัญชี (ข้ามซ้ำ {$duplicate_count} บัญชี)";
                $msg_type = 'success';
            }
        } else {
            $message  = '❌ กรุณาเลือกไฟล์';
            $msg_type = 'error';
        }
    }
}

// ── Soft Delete ──────────────────────────────────────────────
if (isset($_GET['delete_id'])) {
    $del_id = (int)$_GET['delete_id'];
    try {
        $pdo->prepare('UPDATE users SET is_deleted = 1 WHERE id = ?')->execute([$del_id]);
        systemLog($_SESSION['user_id'], 'USER_DELETE', "Soft-deleted ID: {$del_id}");
        header('Location: user_manager.php?msg=deleted');
        exit;
    } catch (PDOException $e) {
        $db_error_msg = 'Delete error: ' . $e->getMessage();
    }
}

if (isset($_GET['msg']) && $_GET['msg'] === 'deleted') {
    $message  = '🗑️ ลบผู้ใช้งานออกจากระบบเรียบร้อยแล้ว';
    $msg_type = 'success';
}

// ── ดึงข้อมูลตารางแสดงผล ────────────────────────────────────
$filter_role     = $_GET['role'] ?? '';
$filter_class_id = !empty($_GET['class_id']) ? (int)$_GET['class_id'] : '';
$users           = [];
$filter_classes  = [];

try {
    // JOIN sys_roles แทน u.role
    $sql = '
        SELECT u.id, u.username, u.display_name, r.role_key AS role,
               u.password, u.created_at, c.class_name
        FROM users u
        JOIN sys_roles r ON r.id = u.role_id
        LEFT JOIN classes c ON u.class_id = c.id
        WHERE u.is_deleted = 0
    ';
    $params = [];

    if ($filter_role) {
        $sql    .= ' AND r.role_key = ?';
        $params[] = $filter_role;
    }
    if ($filter_class_id) {
        $sql    .= ' AND u.class_id = ?';
        $params[] = $filter_class_id;
    }
    $sql .= ' ORDER BY r.role_key ASC, u.display_name ASC';

    $stmt  = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();

    $filter_classes = $pdo->query('SELECT id, class_name FROM classes ORDER BY level ASC, room ASC')->fetchAll();

} catch (PDOException $e) {
    $db_error_msg = 'Database Error: ' . $e->getMessage();
}

require_once 'header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;600&family=Share+Tech+Mono&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root { --uc: <?= h($tenant['primary_color'] ?? '#1e3a8a') ?>; }
*{box-sizing:border-box}
.um{max-width:1200px;margin:0 auto;padding:24px 20px 80px;font-family:'Prompt',sans-serif}
/* Hero */
.um-hero{background:var(--uc);border-radius:16px;padding:24px 30px;margin-bottom:22px;
    color:#fff;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;
    position:relative;overflow:hidden}
.um-hero::after{content:'👥';position:absolute;right:20px;top:50%;transform:translateY(-50%);
    font-size:5rem;opacity:.1;pointer-events:none}
.um-tag{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;
    letter-spacing:.08em;text-transform:uppercase;background:rgba(255,255,255,.15);
    border:1px solid rgba(255,255,255,.25);padding:3px 11px;border-radius:20px;margin-bottom:8px}
.um-hero h1{font-size:1.5rem;font-weight:700;margin:0 0 4px}
.um-hero .sub{font-size:.83rem;opacity:.85;margin:0}
/* Stats */
.um-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:20px}
.um-stat{background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:14px 18px;text-align:center;
    position:relative;overflow:hidden}
.um-stat::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--uc)}
.um-stat .sv{font-size:1.6rem;font-weight:700;color:var(--uc);line-height:1}
.um-stat .sl{font-size:.72rem;color:#94a3b8;margin-top:3px}
/* Section */
.um-sec{font-size:.7rem;font-weight:700;letter-spacing:.1em;color:#94a3b8;text-transform:uppercase;
    margin:20px 0 12px;display:flex;align-items:center;gap:8px}
.um-sec::after{content:'';flex:1;height:1px;background:#f1f5f9}
/* Add form */
.um-add{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:22px 24px;margin-bottom:20px}
.um-form-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:14px}
.um-fg{display:flex;flex-direction:column;gap:4px}
.um-fg label{font-size:.78rem;color:#64748b;font-weight:600}
.um-fg input,.um-fg select{padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:9px;
    font-family:'Prompt',sans-serif;font-size:.9rem;width:100%;transition:.2s}
.um-fg input:focus,.um-fg select:focus{outline:none;border-color:var(--uc);box-shadow:0 0 0 3px rgba(30,58,138,.08)}
.um-btn{padding:10px 22px;border:none;border-radius:9px;font-family:'Prompt',sans-serif;
    font-size:.88rem;font-weight:600;cursor:pointer;transition:.2s}
.um-btn:hover{opacity:.85}
.um-btn.primary{background:var(--uc);color:#fff}
.um-btn.danger{background:#ef4444;color:#fff}
.um-btn.warn{background:#f59e0b;color:#fff}
.um-btn.ghost{background:#f8fafc;color:#64748b;border:1px solid #e2e8f0}
.um-btn.sm{padding:5px 12px;font-size:.78rem}
/* Filter bar */
.um-filter{background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:14px 18px;
    margin-bottom:16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap}
.um-filter input,.um-filter select{padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;
    font-family:'Prompt',sans-serif;font-size:.85rem;flex:1;min-width:140px}
/* Table */
.um-table-wrap{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;overflow:hidden}
.um-table-head{padding:13px 20px;border-bottom:1px solid #f1f5f9;
    display:flex;justify-content:space-between;align-items:center}
.um-table-head span{font-size:.82rem;font-weight:600;color:#0f172a}
.um-tbl{width:100%;border-collapse:collapse}
.um-tbl th{background:#f8fafc;padding:9px 16px;text-align:left;font-size:.75rem;
    color:#64748b;font-weight:700;letter-spacing:.05em;text-transform:uppercase;
    border-bottom:1.5px solid #e2e8f0;white-space:nowrap}
.um-tbl td{padding:11px 16px;font-size:.85rem;border-bottom:1px solid #f9fafb;vertical-align:middle}
.um-tbl tr:last-child td{border-bottom:none}
.um-tbl tr:hover td{background:#fafafa}
.role-pill{display:inline-block;font-size:.72rem;font-weight:600;padding:2px 10px;
    border-radius:20px;border:1px solid}
.role-student{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}
.role-teacher{background:#f0fdf4;color:#15803d;border-color:#bbf7d0}
.role-parent{background:#fffbeb;color:#b45309;border-color:#fde68a}
.role-developer{background:#f5f3ff;color:#6d28d9;border-color:#ddd6fe}
.um-empty{text-align:center;padding:40px;color:#94a3b8;font-size:.88rem}
/* Alert */
.um-alert{padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:.85rem;font-weight:500}
.um-alert.ok{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7}
.um-alert.err{background:#fee2e2;color:#b91c1c;border:1px solid #fecaca}
@media(max-width:640px){.um-form-grid{grid-template-columns:1fr}
    .um{padding:14px 14px 60px}.um-hero{padding:18px 20px}}
</style>

<div class="um">
    <div class="top-bar">
        <h1 class="page-title"><span>👥</span> จัดการบัญชีผู้ใช้</h1>
        <div class="action-group">
            <button class="btn-main btn-add" onclick="openModal('modalAddUser', 'boxAddUser')">➕ เพิ่ม 1 คน</button>
            <button class="btn-main btn-excel" onclick="openModal('modalImportExcel', 'boxImportExcel')">📥 นำเข้าจาก Excel (CSV)</button>
            <button class="btn-main btn-bulk" onclick="openModal('modalBulkReset', 'boxBulkReset')">⚠️ ล้างรหัสผ่านยกห้อง</button>
        </div>
    </div>

    <?php if ($db_error_msg): ?>
        <div class="alert-box alert-error">⚠️ ฐานข้อมูลขัดข้อง: <?= htmlspecialchars($db_error_msg) ?></div>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="alert-box alert-<?= $msg_type ?>"><?= $message ?></div>
    <?php endif; ?>

    <form method="GET" action="user_manager.php" class="filter-box">
        <div class="form-group">
            <label class="form-label">กรองตามบทบาท (Role)</label>
            <select name="role" class="form-select">
                <option value="">-- แสดงทั้งหมด --</option>
                <option value="student" <?= $filter_role=='student'?'selected':'' ?>>นักเรียน (Student)</option>
                <option value="teacher" <?= $filter_role=='teacher'?'selected':'' ?>>ครูผู้สอน (Teacher)</option>
                <option value="parent" <?= $filter_role=='parent'?'selected':'' ?>>ผู้ปกครอง (Parent)</option>
                <option value="developer" <?= $filter_role=='developer'?'selected':'' ?>>ผู้พัฒนา (Developer)</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">กรองตามห้องเรียน (Class)</label>
            <select name="class_id" class="form-select">
                <option value="">-- ทุกห้องเรียน --</option>
                <?php foreach ($filter_classes as $fc): ?>
                    <option value="<?= $fc['id'] ?>" <?= $filter_class_id==$fc['id']?'selected':'' ?>><?= htmlspecialchars($fc['class_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <button type="submit" class="btn-filter">🔍 กรองข้อมูล</button>
        <a href="user_manager.php" style="padding: 10px 15px; color: #64748b; text-decoration: none; font-weight:bold; transition:0.2s; text-align:center;">ล้างค่า</a>
    </form>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th width="15%">Username</th>
                    <th width="25%">ชื่อ-นามสกุล</th>
                    <th width="10%">บทบาท</th>
                    <th width="15%">ห้องเรียน</th>
                    <th width="20%">สถานะรหัสผ่าน</th>
                    <th width="15%" style="text-align: right;">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($users)): ?>
                    <tr><td colspan="6" style="text-align:center; padding: 50px; color:#64748b; font-size:1.1rem;">ไม่มีข้อมูลผู้ใช้งาน</td></tr>
                <?php else: ?>
                    <?php foreach($users as $u): ?>
                        <tr>
                            <td data-label="Username:" style="font-weight:bold; color:#1e293b; font-family:'Share Tech Mono', monospace; font-size:1.1rem;"><?= htmlspecialchars($u['username']) ?></td>
                            <td data-label="ชื่อ-นามสกุล:" style="color:#1e293b;"><?= htmlspecialchars($u['display_name']) ?></td>
                            <td data-label="บทบาท:"><span class="badge-role role-<?= $u['role'] ?>"><?= strtoupper($u['role']) ?></span></td>
                            <td data-label="ห้องเรียน:">
                                <?php if($u['class_name']): ?>
                                    <span class="class-badge">🏫 <?= htmlspecialchars($u['class_name']) ?></span>
                                <?php else: ?>
                                    <span style="color:#cbd5e1;">-</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="สถานะ:">
                                <?php if($u['password'] === 'PENDING_FIRST_LOGIN' /* is_first_login=1 check instead */): ?>
                                    <div class="status-badge status-wait">⚠️ รอผู้ใช้ตั้งรหัส</div>
                                <?php else: ?>
                                    <div class="status-badge status-ok">✅ ปกติ</div>
                                <?php endif; ?>
                            </td>
                            <td data-label="" style="text-align: right;" class="action-links">
                                <button type="button" class="link-reset" onclick="openResetModal(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username']) ?>')" title="รีเซ็ตรหัสผ่าน">🔑</button>
                                <a href="edit_user.php?id=<?= $u['id'] ?>" class="link-edit" title="แก้ไขข้อมูล">✏️</a>
                                <a href="user_manager.php?delete_id=<?= $u['id'] ?>" class="link-delete" title="ลบผู้ใช้" onclick="return confirm('⚠️ คำเตือน: ต้องการลบผู้ใช้ <?= $u['username'] ?> หรือไม่?');">🗑️</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal-overlay" id="modalAddUser">
    <div class="modal-box" id="boxAddUser">
        <form method="POST" action="user_manager.php">
            <input type="hidden" name="action" value="create_user">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            
            <div class="modal-header">
                <h2>➕ สร้างบัญชีผู้ใช้งานใหม่</h2>
                <button type="button" class="btn-close" onclick="closeModal('modalAddUser', 'boxAddUser')">✖</button>
            </div>
            
            <div class="modal-body">
                <div class="form-group" style="margin-bottom:15px;">
                    <label class="form-label">Username *</label>
                    <input type="text" name="username" class="form-control-pass" required placeholder="เช่น stu002">
                </div>
                <div class="form-group" style="margin-bottom:15px;">
                    <label class="form-label">ชื่อ-นามสกุล *</label>
                    <input type="text" name="display_name" class="form-control-pass" required placeholder="เช่น ด.ช.สมชาย ใจดี">
                </div>
                <div class="form-group" style="margin-bottom:15px;">
                    <label class="form-label">บทบาท (Role) *</label>
                    <select name="role" class="form-control-pass" required>
                        <option value="student">นักเรียน (Student)</option>
                        <option value="teacher">ครูผู้สอน (Teacher)</option>
                        <option value="parent">ผู้ปกครอง (Parent)</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:15px;">
                    <label class="form-label">ห้องเรียน (เฉพาะนักเรียน)</label>
                    <select name="class_id" class="form-control-pass">
                        <option value="">-- ไม่ระบุ --</option>
                        <?php foreach ($filter_classes as $fc): ?>
                            <option value="<?= $fc['id'] ?>"><?= htmlspecialchars($fc['class_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="background:#eff6ff; padding:15px; border-radius:8px; color:#1e3a8a; font-size:0.95rem; border:1px solid #bfdbfe; margin-top:20px;">
                    ℹ️ <strong>ระบบยืนยันตัวตนใหม่:</strong> เมื่อผู้ใช้งานล็อกอินครั้งแรก ระบบจะบังคับให้ตั้งรหัสผ่านด้วยตนเอง
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('modalAddUser', 'boxAddUser')">ยกเลิก</button>
                <button type="submit" class="btn-save">💾 บันทึกผู้ใช้ใหม่</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="modalImportExcel">
    <div class="modal-box" id="boxImportExcel">
        <form method="POST" action="user_manager.php" enctype="multipart/form-data">
            <input type="hidden" name="action" value="import_excel">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            
            <div class="modal-header" style="background: linear-gradient(135deg, #1e3a8a, #3b82f6); color:white;">
                <h2 style="color:white;">📥 นำเข้าบัญชีจาก Excel (CSV)</h2>
                <button type="button" class="btn-close" style="color:white;" onclick="closeModal('modalImportExcel', 'boxImportExcel')">✖</button>
            </div>
            
            <div class="modal-body">
                <div style="background:#f8fafc; padding:20px; border-radius:12px; border:2px dashed #cbd5e1; text-align:center; margin-bottom:20px;">
                    <span style="font-size:3rem; display:block; margin-bottom:10px;">📄</span>
                    <strong style="color:#1e293b; font-size:1.1rem;">อัปโหลดไฟล์ (.csv) ของคุณที่นี่</strong>
                    <input type="file" name="csv_file" accept=".csv" required style="margin-top:15px; width:100%;">
                </div>

                <div style="background:#eff6ff; padding:15px; border-radius:8px; color:#1e40af; font-size:0.95rem; border:1px solid #bfdbfe;">
                    <strong style="display:block; margin-bottom:5px;">💡 คำแนะนำการใช้งาน:</strong>
                    1. กรุณาดาวน์โหลด <a href="download_template.php" style="color:#2563eb; font-weight:bold; text-decoration:underline;">ไฟล์แม่แบบ (Template)</a><br>
                    2. กรอกข้อมูลใน Microsoft Excel ตามหัวข้อที่กำหนด<br>
                    3. กด <strong>Save As -> CSV UTF-8 (Comma delimited) (*.csv)</strong><br>
                    4. นำไฟล์ .csv ที่ได้มาอัปโหลดในช่องด้านบน
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('modalImportExcel', 'boxImportExcel')">ยกเลิก</button>
                <button type="submit" class="btn-save" style="background:#16a34a; box-shadow: 0 4px 10px rgba(22,163,74,0.3);">🚀 เริ่มการนำเข้าข้อมูล</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="modalResetPass">
    <div class="modal-box" id="boxResetPass">
        <form method="POST" action="user_manager.php">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="user_id" id="resetUserId" value="">
            <div class="modal-header" style="background: #fef3c7;">
                <h2 style="color: #d97706;">🔑 ล้างรหัสผ่าน (Reset Password)</h2>
                <button type="button" class="btn-close" onclick="closeModal('modalResetPass', 'boxResetPass')">✖</button>
            </div>
            <div class="modal-body">
                <p style="margin-top:0; color:#475569; font-size: 1.1rem;">ล้างรหัสผ่านบัญชี: <strong id="resetUsernameDisplay" style="color:#0f172a; font-family:'Share Tech Mono';">@username</strong></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('modalResetPass', 'boxResetPass')">ยกเลิก</button>
                <button type="submit" class="btn-save" style="background: #f59e0b;">⚡ ยืนยันการรีเซ็ต</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="modalBulkReset">
    <div class="modal-box" id="boxBulkReset" style="border: 2px solid #ef4444;">
        <form method="POST" action="user_manager.php">
            <input type="hidden" name="action" value="bulk_reset">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="modal-header" style="background: #fee2e2;">
                <h2 style="color: #b91c1c;">⚠️ ล้างรหัสผ่านยกห้อง (Bulk Reset)</h2>
                <button type="button" class="btn-close" onclick="closeModal('modalBulkReset', 'boxBulkReset')">✖</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" style="color:#1e293b;">เลือกห้องเรียนเป้าหมาย *</label>
                    <select name="bulk_class_id" class="form-control-pass" required>
                        <option value="">-- เลือกห้องเรียน --</option>
                        <?php foreach ($filter_classes as $fc): ?>
                            <option value="<?= $fc['id'] ?>"><?= htmlspecialchars($fc['class_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer" style="background: #fee2e2;">
                <button type="button" class="btn-cancel" onclick="closeModal('modalBulkReset', 'boxBulkReset')">ยกเลิก</button>
                <button type="submit" class="btn-save" style="background: #ef4444;" onclick="return confirm('ยืนยันการล้างรหัสผ่านนักเรียนทั้งห้อง?');">🚨 ล้างรหัสผ่านทั้งห้อง</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Master Modal Controller
    function openModal(overlayId, boxId) {
        const m = document.getElementById(overlayId);
        const b = document.getElementById(boxId);
        m.style.display = 'flex'; 
        setTimeout(() => b.classList.add('show'), 10);
    }
    
    function closeModal(overlayId, boxId) {
        const m = document.getElementById(overlayId);
        const b = document.getElementById(boxId);
        b.classList.remove('show'); 
        setTimeout(() => m.style.display = 'none', 300);
    }

    function openResetModal(userId, username) {
        document.getElementById('resetUserId').value = userId; 
        document.getElementById('resetUsernameDisplay').innerText = '@' + username;
        openModal('modalResetPass', 'boxResetPass');
    }

    window.onclick = function(event) {
        if (event.target.classList.contains('modal-overlay')) {
            event.target.querySelector('.modal-box').classList.remove('show');
            setTimeout(() => event.target.style.display = 'none', 300);
        }
    }
</script>

<?php require_once 'footer.php'; ?>