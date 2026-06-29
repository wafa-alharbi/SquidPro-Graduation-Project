<?php
session_start();

/*
  TournamentDetails.php
  - Shows full details of a single tournament
  - Organizer/admin can:
      * validate
      * reject
      * mark ongoing
      * mark finished
      * edit basic fields
      * delete tournament
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

/* ---------- GET TOURNAMENT ID ---------- */
$tournament_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($tournament_id <= 0) {
    die("Invalid tournament ID.");
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
    $token  = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $errors[] = "Invalid CSRF token.";
    } else {
        if ($action === 'update_status') {
            $new_status = $_POST['new_status'] ?? '';
            $allowed = ['pending','validated','rejected','ongoing','finished'];
            if (!in_array($new_status, $allowed)) {
                $errors[] = "Invalid status.";
            } else {
                $stmt = $mysqli->prepare("UPDATE tournaments SET status = ? WHERE id = ?");
                $stmt->bind_param("si", $new_status, $tournament_id);
                $stmt->execute();
                $stmt->close();
                $success = "Tournament status updated to: {$new_status}.";
            }
        }
        elseif ($action === 'edit_tournament') {
            $name        = trim($_POST['name'] ?? '');
            $format      = trim($_POST['format'] ?? '');
            $start_date  = trim($_POST['start_date'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if ($name === '') {
                $errors[] = "Name is required.";
            } else {
                $stmt = $mysqli->prepare("
                    UPDATE tournaments
                    SET name = ?, format = ?, start_date = ?, description = ?
                    WHERE id = ?
                ");
                $stmt->bind_param("ssssi", $name, $format, $start_date, $description, $tournament_id);
                $stmt->execute();
                $stmt->close();
                $success = "Tournament details updated.";
            }
        }
        elseif ($action === 'delete_tournament') {
            // Optional: delete related matches and tournament_teams first if you want cascade behavior
            $stmt = $mysqli->prepare("DELETE FROM matches WHERE tournament_id = ?");
            $stmt->bind_param("i", $tournament_id);
            $stmt->execute();
            $stmt->close();

            $stmt = $mysqli->prepare("DELETE FROM tournament_teams WHERE tournament_id = ?");
            $stmt->bind_param("i", $tournament_id);
            $stmt->execute();
            $stmt->close();

            $stmt = $mysqli->prepare("DELETE FROM tournaments WHERE id = ?");
            $stmt->bind_param("i", $tournament_id);
            $stmt->execute();
            $stmt->close();

            header("Location: OrganizerTournaments.php?msg=deleted");
            exit;
        }
    }
}

/* ---------- FETCH TOURNAMENT ---------- */
$stmt = $mysqli->prepare("
    SELECT t.*, 
           u.display_name AS organizer_name,
           g.name AS game_name
    FROM tournaments t
    LEFT JOIN users u ON u.id = t.organizer_id
    LEFT JOIN games g ON g.id = t.game_id
    WHERE t.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $tournament_id);
$stmt->execute();
$tournament = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$tournament) {
    die("Tournament not found.");
}

/* ---------- FETCH TEAMS ---------- */
$teams = [];
$stmt = $mysqli->prepare("
    SELECT tm.id, tm.name, tm.logo_url
    FROM tournament_teams tt
    JOIN teams tm ON tm.id = tt.team_id
    WHERE tt.tournament_id = ?
");
$stmt->bind_param("i", $tournament_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $teams[] = $row;
}
$stmt->close();

/* ---------- FETCH MATCHES ---------- */
$matches = [];
$stmt = $mysqli->prepare("
    SELECT m.*, 
           t1.name AS team1_name,
           t2.name AS team2_name
    FROM matches m
    JOIN teams t1 ON t1.id = m.team1_id
    JOIN teams t2 ON t2.id = m.team2_id
    WHERE m.tournament_id = ?
    ORDER BY m.match_date ASC
");
$stmt->bind_param("i", $tournament_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $matches[] = $row;
}
$stmt->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>squid pro Hub | Tournament Details</title>

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

    .card-block {
        background:rgba(255,255,255,0.06);
        border-radius:18px;
        padding:20px;
        border:1px solid rgba(255,255,255,0.1);
        margin-bottom:20px;
    }

    .btn-action {
        padding:6px 14px;
        border-radius:8px;
        border:none;
        color:white;
        margin-right:6px;
        margin-top:6px;
    }
    .btn-validate { background:#0D6EFD; }
    .btn-reject   { background:#dc3545; }
    .btn-ongoing  { background:#ffc107; color:#000; }
    .btn-finished { background:#198754; }
    .btn-delete   { background:#6c757d; }

    label { font-weight:600; }
</style>
</head>
<body>

<div class="bg-waves"></div>

<div class="sidebar">
    <h2>squid pro Hub</h2>

    <a href="OrganizerDashboard.php">📊 Organizer Dashboard</a>
    <a href="OrganizerEvents.php">✅ Approve Events</a>
    <a href="OrganizerTournaments.php" class="active">🧾 Validate Tournaments</a>
    <a href="OrganizerReports.php">📑 Review Reports</a>
    <a href="Rewards.php">🎁 Rewards</a>
    <a href="Logout.php">🚪 Logout</a>
</div>

<div class="main">
    <h1 class="fw-bold">Tournament Details</h1>
    <p style="opacity:0.8;">Full details and management controls for this tournament.</p>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo esc($success); ?></div>
    <?php endif; ?>

    <?php if ($errors): foreach ($errors as $e): ?>
        <div class="alert alert-danger"><?php echo esc($e); ?></div>
    <?php endforeach; endif; ?>

    <!-- TOURNAMENT INFO -->
    <div class="card-block">
        <h3><?php echo esc($tournament['name']); ?></h3>
        <p><strong>Game:</strong> <?php echo esc($tournament['game_name']); ?></p>
        <p><strong>Organizer:</strong> <?php echo esc($tournament['organizer_name']); ?></p>
        <p><strong>Status:</strong> <?php echo esc($tournament['status']); ?></p>
        <p><strong>Format:</strong> <?php echo esc($tournament['format']); ?></p>
        <p><strong>Start Date:</strong> <?php echo esc($tournament['start_date']); ?></p>
        <p><strong>Description:</strong> <?php echo esc($tournament['description']); ?></p>
        <p><strong>Created at:</strong> <?php echo esc($tournament['created_at']); ?></p>
    </div>

    <!-- STATUS ACTIONS -->
    <div class="card-block">
        <h4>Status Actions</h4>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?php echo esc($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="new_status" value="validated">
            <button class="btn-action btn-validate">Validate</button>
        </form>

        <form method="POST" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?php echo esc($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="new_status" value="rejected">
            <button class="btn-action btn-reject">Reject</button>
        </form>

        <form method="POST" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?php echo esc($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="new_status" value="ongoing">
            <button class="btn-action btn-ongoing">Mark Ongoing</button>
        </form>

        <form method="POST" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?php echo esc($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="new_status" value="finished">
            <button class="btn-action btn-finished">Mark Finished</button>
        </form>
    </div>

    <!-- EDIT TOURNAMENT -->
    <div class="card-block">
        <h4>Edit Tournament</h4>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo esc($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="edit_tournament">

            <div class="mb-3">
                <label>Name</label>
                <input type="text" name="name" class="form-control"
                       value="<?php echo esc($tournament['name']); ?>">
            </div>

            <div class="mb-3">
                <label>Format</label>
                <input type="text" name="format" class="form-control"
                       value="<?php echo esc($tournament['format']); ?>">
            </div>

            <div class="mb-3">
                <label>Start Date (YYYY-MM-DD HH:MM:SS)</label>
                <input type="text" name="start_date" class="form-control"
                       value="<?php echo esc($tournament['start_date']); ?>">
            </div>

            <div class="mb-3">
                <label>Description</label>
                <textarea name="description" class="form-control" rows="3"><?php echo esc($tournament['description']); ?></textarea>
            </div>

            <button class="btn btn-primary">Save Changes</button>
        </form>
    </div>

    <!-- TEAMS -->
    <div class="card-block">
        <h4>Registered Teams</h4>
        <?php if (empty($teams)): ?>
            <p>No teams registered yet.</p>
        <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($teams as $tm): ?>
                    <li class="list-group-item bg-transparent text-white border-secondary">
                        <?php echo esc($tm['name']); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <!-- MATCHES -->
    <div class="card-block">
        <h4>Matches</h4>
        <?php if (empty($matches)): ?>
            <p>No matches scheduled yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-dark table-striped align-middle">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Teams</th>
                            <th>Score</th>
                            <th>Status</th>
                            <th>Match Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($matches as $m): ?>
                            <tr>
                                <td><?php echo (int)$m['id']; ?></td>
                                <td><?php echo esc($m['team1_name']); ?> vs <?php echo esc($m['team2_name']); ?></td>
                                <td><?php echo esc($m['score_team1'] . " - " . $m['score_team2']); ?></td>
                                <td><?php echo esc($m['status']); ?></td>
                                <td><?php echo esc($m['match_date']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- DELETE -->
    <div class="card-block">
        <h4>Danger Zone</h4>
        <form method="POST" onsubmit="return confirm('Are you sure you want to delete this tournament and all its matches/teams?');">
            <input type="hidden" name="csrf_token" value="<?php echo esc($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="delete_tournament">
            <button class="btn-action btn-delete">Delete Tournament</button>
        </form>
    </div>

    <div class="mt-3">
        <a href="OrganizerTournaments.php" class="btn btn-outline-light">← Back to Tournaments</a>
    </div>

</div>

</body>
</html>
