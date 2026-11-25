<?php

/**
 * @file
 * Replaces core/install.php to block access to the Drupal installer.
 *
 * Mapped into place by drupal-scaffold (see composer.json file-mapping).
 */

http_response_code(403);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Not allowed</title>
  <style>
    html, body { margin: 0; height: 100%; background: #fff; color: #000; }
    body { display: flex; align-items: center; justify-content: center; font-family: sans-serif; }
  </style>
</head>
<body>
  <p>This page is not allowed</p>
</body>
</html>
