<?php
session_start();

if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("Access denied.");
}

$db = new mysqli("localhost", "root", "", "SquidPro");
$db->set_charset("utf8mb4");

function countTable($db, $table) {
    $res = $db->query("SELECT COUNT(*) AS c FROM $table");
    return $res->fetch_assoc()['c'];
}

$users = countTable($db, "users");
$games = countTable($db, "games");
$tournaments = countTable($db, "tournaments");
$events = countTable($db, "events");
$reports = countTable($db, "reports");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Dashboard</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
body { background:#0d1117; color:white; font-family:'Poppins',sans-serif; }
.card-box { background:rgba(255,255,255,0.05); padding:20px; border-radius:12px; border:1px solid rgba(255,255,255,0.1); }
</style>
</head>

<body>

<div class="container" style="margin-top:80px;">
    <h1 class="fw-bold">Admin Dashboard</h1>
    <p class="opacity-75">Manage the entire SquidPro platform.</p>

    <div class="row g-4 mt-4">
        <div class="col-md-3"><div class="card-box"><h3><?php echo $users; ?></h3><p>Users</p></div></div>
        <div class="col-md-3"><div class="card-box"><h3><?php echo $games; ?></h3><p>Games</p></div></div>
        <div class="col-md-3"><div class="card-box"><h3><?php echo $tournaments; ?></h3><p>Tournaments</p></div></div>
        <div class="col-md-3"><div class="card-box"><h3><?php echo $events; ?></h3><p>Events</p></div></div>
        <div class="col-md-3"><div class="card-box"><h3><?php echo $reports; ?></h3><p>Reports</p></div></div>
    </div>

    <div class="mt-5">
        <a href="AdminUsers.php" class="btn btn-primary">Manage Users</a>
        <a href="AdminGames.php" class="btn btn-primary">Manage Games</a>
        <a href="AdminTournaments.php" class="btn btn-primary">Manage Tournaments</a>
        <a href="AdminEvents.php" class="btn btn-primary">Manage Events</a>
        <a href="AdminReports.php" class="btn btn-primary">Manage Reports</a>
        <a href="Logout.php" class="btn btn-primary">Logout</a>
    </div>
</div>

</body>
</html>
