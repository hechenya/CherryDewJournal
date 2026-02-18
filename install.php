<?php
require_once 'db.php';

// 如果已有用户，则跳转到登录页
$result = $conn->query("SELECT COUNT(*) as cnt FROM users");
$row = $result->fetch_assoc();
if ($row['cnt'] > 0) {
    header("Location: login.php");
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = '用户名和密码不能为空';
    } elseif ($password !== $confirm) {
        $error = '两次密码不一致';
    } elseif (strlen($password) < 6) {
        $error = '密码至少6位';
    } else {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (username, password, is_admin) VALUES (?, ?, 1)");
        $stmt->bind_param("ss", $username, $hashed);
        if ($stmt->execute()) {
            $new_user_id = $stmt->insert_id;
            // 为新创建的管理员用户插入默认账户
            $defaultAccounts = [
                ['现金', 'asset', NULL, '手头现金', 1],
                ['银行卡', 'asset', NULL, '储蓄卡/信用卡', 1],
                ['支付宝', 'asset', NULL, '支付宝余额', 1],
                ['微信', 'asset', NULL, '微信零钱', 1],
                ['工资收入', 'income', NULL, '工作所得', 0],
                ['其他收入', 'income', NULL, '兼职、红包等', 0],
                ['餐饮', 'expense', NULL, '三餐、零食', 0],
                ['购物', 'expense', NULL, '服装、日用品', 0],
                ['交通', 'expense', NULL, '公交、地铁、加油', 0],
                ['娱乐', 'expense', NULL, '电影、游戏', 0],
                ['住房', 'expense', NULL, '房租、水电', 0],
                ['医疗', 'expense', NULL, '药品、体检', 0],
            ];
            $stmt2 = $conn->prepare("INSERT INTO accounts (user_id, name, type, parent_id, description, is_default) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($defaultAccounts as $acc) {
                $parent_id = $acc[2];
                $stmt2->bind_param("issisi", $new_user_id, $acc[0], $acc[1], $parent_id, $acc[3], $acc[4]);
                $stmt2->execute();
            }
            $stmt2->close();
            $success = '管理员账号创建成功！<a href="login.php">点击登录</a>';
        } else {
            $error = '创建失败：' . $conn->error;
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系统安装 - 复式记账本</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body { background: #f4f7fb; font-family: 'Segoe UI', Roboto, system-ui, sans-serif; display: flex; align-items: center; min-height: 100vh; }
        .install-card { max-width: 450px; margin: 0 auto; border: none; border-radius: 20px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); }
        .card-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 20px 20px 0 0 !important; }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; border-radius: 40px; padding: 0.6rem 2rem; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card install-card">
            <div class="card-header text-center py-3">
                <h3><i class="fas fa-tools me-2"></i>系统安装</h3>
                <span>创建管理员账号</span>
            </div>
            <div class="card-body p-4">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php elseif ($success): ?>
                    <div class="alert alert-success"><?= $success ?></div>
                <?php else: ?>
                    <p class="text-muted">欢迎使用复式记账系统，请设置管理员账号。该账号将拥有后台管理权限，同时也是普通用户。</p>
                <?php endif; ?>
                <?php if (!$success): ?>
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-user me-2"></i>管理员用户名</label>
                        <input type="text" name="username" class="form-control" required autofocus value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-lock me-2"></i>密码 (至少6位)</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label"><i class="fas fa-check-circle me-2"></i>确认密码</label>
                        <input type="password" name="confirm_password" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 py-2">立即安装</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>