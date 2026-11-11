<?php
include 'config.php';

$message = "";

$identifier = $_GET['identifier'];
$type = $_GET['type'];

if (isset($_POST['verify'])) {

    $otp = trim($_POST['otp']);  // ✅ Trim and ensure string
    $otp = (string)$otp;

    $stmt = $conn->prepare("SELECT * FROM users 
        WHERE $type=? 
        AND otp_code=?");

    $stmt->bind_param("ss", $identifier, $otp);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user_row = $result->fetch_assoc();
        $expiry_time = strtotime($user_row['otp_expiry']);
        $current_time = time();
        
        if ($expiry_time >= $current_time) {
            header("Location: reset_password.php?identifier=$identifier&type=$type");
            exit();
        } else {
            $message = "❌ OTP expired!";
        }
    } else {
        $message = "❌ Invalid OTP code!";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Verify OTP</title>
    <style>
        body {
            margin: 0;
            font-family: Arial;
            background: linear-gradient(135deg, #43cea2, #185a9d);
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .card {
            background: white;
            padding: 40px;
            border-radius: 15px;
            width: 350px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            text-align: center;
        }

        h2 {
            margin-bottom: 20px;
            color: #333;
        }

        input {
            width: 100%;
            padding: 12px;
            margin: 10px 0;
            border-radius: 8px;
            border: 1px solid #ccc;
            font-size: 16px;
            text-align: center;
            letter-spacing: 5px;
        }

        input:focus {
            border-color: #43cea2;
            outline: none;
        }

        button {
            width: 100%;
            padding: 12px;
            background: #43cea2;
            border: none;
            color: white;
            border-radius: 8px;
            font-size: 16px;
            cursor: pointer;
            transition: 0.3s;
        }

        button:hover {
            background: #2bbf91;
        }

        .message {
            margin-top: 15px;
            font-size: 14px;
            color: red;
        }

        .info {
            font-size: 12px;
            color: #888;
            margin-bottom: 10px;
        }
    </style>
</head>

<body>

<div class="card">
    <h2>Verify OTP</h2>

    <div class="info">
        Enter the 6-digit code sent to your <?php echo $type; ?>
    </div>

    <form method="POST">
        <input type="text" name="otp" maxlength="6" placeholder="------" required>
        <button name="verify">Verify</button>
    </form>

    <?php if ($message != ""): ?>
        <div class="message"><?php echo $message; ?></div>
    <?php endif; ?>
</div>

</body>
</html>