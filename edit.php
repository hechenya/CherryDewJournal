<?php
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$user_id = $_SESSION['user_id'];

// 类型英文转中文映射
$typeMap = [
    'asset'   => '资产',
    'income'  => '收入',
    'expense' => '支出',
];

$id = $_GET['id'] ?? 0;
if (!$id) { header("Location: index.php"); exit; }

$stmt = $conn->prepare("SELECT * FROM transactions WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $id, $user_id);
$stmt->execute();
$transaction = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$transaction) {
    header("Location: index.php");
    exit;
}

// 获取分录
$entries = $conn->prepare("SELECT e.*, a.name as account_name FROM entries e JOIN accounts a ON e.account_id = a.id WHERE e.transaction_id = ? AND e.user_id = ?");
$entries->bind_param("ii", $id, $user_id);
$entries->execute();
$result = $entries->get_result();
$debit_entry = $credit_entry = null;
while ($row = $result->fetch_assoc()) {
    if ($row['direction'] == 'debit') $debit_entry = $row;
    else $credit_entry = $row;
}
$entries->close();

if (!$debit_entry || !$credit_entry) die('交易数据异常，缺少分录');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $description = trim($_POST['description'] ?? '');
    $date        = $_POST['date'] ?? '';
    $debit_id    = intval($_POST['debit_account'] ?? 0);
    $credit_id   = intval($_POST['credit_account'] ?? 0);
    $amount      = floatval($_POST['amount'] ?? 0);

    if (empty($description) || empty($date) || $debit_id == $credit_id || $amount <= 0) {
        $error = '输入有误，请检查';
    } else {
        // 验证账户属于该用户
        $stmt = $conn->prepare("SELECT COUNT(*) FROM accounts WHERE id IN (?, ?) AND user_id = ?");
        $stmt->bind_param("iii", $debit_id, $credit_id, $user_id);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();
        if ($count != 2) {
            $error = '选择的账户无效';
        } else {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("UPDATE transactions SET description = ?, transaction_date = ? WHERE id = ? AND user_id = ?");
                $stmt->bind_param("ssii", $description, $date, $id, $user_id);
                $stmt->execute();
                $stmt->close();

                $conn->query("DELETE FROM entries WHERE transaction_id = $id AND user_id = $user_id");

                $stmt = $conn->prepare("INSERT INTO entries (transaction_id, user_id, account_id, amount, direction) VALUES (?, ?, ?, ?, 'debit')");
                $stmt->bind_param("iiid", $id, $user_id, $debit_id, $amount);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("INSERT INTO entries (transaction_id, user_id, account_id, amount, direction) VALUES (?, ?, ?, ?, 'credit')");
                $stmt->bind_param("iiid", $id, $user_id, $credit_id, $amount);
                $stmt->execute();
                $stmt->close();

                $conn->commit();
                header("Location: index.php");
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                $error = '更新失败: ' . $e->getMessage();
            }
        }
    }
}

