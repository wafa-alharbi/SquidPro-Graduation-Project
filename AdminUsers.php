<?php
session_start();
if ($_SESSION['role'] !== 'admin') die("Access denied.");

$db = new mysqli("localhost", "root", "", "SquidPro");
$db->set_charset("utf8mb4");

$tournaments = $db->query("
    SELECT t.*, g.name AS game_name, u.username AS organizer
    FROM tournaments t
    LEFT JOIN games g ON g.id = t.game_id
    LEFT JOIN users u ON u.id = t.organizer_id
    ORDER BY t.id DESC
");
?>
<!DOCTYPE html>
<html>
<head>
<title>Manage Tournaments</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body style="background:#0d1117;color:white;">

<div class="container" style="margin-top:80px;">
    <h1>Manage Tournaments</h1>

    <table class="table table-dark table-striped mt-4">
        <thead>
            <tr>
                <th>ID</th><th>Name</th><th>Game</th><th>Organizer</th><th>Status</th><th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php while($t = $tournaments->fetch_assoc()): ?>
            <tr>
                <td><?php echo $t['id']; ?></td>
                <td><?php echo $t['name']; ?></td>
                <td><?php echo $t['game_name']; ?></td>
                <td><?php echo $t['organizer']; ?></td>
                <td><?php echo $t['status']; ?></td>
                <td>
                    <a href="TournamentView.php?id=<?php echo $t['id']; ?>" class="btn btn-sm btn-info">View</a>
                    <a href="AdminValidateTournament.php?id=<?php echo $t['id']; ?>" class="btn btn-sm btn-success">Validate</a>
                    <a href="AdminRejectTournament.php?id=<?php echo $t['id']; ?>" class="btn btn-sm btn-warning">Reject</a>
                    <a href="AdminDeleteTournament.php?id=<?php echo $t['id']; ?>" class="btn btn-sm btn-danger">Delete</a>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

</body>
</html>
