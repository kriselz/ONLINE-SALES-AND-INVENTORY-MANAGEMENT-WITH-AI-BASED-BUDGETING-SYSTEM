<?php
require 'vendor/autoload.php';
require_once __DIR__ . '/google_oauth.php';

$client = new Google_Client();

$client->setClientId('');
$client->setClientSecret('');
$client->setRedirectUri(getGoogleRedirectUri());

$client->addScope("email");
$client->addScope("profile");

header('Location: ' . $client->createAuthUrl());
exit();