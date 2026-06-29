<?php
session_start();

/* ---------- CONFIG ---------- */
$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "squidpro";

/* ---------- HELPERS ---------- */
function esc($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* ---------- AUTH ---------- */
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['organizer','admin'])) {
    header("Location: login.php");
    exit;
}
$current_user_id = (int)$_SESSION['user_id'];
$is_admin = ($_SESSION['role'] === 'admin');

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['csrf_token'];

/* ---------- DB ---------- */
$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($mysqli->connect_errno) die("Database connection failed.");
$mysqli->set_charset("utf8mb4");

/* ---------- Ensure notifications table exists ---------- */
$mysqli->query("
CREATE TABLE IF NOT EXISTS notifications (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  type VARCHAR(50) NOT NULL,
  title VARCHAR(150) NOT NULL,
  body TEXT DEFAULT NULL,
  match_id INT DEFAULT NULL,
  is_read TINYINT(1) DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_match_type (user_id, match_id, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* ---------- ACTIONS ---------- */
$success = "";
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf, $token)) {
        $errors[] = "Invalid CSRF token.";
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'update_match') {
            $match_id = (int)($_POST['match_id'] ?? 0);
            $score1 = (int)($_POST['score_team1'] ?? 0);
            $score2 = (int)($_POST['score_team2'] ?? 0);
            $status = $_POST['status'] ?? 'ongoing';

            if ($match_id <= 0) $errors[] = "Invalid match id.";

            if (empty($errors)) {
                $winner_team_id = null;
                if ($score1 > $score2) {
                    $winner_team_id = (int)$_POST['team1_id'];
                } elseif ($score2 > $score1) {
                    $winner_team_id = (int)$_POST['team2_id'];
                }
                $stmt = $mysqli->prepare("UPDATE matches SET score_team1=?, score_team2=?, winner_team_id=?, status=? WHERE id=?");
                $stmt->bind_param('ssisi', $score1, $score2, $winner_team_id, $status, $match_id);
                $stmt->execute();
                $stmt->close();
                $success = "Match updated.";

                if ($status === 'finished') {
                    // notify players
                    $team1_id = (int)$_POST['team1_id'];
                    $team2_id = (int)$_POST['team2_id'];
                    $users = [];
                    $stmt = $mysqli->prepare("SELECT user_id FROM team_members WHERE team_id = ? ORDER BY role DESC, joined_at ASC LIMIT 3");
                    foreach ([$team1_id, $team2_id] as $tid) {
                        $stmt->bind_param('i', $tid);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        while ($row = $res->fetch_assoc()) $users[] = (int)$row['user_id'];
                    }
                    $stmt->close();
                    $users = array_unique($users);
                    if (!empty($users)) {
                        $ins = $mysqli->prepare("INSERT IGNORE INTO notifications (user_id, type, title, body, match_id) VALUES (?, 'match_completed', ?, ?, ?)");
                        $title = "Match results are ready";
                        $body = "Match #{$match_id} is finished. View results now.";
                        foreach ($users as $uid) {
                            $ins->bind_param('issi', $uid, $title, $body, $match_id);
                            $ins->execute();
                        }
                        $ins->close();
                    }
                }
            }
        }
    }
}

/* ---------- Load matches ---------- */
$matches = [];
$filter_status = $_GET['status'] ?? '';
$filter_game = (int)($_GET['game_id'] ?? 0);
$filter_team = (int)($_GET['team_id'] ?? 0);
$filter_player = (int)($_GET['player_id'] ?? 0);
$filter_player_name = trim($_GET['player_name'] ?? '');
$filter_organizer = (int)($_GET['organizer_id'] ?? 0);
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

$where = [];
$params = [];
$types = '';