// 递归获取账户树
function getAccountTree($conn, $user_id, $parent_id = NULL, $level = 0) {
    $accounts = [];
    $sql = "SELECT id, name, type, description FROM accounts WHERE user_id = ? AND parent_id " . (is_null($parent_id) ? "IS NULL" : "= ?") . " ORDER BY type, name";
    $stmt = $conn->prepare($sql);
    if (is_null($parent_id)) {
        $stmt->bind_param("i", $user_id);
    } else {
        $stmt->bind_param("ii", $user_id, $parent_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['level'] = $level;
        $accounts[] = $row;
        $children = getAccountTree($conn, $user_id, $row['id'], $level + 1);
        $accounts = array_merge($accounts, $children);
    }
    $stmt->close();
    return $accounts;
}

$allAccounts = getAccountTree($conn, $user_id);
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>编辑交易 · 复式记账</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body { background: #f4f7fb; font-family: 'Segoe UI', Roboto, system-ui, sans-serif; }
        .container { max-width: 600px; }
        .card { border: none; border-radius: 25px; box-shadow: 0 15px 35px rgba(50,50,93,0.1), 0 5px 15px rgba(0,0,0,0.07); }
        .card-header { background: linear-gradient(135deg, #f6d365 0%, #fda085 100%); color: #2d3748; font-weight: 700; border-radius: 25px 25px 0 0 !important; padding: 1.2rem 1.5rem; border: none; }
        .card-header i { margin-right: 10px; color: #c44536; }
        .form-control, .form-select { border-radius: 15px; border: 1px solid #e2e8f0; padding: 0.7rem 1.2rem; background: white; transition: 0.2s; }
        .form-control:focus, .form-select:focus { border-color: #f6d365; box-shadow: 0 0 0 0.2rem rgba(246,211,101,0.4); }
        .btn-primary { background: linear-gradient(135deg, #f6d365 0%, #fda085 100%); border: none; border-radius: 40px; padding: 0.6rem 2.5rem; font-weight: 600; color: #2d3748; box-shadow: 0 4px 15px rgba(253,160,133,0.4); }
        .btn-primary:hover { background: linear-gradient(135deg, #f5c45e 0%, #fc9575 100%); color: #1a202c; }
        .btn-secondary { background: #cbd5e0; border: none; border-radius: 40px; padding: 0.6rem 2rem; color: #2d3748; }
        label { font-weight: 500; color: #4a5568; margin-bottom: 0.3rem; }
        .back-link { text-decoration: none; color: #718096; }
        .back-link:hover { color: #4a5568; }
        .indent-1 { margin-left: 20px; }
        .indent-2 { margin-left: 40px; }
        .indent-3 { margin-left: 60px; }
        .indent-4 { margin-left: 80px; }
    </style>
</head>
<body>
    <div class="container py-4">
        <a href="index.php" class="back-link mb-3 d-inline-block"><i class="fas fa-arrow-left me-1"></i>返回首页</a>
        <div class="card">
            <div class="card-header"><i class="fas fa-edit"></i> 编辑交易 #<?= $id ?></div>
            <div class="card-body p-4">
                <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                <form method="post">
                    <div class="mb-4">
                        <label><i class="fas fa-arrow-right text-success me-1"></i>借方账户</label>
                        <select name="debit_account" class="form-select" required>
                            <option value="">-- 选择借方账户 --</option>
                            <?php foreach ($allAccounts as $acc): ?>
                                <option value="<?= $acc['id'] ?>" <?= ($debit_entry['account_id'] == $acc['id']) ? 'selected' : '' ?>>
                                    <?= str_repeat('&nbsp;', $acc['level'] * 4) ?><?= htmlspecialchars($acc['name']) ?> (<?= $typeMap[$acc['type']] ?? $acc['type'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label><i class="fas fa-arrow-left text-danger me-1"></i>贷方账户</label>
                        <select name="credit_account" class="form-select" required>
                            <option value="">-- 选择贷方账户 --</option>
                            <?php foreach ($allAccounts as $acc): ?>
                                <option value="<?= $acc['id'] ?>" <?= ($credit_entry['account_id'] == $acc['id']) ? 'selected' : '' ?>>
                                    <?= str_repeat('&nbsp;', $acc['level'] * 4) ?><?= htmlspecialchars($acc['name']) ?> (<?= $typeMap[$acc['type']] ?? $acc['type'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row mb-4">
                        <div class="col-6">
                            <label><i class="fas fa-coins me-1"></i>金额</label>
                            <input type="number" step="0.01" min="0.01" name="amount" class="form-control" required value="<?= htmlspecialchars($debit_entry['amount']) ?>">
                        </div>
                        <div class="col-6">
                            <label><i class="fas fa-calendar-alt me-1"></i>日期</label>
                            <input type="date" name="date" class="form-control" required value="<?= htmlspecialchars($transaction['transaction_date']) ?>">
                        </div>
                    </div>
                    <div class="mb-5">
                        <label><i class="fas fa-tag me-1"></i>描述</label>
                        <input type="text" name="description" class="form-control" required value="<?= htmlspecialchars($transaction['description']) ?>">
                    </div>
                    <div class="d-flex justify-content-between">
                        <a href="index.php" class="btn btn-secondary px-4"><i class="fas fa-times me-2"></i>取消</a>
                        <button type="submit" class="btn btn-primary px-5"><i class="fas fa-check me-2"></i>保存</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script src="js/bootstrap.bundle.min.js"></script>
</body>
</html>