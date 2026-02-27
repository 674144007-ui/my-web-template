<?php
/**
 * user_manager.php - จัดการบัญชีผู้ใช้ และแสดงรายชื่อ (Phase 3: Class Filter & Export)
 * ระบบบริหารจัดการห้องเรียน โรงเรียนบ้านคาวิทยา
 */
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';

requireRole(['developer', 'admin']);

$page_title = "จัดการผู้ใช้งาน (User Management)";

// จัดการการลบผู้ใช้ (Soft Delete)
if (isset($_GET['delete_id'])) {
    $del_id = intval($_GET['delete_id']);
    $stmt = $conn->prepare("UPDATE users SET is_deleted = 1 WHERE id = ?");
    $stmt->bind_param("i", $del_id);
    $stmt->execute();
    $stmt->close();
    header("Location: user_manager.php?msg=deleted");
    exit;
}

// รับค่า Filter
$filter_role = $_GET['role'] ?? '';
$filter_class_id = !empty($_GET['class_id']) ? intval($_GET['class_id']) : '';

// สร้างคำสั่ง SQL แบบพลวัต (Dynamic SQL)
$sql = "SELECT u.id, u.username, u.display_name, u.role, u.created_at, c.class_name 
        FROM users u 
        LEFT JOIN classes c ON u.class_id = c.id 
        WHERE u.is_deleted = 0";
$params = [];
$types = "";

if ($filter_role) {
    $sql .= " AND u.role = ?";
    $params[] = $filter_role;
    $types .= "s";
}
if ($filter_class_id) {
    $sql .= " AND u.class_id = ?";
    $params[] = $filter_class_id;
    $types .= "i";
}

$sql .= " ORDER BY u.role ASC, c.level ASC, c.room ASC, u.display_name ASC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$users = $stmt->get_result();

// ดึงข้อมูลห้องทั้งหมดมาทำ Dropdown Filter
$filter_classes = [];
$res_c = $conn->query("SELECT id, class_name, level FROM classes WHERE is_active = 1 ORDER BY level ASC, room ASC");
while ($row = $res_c->fetch_assoc()) {
    $filter_classes[] = $row;
}

