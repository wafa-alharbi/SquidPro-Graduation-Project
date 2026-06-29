<?php
session_start();

/*
  OrganizerDashboard.php
  - English-only UI
  - Uses your real database schema
  - Shows pending events, pending tournaments, and new reports
  - Only accessible by organizer or admin
*/

/* ---------- CONFIG ---------- */
$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "squidpro";

/* ---------- HELPERS ---------- */
function esc($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* ---------- AUTH CHECK ---------- */
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['organizer','admin'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$role = $_SESSION['role'];

/* ---------- DB CONNECTION ---------- */
$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($mysqli->connect_errno) {
    die("Database connection failed.");
}
$mysqli->set_charset("utf8mb4");

/* ---------- FETCH DASHBOARD COUNTS ---------- */

// Pending events
$pending_events = 0;
$q1 = $mysqli->query("SELECT COUNT(*) AS c FROM events WHERE status = 'pending'");
if ($q1) { $pending_events = (int)$q1->fetch_assoc()['c']; }

// Pending tournaments
$pending_tournaments = 0;
$q2 = $mysqli->query("SELECT COUNT(*) AS c FROM tournaments WHERE status = 'pending'");
if ($q2) { $pending_tournaments = (int)$q2->fetch_assoc()['c']; }

// New reports
$new_reports = 0;
$q3 = $mysqli->query("SELECT COUNT(*) AS c FROM reports WHERE status = 'open'");
if ($q3) { $new_reports = (int)$q3->fetch_assoc()['c']; }

/* ---------- FETCH LATEST PENDING EVENTS ---------- */
$latest_events = [];
$q4 = $mysqli->query("
    SELECT id, name, organizer_id, location_name, created_at
    FROM events
    WHERE status = 'pending'
    ORDER BY created_at DESC
    LIMIT 5
");
if ($q4) {
    while ($row = $q4->fetch_assoc()) {
        $latest_events[] = $row;
    }
}

/* ---------- FETCH RECENT REPORTS ---------- */
$recent_reports = [];
$q5 = $mysqli->query("
    SELECT id, issue_type, description, created_at
    FROM reports
    ORDER BY created_at DESC
    LIMIT 5
");
if ($q5) {
    while ($row = $q5->fetch_assoc()) {
        $recent_reports[] = $row;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>squid pro | Organizer Dashboard</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">

<style>
    body{
        margin:0;
        font-family:'Poppins',sans-serif;
        background:#0d1117;
        color:white;
        overflow-x:hidden;
    }
    .bg-waves{
        position:fixed;inset:0;z-index:-3;
        background:radial-gradient(circle at 20% 20%,#1a237e,transparent 60%),
                   radial-gradient(circle at 80% 80%,#8e24aa,transparent 60%),
                   radial-gradient(circle at 50% 50%,#0d47a1,transparent 70%);
        filter:blur(90px);
        animation:move 12s infinite alternate ease-in-out;
    }
    @keyframes move{0%{transform:scale(1);}100%{transform:scale(1.25);}}

    .sidebar{
        width:260px;height:100vh;
        background:rgba(0,0,0,0.55);
        backdrop-filter:blur(10px);
        position:fixed;left:0;top:0;
        padding:25px 20px;
        border-right:1px solid rgba(255,255,255,0.1);
    }
    .sidebar h2{
        font-weight:800;font-size:1.9rem;
        background:linear-gradient(90deg,#0D6EFD,#8a2be2);
        -webkit-background-clip:text;color:transparent;
        text-align:center;margin-bottom:40px;
    }
    .sidebar a{
        display:block;padding:12px 15px;margin-bottom:12px;
        border-radius:10px;color:white;text-decoration:none;
        font-size:1.05rem;transition:0.3s;
    }
    .sidebar a:hover,
    .sidebar a.active{
        background:linear-gradient(135deg,#0D6EFD,#8a2be2);
        box-shadow:0 0 15px rgba(13,110,253,0.6);
    }

    .main{
        margin-left:280px;
        padding:40px;
    }

    .dash-card{
        background:rgba(255,255,255,0.06);
        border-radius:18px;
        padding:20px;
        border:1px solid rgba(255,255,255,0.1);
        margin-bottom:20px;
    }

    .section-title{
        margin-top:25px;
        margin-bottom:10px;
        font-weight:700;
    }

    .btn-approve{
        background:#198754;
        border:none;
        padding:6px 14px;
        border-radius:8px;
        color:white;
        font-size:0.85rem;
    }
    .btn-reject{
        background:#dc3545;
        border:none;
        padding:6px 14px;
        border-radius:8px;
        color:white;
        font-size:0.85rem;
    }
</style>
</head>
<body>

<div class="bg-waves"></div>

<div class="sidebar">
    <h2>squid pro Hub</h2>
    <a href="OrganizerDashboard.php" class="active">📊 Organizer Dashboard</a>
    <a href="OrganizerEvents.php">✅ Manage Events</a>
    <a href="OrganizerTournaments.php">🧾 Manage Tournaments</a>
    <a href="OrganizerMatches.php">⚔️ Manage Matches</a>
    <a href="OrganizerMatchResults.php">🧮 Match Results</a>
    <a href="OrganizerReports.php">📑 Review Reports</a>
    <a href="Rewards.php">🎁 Rewards</a>
    <a href="Logout.php">🚪 Logout</a>
</div>

<div class="main">
    <h1 class="fw-bold">Organizer Dashboard</h1>
    <p style="opacity:0.8;">Manage events, tournaments, and reports for the platform.</p>

    <div class="row g-4 mt-2">
        <div class="col-md-4">
            <div class="dash-card text-center">
                <h4>Pending Events</h4>
                <p><?php echo $pending_events; ?></p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="dash-card text-center">
                <h4>Pending Tournaments</h4>
                <p><?php echo $pending_tournaments; ?></p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="dash-card text-center">
                <h4>New Reports</h4>
                <p><?php echo $new_reports; ?></p>
            </div>
        </div>
    </div>

    <h3 class="section-title">Latest Event Requests</h3>

    <?php if (empty($latest_events)): ?>
        <div class="dash-card"><p>No pending events.</p></div>
    <?php else: ?>
        <?php foreach ($latest_events as $ev): ?>
            <div class="dash-card">
                <p><strong><?php echo esc($ev['name']); ?></strong></p>
                <button class="btn-approve">Approve</button>
                <button class="btn-reject">Reject</button>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <h3 class="section-title">Recent Reports</h3>

    <?php if (empty($recent_reports)): ?>
        <div class="dash-card"><p>No reports found.</p></div>
    <?php else: ?>
        <?php foreach ($recent_reports as $rp): ?>
            <div class="dash-card">
                <p><strong>Report #<?php echo esc($rp['id']); ?></strong> â€“ <?php echo esc($rp['issue_type']); ?></p>
                <a href="OrganizerReports.php?id=<?php echo $rp['id']; ?>" class="btn btn-sm btn-outline-light">View Details</a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

</body>
</html>
