<?php
session_start();
include 'config.php';
include 'functions.php';
require_once __DIR__ . '/access_control.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])
    || (($_SESSION['role'] ?? '') !== 'admin' && !isActivePharmacist($conn))) {
    http_response_code(403);
    echo json_encode(['error' => 'You do not have permission to view supplier details.']);
    exit;
}

if (!isset($_GET['id']) || filter_var($_GET['id'], FILTER_VALIDATE_INT) === false || (int)$_GET['id'] < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'A valid supplier ID is required.']);
    exit;
}

try {
    if (!ensureSuppliersTable($conn)) {
        throw new RuntimeException('Unable to prepare supplier data: ' . $conn->error);
    }
    $supplierId = (int)$_GET['id'];
    $statement = $conn->prepare('SELECT * FROM suppliers WHERE id = ? LIMIT 1');
    if (!$statement) {
        throw new RuntimeException('Unable to prepare supplier lookup: ' . $conn->error);
    }
    $statement->bind_param('i', $supplierId);
    if (!$statement->execute()) {
        throw new RuntimeException('Unable to load supplier details: ' . $statement->error);
    }
    $supplier = $statement->get_result()->fetch_assoc();
    $statement->close();

    if (!$supplier) {
        http_response_code(404);
        echo json_encode(['error' => 'Supplier not found.']);
        exit;
    }
    echo json_encode($supplier, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log('Supplier detail lookup failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load supplier details. Please try again.']);
}
?>
