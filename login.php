<?php
require_once 'db.php';

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $account = trim($_POST['account'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($account) || empty($password)) {
        $error = '账号和密码不能为空';
    } else {
        $stmt = $conn->prepare("SELECT id, password FROM users WHERE username = ? OR email = ? OR phone = ?");
        $stmt->bind_param("sss", $account, $account, $account);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            header("Location: index.php");
            exit;
        } else {
            $error = '账号或密码错误';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 - 复式记账本</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body { background: #f4f7fb; font-family: 'Segoe UI', Roboto, system-ui, sans-serif; display: flex; align-items: center; min-height: 100vh; }
        .login-card { max-width: 400px; margin: 0 auto; border: none; border-radius: 20px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); }
        .card-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 20px 20px 0 0 !important; }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; border-radius: 40px; padding: 0.6rem 2rem; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card login-card">
            <div class="card-header text-center py-3">
                <h3><i class="fas fa-book-open me-2"></i>复式记账本</h3>
                <span>登录您的账户</span>
            </div>
            <div class="card-body p-4">
                <?php if (isset($_GET['msg']) && $_GET['msg'] == 'account_deleted'): ?>
                    <div class="alert alert-info">您的账号已成功注销，感谢使用。</div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-user me-2"></i>账号 (用户名/邮箱/手机号)</label>
                        <input type="text" name="account" class="form-control" required autofocus value="<?= htmlspecialchars($_POST['account'] ?? '') ?>">
                    </div>
                    <div class="mb-4">
                        <label class="form-label"><i class="fas fa-lock me-2"></i>密码</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 py-2">登录</button>
                </form>
                <div class="text-center mt-3">
                    <a href="register.php">还没有账号？立即注册</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>