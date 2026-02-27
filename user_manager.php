<?php
// user_manager.php - ระบบจัดการผู้ใช้งานพร้อม Advanced Filtering (Production Version)
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';
require_once 'logger.php';

// อนุญาตเฉพาะ Developer เท่านั้นเพื่อความปลอดภัยขั้นสูงสุด
requireRole(['developer']);

$page_title = "จัดการผู้ใช้งานระบบ (Pro)";
$msg = "";
$msg_type = "";
$csrf = generate_csrf_token();

// -----------------------------
// 1. จัดการคำสั่งแบบกลุ่ม (Bulk Actions)
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    
    $bulk_action = $_POST['bulk_action'];
    $selected_users = $_POST['selected_users'] ?? [];

    if (empty($selected_users)) {
        $msg = "❌ กรุณาเลือกผู้ใช้งานอย่างน้อย 1 คน (ติ๊กถูกด้านหน้า)";
        $msg_type = "error";
    } else {
        $success_count = 0;
        $fail_count = 0;

        foreach ($selected_users as $user_id) {
            $user_id = intval($user_id);
            
            // ป้องกันจัดการตัวเอง
            if ($user_id === $_SESSION['user_id']) continue;

            // ป้องกันจัดการ Developer
            $check = $conn->prepare("SELECT role, username FROM users WHERE id = ?");
            $check->bind_param("i", $user_id);
            $check->execute();
            $res = $check->get_result();
            if ($res->num_rows === 0) continue;
            
            $u_data = $res->fetch_assoc();
            if ($u_data['role'] === 'developer') continue; 
            $check->close();

            // ทำงานตาม Action ที่เลือก
            if ($bulk_action === 'suspend') {
                $stmt = $conn->prepare("UPDATE users SET is_deleted = 1 WHERE id = ?");
                $stmt->bind_param("i", $user_id);
                if($stmt->execute()) $success_count++; else $fail_count++;
                $stmt->close();
            } 
            elseif ($bulk_action === 'restore') {
                $stmt = $conn->prepare("UPDATE users SET is_deleted = 0 WHERE id = ?");
                $stmt->bind_param("i", $user_id);
                if($stmt->execute()) $success_count++; else $fail_count++;
                $stmt->close();
            }
        }

        $msg = "✔ ดำเนินการเสร็จสิ้น: สำเร็จ $success_count รายการ, ล้มเหลว/ข้าม $fail_count รายการ";
        $msg_type = "success";
        systemLog($_SESSION['user_id'], 'BULK_ACTION', "Action: $bulk_action applied to $success_count users");
    }
}

// -----------------------------
// 2. ดึงข้อมูลชั้นเรียนทั้งหมดมาจัดกลุ่มสำหรับ Dropdown กรองข้อมูล
// -----------------------------
$grouped_classes = [];
$res_classes = $conn->query("SELECT id, class_name, level FROM classes ORDER BY level ASC, room ASC");
if ($res_classes) {
    while ($row = $res_classes->fetch_assoc()) {
        $lvl = $row['level'] ? $row['level'] : 'อื่นๆ';
        $grouped_classes[$lvl][] = $row;
    }
}

// -----------------------------
// 3. ระบบค้นหา กรอง และแบ่งหน้า (Advanced Filtering)
// -----------------------------
$search_text = trim($_GET['search'] ?? '');
$filter_role = trim($_GET['role'] ?? '');
$filter_status = trim($_GET['status'] ?? 'active'); 
$filter_class = intval($_GET['class_id'] ?? 0); 

$limit = 50; 
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$sql_base = "
    FROM users u
    LEFT JOIN classes c ON u.class_id = c.id
    WHERE 1=1
";
$params = [];
$types = "";

// กรองสถานะบัญชี (ใช้งาน / ระงับ)
if ($filter_status === 'active') {
    $sql_base .= " AND u.is_deleted = 0";
} elseif ($filter_status === 'suspended') {
    $sql_base .= " AND u.is_deleted = 1";
}

// กรองตามการค้นหาข้อความ
if ($search_text !== '') {
    $sql_base .= " AND (u.username LIKE ? OR u.display_name LIKE ?)";
    $like_search = "%{$search_text}%";
    $params[] = $like_search;
    $params[] = $like_search;
    $types .= "ss";
}

