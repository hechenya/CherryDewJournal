<?php
require_once 'db.php';

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

// 读取系统配置
$settings = [];
$res = $conn->query("SELECT `key`, `value` FROM settings");
while ($row = $res->fetch_assoc()) {
    $settings[$row['key']] = $row['value'];
}
$require_email = $settings['require_email'] ?? '0';
$require_phone = $settings['require_phone'] ?? '0';
$allow_registration = $settings['allow_registration'] ?? '1';

if ($allow_registration != '1') {
    die('注册已关闭，请联系管理员。');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $code_email = trim($_POST['code_email'] ?? '');
    $code_phone = trim($_POST['code_phone'] ?? '');

    // 基础验证
    if (empty($username) || empty($password)) {
        $error = '用户名和密码不能为空';
    } elseif ($password !== $confirm) {
        $error = '两次密码不一致';
    } elseif (strlen($password) < 6) {
        $error = '密码至少6位';
    }

    // 邮箱强制验证
    if (empty($error) && $require_email == '1') {
        if (empty($email)) {
            $error = '邮箱不能为空';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = '邮箱格式不正确';
        } elseif (empty($code_email)) {
            $error = '邮箱验证码不能为空';
        } else {
            $stmt = $conn->prepare("SELECT id FROM verification_codes WHERE contact = ? AND code = ? AND type = 'email' AND is_used = 0 AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1");
            $stmt->bind_param("ss", $email, $code_email);
            $stmt->execute();
            $result = $stmt->get_result();
            $vc = $result->fetch_assoc();
            if (!$vc) {
                $error = '邮箱验证码无效或已过期';
            } else {
                $update = $conn->prepare("UPDATE verification_codes SET is_used = 1 WHERE id = ?");
                $update->bind_param("i", $vc['id']);
                $update->execute();
                $update->close();
            }
            $stmt->close();
        }
    }

    // 手机强制验证
    if (empty($error) && $require_phone == '1') {
        if (empty($phone)) {
            $error = '手机号不能为空';
        } elseif (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            $error = '手机号格式不正确';
        } elseif (empty($code_phone)) {
            $error = '手机验证码不能为空';
        } else {
            $stmt = $conn->prepare("SELECT id FROM verification_codes WHERE contact = ? AND code = ? AND type = 'sms' AND is_used = 0 AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1");
            $stmt->bind_param("ss", $phone, $code_phone);
            $stmt->execute();
            $result = $stmt->get_result();
            $vc = $result->fetch_assoc();
            if (!$vc) {
                $error = '手机验证码无效或已过期';
            } else {
                $update = $conn->prepare("UPDATE verification_codes SET is_used = 1 WHERE id = ?");
                $update->bind_param("i", $vc['id']);
                $update->execute();
                $update->close();
            }
            $stmt->close();
        }
    }

    if (empty($error)) {
        // 检查用户名是否已存在
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $error = '用户名已存在';
        }
        $stmt->close();

        // 如果提供了邮箱，检查是否唯一
        if (empty($error) && !empty($email)) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $error = '该邮箱已被注册';
            }
            $stmt->close();
        }

        // 如果提供了手机，检查是否唯一
        if (empty($error) && !empty($phone)) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE phone = ?");
            $stmt->bind_param("s", $phone);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $error = '该手机号已被注册';
            }
            $stmt->close();
        }

        if (empty($error)) {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, password, email, phone, is_admin) VALUES (?, ?, ?, ?, 0)");
            $stmt->bind_param("ssss", $username, $hashed, $email, $phone);
            if ($stmt->execute()) {
                $new_user_id = $stmt->insert_id;
                // 为新用户插入默认账户分类
                $defaultAccounts = [
                    ['现金', 'asset', NULL, '手头现金'],
                    ['银行卡', 'asset', NULL, '储蓄卡/信用卡'],
                    ['工资收入', 'income', NULL, '工作所得'],
                    ['其他收入', 'income', NULL, '兼职、红包等'],
                    ['餐饮', 'expense', NULL, '三餐、零食'],
                    ['购物', 'expense', NULL, '服装、日用品'],
                    ['交通', 'expense', NULL, '公交、地铁、加油'],
                    ['娱乐', 'expense', NULL, '电影、游戏'],
                    ['住房', 'expense', NULL, '房租、水电'],
                    ['医疗', 'expense', NULL, '药品、体检'],
                ];
                $stmt2 = $conn->prepare("INSERT INTO accounts (user_id, name, type, parent_id, description) VALUES (?, ?, ?, ?, ?)");
                foreach ($defaultAccounts as $acc) {
                    $parent_id = $acc[2];
                    $stmt2->bind_param("issis", $new_user_id, $acc[0], $acc[1], $parent_id, $acc[3]);
                    $stmt2->execute();
                }
                $stmt2->close();
                $success = '注册成功！<a href="login.php">点击登录</a>';
            } else {
                $error = '注册失败，请稍后重试';
            }
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>注册 - 复式记账本</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body { background: #f4f7fb; font-family: 'Segoe UI', Roboto, system-ui, sans-serif; display: flex; align-items: center; min-height: 100vh; }
        .register-card { max-width: 500px; margin: 0 auto; border: none; border-radius: 20px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); }
        .card-header { background: linear-gradient(135deg, #f6d365 0%, #fda085 100%); color: #2d3748; border-radius: 20px 20px 0 0 !important; }
        .btn-primary { background: linear-gradient(135deg, #f6d365 0%, #fda085 100%); border: none; color: #2d3748; border-radius: 40px; padding: 0.6rem 2rem; }
        .verification-btn { border-radius: 30px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card register-card">
            <div class="card-header text-center py-3">
                <h3><i class="fas fa-user-plus me-2"></i>用户注册</h3>
            </div>
            <div class="card-body p-4">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php elseif ($success): ?>
                    <div class="alert alert-success"><?= $success ?></div>
                <?php endif; ?>

                <?php if (!$success): ?>
                <form method="post" id="registerForm">
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-user me-2"></i>用户名</label>
                        <input type="text" name="username" class="form-control" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-lock me-2"></i>密码 (至少6位)</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-check-circle me-2"></i>确认密码</label>
                        <input type="password" name="confirm_password" class="form-control" required>
                    </div>

                    <?php if ($require_email == '1' || $require_phone == '1'): ?>
                        <hr>
                        <p class="text-muted">请完成以下验证：</p>
                    <?php endif; ?>

                    <?php if ($require_email == '1'): ?>
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-envelope me-2"></i>邮箱</label>
                        <div class="input-group">
                            <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                            <button type="button" class="btn btn-outline-primary verification-btn" data-type="email" data-target="email">发送验证码</button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">邮箱验证码</label>
                        <input type="text" name="code_email" class="form-control" maxlength="6" required>
                    </div>
                    <?php endif; ?>

                    <?php if ($require_phone == '1'): ?>
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-phone me-2"></i>手机号</label>
                        <div class="input-group">
                            <input type="tel" name="phone" class="form-control" required value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                            <button type="button" class="btn btn-outline-primary verification-btn" data-type="sms" data-target="phone">发送验证码</button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">手机验证码</label>
                        <input type="text" name="code_phone" class="form-control" maxlength="6" required>
                    </div>
                    <?php endif; ?>

                    <?php if ($require_email != '1' && $require_phone != '1'): ?>
                        <hr>
                        <p class="text-muted">以下为选填，方便找回密码：</p>
                        <div class="mb-3">
                            <label class="form-label">邮箱</label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">手机号</label>
                            <input type="tel" name="phone" class="form-control" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                        </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary w-100 py-2 mt-3">注册</button>
                </form>
                <div class="text-center mt-3">
                    <a href="login.php">已有账号？登录</a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        $(document).ready(function() {
            $('.verification-btn').click(function() {
                var btn = $(this);
                var type = btn.data('type');
                var target = btn.data('target');
                var contact = $('input[name="' + target + '"]').val();

                if (!contact) {
                    alert('请输入' + (type === 'email' ? '邮箱' : '手机号'));
                    return;
                }

                btn.prop('disabled', true).text('发送中...');
                $.post('send_verification.php', {
                    contact: contact,
                    type: type
                }, function(res) {
                    if (res.success) {
                        alert('验证码已发送');
                        var seconds = 60;
                        var timer = setInterval(function() {
                            seconds--;
                            btn.text(seconds + '秒后重试');
                            if (seconds <= 0) {
                                clearInterval(timer);
                                btn.prop('disabled', false).text('发送验证码');
                            }
                        }, 1000);
                    } else {
                        alert(res.message);
                        btn.prop('disabled', false).text('发送验证码');
                    }
                }, 'json').fail(function() {
                    alert('请求失败');
                    btn.prop('disabled', false).text('发送验证码');
                });
            });
        });
    </script>
</body>
</html>