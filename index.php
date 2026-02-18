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

// 处理添加交易
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $description = trim($_POST['description'] ?? '');
    $date        = $_POST['date'] ?? '';
    $debit_id    = intval($_POST['debit_account'] ?? 0);
    $credit_id   = intval($_POST['credit_account'] ?? 0);
    $amount      = floatval($_POST['amount'] ?? 0);

    if (empty($description)) {
        $error = '请填写描述';
    } elseif (empty($date)) {
        $error = '请选择日期';
    } elseif ($debit_id == $credit_id) {
        $error = '借方账户和贷方账户不能相同';
    } elseif ($amount <= 0) {
        $error = '金额必须大于零';
    } else {
        // 验证两个账户是否都属于该用户
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
                // 插入交易
                $stmt = $conn->prepare("INSERT INTO transactions (user_id, description, transaction_date) VALUES (?, ?, ?)");
                $stmt->bind_param("iss", $user_id, $description, $date);
                $stmt->execute();
                $transaction_id = $stmt->insert_id;
                $stmt->close();

                // 插入借方分录
                $stmt = $conn->prepare("INSERT INTO entries (transaction_id, user_id, account_id, amount, direction) VALUES (?, ?, ?, ?, 'debit')");
                $stmt->bind_param("iiid", $transaction_id, $user_id, $debit_id, $amount);
                $stmt->execute();
                $stmt->close();

                // 插入贷方分录
                $stmt = $conn->prepare("INSERT INTO entries (transaction_id, user_id, account_id, amount, direction) VALUES (?, ?, ?, ?, 'credit')");
                $stmt->bind_param("iiid", $transaction_id, $user_id, $credit_id, $amount);
                $stmt->execute();
                $stmt->close();

                $conn->commit();
                header("Location: index.php");
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                $error = '添加失败: ' . $e->getMessage();
            }
        }
    }
}

// 递归获取当前用户的所有账户（用于树形下拉），按 sort_order 排序
function getAccountTree($conn, $user_id, $parent_id = NULL, $level = 0) {
    $accounts = [];
    $sql = "SELECT id, name, type, parent_id, description, is_default, sort_order FROM accounts WHERE user_id = ? AND parent_id " . (is_null($parent_id) ? "IS NULL" : "= ?") . " ORDER BY sort_order ASC, name ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die('SQL 准备失败：' . $conn->error . '<br>SQL：' . $sql . '<br>请检查 accounts 表是否包含 is_default 和 sort_order 字段，并执行 ALTER TABLE 添加。');
    }
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

// 计算当前用户各账户余额
$balance_sql = "SELECT 
    a.id, a.name, a.type,
    COALESCE(SUM(CASE WHEN e.direction='debit' THEN e.amount ELSE 0 END), 0) as total_debit,
    COALESCE(SUM(CASE WHEN e.direction='credit' THEN e.amount ELSE 0 END), 0) as total_credit
    FROM accounts a
    LEFT JOIN entries e ON a.id = e.account_id AND e.user_id = ?
    WHERE a.user_id = ?
    GROUP BY a.id
    ORDER BY a.type, a.name";
$stmt = $conn->prepare($balance_sql);
if (!$stmt) die('SQL 准备失败：' . $conn->error);
$stmt->bind_param("ii", $user_id, $user_id);
$stmt->execute();
$balances_result = $stmt->get_result();

// 将余额数据存入数组便于使用
$balanceMap = [];
while ($row = $balances_result->fetch_assoc()) {
    $balanceMap[$row['id']] = $row;
}
$balances_result->close();

// 合并账户信息和余额数据
$accountsWithBalance = [];
foreach ($allAccounts as $acc) {
    $acc['total_debit'] = $balanceMap[$acc['id']]['total_debit'] ?? 0;
    $acc['total_credit'] = $balanceMap[$acc['id']]['total_credit'] ?? 0;
    $accountsWithBalance[] = $acc;
}

// 筛选顶级分类（parent_id IS NULL）
$topLevelAccounts = array_filter($accountsWithBalance, function($acc) {
    return $acc['parent_id'] === null;
});
$topLevelAccounts = array_values($topLevelAccounts);

// 精简列表：仅显示 is_default = 1 的顶级账户（顺序由 getAccountTree 中的 ORDER BY 保证）
$shortList = array_filter($topLevelAccounts, function($acc) {
    return $acc['is_default'] == 1;
});
$shortList = array_values($shortList);

// 获取当前用户的所有交易（带分录摘要），并将 direction 转为中文
$transactions_sql = "SELECT t.id, t.description, t.transaction_date,
    GROUP_CONCAT(CONCAT(a.name, ' ', 
        CASE e.direction 
            WHEN 'debit' THEN '借方' 
            WHEN 'credit' THEN '贷方' 
        END, 
        ':', e.amount) SEPARATOR ' | ') as entries_summary
    FROM transactions t
    LEFT JOIN entries e ON t.id = e.transaction_id
    LEFT JOIN accounts a ON e.account_id = a.id
    WHERE t.user_id = ?
    GROUP BY t.id
    ORDER BY t.transaction_date DESC, t.id DESC";
