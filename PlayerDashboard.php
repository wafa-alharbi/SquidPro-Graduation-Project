
<?php
session_start();



/* ---------- CONFIG ---------- */
$dbHost = "localhost";
$dbUser = "root";
$dbPass = "";
$dbName = "squidpro";

/* ---------- HELPERS ---------- */
function esc($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function isOrganizerOrAdmin() {
    return isset($_SESSION['user_id']) && in_array($_SESSION['role'] ?? '', ['organizer','admin']);
}

/* ---------- AUTH ---------- */
if (!isOrganizerOrAdmin()) {
    header("Location: login.php");
    exit;
}
$currentUserId = (int)$_SESSION['user_id'];

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['csrf_token'];

/* ---------- DB ---------- */
$db = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($db->connect_errno) die("DB connection failed.");
$db->set_charset("utf8mb4");

/* ---------- Ensure helper tables exist (safe to run) ---------- */
$db->query("
CREATE TABLE IF NOT EXISTS tournament_games (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tournament_id INT NOT NULL,
  game_id INT NOT NULL,
  UNIQUE KEY uk_tg (tournament_id, game_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
$db->query("
CREATE TABLE IF NOT EXISTS tournament_team_requests (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tournament_id INT NOT NULL,
  team_id INT NOT NULL,
  user_id INT NOT NULL,
  message TEXT DEFAULT NULL,
  status ENUM('pending','approved','rejected') DEFAULT 'pending',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME DEFAULT NULL,
  processed_by INT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* ---------- ACTIONS: create / edit / delete tournament, approve/reject requests ---------- */
$errors = [];
$success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf, $token)) {
        $errors[] = "Invalid CSRF token.";
    } else {
        $action = $_POST['action'] ?? '';

        /* CREATE */
        if ($action === 'create') {
            $name = trim($_POST['name'] ?? '');
            $format = trim($_POST['format'] ?? '');
            $start_date = trim($_POST['start_date'] ?? null);
            $description = trim($_POST['description'] ?? '');
            $games = $_POST['games'] ?? [];

            if ($name === '') $errors[] = "Tournament name is required.";
            if (empty($games)) $errors[] = "Select at least one game.";

            if (empty($errors)) {
                $db->begin_transaction();
                try {
                    // store first game as game_id for compatibility
                    $primary_game = (int)$games[0];
                    $stmt = $db->prepare("INSERT INTO tournaments (name, game_id, organizer_id, format, start_date, description, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");
                    $stmt->bind_param("siisss", $name, $primary_game, $currentUserId, $format, $start_date, $description);
                    if (!$stmt->execute()) throw new Exception("Insert tournament failed.");
                    $tournament_id = (int)$db->insert_id;
                    $stmt->close();

                    $ins = $db->prepare("INSERT IGNORE INTO tournament_games (tournament_id, game_id) VALUES (?, ?)");
                    foreach ($games as $g) {
                        $gid = (int)$g;
                        $ins->bind_param("ii", $tournament_id, $gid);
                        $ins->execute();
                    }
                    $ins->close();

                    $db->commit();
                    $success = "Tournament created successfully.";
                } catch (Exception $e) {
                    $db->rollback();
                    $errors[] = "Failed to create tournament.";
                }
            }
        }

        /* EDIT */
        if ($action === 'edit') {
            $tournament_id = (int)($_POST['tournament_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $format = trim($_POST['format'] ?? '');
            $start_date = trim($_POST['start_date'] ?? null);
            $description = trim($_POST['description'] ?? '');
            $games = $_POST['games'] ?? [];

            if ($tournament_id <= 0) $errors[] = "Invalid tournament.";
            if ($name === '') $errors[] = "Tournament name is required.";
            if (empty($games)) $errors[] = "Select at least one game.";

            if (empty($errors)) {
                $db->begin_transaction();
                try {
                    $primary_game = (int)$games[0];
                    $stmt = $db->prepare("UPDATE tournaments SET name=?, game_id=?, format=?, start_date=?, description=? WHERE id=?");
                    $stmt->bind_param("sisssi", $name, $primary_game, $format, $start_date, $description, $tournament_id);
                    if (!$stmt->execute()) throw new Exception("Update failed.");
                    $stmt->close();

                    $del = $db->prepare("DELETE FROM tournament_games WHERE tournament_id = ?");
                    $del->bind_param("i", $tournament_id);
                    $del->execute();
                    $del->close();

                    $ins = $db->prepare("INSERT IGNORE INTO tournament_games (tournament_id, game_id) VALUES (?, ?)");
                    foreach ($games as $g) {
                        $gid = (int)$g;
                        $ins->bind_param("ii", $tournament_id, $gid);
                        $ins->execute();
                    }
                    $ins->close();

                    $db->commit();
                    $success = "Tournament updated successfully.";
                } catch (Exception $e) {
                    $db->rollback();
                    $errors[] = "Failed to update tournament.";
                }
            }
        }

        /* DELETE */
        if ($action === 'delete') {
            $tournament_id = (int)($_POST['tournament_id'] ?? 0);
            if ($tournament_id <= 0) $errors[] = "Invalid tournament.";
            if (empty($errors)) {
                $db->begin_transaction();
                try {
                    // remove related rows
                    $stmt = $db->prepare("DELETE FROM tournament_games WHERE tournament_id = ?");
                    $stmt->bind_param("i", $tournament_id); $stmt->execute(); $stmt->close();

                    $stmt = $db->prepare("DELETE FROM tournament_teams WHERE tournament_id = ?");
                    $stmt->bind_param("i", $tournament_id); $stmt->execute(); $stmt->close();

                    $stmt = $db->prepare("DELETE FROM matches WHERE tournament_id = ?");
                    $stmt->bind_param("i", $tournament_id); $stmt->execute(); $stmt->close();

                    $stmt = $db->prepare("DELETE FROM tournament_team_requests WHERE tournament_id = ?");
                    $stmt->bind_param("i", $tournament_id); $stmt->execute(); $stmt->close();

                    $stmt = $db->prepare("DELETE FROM tournaments WHERE id = ?");
                    $stmt->bind_param("i", $tournament_id); $stmt->execute(); $stmt->close();

                    $db->commit();
                    $success = "Tournament deleted.";
                } catch (Exception $e) {
                    $db->rollback();
                    $errors[] = "Failed to delete tournament.";
                }
            }
        }

        /* Approve team join request */
        if ($action === 'approve_request') {
            $req_id = (int)($_POST['request_id'] ?? 0);
            if ($req_id <= 0) $errors[] = "Invalid request id.";
            if (empty($errors)) {
                $db->begin_transaction();
                try {
                    // fetch request
                    $stmt = $db->prepare("SELECT tournament_id, team_id FROM tournament_team_requests WHERE id = ? AND status = 'pending' LIMIT 1");
                    $stmt->bind_param("i", $req_id);
                    $stmt->execute();
                    $r = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$r) throw new Exception("Request not found.");

                    $tourn = (int)$r['tournament_id'];
                    $team = (int)$r['team_id'];

                    // mark request approved
                    $u = $db->prepare("UPDATE tournament_team_requests SET status='approved', processed_at = NOW(), processed_by = ? WHERE id = ?");
                    $u->bind_param("ii", $currentUserId, $req_id);
                    $u->execute();
                    $u->close();

                    // add to tournament_teams if not exists
                    $chk = $db->prepare("SELECT 1 FROM tournament_teams WHERE tournament_id = ? AND team_id = ? LIMIT 1");
                    $chk->bind_param("ii", $tourn, $team);
                    $chk->execute();
                    $chk->store_result();
                    if ($chk->num_rows === 0) {
                        $chk->close();
                        $ins = $db->prepare("INSERT INTO tournament_teams (tournament_id, team_id) VALUES (?, ?)");
                        $ins->bind_param("ii", $tourn, $team);
                        $ins->execute();
                        $ins->close();
                    } else $chk->close();

                    $db->commit();
                    $success = "Request approved and team registered.";
                } catch (Exception $e) {
                    $db->rollback();
                    $errors[] = "Failed to approve request.";
                }
            }
        }

        /* Reject team join request */
        if ($action === 'reject_request') {
            $req_id = (int)($_POST['request_id'] ?? 0);
            if ($req_id <= 0) $errors[] = "Invalid request id.";
            if (empty($errors)) {
                $stmt = $db->prepare("UPDATE tournament_team_requests SET status='rejected', processed_at = NOW(), processed_by = ? WHERE id = ?");
                $stmt->bind_param("ii", $currentUserId, $req_id);
                if ($stmt->execute()) $success = "Request rejected.";
                else $errors[] = "Failed to reject request.";
                $stmt->close();
            }
        }
    }
}

/* ---------- LOAD GAMES (for selects) ---------- */
$games = [];
$res = $db->query("SELECT id, name FROM games WHERE is_active = 1 ORDER BY name ASC");
while ($r = $res->fetch_assoc()) $games[] = $r;

/* ---------- LOAD TOURNAMENTS (all statuses) ---------- */
$tournaments = [];
$sql = "
    SELECT t.*, u.display_name AS organizer_name,
           (SELECT COUNT(*) FROM tournament_teams tt WHERE tt.tournament_id = t.id) AS team_count,
           GROUP_CONCAT(g.name SEPARATOR '||') AS games_list,
           GROUP_CONCAT(g.id SEPARATOR ',') AS games_ids
    FROM tournaments t
    LEFT JOIN users u ON u.id = t.organizer_id
    LEFT JOIN tournament_games tg ON tg.tournament_id = t.id
    LEFT JOIN games g ON g.id = tg.game_id
    GROUP BY t.id
    ORDER BY t.created_at DESC
";
$res = $db->query($sql);
while ($r = $res->fetch_assoc()) {
    $r['games_list'] = $r['games_list'] ? explode('||', $r['games_list']) : [];
    $r['games_ids'] = $r['games_ids'] ? array_map('intval', explode(',', $r['games_ids'])) : [];
    $tournaments[] = $r;
}

/* ---------- LOAD PENDING TEAM REQUESTS (for organizer) ---------- */
$pending_requests = [];
$stmt = $db->prepare("
    SELECT tr.*, t.name AS team_name, u.display_name AS captain_name, tn.name AS tournament_name
    FROM tournament_team_requests tr
    JOIN teams t ON t.id = tr.team_id
    JOIN users u ON u.id = tr.user_id
    JOIN tournaments tn ON tn.id = tr.tournament_id
    WHERE tr.status = 'pending'
    ORDER BY tr.created_at ASC
");
$stmt->execute();
$pending_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Squid Pro | Organizer Tournaments</title>
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
.sidebar a { display:block;padding:12px 15px;margin-bottom:12px;border-radius:10px;color:white;text-decoration:none;font-size:1.05rem;transition:0.3s; }
.sidebar a:hover, .sidebar a.active { background:linear-gradient(135deg,#0D6EFD,#8a2be2); box-shadow:0 0 15px rgba(13,110,253,0.6); }

.main { margin-left:280px; padding:40px; }
.card-custom { background:rgba(255,255,255,0.04); border-radius:12px; padding:18px; border:1px solid rgba(255,255,255,0.06); margin-bottom:18px; }
.tournament-card { background:rgba(255,255,255,0.06); border-radius:12px; padding:16px; border:1px solid rgba(255,255,255,0.08); margin-bottom:14px; }
.muted { color:rgba(255,255,255,0.7); }
.btn-main { background:linear-gradient(135deg,#0D6EFD,#8a2be2); border:none; color:#fff; }
.chips{display:flex;gap:6px;flex-wrap:wrap;}
.chip{background:rgba(255,255,255,0.06);padding:6px 10px;border-radius:999px;font-size:0.9rem;}
.small-muted{font-size:0.9rem;color:rgba(255,255,255,0.65);}
.req-row{background:rgba(255,255,255,0.02);padding:12px;border-radius:8px;margin-bottom:10px;}
</style>
</head>
<body>
<div class="animated-bg"></div>

<div class="sidebar">
    <h2>squid pro Hub</h2>
    <a href="OrganizerDashboard.php">📊 Organizer Dashboard</a>
    <a href="OrganizerEvents.php">✅ Manage Events</a>
    <a href="OrganizerTournaments.php" class="active">🧾 Manage Tournaments</a>
    <a href="OrganizerMatches.php">⚔️ Manage Matches</a>
    <a href="OrganizerMatchResults.php">🧮 Match Results</a>
    <a href="OrganizerReports.php">📑 Review Reports</a>
    <a href="Rewards.php">🎁 Rewards</a>
    <a href="Logout.php">🚪 Logout</a>
</div>

<div class="main container" style="margin-top:40px;">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h1 class="fw-bold">Manage Tournaments</h1>
      <p class="muted">Create, edit, delete tournaments and handle team join requests.</p>
    </div>
    <div>
      <button class="btn btn-main" data-bs-toggle="modal" data-bs-target="#createModal">+ Create Tournament</button>
    </div>
  </div>

  <?php if ($success): ?><div class="alert alert-success"><?php echo esc($success); ?></div><?php endif; ?>
  <?php if ($errors): foreach ($errors as $e): ?><div class="alert alert-danger"><?php echo esc($e); ?></div><?php endforeach; endif; ?>

  <!-- Pending team requests (global) -->
  <div class="card-custom">
    <h5>Pending Team Join Requests</h5>
    <?php if (empty($pending_requests)): ?>
      <p class="muted">No pending requests.</p>
    <?php else: ?>
      <?php foreach ($pending_requests as $pr): ?>
        <div class="req-row d-flex justify-content-between align-items-start">
          <div>
            <strong><?php echo esc($pr['team_name']); ?></strong> requested to join <strong><?php echo esc($pr['tournament_name']); ?></strong>
            <div class="small-muted mt-1"><?php echo nl2br(esc($pr['message'])); ?></div>
            <div class="small-muted mt-1">Requested by: <?php echo esc($pr['captain_name']); ?> â€¢ <?php echo esc($pr['created_at']); ?></div>
          </div>
          <div class="text-end">
            <form method="POST" style="display:inline-block;">
              <input type="hidden" name="csrf_token" value="<?php echo esc($csrf); ?>">
              <input type="hidden" name="action" value="approve_request">
              <input type="hidden" name="request_id" value="<?php echo (int)$pr['id']; ?>">
              <button class="btn btn-sm btn-main">Approve</button>
            </form>
            <form method="POST" style="display:inline-block;margin-left:8px;">
              <input type="hidden" name="csrf_token" value="<?php echo esc($csrf); ?>">
              <input type="hidden" name="action" value="reject_request">
              <input type="hidden" name="request_id" value="<?php echo (int)$pr['id']; ?>">
              <button class="btn btn-sm btn-outline-light">Reject</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Tournaments list -->
  <div class="card-custom mt-3">
    <h5>All Tournaments</h5>
    <?php if (empty($tournaments)): ?>
      <p class="muted">No tournaments found.</p>
    <?php else: ?>
      <?php foreach ($tournaments as $t): ?>
        <div class="tournament-card d-flex justify-content-between align-items-start">
          <div style="flex:1;">
            <h5><?php echo esc($t['name']); ?> <span class="small-muted">[<?php echo esc($t['status']); ?>]</span></h5>
            <div class="muted">Organizer: <?php echo esc($t['organizer_name'] ?? 'â€”'); ?> â€¢ Teams: <?php echo (int)$t['team_count']; ?> â€¢ Start: <?php echo esc($t['start_date'] ?? 'â€”'); ?></div>
            <p class="mt-2"><?php echo nl2br(esc($t['description'])); ?></p>
            <div class="chips mt-2">
              <?php foreach ($t['games_list'] as $gname): ?>
                <div class="chip"><?php echo esc($gname); ?></div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="ms-3 text-end">
            <button class="btn btn-outline-light mb-2" data-bs-toggle="modal" data-bs-target="#editModal<?php echo (int)$t['id']; ?>">Edit</button>

            <form method="POST" style="display:inline-block;" onsubmit="return confirm('Delete this tournament?');">
              <input type="hidden" name="csrf_token" value="<?php echo esc($csrf); ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="tournament_id" value="<?php echo (int)$t['id']; ?>">
              <button class="btn btn-danger">Delete</button>
            </form>
          </div>
        </div>

        <!-- Edit Modal -->
        <div class="modal fade" id="editModal<?php echo (int)$t['id']; ?>" tabindex="-1">
          <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content" style="background:#0d1117;color:white;">
              <div class="modal-header">
                <h5 class="modal-title">Edit Tournament</h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
              </div>
              <form method="POST">
                <div class="modal-body">
                  <input type="hidden" name="csrf_token" value="<?php echo esc($csrf); ?>">
                  <input type="hidden" name="action" value="edit">
                  <input type="hidden" name="tournament_id" value="<?php echo (int)$t['id']; ?>">

                  <label class="form-label">Name</label>
                  <input name="name" class="form-control" value="<?php echo esc($t['name']); ?>" required>

                  <label class="form-label mt-3">Format</label>
                  <input name="format" class="form-control" value="<?php echo esc($t['format'] ?? ''); ?>">

                  <label class="form-label mt-3">Start Date</label>
                  <input type="datetime-local" name="start_date" class="form-control" value="<?php echo $t['start_date'] ? date('Y-m-d\TH:i', strtotime($t['start_date'])) : ''; ?>">

                  <label class="form-label mt-3">Description</label>
                  <textarea name="description" class="form-control" rows="4"><?php echo esc($t['description']); ?></textarea>

                  <label class="form-label mt-3">Games</label>
                  <select name="games[]" class="form-select" multiple required>
                    <?php foreach ($games as $g): ?>
                      <option value="<?php echo (int)$g['id']; ?>" <?php echo in_array((int)$g['id'], $t['games_ids']) ? 'selected' : ''; ?>>
                        <?php echo esc($g['name']); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <div class="muted mt-1">Hold Ctrl (or Cmd) to select multiple games.</div>
                </div>
                <div class="modal-footer">
                  <button class="btn btn-outline-light" data-bs-dismiss="modal">Cancel</button>
                  <button class="btn btn-main">Save changes</button>
                </div>
              </form>
            </div>
          </div>
        </div>

      <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div>

<!-- Create Modal -->
<div class="modal fade" id="createModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content" style="background:#0d1117;color:white;">
      <div class="modal-header">
        <h5 class="modal-title">Create Tournament</h5>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?php echo esc($csrf); ?>">
          <input type="hidden" name="action" value="create">

          <label class="form-label">Name</label>
          <input name="name" class="form-control" required>

          <label class="form-label mt-3">Format</label>
          <input name="format" class="form-control">

          <label class="form-label mt-3">Start Date</label>
          <input type="datetime-local" name="start_date" class="form-control">

          <label class="form-label mt-3">Description</label>
          <textarea name="description" class="form-control" rows="4"></textarea>

          <label class="form-label mt-3">Games</label>
          <select name="games[]" class="form-select" multiple required>
            <?php foreach ($games as $g): ?>
              <option value="<?php echo (int)$g['id']; ?>"><?php echo esc($g['name']); ?></option>
            <?php endforeach; ?>
          </select>
          <div class="muted mt-1">Hold Ctrl (or Cmd) to select multiple games.</div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline-light" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-main">Create Tournament</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