// กรองตามบทบาท
if ($filter_role !== '') {
    $sql_base .= " AND u.role = ?";
    $params[] = $filter_role;
    $types .= "s";
}

// กรองตามห้องเรียน
if ($filter_class > 0) {
    $sql_base .= " AND u.class_id = ?";
    $params[] = $filter_class;
    $types .= "i";
}

// หาจำนวนรวม
$stmt_count = $conn->prepare("SELECT COUNT(u.id) AS total " . $sql_base);
if ($types !== "") $stmt_count->bind_param($types, ...$params);
$stmt_count->execute();
$total_rows = $stmt_count->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);
$stmt_count->close();

// ดึงข้อมูลจริง
$sql_data = "
    SELECT u.id, u.username, u.display_name, u.role, u.is_deleted, u.created_at, c.class_name 
    " . $sql_base . " 
    ORDER BY u.role ASC, c.level ASC, c.room ASC, u.display_name ASC 
    LIMIT ? OFFSET ?
";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt_data = $conn->prepare($sql_data);
if ($types !== "") $stmt_data->bind_param($types, ...$params);
$stmt_data->execute();
$result_users = $stmt_data->get_result();

require_once 'header.php';
?>

<style>
    .toolbar {
        background: white; padding: 20px; border-radius: 12px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 20px;
        display: flex; justify-content: space-between; flex-wrap: wrap; gap: 15px; align-items: center;
    }
    .search-box { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; width: 100%; }
    .search-box input[type="text"], .search-box select {
        padding: 10px 15px; border-radius: 8px; border: 1px solid #cbd5e1; outline: none; margin-bottom: 0; font-family: inherit;
    }
    .btn-search { padding: 10px 20px; background: #3b82f6; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; }
    .btn-search:hover { background: #2563eb; }
    
    .action-buttons { display: flex; gap: 10px; width: 100%; justify-content: flex-end; border-top: 1px solid #f1f5f9; padding-top: 15px; margin-top: 5px; }
    .btn-add { padding: 10px 20px; background: #10b981; color: white; text-decoration: none; border-radius: 8px; font-weight: bold; }
    .btn-import { padding: 10px 20px; background: #f59e0b; color: white; text-decoration: none; border-radius: 8px; font-weight: bold; }
    .btn-mapping { padding: 10px 20px; background: #8b5cf6; color: white; text-decoration: none; border-radius: 8px; font-weight: bold; }

    .data-table { width: 100%; border-collapse: collapse; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
    .data-table thead { background: #f8fafc; text-align: left; }
    .data-table th, .data-table td { padding: 15px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
    
    .role-badge { padding: 5px 12px; border-radius: 20px; font-size: 0.85em; font-weight: bold; display: inline-block; }
    .role-developer { background: #1e293b; color: #f8fafc; }
    .role-teacher { background: #dbeafe; color: #1e40af; }
    .role-student { background: #dcfce7; color: #166534; }
    .role-parent { background: #fef3c7; color: #92400e; }

    .bulk-bar {
        background: #1e293b; color: white; padding: 15px 20px; border-radius: 12px; margin-bottom: 15px;
        display: flex; justify-content: space-between; align-items: center;
        opacity: 0; pointer-events: none; transition: 0.3s; transform: translateY(-10px);
    }
    .bulk-bar.active { opacity: 1; pointer-events: auto; transform: translateY(0); }
    
    .chk-user { width: 18px; height: 18px; cursor: pointer; }

    .pagination { display: flex; justify-content: center; gap: 5px; margin-top: 20px; }
    .pagination a { padding: 8px 15px; background: white; border: 1px solid #cbd5e1; color: #334155; text-decoration: none; border-radius: 6px; font-weight: bold; }
    .pagination a.active { background: #3b82f6; color: white; border-color: #3b82f6; }
    .pagination a:hover:not(.active) { background: #f1f5f9; }
</style>

<h2>👥 จัดการผู้ใช้งานระบบ (User Management Pro)</h2>
<p style="color: #64748b; margin-top: -10px; margin-bottom: 20px;">ค้นหา เพิ่ม นำเข้าไฟล์ CSV และจัดการสิทธิ์ผู้ใช้งานทั้งหมดในโรงเรียนบ้านคาวิทยา</p>

<?php if ($msg): ?>
    <div class="msg <?= h($msg_type) ?>"><?= h($msg) ?></div>
<?php endif; ?>

<div class="toolbar">
    <form method="get" class="search-box">
        <input type="text" name="search" placeholder="🔍 ค้นหาชื่อ/Username..." value="<?= h($search_text) ?>" style="flex: 2; min-width: 200px;">
        
        <select name="role" style="flex: 1; min-width: 150px;">
            <option value="">-- ทุกบทบาท --</option>
            <option value="student" <?= $filter_role === 'student' ? 'selected' : '' ?>>นักเรียน</option>
            <option value="teacher" <?= $filter_role === 'teacher' ? 'selected' : '' ?>>ครู</option>
            <option value="parent" <?= $filter_role === 'parent' ? 'selected' : '' ?>>ผู้ปกครอง</option>
            <option value="developer" <?= $filter_role === 'developer' ? 'selected' : '' ?>>ผู้พัฒนา</option>
        </select>

        <select name="class_id" style="flex: 1.5; min-width: 180px;">
            <option value="0">-- ทุกห้องเรียน --</option>
            <?php foreach ($grouped_classes as $lvl => $rooms): ?>
                <optgroup label="📚 ระดับชั้น <?= h($lvl) ?>">
                    <?php foreach ($rooms as $c): ?>
                        <option value="<?= h($c['id']) ?>" <?= $filter_class == $c['id'] ? 'selected' : '' ?>>
                            <?= h($c['class_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endforeach; ?>
        </select>

        <select name="status" style="flex: 1; min-width: 150px;">
            <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>-- ทุกสถานะ --</option>
            <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>✅ ใช้งานปกติ</option>
            <option value="suspended" <?= $filter_status === 'suspended' ? 'selected' : '' ?>>🚫 ถูกระงับบัญชี</option>
        </select>

        <button type="submit" class="btn-search">🔍 กรองข้อมูล</button>
        <a href="user_manager.php" style="color:#ef4444; font-weight:bold; margin-left:10px; text-decoration:none;">ล้างตัวกรอง</a>
    </form>

    <div class="action-buttons">
        <a class="btn-add" href="add_user.php">➕ เพิ่มคนเดียว</a>
        <a class="btn-import" href="import_users.php">📂 นำเข้า Excel</a>
        <a class="btn-mapping" href="parent_mapping.php">👨‍👩‍👦 จับคู่ผู้ปกครอง</a>
    </div>
</div>

<form method="post" id="bulkForm" onsubmit="return confirm('ยืนยันการทำรายการแบบกลุ่มกับรายชื่อที่เลือก?');">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    
    <div class="bulk-bar" id="bulkBar">
        <div><strong id="selectedCount">เลือก 0 รายการ</strong></div>
        <div>
            <select name="bulk_action" required style="padding: 8px 15px; border-radius: 6px; border:none; outline:none; margin-right: 10px; font-family: inherit;">
                <option value="">-- เลือกการจัดการ --</option>
                <option value="suspend">🚫 ระงับบัญชี (Suspend)</option>
                <option value="restore">🔓 กู้คืนบัญชี (Restore)</option>
            </select>
            <button type="submit" class="btn-primary" style="background:#3b82f6; padding: 8px 15px;">ดำเนินการ</button>
        </div>
    </div>

    <div style="overflow-x: auto;">
        <table class="data-table">
            <thead>
                <tr>
                    <th width="5%"><input type="checkbox" id="selectAll" class="chk-user" title="เลือกทั้งหมด"></th>
                    <th>Username</th>
                    <th>ชื่อ-นามสกุล</th>
                    <th>บทบาท</th>
                    <th>ระดับชั้น</th>
                    <th>สถานะ</th>
                    <th style="text-align: center; min-width: 180px;">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result_users->num_rows > 0): ?>
                    <?php while($row = $result_users->fetch_assoc()): ?>
                    <tr style="<?= $row['is_deleted'] == 1 ? 'background: #fff5f5; opacity: 0.8;' : '' ?>">
                        <td>
                            <?php if ($row['role'] !== 'developer'): ?>
                                <input type="checkbox" name="selected_users[]" value="<?= h($row['id']) ?>" class="chk-item chk-user">
                            <?php endif; ?>
                        </td>
                        <td><strong><?= h($row['username']) ?></strong></td>
                        <td style="<?= $row['is_deleted'] == 1 ? 'text-decoration: line-through;' : '' ?>"><?= h($row['display_name']) ?></td>
                        <td><span class="role-badge role-<?= h($row['role']) ?>"><?= strtoupper(h($row['role'])) ?></span></td>
                        <td>
                            <?php if ($row['class_name']): ?>
                                <span style="font-weight: bold; color: #0f172a; background: #f1f5f9; padding: 4px 8px; border-radius: 6px; border: 1px solid #e2e8f0;">
                                    <?= h($row['class_name']) ?>
                                </span>
                            <?php else: ?>
                                <span style="color:#cbd5e1;">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $row['is_deleted'] == 1 ? '<span style="color:#ef4444; font-weight:bold;">ถูกระงับ</span>' : '<span style="color:#10b981; font-weight:bold;">ปกติ</span>' ?>
                        </td>
                        <td style="text-align: center;">
                            <a href="edit_user.php?id=<?= h($row['id']) ?>" style="display: inline-block; padding: 6px 12px; background: #3b82f6; color: white; text-decoration: none; border-radius: 6px; font-weight: bold; margin-right: 5px; transition: 0.3s;">⚙️ แก้ไข</a>
                            <a href="view_user_logs.php?id=<?= h($row['id']) ?>" title="ดูประวัติการใช้งาน" style="display: inline-block; padding: 6px 12px; background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; text-decoration: none; border-radius: 6px; font-weight: bold; transition: 0.3s;">👁️ ประวัติ</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="7" style="text-align: center; padding: 40px; color: #64748b; font-size: 1.1rem;">ไม่พบข้อมูลผู้ใช้งานที่ตรงกับเงื่อนไขการค้นหา</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</form>

<?php if ($total_pages > 1): ?>
<div class="pagination">
    <?php
    $base_url = "user_manager.php?search=" . urlencode($search_text) . "&role=" . urlencode($filter_role) . "&status=" . urlencode($filter_status) . "&class_id=" . $filter_class;
    
    if ($page > 1) echo "<a href='{$base_url}&page=" . ($page - 1) . "'>&laquo; กลับ</a>";

    for ($i = 1; $i <= $total_pages; $i++) {
        if ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)) {
            $active = ($i == $page) ? "class='active'" : "";
            echo "<a href='{$base_url}&page={$i}' {$active}>{$i}</a>";
        } elseif ($i == 2 || $i == $total_pages - 1) {
            echo "<span style='padding: 8px;'>...</span>";
        }
    }

    if ($page < $total_pages) echo "<a href='{$base_url}&page=" . ($page + 1) . "'>ถัดไป &raquo;</a>";
    ?>
</div>
<?php endif; ?>

<div style="margin-top: 30px; text-align: center;">
    <a href="dashboard_dev.php" style="color: #64748b; text-decoration: none; font-weight: bold;">⬅ กลับ Dev Dashboard</a>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        const selectAllBtn = document.getElementById('selectAll');
        const checkboxes = document.querySelectorAll('.chk-item');
        const bulkBar = document.getElementById('bulkBar');
        const selectedCount = document.getElementById('selectedCount');

        function updateBulkBar() {
            let checkedCount = document.querySelectorAll('.chk-item:checked').length;
            selectedCount.innerText = `เลือก ${checkedCount} รายการ`;
            if (checkedCount > 0) { bulkBar.classList.add('active'); } 
            else { bulkBar.classList.remove('active'); selectAllBtn.checked = false; }
        }

        if (selectAllBtn) {
            selectAllBtn.addEventListener('change', function() {
                checkboxes.forEach(cb => { cb.checked = selectAllBtn.checked; });
                updateBulkBar();
            });
        }

        checkboxes.forEach(cb => { cb.addEventListener('change', updateBulkBar); });
    });
</script>

<?php 
$stmt_data->close();
require_once 'footer.php'; 
?>