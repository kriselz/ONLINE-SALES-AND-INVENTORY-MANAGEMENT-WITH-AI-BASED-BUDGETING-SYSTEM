<?php
include 'config.php';

$email = trim($_GET['email'] ?? $_POST['email'] ?? '');
$message = '';
$messageType = 'error';
$pendingUser = null;
$otpExpiresAt = 0;

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $message = 'Invalid verification request.';
} else {
    $lookup = $conn->prepare('SELECT id, otp_code, otp_expiry FROM users WHERE email=? AND email_verified=0 AND is_active=0');
    if (!$lookup) {
        $message = 'Unable to verify this registration. Please try again.';
    } else {
        $lookup->bind_param('s', $email);
        $lookup->execute();
        $pendingUser = $lookup->get_result()->fetch_assoc();

        if (!$pendingUser) {
            $message = 'No pending registration was found for this email. Please register again.';
        } else {
            $otpExpiresAt = !empty($pendingUser['otp_expiry']) ? (int) strtotime($pendingUser['otp_expiry']) : 0;

            if (isset($_POST['resend'])) {
                if ($otpExpiresAt > time()) {
                    $message = 'Your current code is still valid. Wait until it expires before requesting another.';
                } else {
                    $userId = (int) $pendingUser['id'];
                    $oldOtp = $pendingUser['otp_code'];
                    $oldExpiry = $pendingUser['otp_expiry'];
                    $newOtp = (string) random_int(100000, 999999);
                    $newExpiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));

                    $updateOtp = $conn->prepare('UPDATE users SET otp_code=?, otp_expiry=? WHERE id=? AND email_verified=0 AND is_active=0');
                    if (!$updateOtp) {
                        $message = 'Unable to resend the code. Please try again.';
                    } else {
                        $updateOtp->bind_param('ssi', $newOtp, $newExpiry, $userId);
                        if (!$updateOtp->execute()) {
                            $message = 'Unable to resend the code. Please try again.';
                        } else {
                            $errorInfo = '';
                            if (sendOtpEmail($email, $newOtp, $errorInfo)) {
                                $message = 'A new verification code has been sent.';
                                $messageType = 'success';
                                $pendingUser['otp_code'] = $newOtp;
                                $pendingUser['otp_expiry'] = $newExpiry;
                                $otpExpiresAt = (int) strtotime($newExpiry);
                            } else {
                                $restoreOtp = $conn->prepare('UPDATE users SET otp_code=?, otp_expiry=? WHERE id=?');
                                if ($restoreOtp) {
                                    $restoreOtp->bind_param('ssi', $oldOtp, $oldExpiry, $userId);
                                    $restoreOtp->execute();
                                }
                                error_log('Registration OTP resend failed: ' . $errorInfo);
                                $message = 'Could not send a new code. Please try again.';
                            }
                        }
                    }
                }
            } elseif (isset($_POST['verify'])) {
                $otp = trim($_POST['otp'] ?? '');
                if (!preg_match('/^[0-9]{6}$/', $otp) || !hash_equals((string) $pendingUser['otp_code'], $otp)) {
                    $message = 'Invalid OTP code.';
                } elseif ($otpExpiresAt === 0 || $otpExpiresAt < time()) {
                    $message = 'OTP expired. Request a new code below.';
                } else {
                    $userId = (int) $pendingUser['id'];
                    $updateAccount = $conn->prepare('UPDATE users SET email_verified=1, is_active=1, otp_code=NULL, otp_expiry=NULL WHERE id=? AND email_verified=0 AND is_active=0');
                    if ($updateAccount) {
                        $updateAccount->bind_param('i', $userId);
                        if ($updateAccount->execute() && $updateAccount->affected_rows === 1) {
                            header('Location: login.php?verified=success');
                            exit();
                        }
                    }
                    $message = 'Unable to verify your email right now. Please try again.';
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
    <title>Verify Email</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f1f5f2; min-height: 100vh; display: grid; place-items: center; margin: 0; padding: 20px; }
        .card { width: min(400px, 100%); background: #fff; padding: 32px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,.1); text-align: center; }
        h2 { margin: 0 0 10px; color: #202923; }
        .subtitle { margin: 0; color: #667085; line-height: 1.5; }
        .timer { margin: 16px 0; color: #287342; font-size: 13px; }
        .timer.is-expired { color: #c62828; }
        .otp-inputs { display: flex; justify-content: center; gap: 8px; margin: 20px 0 14px; }
        .otp-input { width: 44px; height: 50px; min-width: 0; padding: 8px 4px; border: 1px solid #cbd5ce; border-radius: 8px; text-align: center; font-size: 21px; font-weight: 700; }
        .otp-input:focus { border-color: #278443; outline: 2px solid rgba(39,132,67,.15); }
        button { width: 100%; padding: 13px; border: 0; border-radius: 8px; background: #278443; color: #fff; font-size: 15px; font-weight: 700; cursor: pointer; }
        button:hover:not(:disabled) { background: #206d37; }
        button:disabled { background: #aab8ad; cursor: not-allowed; }
        .resend-form { margin-top: 10px; }
        .resend-form button { background: #edf6ef; color: #206d37; }
        .resend-form button:hover:not(:disabled) { background: #dcefe0; }
        .message { margin: 14px 0; color: #c62828; font-size: 14px; line-height: 1.4; }
        .message.success { color: #287342; }
        .back-link { display: inline-block; margin-top: 18px; color: #287342; font-size: 14px; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
        @media (max-width: 360px) { .card { padding: 24px 18px; } .otp-inputs { gap: 6px; } .otp-input { width: 40px; } }
    </style>
</head>
<body>
    <main class="card">
        <h2>Verify your email</h2>
        <?php if ($pendingUser): ?>
            <p class="subtitle">Enter the 6-digit code sent to your email address.</p>
            <p class="timer" id="otp-timer" data-expires-at="<?= $otpExpiresAt ?>">Code expires in <strong id="otp-countdown">--:--</strong></p>
            <?php if ($message !== ''): ?><div class="message <?= htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8') ?>" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <form method="POST" id="verify-form">
                <div class="otp-inputs">
                    <?php for ($digit = 1; $digit <= 6; $digit++): ?>
                        <input class="otp-input" type="text" inputmode="numeric" pattern="[0-9]" maxlength="1" aria-label="OTP digit <?= $digit ?>" autocomplete="one-time-code" required>
                    <?php endfor; ?>
                </div>
                <input type="hidden" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="otp" id="otp-value">
                <button type="submit" name="verify">Verify Email</button>
            </form>
            <form method="POST" class="resend-form">
                <input type="hidden" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" name="resend" id="resend-button" <?= $otpExpiresAt > time() ? 'disabled' : '' ?>>Resend code</button>
            </form>
        <?php else: ?>
            <?php if ($message !== ''): ?><div class="message" role="alert"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <a class="back-link" href="register.php">Back to registration</a>
        <?php endif; ?>
        <a class="back-link" href="login.php">Back to login</a>
    </main>
    <?php if ($pendingUser): ?>
    <script>
        const otpInputs = Array.from(document.querySelectorAll('.otp-input'));
        const otpValue = document.getElementById('otp-value');
        const verifyForm = document.getElementById('verify-form');

        function updateOtpValue() {
            otpValue.value = otpInputs.map(function (input) { return input.value; }).join('');
        }

        otpInputs.forEach(function (input, index) {
            input.addEventListener('input', function () {
                input.value = input.value.replace(/[^0-9]/g, '').slice(-1);
                if (input.value && otpInputs[index + 1]) otpInputs[index + 1].focus();
                updateOtpValue();
            });
            input.addEventListener('keydown', function (event) {
                if (event.key === 'Backspace' && !input.value && otpInputs[index - 1]) otpInputs[index - 1].focus();
            });
        });

        otpInputs[0].addEventListener('paste', function (event) {
            const digits = event.clipboardData.getData('text').replace(/[^0-9]/g, '').slice(0, 6);
            if (!digits) return;
            event.preventDefault();
            digits.split('').forEach(function (digit, index) { otpInputs[index].value = digit; });
            otpInputs[Math.min(digits.length, 5)].focus();
            updateOtpValue();
        });

        verifyForm.addEventListener('submit', updateOtpValue);

        const timerElement = document.getElementById('otp-timer');
        const countdownElement = document.getElementById('otp-countdown');
        const resendButton = document.getElementById('resend-button');
        const expiresAt = Number(timerElement.dataset.expiresAt) * 1000;

        function updateCountdown() {
            const secondsLeft = Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000));
            const minutes = Math.floor(secondsLeft / 60);
            const seconds = secondsLeft % 60;
            countdownElement.textContent = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
            if (secondsLeft === 0) {
                timerElement.classList.add('is-expired');
                countdownElement.textContent = 'Expired';
                resendButton.disabled = false;
                return;
            }
            resendButton.disabled = true;
        }

        updateCountdown();
        window.setInterval(updateCountdown, 1000);
    </script>
    <?php endif; ?>
</body>
</html>