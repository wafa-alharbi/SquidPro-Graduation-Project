<?php
session_start();


/* ---------- CONFIG ---------- */
$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "squidpro";

/* Google Maps API key (replace with your key if needed) */
$google_maps_api_key = 'AIzaSyCbWxWqIM7Laq6uwkXWmk0R0geRZ5QKENw';

/* ---------- HELPERS ---------- */
function esc($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* ---------- AUTH CHECK ---------- */
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['organizer','admin'])) {
    header("Location: login.php");
    exit;
}
$_SESSION['role'] = $_SESSION['role'] ?? 'organizer';
$is_admin = ($_SESSION['role'] === 'admin');
$current_user_id = (int) $_SESSION['user_id'];

/* ---------- CSRF TOKEN ---------- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}

/* ---------- DB CONNECTION ---------- */
$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($mysqli->connect_errno) {
    die("Database connection failed.");
}
$mysqli->set_charset("utf8mb4");

/* ---------- Ensure event join table exists ---------- */
$mysqli->query("
CREATE TABLE IF NOT EXISTS event_join_requests (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  user_id INT NOT NULL,
  status ENUM('pending','approved','rejected') DEFAULT 'pending',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  responded_at DATETIME DEFAULT NULL,
  responded_by INT DEFAULT NULL,
  UNIQUE KEY uk_event_user (event_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* ---------- HANDLE APPROVE / REJECT ---------- */
$success = "";
$errors = [];

/* ---------- LOAD GAMES & LOCATIONS ---------- */
$games = [];
$locations = [];

if ($qg = $mysqli->query("SELECT id, name FROM games WHERE is_active = 1 ORDER BY name ASC")) {
    while ($row = $qg->fetch_assoc()) { $games[] = $row; }
}
if ($ql = $mysqli->query("SELECT id, name, latitude, longitude FROM locations ORDER BY name ASC")) {
    while ($row = $ql->fetch_assoc()) { $locations[] = $row; }
}

/* ---------- LOAD EVENT FOR EDIT ---------- */
$edit_event = null;
$edit_id = isset($_GET['edit_id']) ? (int) $_GET['edit_id'] : 0;
if ($edit_id > 0) {
    if ($is_admin) {
        $stmt = $mysqli->prepare("SELECT * FROM events WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $edit_id);
    } else {
        $stmt = $mysqli->prepare("SELECT * FROM events WHERE id = ? AND organizer_id = ? LIMIT 1");
        $stmt->bind_param("ii", $edit_id, $current_user_id);
    }
    $stmt->execute();
    $edit_event = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

/* ---------- HANDLE ACTIONS ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $event_id = (int) ($_POST['event_id'] ?? 0);
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $errors[] = "Invalid CSRF token.";
    } else {
        if ($action === "approve" || $action === "reject") {
            if (!$is_admin) {
                $errors[] = "You do not have permission to approve/reject events.";
            } elseif ($event_id <= 0) {
                $errors[] = "Invalid event ID.";
            } else {
                $new_status = ($action === "approve") ? "approved" : "rejected";
                $stmt = $mysqli->prepare("UPDATE events SET status = ? WHERE id = ?");
                $stmt->bind_param("si", $new_status, $event_id);
                $stmt->execute();
                $stmt->close();
                $success = ($action === "approve") ? "Event approved successfully." : "Event rejected.";
            }
        }

        if ($action === "approve_join" || $action === "reject_join") {
            $req_id = (int) ($_POST['request_id'] ?? 0);
            if ($req_id <= 0) {
                $errors[] = "Invalid request ID.";
            } else {
                // verify ownership
                if ($is_admin) {
                    $stmt = $mysqli->prepare("
                        SELECT r.id
                        FROM event_join_requests r
                        JOIN events e ON e.id = r.event_id
                        WHERE r.id = ?
                        LIMIT 1
                    ");
                    $stmt->bind_param("i", $req_id);
                } else {
                    $stmt = $mysqli->prepare("
                        SELECT r.id
                        FROM event_join_requests r
                        JOIN events e ON e.id = r.event_id
                        WHERE r.id = ? AND e.organizer_id = ?
                        LIMIT 1
                    ");
                    $stmt->bind_param("ii", $req_id, $current_user_id);
                }
                $stmt->execute();
                $stmt->store_result();
                $ok = ($stmt->num_rows > 0);
                $stmt->close();

                if (!$ok) {
                    $errors[] = "Unauthorized request.";
                } else {
                    $new_status = ($action === "approve_join") ? "approved" : "rejected";
                    $stmt = $mysqli->prepare("
                        UPDATE event_join_requests
                        SET status = ?, responded_at = NOW(), responded_by = ?
                        WHERE id = ?
                    ");
                    $stmt->bind_param("sii", $new_status, $current_user_id, $req_id);
                    $stmt->execute();
                    $stmt->close();
                    $success = ($action === "approve_join") ? "Join request approved." : "Join request rejected.";
                }
            }
        }

        if ($action === "delete") {
            if ($event_id <= 0) {
                $errors[] = "Invalid event ID.";
            } else {
                if ($is_admin) {
                    $stmt = $mysqli->prepare("DELETE FROM events WHERE id = ?");
                    $stmt->bind_param("i", $event_id);
                } else {
                    $stmt = $mysqli->prepare("DELETE FROM events WHERE id = ? AND organizer_id = ?");
                    $stmt->bind_param("ii", $event_id, $current_user_id);
                }
                $stmt->execute();
                $stmt->close();
                $success = "Event deleted successfully.";
            }
        }

        if ($action === "create" || $action === "update") {
            $name = trim($_POST['name'] ?? '');
            $game_id = (int) ($_POST['game_id'] ?? 0);
            $location_id = (int) ($_POST['location_id'] ?? 0);
            $new_location_name = trim($_POST['new_location_name'] ?? '');
            $new_address = trim($_POST['new_address'] ?? '');
            $new_lat = trim($_POST['new_latitude'] ?? '');
            $new_lng = trim($_POST['new_longitude'] ?? '');
            $start_raw = trim($_POST['start_date'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $status = $is_admin ? ($_POST['status'] ?? 'pending') : 'pending';

            if ($name === '') $errors[] = "Event name is required.";
            if ($game_id <= 0) $errors[] = "Please select a game.";
            if ($start_raw === '') $errors[] = "Start date/time is required.";

            $start_ts = $start_raw ? strtotime($start_raw) : false;
            if ($start_raw && $start_ts === false) {
                $errors[] = "Invalid start date/time.";
            }
            $start_date = $start_ts ? date('Y-m-d H:i:s', $start_ts) : null;

            $location_name = null;
            $latitude = null;
            $longitude = null;
            if ($location_id > 0) {
                $stmt = $mysqli->prepare("SELECT name, latitude, longitude FROM locations WHERE id = ? LIMIT 1");
                $stmt->bind_param("i", $location_id);
                $stmt->execute();
                $loc = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($loc) {
                    $location_name = $loc['name'];
                    $latitude = $loc['latitude'];
                    $longitude = $loc['longitude'];
                } else {
                    $errors[] = "Selected location not found.";
                }
            } else {
                // Create new location from map selection
                if ($new_location_name === '') $errors[] = "Location name is required.";
                if ($new_lat === '' || $new_lng === '' || !is_numeric($new_lat) || !is_numeric($new_lng)) {
                    $errors[] = "Valid latitude and longitude are required.";
                }
                if (empty($errors)) {
                    $ins = $mysqli->prepare("
                        INSERT INTO locations (name, type, latitude, longitude, address, created_at)
                        VALUES (?, 'other', ?, ?, ?, NOW())
                    ");
                    $ins->bind_param("sdds", $new_location_name, $new_lat, $new_lng, $new_address);
                    if ($ins->execute()) {
                        $location_id = (int)$mysqli->insert_id;
                        $location_name = $new_location_name;
                        $latitude = $new_lat;
                        $longitude = $new_lng;
                    } else {
                        $errors[] = "Failed to create new location.";
                    }
                    $ins->close();
                }
            }

            if (empty($errors)) {
                if ($action === "create") {
                    $stmt = $mysqli->prepare("
                        INSERT INTO events (name, organizer_id, game_id, location_name, latitude, longitude, start_date, description, status, location_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->bind_param(
                        "siisddsssi",
                        $name,
                        $current_user_id,
                        $game_id,
                        $location_name,
                        $latitude,
                        $longitude,
                        $start_date,
                        $description,
                        $status,
                        $location_id
                    );
                    $stmt->execute();
                    $stmt->close();
                    $success = "Event created successfully.";
                } else {
                    if ($event_id <= 0) {
                        $errors[] = "Invalid event ID.";
                    } else {
                        if ($is_admin) {
                            $stmt = $mysqli->prepare("
                                UPDATE events
                                SET name = ?, game_id = ?, location_name = ?, latitude = ?, longitude = ?, start_date = ?, description = ?, status = ?, location_id = ?
                                WHERE id = ?
                            ");
                            $stmt->bind_param(
                                "sisddssssi",
                                $name,
                                $game_id,
                                $location_name,
                                $latitude,
                                $longitude,
                                $start_date,
                                $description,
                                $status,
                                $location_id,
                                $event_id
                            );
                        } else {
                            $stmt = $mysqli->prepare("
                                UPDATE events
                                SET name = ?, game_id = ?, location_name = ?, latitude = ?, longitude = ?, start_date = ?, description = ?, location_id = ?
                                WHERE id = ? AND organizer_id = ?
                            ");
                            $stmt->bind_param(
                                "sisddsssii",
                                $name,
                                $game_id,
                                $location_name,
                                $latitude,
                                $longitude,
                                $start_date,
                                $description,
                                $location_id,
                                $event_id,
                                $current_user_id
                            );
                        }
                        $stmt->execute();
                        $stmt->close();
                        $success = "Event updated successfully.";
                        $edit_event = null;
                    }
                }
            }
        }
    }
}

/* ---------- FETCH PENDING EVENTS ---------- */
$events = [];
if ($is_admin) {
    $q = $mysqli->query("
        SELECT e.*, u.display_name AS requester, g.name AS game_name, l.name AS location_label
        FROM events e
        LEFT JOIN users u ON u.id = e.organizer_id
        LEFT JOIN games g ON g.id = e.game_id
        LEFT JOIN locations l ON l.id = e.location_id
        ORDER BY e.created_at DESC
    ");
} else {
    $stmt = $mysqli->prepare("
        SELECT e.*, u.display_name AS requester, g.name AS game_name, l.name AS location_label
        FROM events e
        LEFT JOIN users u ON u.id = e.organizer_id
        LEFT JOIN games g ON g.id = e.game_id
        LEFT JOIN locations l ON l.id = e.location_id
        WHERE e.organizer_id = ?
        ORDER BY e.created_at DESC
    ");
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $q = $stmt->get_result();
}
if ($q) {
    while ($row = $q->fetch_assoc()) {
        $events[] = $row;
    }
}

/* ---------- FETCH EVENT JOIN REQUESTS ---------- */
$join_requests = [];
if ($is_admin) {
    $jq = $mysqli->query("
        SELECT r.id, r.status, r.created_at, e.id AS event_id, e.name AS event_name,
               u.display_name AS requester
        FROM event_join_requests r
        JOIN events e ON e.id = r.event_id
        JOIN users u ON u.id = r.user_id
        ORDER BY r.created_at DESC
    ");
} else {
    $stmt = $mysqli->prepare("
        SELECT r.id, r.status, r.created_at, e.id AS event_id, e.name AS event_name,
               u.display_name AS requester
        FROM event_join_requests r
        JOIN events e ON e.id = r.event_id
        JOIN users u ON u.id = r.user_id
        WHERE e.organizer_id = ?
        ORDER BY r.created_at DESC
    ");
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $jq = $stmt->get_result();
}
if ($jq) {
    while ($row = $jq->fetch_assoc()) {
        $join_requests[] = $row;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>squid pro | Manage Events</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">

<style>
    body {
        margin:0;
        font-family:'Poppins',sans-serif;
        background:#0d1117;
        color:white;
        overflow-x:hidden;
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
        width:260px; height:100vh;
        background:rgba(0,0,0,0.55);
        backdrop-filter:blur(10px);
        position:fixed; left:0; top:0;
        padding:25px 20px;
        border-right:1px solid rgba(255,255,255,0.1);
    }
    .sidebar h2 {
        font-weight:800; font-size:1.9rem;
        background:linear-gradient(90deg,#0D6EFD,#8a2be2);
        -webkit-background-clip:text; color:transparent;
        text-align:center; margin-bottom:40px;
    }
    .sidebar a {
        display:block; padding:12px 15px; margin-bottom:12px;
        border-radius:10px; color:white; text-decoration:none;
        font-size:1.05rem; transition:0.3s;
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

    .event-card {
        background:rgba(255,255,255,0.06);
        border-radius:18px;
        padding:20px;
        border:1px solid rgba(255,255,255,0.1);
        margin-bottom:20px;
    }
    .form-card {
        background:rgba(255,255,255,0.06);
        border-radius:18px;
        padding:22px;
        border:1px solid rgba(255,255,255,0.1);
        margin-bottom:26px;
    }
    .small-muted { color: rgba(255,255,255,0.7); font-size:0.9rem; }

    .btn-approve {
        background:#198754;
        border:none;
        padding:6px 14px;
        border-radius:8px;
        color:white;
    }
    .btn-reject {
        background:#dc3545;
        border:none;
        padding:6px 14px;
        border-radius:8px;
        color:white;
    }
</style>
</head>
<body>

<div class="bg-waves"></div>

<div class="sidebar">
    <h2>squid pro Hub</h2>
    <a href="OrganizerDashboard.php">📊 Organizer Dashboard</a>
    <a href="OrganizerEvents.php" class="active">✅ Manage Events</a>
    <a href="OrganizerTournaments.php">🧾 Manage Tournaments</a>
    <a href="OrganizerMatches.php">⚔️ Manage Matches</a>
    <a href="OrganizerMatchResults.php">🧮 Match Results</a>
    <a href="OrganizerReports.php">📑 Review Reports</a>
    <a href="Rewards.php">🎁 Rewards</a>
    <a href="Logout.php">🚪 Logout</a>
</div>

<div class="main">
    <h1 class="fw-bold">Manage Events</h1>
    <p style="opacity:0.8;">Create, edit, and delete your events. Admins can also approve or reject.</p>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo esc($success); ?></div>
    <?php endif; ?>

    <?php if ($errors): foreach ($errors as $e): ?>
        <div class="alert alert-danger"><?php echo esc($e); ?></div>
    <?php endforeach; endif; ?>

    <div class="event-card">
        <h4 class="mb-3">Event Join Requests</h4>
        <?php if (empty($join_requests)): ?>
            <div class="small-muted">No join requests yet.</div>
        <?php else: ?>
            <?php foreach ($join_requests as $jr): ?>
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <div style="font-weight:700;"><?php echo esc($jr['event_name']); ?></div>
                        <div class="small-muted">Player: <?php echo esc($jr['requester']); ?></div>
                        <div class="small-muted">Status: <?php echo esc($jr['status']); ?> • <?php echo esc($jr['created_at']); ?></div>
                    </div>
                    <div class="d-flex gap-2">
                        <?php if ($jr['status'] === 'pending'): ?>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo esc($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="request_id" value="<?php echo (int)$jr['id']; ?>">
                                <input type="hidden" name="action" value="approve_join">
                                <button class="btn-approve">Approve</button>
                            </form>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo esc($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="request_id" value="<?php echo (int)$jr['id']; ?>">
                                <input type="hidden" name="action" value="reject_join">
                                <button class="btn-reject">Reject</button>
                            </form>
                        <?php else: ?>
                            <span class="badge bg-secondary"><?php echo esc($jr['status']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <hr style="border-color: rgba(255,255,255,0.08);">
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="form-card">
        <h4 class="mb-3"><?php echo $edit_event ? 'Edit Event' : 'Add New Event'; ?></h4>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo esc($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="<?php echo $edit_event ? 'update' : 'create'; ?>">
            <?php if ($edit_event): ?>
                <input type="hidden" name="event_id" value="<?php echo (int)$edit_event['id']; ?>">
            <?php endif; ?>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Event Name</label>
                    <input type="text" name="name" class="form-control" required value="<?php echo esc($edit_event['name'] ?? ''); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Game</label>
                    <select name="game_id" class="form-select">
                        <option value="0">Select game</option>
                        <?php foreach ($games as $g): ?>
                            <option value="<?php echo (int)$g['id']; ?>" <?php echo (!empty($edit_event) && (int)$edit_event['game_id'] === (int)$g['id']) ? 'selected' : ''; ?>>
                                <?php echo esc($g['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Location</label>
                    <select name="location_id" class="form-select">
                        <option value="0">Select location or pick on map</option>
                        <?php foreach ($locations as $l): ?>
                            <option value="<?php echo (int)$l['id']; ?>" <?php echo (!empty($edit_event) && (int)$edit_event['location_id'] === (int)$l['id']) ? 'selected' : ''; ?>>
                                <?php echo esc($l['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">New Location Name (optional)</label>
                    <input type="text" name="new_location_name" class="form-control" placeholder="Pick on map and name it">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Address (optional)</label>
                    <input type="text" name="new_address" class="form-control" placeholder="Address">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Latitude</label>
                    <input type="text" name="new_latitude" class="form-control" placeholder="Click map" readonly>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Longitude</label>
                    <input type="text" name="new_longitude" class="form-control" placeholder="Click map" readonly>
                </div>
                <div class="col-12">
                    <div id="eventLocationMap" style="height:260px;border-radius:12px;border:1px solid rgba(255,255,255,0.08);"></div>
                    <div class="small-muted mt-2">Tip: Click on the map to set location. If you choose a new location, leave the dropdown on "Select location or pick on map".</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Start Date & Time</label>
                    <input type="datetime-local" name="start_date" class="form-control" required
                        value="<?php echo !empty($edit_event['start_date']) ? esc(date('Y-m-d\\TH:i', strtotime($edit_event['start_date']))) : ''; ?>">
                </div>
                <?php if ($is_admin): ?>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <?php
                            $st = $edit_event['status'] ?? 'pending';
                            $statuses = ['pending','approved','rejected','completed'];
                            foreach ($statuses as $s) {
                                $sel = ($st === $s) ? 'selected' : '';
                                echo '<option value="' . esc($s) . '" ' . $sel . '>' . esc(ucfirst($s)) . '</option>';
                            }
                        ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="3"><?php echo esc($edit_event['description'] ?? ''); ?></textarea>
                </div>
            </div>

            <div class="mt-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><?php echo $edit_event ? 'Update Event' : 'Create Event'; ?></button>
                <?php if ($edit_event): ?>
                    <a href="OrganizerEvents.php" class="btn btn-outline-light">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if (empty($events)): ?>
        <div class="event-card"><p>No events found.</p></div>
    <?php else: ?>
        <?php foreach ($events as $ev): ?>
            <div class="event-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h4 class="mb-1"><?php echo esc($ev['name']); ?></h4>
                        <div style="opacity:0.85;">
                            <span>Status: <?php echo esc($ev['status']); ?></span>
                            <span class="ms-3">Game: <?php echo esc($ev['game_name'] ?: 'N/A'); ?></span>
                        </div>
                    </div>
                    <div class="text-end" style="opacity:0.8;">
                        <div><?php echo esc($ev['start_date']); ?></div>
                        <div><?php echo esc($ev['location_label'] ?: $ev['location_name'] ?: 'N/A'); ?></div>
                    </div>
                </div>

                <?php if (!empty($ev['description'])): ?>
                    <p class="mt-2 mb-2"><?php echo esc($ev['description']); ?></p>
                <?php endif; ?>

                <div class="d-flex flex-wrap gap-2">
                    <a href="OrganizerEvents.php?edit_id=<?php echo (int)$ev['id']; ?>" class="btn btn-outline-light btn-sm">Edit</a>

                    <form method="POST" onsubmit="return confirm('Delete this event?');">
                        <input type="hidden" name="csrf_token" value="<?php echo esc($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="event_id" value="<?php echo (int)$ev['id']; ?>">
                        <input type="hidden" name="action" value="delete">
                        <button class="btn btn-outline-danger btn-sm">Delete</button>
                    </form>

                    <?php if ($is_admin && $ev['status'] === 'pending'): ?>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo esc($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="event_id" value="<?php echo (int)$ev['id']; ?>">
                            <input type="hidden" name="action" value="approve">
                            <button class="btn-approve">Approve</button>
                        </form>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo esc($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="event_id" value="<?php echo (int)$ev['id']; ?>">
                            <input type="hidden" name="action" value="reject">
                            <button class="btn-reject">Reject</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

<script>
  const mapEl = document.getElementById('eventLocationMap');
  const latInput = document.querySelector('input[name="new_latitude"]');
  const lngInput = document.querySelector('input[name="new_longitude"]');
  const locSelect = document.querySelector('select[name="location_id"]');

  function initEventMap() {
    if (!mapEl) return;
    const center = { lat: 24.7136, lng: 46.6753 };
    const map = new google.maps.Map(mapEl, {
      zoom: 11,
      center: center,
      styles: [
        { elementType: "geometry", stylers: [{ color: "#1a1a1a" }] },
        { elementType: "labels.text.fill", stylers: [{ color: "#ffffff" }] },
        { elementType: "labels.text.stroke", stylers: [{ color: "#000000" }] },
        { featureType: "poi", stylers: [{ visibility: "off" }] },
        { featureType: "road", stylers: [{ color: "#2c2c2c" }] }
      ]
    });

    let marker = null;

    map.addListener('click', (e) => {
      const lat = e.latLng.lat().toFixed(6);
      const lng = e.latLng.lng().toFixed(6);
      latInput.value = lat;
      lngInput.value = lng;
      if (locSelect) locSelect.value = "0";
      if (marker) marker.setMap(null);
      marker = new google.maps.Marker({ position: e.latLng, map });
    });
  }
</script>
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo esc($google_maps_api_key); ?>&callback=initEventMap" async defer></script>
</body>
</html>
