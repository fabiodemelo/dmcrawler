<?php
session_start();
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    header('location: index.php');
    exit;
}

include 'db.php';
$username = $password = "";
$username_err = $password_err = $login_err = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (empty(trim($_POST["username"]))) {
        $username_err = "Please enter username.";
    } else {
        $username = trim($_POST["username"]);
    }
    if (empty(trim($_POST["password"]))) {
        $password_err = "Please enter your password.";
    } else {
        $password = trim($_POST["password"]);
    }
    if (empty($username_err) && empty($password_err)) {
        $stmt = $conn->prepare("SELECT id, username, password FROM users WHERE username = ?");
        if ($stmt) {
            $stmt->bind_param("s", $username);
            if ($stmt->execute()) {
                $stmt->store_result();
                if ($stmt->num_rows == 1) {
                    $stmt->bind_result($id, $username, $hashed_password);
                    if ($stmt->fetch() && password_verify($password, $hashed_password)) {
                        $_SESSION["loggedin"] = true;
                        $_SESSION["id"] = $id;
                        $_SESSION["username"] = $username;
                        header("location: index.php");
                        exit;
                    } else {
                        $login_err = "Invalid username or password.";
                    }
                } else {
                    $login_err = "Invalid username or password.";
                }
            }
            $stmt->close();
        }
    }
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Demelos</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="login-page">
<div class="login-card">
    <h2>demelos<span style="color:#f5a623;">.</span></h2>
    <p class="login-subtitle">Sign in to your crawler dashboard</p>

    <?php if (!empty($login_err)): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= $login_err ?></div>
    <?php endif; ?>

    <form action="<?= htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
        <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="text" name="username" class="form-control <?= !empty($username_err) ? 'is-invalid' : '' ?>" value="<?= htmlspecialchars($username) ?>" placeholder="you@company.com">
            <div class="invalid-feedback"><?= $username_err ?></div>
        </div>
        <div class="mb-3">
            <label class="form-label">Password</label>
            <input type="password" name="password" class="form-control <?= !empty($password_err) ? 'is-invalid' : '' ?>" placeholder="Enter password">
            <div class="invalid-feedback"><?= $password_err ?></div>
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-100 mb-3">
            <i class="fas fa-sign-in-alt me-2"></i>Sign In
        </button>
        <p class="text-center"><a href="forgot_password.php" style="font-size:0.9rem;">Forgot Password?</a></p>
    </form>
</div>
</body>
</html>
