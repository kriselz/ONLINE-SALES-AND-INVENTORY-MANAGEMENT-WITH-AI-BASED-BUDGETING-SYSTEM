<?php
include 'config.php';

$identifier = $_GET['identifier'];
$type = $_GET['type'];

if (isset($_POST['reset'])) {

    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    $stmt = $conn->prepare("UPDATE users SET password=? WHERE $type=?");
    $stmt->bind_param("ss", $password, $identifier);
    $stmt->execute();

    echo "Password updated successfully!";
}
?>

<form method="POST">
    <h2>Reset Password</h2>
    <input type="password" name="password" placeholder="New Password" required>
    <button name="reset">Reset</button>
</form>