require_once 'header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">
<style>
    body { background-color: #f8fafc; font-family: 'Sarabun', sans-serif; }
    .manager-container { max-width: 1250px; margin: 30px auto; padding: 0 20px; }
    
    .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;}
    .page-title { font-size: 2rem; color: #1e293b; font-weight: bold; margin: 0; display:flex; align-items:center; gap:10px;}
    
    .action-group { display: flex; gap: 10px; }
    .btn-main { padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: bold; transition: 0.2s; display: inline-flex; align-items: center; gap: 8px; border: none; cursor: pointer; font-size: 1rem; font-family: inherit;}
    .btn-add { background: linear-gradient(135deg, #10b981, #059669); color: white; box-shadow: 0 4px 10px rgba(16, 185, 129, 0.3); }
    .btn-add:hover { transform: translateY(-2px); box-shadow: 0 6px 15px rgba(16, 185, 129, 0.4); }
    .btn-import { background: linear-gradient(135deg, #f59e0b, #d97706); color: white; box-shadow: 0 4px 10px rgba(245, 158, 11, 0.3); }
    .btn-import:hover { transform: translateY(-2px); box-shadow: 0 6px 15px rgba(245, 158, 11, 0.4); }

    /* Filter Box */
    .filter-box { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); margin-bottom: 25px; border: 1px solid #e2e8f0; display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;}
    .form-group { flex: 1; min-width: 200px; }
    .form-label { display: block; font-size: 0.9rem; font-weight: bold; color: #64748b; margin-bottom: 5px; }
    .form-select { width: 100%; padding: 10px 15px; border-radius: 8px; border: 1px solid #cbd5e1; background: #f8fafc; font-family: inherit; font-size: 1rem; color: #1e293b; outline:none; transition: 0.3s;}
    .form-select:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); background: white;}
    
    .btn-filter { background: #3b82f6; color: white; border: none; padding: 10px 25px; border-radius: 8px; cursor: pointer; font-weight: bold; height: 43px; display: flex; align-items: center; transition:0.2s;}
    .btn-filter:hover { background: #2563eb; }
    
    .btn-export { background: #1e293b; color: white; text-decoration: none; padding: 10px 20px; border-radius: 8px; font-weight: bold; height: 43px; display: inline-flex; align-items: center; gap: 8px; transition:0.2s; border:1px solid #0f172a;}
    .btn-export:hover { background: #334155; }

    /* Table */
    .table-card { background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #e2e8f0; }
    table { width: 100%; border-collapse: collapse; text-align: left; }
    thead { background: #f1f5f9; color: #334155; border-bottom: 2px solid #e2e8f0;}
    th { padding: 15px 20px; font-weight: bold; font-size: 0.95rem; }
    td { padding: 15px 20px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
    tbody tr:hover { background: #f8fafc; }
    
    .badge-role { padding: 5px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: bold; display: inline-block; text-align: center; min-width: 70px;}
    .role-student { background: #dbeafe; color: #1e40af; }
    .role-teacher { background: #fef3c7; color: #92400e; }
    .role-parent { background: #e0e7ff; color: #3730a3; }
    .role-developer { background: #f3e8ff; color: #6b21a8; }
    
    .class-badge { background: #f1f5f9; border: 1px solid #cbd5e1; color: #334155; padding: 4px 10px; border-radius: 6px; font-weight: bold; font-size: 0.85rem; }

    .action-links a { text-decoration: none; font-size: 0.85rem; font-weight: bold; padding: 6px 12px; border-radius: 6px; transition: 0.2s; margin-right: 5px; display: inline-block;}
    .link-edit { background: #e0f2fe; color: #0284c7; } .link-edit:hover { background: #bae6fd; }
    .link-delete { background: #fee2e2; color: #dc2626; } .link-delete:hover { background: #fca5a5; }
</style>

<div class="manager-container">
    <div class="top-bar">
        <h1 class="page-title"><span>👥</span> จัดการบัญชีผู้ใช้</h1>
        <div class="action-group">
            <a href="add_user.php" class="btn-main btn-add">➕ เพิ่มรายบุคคล</a>
            <a href="import_users.php" class="btn-main btn-import">📥 นำเข้าจาก Excel</a>
        </div>
    </div>

    <form method="GET" action="user_manager.php" class="filter-box" id="filterForm">
        <div class="form-group">
            <label class="form-label">กรองตามบทบาท (Role)</label>
            <select name="role" class="form-select">
                <option value="">-- แสดงทั้งหมด --</option>
                <option value="student" <?= $filter_role=='student'?'selected':'' ?>>นักเรียน (Student)</option>
                <option value="teacher" <?= $filter_role=='teacher'?'selected':'' ?>>ครูผู้สอน (Teacher)</option>
                <option value="developer" <?= $filter_role=='developer'?'selected':'' ?>>ผู้พัฒนา (Developer)</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">กรองตามห้องเรียน (Class)</label>
            <select name="class_id" class="form-select">
                <option value="">-- ทุกห้องเรียน --</option>
                <?php 
                $current_lvl = '';
                foreach ($filter_classes as $fc): 
                    if ($current_lvl !== $fc['level']) {
                        if ($current_lvl !== '') echo '</optgroup>';
                        $current_lvl = $fc['level'];
                        echo "<optgroup label=\"ระดับชั้น {$current_lvl}\">";
                    }
                ?>
                    <option value="<?= $fc['id'] ?>" <?= $filter_class_id==$fc['id']?'selected':'' ?>><?= htmlspecialchars($fc['class_name']) ?></option>
                <?php endforeach; if($current_lvl !== '') echo '</optgroup>'; ?>
            </select>
        </div>
        
        <button type="submit" class="btn-filter">🔍 กรองข้อมูล</button>
        <a href="user_manager.php" style="padding: 10px 15px; color: #64748b; text-decoration: none; font-weight:bold; transition:0.2s;">ล้างค่า</a>

        <a href="#" onclick="exportData()" class="btn-export" title="ส่งออกรายชื่อและรหัสผ่านเพื่อแจกนักเรียน">
            <span>🖨️</span> ส่งออก Excel (CSV)
        </a>
    </form>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th width="5%">ID</th>
                    <th width="15%">Username</th>
                    <th width="25%">ชื่อ-นามสกุล</th>
                    <th width="15%">บทบาท</th>
                    <th width="15%">ห้องเรียน</th>
                    <th width="10%">วันที่สร้าง</th>
                    <th width="15%" style="text-align: right;">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if($users->num_rows == 0): ?>
                    <tr><td colspan="7" style="text-align:center; padding: 50px; color:#64748b; font-size:1.1rem;">ไม่มีข้อมูลผู้ใช้งานที่ตรงกับเงื่อนไขที่ค้นหา</td></tr>
                <?php else: ?>
                    <?php while($u = $users->fetch_assoc()): ?>
                        <tr>
                            <td style="color:#94a3b8;">#<?= $u['id'] ?></td>
                            <td style="font-weight:bold; color:#1e293b; font-family:'Share Tech Mono', monospace; font-size:1.1rem;"><?= htmlspecialchars($u['username']) ?></td>
                            <td style="color:#1e293b;"><?= htmlspecialchars($u['display_name']) ?></td>
                            <td><span class="badge-role role-<?= $u['role'] ?>"><?= strtoupper($u['role']) ?></span></td>
                            <td>
                                <?php if($u['class_name']): ?>
                                    <span class="class-badge">🏫 <?= htmlspecialchars($u['class_name']) ?></span>
                                <?php else: ?>
                                    <span style="color:#cbd5e1;">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:0.85rem; color:#64748b;"><?= date('d/m/Y', strtotime($u['created_at'])) ?></td>
                            <td style="text-align: right;" class="action-links">
                                <a href="edit_user.php?id=<?= $u['id'] ?>" class="link-edit">✏️</a>
                                <a href="user_manager.php?delete_id=<?= $u['id'] ?>" class="link-delete" onclick="return confirm('⚠️ คำเตือน: คุณแน่ใจหรือไม่ว่าต้องการลบผู้ใช้ <?= $u['username'] ?> ?');">🗑️</a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    // ฟังก์ชันสำหรับส่งต่อพารามิเตอร์การค้นหาไปยังไฟล์ Export
    function exportData() {
        const form = document.getElementById('filterForm');
        const role = form.elements['role'].value;
        const classId = form.elements['class_id'].value;
        
        let url = 'export_users.php?';
        let params = [];
        if (role) params.push('role=' + encodeURIComponent(role));
        if (classId) params.push('class_id=' + encodeURIComponent(classId));
        
        url += params.join('&');
        
        // สั่งเบราว์เซอร์ให้ดาวน์โหลด
        window.location.href = url;
    }
</script>

<?php require_once 'footer.php'; ?>