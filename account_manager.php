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

// 处理添加/编辑/删除（不包括切换默认显示，因为已用 AJAX）
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add') {
            $name = trim($_POST['name'] ?? '');
            $type = $_POST['type'] ?? '';
            $parent_id = !empty($_POST['parent_id']) ? intval($_POST['parent_id']) : null;
            $desc = trim($_POST['description'] ?? '');
            $is_default = isset($_POST['is_default']) ? 1 : 0;
            $sort_order = intval($_POST['sort_order'] ?? 0);
            if (empty($name) || !in_array($type, ['asset','income','expense'])) {
                $message = '<div class="alert alert-danger">请填写完整信息</div>';
            } else {
                $stmt = $conn->prepare("INSERT INTO accounts (user_id, name, type, parent_id, description, is_default, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("issisii", $user_id, $name, $type, $parent_id, $desc, $is_default, $sort_order);
                if ($stmt->execute()) {
                    $message = '<div class="alert alert-success">分类添加成功</div>';
                } else {
                    $message = '<div class="alert alert-danger">添加失败：' . $conn->error . '</div>';
                }
                $stmt->close();
            }
        } elseif ($_POST['action'] === 'edit') {
            $id = intval($_POST['id']);
            $name = trim($_POST['name'] ?? '');
            $type = $_POST['type'] ?? '';
            $parent_id = !empty($_POST['parent_id']) ? intval($_POST['parent_id']) : null;
            $desc = trim($_POST['description'] ?? '');
            $is_default = isset($_POST['is_default']) ? 1 : 0;
            $sort_order = intval($_POST['sort_order'] ?? 0);
            if (empty($name) || !in_array($type, ['asset','income','expense'])) {
                $message = '<div class="alert alert-danger">请填写完整信息</div>';
            } else {
                $stmt = $conn->prepare("UPDATE accounts SET name=?, type=?, parent_id=?, description=?, is_default=?, sort_order=? WHERE id=? AND user_id=?");
                $stmt->bind_param("sssiiiii", $name, $type, $parent_id, $desc, $is_default, $sort_order, $id, $user_id);
                if ($stmt->execute()) {
                    $message = '<div class="alert alert-success">分类更新成功</div>';
                } else {
                    $message = '<div class="alert alert-danger">更新失败：' . $conn->error . '</div>';
                }
                $stmt->close();
            }
        } elseif ($_POST['action'] === 'delete') {
            $id = intval($_POST['id']);
            // 检查是否有子分类或分录
            $check = $conn->prepare("SELECT COUNT(*) FROM accounts WHERE parent_id = ? AND user_id = ?");
            $check->bind_param("ii", $id, $user_id);
            $check->execute();
            $check->bind_result($child_count);
            $check->fetch();
            $check->close();
            if ($child_count > 0) {
                $message = '<div class="alert alert-danger">请先删除子分类</div>';
            } else {
                $stmt = $conn->prepare("DELETE FROM accounts WHERE id = ? AND user_id = ?");
                $stmt->bind_param("ii", $id, $user_id);
                if ($stmt->execute()) {
                    $message = '<div class="alert alert-success">分类删除成功</div>';
                } else {
                    $message = '<div class="alert alert-danger">删除失败</div>';
                }
                $stmt->close();
            }
        }
    }
}

