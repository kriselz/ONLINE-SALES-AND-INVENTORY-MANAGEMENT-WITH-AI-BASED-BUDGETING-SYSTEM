<?php
// Simple JSON endpoint to list products for debugging
header('Content-Type: application/json; charset=utf-8');
require_once 'config.php';
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit();
}

$res = $conn->query("SELECT * FROM products ORDER BY id DESC");
if ($res === false) {
    http_response_code(500);
    echo json_encode(['error' => 'db_error', 'details' => $conn->error]);
    exit();
}

$rows = [];
while ($r = $res->fetch_assoc()) {
    $rows[] = $r;
}

echo json_encode(['count' => count($rows), 'products' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
