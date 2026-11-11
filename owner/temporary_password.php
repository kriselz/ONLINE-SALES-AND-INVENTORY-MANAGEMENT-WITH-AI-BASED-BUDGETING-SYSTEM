<?php
session_start();

if (($_SESSION['role'] ?? '') === 'rider') {
    header('Location: ../rider/rider_profile.php');
    exit();
}

header('Location: profile.php');
exit();
