<?php
session_start();

if ($_SESSION['role'] !== 'admin') die("Access denied.");

$db = new mysqli("localhost", "root", "", "SquidPro");
$db->set_charset("utf8mb4");

$users = $db->query("SELECT id, username, email, role, created_at FROM users ORDER BY id DESC");
?>
<!DOCTYPE html>
<html>
<head>
<title>Manage Users</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background:#0d1117; color:white; font-family:'Poppins'; }
.table-box { background:rgba(255,255,255,0.05); padding:20px; border-radius:12px; }
</style>
</head>
<body>

<div class="container" style="margin-top:80px;">
    <h1 class="fw-bold">Manage Users</h1>

    <div class="table-box mt-4">
        <table class="table table-dark table-striped">
            <thead>
                <tr><th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php while($u = $users->fetch_assoc()): ?>
                <tr>
                    <td><?php echo $u['id']; ?></td>
                    <td><?php echo $u['username']; ?></td>
                    <td><?php echo $u['email']; ?></td>
                    <td><?php echo $u['role']; ?></td>
                    <td>
                        <a href="AdminEditUser.php?id=<?php echo $u['id']; ?>" class="btn btn-sm btn-warning">Edit</a>
                        <a href="AdminDeleteUser.php?id=<?php echo $u['id']; ?>" class="btn btn-sm btn-danger">Delete</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>
