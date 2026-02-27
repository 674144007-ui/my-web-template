<?php
// config.php - ไฟล์ตั้งค่าหลักของระบบ

// ตั้งค่าโซนเวลาของไทย
date_default_timezone_set('Asia/Bangkok');

// URL หลักของเว็บไซต์ (เปลี่ยนเป็น URL จริงเมื่อขึ้นโฮสต์ เช่น https://yourdomain.com/)
define('BASE_URL', 'http://localhost/classroom_mgmt/');

// ตั้งค่าเกี่ยวกับไฟล์อัปโหลด
define('UPLOAD_DIR', __DIR__ . '/uploads/'); // พาธโฟลเดอร์สำหรับเก็บไฟล์
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // ขนาดไฟล์สูงสุด 5 MB

// สร้างโฟลเดอร์ uploads อัตโนมัติหากยังไม่มี
if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
    
    // สร้างไฟล์ .htaccess เพื่อป้องกันการรันสคริปต์ PHP ในโฟลเดอร์ uploads (สำคัญมาก!)
    $htaccess_content = "<FilesMatch \"\.(php|php[3-7]|phtml|sh|exe|pl|cgi)\$\">\nOrder allow,deny\nDeny from all\n</FilesMatch>\nOptions -Indexes";
    file_put_contents(UPLOAD_DIR . '.htaccess', $htaccess_content);
}
?>