$stmt = $conn->prepare($transactions_sql);
if (!$stmt) die('SQL 准备失败：' . $conn->error);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$transactions = $stmt->get_result();

// 获取当前用户信息
$stmt = $conn->prepare("SELECT username, is_admin FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_info = $stmt->get_result()->fetch_assoc();
$username = $user_info['username'] ?? '用户';
$is_admin = $user_info['is_admin'] ?? 0;

// ==================== 总结面板（下拉菜单选择时间段） ====================
$periods = [
    '今天' => ['start' => date('Y-m-d'), 'end' => date('Y-m-d')],
    '本周' => ['start' => date('Y-m-d', strtotime('monday this week')), 'end' => date('Y-m-d')],
    '15天' => ['start' => date('Y-m-d', strtotime('-14 days')), 'end' => date('Y-m-d')],
    '本月' => ['start' => date('Y-m-01'), 'end' => date('Y-m-t')],
    '季度' => function() {
        $month = date('n');
        $quarterStartMonth = floor(($month - 1) / 3) * 3 + 1;
        $quarterEndMonth = $quarterStartMonth + 2;
        $year = date('Y');
        $start = sprintf('%04d-%02d-01', $year, $quarterStartMonth);
        $end = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $quarterEndMonth)));
        return ['start' => $start, 'end' => $end];
    },
    '年度' => function() {
        $year = date('Y');
        return ['start' => $year . '-01-01', 'end' => $year . '-12-31'];
    },
];

$selected_period = $_GET['period'] ?? '今天';
if (!array_key_exists($selected_period, $periods)) {
    $selected_period = '今天';
}

$range = $periods[$selected_period];
if (is_callable($range)) {
    $range = $range();
}
$start = $range['start'];
$end = $range['end'];

