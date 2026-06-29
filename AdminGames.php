<?php
session_start();
if ($_SESSION['role'] !== 'admin') die("Access denied.");

$db = new mysqli("localhost", "root", "", "SquidPro");

$events = $db->query("
    SELECT e.*, u.username AS organizer
    FROM events e
    LEFT JOIN users u ON u.id = e.organizer_id
    ORDER BY e.id DESC
");
?>
<!DOCTYPE html>
<html>
<head>
<title>Manage Events</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body style="background:#0d1117;color:white;">

<div class="container" style="margin-top:80px;">
    <h1>Manage Events</h1>

    <table class="table table-dark table-striped mt-4">
        <thead>
            <tr>
                <th>ID</th><th>Name</th><th>Organizer</th><th>Status</th><th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php while($e = $events->fetch_assoc()): ?>
            <tr>
                <td><?php echo $e['id']; ?></td>
                <td><?php echo $e['name']; ?></td>
                <td><?php echo $e['organizer']; ?></td>
                <td><?php echo $e['status']; ?></td>
                <td>
                    <a href="AdminApproveEvent.php?id=<?php echo $e['id']; ?>" class="btn btn-sm btn-success">Approve</a>
                    <a href="AdminRejectEvent.php?id=<?php echo $e['id']; ?>" class="btn btn-sm btn-warning">Reject</a>
                    <a href="AdminDeleteEvent.php?id=<?php echo $e['id']; ?>" class="btn btn-sm btn-danger">Delete</a>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

</body>
</html>
