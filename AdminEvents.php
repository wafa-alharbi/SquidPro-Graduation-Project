<?php
session_start();
if ($_SESSION['role'] !== 'admin') die("Access denied.");

$db = new mysqli("localhost", "root", "", "SquidPro");

$id = (int)$_GET['id'];
$user = $db->query("SELECT * FROM users WHERE id=$id")->fetch_assoc();

if (!$user) die("User not found.");

$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $role = trim($_POST['role']);

    $stmt = $db->prepare("UPDATE users SET username=?, role=? WHERE id=?");
    $stmt->bind_param("ssi", $username, $role, $id);
    $stmt->execute();
    $success = true;
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Edit User</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body style="background:#0d1117;color:white;">

<div class="container" style="margin-top:80px;">
    <h1>Edit User</h1>

    <?php if ($success): ?>
        <div class="alert alert-success">User updated successfully!</div>
    <?php endif; ?>

    <form method="POST">
        <label>Username</label>
        <input type="text" name="username" class="form-control" value="<?php echo $user['username']; ?>">

        <label class="mt-3">Role</label>
        <select name="role" class="form-control">
            <option value="player" <?php if($user['role']=='player') echo 'selected'; ?>>Player</option>
            <option value="organizer" <?php if($user['role']=='organizer') echo 'selected'; ?>>Organizer</option>
            <option value="admin" <?php if($user['role']=='admin') echo 'selected'; ?>>Admin</option>
        </select>

        <button class="btn btn-warning mt-4">Save Changes</button>
    </form>
</div>

</body>
</html>
