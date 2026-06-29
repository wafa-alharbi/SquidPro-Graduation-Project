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
$notif_id = (int)($_GET['notif_id'] ?? 0);

$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($mysqli->connect_errno) { die('Database connection failed.'); }
$mysqli->set_charset('utf8mb4');

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

/* ---------- Mark notification as read (auto) ---------- */
if ($notif_id > 0) {
    $stmt = $mysqli->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->bind_param('ii', $notif_id, $user_id);
    $stmt->execute();
    $stmt->close();
}

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

// Load player results
$results = [];
$stmt = $mysqli->prepare("
    SELECT r.user_id, r.team_id, r.score_for, r.score_against, r.result, r.submitted_at,
           u.display_name, u.username
    FROM match_player_results r
    JOIN users u ON u.id = r.user_id
    WHERE r.match_id = ?
");
$stmt->bind_param('i', $match_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $results[(int)$row['user_id']] = $row;
}
$stmt->close();

$mysqli->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Squid Pro | Match Results</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">
<style>
  body { margin:0; font-family:'Poppins',sans-serif; background:#0d1117; color:#fff; }
  .bg { position:fixed; inset:0; z-index:-1; background: radial-gradient(circle at 20% 20%, #1a237e, transparent 60%), radial-gradient(circle at 80% 80%, #8e24aa, transparent 60%), radial-gradient(circle at 50% 50%, #0d47a1, transparent 70%); filter: blur(90px); }
  .card-box { background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.1); border-radius:16px; padding:20px; }
  .muted { color:rgba(255,255,255,0.7); }
</style>
</head>
<body>
<div class="bg"></div>
<div class="container" style="max-width:960px; margin-top:60px;">
  <div class="card-box">
    <h2 class="fw-bold">Match Results</h2>
    <p class="muted">Game: <?php echo esc($match['game_name']); ?> • Match ID: <?php echo (int)$match_id; ?></p>

    <div class="alert alert-info">Final Score: <?php echo esc($match['score_team1'] ?? '-'); ?> - <?php echo esc($match['score_team2'] ?? '-'); ?></div>

    <div class="row g-3">
      <div class="col-md-6">
        <div class="card-box">
          <h5><?php echo esc($match['team1_name']); ?></h5>
          <?php foreach ($team1_roster as $p): ?>
            <?php $r = $results[(int)$p['id']] ?? null; ?>
            <div class="mt-2">
              <div><?php echo esc($p['display_name'] ?: $p['username']); ?></div>
              <?php if ($r): ?>
                <div class="muted">Result: <?php echo esc($r['result']); ?> • <?php echo (int)$r['score_for']; ?>-<?php echo (int)$r['score_against']; ?></div>
              <?php else: ?>
                <div class="muted">No submission yet.</div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="col-md-6">
        <div class="card-box">
          <h5><?php echo esc($match['team2_name']); ?></h5>
          <?php foreach ($team2_roster as $p): ?>
            <?php $r = $results[(int)$p['id']] ?? null; ?>
            <div class="mt-2">
              <div><?php echo esc($p['display_name'] ?: $p['username']); ?></div>
              <?php if ($r): ?>
                <div class="muted">Result: <?php echo esc($r['result']); ?> • <?php echo (int)$r['score_for']; ?>-<?php echo (int)$r['score_against']; ?></div>
              <?php else: ?>
                <div class="muted">No submission yet.</div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <a href="PlayMatch.php?match_id=<?php echo (int)$match_id; ?>" class="btn btn-outline-light mt-3">Back to Match</a>
  </div>
</div>
</body>
</html>
