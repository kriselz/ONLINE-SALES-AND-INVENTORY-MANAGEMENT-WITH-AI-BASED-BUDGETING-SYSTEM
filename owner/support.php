<?php
session_start();
include 'config.php';
include 'functions.php';

// CHECK LOGIN AND ADMIN ROLE
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: login_a.php");
    exit();
}

$message = '';
$error = '';
$ticket = null;
$messages_list = null;

// Handle actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] == 'add_reply') {
            $ticket_id = (int)$_POST['ticket_id'];
            $reply_text = trim($_POST['reply_text']);
            
            if (!empty($reply_text)) {
                if (addSupportMessage($conn, $ticket_id, $_SESSION['user_id'], $reply_text, 1)) {
                    updateTicketStatus($conn, $ticket_id, 'In Progress');
                    $message = "✅ Reply sent!";
                } else {
                    $error = "❌ Failed to send reply";
                }
            } else {
                $error = "❌ Reply cannot be empty";
            }
        } elseif ($_POST['action'] == 'update_status') {
            $ticket_id = (int)$_POST['ticket_id'];
            $status = $_POST['status'];
            
            if (updateTicketStatus($conn, $ticket_id, $status)) {
                $message = "✅ Ticket status updated!";
            } else {
                $error = "❌ Failed to update status";
            }
        }
    }
}

// Get ticket ID from URL if viewing specific ticket
$ticket_id = isset($_GET['id']) ? (int)$_GET['id'] : null;

if ($ticket_id) {
    $ticket = getSupportTicketById($conn, $ticket_id);
    if ($ticket) {
        $messages_list = getSupportMessages($conn, $ticket_id);
    } else {
        header("Location: support.php");
        exit();
    }
}

$all_tickets = getAllSupportTickets($conn);
?>

