<?php
session_start();

/* DB CONFIG */
$db = new mysqli("localhost", "root", "", "squidpro");
$db->set_charset("utf8mb4");

function esc($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/* FETCH GAMES FOR FILTER */
$games = [];
$q = $db->query("SELECT id, name FROM games WHERE is_active = 1 ORDER BY name ASC");
while($row = $q->fetch_assoc()) $games[] = $row;

/* SELECTED GAME FILTER */
$game_filter = isset($_GET['game']) ? (int)$_GET['game'] : 0;

/* FETCH LEADERBOARD */
$sql = "
    SELECT us.*, u.display_name, g.name AS game_name
    FROM user_stats us
    LEFT JOIN users u ON u.id = us.user_id
    LEFT JOIN games g ON g.id = us.game_id
";

if ($game_filter > 0) {
    $sql .= " WHERE us.game_id = $game_filter ";
}

$sql .= " ORDER BY us.total_points DESC LIMIT 100";

$leaders = $db->query($sql)->fetch_all(MYSQLI_ASSOC);

/* RANK FUNCTION */
function getRank($points){
    if ($points >= 3000) return "Legend";
    if ($points >= 2500) return "Master";
    if ($points >= 2000) return "Pro";
    if ($points >= 1500) return "Diamond";
    return "Platinum";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>squid pro Hub | Leaderboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">

<style>
/* نفس الهوية البصرية */
body{margin:0;font-family:'Poppins',sans-serif;background:#0d1117;color:white;}
.bg-waves{position:fixed;inset:0;z-index:-3;background:radial-gradient(circle at 20% 20%,#1a237e,transparent 60%),radial-gradient(circle at 80% 80%,#8e24aa,transparent 60%),radial-gradient(circle at 50% 50%,#0d47a1,transparent 70%);filter:blur(90px);animation:move 12s infinite alternate ease-in-out;}
@keyframes move{0%{transform:scale(1);}100%{transform:scale(1.25);}}
.sidebar{width:260px;height:100vh;background:rgba(0,0,0,0.55);backdrop-filter:blur(10px);position:fixed;left:0;top:0;padding:25px 20px;border-right:1px solid rgba(255,255,255,0.1);}
.sidebar h2{font-weight:800;font-size:1.9rem;background:linear-gradient(90deg,#0D6EFD,#8a2be2);-webkit-background-clip:text;color:transparent;text-align:center;margin-bottom:40px;}
.sidebar a{display:block;padding:12px 15px;margin-bottom:12px;border-radius:10px;color:white;text-decoration:none;font-size:1.05rem;transition:0.3s;}
.sidebar a:hover,.sidebar a.active{background:linear-gradient(135deg,#0D6EFD,#8a2be2);box-shadow:0 0 15px rgba(13,110,253,0.6);}
.main{margin-left:280px;padding:40px;}
.leader-card{background:rgba(255,255,255,0.06);border-radius:18px;padding:20px;border:1px solid rgba(255,255,255,0.1);}
.rank-1{color:#ffd700;font-weight:700;}
.rank-2{color:#c0c0c0;font-weight:700;}
.rank-3{color:#cd7f32;font-weight:700;}
</style>
</head>
<body>

<div class="bg-waves"></div>

<div class="sidebar">
    <h2>squid pro</h2>
    <a href="PlayerDashboard.php">🏠 Dashboard</a>
    <a href="Profile.php">👤 Profile</a>
    <a href="Tournaments.php">🏆 Tournaments</a>
    <a href="Locations.php">📍 Locations</a>
    <a href="Chat.php">💬 Chat</a>
    <a href="AI-Coach.php">🤖 AI Coach</a>
    <a href="Settings.php">⚙️ Settings</a>
    <a href="Logout.php">🚪 Logout</a>
</div>

<div class="main">
    <h1 class="fw-bold">Leaderboard</h1>
    <p style="opacity:0.8;">Top players across all games.</p>

    <form method="GET" class="filter-bar d-flex gap-3 mb-3">
        <select name="game" class="form-select" style="max-width:200px;">
            <option value="0">All Games</option>
            <?php foreach($games as $g): ?>
                <option value="<?= $g['id'] ?>" <?= $game_filter==$g['id']?'selected':'' ?>>
                    <?= esc($g['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-primary">Filter</button>
    </form>

    <div class="leader-card">
        <table class="table table-dark table-striped">
            <thead>
                <tr>
                    <th>#</th><th>Player</th><th>Game</th><th>Rank</th><th>Points</th>
                </tr>
            </thead>
            <tbody>
                <?php $rank=1; foreach($leaders as $l): ?>
                    <tr class="rank-<?= $rank ?>">
                        <td><?= $rank ?></td>
                        <td><?= esc($l['display_name']) ?></td>
                        <td><?= esc($l['game_name']) ?></td>
                        <td><?= getRank($l['total_points']) ?></td>
                        <td><?= $l['total_points'] ?></td>
                    </tr>
                <?php $rank++; endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>
