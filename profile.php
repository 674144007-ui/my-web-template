<?php
// profile.php - หน้าโปรไฟล์สวยงาม + แก้ไขข้อมูล
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'auth.php';
require_once 'db.php';

// บังคับ Login
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$my_id = $_SESSION['user_id'];
$view_id = isset($_GET['id']) ? intval($_GET['id']) : $my_id;
$is_me = ($view_id == $my_id);

// --- ส่วนบันทึกข้อมูล (เฉพาะเจ้าของ) ---
if ($is_me && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dname = trim($_POST['display_name']);
    $bio = trim($_POST['bio']);
    $frame = $_POST['frame'];
    $pic = $_POST['old_pic'];

    // อัปโหลดรูปภาพ
    if (isset($_FILES['pic']) && $_FILES['pic']['error'] == 0) {
        $ext = pathinfo($_FILES['pic']['name'], PATHINFO_EXTENSION);
        $new_pic = "profile_{$my_id}_".time().".$ext";
        if(!is_dir("uploads")) mkdir("uploads");
        move_uploaded_file($_FILES['pic']['tmp_name'], "uploads/$new_pic");
        $pic = $new_pic;
    }
    
    // อัปเดตลง Database
    $stmt = $conn->prepare("UPDATE users SET display_name=?, bio=?, profile_frame=?, profile_pic=? WHERE id=?");
    $stmt->bind_param("ssssi", $dname, $bio, $frame, $pic, $my_id);
    $stmt->execute();
    
    $_SESSION['display_name'] = $dname; // อัปเดต Session
    header("Location: profile.php"); 
    exit;
}

// ดึงข้อมูล User ที่จะดู
$u_query = $conn->query("SELECT * FROM users WHERE id=$view_id");
if ($u_query->num_rows == 0) die("ไม่พบข้อมูลผู้ใช้นี้");
$u = $u_query->fetch_assoc();

