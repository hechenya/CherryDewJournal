-- 删除现有数据库（如果存在）
DROP DATABASE IF EXISTS `geren`;

-- 创建新数据库
CREATE DATABASE `geren` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 使用数据库
USE `geren`;

-- 用户表
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `email` VARCHAR(100) NULL UNIQUE,
    `phone` VARCHAR(20) NULL UNIQUE,
    `is_admin` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 账户表（分类）——每个用户独立，支持无限级，包含 is_default 和 sort_order
CREATE TABLE IF NOT EXISTS `accounts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `name` VARCHAR(50) NOT NULL,
    `type` ENUM('asset','liability','equity','income','expense') NOT NULL,
    `parent_id` INT NULL,
    `description` TEXT,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否默认显示在首页精简列表',
    `sort_order` INT NOT NULL DEFAULT 0 COMMENT '排序值，越小越靠前',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`parent_id`) REFERENCES `accounts`(`id`) ON DELETE CASCADE,
    INDEX (`user_id`, `parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 交易主表
CREATE TABLE IF NOT EXISTS `transactions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `description` TEXT NOT NULL,
    `transaction_date` DATE NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 分录表
CREATE TABLE IF NOT EXISTS `entries` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `transaction_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `account_id` INT NOT NULL,
    `amount` DECIMAL(10,2) NOT NULL,
    `direction` ENUM('debit','credit') NOT NULL,
    FOREIGN KEY (`transaction_id`) REFERENCES `transactions`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`account_id`) REFERENCES `accounts`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 验证码表
CREATE TABLE IF NOT EXISTS `verification_codes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `contact` VARCHAR(100) NOT NULL,
    `code` VARCHAR(10) NOT NULL,
    `type` ENUM('email','sms') NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `expires_at` DATETIME NOT NULL,
    `is_used` TINYINT(1) DEFAULT 0,
    INDEX (`contact`, `code`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 系统配置表
CREATE TABLE IF NOT EXISTS `settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(50) NOT NULL UNIQUE,
    `value` TEXT,
    `description` TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 插入默认系统配置
INSERT INTO `settings` (`key`, `value`, `description`) VALUES
('mail_host', 'smtp.qq.com', '邮件服务器地址'),
('mail_port', '465', '邮件服务器端口'),
('mail_username', '', '邮箱账号'),
('mail_password', '', '邮箱密码/授权码'),
('mail_encryption', 'ssl', '加密方式 (ssl/tls)'),
('mail_from', '', '发件人邮箱'),
('sms_provider', 'aliyun', '短信服务商 (aliyun/tencent/twilio)'),
('sms_access_key', '', 'Access Key'),
('sms_secret_key', '', 'Secret Key'),
('sms_sign', '', '短信签名'),
('sms_template_code', '', '短信模板代码'),
('twilio_account_sid', '', 'Twilio Account SID'),
('twilio_auth_token', '', 'Twilio Auth Token'),
('twilio_phone_number', '', 'Twilio 发件号码'),
('require_email', '0', '是否强制绑定邮箱 (1=是, 0=否)'),
('require_phone', '0', '是否强制绑定手机号 (1=是, 0=否)'),
('allow_registration', '1', '是否允许新用户注册 (1=是, 0=否)')
ON DUPLICATE KEY UPDATE `id`=`id`;