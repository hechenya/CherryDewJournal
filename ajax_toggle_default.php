<?php
require_once 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => '未登录']);
    exit;
}
$user_id = $_SESSION['user_id'];

$id = intval($_POST['id'] ?? 0);
$current = intval($_POST['current'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'message' => '参数错误']);
    exit;
}

$new = $current ? 0 : 1;
$stmt = $conn->prepare("UPDATE accounts SET is_default = ? WHERE id = ? AND user_id = ?");
$stmt->bind_param("iii", $new, $id, $user_id);

if ($stmt->execute() && $stmt->affected_rows > 0) {
    echo json_encode(['success' => true, 'new_value' => $new]);
} else {
    echo json_encode(['success' => false, 'message' => '数据库更新失败']);
}
$stmt->close();
?>