// 查询收入总额
$stmt = $conn->prepare("SELECT COALESCE(SUM(e.amount), 0) FROM entries e 
                        JOIN accounts a ON e.account_id = a.id
                        JOIN transactions t ON e.transaction_id = t.id
                        WHERE e.user_id = ? AND a.type = 'income' AND e.direction = 'credit'
                        AND t.transaction_date BETWEEN ? AND ?");
$stmt->bind_param("iss", $user_id, $start, $end);
$stmt->execute();
$stmt->bind_result($income);
$stmt->fetch();
$stmt->close();

// 查询支出总额
$stmt = $conn->prepare("SELECT COALESCE(SUM(e.amount), 0) FROM entries e 
                        JOIN accounts a ON e.account_id = a.id
                        JOIN transactions t ON e.transaction_id = t.id
                        WHERE e.user_id = ? AND a.type = 'expense' AND e.direction = 'debit'
                        AND t.transaction_date BETWEEN ? AND ?");
$stmt->bind_param("iss", $user_id, $start, $end);
$stmt->execute();
$stmt->bind_result($expense);
$stmt->fetch();
$stmt->close();

$balance = $income - $expense;
// ==================== 结束总结面板数据计算 ====================
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>复式记账本</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body { background: #f4f7fb; font-family: 'Segoe UI', Roboto, system-ui, sans-serif; }
        .container { max-width: 720px; }
        .navbar { background: white; border-radius: 50px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); padding: 0.5rem 1.5rem; }
        .username { font-weight: 600; color: #667eea; }
        .btn-logout { border-radius: 30px; }
        .card { border: none; border-radius: 20px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05), 0 8px 10px -6px rgba(0,0,0,0.02); transition: transform 0.2s ease; }
        .card:hover { transform: translateY(-2px); }
        .card-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; font-weight: 600; border-radius: 20px 20px 0 0 !important; padding: 1rem 1.25rem; border: none; }
        .card-header i { margin-right: 8px; }
        .badge-type { background: #e9ecef; color: #495057; font-weight: 500; padding: 0.35em 0.65em; border-radius: 30px; }
        .badge-asset { background: #d1ecf1; color: #0c5460; }
        .badge-income { background: #d4edda; color: #155724; }
        .badge-expense { background: #f8d7da; color: #721c24; }
        .btn-sm { border-radius: 30px; padding: 0.25rem 0.8rem; font-size: 0.8rem; }
        .btn-outline-primary { border-color: #cbd5e0; color: #4a5568; }
        .btn-outline-primary:hover { background: #667eea; border-color: #667eea; color: white; }
        .btn-outline-danger:hover { background: #e53e3e; border-color: #e53e3e; }
        .amount-positive { color: #2ecc71; font-weight: 600; }
        .amount-negative { color: #e74c3c; font-weight: 600; }
        .form-control, .form-select { border-radius: 15px; border: 1px solid #e2e8f0; padding: 0.6rem 1rem; background: white; }
        .form-control:focus, .form-select:focus { border-color: #667eea; box-shadow: 0 0 0 0.2rem rgba(102,126,234,0.25); }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; border-radius: 40px; padding: 0.6rem 2rem; font-weight: 600; box-shadow: 0 4px 15px rgba(102,126,234,0.4); transition: all 0.3s; }
        .btn-primary:hover { background: linear-gradient(135deg, #5a67d8 0%, #6b46a0 100%); transform: scale(1.02); }
        .table-responsive { border-radius: 15px; overflow-x: auto; }
        .account-list .list-group-item {
            border-left: none;
            border-right: none;
            padding: 0.75rem 1rem;
        }
        .toggle-arrow {
            cursor: pointer;
            transition: transform 0.3s;
        }
        .toggle-arrow.rotated {
            transform: rotate(90deg);
        }
        .summary-card .display-income { color: #28a745; font-size: 1.2rem; font-weight: 600; }
        .summary-card .display-expense { color: #dc3545; font-size: 1.2rem; font-weight: 600; }
        .summary-card .display-balance { font-size: 1.2rem; font-weight: 600; }
        @media (max-width: 576px) {
            .card-header { padding: 0.75rem 1rem; }
            .btn-sm { padding: 0.2rem 0.6rem; }
        }
    </style>
</head>
<body>
    <div class="container py-3">
        <!-- 导航栏（带用户下拉菜单） -->
        <div class="navbar d-flex justify-content-between align-items-center mb-4">
            <div><i class="fas fa-book-open me-2" style="color:#667eea;"></i><span class="fw-bold">复式记账本</span></div>
            <div class="d-flex align-items-center">
                <?php if ($is_admin): ?>
                    <a href="settings.php" class="btn btn-sm btn-outline-primary me-2"><i class="fas fa-cog"></i> 系统配置</a>
                <?php endif; ?>
                <a href="account_manager.php" class="btn btn-sm btn-outline-success me-2"><i class="fas fa-tags"></i> 分类管理</a>
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-user-circle"></i> <?= htmlspecialchars($username) ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                        <li><a class="dropdown-item text-danger" href="delete_account.php"><i class="fas fa-user-slash me-2"></i>注销账号</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>退出</a></li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- 我的账户余额（可展开/折叠） -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-wallet"></i> 我的账户余额</span>
                <span class="toggle-arrow" id="toggleAccounts">
                    <i class="fas fa-chevron-down"></i>
                </span>
            </div>
            <div class="card-body p-0">
                <!-- 精简列表（默认显示 is_default=1 的账户，按 sort_order 排序） -->
                <ul class="list-group list-group-flush account-list" id="shortList">
                    <?php foreach ($shortList as $acc): 
                        $balance = $acc['total_debit'] - $acc['total_credit'];
                        $balanceClass = $balance >= 0 ? 'amount-positive' : 'amount-negative';
                    ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span>
                                <?= htmlspecialchars($acc['name']) ?> 
                                <small class="text-muted">(<?= $typeMap[$acc['type']] ?? $acc['type'] ?>)</small>
                            </span>
                            <span class="<?= $balanceClass ?>"><?= number_format($balance, 2) ?></span>
                        </li>
                    <?php endforeach; ?>
                    <?php if (empty($shortList)): ?>
                        <li class="list-group-item text-muted">暂无默认显示账户</li>
                    <?php endif; ?>
                </ul>
                <!-- 完整顶级分类列表（隐藏，也按 sort_order 排序） -->
                <ul class="list-group list-group-flush account-list" id="fullList" style="display: none;">
                    <?php foreach ($topLevelAccounts as $acc): 
                        $balance = $acc['total_debit'] - $acc['total_credit'];
                        $balanceClass = $balance >= 0 ? 'amount-positive' : 'amount-negative';
                    ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span>
                                <?= htmlspecialchars($acc['name']) ?> 
                                <small class="text-muted">(<?= $typeMap[$acc['type']] ?? $acc['type'] ?>)</small>
                            </span>
                            <span class="<?= $balanceClass ?>"><?= number_format($balance, 2) ?></span>
                        </li>
                    <?php endforeach; ?>
                    <?php if (empty($topLevelAccounts)): ?>
                        <li class="list-group-item text-muted">暂无顶级分类</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <!-- 收支总结（下拉菜单） -->
        <div class="card mb-4 summary-card">
            <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                <span><i class="fas fa-chart-line"></i> 收支总结</span>
                <form method="get" class="d-inline-block">
                    <select name="period" class="form-select form-select-sm" style="width: auto; display: inline-block;" onchange="this.form.submit()">
                        <?php foreach (array_keys($periods) as $period): ?>
                            <option value="<?= $period ?>" <?= $selected_period == $period ? 'selected' : '' ?>><?= $period ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-4">
                        <div class="text-muted small">收入</div>
                        <div class="display-income">¥ <?= number_format($income, 2) ?></div>
                    </div>
                    <div class="col-4">
                        <div class="text-muted small">支出</div>
                        <div class="display-expense">¥ <?= number_format($expense, 2) ?></div>
                    </div>
                    <div class="col-4">
                        <div class="text-muted small">结余</div>
                        <div class="display-balance <?= $balance >= 0 ? 'amount-positive' : 'amount-negative' ?>">¥ <?= number_format($balance, 2) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 记一笔表单 -->
        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-pen"></i> 记一笔（复式）</div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="fas fa-exclamation-circle me-2"></i> <?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="action" value="add">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label"><i class="fas fa-arrow-right text-success me-1"></i>借方账户</label>
                            <select name="debit_account" class="form-select" required>
                                <option value="">-- 选择借方账户 --</option>
                                <?php foreach ($allAccounts as $acc): ?>
                                    <option value="<?= $acc['id'] ?>" <?= (isset($_POST['debit_account']) && $_POST['debit_account']==$acc['id']) ? 'selected' : '' ?>>
                                        <?= str_repeat('&nbsp;', $acc['level'] * 4) ?><?= htmlspecialchars($acc['name']) ?> (<?= $typeMap[$acc['type']] ?? $acc['type'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label"><i class="fas fa-arrow-left text-danger me-1"></i>贷方账户</label>
                            <select name="credit_account" class="form-select" required>
                                <option value="">-- 选择贷方账户 --</option>
                                <?php foreach ($allAccounts as $acc): ?>
                                    <option value="<?= $acc['id'] ?>" <?= (isset($_POST['credit_account']) && $_POST['credit_account']==$acc['id']) ? 'selected' : '' ?>>
                                        <?= str_repeat('&nbsp;', $acc['level'] * 4) ?><?= htmlspecialchars($acc['name']) ?> (<?= $typeMap[$acc['type']] ?? $acc['type'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label"><i class="fas fa-coins me-1"></i>金额</label>
                            <input type="number" step="0.01" min="0.01" name="amount" class="form-control" required value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>">
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label"><i class="fas fa-calendar-alt me-1"></i>日期</label>
                            <input type="date" name="date" class="form-control" required value="<?= htmlspecialchars($_POST['date'] ?? date('Y-m-d')) ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label"><i class="fas fa-tag me-1"></i>描述</label>
                            <input type="text" name="description" class="form-control" required placeholder="如：午餐" value="<?= htmlspecialchars($_POST['description'] ?? '') ?>">
                        </div>
                        <div class="col-12 text-center text-md-end mt-3">
                            <button type="submit" class="btn btn-primary px-5"><i class="fas fa-save me-2"></i>保存交易</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- 历史交易列表 -->
        <div class="card">
            <div class="card-header"><i class="fas fa-history"></i> 我的历史交易</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>日期</th>
                                <th>描述</th>
                                <th>分录详情</th>
                                <th class="text-end">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($transactions->num_rows > 0): while ($row = $transactions->fetch_assoc()): ?>
                            <tr>
                                <td><i class="far fa-calendar-alt me-1 text-muted"></i><?= htmlspecialchars($row['transaction_date']) ?></td>
                                <td><?= htmlspecialchars($row['description']) ?></td>
                                <td><small class="text-muted"><?= htmlspecialchars($row['entries_summary'] ?: '无分录') ?></small></td>
                                <td class="text-end">
                                    <a href="edit.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-primary me-1"><i class="fas fa-edit"></i></a>
                                    <a href="javascript:void(0);" onclick="if(confirm('确定删除这笔交易吗？')) location.href='delete.php?id=<?= $row['id'] ?>';" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash-alt"></i></a>
                                </td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr><td colspan="4" class="text-center py-4 text-muted">暂无交易，记一笔吧！</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const toggleBtn = document.getElementById('toggleAccounts');
            const shortList = document.getElementById('shortList');
            const fullList = document.getElementById('fullList');
            const arrowIcon = toggleBtn.querySelector('i');

            toggleBtn.addEventListener('click', function() {
                if (shortList.style.display === 'none') {
                    // 当前是展开状态，切换为折叠
                    shortList.style.display = '';
                    fullList.style.display = 'none';
                    arrowIcon.className = 'fas fa-chevron-down';
                } else {
                    // 当前是折叠状态，切换为展开
                    shortList.style.display = 'none';
                    fullList.style.display = '';
                    arrowIcon.className = 'fas fa-chevron-right';
                }
            });
        });
    </script>
</body>
</html>