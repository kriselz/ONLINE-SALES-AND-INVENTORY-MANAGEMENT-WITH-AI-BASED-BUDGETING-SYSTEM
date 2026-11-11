<?php
require __DIR__ . '/config.php';
$staffRows = $conn->query("SELECT u.id, u.username, u.email FROM users u WHERE u.username IN ('pharmacist1', 'pharmacist2', 'pharmacist3') AND u.role = 'pharmacist' ORDER BY u.username");
if (!$staffRows) { fwrite(STDERR, $conn->error . PHP_EOL); exit(1); }
$staff = [];
while ($row = $staffRows->fetch_assoc()) { $staff[] = $row; }
$ordersColumn = $conn->query("SHOW COLUMNS FROM orders LIKE 'seller_id'");
$movementTable = $conn->query("SHOW TABLES LIKE 'batch_movements'");
foreach ($staff as $user) {
    $id = (int)$user['id'];
    $salesRows = [];
    if ($ordersColumn && $ordersColumn->num_rows) {
        $sales = $conn->query("SELECT id, total_price, order_date, status, delivery_type FROM orders WHERE seller_id = $id ORDER BY id");
        if (!$sales) { fwrite(STDERR, $conn->error . PHP_EOL); exit(1); }
        while ($sale = $sales->fetch_assoc()) {
            $orderId = (int)$sale['id'];
            $items = $conn->query("SELECT oi.product_id, oi.quantity, oi.price, p.product_name FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id WHERE oi.order_id=$orderId");
            $sale['items'] = [];
            if ($items) { while ($item = $items->fetch_assoc()) { $sale['items'][] = $item; } }
            if ($movementTable && $movementTable->num_rows) {
                $movements = $conn->query("SELECT id, batch_id, product_id, quantity, movement_type FROM batch_movements WHERE order_id=$orderId AND created_by=$id");
                $sale['movements'] = [];
                if ($movements) { while ($movement = $movements->fetch_assoc()) { $sale['movements'][] = $movement; } }
            }
            $salesRows[] = $sale;
        }
    }
    $linkedCounts = [];
    foreach (['notifications', 'customer_chat_messages', 'loyalty_accounts', 'loyalty_transactions'] as $table) {
        $exists = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
        if ($exists && $exists->num_rows) {
            $linked = $conn->query("SELECT COUNT(*) AS total FROM `$table` WHERE user_id=$id");
            $linkedCounts[$table] = $linked ? (int)$linked->fetch_assoc()['total'] : -1;
        }
    }
    $inventoryState = [];
    if ($movementTable && $movementTable->num_rows) {
        $inventory = $conn->query("SELECT bm.order_id, bm.batch_id, bm.product_id, bm.quantity, bm.movement_type, p.product_name, p.pieces, p.box, p.pieces_per_box, b.current_quantity AS batch_quantity FROM batch_movements bm JOIN products p ON p.id = bm.product_id LEFT JOIN product_batches b ON b.id = bm.batch_id WHERE bm.created_by = $id ORDER BY bm.id");
        if ($inventory) { while ($movement = $inventory->fetch_assoc()) { $inventoryState[] = $movement; } }
    }
    echo json_encode(['username'=>$user['username'],'id'=>$id,'sales'=>$salesRows,'inventory_state'=>$inventoryState,'linked_records'=>$linkedCounts], JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
