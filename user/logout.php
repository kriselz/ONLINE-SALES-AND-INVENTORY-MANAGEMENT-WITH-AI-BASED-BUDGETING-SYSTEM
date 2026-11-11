<?php
session_start();

if (isset($_POST['confirm_logout'])) {
    if ($_POST['confirm_logout'] == "yes") {
        session_destroy();
        header("Location: login.php");
        exit();
    } else {
        header("Location: product_screen.php"); // babalik sa main page kapag No
        exit();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Logout Confirmation</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        .logout-box {
            background: white;
            width: 400px;
            padding: 35px;
            border-radius: 18px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        }

        h2 {
            color: #d32f2f;
            margin-bottom: 15px;
        }

        p {
            font-size: 16px;
            color: #444;
            margin-bottom: 25px;
        }

        .btn-group {
            display: flex;
            gap: 15px;
        }

        button {
            flex: 1;
            padding: 14px;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
        }

        .yes-btn {
            background: #e53935;
            color: white;
        }

        .no-btn {
            background: #34a853;
            color: white;
        }

        .yes-btn:hover,
        .no-btn:hover {
            opacity: 0.95;
        }
    </style>
</head>
<body>

<div class="logout-box">
    <h2>Logout Confirmation</h2>
    <p>Are you sure you want to log out?</p>

    <form method="POST">
        <div class="btn-group">
            <button type="submit" name="confirm_logout" value="yes" class="yes-btn">
                Yes
            </button>

            <button type="submit" name="confirm_logout" value="no" class="no-btn">
                No
            </button>
        </div>
    </form>
</div>

</body>
</html>