// 获取账户树（用于显示列表和父分类选择）
function getAccountTree($conn, $user_id, $parent_id = NULL, $level = 0) {
    $accounts = [];
    $sql = "SELECT id, name, type, description, parent_id, is_default, sort_order FROM accounts WHERE user_id = ? AND parent_id " . (is_null($parent_id) ? "IS NULL" : "= ?") . " ORDER BY sort_order ASC, name ASC";
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>分类管理 - 复式记账本</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body { background: #f4f7fb; font-family: 'Segoe UI', Roboto, system-ui, sans-serif; }
        .container { max-width: 1000px; }
        .indent-1 { margin-left: 20px; }
        .indent-2 { margin-left: 40px; }
        .indent-3 { margin-left: 60px; }
        .star-icon {
            color: #ffc107;
            cursor: pointer;
            font-size: 1.2rem;
            transition: color 0.2s;
        }
        .star-icon.inactive {
            color: #e4e5e9;
        }
        .star-icon:hover {
            opacity: 0.8;
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-tags me-2"></i>分类管理</h2>
            <a href="index.php" class="btn btn-outline-secondary">返回首页</a>
        </div>

        <?= $message ?>

        <!-- 添加分类表单 -->
        <div class="card mb-4">
            <div class="card-header bg-success text-white">添加新分类</div>
            <div class="card-body">
                <form method="post" class="row g-3">
                    <input type="hidden" name="action" value="add">
                    <div class="col-md-2">
                        <label class="form-label">分类名称</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">类型</label>
                        <select name="type" class="form-select" required>
                            <option value="asset">资产</option>
                            <option value="income">收入</option>
                            <option value="expense">支出</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">父分类</label>
                        <select name="parent_id" class="form-select">
                            <option value="">-- 顶级分类 --</option>
                            <?php foreach ($allAccounts as $acc): ?>
                                <option value="<?= $acc['id'] ?>"><?= str_repeat('&nbsp;', $acc['level'] * 4) ?><?= htmlspecialchars($acc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">排序值</label>
                        <input type="number" name="sort_order" class="form-control" value="0">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">默认显示</label>
                        <div class="form-check form-switch" style="padding-top: 8px;">
                            <input class="form-check-input" type="checkbox" name="is_default" id="add_is_default" value="1">
                            <label class="form-check-label" for="add_is_default">是</label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">描述</label>
                        <input type="text" name="description" class="form-control">
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">添加</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 分类列表 -->
        <div class="card">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <span>现有分类</span>
                <small class="text-white-50">点击星标可切换默认显示，排序值越小越靠前</small>
            </div>
            <div class="card-body p-0">
                <table class="table table-striped table-hover mb-0">
                    <thead>
                        <tr>
                            <th>名称</th>
                            <th>类型</th>
                            <th>排序</th>
                            <th>描述</th>
                            <th class="text-center">默认显示</th>
                            <th class="text-end">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allAccounts as $acc): ?>
                        <tr>
                            <td>
                                <?= str_repeat('<span class="indent-1"></span>', $acc['level']) ?>
                                <?= htmlspecialchars($acc['name']) ?>
                                <?php if ($acc['level'] == 0): ?><span class="badge bg-secondary">顶级</span><?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $badge = '';
                                if ($acc['type'] == 'asset') $badge = 'badge bg-info text-dark';
                                elseif ($acc['type'] == 'income') $badge = 'badge bg-success';
                                else $badge = 'badge bg-danger';
                                ?>
                                <span class="<?= $badge ?>"><?= $typeMap[$acc['type']] ?></span>
                            </td>
                            <td><?= $acc['sort_order'] ?></td>
                            <td><?= htmlspecialchars($acc['description'] ?: '-') ?></td>
                            <td class="text-center">
                                <span class="star-toggle" data-id="<?= $acc['id'] ?>" data-current="<?= $acc['is_default'] ?>" style="cursor: pointer;">
                                    <i class="fas fa-star star-icon <?= $acc['is_default'] ? '' : 'inactive' ?>"></i>
                                </span>
                            </td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal<?= $acc['id'] ?>"><i class="fas fa-edit"></i></button>
                                <form method="post" style="display:inline;" onsubmit="return confirm('确定删除该分类吗？');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $acc['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash-alt"></i></button>
                                </form>
                            </td>
                        </tr>

                        <!-- 编辑模态框 -->
                        <div class="modal fade" id="editModal<?= $acc['id'] ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="post">
                                        <input type="hidden" name="action" value="edit">
                                        <input type="hidden" name="id" value="<?= $acc['id'] ?>">
                                        <div class="modal-header">
                                            <h5 class="modal-title">编辑分类</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="mb-3">
                                                <label class="form-label">分类名称</label>
                                                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($acc['name']) ?>" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">类型</label>
                                                <select name="type" class="form-select" required>
                                                    <option value="asset" <?= $acc['type']=='asset'?'selected':'' ?>>资产</option>
                                                    <option value="income" <?= $acc['type']=='income'?'selected':'' ?>>收入</option>
                                                    <option value="expense" <?= $acc['type']=='expense'?'selected':'' ?>>支出</option>
                                                </select>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">父分类</label>
                                                <select name="parent_id" class="form-select">
                                                    <option value="">-- 顶级分类 --</option>
                                                    <?php foreach ($allAccounts as $p): if ($p['id'] == $acc['id']) continue; ?>
                                                        <option value="<?= $p['id'] ?>" <?= $p['id']==$acc['parent_id']?'selected':'' ?>><?= str_repeat('&nbsp;', $p['level'] * 4) ?><?= htmlspecialchars($p['name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">排序值</label>
                                                <input type="number" name="sort_order" class="form-control" value="<?= $acc['sort_order'] ?>">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">描述</label>
                                                <input type="text" name="description" class="form-control" value="<?= htmlspecialchars($acc['description'] ?? '') ?>">
                                            </div>
                                            <div class="mb-3">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" name="is_default" id="edit_is_default_<?= $acc['id'] ?>" value="1" <?= $acc['is_default'] ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="edit_is_default_<?= $acc['id'] ?>">默认显示在首页</label>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                                            <button type="submit" class="btn btn-primary">保存</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($allAccounts)): ?>
                        <tr><td colspan="6" class="text-center py-3">暂无分类，请添加</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="js/bootstrap.bundle.min.js"></script>
    <script>
    $(document).ready(function() {
        $('.star-toggle').click(function() {
            var $this = $(this);
            var id = $this.data('id');
            var current = $this.data('current');
            var $icon = $this.find('.star-icon');

            $.ajax({
                url: 'ajax_toggle_default.php',
                type: 'POST',
                data: {
                    id: id,
                    current: current
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // 切换星标样式
                        $icon.toggleClass('inactive');
                        // 更新 data-current 属性
                        $this.data('current', response.new_value);
                    } else {
                        alert('更新失败：' + response.message);
                    }
                },
                error: function() {
                    alert('请求失败，请稍后重试');
                }
            });
        });
    });
    </script>
</body>
</html>