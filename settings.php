<?php
require_once 'admin_auth.php';

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($_POST as $key => $value) {
        if ($key !== 'submit') {
            $stmt = $conn->prepare("UPDATE settings SET `value` = ? WHERE `key` = ?");
            $stmt->bind_param("ss", $value, $key);
            $stmt->execute();
            $stmt->close();
        }
    }
    $message = '配置已保存';
}

$settings = [];
$res = $conn->query("SELECT `key`, `value`, description FROM settings ORDER BY id");
while ($row = $res->fetch_assoc()) {
    $settings[$row['key']] = $row;
}
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系统配置 - 复式记账本</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-cog me-2"></i>系统配置</h2>
            <a href="index.php" class="btn btn-outline-secondary">返回首页</a>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><?= $message ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header bg-primary text-white">邮件服务配置</div>
            <div class="card-body">
                <form method="post">
                    <div class="row">
                        <?php
                        $mailKeys = ['mail_host', 'mail_port', 'mail_username', 'mail_password', 'mail_encryption', 'mail_from'];
                        foreach ($mailKeys as $key):
                            $item = $settings[$key];
                        ?>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?= htmlspecialchars($item['description']) ?></label>
                            <input type="text" name="<?= $key ?>" class="form-control" value="<?= htmlspecialchars($item['value']) ?>">
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <hr class="my-4">
                    <h5>短信服务配置</h5>
                    <div class="row">
                        <?php
                        $smsKeys = ['sms_provider', 'sms_access_key', 'sms_secret_key', 'sms_sign', 'sms_template_code'];
                        foreach ($smsKeys as $key):
                            $item = $settings[$key];
                        ?>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?= htmlspecialchars($item['description']) ?></label>
                            <input type="text" name="<?= $key ?>" class="form-control" value="<?= htmlspecialchars($item['value']) ?>">
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="row">
                        <?php
                        $twilioKeys = ['twilio_account_sid', 'twilio_auth_token', 'twilio_phone_number'];
                        foreach ($twilioKeys as $key):
                            $item = $settings[$key];
                        ?>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?= htmlspecialchars($item['description']) ?></label>
                            <input type="text" name="<?= $key ?>" class="form-control" value="<?= htmlspecialchars($item['value']) ?>">
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <hr class="my-4">
                    <h5>注册与绑定配置</h5>
                    <div class="row">
                        <?php
                        $regKeys = ['require_email', 'require_phone', 'allow_registration'];
                        foreach ($regKeys as $key):
                            $item = $settings[$key];
                        ?>
                        <div class="col-md-4 mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="<?= $key ?>" id="<?= $key ?>" value="1" <?= $item['value'] == '1' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="<?= $key ?>"><?= htmlspecialchars($item['description']) ?></label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="submit" name="submit" class="btn btn-primary mt-3">保存配置</button>
                </form>
            </div>
        </div>
    </div>
    <script src="js/bootstrap.bundle.min.js"></script>
</body>
</html>