<?php
// inventory.php - หน้าตรวจสอบรายชื่อสารเคมีทั้งหมด
require_once 'db.php';

// ดึงข้อมูลทั้งหมด
$sql = "SELECT * FROM chemicals ORDER BY id ASC";
$result = $pdo->query($sql);
$total = $result->rowCount();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chemical Inventory (<?php echo $total; ?> Items)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; background-color: #f8f9fa; padding: 20px; }
        .color-box {
            width: 30px; height: 30px;
            border-radius: 50%;
            border: 1px solid #ccc;
            box-shadow: 1px 1px 3px rgba(0,0,0,0.2);
            display: inline-block;
            vertical-align: middle;
        }
        .table-hover tbody tr:hover { background-color: #e9ecef; }
        .badge-type { min-width: 80px; }
    </style>
</head>
<body>

<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>📦 คลังสารเคมี (<?php echo $total; ?> รายการ)</h1>
        <a href="index.html" class="btn btn-primary">⬅️ กลับไปหน้าทดลอง</a>
    </div>

    <div class="card mb-4 shadow-sm">
        <div class="card-body">
            <input type="text" id="searchInput" class="form-control form-control-lg" placeholder="🔍 พิมพ์ชื่อสารเคมีเพื่อค้นหา...">
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0" id="chemTable">
                    <thead class="table-dark">
                        <tr>
                            <th scope="col">ID</th>
                            <th scope="col">สี (Color)</th>
                            <th scope="col">ชื่อสารเคมี (Name)</th>
                            <th scope="col">ประเภท (Type)</th>
                            <th scope="col">สถานะ (State)</th>
                            <th scope="col">ความพิษ (Toxicity)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($total > 0): ?>
                            <?php while($row = $result->fetch()): ?>
                                <tr>
                                    <td><?php echo $row['id']; ?></td>
                                    <td>
                                        <div class="color-box" style="background-color: <?php echo $row['color_neutral']; ?>;" title="<?php echo $row['color_neutral']; ?>"></div>
                                        <small class="text-muted ms-1"><?php echo $row['color_neutral']; ?></small>
                                    </td>
                                    <td class="fw-bold"><?php echo htmlspecialchars($row['name']); ?></td>
                                    <td>
                                        <span class="badge bg-secondary badge-type"><?php echo ucfirst($row['type']); ?></span>
                                    </td>
                                    <td>
                                        <?php 
                                            $stateBadge = 'bg-info';
                                            if($row['state']=='solid') $stateBadge = 'bg-secondary';
                                            if($row['state']=='gas') $stateBadge = 'bg-warning text-dark';
                                        ?>
                                        <span class="badge <?php echo $stateBadge; ?>"><?php echo ucfirst($row['state']); ?></span>
                                    </td>
                                    <td>
                                        <?php if($row['toxicity'] > 50): ?>
                                            <span class="text-danger fw-bold">☠️ <?php echo $row['toxicity']; ?></span>
                                        <?php elseif($row['toxicity'] > 0): ?>
                                            <span class="text-warning fw-bold">⚠️ <?php echo $row['toxicity']; ?></span>
                                        <?php else: ?>
                                            <span class="text-success">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center p-5">ไม่พบข้อมูลสารเคมี</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    // ระบบค้นหาแบบ Real-time
    document.getElementById('searchInput').addEventListener('keyup', function() {
        let filter = this.value.toLowerCase();
        let rows = document.querySelectorAll('#chemTable tbody tr');

        rows.forEach(row => {
            let text = row.innerText.toLowerCase();
            row.style.display = text.includes(filter) ? '' : 'none';
        });
    });
</script>

</body>
</html>
<?php /* PDO ไม่ต้องปิด connection */ 
    } catch (\PDOException $e) {
        error_log('[db_error] ' . $e->getMessage() . ' in ' . __FILE__ . ':' . __LINE__);
        if (!headers_sent()) {
            http_response_code(500);
        }
        $is_dev = defined('APP_ENV') && APP_ENV === 'development' && defined('APP_DEBUG') && APP_DEBUG;
        $msg = $is_dev ? htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') : 'กรุณาลองใหม่อีกครั้ง';
        die('<div style="font-family:sans-serif;text-align:center;padding:3rem">'
          . '<h3 style="color:#ef4444">เกิดข้อผิดพลาดในระบบฐานข้อมูล</h3>'
          . '<p style="color:#6b7280">' . $msg . '</p>'
          . '<a href="index.php" style="color:#3b82f6">← กลับหน้าหลัก</a>'
          . '</div>');
    }

?>
try {