if (!$is_admin) {
    $where[] = 'm.organizer_id = ?';
    $params[] = $current_user_id;
    $types .= 'i';
}
if ($filter_status !== '') {
    $where[] = 'm.status = ?';
    $params[] = $filter_status;
    $types .= 's';
}
if ($filter_game > 0) {
    $where[] = 'm.game_id = ?';
    $params[] = $filter_game;
    $types .= 'i';
}
if ($filter_team > 0) {
    $where[] = '(m.team1_id = ? OR m.team2_id = ?)';
    $params[] = $filter_team;
    $params[] = $filter_team;
    $types .= 'ii';
}
if ($filter_player > 0) {
    $where[] = 'EXISTS (SELECT 1 FROM team_members tm WHERE tm.team_id IN (m.team1_id, m.team2_id) AND tm.user_id = ?)';
    $params[] = $filter_player;
    $types .= 'i';
}
if ($filter_player_name !== '') {
    $where[] = "EXISTS (
        SELECT 1
        FROM team_members tm
        JOIN users u ON u.id = tm.user_id
        WHERE tm.team_id IN (m.team1_id, m.team2_id)
          AND (u.display_name LIKE CONCAT('%', ?, '%') OR u.username LIKE CONCAT('%', ?, '%'))
    )";
    $params[] = $filter_player_name;
    $params[] = $filter_player_name;
    $types .= 'ss';
}
if ($is_admin && $filter_organizer > 0) {
    $where[] = 'm.organizer_id = ?';
    $params[] = $filter_organizer;
    $types .= 'i';
}
if ($date_from !== '') {
    $where[] = 'COALESCE(m.scheduled_at, m.created_at) >= ?';
    $params[] = $date_from;
    $types .= 's';
}
if ($date_to !== '') {
    $where[] = 'COALESCE(m.scheduled_at, m.created_at) <= ?';
    $params[] = $date_to;
    $types .= 's';
}

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$sql = "
    SELECT m.*, t1.name AS team1_name, t2.name AS team2_name, g.name AS game_name
    FROM matches m
    JOIN teams t1 ON t1.id = m.team1_id
    JOIN teams t2 ON t2.id = m.team2_id
    JOIN games g ON g.id = m.game_id
    $where_sql
    ORDER BY COALESCE(m.scheduled_at, m.created_at) DESC
";
$stmt = $mysqli->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$q = $stmt->get_result();
if ($q) {
    while ($row = $q->fetch_assoc()) $matches[] = $row;
}
if (isset($stmt)) $stmt->close();

/* ---------- Load games for filter ---------- */
$games = [];
$res = $mysqli->query("SELECT id, name FROM games ORDER BY name ASC");
while ($res && ($row = $res->fetch_assoc())) $games[] = $row;

/* ---------- Load teams for filter ---------- */
$teams = [];
$res = $mysqli->query("SELECT id, name FROM teams ORDER BY name ASC");
while ($res && ($row = $res->fetch_assoc())) $teams[] = $row;

/* ---------- Load players for filter ---------- */
$players = [];
$res = $mysqli->query("SELECT id, display_name, username FROM users ORDER BY username ASC");
while ($res && ($row = $res->fetch_assoc())) $players[] = $row;

/* ---------- Load organizers for filter ---------- */
$organizers = [];
$res = $mysqli->query("SELECT id, display_name, username FROM users WHERE role IN ('organizer','admin') ORDER BY username ASC");
while ($res && ($row = $res->fetch_assoc())) $organizers[] = $row;

/* ---------- Load submissions by match ---------- */
$submissions = [];
if (!empty($matches)) {
    $ids = implode(',', array_map('intval', array_column($matches, 'id')));
    $res = $mysqli->query("SELECT match_id, user_id FROM match_player_results WHERE match_id IN ($ids)");
    while ($row = $res->fetch_assoc()) {
        $submissions[(int)$row['match_id']][(int)$row['user_id']] = true;
    }
}

