<?php
session_start();

/* ---------- CONFIG ---------- */
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'SquidPro';

/* ---------- HELPERS ---------- */
function esc($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* ---------- AUTH ---------- */
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$user_id = (int)$_SESSION['user_id'];

$match_id = (int)($_GET['match_id'] ?? 0);
if ($match_id <= 0) { die('Invalid match.'); }

$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($mysqli->connect_errno) { die('Database connection failed.'); }
$mysqli->set_charset('utf8mb4');

/* Ensure match_player_results table exists */
$mysqli->query("
CREATE TABLE IF NOT EXISTS match_player_results (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  match_id INT NOT NULL,
  user_id INT NOT NULL,
  team_id INT NOT NULL,
  game_id INT NOT NULL,
  score_for INT NOT NULL,
  score_against INT NOT NULL,
  result ENUM('win','loss','draw') NOT NULL,
  submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_match_user (match_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* Ensure match_checkins table exists */
$mysqli->query("
CREATE TABLE IF NOT EXISTS match_checkins (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  match_id INT NOT NULL,
  user_id INT NOT NULL,
  team_id INT NOT NULL,
  checked_in_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_match_user (match_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$stmt = $mysqli->prepare("
    SELECT m.*, t1.name AS team1_name, t2.name AS team2_name, g.name AS game_name
    FROM matches m
    JOIN teams t1 ON t1.id = m.team1_id
    JOIN teams t2 ON t2.id = m.team2_id
    JOIN games g ON g.id = m.game_id
    WHERE m.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $match_id);
$stmt->execute();
$match = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$match) { die('Match not found.'); }

// Verify user is in one of the teams (or captain)
$allowed = false;
$team_ids = [(int)$match['team1_id'], (int)$match['team2_id']];
$stmt = $mysqli->prepare("SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ? LIMIT 1");
foreach ($team_ids as $tid) {
    $stmt->bind_param('ii', $tid, $user_id);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) { $allowed = true; break; }
}
$stmt->close();
if (!$allowed) {
    $stmt = $mysqli->prepare("SELECT 1 FROM teams WHERE (id = ? OR id = ?) AND captain_id = ? LIMIT 1");
    $stmt->bind_param('iii', $team_ids[0], $team_ids[1], $user_id);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) $allowed = true;
    $stmt->close();
}
if (!$allowed) { die('Access denied.'); }

// Record check-in
$team1_id = (int)$match['team1_id'];
$team2_id = (int)$match['team2_id'];
$my_team_id = in_array($team1_id, $team_ids, true) ? $team1_id : $team2_id;
$stmt = $mysqli->prepare("INSERT IGNORE INTO match_checkins (match_id, user_id, team_id) VALUES (?, ?, ?)");
$stmt->bind_param('iii', $match_id, $user_id, $my_team_id);
$stmt->execute();
$stmt->close();

// Forfeit logic: if one team checked in and other not after 3 minutes
$forfeit_winner = null;
$deadline = null;
if (!empty($match['scheduled_at'])) {
    $deadline = (new DateTime($match['scheduled_at']))->modify('+3 minutes');
} else {
    // use first check-in time as reference
    $stmt = $mysqli->prepare("SELECT MIN(checked_in_at) FROM match_checkins WHERE match_id = ?");
    $stmt->bind_param('i', $match_id);
    $stmt->execute();
    $stmt->bind_result($first_checkin);
    if ($stmt->fetch() && $first_checkin) {
        $deadline = (new DateTime($first_checkin))->modify('+3 minutes');
    }
    $stmt->close();
}

if ($deadline) {
    $now = new DateTime('now');
    $stmt = $mysqli->prepare("
        SELECT team_id, COUNT(*) AS c
        FROM match_checkins
        WHERE match_id = ?
        GROUP BY team_id
    ");
    $stmt->bind_param('i', $match_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $counts = [];
    while ($row = $res->fetch_assoc()) { $counts[(int)$row['team_id']] = (int)$row['c']; }
    $stmt->close();

    $team1_in = !empty($counts[$team1_id]);
    $team2_in = !empty($counts[$team2_id]);

    if ($now >= $deadline && ($team1_in xor $team2_in) && $match['status'] !== 'finished') {
        $forfeit_winner = $team1_in ? $team1_id : $team2_id;
        $score1 = $team1_in ? 1 : 0;
        $score2 = $team2_in ? 1 : 0;
        $stmt = $mysqli->prepare("UPDATE matches SET score_team1 = ?, score_team2 = ?, winner_team_id = ?, status = 'finished', match_date = NOW() WHERE id = ?");
        $stmt->bind_param('ssii', $score1, $score2, $forfeit_winner, $match_id);
        $stmt->execute();
        $stmt->close();
        $match['status'] = 'finished';
        $match['score_team1'] = $score1;
        $match['score_team2'] = $score2;
    }
}

// Load rosters (top 3 players)
function load_roster($mysqli, $team_id) {
    $players = [];
    $stmt = $mysqli->prepare("
        SELECT u.id, u.display_name, u.username
        FROM team_members tm
        JOIN users u ON u.id = tm.user_id
        WHERE tm.team_id = ?
        ORDER BY tm.role DESC, tm.joined_at ASC
        LIMIT 3
    ");
    $stmt->bind_param('i', $team_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $players[] = $row;
    $stmt->close();
    return $players;
}
$team1_roster = load_roster($mysqli, (int)$match['team1_id']);
$team2_roster = load_roster($mysqli, (int)$match['team2_id']);

// Load submissions
$submissions = [];
$stmt = $mysqli->prepare("SELECT user_id FROM match_player_results WHERE match_id = ?");
$stmt->bind_param('i', $match_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $submissions[(int)$row['user_id']] = true;
}
$stmt->close();

$mysqli->close();

$game_link = null;
if ((int)$match['game_id'] === 1) $game_link = 'PlayRPS.php';
if ((int)$match['game_id'] === 3) $game_link = 'PlayPadel.php';

$scheduled_at = $match['scheduled_at'] ?? null;
$now = new DateTime('now');
$can_play = true;
if (!empty($scheduled_at)) {
    $sched = new DateTime($scheduled_at);
    if ($now < $sched) $can_play = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Squid Pro | Match Lobby</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">
<style>
  body { margin:0; font-family:'Poppins',sans-serif; background:#0d1117; color:#fff; }
  .bg { position:fixed; inset:0; z-index:-1; background: radial-gradient(circle at 20% 20%, #1a237e, transparent 60%), radial-gradient(circle at 80% 80%, #8e24aa, transparent 60%), radial-gradient(circle at 50% 50%, #0d47a1, transparent 70%); filter: blur(90px); }
  .navbar { background-color: rgba(0,0,0,0.55) !important; backdrop-filter: blur(6px); }
  .logo-icon { width:55px; height:55px; background:linear-gradient(135deg,#0D6EFD,#8a2be2); border-radius:14px; display:flex; align-items:center; justify-content:center; font-weight:900; font-size:24px; color:#fff; box-shadow:0 0 25px rgba(13,110,253,0.9); }
  .card-box { background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.1); border-radius:16px; padding:20px; }
  .muted { color:rgba(255,255,255,0.7); }
</style>
</head>
<body>
<div class="bg"></div>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark fixed-top shadow-sm">
  <div class="container">
    <div class="d-flex align-items-center">
      <div class="logo-icon me-3">S</div>
      <a class="navbar-brand fw-bold fs-4" href="index.php" style="color:#fff;text-decoration:none;">squid pro</a>
    </div>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMenu">
      <ul class="navbar-nav ms-auto me-0">
        <li class="nav-item"><a class="nav-link" href="index.php">Home</a></li>
        <li class="nav-item"><a class="nav-link" href="Games.php">Games</a></li>
        <li class="nav-item"><a class="nav-link" href="Teams.php">Teams</a></li>
        <li class="nav-item"><a class="nav-link" href="Tournaments.php">Tournaments</a></li>
        <li class="nav-item"><a class="nav-link" href="AI-Coach.php">AI Coach</a></li>
      </ul>
      <div class="d-flex ms-3">
        <?php if (!empty($_SESSION['user_id'])): ?>
          <a href="Profile.php" class="btn btn-outline-light me-2">Profile</a>
          <a href="Logout.php" class="btn btn-outline-light">Logout</a>
        <?php else: ?>
          <a href="login.php" class="btn btn-primary">Login</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</nav>

<div class="container" style="max-width:960px; margin-top:120px;">
  <div class="card-box">
    <h2 class="fw-bold">Match Lobby</h2>
    <p class="muted">Game: <?php echo esc($match['game_name']); ?> • Match ID: <?php echo (int)$match_id; ?></p>
    <div class="row g-3 mt-2">
      <div class="col-md-6">
        <div class="card-box">
          <h5><?php echo esc($match['team1_name']); ?></h5>
          <?php foreach ($team1_roster as $p): ?>
            <div class="muted">• <?php echo esc($p['display_name'] ?: $p['username']); ?>
              <?php if (!empty($submissions[(int)$p['id']])): ?>
                <span class="badge bg-success ms-2">Submitted</span>
              <?php else: ?>
                <span class="badge bg-warning text-dark ms-2">Pending</span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="col-md-6">
        <div class="card-box">
          <h5><?php echo esc($match['team2_name']); ?></h5>
          <?php foreach ($team2_roster as $p): ?>
            <div class="muted">• <?php echo esc($p['display_name'] ?: $p['username']); ?>
              <?php if (!empty($submissions[(int)$p['id']])): ?>
                <span class="badge bg-success ms-2">Submitted</span>
              <?php else: ?>
                <span class="badge bg-warning text-dark ms-2">Pending</span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="mt-3">
      <?php if (!empty($scheduled_at)): ?>
        <div class="muted">Scheduled at: <?php echo esc($scheduled_at); ?></div>
      <?php endif; ?>
      <?php if ($match['status'] === 'finished'): ?>
        <div class="alert alert-success mt-3">Final Score: <?php echo esc($match['score_team1']); ?> - <?php echo esc($match['score_team2']); ?></div>
      <?php else: ?>
        <?php if (!$can_play): ?>
          <div class="alert alert-warning mt-3">Match has not started yet.</div>
        <?php endif; ?>
        <?php if (!$game_link): ?>
          <div class="alert alert-danger mt-3">This game is not supported yet.</div>
        <?php else: ?>
          <a class="btn btn-primary mt-3" href="<?php echo esc($game_link); ?>?match_id=<?php echo (int)$match_id; ?>" <?php echo $can_play ? '' : 'disabled'; ?>>Start Game</a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
