<?php
session_start();
include 'config.php';
include 'functions.php';

// Check if admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_coupon'])) {
        $code = trim($_POST['code']);
        $discount_type = $_POST['discount_type'];
        $discount_value = floatval($_POST['discount_value']);
        $min_order = floatval($_POST['min_order']);
        $usage_limit = !empty($_POST['usage_limit']) ? intval($_POST['usage_limit']) : NULL;
        $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : NULL;

        $stmt = $conn->prepare("INSERT INTO coupons (code, discount_type, discount_value, min_order, usage_limit, expiry_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssddisi", $code, $discount_type, $discount_value, $min_order, $usage_limit, $expiry_date, $_SESSION['user_id']);
        if ($stmt->execute()) {
            $message = "Coupon added successfully!";
        } else {
            $message = "Error adding coupon: " . $conn->error;
        }
    } elseif (isset($_POST['delete_coupon'])) {
        $id = intval($_POST['coupon_id']);
        $stmt = $conn->prepare("DELETE FROM coupons WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $message = "Coupon deleted!";
    }
}

$coupons = $conn->query("SELECT * FROM coupons ORDER BY created_at DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Coupons</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>
<div class="container mt-5">
    <h2>Manage Coupons</h2>
    <?php if ($message): ?>
        <div class="alert alert-info"><?php echo $message; ?></div>
    <?php endif; ?>

    <form method="POST" class="mb-4">
        <div class="form-row">
            <div class="col-md-2">
                <input type="text" name="code" class="form-control" placeholder="Code" required>
            </div>
            <div class="col-md-2">
                <select name="discount_type" class="form-control" required>
                    <option value="percent">Percent</option>
                    <option value="fixed">Fixed</option>
                </select>
            </div>
            <div class="col-md-2">
                <input type="number" step="0.01" name="discount_value" class="form-control" placeholder="Value" required>
            </div>
            <div class="col-md-2">
                <input type="number" step="0.01" name="min_order" class="form-control" placeholder="Min Order" value="0">
            </div>
            <div class="col-md-2">
                <input type="number" name="usage_limit" class="form-control" placeholder="Usage Limit">
            </div>
            <div class="col-md-2">
                <input type="datetime-local" name="expiry_date" class="form-control">
            </div>
        </div>
        <button type="submit" name="add_coupon" class="btn btn-primary mt-2">Add Coupon</button>
    </form>

    <table class="table">
        <thead>
            <tr>
                <th>Code</th>
                <th>Type</th>
                <th>Value</th>
                <th>Min Order</th>
                <th>Used</th>
                <th>Expiry</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($coupon = $coupons->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($coupon['code']); ?></td>
                    <td><?php echo $coupon['discount_type']; ?></td>
                    <td><?php echo $coupon['discount_value']; ?></td>
                    <td><?php echo $coupon['min_order']; ?></td>
                    <td><?php echo $coupon['used_count']; ?>/<?php echo $coupon['usage_limit'] ?: '∞'; ?></td>
                    <td><?php echo $coupon['expiry_date']; ?></td>
                    <td>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="coupon_id" value="<?php echo $coupon['id']; ?>">
                            <button type="submit" name="delete_coupon" class="btn btn-danger btn-sm">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>
</body>
</html>