/* ---------- Build rosters (top 3 per team) ---------- */
$rosters = [];
if (!empty($matches)) {
    $stmt = $mysqli->prepare("
        SELECT u.id, u.display_name, u.username
        FROM team_members tm
        JOIN users u ON u.id = tm.user_id
        WHERE tm.team_id = ?
        ORDER BY tm.role DESC, tm.joined_at ASC
        LIMIT 3
    ");
    foreach ($matches as $m) {
        $tid1 = (int)$m['team1_id'];
        $tid2 = (int)$m['team2_id'];
        $mid = (int)$m['id'];
        $rosters[$mid] = ['t1' => [], 't2' => []];

        $stmt->bind_param('i', $tid1);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $rosters[$mid]['t1'][] = $row;

        $stmt->bind_param('i', $tid2);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $rosters[$mid]['t2'][] = $row;
    }
    $stmt->close();
}

$mysqli->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>squid pro | Match Results (Organizer)</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">
<style>
    body { margin:0; font-family:'Poppins',sans-serif; background:#0d1117; color:white; }
    .bg-waves { position:fixed; inset:0; z-index:-3; background:radial-gradient(circle at 20% 20%,#1a237e,transparent 60%),radial-gradient(circle at 80% 80%,#8e24aa,transparent 60%),radial-gradient(circle at 50% 50%,#0d47a1,transparent 70%); filter:blur(90px); }
    .sidebar { width:260px;height:100vh; background:rgba(0,0,0,0.55); backdrop-filter:blur(10px); position:fixed; left:0; top:0; padding:25px 20px; border-right:1px solid rgba(255,255,255,0.1); }
    .sidebar h2 { font-weight:800;font-size:1.9rem; background:linear-gradient(90deg,#0D6EFD,#8a2be2); -webkit-background-clip:text; color:transparent; text-align:center; margin-bottom:40px; }
    .sidebar a { display:block;padding:12px 15px;margin-bottom:12px;border-radius:10px;color:white;text-decoration:none;font-size:1.05rem;transition:0.3s; }
    .sidebar a:hover,.sidebar a.active { background:linear-gradient(135deg,#0D6EFD,#8a2be2); box-shadow:0 0 15px rgba(13,110,253,0.6); }
    .main { margin-left:280px; padding:40px; }
    .card-box { background:rgba(255,255,255,0.06); border-radius:18px; padding:20px; border:1px solid rgba(255,255,255,0.1); margin-bottom:16px; }
    .muted { color:rgba(255,255,255,0.7); }
</style>
</head>
<body>
<div class="bg-waves"></div>

<div class="sidebar">
    <h2>squid pro Hub</h2>
    <a href="OrganizerDashboard.php">📊 Organizer Dashboard</a>
    <a href="OrganizerEvents.php">✅ Manage Events</a>
    <a href="OrganizerTournaments.php">🧾 Manage Tournaments</a>
    <a href="OrganizerMatches.php">⚔️ Manage Matches</a>
    <a href="OrganizerMatchResults.php" class="active">🧮 Match Results</a>
    <a href="OrganizerReports.php">📑 Review Reports</a>
    <a href="Rewards.php">🎁 Rewards</a>
    <a href="Logout.php">🚪 Logout</a>
</div>

<div class="main">
  <h1 class="fw-bold">Match Results Management</h1>
  <p class="muted">Review submissions and finalize results.</p>

  <?php if ($success): ?><div class="alert alert-success"><?php echo esc($success); ?></div><?php endif; ?>
  <?php if ($errors): foreach ($errors as $e): ?><div class="alert alert-danger"><?php echo esc($e); ?></div><?php endforeach; endif; ?>

  <div class="card-box">
    <form method="GET" class="row g-2 align-items-center">
      <div class="col-md-4">
        <select name="status" class="form-select">
          <option value="">All Status</option>
          <?php
            $opts = ['pending','accepted','ongoing','finished','rejected'];
            foreach ($opts as $o) {
              $sel = ($filter_status === $o) ? 'selected' : '';
              echo '<option value="' . esc($o) . '" ' . $sel . '>' . esc($o) . '</option>';
            }
          ?>
        </select>
      </div>
      <div class="col-md-4">
        <select name="game_id" class="form-select">
          <option value="0">All Games</option>
          <?php foreach ($games as $g): ?>
            <option value="<?php echo (int)$g['id']; ?>" <?php echo ($filter_game == (int)$g['id']) ? 'selected' : ''; ?>>
              <?php echo esc($g['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <select name="team_id" class="form-select">
          <option value="0">All Teams</option>
          <?php foreach ($teams as $t): ?>
            <option value="<?php echo (int)$t['id']; ?>" <?php echo ($filter_team == (int)$t['id']) ? 'selected' : ''; ?>>
              <?php echo esc($t['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <select name="player_id" class="form-select">
          <option value="0">All Players</option>
          <?php foreach ($players as $p): ?>
            <?php $pname = $p['display_name'] ?: $p['username']; ?>
            <option value="<?php echo (int)$p['id']; ?>" <?php echo ($filter_player == (int)$p['id']) ? 'selected' : ''; ?>>
              <?php echo esc($pname); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <input type="text" name="player_name" class="form-control" placeholder="Search player name" value="<?php echo esc($filter_player_name); ?>">
      </div>
      <?php if ($is_admin): ?>
      <div class="col-md-4">
        <select name="organizer_id" class="form-select">
          <option value="0">All Organizers</option>
          <?php foreach ($organizers as $o): ?>
            <?php $oname = $o['display_name'] ?: $o['username']; ?>
            <option value="<?php echo (int)$o['id']; ?>" <?php echo ($filter_organizer == (int)$o['id']) ? 'selected' : ''; ?>>
              <?php echo esc($oname); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="col-md-2">
        <input type="date" name="date_from" class="form-control" value="<?php echo esc($date_from); ?>">
      </div>
      <div class="col-md-2">
        <input type="date" name="date_to" class="form-control" value="<?php echo esc($date_to); ?>">
      </div>
      <div class="col-md-2 d-grid gap-2">
        <button class="btn btn-primary">Filter</button>
        <a href="OrganizerMatchResults.php" class="btn btn-outline-light">Reset</a>
      </div>
    </form>
  </div>

  <?php if (empty($matches)): ?>
    <div class="card-box">No matches found.</div>
  <?php else: ?>
    <?php foreach ($matches as $m): ?>
      <div class="card-box">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <h5><?php echo esc($m['team1_name']); ?> vs <?php echo esc($m['team2_name']); ?></h5>
            <div class="muted">Game: <?php echo esc($m['game_name']); ?> • Status: <?php echo esc($m['status']); ?></div>
            <div class="muted">Scheduled: <?php echo esc($m['scheduled_at']); ?></div>
            <div class="muted">Score: <?php echo esc($m['score_team1'] ?? '-'); ?> - <?php echo esc($m['score_team2'] ?? '-'); ?></div>
          </div>
          <div>
            <a class="btn btn-sm btn-outline-light" href="MatchResults.php?match_id=<?php echo (int)$m['id']; ?>">View</a>
          </div>
        </div>

        <form method="POST" class="row g-2 mt-3">
          <input type="hidden" name="csrf_token" value="<?php echo esc($csrf); ?>">
          <input type="hidden" name="action" value="update_match">
          <input type="hidden" name="match_id" value="<?php echo (int)$m['id']; ?>">
          <input type="hidden" name="team1_id" value="<?php echo (int)$m['team1_id']; ?>">
          <input type="hidden" name="team2_id" value="<?php echo (int)$m['team2_id']; ?>">
          <div class="col-md-2">
            <input name="score_team1" class="form-control" value="<?php echo esc($m['score_team1'] ?? 0); ?>">
          </div>
          <div class="col-md-2">
            <input name="score_team2" class="form-control" value="<?php echo esc($m['score_team2'] ?? 0); ?>">
          </div>
          <div class="col-md-3">
            <select name="status" class="form-select">
              <?php
                $opts = ['pending','accepted','ongoing','finished','rejected'];
                foreach ($opts as $o) {
                  $sel = ($m['status'] === $o) ? 'selected' : '';
                  echo '<option value="' . esc($o) . '" ' . $sel . '>' . esc($o) . '</option>';
                }
              ?>
            </select>
          </div>
          <div class="col-md-3">
            <button class="btn btn-primary">Update</button>
          </div>
        </form>

        <div class="mt-3">
          <strong>Submissions:</strong>
          <div class="row g-2 mt-1">
            <div class="col-md-6">
              <div class="muted"><?php echo esc($m['team1_name']); ?></div>
              <?php $r1 = $rosters[(int)$m['id']]['t1'] ?? []; ?>
              <?php foreach ($r1 as $p): ?>
                <?php $done = !empty($submissions[(int)$m['id']][(int)$p['id']]); ?>
                <div class="muted">• <?php echo esc($p['display_name'] ?: $p['username']); ?>
                  <?php if ($done): ?><span class="badge bg-success ms-2">Submitted</span><?php else: ?><span class="badge bg-warning text-dark ms-2">Pending</span><?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="col-md-6">
              <div class="muted"><?php echo esc($m['team2_name']); ?></div>
              <?php $r2 = $rosters[(int)$m['id']]['t2'] ?? []; ?>
              <?php foreach ($r2 as $p): ?>
                <?php $done = !empty($submissions[(int)$m['id']][(int)$p['id']]); ?>
                <div class="muted">• <?php echo esc($p['display_name'] ?: $p['username']); ?>
                  <?php if ($done): ?><span class="badge bg-success ms-2">Submitted</span><?php else: ?><span class="badge bg-warning text-dark ms-2">Pending</span><?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
</body>
</html>
