<?php
// version 3.5 - Manage Users
include 'auth_check.php';
include 'db.php';

$message = '';

// Handle Add User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $username = $conn->real_escape_string(trim($_POST['username']));
    $password = $_POST['password'];
    if (!empty($username) && !empty($password)) {
        // Check if user already exists
        $check_res = $conn->query("SELECT id FROM users WHERE username = '$username'");
        if($check_res->num_rows > 0) {
            $message = '<div class="alert alert-warning">A user with this email address already exists.</div>';
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $conn->query("INSERT INTO users (username, password) VALUES ('$username', '$hashed_password')");
            $message = '<div class="alert alert-success">User added successfully.</div>';
        }
    } else {
        $message = '<div class="alert alert-danger">Username and password cannot be empty.</div>';
    }
}

// Handle Delete User
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    // Prevent deleting the main admin user (ID 1) or the current user
    if ($id !== 1 && $id !== $_SESSION['id']) {
        $conn->query("DELETE FROM users WHERE id = $id");
        $message = '<div class="alert alert-info">User deleted.</div>';
    } else {
        if ($id === 1) {
            $message = '<div class="alert alert-warning">Cannot delete the primary admin user.</div>';
        }
        if ($id === $_SESSION['id']) {
            $message = '<div class="alert alert-warning">You cannot delete your own account.</div>';
        }
    }
}

$users_result = $conn->query("SELECT id, username, created_at FROM users ORDER BY id ASC");
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Manage Users</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body>
<?php include 'nav.php'; ?>

<div class="container">
    <?= $message ?>
    <div class="card mb-4">
        <div class="card-header">Add New User</div>
        <div class="card-body">
            <form method="post">
                <div class="row g-3">
                    <div class="col-md-5">
                        <input type="email" name="username" class="form-control" placeholder="Email (Username)" required>
                    </div>
                    <div class="col-md-5">
                        <input type="password" name="password" class="form-control" placeholder="Password" required>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" name="add_user" class="btn btn-primary w-100">Add User</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">Existing Users</div>
        <div class="card-body">
            <table class="table table-striped">
                <thead class="table-dark">
                <tr>
                    <th>Username</th>
                    <th>Date Created</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                <?php while($row = $users_result->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['username']) ?></td>
                        <td><?= $row['created_at'] ?></td>
                        <td>
                            <?php if ($row['id'] != 1 && $row['id'] != $_SESSION['id']): ?>
                                <a href="manage_users.php?delete=<?= $row['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this user?')">Delete</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
