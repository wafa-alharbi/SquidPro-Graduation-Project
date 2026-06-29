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
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
if (empty($_SESSION['role']) || $_SESSION['role'] !== 'player') { header('Location: login.php'); exit; }
$user_id = (int)$_SESSION['user_id'];

$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($mysqli->connect_errno) die('Database connection failed.');
$mysqli->set_charset('utf8mb4');

$rows = [];
$stmt = $mysqli->prepare("\
    SELECT r.*, m.team1_id, m.team2_id, t1.name AS team1_name, t2.name AS team2_name, g.name AS game_name
    FROM match_player_results r
    JOIN matches m ON m.id = r.match_id
    JOIN teams t1 ON t1.id = m.team1_id
    JOIN teams t2 ON t2.id = m.team2_id
    JOIN games g ON g.id = m.game_id
    WHERE r.user_id = ?
    ORDER BY r.submitted_at DESC
");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $rows[] = $row;
$stmt->close();
$mysqli->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Squid Pro | My Match History</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">
<style>
  body { margin:0; font-family:'Poppins',sans-serif; background:#0d1117; color:#fff; }
  .bg { position:fixed; inset:0; z-index:-1; background: radial-gradient(circle at 20% 20%, #1a237e, transparent 60%), radial-gradient(circle at 80% 80%, #8e24aa, transparent 60%), radial-gradient(circle at 50% 50%, #0d47a1, transparent 70%); filter: blur(90px); }
  .card-box { background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.1); border-radius:16px; padding:20px; margin-bottom:12px; }
  .muted { color:rgba(255,255,255,0.7); }
</style>
</head>
<body>
<div class="bg"></div>
<div class="container" style="max-width:960px; margin-top:60px;">
  <div class="card-box">
    <h2 class="fw-bold">My Match History</h2>
    <p class="muted">All your match submissions and results.</p>

    <?php if (empty($rows)): ?>
      <div class="card-box">No history yet.</div>
    <?php else: ?>
      <?php foreach ($rows as $r): ?>
        <div class="card-box">
          <div><strong><?php echo esc($r['team1_name']); ?></strong> vs <strong><?php echo esc($r['team2_name']); ?></strong></div>
          <div class="muted">Game: <?php echo esc($r['game_name']); ?> • Result: <?php echo esc($r['result']); ?></div>
          <div class="muted">Score: <?php echo (int)$r['score_for']; ?> - <?php echo (int)$r['score_against']; ?></div>
          <div class="muted">Submitted: <?php echo esc($r['submitted_at']); ?></div>
          <a class="btn btn-sm btn-outline-light mt-2" href="MatchResults.php?match_id=<?php echo (int)$r['match_id']; ?>">View Match</a>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <a href="PlayerDashboard.php" class="btn btn-outline-light mt-2">Back to Dashboard</a>
  </div>
</div>
</body>
</html>
