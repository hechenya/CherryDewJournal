<?php
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// 如果是 POST 请求（比如通过表单提交），或者我们可以直接用 GET 带确认参数
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm']) && $_POST['confirm'] === 'yes') {
    // 删除用户
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    if ($stmt->execute()) {
        // 注销 session
        session_destroy();
        // 重定向到登录页，并携带注销成功消息（可以用 session 闪存，这里简单用 GET 参数）
        header("Location: login.php?msg=account_deleted");
        exit;
    } else {
        die('注销失败：' . $conn->error);
    }
    $stmt->close();
}

// 显示确认页面
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>注销账号 - 复式记账本</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body { background: #f4f7fb; font-family: 'Segoe UI', Roboto, system-ui, sans-serif; display: flex; align-items: center; min-height: 100vh; }
        .confirm-card { max-width: 500px; margin: 0 auto; border: none; border-radius: 20px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); }
        .card-header { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: white; border-radius: 20px 20px 0 0 !important; }
        .btn-danger { border-radius: 40px; padding: 0.6rem 2rem; }
        .btn-secondary { border-radius: 40px; padding: 0.6rem 2rem; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card confirm-card">
            <div class="card-header text-center py-3">
                <h3><i class="fas fa-exclamation-triangle me-2"></i>确认注销账号</h3>
            </div>
            <div class="card-body p-4 text-center">
                <p class="lead">您确定要永久注销您的账号吗？</p>
                <p class="text-danger">此操作不可逆，所有数据将被永久删除！</p>
                <form method="post" onsubmit="return confirm('再次确认，确定要注销吗？');">
                    <input type="hidden" name="confirm" value="yes">
                    <button type="submit" class="btn btn-danger me-2"><i class="fas fa-user-slash me-2"></i>确认注销</button>
                    <a href="index.php" class="btn btn-secondary"><i class="fas fa-times me-2"></i>取消</a>
                </form>
            </div>
        </div>
    </div>
</body>
</html>