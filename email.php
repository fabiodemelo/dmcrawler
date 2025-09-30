<?php
// email.php — simple HTML email sender test
// Place this file on your server and open it in a browser.

if ($_SERVER['REQUEST_METHOD'] === 'POST') { 
    // Adjust as needed
    $toEmail   = 'fabio@demelos.com';
    $fromEmail = 'fabio@demelos.com';

    // Collect and sanitize inputs
    $subject = isset($_POST['subject']) ? trim($_POST['subject']) : '';
    $message = isset($_POST['message']) ? trim($_POST['message']) : '';

    if ($subject === '') {
        $subject = 'Mail test from server';
    }
    if ($message === '') {
        $message = 'This is a test HTML email from the server.';
    }

    // Build headers for HTML email
    $headers = array();
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';
    $headers[] = 'From: ' . $fromEmail;
    $headers[] = 'Reply-To: ' . $fromEmail;

    // Basic HTML body
    $htmlBody = '<!doctype html>
<html>
<head><meta charset="utf-8"><title>Email Test</title></head>
<body style="font-family:Arial,Helvetica,sans-serif; color:#222;">
  <h2 style="margin:0 0 10px;">Server Email Test</h2>
  <p style="margin:0 0 10px;">This is a test email sent from your server.</p>
  <hr style="border:none;border-top:1px solid #ddd;margin:10px 0;">
  <div>' . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . '</div>
  <hr style="border:none;border-top:1px solid #ddd;margin:10px 0;">
  <p style="font-size:12px;color:#666;margin:0;">Sent: ' . date('Y-m-d H:i:s') . '</p>
</body>
</html>';

    // Send
    $ok = @mail($toEmail, $subject, $htmlBody, implode("\r\n", $headers));

    // Feedback to user
    $statusMsg = $ok
        ? 'Email sent successfully to ' . htmlspecialchars($toEmail, ENT_QUOTES, 'UTF-8') . '.'
        : 'Failed to send email. Check server mail configuration.';
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Email Test</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: Arial, Helvetica, sans-serif; margin: 24px; color: #222; }
        .wrap { max-width: 640px; margin: 0 auto; }
        h1 { font-size: 20px; margin: 0 0 12px; }
        .note { color: #555; font-size: 13px; margin-bottom: 16px; }
        label { display: block; font-weight: bold; margin: 12px 0 6px; }
        input[type="text"], textarea { width: 100%; box-sizing: border-box; padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
        textarea { min-height: 140px; }
        .btn { margin-top: 12px; padding: 10px 14px; background: #1976d2; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
        .btn:hover { background: #155a9b; }
        .alert { margin: 12px 0; padding: 10px; border-radius: 4px; }
        .alert.ok { background: #e6f5ea; color: #1b5e20; border: 1px solid #a5d6a7; }
        .alert.err { background: #fdecea; color: #b71c1c; border: 1px solid #ef9a9a; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Email Test (HTML)</h1>
    <div class="note">
        This sends an HTML email using PHP mail() from and to: <strong>fabio@demelos.com</strong>.<br>
        You can edit subject and message below, then click Send Test Email.
    </div>

    <?php if (!empty($statusMsg)): ?>
        <div class="alert <?php echo (!empty($ok) ? 'ok' : 'err'); ?>">
            <?php echo htmlspecialchars($statusMsg, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <form method="post" action="">
        <label for="subject">Subject</label>
        <input type="text" id="subject" name="subject" value="<?php echo isset($_POST['subject']) ? htmlspecialchars($_POST['subject'], ENT_QUOTES, 'UTF-8') : 'Mail test from server'; ?>">

        <label for="message">Message (HTML-safe; will be escaped)</label>
        <textarea id="message" name="message"><?php
            echo isset($_POST['message'])
                ? htmlspecialchars($_POST['message'], ENT_QUOTES, 'UTF-8')
                : 'This is a test HTML email from the server.';
        ?></textarea>

        <button class="btn" type="submit">Send Test Email</button>
    </form>

    <div class="note" style="margin-top:14px;">
        If delivery fails, verify your server’s mail configuration, SPF/DKIM/DMARC for demelos.com, and that PHP’s mail() is enabled.
    </div>
</div>
</body>
</html>
