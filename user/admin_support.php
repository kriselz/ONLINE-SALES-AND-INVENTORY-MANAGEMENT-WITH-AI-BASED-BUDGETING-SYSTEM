<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['reply'])) {
        $ticket_id = intval($_POST['ticket_id']);
        $reply_message = trim($_POST['reply_message']);

        $stmt = $conn->prepare("INSERT INTO support_messages (ticket_id, message, is_admin) VALUES (?, ?, 1)");
        $stmt->bind_param("is", $ticket_id, $reply_message);
        $stmt->execute();

        // Update ticket status and updated_at
        $conn->query("UPDATE support_tickets SET status = 'in_progress', updated_at = NOW() WHERE id = $ticket_id");

        $message = "Reply sent!";
    } elseif (isset($_POST['close_ticket'])) {
        $ticket_id = intval($_POST['ticket_id']);
        $conn->query("UPDATE support_tickets SET status = 'closed', updated_at = NOW() WHERE id = $ticket_id");
        $message = "Ticket closed!";
    }
}

$tickets = $conn->query("SELECT st.*, u.username FROM support_tickets st JOIN users u ON st.user_id = u.id ORDER BY st.updated_at DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Support</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>
<div class="container mt-5">
    <h2>Admin Support Dashboard</h2>
    <?php if ($message): ?>
        <div class="alert alert-info"><?php echo $message; ?></div>
    <?php endif; ?>

    <?php while ($ticket = $tickets->fetch_assoc()): ?>
        <div class="card mb-3">
            <div class="card-header bg-light">
                <strong>Ticket #<?php echo $ticket['id']; ?> - <?php echo htmlspecialchars($ticket['subject']); ?></strong>
                <span class="badge badge-<?php echo $ticket['status'] == 'open' ? 'danger' : ($ticket['status'] == 'in_progress' ? 'warning' : 'success'); ?>"><?php echo $ticket['status']; ?></span>
                <small>by <?php echo htmlspecialchars($ticket['username']); ?> - Updated: <?php echo date('Y-m-d H:i', strtotime($ticket['updated_at'])); ?></small>
            </div>
            <div class="card-body">
                <?php
                $messages = $conn->query("SELECT sm.*, u.username FROM support_messages sm LEFT JOIN users u ON sm.user_id = u.id WHERE sm.ticket_id = " . $ticket['id'] . " ORDER BY sm.created_at");
                while ($msg = $messages->fetch_assoc()): ?>
                    <div class="mb-2 p-2 border rounded">
                        <strong><?php echo $msg['is_admin'] ? 'Support' : htmlspecialchars($msg['username']); ?>:</strong>
                        <?php echo htmlspecialchars($msg['message']); ?>
                        <small class="text-muted d-block">(<?php echo date('Y-m-d H:i', strtotime($msg['created_at'])); ?>)</small>
                    </div>
                <?php endwhile; ?>

                <?php if ($ticket['status'] !== 'closed'): ?>
                    <form method="POST" class="mt-3">
                        <input type="hidden" name="ticket_id" value="<?php echo $ticket['id']; ?>">
                        <div class="form-group">
                            <textarea name="reply_message" class="form-control" rows="2" placeholder="Reply..."></textarea>
                        </div>
                        <button type="submit" name="reply" class="btn btn-primary">Reply</button>
                        <button type="submit" name="close_ticket" class="btn btn-success">Close Ticket</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endwhile; ?>
</div>
</body>
</html>