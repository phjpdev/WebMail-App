<?php

declare(strict_types=1);

/**
 * Upload this file to your domain root (public_html/default.php),
 * alongside the webmail/ folder.
 *
 * Example layout on Hostinger:
 *   public_html/default.php   ← this file
 *   public_html/webmail/      ← the webmail project
 */

$webmailPath = '/webmail/';

// Auto-redirect to the webmail app
if (!headers_sent()) {
    header('Location: ' . $webmailPath, true, 302);
    exit;
}

// Fallback if headers were already sent
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="0;url=<?= htmlspecialchars($webmailPath, ENT_QUOTES, 'UTF-8') ?>">
    <title>D&J Webmail</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f0f2f5;
            color: #1a1d21;
        }
        .card {
            text-align: center;
            padding: 2.5rem;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.08);
        }
        h1 { margin: 0 0 0.5rem; font-size: 1.5rem; }
        p { margin: 0 0 1.25rem; color: #6b7280; }
        a {
            color: #4f46e5;
            font-weight: 600;
            text-decoration: none;
        }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card">
        <h1>D&J Webmail</h1>
        <p>Redirecting to webmail…</p>
        <a href="<?= htmlspecialchars($webmailPath, ENT_QUOTES, 'UTF-8') ?>">Open webmail</a>
    </div>
</body>
</html>
