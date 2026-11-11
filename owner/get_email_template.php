<?php
session_start();
include 'config.php';
include 'functions.php';

// CHECK LOGIN AND ADMIN ROLE
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    http_response_code(403);
    exit();
}

if (isset($_GET['id'])) {
    $template_id = (int)$_GET['id'];
    $sql = "SELECT * FROM email_templates WHERE id = $template_id";
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        $template = $result->fetch_assoc();
        header('Content-Type: application/json');
        echo json_encode($template);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Template not found']);
    }
}
?>
