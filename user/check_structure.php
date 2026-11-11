<?php
include 'config.php';

$result = $conn->query('DESCRIBE support_tickets');
while($row = $result->fetch_assoc()) {
    echo $row['Field'] . ' - ' . $row['Type'] . "\n";
}
?>