<?php
session_start();

$host = 'localhost';
$user = 'root';          // 请修改为您的数据库用户名
$pass = '';              // 请修改为您的数据库密码
$db   = '';              // 请修改为您的数据库名称

// 开启错误显示（调试用，生产环境可关闭）
error_reporting(E_ALL);
ini_set('display_errors', 1);

$conn = new mysqli($host, $user, $pass);
if ($conn->connect_error) {
    die('数据库连接失败: ' . $conn->connect_error);
}

// 创建数据库（如果不存在）
if (!$conn->query("CREATE DATABASE IF NOT EXISTS $db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
    die('创建数据库失败: ' . $conn->error);
}

// 选择数据库
if (!$conn->select_db($db)) {
    die('无法选择数据库 ' . $db . ': ' . $conn->error);
}

// 创建用户表
$sql_users = "CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100) NULL UNIQUE,
    phone VARCHAR(20) NULL UNIQUE,
    is_admin TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
if (!$conn->query($sql_users)) {
    die('创建 users 表失败: ' . $conn->error);
}

// 创建账户表（分类）——每个用户独立，支持无限级
// 更新：增加 is_default 和 sort_order 字段，与 geren_latest.sql 保持一致
$sql_accounts = "CREATE TABLE IF NOT EXISTS accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(50) NOT NULL,
    type ENUM('asset','liability','equity','income','expense') NOT NULL,
    parent_id INT NULL,
    description TEXT,
    is_default TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否默认显示在首页精简列表',
    sort_order INT NOT NULL DEFAULT 0 COMMENT '排序值，越小越靠前',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES accounts(id) ON DELETE CASCADE,
    INDEX (user_id, parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
if (!$conn->query($sql_accounts)) {
    die('创建 accounts 表失败: ' . $conn->error);
}

// 创建交易主表
$sql_transactions = "CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    description TEXT NOT NULL,
    transaction_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
if (!$conn->query($sql_transactions)) {
    die('创建 transactions 表失败: ' . $conn->error);
}

// 创建分录表
$sql_entries = "CREATE TABLE IF NOT EXISTS entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    user_id INT NOT NULL,
    account_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    direction ENUM('debit','credit') NOT NULL,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
if (!$conn->query($sql_entries)) {
    die('创建 entries 表失败: ' . $conn->error);
}

// 创建验证码表
$sql_codes = "CREATE TABLE IF NOT EXISTS verification_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contact VARCHAR(100) NOT NULL,
    code VARCHAR(10) NOT NULL,
    type ENUM('email','sms') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    is_used TINYINT(1) DEFAULT 0,
    INDEX (contact, code, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
if (!$conn->query($sql_codes)) {
    die('创建 verification_codes 表失败: ' . $conn->error);
}

// 创建系统配置表
$sql_settings = "CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(50) NOT NULL UNIQUE,
    `value` TEXT,
    description TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
if (!$conn->query($sql_settings)) {
    die('创建 settings 表失败: ' . $conn->error);
}

// 插入默认配置（如果配置表为空）
$result = $conn->query("SELECT COUNT(*) as cnt FROM settings");
if (!$result) {
    die('查询 settings 表失败: ' . $conn->error);
}
$row = $result->fetch_assoc();
if ($row['cnt'] == 0) {
    $defaultSettings = [
        ['mail_host', 'smtp.qq.com', '邮件服务器地址'],
        ['mail_port', '465', '邮件服务器端口'],
        ['mail_username', '', '邮箱账号'],
        ['mail_password', '', '邮箱密码/授权码'],
        ['mail_encryption', 'ssl', '加密方式 (ssl/tls)'],
        ['mail_from', '', '发件人邮箱'],
        ['sms_provider', 'aliyun', '短信服务商 (aliyun/tencent/twilio)'],
        ['sms_access_key', '', 'Access Key'],
        ['sms_secret_key', '', 'Secret Key'],
        ['sms_sign', '', '短信签名'],
        ['sms_template_code', '', '短信模板代码'],
        ['twilio_account_sid', '', 'Twilio Account SID'],
        ['twilio_auth_token', '', 'Twilio Auth Token'],
        ['twilio_phone_number', '', 'Twilio 发件号码'],
        ['require_email', '0', '是否强制绑定邮箱 (1=是, 0=否)'],
        ['require_phone', '0', '是否强制绑定手机号 (1=是, 0=否)'],
        ['allow_registration', '1', '是否允许新用户注册 (1=是, 0=否)'],
    ];
    $stmt = $conn->prepare("INSERT INTO settings (`key`, `value`, description) VALUES (?, ?, ?)");
    if (!$stmt) {
        die('准备插入 settings 失败: ' . $conn->error);
    }
    foreach ($defaultSettings as $s) {
        $stmt->bind_param("sss", $s[0], $s[1], $s[2]);
        if (!$stmt->execute()) {
            die('插入 settings 失败: ' . $stmt->error);
        }
    }
    $stmt->close();
}

// 注意：默认账户不再全局插入，而是在用户注册时针对该用户插入。
// 因此这里不插入 accounts 表数据。

$conn->set_charset('utf8mb4');

// 安装检测：如果 users 表为空且不是 install.php，则跳转到安装页
if (basename($_SERVER['PHP_SELF']) != 'install.php') {
    $result = $conn->query("SELECT COUNT(*) as cnt FROM users");
    if (!$result) {
        die('查询 users 表失败: ' . $conn->error);
    }
    $row = $result->fetch_assoc();
    if ($row['cnt'] == 0) {
        header("Location: install.php");
        exit;
    }
}
?>