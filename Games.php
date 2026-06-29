<?php
session_start();

/* DB CONFIG */
$db = new mysqli("localhost", "root", "", "squidpro");
$db->set_charset("utf8mb4");

function esc($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/* CHECK ORGANIZER LOGIN */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'organizer') {
    header("Location: Login.php");
    exit;
}

$organizer_id = $_SESSION['user_id'];

/* GET EVENT ID */
$event_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($event_id <= 0) die("Invalid event.");

/* FETCH EVENT DETAILS */
$stmt = $db->prepare("
    SELECT e.*, 
           u.display_name AS organizer_name,
           g.name AS game_name,
           l.name AS location_name,
           l.address,
           l.latitude,
           l.longitude
    FROM events e
    LEFT JOIN users u ON u.id = e.organizer_id
    LEFT JOIN games g ON g.id = e.game_id
    LEFT JOIN locations l ON l.id = e.location_id
    WHERE e.id = ?
");
$stmt->bind_param("i", $event_id);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$event) die("Event not found.");

/* HANDLE APPROVE / REJECT */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];

    if ($action === 'approve') {
        $db->query("UPDATE events SET status='approved' WHERE id=$event_id");
    }
    if ($action === 'reject') {
        $db->query("UPDATE events SET status='rejected' WHERE id=$event_id");
    }

    header("Location: EventDetails.php?id=$event_id");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>squid pro | Event Details</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">

<style>
/* نفس الهوية البصرية */
body{margin:0;font-family:'Poppins',sans-serif;background:#0d1117;color:white;}
.bg-waves{position:fixed;inset:0;z-index:-3;background:radial-gradient(circle at 20% 20%,#1a237e,transparent 60%),radial-gradient(circle at 80% 80%,#8e24aa,transparent 60%),radial-gradient(circle at 50% 50%,#0d47a1,transparent 70%);filter:blur(90px);animation:move 12s infinite alternate ease-in-out;}
@keyframes move{0%{transform:scale(1);}100%{transform:scale(1.25);}}
.sidebar{width:260px;height:100vh;background:rgba(0,0,0,0.55);backdrop-filter:blur(10px);position:fixed;left:0;top:0;padding:25px 20px;border-right:1px solid rgba(255,255,255,0.1);}
.sidebar h2{font-weight:800;font-size:1.9rem;background:linear-gradient(90deg,#0D6EFD,#8a2be2);-webkit-background-clip:text;color:transparent;text-align:center;margin-bottom:40px;}
.sidebar a{display:block;padding:12px 15px;margin-bottom:12px;border-radius:10px;color:white;text-decoration:none;font-size:1.05rem;transition:0.3s;}
.sidebar a:hover,.sidebar a.active{background:linear-gradient(135deg,#0D6EFD,#8a2be2);box-shadow:0 0 15px rgba(13,110,253,0.6);}
.main{margin-left:280px;padding:40px;}
.details-card{background:rgba(255,255,255,0.06);border-radius:18px;padding:25px;border:1px solid rgba(255,255,255,0.1);}
.btn-approve{background:#198754;border:none;padding:10px 20px;border-radius:10px;color:white;}
.btn-reject{background:#dc3545;border:none;padding:10px 20px;border-radius:10px;color:white;}
</style>
</head>

<body>

<div class="bg-waves"></div>

<div class="sidebar">
    <h2>squid pro</h2>

    <a href="OrganizerDashboard.php">📊 Organizer Dashboard</a>
    <a href="OrganizerEvents.php" class="active">✅ Approve Events</a>
    <a href="OrganizerTournaments.php">🧾 Validate Tournaments</a>
    <a href="OrganizerReports.php">📑 Review Reports</a>
    <a href="Rewards.php">🎁 Rewards</a>
    <a href="Logout.php">🚪 Logout</a>
</div>

<div class="main">
    <h1 class="fw-bold">Event Details</h1>
    <p style="opacity:0.8;">Review full event information before approving.</p>

    <div class="details-card mt-4">
        <h3><?= esc($event['name']) ?></h3>

        <p><strong>Requested by:</strong> <?= esc($event['organizer_name']) ?></p>
        <p><strong>Game:</strong> <?= esc($event['game_name']) ?></p>
        <p><strong>Date:</strong> <?= esc($event['start_date']) ?></p>
        <p><strong>Location:</strong> <?= esc($event['location_name']) ?>  
            <br><span style="opacity:0.7;"><?= esc($event['address']) ?></span>
        </p>
        <p><strong>Description:</strong> <?= esc($event['description']) ?></p>
        <p><strong>Status:</strong> <?= esc($event['status']) ?></p>

        <hr style="border-color:rgba(255,255,255,0.1);">

        <?php if ($event['status'] === 'pending'): ?>
        <form method="POST" style="display:inline;">
            <button name="action" value="approve" class="btn-approve">Approve Event</button>
        </form>

        <form method="POST" style="display:inline;">
            <button name="action" value="reject" class="btn-reject">Reject Event</button>
        </form>
        <?php else: ?>
            <p class="mt-3"><strong>This event has already been <?= esc($event['status']) ?>.</strong></p>
        <?php endif; ?>
    </div>

</div>

</body>
</html>
