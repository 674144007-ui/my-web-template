<?php
// line_notify.php - ฟังก์ชันสำหรับส่งข้อความผ่าน LINE Notify

/**
 * ฟังก์ชันส่งข้อความเข้า LINE
 * @param string $message ข้อความที่ต้องการส่ง
 * @param string $token LINE Notify Token ของกลุ่มหรือบุคคลนั้นๆ
 * @return bool ส่งสำเร็จหรือไม่
 */
function sendLineNotify($message, $token) {
    $url = "https://notify-api.line.me/api/notify";
    
    $data = array('message' => $message);
    $data = http_build_query($data, '', '&');
    
    $headerOptions = array(
        'http' => array(
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n"
                       . "Authorization: Bearer " . $token . "\r\n"
                       . "Content-Length: " . strlen($data) . "\r\n",
            'content' => $data
        )
    );
    
    $context = stream_context_create($headerOptions);
    $result = file_get_contents($url, false, $context);
    
    if ($result === FALSE) {
        error_log("LINE Notify Error: ไม่สามารถส่งข้อความได้");
        return false;
    }
    
    $res = json_decode($result);
    return $res->status == 200;
}
?>