<?php
// view_user_logs.php - ระบบตรวจสอบประวัติการใช้งาน (Audit Logs) - Phase 4
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';

// อนุญาตเฉพาะ Developer เท่านั้นที่ส่องประวัติคนอื่นได้
requireRole(['developer']);

$target_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($target_id <= 0) {
    die("❌ ไม่พบรหัสผู้ใช้งาน <br><a href='user_manager.php'>กลับไปหน้าจัดการผู้ใช้</a>");
}

// -----------------------------
// 1. ดึงข้อมูลพื้นฐานของผู้ใช้งานเป้าหมาย
// -----------------------------
$user_data = null;
$stmt_user = $conn->prepare("SELECT username, display_name, role, is_deleted FROM users WHERE id = ?");
$stmt_user->bind_param("i", $target_id);
$stmt_user->execute();
$res_user = $stmt_user->get_result();

if ($res_user->num_rows > 0) {
    $user_data = $res_user->fetch_assoc();
} else {
    die("❌ ไม่พบข้อมูลบัญชีผู้ใช้งานนี้ <br><a href='user_manager.php'>กลับไปหน้าจัดการผู้ใช้</a>");
}
$stmt_user->close();

// -----------------------------
// 2. ระบบแบ่งหน้าสำหรับ Logs
// -----------------------------
$limit = 50; // แสดง 50 รายการต่อหน้า
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// หาจำนวน Logs ทั้งหมดของคนนี้
$stmt_count = $conn->prepare("SELECT COUNT(id) AS total FROM system_logs WHERE user_id = ?");
$stmt_count->bind_param("i", $target_id);
$stmt_count->execute();
$total_rows = $stmt_count->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);
$stmt_count->close();

