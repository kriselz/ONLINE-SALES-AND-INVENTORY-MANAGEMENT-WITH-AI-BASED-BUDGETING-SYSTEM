<?php
include 'config.php';

$message = "";
$messageType = "";
$showVerifyForm = false;
$showResetForm = false;
$identifier = "";
$type = "";
$step = 'send';

if (isset($_POST['send'])) {
    $identifier = trim($_POST['identifier']);

    if ($identifier === "") {
        $message = "❌ Please enter your email or phone number.";
        $messageType = "error";
    } else {
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $type = "email";
        } else {
            $type = "phone";
        }

        $stmt = $conn->prepare("SELECT * FROM users WHERE $type=?");
        $stmt->bind_param("s", $identifier);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $otp = (string)rand(100000, 999999);  // ✅ Explicitly cast to string
            $expiry = date("Y-m-d H:i:s", strtotime("+30 minutes"));  // Extended to 30 minutes for testing

            $allowedTypes = ['email', 'phone'];
            if (!in_array($type, $allowedTypes, true)) {
                $message = "❌ Invalid request.";
                $messageType = "error";
            } else {
                $column = $type === 'email' ? 'email' : 'phone';
                $stmt = $conn->prepare("UPDATE users SET otp_code=?, otp_expiry=?, otp_attempts=0 WHERE $column=?");
                if (!$stmt) {
                    $message = '❌ Server error: could not prepare update query.';
                    $messageType = 'error';
                } else {
                    $stmt->bind_param("sss", $otp, $expiry, $identifier);
                    $stmt->execute();

                    if ($type == "email") {
                        $errorInfo = '';
                        if (sendOtpEmail($identifier, $otp, $errorInfo)) {
                            $message = "✅ OTP sent to your email!";
                            $messageType = "success";
                        } else {
                            $message = "❌ Email error: $errorInfo";
                            $messageType = "error";
                        }
                    } else {
                        $message = "📱 OTP sent to phone: $otp";
                        $messageType = "success";
                    }

                    if ($messageType === "success") {
                        $showVerifyForm = true;
                        $step = 'verify';
                    }
                }
            }
        } else {
            $message = "❌ User not found!";
            $messageType = "error";
        }
    }
}

if (isset($_POST['verify'])) {
    $identifier = trim($_POST['identifier']);
    $type = $_POST['type'];
    $otp = trim($_POST['otp']);  // ✅ Trim whitespace
    $otp = (string)$otp;  // ✅ Ensure it's a string

    $allowedTypes = ['email', 'phone'];
    if (!in_array($type, $allowedTypes, true)) {
        $message = "❌ Invalid request.";
        $messageType = "error";
        $showVerifyForm = true;
    } else {
        $column = $type === 'email' ? 'email' : 'phone';
        $stmt = $conn->prepare("SELECT otp_block_until, otp_attempts, otp_expiry FROM users WHERE $column=?");
        $stmt->bind_param("s", $identifier);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $block_until = $row['otp_block_until'];
            $attempts = $row['otp_attempts'];
            $otp_expiry = $row['otp_expiry'];
            if ($block_until && strtotime($block_until) > time()) {
                $remaining = strtotime($block_until) - time();
                $message = "Too many attempts. Try again in " . ceil($remaining / 60) . " minutes.";
                $messageType = "error";
                $showVerifyForm = true;
            } else {
                if ($otp === "") {
                    $message = "❌ Please enter the OTP code.";
                    $messageType = "error";
                    $showVerifyForm = true;
                } else {
                    $stmt = $conn->prepare("SELECT * FROM users WHERE $column=? AND otp_code=?");
                    $stmt->bind_param("ss", $identifier, $otp);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        $user_row = $result->fetch_assoc();
                        $expiry_time = strtotime($user_row['otp_expiry']);
                        $current_time = time();
                        
                        if ($expiry_time >= $current_time) {
                            $showResetForm = true;
                            $showVerifyForm = false;
                            $step = 'reset';
                            $message = "✅ OTP verified. Please enter your new password.";
                            $messageType = "success";
                        } else {
                            $attempts++;
                            $stmt = $conn->prepare("UPDATE users SET otp_attempts=? WHERE $column=?");
                            $stmt->bind_param("is", $attempts, $identifier);
                            $stmt->execute();
                            $message = "❌ OTP expired! Attempts left: " . (3 - $attempts);
                            $messageType = "error";
                            $showVerifyForm = true;
                        }
                    } else {
                        $attempts++;
                        if ($attempts >= 3) {
                            $block_until = date("Y-m-d H:i:s", strtotime("+30 minutes"));
                            $stmt = $conn->prepare("UPDATE users SET otp_attempts=?, otp_block_until=? WHERE $column=?");
                            $stmt->bind_param("iss", $attempts, $block_until, $identifier);
                            $stmt->execute();
                            $message = "Too many attempts. Try again in 30 minutes.";
                            $messageType = "error";
                            $showVerifyForm = true;
                        } else {
                            $stmt = $conn->prepare("UPDATE users SET otp_attempts=? WHERE $column=?");
                            $stmt->bind_param("is", $attempts, $identifier);
                            $stmt->execute();
                            $message = "❌ Invalid OTP! Attempts left: " . (3 - $attempts);
                            $messageType = "error";
                            $showVerifyForm = true;
                        }
                    }
                }
            }
        } else {
            $message = "❌ User not found.";
            $messageType = "error";
            $showVerifyForm = true;
        }
    }
}

