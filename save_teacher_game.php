<?php
session_start();
require_once "db.php";
header("Content-Type: application/json");

// ตรวจสอบ session
if (!isset($_SESSION["id"])) {
    echo json_encode(["status"=>"error","message"=>"Not logged in"]);
    exit;
}

$teacher_id = $_SESSION["id"];
$title = $_POST["title"] ?? "";
$instructions = $_POST["instructions"] ?? "";
$allowed = $_POST["allowed_chemicals"] ?? "";
$pass = $_POST["pass_condition"] ?? "";

// ตรวจสอบ input
if (empty($title) || empty($instructions)) {
    echo json_encode(["status"=>"error","message"=>"Missing required fields"]);
    exit;
}

$stmt = $conn->prepare("
    INSERT INTO teacher_games
    (teacher_id, title, instructions, allowed_chemicals, pass_condition)
    VALUES (?,?,?,?,?)
");

$stmt->bind_param(
    "issss",
    $teacher_id, $title, $instructions, $allowed, $pass
);

if ($stmt->execute()) {
    echo json_encode(["status"=>"saved"]);
} else {
    echo json_encode(["status"=>"error","message"=>$stmt->error]);
}
?>