// เช็คสถานะเพื่อน (เพื่อเปลี่ยนปุ่ม)
$friend_stat = 'none';
if (!$is_me) {
    $f = $conn->query("SELECT status FROM friends WHERE (user_id_1=$my_id AND user_id_2=$view_id) OR (user_id_1=$view_id AND user_id_2=$my_id)");
    if ($row = $f->fetch_assoc()) $friend_stat = $row['status'];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Profile - <?= htmlspecialchars($u['display_name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700&display=swap" rel="stylesheet">
<style>
body { background: #0f172a; color: white; font-family: 'Sarabun', sans-serif; margin:0; padding:20px; }
.container { max-width:800px; margin:0 auto; }
.card { background: rgba(30,41,59,0.8); backdrop-filter: blur(10px); border-radius: 20px; padding: 40px; text-align: center; border: 1px solid rgba(255,255,255,0.1); position:relative; overflow:hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
.bg-deco { position:absolute; width:100%; height:150px; top:0; left:0; background: linear-gradient(45deg, #3b82f6, #8b5cf6); z-index:0; }

.avatar-box { position:relative; width:140px; height:140px; margin: 60px auto 20px; z-index:1; }
.avatar { width:100%; height:100%; object-fit: cover; border-radius: 50%; border: 4px solid #0f172a; background:#1e293b; }
.frame { position:absolute; top:-10%; left:-10%; width:120%; height:120%; pointer-events:none; z-index:2; }

/* Styles กรอบรูป */
.f-gold { border: 5px solid #fbbf24; border-radius:50%; box-shadow: 0 0 15px #fbbf24; }
.f-fire { border: 5px solid #ef4444; border-radius:50%; box-shadow: 0 0 15px #ef4444; }
.f-neon { border: 5px solid #06b6d4; border-radius:50%; box-shadow: 0 0 15px #06b6d4; }

h1 { margin:0; font-size:2rem; z-index:1; position:relative; }
.role { background:#334155; padding:5px 15px; border-radius:20px; font-size:0.9rem; color:#cbd5e1; display:inline-block; margin-bottom:15px; z-index:1; position:relative; }
.bio { color:#94a3b8; font-style:italic; margin-bottom:30px; z-index:1; position:relative; }

.stats { display:flex; justify-content:center; gap:30px; margin-top:30px; border-top:1px solid #334155; padding-top:20px; }
.stat-box { text-align:center; }
.stat-val { font-size:1.2rem; font-weight:bold; }
.stat-label { font-size:0.8rem; color:#64748b; }

.btn { padding:10px 20px; border-radius:10px; border:none; cursor:pointer; font-weight:bold; text-decoration:none; display:inline-block; transition:0.2s; font-family:inherit; font-size:1rem; }
.btn-blue { background:#3b82f6; color:white; }
.btn-blue:hover { background:#2563eb; transform:translateY(-2px); }
.btn-green { background:#10b981; color:white; }
.btn-outline { border:2px solid #475569; color:#cbd5e1; background:transparent; }

/* Modal Edit */
.modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); z-index:100; align-items:center; justify-content:center; }
.modal-content { background:#1e293b; padding:30px; border-radius:15px; width:90%; max-width:500px; text-align:left; }
input, textarea, select { width:100%; padding:10px; margin:5px 0 15px; background:#0f172a; border:1px solid #334155; color:white; border-radius:8px; box-sizing:border-box; }

.frame-select { display:flex; gap:10px; margin-bottom:15px; }
.fs-opt { width:40px; height:40px; border-radius:50%; cursor:pointer; border:2px solid #334155; display:flex; align-items:center; justify-content:center; font-size:0.7rem; }
.fs-opt.active { border-color:#3b82f6; box-shadow:0 0 10px #3b82f6; }
</style>
</head>
<body>

<div class="container">
    <a href="javascript:history.back()" style="color:#94a3b8; text-decoration:none; display:inline-block; margin-bottom:20px;">⬅ กลับ</a>
    
    <div class="card">
        <div class="bg-deco"></div>
        <div class="avatar-box">
            <img src="<?= (!empty($u['profile_pic']) && file_exists('uploads/'.$u['profile_pic'])) ? 'uploads/'.$u['profile_pic'] : 'https://via.placeholder.com/150?text=User' ?>" class="avatar">
            <?php if(!empty($u['profile_frame']) && $u['profile_frame']!='none'): ?>
                <div class="frame f-<?= $u['profile_frame'] ?>"></div>
            <?php endif; ?>
        </div>
        
        <h1><?= htmlspecialchars($u['display_name']) ?></h1>
        <div class="role"><?= ucfirst($u['role']) ?></div>
        <div class="bio">"<?= !empty($u['bio']) ? htmlspecialchars($u['bio']) : 'ยังไม่มีคำแนะนำตัว' ?>"</div>

        <div style="position:relative; z-index:1;">
            <?php if ($is_me): ?>
                <button class="btn btn-blue" onclick="document.getElementById('editModal').style.display='flex'">✏️ แก้ไขข้อมูล</button>
            <?php else: ?>
                <?php if ($friend_stat == 'accepted'): ?>
                    <a href="chat.php?with=<?= $view_id ?>" class="btn btn-blue">💬 แชทเลย</a>
                    <button class="btn btn-outline" disabled>✅ เป็นเพื่อนแล้ว</button>
                <?php elseif ($friend_stat == 'pending'): ?>
                    <button class="btn btn-outline" disabled>⏳ รอการตอบรับ</button>
                <?php else: ?>
                    <button class="btn btn-green" onclick="addFriend(<?= $view_id ?>)">➕ เพิ่มเพื่อน</button>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="stats">
            <div class="stat-box">
                <div class="stat-val"><?= $u['role']=='student' ? $u['class_level'] : '-' ?></div>
                <div class="stat-label">ห้อง/สังกัด</div>
            </div>
            <div class="stat-box">
                <div class="stat-val"><?= date('d/m/Y', strtotime($u['created_at'])) ?></div>
                <div class="stat-label">วันที่สมัคร</div>
            </div>
        </div>
    </div>
</div>

<div id="editModal" class="modal">
    <div class="modal-content">
        <h2 style="margin-top:0;">แก้ไขโปรไฟล์</h2>
        <form method="post" enctype="multipart/form-data">
            <label>ชื่อที่แสดง (Display Name)</label>
            <input type="text" name="display_name" value="<?= htmlspecialchars($u['display_name']) ?>" required>
            
            <label>คำแนะนำตัว (Bio)</label>
            <textarea name="bio" rows="3"><?= htmlspecialchars($u['bio']) ?></textarea>
            
            <label>เลือกกรอบโปรไฟล์</label>
            <div class="frame-select">
                <input type="hidden" name="frame" id="frameInput" value="<?= $u['profile_frame'] ?>">
                <div class="fs-opt" style="background:#334155" onclick="setFrame('none', this)">None</div>
                <div class="fs-opt" style="background:#fbbf24; color:black;" onclick="setFrame('gold', this)">Gold</div>
                <div class="fs-opt" style="background:#ef4444" onclick="setFrame('fire', this)">Fire</div>
                <div class="fs-opt" style="background:#06b6d4" onclick="setFrame('neon', this)">Neon</div>
            </div>
            
            <label>เปลี่ยนรูปโปรไฟล์</label>
            <input type="hidden" name="old_pic" value="<?= $u['profile_pic'] ?>">
            <input type="file" name="pic" accept="image/*">
            
            <button type="submit" class="btn btn-blue" style="width:100%;">บันทึก</button>
            <button type="button" class="btn btn-outline" style="width:100%; margin-top:10px;" onclick="document.getElementById('editModal').style.display='none'">ยกเลิก</button>
        </form>
    </div>
</div>

<script>
function setFrame(val, el) {
    document.getElementById('frameInput').value = val;
    document.querySelectorAll('.fs-opt').forEach(e => e.classList.remove('active'));
    el.classList.add('active');
}
function addFriend(id) {
    if(confirm('ส่งคำขอเป็นเพื่อน?')) {
        fetch('social_api.php?action=add_friend&id='+id).then(r=>r.json()).then(d=>{
            alert(d.msg); location.reload();
        });
    }
}
</script>

</body>
</html>