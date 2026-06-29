<?php
session_start();
if ($_SESSION['role'] !== 'admin') die("Access denied.");

$db = new mysqli("localhost", "root", "", "SquidPro");
$db->set_charset("utf8mb4");

$games = $db->query("SELECT * FROM games ORDER BY id DESC");
?>
<!DOCTYPE html>
<html>
<head>
<title>Manage Games</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background:#0d1117; color:white; font-family:'Poppins'; }
.table-box { background:rgba(255,255,255,0.05); padding:20px; border-radius:12px; }
</style>
</head>
<body>

<div class="container" style="margin-top:80px;">
    <h1 class="fw-bold">Manage Games</h1>

    <a href="AdminAddGame.php" class="btn btn-success mt-3">Add New Game</a>

    <div class="table-box mt-4">
        <table class="table table-dark table-striped">
            <thead>
                <tr><th>ID</th><th>Name</th><th>Icon</th><th>Description</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php while($g = $games->fetch_assoc()): ?>
                <tr>
                    <td><?php echo $g['id']; ?></td>
                    <td><?php echo $g['name']; ?></td>
                    <td><img src="image/<?php echo $g['icon']; ?>" width="60"></td>
                    <td><?php echo $g['description']; ?></td>
                    <td>
                        <a href="AdminEditGame.php?id=<?php echo $g['id']; ?>" class="btn btn-sm btn-warning">Edit</a>
                        <a href="AdminDeleteGame.php?id=<?php echo $g['id']; ?>" class="btn btn-sm btn-danger">Delete</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>