// -----------------------------
// 3. ดึงประวัติการใช้งาน (Logs)
// -----------------------------
$logs = [];
$stmt_logs = $conn->prepare("
    SELECT action, details, ip_address, created_at 
    FROM system_logs 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT ? OFFSET ?
");
$stmt_logs->bind_param("iii", $target_id, $limit, $offset);
$stmt_logs->execute();
$res_logs = $stmt_logs->get_result();
while ($row = $res_logs->fetch_assoc()) {
    $logs[] = $row;
}
$stmt_logs->close();

$page_title = "ประวัติการใช้งาน - " . $user_data['username'];
require_once 'header.php';

// ฟังก์ชันสำหรับเลือกสีป้าย (Badge) ตามประเภท Action
function getActionBadge($action) {
    $action_upper = strtoupper($action);
    if (strpos($action_upper, 'LOGIN_SUCCESS') !== false) return "<span class='log-badge badge-success'>LOGIN SUCCESS</span>";
    if (strpos($action_upper, 'LOGIN_FAILED') !== false) return "<span class='log-badge badge-danger'>LOGIN FAILED</span>";
    if (strpos($action_upper, 'DELETE') !== false || strpos($action_upper, 'SUSPEND') !== false) return "<span class='log-badge badge-danger'>$action_upper</span>";
    if (strpos($action_upper, 'CREATE') !== false || strpos($action_upper, 'ADD') !== false) return "<span class='log-badge badge-primary'>$action_upper</span>";
    if (strpos($action_upper, 'UPDATE') !== false || strpos($action_upper, 'EDIT') !== false || strpos($action_upper, 'RESTORE') !== false) return "<span class='log-badge badge-warning'>$action_upper</span>";
    if (strpos($action_upper, 'LAB_ACCIDENT') !== false) return "<span class='log-badge badge-danger'>⚠️ LAB ACCIDENT</span>";
    return "<span class='log-badge badge-secondary'>$action_upper</span>";
}
?>

<style>
    .profile-header {
        background: white; padding: 25px; border-radius: 16px; 
        box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 25px;
        display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;
    }
    .profile-info h2 { margin: 0; color: #0f172a; }
    .profile-info p { margin: 5px 0 0 0; color: #64748b; font-size: 1.1rem; }
    
    .role-tag {
        padding: 5px 12px; border-radius: 20px; font-size: 0.9em; font-weight: bold;
        background: #e2e8f0; color: #334155; display: inline-block; margin-top: 5px;
    }

    .timeline-card {
        background: white; padding: 25px; border-radius: 16px; 
        box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    }

    .log-table { width: 100%; border-collapse: collapse; }
    .log-table th, .log-table td { padding: 15px; border-bottom: 1px solid #f1f5f9; text-align: left; vertical-align: top; }
    .log-table th { background: #f8fafc; color: #475569; font-weight: bold; border-bottom: 2px solid #e2e8f0; }
    .log-table tr:hover { background: #f8fafc; }

    .log-badge { padding: 4px 10px; border-radius: 6px; font-size: 0.8em; font-weight: bold; }
    .badge-success { background: #dcfce7; color: #166534; }
    .badge-danger { background: #fee2e2; color: #991b1b; }
    .badge-warning { background: #fef3c7; color: #b45309; }
    .badge-primary { background: #dbeafe; color: #1e40af; }
    .badge-secondary { background: #f1f5f9; color: #475569; }

    .ip-text { font-family: monospace; color: #94a3b8; font-size: 0.9em; }

    .pagination { display: flex; justify-content: center; gap: 5px; margin-top: 20px; }
    .pagination a { padding: 8px 15px; background: white; border: 1px solid #cbd5e1; color: #334155; text-decoration: none; border-radius: 6px; font-weight: bold; }
    .pagination a.active { background: #3b82f6; color: white; border-color: #3b82f6; }
    .pagination a:hover:not(.active) { background: #f1f5f9; }
</style>

<div style="margin-bottom: 20px;">
    <a href="user_manager.php" style="color: #64748b; text-decoration: none; font-weight: bold;">⬅ กลับหน้ารายชื่อผู้ใช้</a>
</div>

<div class="profile-header">
    <div class="profile-info">
        <h2>สอดแนมประวัติ: <?= h($user_data['display_name']) ?></h2>
        <p>Username: <strong><?= h($user_data['username']) ?></strong></p>
        <span class="role-tag">ROLE: <?= strtoupper(h($user_data['role'])) ?></span>
        <?php if ($user_data['is_deleted'] == 1): ?>
            <span class="role-tag" style="background: #fee2e2; color: #991b1b;">🚫 บัญชีถูกระงับ</span>
        <?php endif; ?>
    </div>
    <div style="text-align: right;">
        <div style="font-size: 2.5rem; font-weight: bold; color: #3b82f6;"><?= number_format($total_rows) ?></div>
        <div style="color: #64748b; font-size: 0.9em;">รายการทั้งหมด (All Logs)</div>
    </div>
</div>

<div class="timeline-card">
    <h3 style="margin-top: 0; color: #0f172a; border-bottom: 2px solid #f1f5f9; padding-bottom: 15px;">
        🕒 บันทึกกิจกรรม (Audit Trail)
    </h3>

    <?php if (count($logs) > 0): ?>
        <table class="log-table">
            <thead>
                <tr>
                    <th width="15%">วันเวลา (Date/Time)</th>
                    <th width="20%">การกระทำ (Action)</th>
                    <th width="50%">รายละเอียด (Details)</th>
                    <th width="15%">IP Address</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td style="color: #64748b; font-size: 0.9em;">
                            <?= date('d/m/Y', strtotime($log['created_at'])) ?><br>
                            <span style="color: #0f172a; font-weight: bold;"><?= date('H:i:s', strtotime($log['created_at'])) ?></span>
                        </td>
                        <td><?= getActionBadge($log['action']) ?></td>
                        <td style="color: #334155; line-height: 1.5;"><?= h($log['details']) ?></td>
                        <td class="ip-text"><?= h($log['ip_address']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php
            $base_url = "view_user_logs.php?id={$target_id}";
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

    <?php else: ?>
        <div style="text-align: center; color: #94a3b8; padding: 40px 0;">
            <span style="font-size: 3rem;">📭</span><br>
            ยังไม่มีประวัติการทำกิจกรรมใดๆ ของผู้ใช้งานนี้
        </div>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>