if (isset($_POST['reset'])) {
    $identifier = trim($_POST['identifier']);
    $type = $_POST['type'];
    $password = $_POST['password'];
    $confirmPassword = $_POST['confirm_password'];

    $step = 'reset';
    $showResetForm = true;

    if ($password === "" || $confirmPassword === "") {
        $message = "❌ Please fill in both password fields.";
        $messageType = "error";
    } elseif ($password !== $confirmPassword) {
        $message = "❌ Passwords do not match.";
        $messageType = "error";
    } elseif (strlen($password) < 6) {
        $message = "❌ Password must be at least 6 characters.";
        $messageType = "error";
    } else {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $allowedTypes = ['email', 'phone'];

        if (!in_array($type, $allowedTypes, true)) {
            $message = "❌ Invalid request.";
            $messageType = "error";
        } else {
            $column = $type === 'email' ? 'email' : 'phone';
            $stmt = $conn->prepare("UPDATE users SET password=?, otp_code=NULL, otp_expiry=NULL WHERE $column=?");
            if (!$stmt) {
                $message = "❌ Server error: could not prepare password update.";
                $messageType = "error";
            } else {
                $stmt->bind_param("ss", $hashedPassword, $identifier);
                if ($stmt->execute()) {
                    header("Location: login.php?reset=success");
                    exit();
                } else {
                    $message = "❌ Server error: could not update password.";
                    $messageType = "error";
                    $showResetForm = true;
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            margin: 0;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f4f7f9;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .card {
            background: #ffffff;
            border-radius: 20px;
            width: 100%;
            max-width: 400px;
            padding: 40px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            text-align: center;
        }

        .key-icon {
            font-size: 40px;
            color: #e63946;
            margin-bottom: 20px;
            display: inline-block;
        }

        h2 {
            margin: 0 0 12px;
            font-size: 24px;
            font-weight: 700;
            color: #333;
        }

        .subtitle {
            margin: 0 0 30px;
            color: #888;
            font-size: 14px;
            line-height: 1.5;
            padding: 0 10px;
        }

        .input-container {
            position: relative;
            margin-bottom: 20px;
        }

        .input-container i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #aaa;
            font-size: 16px;
        }

        .input-container input {
            width: 100%;
            padding: 14px 15px 14px 45px;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            font-size: 15px;
            box-sizing: border-box;
            transition: border-color 0.3s;
        }

        .input-container input:focus {
            outline: none;
            border-color: #e63946;
        }

        .password-toggle {
            position: absolute;
            right: 45px;
            top: 50%;
            transform: translateY(-50%);
            color: #888;
            cursor: pointer;
            font-size: 16px;
        }

        button {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 10px;
            background: #e63946;
            color: white;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        button:hover {
            background: #d62d3a;
        }

        .back-link {
            display: inline-block;
            margin-top: 25px;
            color: #888;
            text-decoration: none;
            font-size: 14px;
            transition: color 0.2s;
        }

        .otp-container {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .otp-input {
            width: 40px;
            height: 40px;
            text-align: center;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            font-size: 18px;
            font-weight: 600;
            transition: border-color 0.3s;
        }

        .otp-input:focus {
            outline: none;
            border-color: #e63946;
        }

        .message {
            padding: 12px;
            border-radius: 10px;
            font-size: 13px;
            margin-bottom: 20px;
            border: 1px solid;
        }

        .message.success {
            background: #e8f5e8;
            color: #2e7d32;
            border-color: #c8e6c9;
            animation: fadeIn 0.5s ease-in;
        }

        .message.error {
            background: #ffebee;
            color: #c62828;
            border-color: #ffcdd2;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
    </style>
</head>
<body>

<div class="card">
    <div class="loading-bar" id="loadingBar"></div>
    <div class="key-icon">
        <i class="fa-solid fa-key"></i>
    </div>
    
    <h2>Forgot Password</h2>
    <?php if ($step === 'send'): ?>
        <p class="subtitle">Enter your registered email or phone number to receive an OTP.</p>
    <?php elseif ($step === 'verify'): ?>
        <p class="subtitle">Enter the 6-digit code sent to your <?php echo htmlspecialchars($type); ?>.</p>
        <p id="timer" style="color: #888; font-size: 12px;">OTP expires in: <span id="countdown">05:00</span></p>
    <?php elseif ($step === 'reset'): ?>
        <p class="subtitle">Create a new password and confirm it.</p>
    <?php else: ?>
        <p class="subtitle">Your password has been updated. You may now log in.</p>
    <?php endif; ?>

    <?php if ($showResetForm): ?>
        <form method="POST" onsubmit="showLoading()">
            <div class="input-container">
                <i class="fa-solid fa-lock"></i>
                <input id="password" type="password" name="password" placeholder="New Password" required>
                <span class="password-toggle" onclick="togglePassword('password', this)"><i class="fa-solid fa-eye"></i></span>
            </div>
            <div class="input-container">
                <i class="fa-solid fa-lock"></i>
                <input id="confirm_password" type="password" name="confirm_password" placeholder="Confirm New Password" required>
            </div>
            <input type="hidden" name="identifier" value="<?php echo htmlspecialchars($identifier); ?>">
            <input type="hidden" name="type" value="<?php echo htmlspecialchars($type); ?>">
            <button type="submit" name="reset">Reset Password</button>
        </form>
    <?php elseif ($showVerifyForm): ?>
        <form method="POST" onsubmit="showLoading(); combineOTP()">
            <div class="otp-container">
                <input type="text" class="otp-input" id="otp1" maxlength="1" oninput="moveToNext(this, 1)" onkeydown="handleBackspace(this, 1)" required>
                <input type="text" class="otp-input" id="otp2" maxlength="1" oninput="moveToNext(this, 2)" onkeydown="handleBackspace(this, 2)" required>
                <input type="text" class="otp-input" id="otp3" maxlength="1" oninput="moveToNext(this, 3)" onkeydown="handleBackspace(this, 3)" required>
                <input type="text" class="otp-input" id="otp4" maxlength="1" oninput="moveToNext(this, 4)" onkeydown="handleBackspace(this, 4)" required>
                <input type="text" class="otp-input" id="otp5" maxlength="1" oninput="moveToNext(this, 5)" onkeydown="handleBackspace(this, 5)" required>
                <input type="text" class="otp-input" id="otp6" maxlength="1" oninput="moveToNext(this, 6)" onkeydown="handleBackspace(this, 6)" required>
            </div>
            <input type="hidden" name="otp" id="otp">
            <input type="hidden" name="identifier" value="<?php echo htmlspecialchars($identifier); ?>">
            <input type="hidden" name="type" value="<?php echo htmlspecialchars($type); ?>">
            <button type="submit" name="verify">Verify OTP</button>
        </form>
    <?php else: ?>
        <form method="POST" onsubmit="showLoading()">
            <div class="input-container">
                <i class="fa-solid fa-user-shield"></i>
                <input type="text" name="identifier" placeholder="Email or Phone Number" value="<?php echo htmlspecialchars($identifier); ?>" required>
            </div>
            <button type="submit" name="send">Send OTP Code</button>
        </form>
    <?php endif; ?>

    <?php if ($message != ""): ?>
        <div class="message <?php echo $messageType; ?>"><?php echo $message; ?></div>
    <?php endif; ?>

    <a href="login.php" class="back-link">
        <i class="fa-solid fa-arrow-left"></i> Back to Login
    </a>
</div>

<script src="back-navigation.js"></script>
<script>
    function showLoading() {
        document.getElementById('loadingBar').style.display = 'block';
    }

    function togglePassword(fieldId, iconElement) {
        const input = document.getElementById(fieldId);
        const icon = iconElement.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }

    function moveToNext(current, index) {
        // Allow only digits
        current.value = current.value.replace(/[^0-9]/g, '');
        if (current.value.length === 1 && index < 6) {
            document.getElementById('otp' + (index + 1)).focus();
        }
        combineOTP();
    }

    function handleBackspace(current, index) {
        if (event.key === 'Backspace' && current.value === '' && index > 1) {
            document.getElementById('otp' + (index - 1)).focus();
        }
    }

    function combineOTP() {
        let otp = '';
        for (let i = 1; i <= 6; i++) {
            otp += document.getElementById('otp' + i).value;
        }
        document.getElementById('otp').value = otp;
    }

    function startCountdown() {
        let timeLeft = 300; // 5 minutes
        const countdownElement = document.getElementById('countdown');
        const timer = setInterval(() => {
            const minutes = Math.floor(timeLeft / 60);
            const seconds = timeLeft % 60;
            countdownElement.textContent = minutes.toString().padStart(2, '0') + ':' + seconds.toString().padStart(2, '0');
            timeLeft--;
            if (timeLeft < 0) {
                clearInterval(timer);
                countdownElement.textContent = 'Expired';
                // Optionally disable form
            }
        }, 1000);
    }

    // Start countdown if on verify step
    if ('<?php echo $step; ?>' === 'verify') {
        startCountdown();
    }
</script>

</body>
</html>