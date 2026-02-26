<?php
// chat.php - แชทเรียลไทม์
session_start();
require_once 'auth.php';
require_once 'db.php';
if (!isLoggedIn()) { header("Location: index.php"); exit; }

$my_id = $_SESSION['user_id'];
$target_id = isset($_GET['with']) ? intval($_GET['with']) : 0;

// ดึงรายชื่อเพื่อนที่สถานะ accepted เท่านั้น
$friends = $conn->query("SELECT u.id, u.display_name, u.profile_pic FROM friends f JOIN users u ON (f.user_id_1=u.id OR f.user_id_2=u.id) WHERE (f.user_id_1=$my_id OR f.user_id_2=$my_id) AND f.status='accepted' AND u.id!=$my_id");

$partner = null;
if ($target_id) $partner = $conn->query("SELECT * FROM users WHERE id=$target_id")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Chat</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700&display=swap" rel="stylesheet">
<style>
body { margin:0; font-family:'Sarabun',sans-serif; background:#0f172a; color:white; height:100vh; display:flex; overflow:hidden; }
.sidebar { width:280px; background:#1e293b; border-right:1px solid #334155; display:flex; flex-direction:column; }
.chat-area { flex:1; display:flex; flex-direction:column; background:#0f172a; }
.f-item { padding:15px; display:flex; align-items:center; gap:10px; cursor:pointer; border-bottom:1px solid rgba(255,255,255,0.05); text-decoration:none; color:white; }
.f-item:hover, .f-item.active { background:#334155; }
.avatar { width:40px; height:40px; border-radius:50%; object-fit:cover; background:#475569; }
.header { padding:15px; background:#1e293b; border-bottom:1px solid #334155; font-weight:bold; }
.msgs { flex:1; overflow-y:auto; padding:20px; display:flex; flex-direction:column; gap:10px; }
.msg { max-width:70%; padding:10px 15px; border-radius:15px; font-size:0.95rem; word-wrap:break-word; }
.me { align-self:flex-end; background:#3b82f6; border-bottom-right-radius:2px; }
.them { align-self:flex-start; background:#334155; border-bottom-left-radius:2px; }
.input-box { padding:15px; background:#1e293b; display:flex; gap:10px; }
input { flex:1; padding:10px; border-radius:20px; border:none; background:#0f172a; color:white; outline:none; }
button { padding:10px 20px; border-radius:20px; border:none; background:#3b82f6; color:white; cursor:pointer; font-weight:bold; }
.file-link { display:block; background:rgba(0,0,0,0.2); padding:5px; margin-top:5px; border-radius:5px; color:#cbd5e1; text-decoration:none; font-size:0.8rem; }
</style>
</head>
<body>

<div class="sidebar">
    <div class="header">💬 เพื่อนของฉัน</div>
    <div style="flex:1; overflow-y:auto;">
        <?php if($friends->num_rows > 0): ?>
            <?php while($f = $friends->fetch_assoc()): ?>
                <a href="?with=<?= $f['id'] ?>" class="f-item <?= $target_id==$f['id']?'active':'' ?>">
                    <img src="<?= (!empty($f['profile_pic']) && file_exists('uploads/'.$f['profile_pic'])) ? 'uploads/'.$f['profile_pic'] : 'https://via.placeholder.com/40' ?>" class="avatar">
                    <span><?= htmlspecialchars($f['display_name']) ?></span>
                </a>
            <?php endwhile; ?>
        <?php else: ?>
            <div style="padding:20px; text-align:center; color:#64748b;">ยังไม่มีเพื่อน</div>
        <?php endif; ?>
    </div>
    <?php 
        $role = $_SESSION['role'];
        $dash = ($role=='developer'||$role=='admin') ? 'dashboard_dev.php' : ($role=='teacher' ? 'dashboard_teacher.php' : ($role=='parent' ? 'dashboard_parent.php' : 'dashboard_student.php'));
    ?>
    <a href="<?= $dash ?>" class="f-item" style="justify-content:center; background:#0f172a; border-top:1px solid #334155;">⬅ กลับหน้าหลัก</a>
</div>

<div class="chat-area">
    <?php if($partner): ?>
        <div class="header">
            <img src="<?= (!empty($partner['profile_pic']) && file_exists('uploads/'.$partner['profile_pic'])) ? 'uploads/'.$partner['profile_pic'] : 'https://via.placeholder.com/40' ?>" class="avatar" style="width:30px; height:30px; vertical-align:middle; margin-right:10px;">
            <?= htmlspecialchars($partner['display_name']) ?>
        </div>
        <div class="msgs" id="msgBox"></div>
        <div class="input-box">
            <input type="file" id="file" style="display:none" onchange="sendFile()">
            <button onclick="document.getElementById('file').click()" style="background:#475569;">📎</button>
            <input type="text" id="txt" placeholder="พิมพ์ข้อความ..." onkeypress="if(event.key=='Enter') send()">
            <button onclick="send()">➤</button>
        </div>
    <?php else: ?>
        <div style="margin:auto; color:#64748b; text-align:center;">
            <h3>👋 เริ่มต้นการสนทนา</h3>
            <p>เลือกเพื่อนจากเมนูฝั่งซ้ายเพื่อเริ่มแชท</p>
        </div>
    <?php endif; ?>
</div>

<script>
const tid = <?= $target_id ?>;
const mid = <?= $my_id ?>;
if(tid > 0) {
    // 🔴 FIX: เพิ่มเวลาหน่วง (Polling) เป็น 5 วินาที ลดภาระเซิร์ฟเวอร์ร่วง
    setInterval(loadMsgs, 5000);
    loadMsgs();
}

function loadMsgs() {
    fetch(`social_api.php?action=get_messages&partner_id=${tid}`)
    .then(r=>r.json()).then(d=>{
        const box = document.getElementById('msgBox');
        box.innerHTML = ''; // Clear ก่อนเพิ่มใหม่
        d.forEach(m => {
            // 🔴 FIX: อุดช่องโหว่ XSS (Cross-Site Scripting) 
            // โดยการสร้าง Element และใช้ textContent แทนการนำไปต่อ String
            const msgDiv = document.createElement('div');
            msgDiv.className = `msg ${m.sender_id == mid ? 'me' : 'them'}`;
            msgDiv.textContent = m.message; // ป้องกัน Script ทำงาน
            
            if(m.file_path) {
                const a = document.createElement('a');
                a.href = `uploads/${m.file_path}`;
                a.target = '_blank';
                a.className = 'file-link';
                a.textContent = `📎 ${m.file_name}`;
                
                msgDiv.appendChild(document.createElement('br'));
                msgDiv.appendChild(a);
            }
            
            box.appendChild(msgDiv);
        });
    });
}

function send() {
    const txt = document.getElementById('txt').value;
    if(!txt) return;
    const fd = new FormData();
    fd.append('action','send_message'); fd.append('receiver_id',tid); fd.append('message',txt);
    fetch('social_api.php',{method:'POST',body:fd}).then(()=>{
        document.getElementById('txt').value=''; loadMsgs();
    });
}

function sendFile() {
    const f = document.getElementById('file').files[0];
    if(!f) return;
    const fd = new FormData();
    fd.append('action','send_message'); fd.append('receiver_id',tid); 
    fd.append('message','ส่งไฟล์: '+f.name); fd.append('file',f);
    fetch('social_api.php',{method:'POST',body:fd}).then(()=>{
        document.getElementById('file').value=''; loadMsgs();
    });
}
</script>
</body>
</html>