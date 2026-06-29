<?php
session_start();

/* ---------- CONFIG ---------- */
$dbHost = "localhost";
$dbUser = "root";
$dbPass = "";
$dbName = "SquidPro";

/* ---------- HELPERS ---------- */
function esc($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* ---------- DB CONNECT ---------- */
$db = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($db->connect_errno) {
    die("Database connection failed.");
}
$db->set_charset("utf8mb4");

/* ---------- AUTH (optional: allow guests to view) ---------- */
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$user_display = $_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Guest';
$user_role = $_SESSION['role'] ?? 'player';

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['csrf_token'];

/* ---------- GET TOURNAMENT ID ---------- */
$tournament_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($tournament_id <= 0) {
    die("Invalid tournament id.");
}

/* ---------- FETCH TOURNAMENT ---------- */
$stmt = $db->prepare("
    SELECT t.*, g.name AS game_name, u.display_name AS organizer_name
    FROM tournaments t
    LEFT JOIN games g ON g.id = t.game_id
    LEFT JOIN users u ON u.id = t.organizer_id
    WHERE t.id = ?
");
$stmt->bind_param("i", $tournament_id);
$stmt->execute();
$tournament = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$tournament) die("Tournament not found.");

/* ---------- FETCH RELATED GAMES ---------- */
$related_games = [];
$check = $db->query("SHOW TABLES LIKE 'tournament_games'")->fetch_row();
if ($check) {
    $stmt = $db->prepare("
        SELECT g.id, g.name, COALESCE(g.icon, '') AS icon
        FROM tournament_games tg
        JOIN games g ON g.id = tg.game_id
        WHERE tg.tournament_id = ?
        ORDER BY g.name ASC
    ");
    $stmt->bind_param("i", $tournament_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $related_games[] = $r;
    $stmt->close();
}
if (empty($related_games) && !empty($tournament['game_id'])) {
    $related_games[] = ['id' => (int)$tournament['game_id'], 'name' => $tournament['game_name'], 'icon' => ''];
}

/* ---------- FETCH TEAMS REGISTERED IN TOURNAMENT ---------- */
$teams = [];
$stmt = $db->prepare("
    SELECT tt.team_id, teams.name
    FROM tournament_teams tt
    LEFT JOIN teams ON teams.id = tt.team_id
    WHERE tt.tournament_id = ?
");
$stmt->bind_param("i", $tournament_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $teams[] = $row;
$stmt->close();

/* ---------- FETCH MATCHES ---------- */
$matches = [];
$stmt = $db->prepare("
    SELECT m.*, t1.name AS team1_name, t2.name AS team2_name
    FROM matches m
    LEFT JOIN teams t1 ON t1.id = m.team1_id
    LEFT JOIN teams t2 ON t2.id = m.team2_id
    WHERE m.tournament_id = ?
    ORDER BY COALESCE(m.scheduled_at, m.match_date, m.created_at) ASC
");
$stmt->bind_param("i", $tournament_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $matches[] = $row;
$stmt->close();

/* ---------- USER TEAMS (captain/co-captain/owner) ---------- */
$user_teams = [];
if ($user_id) {
    $stmt = $db->prepare("
        SELECT t.id, t.name
        FROM teams t
        JOIN team_members tm ON tm.team_id = t.id
        WHERE tm.user_id = ? AND tm.role IN ('captain','co-captain','owner')
        ORDER BY t.name ASC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $user_teams[] = $r;
    $stmt->close();
}

/* ---------- FETCH USER'S EXISTING TOURNAMENT REQUESTS ---------- */
$user_tourn_requests = [];
if ($user_id && !empty($user_teams)) {
    $team_ids = array_column($user_teams, 'id');
    $in = implode(',', array_map('intval', $team_ids));
    $sql = "
        SELECT tr.id, tr.team_id, tr.status, tr.created_at, t.name AS tournament_name
        FROM tournament_team_requests tr
        LEFT JOIN tournaments t ON t.id = tr.tournament_id
        WHERE tr.tournament_id = ? AND tr.team_id IN ($in)
    ";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $tournament_id);
    $stmt->execute();
    $user_tourn_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

/* ---------- FETCH USER'S EXISTING GAME REQUESTS (for quick lookup) ---------- */
$user_game_requests = [];
if ($user_id && !empty($user_teams)) {
    $team_ids = array_column($user_teams, 'id');
    $in = implode(',', array_map('intval', $team_ids));
    $sql = "
        SELECT tr.id, tr.team_id, tr.game_id, tr.status, tr.created_at, g.name AS game_name
        FROM tournament_game_requests tr
        LEFT JOIN games g ON g.id = tr.game_id
        WHERE tr.tournament_id = ? AND tr.team_id IN ($in)
    ";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $tournament_id);
    $stmt->execute();
    $user_game_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

/* ---------- MAP REQUESTS ---------- */
$tourn_req_map = [];
foreach ($user_tourn_requests as $ur) {
    $tourn_req_map[$ur['team_id']] = $ur;
}
$game_req_map = [];
foreach ($user_game_requests as $ug) {
    $key = $ug['team_id'] . '_' . $ug['game_id'];
    $game_req_map[$key] = $ug;
}

/* ---------- HANDLE POST ACTIONS ---------- */
$errors = [];
$success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $errors[] = "Invalid CSRF token.";
    } else {
        $action = $_POST['action'] ?? '';

        /* 1) Request to join tournament (team registration) */
        if ($action === 'request_join_tournament' && $user_id) {
            $team_id = (int)($_POST['team_id'] ?? 0);
            $message = trim($_POST['message'] ?? '');

            // verify user is captain/owner of that team
            $chk = $db->prepare("SELECT role FROM team_members WHERE team_id = ? AND user_id = ? LIMIT 1");
            $chk->bind_param("ii", $team_id, $user_id);
            $chk->execute();
            $res = $chk->get_result()->fetch_assoc();
            $chk->close();
            $role_in_team = $res['role'] ?? null;

            if (!$role_in_team || !in_array($role_in_team, ['captain','co-captain','owner'])) {
                $errors[] = "You must be the team's captain to request tournament registration.";
            } else {
                // check not already registered
                $chk2 = $db->prepare("SELECT 1 FROM tournament_teams WHERE tournament_id = ? AND team_id = ? LIMIT 1");
                $chk2->bind_param("ii", $tournament_id, $team_id);
                $chk2->execute();
                $chk2->store_result();
                $already_registered = $chk2->num_rows > 0;
                $chk2->close();

                // check existing request
                $chk3 = $db->prepare("SELECT id, status FROM tournament_team_requests WHERE tournament_id = ? AND team_id = ? LIMIT 1");
                $chk3->bind_param("ii", $tournament_id, $team_id);
                $chk3->execute();
                $existing = $chk3->get_result()->fetch_assoc();
                $chk3->close();

                if ($already_registered) {
                    $errors[] = "This team is already registered in the tournament.";
                } elseif ($existing && $existing['status'] === 'pending') {
                    $errors[] = "You already have a pending tournament request for this team.";
                } else {
                    $ins = $db->prepare("INSERT INTO tournament_team_requests (tournament_id, team_id, user_id, message, status, created_at) VALUES (?, ?, ?, ?, 'pending', NOW())");
                    $ins->bind_param("iiis", $tournament_id, $team_id, $user_id, $message);
                    if ($ins->execute()) {
                        $success = "Tournament registration request submitted. Organizer will review it.";
                    } else {
                        $errors[] = "Failed to submit tournament request.";
                    }
                    $ins->close();
                }
            }
        }

        /* 2) Request to join a specific game inside tournament */
        if ($action === 'request_join_game' && $user_id) {
            $team_id = (int)($_POST['team_id'] ?? 0);
            $game_id = (int)($_POST['game_id'] ?? 0);
            $message = trim($_POST['message'] ?? '');

            // verify user role in team
            $chk = $db->prepare("SELECT role FROM team_members WHERE team_id = ? AND user_id = ? LIMIT 1");
            $chk->bind_param("ii", $team_id, $user_id);
            $chk->execute();
            $res = $chk->get_result()->fetch_assoc();
            $chk->close();
            $role_in_team = $res['role'] ?? null;

            if (!$role_in_team || !in_array($role_in_team, ['captain','co-captain','owner'])) {
                $errors[] = "You must be the team's captain to request joining a game.";
            } else {
                // check game belongs to tournament
                $chkG = $db->prepare("SELECT 1 FROM tournament_games WHERE tournament_id = ? AND game_id = ? LIMIT 1");
                $chkG->bind_param("ii", $tournament_id, $game_id);
                $chkG->execute();
                $chkG->store_result();
                $belongs = $chkG->num_rows > 0;
                $chkG->close();

                if (!$belongs) {
                    $errors[] = "Selected game is not part of this tournament.";
                } else {
                    // check duplicate request
                    $chk2 = $db->prepare("SELECT id, status FROM tournament_game_requests WHERE tournament_id = ? AND team_id = ? AND game_id = ? LIMIT 1");
                    $chk2->bind_param("iii", $tournament_id, $team_id, $game_id);
                    $chk2->execute();
                    $exists = $chk2->get_result()->fetch_assoc();
                    $chk2->close();

                    if ($exists && $exists['status'] === 'pending') {
                        $errors[] = "You already have a pending request for this team and game.";
                    } else {
                        $ins = $db->prepare("INSERT INTO tournament_game_requests (tournament_id, team_id, game_id, user_id, message, status, created_at) VALUES (?, ?, ?, ?, ?, 'pending', NOW())");
                        $ins->bind_param("iiiis", $tournament_id, $team_id, $game_id, $user_id, $message);
                        if ($ins->execute()) {
                            $success = "Game join request submitted. Organizer will review it.";
                        } else {
                            $errors[] = "Failed to submit game request.";
                        }
                        $ins->close();
                    }
                }
            }
        }

        /* 3) Cancel tournament request (by requester) */
        if ($action === 'cancel_tournament_request' && $user_id) {
            $req_id = (int)($_POST['request_id'] ?? 0);
            if ($req_id > 0) {
                $del = $db->prepare("DELETE FROM tournament_team_requests WHERE id = ? AND user_id = ? AND status = 'pending'");
                $del->bind_param("ii", $req_id, $user_id);
                if ($del->execute()) $success = "Tournament request cancelled.";
                else $errors[] = "Failed to cancel request.";
                $del->close();
            } else $errors[] = "Invalid request.";
        }

        /* 4) Cancel game request (by requester) */
        if ($action === 'cancel_game_request' && $user_id) {
            $req_id = (int)($_POST['request_id'] ?? 0);
            if ($req_id > 0) {
                $del = $db->prepare("DELETE FROM tournament_game_requests WHERE id = ? AND user_id = ? AND status = 'pending'");
                $del->bind_param("ii", $req_id, $user_id);
                if ($del->execute()) $success = "Game request cancelled.";
                else $errors[] = "Failed to cancel request.";
                $del->close();
            } else $errors[] = "Invalid request.";
        }
    }

    // refresh to show updated state
    header("Location: TournamentDetails.php?id=" . $tournament_id);
    exit;
}

/* ---------- FETCH PENDING REQUESTS FOR ORGANIZER (optional display) ---------- */
$pending_requests = [];
if ($user_id && $user_role === 'organizer' && (int)$tournament['organizer_id'] === $user_id) {
    $stmt = $db->prepare("
        SELECT tr.*, t.name AS team_name, g.name AS game_name, u.display_name AS captain_name
        FROM tournament_game_requests tr
        JOIN teams t ON t.id = tr.team_id
        JOIN games g ON g.id = tr.game_id
        JOIN users u ON u.id = tr.user_id
        WHERE tr.tournament_id = ? AND tr.status = 'pending'
        ORDER BY tr.created_at ASC
    ");
    $stmt->bind_param("i", $tournament_id);
    $stmt->execute();
    $pending_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

/* ---------- FETCH PENDING TOURNAMENT REQUESTS FOR ORGANIZER ---------- */
$pending_tourn_requests = [];
if ($user_id && $user_role === 'organizer' && (int)$tournament['organizer_id'] === $user_id) {
    $stmt = $db->prepare("
        SELECT tr.*, tm.name AS team_name, u.display_name AS captain_name
        FROM tournament_team_requests tr
        JOIN teams tm ON tm.id = tr.team_id
        JOIN users u ON u.id = tr.user_id
        WHERE tr.tournament_id = ? AND tr.status = 'pending'
        ORDER BY tr.created_at ASC
    ");
    $stmt->bind_param("i", $tournament_id);
    $stmt->execute();
    $pending_tourn_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tournament Details | <?php echo esc($tournament['name']); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">
<style>
body { background:#0d1117; color:white; font-family:'Poppins',sans-serif;  }
.container { max-width:1100px; }
.animated-bg { position:fixed; inset:0; z-index:-1;
    background:radial-gradient(circle at 20% 20%,#1a237e,transparent 60%),
               radial-gradient(circle at 80% 80%,#8e24aa,transparent 60%),
               radial-gradient(circle at 50% 50%,#0d47a1,transparent 70%);
    filter:blur(80px);
}
.card-custom { background:rgba(255,255,255,0.04); border-radius:12px; padding:18px; border:1px solid rgba(255,255,255,0.06); }
.muted { color:rgba(255,255,255,0.7); }
.btn-main { background:linear-gradient(135deg,#0D6EFD,#8a2be2); border:none; color:#fff; }
.game-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;margin-top:18px;}
.game-card{background:rgba(255,255,255,0.03);border-radius:12px;padding:16px;border:1px solid rgba(255,255,255,0.06);display:flex;flex-direction:column;justify-content:space-between;}
.chip{display:inline-block;padding:6px 10px;border-radius:999px;background:rgba(255,255,255,0.04);margin-left:6px;}
.muted-small{font-size:0.9rem;color:rgba(255,255,255,0.65);}
.status-pending{color:#ffc107;font-weight:700;}
.status-approved{color:#28a745;font-weight:700;}
.status-rejected{color:#dc3545;font-weight:700;}
.small-muted{font-size:0.9rem;color:rgba(255,255,255,0.65);}
.modal-content { background:#0d1117; color:white; }
</style>
</head>
<body>
<div class="animated-bg"></div>

<nav class="navbar navbar-expand-lg navbar-dark fixed-top" style="background:rgba(0,0,0,0.55);backdrop-filter:blur(6px);">
  <div class="container">
    <a class="navbar-brand fw-bold" href="index.php">squid pro</a>
    <div class="ms-auto">
      <?php if ($user_id): ?>
        <span class="muted me-3">Signed in as <?php echo esc($user_display); ?></span>
        <a href="Profile.php" class="btn btn-outline-light btn-sm me-2">Profile</a>
        <a href="Logout.php" class="btn btn-outline-light btn-sm">Logout</a>
      <?php else: ?>
        <a href="login.php" class="btn btn-primary">Login</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<div class="container" style="margin-top:110px;">
  <div class="card-custom mb-4">
    <div class="d-flex justify-content-between align-items-start">
      <div>
        <h2><?php echo esc($tournament['name']); ?></h2>
        <div class="muted">Organizer: <?php echo esc($tournament['organizer_name'] ?? '—'); ?> • Status: <?php echo esc($tournament['status']); ?></div>
        <p class="muted-small mt-2"><?php echo nl2br(esc($tournament['description'])); ?></p>
      </div>
      <div class="text-end">
        <div class="chip">Start: <?php echo $tournament['start_date'] ? esc(date('Y-m-d H:i', strtotime($tournament['start_date']))) : '—'; ?></div>
        <div class="chip mt-2">Games: <?php echo count($related_games); ?></div>
      </div>
    </div>
  </div>

  <?php if (!empty($success)): ?>
    <div class="alert alert-success"><?php echo esc($success); ?></div>
  <?php endif; ?>
  <?php if (!empty($errors)): foreach ($errors as $e): ?>
    <div class="alert alert-danger"><?php echo esc($e); ?></div>
  <?php endforeach; endif; ?>

  <!-- Tournament registration (global) -->
  <div class="card-custom mb-4">
    <h5>Register Your Team for This Tournament</h5>
    <?php if (!$user_id): ?>
      <p class="muted">Please log in to request registration.</p>
    <?php elseif (empty($user_teams)): ?>
      <p class="muted">You are not captain of any team. Only captains can request registration.</p>
    <?php else: ?>
      <p class="muted">Select one of your teams to request registration for the tournament.</p>

      <?php if (!empty($tourn_req_map)): ?>
        <div class="mb-2">
          <strong>Your tournament requests (for this tournament):</strong>
          <ul>
            <?php foreach ($user_tourn_requests as $ur): ?>
              <li><?php echo esc($ur['team_id'] ? $ur['team_id'] : 'Team'); ?> — <?php echo esc($ur['status']); ?>
                <?php if ($ur['status'] === 'pending'): ?>
                  <form method="POST" style="display:inline-block;margin-left:8px;">
                    <input type="hidden" name="csrf_token" value="<?php echo esc($csrf); ?>">
                    <input type="hidden" name="action" value="cancel_tournament_request">
                    <input type="hidden" name="request_id" value="<?php echo (int)$ur['id']; ?>">
                    <button class="btn btn-sm btn-outline-light">Cancel</button>
                  </form>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <form method="POST" class="row g-2">
        <input type="hidden" name="csrf_token" value="<?php echo esc($csrf); ?>">
        <input type="hidden" name="action" value="request_join_tournament">
        <div class="col-md-6">
          <select name="team_id" class="form-select" required>
            <option value="">Select your team...</option>
            <?php foreach ($user_teams as $ut): ?>
              <option value="<?php echo (int)$ut['id']; ?>"><?php echo esc($ut['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <input name="message" class="form-control" placeholder="Optional message to organizer">
        </div>
        <div class="col-12">
          <button class="btn btn-main">Request Tournament Registration</button>
        </div>
      </form>
    <?php endif; ?>
  </div>

  <!-- Games grid -->
  <h4>Games in this Tournament</h4>
  <?php if (empty($related_games)): ?>
    <div class="card-custom"><p class="muted">No games associated with this tournament.</p></div>
  <?php else: ?>
    <div class="game-grid">
      <?php foreach ($related_games as $g):
          $gid = (int)$g['id'];
      ?>
        <div class="game-card">
          <div>
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <h5 class="fw-bold"><?php echo esc($g['name']); ?></h5>
                <p class="muted-small">Short description for the game if available</p>
              </div>
              <div style="font-size:28px;"><?php echo esc($g['icon'] ?? '🎮'); ?></div>
            </div>

            <div class="mt-3">
              <strong>Registered teams:</strong>
              <?php
                $stmt = $db->prepare("SELECT COUNT(*) FROM tournament_teams WHERE tournament_id = ?");
                $stmt->bind_param("i", $tournament_id);
                $stmt->execute();
                $stmt->bind_result($reg_count);
                $stmt->fetch();
                $stmt->close();
              ?>
              <span class="muted"><?php echo (int)$reg_count; ?> teams</span>
            </div>
          </div>

          <div class="mt-3">
            <?php if ($user_id && !empty($user_teams)): ?>
              <?php foreach ($user_teams as $ut):
                  $key = $ut['id'] . '_' . $gid;
                  $existing = $game_req_map[$key] ?? null;
              ?>
                <div class="mb-2 d-flex justify-content-between align-items-center">
                  <div><small class="muted"><?php echo esc($ut['name']); ?></small></div>
                  <div>
                    <?php if ($existing): ?>
                      <?php if ($existing['status'] === 'pending'): ?>
                        <span class="status-pending">Pending</span>
                        <form method="POST" style="display:inline-block;margin-left:8px;">
                          <input type="hidden" name="csrf_token" value="<?php echo esc($csrf); ?>">
                          <input type="hidden" name="action" value="cancel_game_request">
                          <input type="hidden" name="request_id" value="<?php echo (int)$existing['id']; ?>">
                          <button class="btn btn-sm btn-outline-light">Cancel</button>
                        </form>
                      <?php elseif ($existing['status'] === 'approved'): ?>
                        <span class="status-approved">Approved</span>
                      <?php else: ?>
                        <span class="status-rejected">Rejected</span>
                      <?php endif; ?>
                    <?php else: ?>
                      <button class="btn btn-sm btn-main" data-bs-toggle="modal" data-bs-target="#reqModal_<?php echo (int)$ut['id'] . '_' . $gid; ?>">Request Join</button>
                    <?php endif; ?>
                  </div>
                </div>

                <?php if (!$existing): ?>
                <div class="modal fade" id="reqModal_<?php echo (int)$ut['id'] . '_' . $gid; ?>" tabindex="-1">
                  <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                      <div class="modal-header">
                        <h5 class="modal-title">Request to register your team in <?php echo esc($g['name']); ?></h5>
                        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                      </div>
                      <form method="POST">
                        <div class="modal-body">
                          <input type="hidden" name="csrf_token" value="<?php echo esc($csrf); ?>">
                          <input type="hidden" name="action" value="request_join_game">
                          <input type="hidden" name="team_id" value="<?php echo (int)$ut['id']; ?>">
                          <input type="hidden" name="game_id" value="<?php echo $gid; ?>">
                          <p class="muted-small">Send a short message to the organizer (optional):</p>
                          <textarea name="message" class="form-control" rows="3" placeholder="Example: Our team is ready and wants to participate"></textarea>
                        </div>
                        <div class="modal-footer">
                          <button class="btn btn-outline-light" data-bs-dismiss="modal">Cancel</button>
                          <button class="btn btn-main">Send Request</button>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
                <?php endif; ?>

              <?php endforeach; ?>
            <?php else: ?>
              <div class="muted">To submit a request you must be the captain of a team.</div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- Organizer: pending requests -->
  <?php if (!empty($pending_tourn_requests) || !empty($pending_requests)): ?>
    <div class="card-custom mt-4">
      <h5>Pending Requests (Organizer)</h5>

      <?php if (!empty($pending_tourn_requests)): ?>
        <div class="mt-2">
          <h6>Tournament Registration Requests</h6>
          <?php foreach ($pending_tourn_requests as $r): ?>
            <div class="req-row d-flex justify-content-between align-items-start">
              <div>
                <strong><?php echo esc($r['team_name']); ?></strong>
                <div class="small-muted mt-1"><?php echo nl2br(esc($r['message'])); ?></div>
                <div class="small-muted mt-1">Requested by: <?php echo esc($r['captain_name']); ?> • <?php echo esc($r['created_at']); ?></div>
              </div>
              <div class="text-end">
                <form method="POST" style="display:inline-block;">
                  <input type="hidden" name="csrf_token" value="<?php echo esc($csrf); ?>">
                  <input type="hidden" name="action" value="approve_request">
                  <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                  <button class="btn btn-sm btn-main">Approve</button>
                </form>
                <form method="POST" style="display:inline-block;margin-left:8px;">
                  <input type="hidden" name="csrf_token" value="<?php echo esc($csrf); ?>">
                  <input type="hidden" name="action" value="reject_request">
                  <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                  <button class="btn btn-sm btn-outline-light">Reject</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($pending_requests)): ?>
        <div class="mt-3">
          <h6>Game Join Requests</h6>
          <?php foreach ($pending_requests as $pr): ?>
            <div class="req-row d-flex justify-content-between align-items-start">
              <div>
                <strong><?php echo esc($pr['team_name']); ?></strong> — Game: <?php echo esc($pr['game_name']); ?>
                <div class="small-muted mt-1"><?php echo nl2br(esc($pr['message'])); ?></div>
                <div class="small-muted mt-1">Requested by: <?php echo esc($pr['captain_name']); ?> • <?php echo esc($pr['created_at']); ?></div>
              </div>
              <div class="text-end">
                <form method="POST" style="display:inline-block;">
                  <input type="hidden" name="csrf_token" value="<?php echo esc($csrf); ?>">
                  <input type="hidden" name="action" value="approve_game_request">
                  <input type="hidden" name="request_id" value="<?php echo (int)$pr['id']; ?>">
                  <button class="btn btn-sm btn-main">Approve</button>
                </form>
                <form method="POST" style="display:inline-block;margin-left:8px;">
                  <input type="hidden" name="csrf_token" value="<?php echo esc($csrf); ?>">
                  <input type="hidden" name="action" value="reject_game_request">
                  <input type="hidden" name="request_id" value="<?php echo (int)$pr['id']; ?>">
                  <button class="btn btn-sm btn-outline-light">Reject</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    </div>
  <?php endif; ?>

  <!-- Matches -->
  <div class="card-custom mt-4">
    <h5>Matches</h5>
    <?php if (empty($matches)): ?>
      <p class="muted">No matches scheduled.</p>
    <?php else: ?>
      <?php foreach ($matches as $m): ?>
        <div class="mb-3" style="background:rgba(255,255,255,0.02);padding:12px;border-radius:8px;">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <strong><?php echo esc($m['team1_name']); ?></strong> vs <strong><?php echo esc($m['team2_name']); ?></strong>
              <div class="muted-small"><?php echo esc($m['match_date'] ?? $m['scheduled_at'] ?? $m['created_at']); ?></div>
            </div>
            <div class="text-end">
              <div class="muted">Status: <?php echo esc($m['status']); ?></div>
              <?php if ($m['status'] === 'finished'): ?>
                <div class="mt-2"><strong><?php echo esc($m['score_team1'] ?? $m['team_score']); ?> - <?php echo esc($m['score_team2'] ?? $m['opponent_score']); ?></strong></div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
