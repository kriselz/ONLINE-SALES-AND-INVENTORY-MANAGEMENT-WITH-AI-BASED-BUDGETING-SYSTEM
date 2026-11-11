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
$edit_template = null;

// Handle actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] == 'create_template') {
            $template_name = $_POST['template_name'];
            $template_type = $_POST['template_type'];
            $subject = $_POST['subject'];
            $body = $_POST['body'];
            $variables = $_POST['variables'];
            
            if (createEmailTemplate($conn, $template_name, $template_type, $subject, $body, $variables, $_SESSION['user_id'])) {
                $message = "✅ Email template created successfully!";
            } else {
                $error = "❌ Failed to create email template";
            }
        } elseif ($_POST['action'] == 'update_template') {
            $template_id = (int)$_POST['template_id'];
            $subject = $_POST['subject'];
            $body = $_POST['body'];
            
            if (updateEmailTemplate($conn, $template_id, $subject, $body)) {
                $message = "✅ Email template updated successfully!";
            } else {
                $error = "❌ Failed to update email template";
            }
        } elseif ($_POST['action'] == 'toggle_status') {
            $template_id = (int)$_POST['template_id'];
            $is_active = (int)$_POST['is_active'];
            
            $sql = "UPDATE email_templates SET is_active = $is_active WHERE id = $template_id";
            if ($conn->query($sql)) {
                $message = $is_active ? "✅ Template activated!" : "✅ Template deactivated!";
            } else {
                $error = "❌ Failed to update template status";
            }
        }
    }
}

$templates = getEmailTemplates($conn);
?>

