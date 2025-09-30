<?php
// version 3.4 - Forgot Password
// NOTE: This requires a configured mail server for PHP's mail() function to work.
include 'db.php';
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'])) {
    $email = $conn->real_escape_string(trim($_POST['email']));
    $res = $conn->query("SELECT id FROM users WHERE username = '$email'");
    if ($res->num_rows > 0) {
        $token = bin2hex(random_bytes(50));
        $expires = time() + 3600; // 1 hour
        $conn->query("INSERT INTO password_resets (email, token, expires) VALUES ('$email', '$token', $expires)");

        $reset_link = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/reset_password.php?token=$token";

        $mail_subject = "Password Reset Request";
        $mail_body = "Click on this link to reset your password: \n\n" . $reset_link;
        $mail_headers = "From: no-reply@" . $_SERVER['HTTP_HOST'];

        if (mail($email, $mail_subject, $mail_body, $mail_headers)) {
            $message = '<div class="alert alert-success">A password reset link has been sent to your email.</div>';
        } else {
            $message = '<div class="alert alert-danger">Failed to send email. Please check server mail configuration.</div>';
        }
    } else {
        $message = '<div class="alert alert-warning">No user found with that email address.</div>';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Forgot Password</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .wrapper { width: 400px; padding: 20px; margin: 100px auto; background: #fff; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,.1); }
    </style>
</head>
<body>
<div class="wrapper">
    <h2>Forgot Password</h2>
    <p>Please enter your email address to receive a password reset link.</p>
    <?= $message ?>
    <form method="post">
        <div class="form-group mb-3">
            <input type="email" name="email" class="form-control" placeholder="Enter your email" required>
        </div>
        <div class="form-group mb-3">
            <button type="submit" class="btn btn-primary">Send Reset Link</button>
        </div>
        <p><a href="login.php">Back to Login</a></p>
    </form>
</div>
</body>
</html>
