<?php
// version 3.4 - Login Page
session_start();

// If the user is already logged in, redirect to the dashboard.
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
        $sql = "SELECT id, username, password FROM users WHERE username = ?";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("s", $param_username);
            $param_username = $username;
            if ($stmt->execute()) {
                $stmt->store_result();
                if ($stmt->num_rows == 1) {
                    $stmt->bind_result($id, $username, $hashed_password);
                    if ($stmt->fetch()) {
                        if (password_verify($password, $hashed_password)) {
                            session_start();
                            $_SESSION["loggedin"] = true;
                            $_SESSION["id"] = $id;
                            $_SESSION["username"] = $username;
                            header("location: index.php");
                        } else {
                            $login_err = "Invalid username or password.";
                        }
                    }
                } else {
                    $login_err = "Invalid username or password.";
                }
            } else {
                echo "Oops! Something went wrong. Please try again later.";
            }
            $stmt->close();
        }
    }
    $conn->close();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Login - Crawler</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .wrapper { width: 360px; padding: 20px; margin: 100px auto; background: #fff; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,.1); }
    </style>
</head>
<body>
<div class="wrapper">
    <h2>Crawler Login</h2>
    <p>Please fill in your credentials to login.</p>
    <?php
    if(!empty($login_err)){
        echo '<div class="alert alert-danger">' . $login_err . '</div>';
    }
    ?>
    <form action="<?= htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
        <div class="form-group mb-3">
            <label>Username</label>
            <input type="text" name="username" class="form-control <?= (!empty($username_err)) ? 'is-invalid' : ''; ?>" value="<?= $username; ?>">
            <span class="invalid-feedback"><?= $username_err; ?></span>
        </div>
        <div class="form-group mb-3">
            <label>Password</label>
            <input type="password" name="password" class="form-control <?= (!empty($password_err)) ? 'is-invalid' : ''; ?>">
            <span class="invalid-feedback"><?= $password_err; ?></span>
        </div>
        <div class="form-group mb-3">
            <input type="submit" class="btn btn-primary" value="Login">
        </div>
        <p><a href="forgot_password.php">Forgot Password?</a></p>
    </form>
</div>
</body>
</html>