<!DOCTYPE html>
<html>
<head>
<title>Email Templates</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; }
    
    .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
    .header { margin-bottom: 30px; }
    .header h1 { color: #2c3e50; font-size: 28px; }
    
    .controls {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
    }
    
    .btn-new {
        padding: 10px 20px;
        background: #27ae60;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-weight: 600;
    }
    
    .btn-new:hover { background: #229954; }
    
    .message {
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 4px;
        font-weight: 500;
    }
    
    .message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    .message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    
    .templates-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .template-card {
        background: white;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        border-left: 4px solid #3498db;
        position: relative;
    }
    
    .template-card h3 {
        color: #2c3e50;
        margin-bottom: 10px;
        font-size: 16px;
    }
    
    .template-info {
        font-size: 13px;
        color: #7f8c8d;
        margin-bottom: 10px;
    }
    
    .template-info span {
        display: inline-block;
        margin-bottom: 5px;
        margin-right: 10px;
    }
    
    .badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 600;
        background: #ecf0f1;
        color: #2c3e50;
    }
    
    .badge-active { background: #d4edda; color: #155724; }
    .badge-inactive { background: #f8d7da; color: #721c24; }
    
    .template-actions {
        display: flex;
        gap: 8px;
        margin-top: 15px;
        padding-top: 15px;
        border-top: 1px solid #ecf0f1;
    }
    
    button {
        padding: 8px 12px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 12px;
        font-weight: 600;
        flex: 1;
    }
    
    .btn-primary {
        background: #3498db;
        color: white;
    }
    
    .btn-danger {
        background: #e74c3c;
        color: white;
    }
    
    .btn-primary:hover { background: #2980b9; }
    .btn-danger:hover { background: #c0392b; }
    
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.4);
        overflow-y: auto;
    }
    
    .modal-content {
        background-color: #fefefe;
        margin: 5% auto;
        padding: 20px;
        border: 1px solid #888;
        border-radius: 8px;
        width: 90%;
        max-width: 700px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.2);
    }
    
    .close {
        color: #aaa;
        float: right;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
    }
    
    .close:hover { color: black; }
    
    .form-group {
        margin-bottom: 15px;
    }
    
    label {
        display: block;
        margin-bottom: 5px;
        color: #2c3e50;
        font-weight: 500;
    }
    
    input, select, textarea {
        width: 100%;
        padding: 10px;
        border: 1px solid #bdc3c7;
        border-radius: 4px;
        font-size: 14px;
        font-family: inherit;
    }
    
    textarea {
        resize: vertical;
        min-height: 200px;
    }
    
    input:focus, select:focus, textarea:focus {
        outline: none;
        border-color: #3498db;
        box-shadow: 0 0 5px rgba(52, 152, 219, 0.5);
    }
    
    .btn-submit {
        background: #3498db;
        color: white;
        width: 100%;
        padding: 12px;
        border: none;
        border-radius: 4px;
        font-weight: 600;
        cursor: pointer;
        font-size: 14px;
    }
    
    .btn-submit:hover { background: #2980b9; }
    
    .help-text {
        background: #ecf0f1;
        padding: 10px;
        border-radius: 4px;
        margin-top: 10px;
        font-size: 12px;
        color: #2c3e50;
    }
    
    .help-text strong { display: block; margin-bottom: 5px; }
    
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
    
    .empty-state {
        text-align: center;
        padding: 40px 20px;
        color: #7f8c8d;
    }
</style>
</head>
<body>

<div class="container">
    <a href="dashboard.php" class="back-button">← Back to Dashboard</a>
    
    <div class="header">
        <h1>📧 Email Templates</h1>
    </div>
    
    <div class="controls">
        <button class="btn-new" onclick="openCreateModal()">+ Create New Template</button>
    </div>
    
    <?php if ($message): ?>
        <div class="message success"><?php echo $message; ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="message error"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <div class="templates-grid">
        <?php
        if ($templates && $templates->num_rows > 0) {
            while ($template = $templates->fetch_assoc()) {
                $status_badge = $template['is_active'] ? 'badge-active' : 'badge-inactive';
                $status_text = $template['is_active'] ? 'Active' : 'Inactive';
                
                echo "<div class='template-card'>";
                echo "<h3>{$template['template_name']}</h3>";
                echo "<div class='template-info'>";
                echo "<span><strong>Type:</strong> {$template['template_type']}</span><br>";
                echo "<span><strong>Creator:</strong> " . htmlspecialchars($template['creator_name'] ?? 'system') . "</span><br>";
                echo "<span><strong>Status:</strong> <span class='badge $status_badge'>$status_text</span></span>";
                echo "</div>";
                
                echo "<div style='background: #f9f9f9; padding: 10px; border-radius: 4px; margin: 10px 0; max-height: 100px; overflow-y: auto;'>";
                echo "<p style='font-size: 12px; color: #2c3e50; margin: 0;'><strong>Subject:</strong> " . substr($template['subject'], 0, 50) . "...</p>";
                echo "</div>";
                
                echo "<div class='template-actions'>";
                echo "<button class='btn-primary' onclick=\"openEditModal({$template['id']})\">Edit</button>";
                
                if ($template['is_active']) {
                    echo "<form method='POST' style='display:inline; flex: 1;'>";
                    echo "<input type='hidden' name='action' value='toggle_status'>";
                    echo "<input type='hidden' name='template_id' value='{$template['id']}'>";
                    echo "<input type='hidden' name='is_active' value='0'>";
                    echo "<button type='submit' class='btn-danger'>Deactivate</button>";
                    echo "</form>";
                } else {
                    echo "<form method='POST' style='display:inline; flex: 1;'>";
                    echo "<input type='hidden' name='action' value='toggle_status'>";
                    echo "<input type='hidden' name='template_id' value='{$template['id']}'>";
                    echo "<input type='hidden' name='is_active' value='1'>";
                    echo "<button type='submit' class='btn-primary'>Activate</button>";
                    echo "</form>";
                }
                
                echo "</div>";
                echo "</div>";
            }
        } else {
            echo "<div class='empty-state' style='grid-column: 1 / -1;'>";
            echo "<p>No email templates found. Click \"Create New Template\" to get started.</p>";
            echo "</div>";
        }
        ?>
    </div>
</div>

<!-- CREATE TEMPLATE MODAL -->
<div id="createModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeCreateModal()">&times;</span>
        <h2>Create New Email Template</h2>
        
        <form method="POST">
            <input type="hidden" name="action" value="create_template">
            
            <div class="form-group">
                <label for="template_name">Template Name:</label>
                <input type="text" id="template_name" name="template_name" required maxlength="100">
                <div class="help-text">Unique name for this template (e.g., "Order Confirmation")</div>
            </div>
            
            <div class="form-group">
                <label for="template_type">Template Type:</label>
                <select id="template_type" name="template_type" required>
                    <option value="">-- Select Type --</option>
                    <option value="Order Confirmation">Order Confirmation</option>
                    <option value="Order Shipped">Order Shipped</option>
                    <option value="Order Delivered">Order Delivered</option>
                    <option value="Return Approved">Return Approved</option>
                    <option value="Ticket Update">Ticket Update</option>
                    <option value="Low Stock Alert">Low Stock Alert</option>
                    <option value="Custom">Custom</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="subject">Email Subject:</label>
                <input type="text" id="subject" name="subject" required placeholder="e.g., Your Order {{order_id}} has been Confirmed">
                <div class="help-text">Use {{variable_name}} for dynamic content</div>
            </div>
            
            <div class="form-group">
                <label for="body">Email Body:</label>
                <textarea id="body" name="body" required placeholder="Enter email content here...

Use these variables:
{{customer_name}} - Customer's full name
{{order_id}} - Order ID
{{total_amount}} - Order total
{{delivery_address}} - Delivery address

Example:
Dear {{customer_name}},

Thank you for your order {{order_id}}!
Total: {{total_amount}}
Delivery to: {{delivery_address}}

Best regards"></textarea>
                <div class="help-text"><strong>Available Variables:</strong> {{customer_name}}, {{order_id}}, {{total_amount}}, {{delivery_address}}, {{product_name}}, {{quantity}}, and more custom variables</div>
            </div>
            
            <div class="form-group">
                <label for="variables">Available Variables (JSON):</label>
                <input type="text" id="variables" name="variables" placeholder='["customer_name", "order_id", "total_amount"]'>
                <div class="help-text">List of variables available in this template (optional)</div>
            </div>
            
            <button type="submit" class="btn-submit" style="margin-top: 20px;">Create Template</button>
        </form>
    </div>
</div>

<!-- EDIT TEMPLATE MODAL -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeEditModal()">&times;</span>
        <h2>Edit Email Template</h2>
        
        <form method="POST">
            <input type="hidden" name="action" value="update_template">
            <input type="hidden" id="editTemplateId" name="template_id" value="">
            
            <div class="form-group">
                <label for="editSubject">Email Subject:</label>
                <input type="text" id="editSubject" name="subject" required>
                <div class="help-text">Use {{variable_name}} for dynamic content</div>
            </div>
            
            <div class="form-group">
                <label for="editBody">Email Body:</label>
                <textarea id="editBody" name="body" required></textarea>
                <div class="help-text">Use {{variable_name}} for placeholders (e.g., {{customer_name}}, {{order_id}})</div>
            </div>
            
            <button type="submit" class="btn-submit" style="margin-top: 20px;">Update Template</button>
        </form>
    </div>
</div>

<script>
function openCreateModal() {
    document.getElementById('createModal').style.display = 'block';
}

function closeCreateModal() {
    document.getElementById('createModal').style.display = 'none';
}

function openEditModal(templateId) {
    // Fetch template data
    fetch('get_email_template.php?id=' + templateId)
        .then(response => response.json())
        .then(data => {
            document.getElementById('editTemplateId').value = data.id;
            document.getElementById('editSubject').value = data.subject;
            document.getElementById('editBody').value = data.body;
            document.getElementById('editModal').style.display = 'block';
        });
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

window.onclick = function(event) {
    const createModal = document.getElementById('createModal');
    const editModal = document.getElementById('editModal');
    
    if (event.target == createModal) {
        createModal.style.display = 'none';
    }
    if (event.target == editModal) {
        editModal.style.display = 'none';
    }
}
</script>

</body>
</html>
