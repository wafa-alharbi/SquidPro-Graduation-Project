<?php
session_start();

/*
  ReportDetails.php
  - Shows full details of a single report
  - Organizer/admin can mark as: in_review, resolved, banned
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

/* ---------- CSRF TOKEN ---------- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}

/* ---------- GET REPORT ID ---------- */
$report_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($report_id <= 0) {
    die("Invalid report ID.");
}

/* ---------- DB CONNECTION ---------- */
$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($mysqli->connect_errno) {
    die("Database connection failed.");
}
$mysqli->set_charset("utf8mb4");

/* ---------- HANDLE ACTIONS ---------- */
$success = "";
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $errors[] = "Invalid CSRF token.";
    } else {
        if ($action === "review") {
            $stmt = $mysqli->prepare("UPDATE reports SET status = 'in_review' WHERE id = ?");
            $stmt->bind_param("i", $report_id);
            $stmt->execute();
            $stmt->close();
            $success = "Report marked as In Review.";
        }
        elseif ($action === "resolve") {
            $stmt = $mysqli->prepare("UPDATE reports SET status = 'resolved' WHERE id = ?");
            $stmt->bind_param("i", $report_id);
            $stmt->execute();
            $stmt->close();
            $success = "Report resolved.";
        }
        elseif ($action === "ban") {
            $stmt = $mysqli->prepare("UPDATE reports SET status = 'banned' WHERE id = ?");
            $stmt->bind_param("i", $report_id);
            $stmt->execute();
            $stmt->close();
            $success = "User banned and report updated.";
        }
    }
}

/* ---------- FETCH REPORT DETAILS ---------- */
$stmt = $mysqli->prepare("
    SELECT r.*, 
           u1.display_name AS reporter_name,
           u2.display_name AS reported_name
    FROM reports r
    LEFT JOIN users u1 ON u1.id = r.reporter_id
    LEFT JOIN users u2 ON u2.id = r.reported_user_id
    WHERE r.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $report_id);
$stmt->execute();
$report = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$report) {
    die("Report not found.");
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>squid pro | Report Details</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">

<style>
    body {
        margin:0;
        font-family:'Poppins',sans-serif;
        background:#0d1117;
        color:white;
    }

    .bg-waves {
        position:fixed; inset:0; z-index:-3;
        background:radial-gradient(circle at 20% 20%,#1a237e,transparent 60%),
                   radial-gradient(circle at 80% 80%,#8e24aa,transparent 60%),
                   radial-gradient(circle at 50% 50%,#0d47a1,transparent 70%);
        filter:blur(90px);
        animation:move 12s infinite alternate ease-in-out;
    }
    @keyframes move {0%{transform:scale(1);}100%{transform:scale(1.25);} }

    .sidebar {
        width:260px;height:100vh;
        background:rgba(0,0,0,0.55);
        backdrop-filter:blur(10px);
        position:fixed;left:0;top:0;
        padding:25px 20px;
        border-right:1px solid rgba(255,255,255,0.1);
    }
    .sidebar h2 {
        font-weight:800;font-size:1.9rem;
        background:linear-gradient(90deg,#0D6EFD,#8a2be2);
        -webkit-background-clip:text;color:transparent;
        text-align:center;margin-bottom:40px;
    }
    .sidebar a {
        display:block;padding:12px 15px;margin-bottom:12px;
        border-radius:10px;color:white;text-decoration:none;
        font-size:1.05rem;transition:0.3s;
    }
    .sidebar a:hover,
    .sidebar a.active {
        background:linear-gradient(135deg,#0D6EFD,#8a2be2);
        box-shadow:0 0 15px rgba(13,110,253,0.6);
    }

    .main {
        margin-left:280px;
        padding:40px;
    }

    .details-card {
        background:rgba(255,255,255,0.06);
        border-radius:18px;
        padding:25px;
        border:1px solid rgba(255,255,255,0.1);
        margin-bottom:20px;
    }

    .btn-action {
        padding:8px 16px;
        border-radius:8px;
        border:none;
        color:white;
        margin-right:8px;
    }
    .btn-review { background:#ffc107; }
    .btn-resolve { background:#198754; }
    .btn-ban { background:#dc3545; }
</style>
</head>
<body>

<div class="bg-waves"></div>

<div class="sidebar">
    <h2>squid pro</h2>

    <a href="OrganizerDashboard.php">📊 Organizer Dashboard</a>
    <a href="OrganizerEvents.php">✅ Approve Events</a>
    <a href="OrganizerTournaments.php">🧾 Validate Tournaments</a>
    <a href="OrganizerReports.php" class="active">📑 Review Reports</a>
    <a href="Rewards.php">🎁 Rewards</a>
    <a href="Logout.php">🚪 Logout</a>
</div>

<div class="main">
    <h1 class="fw-bold">Report Details</h1>
    <p style="opacity:0.8;">Full details of the selected report.</p>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo esc($success); ?></div>
    <?php endif; ?>

    <?php if ($errors): foreach ($errors as $e): ?>
        <div class="alert alert-danger"><?php echo esc($e); ?></div>
    <?php endforeach; endif; ?>

    <div class="details-card">
        <h3>Report #<?php echo esc($report['id']); ?></h3>
        <p><strong>Issue:</strong> <?php echo esc($report['issue_type']); ?></p>
        <p><strong>Description:</strong> <?php echo esc($report['description']); ?></p>
        <p><strong>Reported by:</strong> <?php echo esc($report['reporter_name'] ?: "Unknown"); ?></p>
        <p><strong>Reported user:</strong> <?php echo esc($report['reported_name'] ?: "Unknown"); ?></p>
        <p><strong>Status:</strong> <?php echo esc($report['status']); ?></p>
        <p><strong>Created at:</strong> <?php echo esc($report['created_at']); ?></p>
    </div>

    <h4 class="mt-4">Actions</h4>

    <form method="POST" style="display:inline;">
        <input type="hidden" name="csrf_token" value="<?php echo esc($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="review">
        <button class="btn-action btn-review">Mark In Review</button>
    </form>

    <form method="POST" style="display:inline;">
        <input type="hidden" name="csrf_token" value="<?php echo esc($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="resolve">
        <button class="btn-action btn-resolve">Resolve</button>
    </form>

    <form method="POST" style="display:inline;">
        <input type="hidden" name="csrf_token" value="<?php echo esc($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="ban">
        <button class="btn-action btn-ban">Ban User</button>
    </form>

    <div class="mt-4">
        <a href="OrganizerReports.php" class="btn btn-outline-light">← Back to Reports</a>
    </div>

</div>

</body>
</html>
