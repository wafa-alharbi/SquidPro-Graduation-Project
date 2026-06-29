<?php
session_start();
if ($_SESSION['role'] !== 'admin') die("Access denied.");

$db = new mysqli("localhost", "root", "", "SquidPro");

$reports = $db->query("
    SELECT r.*, u.username AS reporter, u2.username AS reported
    FROM reports r
    LEFT JOIN users u ON u.id = r.reporter_id
    LEFT JOIN users u2 ON u2.id = r.reported_user_id
    ORDER BY r.id DESC
");
?>
<!DOCTYPE html>
<html>
<head>
<title>Manage Reports</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body style="background:#0d1117;color:white;">

<div class="container" style="margin-top:80px;">
    <h1>Manage Reports</h1>

    <table class="table table-dark table-striped mt-4">
        <thead>
            <tr>
                <th>ID</th><th>Reporter</th><th>Reported</th><th>Issue</th><th>Status</th><th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php while($r = $reports->fetch_assoc()): ?>
            <tr>
                <td><?php echo $r['id']; ?></td>
                <td><?php echo $r['reporter']; ?></td>
                <td><?php echo $r['reported']; ?></td>
                <td><?php echo $r['issue_type']; ?></td>
                <td><?php echo $r['status']; ?></td>
                <td>
                    <a href="AdminResolveReport.php?id=<?php echo $r['id']; ?>" class="btn btn-sm btn-success">Resolve</a>
                    <a href="AdminBanUser.php?id=<?php echo $r['reported_user_id']; ?>" class="btn btn-sm btn-warning">Ban User</a>
                    <a href="AdminDeleteReport.php?id=<?php echo $r['id']; ?>" class="btn btn-sm btn-danger">Delete</a>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

</body>
</html>
