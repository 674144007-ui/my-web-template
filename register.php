<?php
require_once "db.php";

$username = $_POST["username"];
$password = password_hash($_POST["password"], PASSWORD_DEFAULT);
$role = $_POST["role"]; // student / teacher

$stmt = $conn->prepare("
    INSERT INTO users (username, password, role)
    VALUES (?, ?, ?)
");

$stmt->bind_param("sss", $username, $password, $role);
$stmt->execute();

echo json_encode(["status"=>"success"]);
