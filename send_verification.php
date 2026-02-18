<?php
require_once 'db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '非法请求']);
    exit;
}

$contact = trim($_POST['contact'] ?? '');
$type = $_POST['type'] ?? ''; // email 或 sms

if (empty($contact) || !in_array($type, ['email', 'sms'])) {
    echo json_encode(['success' => false, 'message' => '参数错误']);
    exit;
}

// 格式校验
if ($type === 'email' && !filter_var($contact, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => '邮箱格式不正确']);
    exit;
}
if ($type === 'sms' && !preg_match('/^1[3-9]\d{9}$/', $contact)) {
    echo json_encode(['success' => false, 'message' => '手机号格式不正确']);
    exit;
}

// 频率限制
$stmt = $conn->prepare("SELECT COUNT(*) FROM verification_codes WHERE contact = ? AND type = ? AND is_used = 0 AND expires_at > NOW()");
$stmt->bind_param("ss", $contact, $type);
$stmt->execute();
$stmt->bind_result($count);
$stmt->fetch();
$stmt->close();

if ($count >= 5) {
    echo json_encode(['success' => false, 'message' => '请求过于频繁，请稍后再试']);
    exit;
}

$code = rand(100000, 999999);
$expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));

// 读取配置
$settings = [];
$res = $conn->query("SELECT `key`, `value` FROM settings");
while ($row = $res->fetch_assoc()) {
    $settings[$row['key']] = $row['value'];
}

$sent = false;

if ($type === 'email') {
    // ---------- 邮件发送（PHPMailer）----------
    require_once 'PHPMailer/PHPMailer.php';
    require_once 'PHPMailer/SMTP.php';
    require_once 'PHPMailer/Exception.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $settings['mail_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $settings['mail_username'];
        $mail->Password   = $settings['mail_password'];
        $mail->SMTPSecure = $settings['mail_encryption'];
        $mail->Port       = $settings['mail_port'];
        $mail->setFrom($settings['mail_from'], '记账系统');
        $mail->addAddress($contact);
        $mail->Subject = '验证码';
        $mail->Body    = "您的验证码是：$code，10分钟内有效。";
        $mail->send();
        $sent = true;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '邮件发送失败: ' . $mail->ErrorInfo]);
        exit;
    }
} else {
    // ---------- 短信发送（根据服务商）----------
    $provider = $settings['sms_provider'] ?? 'none';

    switch ($provider) {
        case 'aliyun':
            $accessKeyId = $settings['sms_access_key'];
            $accessSecret = $settings['sms_secret_key'];
            $signName = $settings['sms_sign'];
            $templateCode = $settings['sms_template_code'];

            $params = [
                'PhoneNumbers'  => $contact,
                'SignName'      => $signName,
                'TemplateCode'  => $templateCode,
                'TemplateParam' => json_encode(['code' => $code]),
                'RegionId'      => 'cn-hangzhou',
                'Action'        => 'SendSms',
                'Version'       => '2017-05-25',
                'AccessKeyId'   => $accessKeyId,
                'Format'        => 'JSON',
                'SignatureMethod' => 'HMAC-SHA1',
                'SignatureVersion' => '1.0',
                'SignatureNonce' => uniqid(),
                'Timestamp'     => gmdate('Y-m-d\TH:i:s\Z'),
            ];
            ksort($params);
            $stringToSign = 'GET&%2F&' . urlencode(http_build_query($params, '', '&', PHP_QUERY_RFC3986));
            $signature = base64_encode(hash_hmac('sha1', $stringToSign, $accessSecret . '&', true));
            $params['Signature'] = $signature;
            $url = 'https://dysmsapi.aliyuncs.com/?' . http_build_query($params);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $response = curl_exec($ch);
            curl_close($ch);
            $result = json_decode($response, true);
            if ($result && isset($result['Code']) && $result['Code'] === 'OK') {
                $sent = true;
            } else {
                echo json_encode(['success' => false, 'message' => '阿里云短信发送失败：' . ($result['Message'] ?? '未知错误')]);
                exit;
            }
            break;

        case 'tencent':
            // 腾讯云短信需集成 SDK，此处给出简易提示
            echo json_encode(['success' => false, 'message' => '腾讯云短信发送请参考官方文档集成 SDK']);
            exit;
            break;

        case 'twilio':
            $accountSid = $settings['twilio_account_sid'];
            $authToken = $settings['twilio_auth_token'];
            $fromNumber = $settings['twilio_phone_number'];
            $url = "https://api.twilio.com/2010-04-01/Accounts/$accountSid/Messages.json";
            $data = [
                'To'   => $contact,
                'From' => $fromNumber,
                'Body' => "您的验证码是：$code"
            ];
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_USERPWD, "$accountSid:$authToken");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode == 201) {
                $sent = true;
            } else {
                $result = json_decode($response, true);
                echo json_encode(['success' => false, 'message' => 'Twilio 发送失败：' . ($result['message'] ?? '未知错误')]);
                exit;
            }
            break;

        default:
            echo json_encode(['success' => false, 'message' => '未配置短信服务商或服务商不支持']);
            exit;
    }
}

if ($sent) {
    $stmt = $conn->prepare("INSERT INTO verification_codes (contact, code, type, expires_at) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $contact, $code, $type, $expires);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true, 'message' => '验证码已发送']);
} else {
    echo json_encode(['success' => false, 'message' => '发送失败，请检查配置']);
}
?>