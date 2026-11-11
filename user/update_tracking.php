<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $order_id = intval($_POST['order_id']);
    $tracking_number = trim($_POST['tracking_number']);
    $estimated_delivery = $_POST['estimated_delivery'];
    $status_update = trim($_POST['status_update']);
    $location = trim($_POST['location']);

    // Get current tracking status
    $order = $conn->query("SELECT tracking_status FROM orders WHERE id = $order_id")->fetch_assoc();
    $tracking_status = json_decode($order['tracking_status'], true) ?: [];

    // Add new update
    $tracking_status[] = [
        'date' => date('Y-m-d H:i:s'),
        'status' => $status_update,
        'location' => $location
    ];

    $tracking_json = json_encode($tracking_status);

    $stmt = $conn->prepare("UPDATE orders SET tracking_number = ?, estimated_delivery = ?, tracking_status = ? WHERE id = ?");
    $stmt->bind_param("sssi", $tracking_number, $estimated_delivery, $tracking_json, $order_id);

    if ($stmt->execute()) {
        $message = "Tracking updated successfully!";
    } else {
        $message = "Error updating tracking.";
    }
}

// Get all orders with tracking info
$orders = $conn->query("SELECT id, fullname, status, tracking_number, estimated_delivery FROM orders ORDER BY order_date DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Update Order Tracking</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>
<div class="container mt-5">
    <h2>Update Order Tracking</h2>
    <?php if ($message): ?>
        <div class="alert alert-info"><?php echo $message; ?></div>
    <?php endif; ?>

    <table class="table">
        <thead>
            <tr>
                <th>Order ID</th>
                <th>Customer</th>
                <th>Status</th>
                <th>Tracking Number</th>
                <th>Estimated Delivery</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($order = $orders->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars(formatOrderNumber($conn, (int)$order['id']), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($order['fullname']); ?></td>
                    <td><?php echo $order['status']; ?></td>
                    <td><?php echo $order['tracking_number'] ?: 'N/A'; ?></td>
                    <td><?php echo $order['estimated_delivery'] ? date('Y-m-d H:i', strtotime($order['estimated_delivery'])) : 'N/A'; ?></td>
                    <td>
                        <button class="btn btn-primary btn-sm" onclick="editTracking(<?php echo $order['id']; ?>, '<?php echo addslashes($order['tracking_number']); ?>', '<?php echo $order['estimated_delivery']; ?>', <?php echo json_encode(formatOrderNumber($conn, (int)$order['id'])); ?>)">Update</button>
                    </td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>

    <div id="editForm" style="display:none;" class="mt-4">
        <h5>Update Tracking for Order <span id="editOrderId"></span></h5>
        <form method="POST">
            <input type="hidden" name="order_id" id="order_id">
            <div class="form-group">
                <label>Tracking Number</label>
                <input type="text" name="tracking_number" id="tracking_number" class="form-control">
            </div>
            <div class="form-group">
                <label>Estimated Delivery</label>
                <input type="datetime-local" name="estimated_delivery" id="estimated_delivery" class="form-control">
            </div>
            <div class="form-group">
                <label>Status Update</label>
                <input type="text" name="status_update" id="status_update" class="form-control" placeholder="e.g., Shipped, In Transit">
            </div>
            <div class="form-group">
                <label>Location</label>
                <input type="text" name="location" id="location" class="form-control" placeholder="e.g., Warehouse, Manila">
            </div>
            <button type="submit" class="btn btn-success">Update Tracking</button>
            <button type="button" onclick="cancelEdit()" class="btn btn-secondary">Cancel</button>
        </form>
    </div>
</div>

<script>
function editTracking(orderId, trackingNumber, estimatedDelivery, orderNumber) {
    document.getElementById('editOrderId').textContent = orderNumber;
    document.getElementById('order_id').value = orderId;
    document.getElementById('tracking_number').value = trackingNumber;
    document.getElementById('estimated_delivery').value = estimatedDelivery;
    document.getElementById('editForm').style.display = 'block';
}

function cancelEdit() {
    document.getElementById('editForm').style.display = 'none';
}
</script>
</body>
</html>