<!DOCTYPE html>
<html>
<head>
<title>Support Tickets Management</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; }
    
    .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
    .header { margin-bottom: 30px; }
    .header h1 { color: #2c3e50; font-size: 28px; }
    
    .back-button {
        display: inline-block;
        padding: 10px 20px;
        background: #3498db;
        color: white;
        text-decoration: none;
        border-radius: 4px;
        margin-bottom: 20px;
    }
    
    .back-button:hover { background: #2980b9; }
    
    .message {
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 4px;
        font-weight: 500;
    }
    
    .message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    .message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    
    .grid {
        display: grid;
        grid-template-columns: 300px 1fr;
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .tickets-list {
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        max-height: 600px;
        overflow-y: auto;
    }
    
    .ticket-item {
        padding: 15px;
        border-bottom: 1px solid #ecf0f1;
        cursor: pointer;
        transition: background 0.2s;
    }
    
    .ticket-item:hover { background: #f9f9f9; }
    .ticket-item.active { background: #e3f2fd; border-left: 4px solid #3498db; }
    
    .ticket-number { color: #7f8c8d; font-size: 12px; }
    .ticket-subject { font-weight: 600; color: #2c3e50; margin: 5px 0; }
    .ticket-meta { font-size: 12px; color: #7f8c8d; }
    
    .status-badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 600;
        margin-top: 5px;
    }
    
    .status-open { background: #fff3cd; color: #856404; }
    .status-in-progress { background: #cfe2ff; color: #084298; }
    .status-closed { background: #d1e7dd; color: #0f5132; }
    .status-resolved { background: #d1e7dd; color: #0f5132; }
    
    .ticket-detail {
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .ticket-header {
        padding: 20px;
        border-bottom: 1px solid #ecf0f1;
    }
    
    .ticket-header h2 { color: #2c3e50; margin-bottom: 10px; }
    .ticket-header .ticket-meta { color: #7f8c8d; }
    
    .messages-container {
        padding: 20px;
        max-height: 400px;
        overflow-y: auto;
        border-bottom: 1px solid #ecf0f1;
    }
    
    .message-bubble {
        margin-bottom: 15px;
        padding: 12px;
        border-radius: 4px;
        background: #f9f9f9;
        border-left: 4px solid #3498db;
    }
    
    .message-bubble.admin {
        background: #e8f4f8;
        border-left-color: #27ae60;
    }
    
    .message-sender {
        font-weight: 600;
        color: #2c3e50;
        margin-bottom: 5px;
    }
    
    .message-sender .admin-badge {
        background: #27ae60;
        color: white;
        padding: 2px 6px;
        border-radius: 3px;
        font-size: 11px;
        margin-left: 5px;
    }
    
    .message-time {
        font-size: 12px;
        color: #7f8c8d;
        margin-top: 5px;
    }
    
    .reply-form {
        padding: 20px;
    }
    
    .form-group {
        margin-bottom: 15px;
    }
    
    label {
        display: block;
        margin-bottom: 5px;
        color: #2c3e50;
        font-weight: 500;
    }
    
    textarea, select {
        width: 100%;
        padding: 10px;
        border: 1px solid #bdc3c7;
        border-radius: 4px;
        font-size: 14px;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    
    textarea:focus, select:focus {
        outline: none;
        border-color: #3498db;
        box-shadow: 0 0 5px rgba(52, 152, 219, 0.5);
    }
    
    button {
        padding: 10px 20px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-weight: 600;
        font-size: 14px;
    }
    
    .btn-primary {
        background: #3498db;
        color: white;
    }
    
    .btn-primary:hover { background: #2980b9; }
    
    .empty-state {
        padding: 40px;
        text-align: center;
        color: #7f8c8d;
    }
    
    .controls {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
    }
    
    .status-filter {
        padding: 8px 15px;
        border: 1px solid #bdc3c7;
        border-radius: 4px;
        background: white;
        cursor: pointer;
        font-size: 14px;
    }
    
    .status-filter.active {
        background: #3498db;
        color: white;
        border-color: #3498db;
    }
</style>
</head>
<body>

<div class="container">
    <a href="dashboard.php" class="back-button">← Back to Dashboard</a>
    
    <div class="header">
        <h1>🆘 Support Tickets Management</h1>
    </div>
    
    <?php if ($message): ?>
        <div class="message success"><?php echo $message; ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="message error"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <?php if (!$ticket): ?>
        <!-- TICKETS LIST VIEW -->
        <div class="controls">
            <a href="support.php" class="status-filter active">All</a>
            <a href="support.php?filter=Open" class="status-filter">Open</a>
            <a href="support.php?filter=In Progress" class="status-filter">In Progress</a>
            <a href="support.php?filter=Resolved" class="status-filter">Resolved</a>
        </div>
        
        <div class="tickets-list">
            <?php
            if ($all_tickets && $all_tickets->num_rows > 0) {
                while ($t = $all_tickets->fetch_assoc()) {
                    $status_class = 'status-' . strtolower(str_replace(' ', '-', $t['status']));
                    $status_badge = "<span class='status-badge $status_class'>{$t['status']}</span>";
                    
                    echo "<div class='ticket-item' onclick=\"window.location.href='support.php?id={$t['id']}'\">";
                    echo "<div class='ticket-number'>#" . str_pad($t['id'], 5, '0', STR_PAD_LEFT) . "</div>";
                    echo "<div class='ticket-subject'>" . htmlspecialchars(substr($t['subject'], 0, 40)) . "</div>";
                    echo "<div class='ticket-meta'>👤 {$t['username']} • 💬 {$t['message_count']} replies</div>";
                    echo $status_badge;
                    echo "</div>";
                }
            } else {
                echo "<div class='empty-state'>No support tickets yet.</div>";
            }
            ?>
        </div>
        
    <?php else: ?>
        <!-- SINGLE TICKET VIEW -->
        <div class="ticket-detail">
            <div class="ticket-header">
                <h2><?php echo htmlspecialchars($ticket['subject']); ?></h2>
                <div class="ticket-meta">
                    From: <strong><?php echo htmlspecialchars($ticket['username']); ?></strong> (<?php echo htmlspecialchars($ticket['email']); ?>)<br>
                    Created: <?php echo date('Y-m-d H:i', strtotime($ticket['created_at'])); ?> •
                    Updated: <?php echo date('Y-m-d H:i', strtotime($ticket['updated_at'])); ?> •
                    <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $ticket['status'])); ?>"><?php echo $ticket['status']; ?></span>
                </div>
            </div>
            
            <div class="messages-container">
                <?php
                if ($messages_list && $messages_list->num_rows > 0) {
                    while ($msg = $messages_list->fetch_assoc()) {
                        $msg_class = $msg['is_admin'] ? 'admin' : '';
                        $sender_label = $msg['is_admin'] ? "<strong class='admin-badge'>ADMIN</strong>" : '';
                        
                        echo "<div class='message-bubble $msg_class'>";
                        echo "<div class='message-sender'>" . htmlspecialchars($msg['username'] ?? 'System') . " $sender_label</div>";
                        echo "<div>" . nl2br(htmlspecialchars($msg['message'])) . "</div>";
                        echo "<div class='message-time'>" . date('Y-m-d H:i', strtotime($msg['created_at'])) . "</div>";
                        echo "</div>";
                    }
                }
                ?>
            </div>
            
            <div class="reply-form">
                <form method="POST">
                    <input type="hidden" name="action" value="add_reply">
                    <input type="hidden" name="ticket_id" value="<?php echo $ticket['id']; ?>">
                    
                    <div class="form-group">
                        <label>Update Status:</label>
                        <select name="status" onchange="updateStatus(<?php echo $ticket['id']; ?>, this.value)">
                            <option value="Open" <?php echo $ticket['status'] == 'Open' ? 'selected' : ''; ?>>Open</option>
                            <option value="In Progress" <?php echo $ticket['status'] == 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="Resolved" <?php echo $ticket['status'] == 'Resolved' ? 'selected' : ''; ?>>Resolved</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Your Reply:</label>
                        <textarea name="reply_text" rows="4" placeholder="Type your reply here..."></textarea>
                    </div>
                    
                    <button type="submit" class="btn-primary">Send Reply</button>
                    <a href="support.php" class="btn-primary" style="text-decoration: none; display: inline-block;">Back to Tickets</a>
                </form>
            </div>
        </div>
        
    <?php endif; ?>
</div>

<script>
function updateStatus(ticketId, status) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="update_status">
        <input type="hidden" name="ticket_id" value="${ticketId}">
        <input type="hidden" name="status" value="${status}">
    `;
    document.body.appendChild(form);
    form.submit();
}
</script>

</body>
</html>
