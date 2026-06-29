
<?php
session_start();

/*
  OrganizerMatches.php
  - Full rewrite: professional, secure, and visually consistent with your identity
  - Features:
    * Organizer/admin only access
    * Create / edit / delete matches
    * When creating a match, games and teams are loaded per selected tournament
      and teams list is filtered by the selected game (teams that are registered
      and approved for that game are prioritized)
    * Deletes cascade-related dependent rows (reports, notifications) before deleting a match
    * Prepared statements, CSRF, input validation, friendly UI messages
*/

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
if ($db->connect_errno) {
    die("Database connection failed.");
}
$db->set_charset("utf8mb4");

/* ---------- UTILS ---------- */
function flash_success($msg) { return ['type'=>'success','text'=>$msg]; }
function flash_error($msg) { return ['type'=>'error','text'=>$msg]; }

/* ---------- ACTIONS (POST) ---------- */
$messages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf, $token)) {
        $errors[] = "Invalid CSRF token.";
    } else {
        $action = $_POST['action'] ?? '';

        /* CREATE MATCH */
        if ($action === 'create_match') {
            $tournament_id = (int)($_POST['tournament_id'] ?? 0);
            $game_id = (int)($_POST['game_id'] ?? 0);
            $team1_id = (int)($_POST['team1_id'] ?? 0);
            $team2_id = (int)($_POST['team2_id'] ?? 0);
            $scheduled_at = trim($_POST['scheduled_at'] ?? null);

            if ($tournament_id <= 0) $errors[] = "Please select a tournament.";
            if ($game_id <= 0) $errors[] = "Please select a game.";
            if ($team1_id <= 0 || $team2_id <= 0) $errors[] = "Please select two teams.";
            if ($team1_id === $team2_id) $errors[] = "Team 1 and Team 2 must be different.";

            if (empty($errors)) {
                // Verify both teams are registered in the tournament and eligible for the selected game
                $stmt = $db->prepare("
                    SELECT COUNT(DISTINCT tt.team_id) AS cnt
                    FROM tournament_teams tt
                    WHERE tt.tournament_id = ? AND tt.team_id IN (?, ?)
                ");
                $stmt->bind_param("iii", $tournament_id, $team1_id, $team2_id);
                $stmt->execute();
                $stmt->bind_result($cnt);
                $stmt->fetch();
                $stmt->close();

                if ($cnt < 2) {
                    $errors[] = "Both teams must be registered in the tournament.";
                } else {
                    // Optional: ensure teams are approved for the specific game (if your flow requires it)
                    // We'll allow scheduling if teams are registered; organizer can still approve game-specific registration separately.
                    $ins = $db->prepare("
                        INSERT INTO matches (tournament_id, game_id, team1_id, team2_id, status, scheduled_at, organizer_id, created_at)
                        VALUES (?, ?, ?, ?, 'pending', ?, ?, NOW())
                    ");
                    $ins->bind_param("iiiisi", $tournament_id, $game_id, $team1_id, $team2_id, $scheduled_at, $currentUserId);
                    if ($ins->execute()) {
                        $messages[] = flash_success("Match created successfully and is pending captain approval.");
                    } else {
                        $errors[] = "Failed to create match: " . $ins->error;
                    }
                    $ins->close();
                }
            }
        }

        /* EDIT MATCH */
        if ($action === 'edit_match') {
            $match_id = (int)($_POST['match_id'] ?? 0);
            $scheduled_at = trim($_POST['scheduled_at'] ?? null);
            $status = $_POST['status'] ?? 'pending';

            if ($match_id <= 0) $errors[] = "Invalid match id.";
            if (empty($errors)) {
                $stmt = $db->prepare("UPDATE matches SET scheduled_at = ?, status = ? WHERE id = ?");
                $stmt->bind_param("ssi", $scheduled_at, $status, $match_id);
                if ($stmt->execute()) {
                    $messages[] = flash_success("Match updated successfully.");
                } else {
                    $errors[] = "Failed to update match: " . $stmt->error;
                }
                $stmt->close();
            }
        }

        /* DELETE MATCH */
        if ($action === 'delete_match') {
            $match_id = (int)($_POST['match_id'] ?? 0);
            if ($match_id <= 0) $errors[] = "Invalid match id.";
            if (empty($errors)) {
                $db->begin_transaction();
                try {
                    // Delete dependent rows that reference matches to avoid FK constraint errors
                    // 1) reports
                    $del = $db->prepare("DELETE FROM reports WHERE match_id = ?");
                    $del->bind_param("i", $match_id);
                    $del->execute();
                    $del->close();

                    // 2) any other tables referencing matches (notifications, chat messages, etc.)
                    // Example: notifications table with match_id
                    $del2 = $db->prepare("DELETE FROM notifications WHERE match_id = ?");
                    if ($del2) {
                        $del2->bind_param("i", $match_id);
                        $del2->execute();
                        $del2->close();
                    }

                    // 3) Finally delete the match
                    $stmt = $db->prepare("DELETE FROM matches WHERE id = ?");
                    $stmt->bind_param("i", $match_id);
                    if (!$stmt->execute()) throw new Exception("Failed to delete match: " . $stmt->error);
                    $stmt->close();

                    $db->commit();
                    $messages[] = flash_success("Match and related data deleted successfully.");
                } catch (Exception $e) {
                    $db->rollback();
                    $errors[] = "Failed to delete match: " . $e->getMessage();
                }
            }
        }
    }

    // redirect to avoid resubmission and to show updated lists
    $redirect = "OrganizerMatches.php";
    if (!empty($_POST['tournament_id'])) $redirect .= "?tournament_id=" . (int)$_POST['tournament_id'];
    header("Location: " . $redirect);
    exit;
}

/* ---------- LOAD DATA FOR UI ---------- */

/* Load tournaments (for filter/select) */
$tournaments = [];
$res = $db->query("SELECT id, name FROM tournaments ORDER BY created_at DESC");
while ($r = $res->fetch_assoc()) $tournaments[] = $r;

/* Determine selected tournament (filter) */
$selected_tournament = isset($_GET['tournament_id']) ? (int)$_GET['tournament_id'] : 0;

/* Load matches (filtered by tournament if provided) */
$matches = [];
if ($selected_tournament) {
    $stmt = $db->prepare("
        SELECT m.*, t1.name AS team1_name, t2.name AS team2_name, g.name AS game_name
        FROM matches m
        LEFT JOIN teams t1 ON t1.id = m.team1_id
        LEFT JOIN teams t2 ON t2.id = m.team2_id
        LEFT JOIN games g ON g.id = m.game_id
        WHERE m.tournament_id = ?
        ORDER BY COALESCE(m.scheduled_at, m.match_date, m.created_at) ASC
    ");
    $stmt->bind_param("i", $selected_tournament);
    $stmt->execute();
    $matches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $res = $db->query("
        SELECT m.*, t1.name AS team1_name, t2.name AS team2_name, g.name AS game_name, tn.name AS tournament_name
        FROM matches m
        LEFT JOIN teams t1 ON t1.id = m.team1_id
        LEFT JOIN teams t2 ON t2.id = m.team2_id
        LEFT JOIN games g ON g.id = m.game_id
        LEFT JOIN tournaments tn ON tn.id = m.tournament_id
        ORDER BY COALESCE(m.scheduled_at, m.match_date, m.created_at) DESC
    ");
    $matches = $res->fetch_all(MYSQLI_ASSOC);
}

/* Build a JSON map for client-side selection:
   For each tournament:
     - games: list of games (id,name)
     - teams_by_game: teams approved for that game (via tournament_game_requests.status='approved')
     - fallback_teams: teams registered in tournament (tournament_teams)
*/
$tourn_map = [];

/* initialize map keys */
foreach ($tournaments as $t) {
    $tourn_map[(int)$t['id']] = ['games'=>[], 'teams_by_game'=>[], 'fallback_teams'=>[]];
}

/* games per tournament */
$stmt = $db->prepare("SELECT tg.tournament_id, g.id, g.name FROM tournament_games tg JOIN games g ON g.id = tg.game_id");
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $tid = (int)$r['tournament_id'];
    if (!isset($tourn_map[$tid])) $tourn_map[$tid] = ['games'=>[], 'teams_by_game'=>[], 'fallback_teams'=>[]];
    $tourn_map[$tid]['games'][] = ['id'=>(int)$r['id'], 'name'=>$r['name']];
}
$stmt->close();

/* teams registered in tournament (fallback) */
$stmt = $db->prepare("SELECT tt.tournament_id, teams.id, teams.name FROM tournament_teams tt JOIN teams ON teams.id = tt.team_id");
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $tid = (int)$r['tournament_id'];
    if (!isset($tourn_map[$tid])) $tourn_map[$tid] = ['games'=>[], 'teams_by_game'=>[], 'fallback_teams'=>[]];
    $tourn_map[$tid]['fallback_teams'][] = ['id'=>(int)$r['id'], 'name'=>$r['name']];
}
$stmt->close();

/* teams approved per game (preferred list) */
$stmt = $db->prepare("
    SELECT r.tournament_id, r.game_id, teams.id, teams.name
    FROM tournament_game_requests r
    JOIN teams ON teams.id = r.team_id
    WHERE r.status = 'approved'
");
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $tid = (int)$r['tournament_id'];
    $gid = (int)$r['game_id'];
    if (!isset($tourn_map[$tid])) $tourn_map[$tid] = ['games'=>[], 'teams_by_game'=>[], 'fallback_teams'=>[]];
    if (!isset($tourn_map[$tid]['teams_by_game'][$gid])) $tourn_map[$tid]['teams_by_game'][$gid] = [];
    $tourn_map[$tid]['teams_by_game'][$gid][] = ['id'=>(int)$r['id'], 'name'=>$r['name']];
}
$stmt->close();

/* JSON encode map for client */
$tourn_map_json = json_encode($tourn_map, JSON_UNESCAPED_UNICODE);

/* ---------- RENDER UI ---------- */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Squid Pro | Organizer Matches</title>
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
.match-row { background:rgba(255,255,255,0.02); padding:12px; border-radius:8px; margin-bottom:12px; }
.muted { color:rgba(255,255,255,0.7); }
.btn-main { background:linear-gradient(135deg,#0D6EFD,#8a2be2); border:none; color:#fff; }
.small-muted{font-size:0.9rem;color:rgba(255,255,255,0.65);}
.form-inline .form-control{background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.04);color:#fff;}
.modal-content{background:#0d1117;color:white;}
.badge-status { padding:6px 10px; border-radius:999px; font-weight:600; }
.badge-pending { background:#ffc107; color:#111; }
.badge-ongoing { background:#0dcaf0; color:#042; }
.badge-finished { background:#28a745; color:#042; }
.badge-rejected { background:#dc3545; color:#fff; }
</style>
</head>
<body>
<div class="animated-bg"></div>

<div class="sidebar">
    <h2>squid pro Hub</h2>
    <a href="OrganizerDashboard.php">📊 Organizer Dashboard</a>
    <a href="OrganizerEvents.php">✅ Manage Events</a>
    <a href="OrganizerTournaments.php">🧾 Manage Tournaments</a>
    <a href="OrganizerMatches.php" class="active">⚔️ Manage Matches</a>
    <a href="OrganizerMatchResults.php">🧮 Match Results</a>
    <a href="OrganizerReports.php">📑 Review Reports</a>
    <a href="Rewards.php">🎁 Rewards</a>
    <a href="Logout.php">🚪 Logout</a>
</div>

<div class="main container" style="margin-top:40px;">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h1 class="fw-bold">Manage Matches</h1>
      <p class="muted">Create, edit and remove matches. Teams list updates by selected tournament & game.</p>
    </div>
    <div>
      <button class="btn btn-main" data-bs-toggle="modal" data-bs-target="#createMatchModal">+ Create Match</button>
    </div>
  </div>

  <?php if (!empty($messages)): foreach ($messages as $m): ?>
    <?php if ($m['type'] === 'success'): ?>
      <div class="alert alert-success"><?php echo esc($m['text']); ?></div>
    <?php endif; ?>
  <?php endforeach; endif; ?>

  <?php if (!empty($errors)): foreach ($errors as $e): ?>
    <div class="alert alert-danger"><?php echo esc($e); ?></div>
  <?php endforeach; endif; ?>

  <div class="card-custom mb-3">
    <form method="GET" class="row g-2 align-items-center">
      <div class="col-md-6">
        <select name="tournament_id" class="form-select" onchange="this.form.submit()">
          <option value="">Filter by tournament (all)</option>
          <?php foreach ($tournaments as $t): ?>
            <option value="<?php echo (int)$t['id']; ?>" <?php echo $selected_tournament == $t['id'] ? 'selected' : ''; ?>><?php echo esc($t['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6 text-end">
        <small class="small-muted">Select a tournament to load its games and registered teams for scheduling.</small>
      </div>
    </form>
  </div>

  <div class="card-custom">
    <h5><?php echo $selected_tournament ? "Matches for selected tournament" : "All Matches"; ?></h5>

    <?php if (empty($matches)): ?>
      <p class="muted">No matches found.</p>
    <?php else: ?>
      <?php foreach ($matches as $m): ?>
        <div class="match-row d-flex justify-content-between align-items-start">
          <div>
            <div class="d-flex gap-3 align-items-center">
              <div>
                <strong><?php echo esc($m['team1_name']); ?></strong>
                <span class="small-muted">vs</span>
                <strong><?php echo esc($m['team2_name']); ?></strong>
              </div>
              <div class="small-muted">â€¢ <?php echo esc($m['game_name'] ?? 'Game'); ?></div>
            </div>
            <div class="small-muted mt-1"><?php echo esc($m['scheduled_at'] ?? $m['match_date'] ?? $m['created_at']); ?></div>
            <div class="mt-2">
              <?php
                $status = $m['status'] ?? 'pending';
                $badgeClass = 'badge-pending';
                if ($status === 'ongoing') $badgeClass = 'badge-ongoing';
                if ($status === 'finished') $badgeClass = 'badge-finished';
                if ($status === 'rejected') $badgeClass = 'badge-rejected';
              ?>
              <span class="badge-status <?php echo $badgeClass; ?>"><?php echo esc($status); ?></span>
            </div>
          </div>

          <div class="text-end">
            <button class="btn btn-sm btn-outline-light mb-1" data-bs-toggle="modal" data-bs-target="#editMatchModal_<?php echo (int)$m['id']; ?>">Edit</button>
            <form method="POST" style="display:inline-block;" onsubmit="return confirm('Delete this match and its related data?');">
              <input type="hidden" name="csrf_token" value="<?php echo esc($csrf); ?>">
              <input type="hidden" name="action" value="delete_match">
              <input type="hidden" name="match_id" value="<?php echo (int)$m['id']; ?>">
              <button class="btn btn-sm btn-danger">Delete</button>
            </form>
          </div>
        </div>

        <!-- Edit Match Modal -->
        <div class="modal fade" id="editMatchModal_<?php echo (int)$m['id']; ?>" tabindex="-1">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title">Edit Match</h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
              </div>
              <form method="POST">
                <div class="modal-body">
                  <input type="hidden" name="csrf_token" value="<?php echo esc($csrf); ?>">
                  <input type="hidden" name="action" value="edit_match">
                  <input type="hidden" name="match_id" value="<?php echo (int)$m['id']; ?>">

                  <label class="form-label">Scheduled At</label>
                  <input type="datetime-local" name="scheduled_at" class="form-control" value="<?php echo $m['scheduled_at'] ? date('Y-m-d\TH:i', strtotime($m['scheduled_at'])) : ''; ?>">

                  <label class="form-label mt-3">Status</label>
                  <select name="status" class="form-select">
                    <option value="pending" <?php echo ($m['status'] === 'pending') ? 'selected' : ''; ?>>pending</option>
                    <option value="accepted" <?php echo ($m['status'] === 'accepted') ? 'selected' : ''; ?>>accepted</option>
                    <option value="rejected" <?php echo ($m['status'] === 'rejected') ? 'selected' : ''; ?>>rejected</option>
                    <option value="ongoing" <?php echo ($m['status'] === 'ongoing') ? 'selected' : ''; ?>>ongoing</option>
                    <option value="finished" <?php echo ($m['status'] === 'finished') ? 'selected' : ''; ?>>finished</option>
                  </select>
                </div>
                <div class="modal-footer">
                  <button class="btn btn-outline-light" data-bs-dismiss="modal">Cancel</button>
                  <button class="btn btn-main">Save</button>
                </div>
              </form>
            </div>
          </div>
        </div>

      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Create Match Modal -->
  <div class="modal fade" id="createMatchModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Create Match</h5>
          <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <form method="POST">
          <div class="modal-body">
            <input type="hidden" name="csrf_token" value="<?php echo esc($csrf); ?>">
            <input type="hidden" name="action" value="create_match">

            <label class="form-label">Tournament</label>
            <select name="tournament_id" id="create_tournament" class="form-select" required onchange="onTournamentChange(this.value)">
              <option value="">Select tournament...</option>
              <?php foreach ($tournaments as $t): ?>
                <option value="<?php echo (int)$t['id']; ?>"><?php echo esc($t['name']); ?></option>
              <?php endforeach; ?>
            </select>

            <div id="tourn-data" style="display:none;margin-top:12px;">
              <label class="form-label">Game</label>
              <select name="game_id" id="create_game" class="form-select" required onchange="onGameChange(this.value)"></select>

              <div class="row g-2 mt-2">
                <div class="col-md-6">
                  <label class="form-label">Team 1</label>
                  <select name="team1_id" id="create_team1" class="form-select" required></select>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Team 2</label>
                  <select name="team2_id" id="create_team2" class="form-select" required></select>
                </div>
              </div>

              <label class="form-label mt-3">Scheduled At</label>
              <input type="datetime-local" name="scheduled_at" class="form-control">
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-outline-light" data-bs-dismiss="modal">Cancel</button>
            <button class="btn btn-main">Create Match</button>
          </div>
        </form>
      </div>
    </div>
  </div>

</div>

<script>
/* Client-side map: tournament -> {games:[], teams_by_game:{gameId:[]}, fallback_teams:[] } */
const tournMap = <?php echo $tourn_map_json; ?>;

function onTournamentChange(tid) {
  const container = document.getElementById('tourn-data');
  const gameSel = document.getElementById('create_game');
  const t1 = document.getElementById('create_team1');
  const t2 = document.getElementById('create_team2');

  gameSel.innerHTML = '';
  t1.innerHTML = '';
  t2.innerHTML = '';

  if (!tid || !tournMap[tid]) {
    container.style.display = 'none';
    return;
  }

  const data = tournMap[tid];

  // populate games
  if (data.games && data.games.length) {
    data.games.forEach(g => {
      const opt = document.createElement('option'); opt.value = g.id; opt.textContent = g.name; gameSel.appendChild(opt);
    });
  } else {
    const opt = document.createElement('option'); opt.value = ''; opt.textContent = 'No games linked to this tournament'; gameSel.appendChild(opt);
  }

  // populate fallback teams (initial)
  const teams = data.fallback_teams || [];
  teams.forEach(tm => {
    const o1 = document.createElement('option'); o1.value = tm.id; o1.textContent = tm.name; t1.appendChild(o1);
    const o2 = document.createElement('option'); o2.value = tm.id; o2.textContent = tm.name; t2.appendChild(o2);
  });

  container.style.display = 'block';
}

function onGameChange(gameId) {
  const tournamentId = document.getElementById('create_tournament').value;
  const t1 = document.getElementById('create_team1');
  const t2 = document.getElementById('create_team2');

  t1.innerHTML = '';
  t2.innerHTML = '';

  if (!tournamentId || !tournMap[tournamentId]) return;

  const data = tournMap[tournamentId];
  const teamsByGame = data.teams_by_game || {};
  const preferred = teamsByGame[gameId] || [];

  // If there are approved teams for this game, show them; otherwise fallback to registered teams
  const list = (preferred.length > 0) ? preferred : (data.fallback_teams || []);

  list.forEach(tm => {
    const o1 = document.createElement('option'); o1.value = tm.id; o1.textContent = tm.name; t1.appendChild(o1);
    const o2 = document.createElement('option'); o2.value = tm.id; o2.textContent = tm.name; t2.appendChild(o2);
  });
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
