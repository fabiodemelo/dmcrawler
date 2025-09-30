<?php
// version 3.4 - Reset Password
include 'db.php';
$message = '';
$token = $_GET['token'] ?? '';

if (empty($token)) {
    header('location: login.php');
    exit;
}

$res = $conn->query("SELECT * FROM password_resets WHERE token = '$token' AND expires >= " . time());
if ($res->num_rows === 0) {
    $message = '<div class="alert alert-danger">This password reset link is invalid or has expired.</div>';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $row = $res->fetch_assoc();
    $email = $row['email'];
    $new_password = $_POST['password'];
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

    $conn->query("UPDATE users SET password = '$hashed_password' WHERE username = '$email'");
    $conn->query("DELETE FROM password_resets WHERE email = '$email'");

    $message = '<div class="alert alert-success">Your password has been reset successfully. You can now <a href="login.php">login</a>.</div>';
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Reset Password</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .wrapper { width: 400px; padding: 20px; margin: 100px auto; background: #fff; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,.1); }
    </style>
</head>
<body>
<div class="wrapper">
    <h2>Reset Password</h2>
    <?= $message ?>
    <?php if ($res->num_rows > 0): ?>
        <form method="post">
            <div class="form-group mb-3">
                <label>New Password</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <div class="form-group mb-3">
                <button type="submit" class="btn btn-primary">Reset Password</button>
            </div>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
