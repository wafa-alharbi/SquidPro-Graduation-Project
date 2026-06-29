<?php
session_start();

/* DB CONFIG */
$db = new mysqli("localhost", "root", "", "squidpro");
$db->set_charset("utf8mb4");

function esc($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/* USER MUST BE LOGGED IN */
if (!isset($_SESSION['user_id'])) {
    header("Location: Login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

/* GET TEAM ID */
$team_id = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;
if ($team_id <= 0) die("Invalid team.");

/* CHECK USER ROLE */
$stmt = $db->prepare("SELECT role FROM team_members WHERE team_id=? AND user_id=?");
$stmt->bind_param("ii", $team_id, $user_id);
$stmt->execute();
$role_row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$user_role = $role_row['role'] ?? null;
$is_owner   = false;
$is_captain = ($user_role == 'co-captain');
$is_member  = ($user_role == 'member');

/* CHECK IF OWNER */
$q = $db->prepare("SELECT owner_id FROM teams WHERE id=?");
$q->bind_param("i", $team_id);
$q->execute();
$team_data = $q->get_result()->fetch_assoc();
$q->close();

if ($team_data['owner_id'] == $user_id) $is_owner = true;

/* FETCH MATCHES */
$matches = [];
$stmt = $db->prepare("
    SELECT m.*, 
           t1.name AS team1_name,
           t2.name AS team2_name
    FROM matches m
    JOIN teams t1 ON t1.id = m.team1_id
    JOIN teams t2 ON t2.id = m.team2_id
    WHERE m.team1_id = ? OR m.team2_id = ?
    ORDER BY m.match_date DESC
");
$stmt->bind_param("ii", $team_id, $team_id);
$stmt->execute();
$res = $stmt->get_result();
while($row = $res->fetch_assoc()) $matches[] = $row;
$stmt->close();

/* HANDLE ADD MATCH */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($is_owner || $is_captain)) {
    if ($_POST['action'] === 'add') {
        $opponent_id = (int)$_POST['opponent_id'];
        $match_date  = $_POST['match_date'];

        $stmt = $db->prepare("
            INSERT INTO matches (team1_id, team2_id, status, match_date)
            VALUES (?, ?, 'upcoming', ?)
        ");
        $stmt->bind_param("iis", $team_id, $opponent_id, $match_date);
        $stmt->execute();
        $stmt->close();

        header("Location: TeamMatches.php?team_id=$team_id");
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>squid pro Hub | Team Matches</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">

<style>
/* نفس الهوية البصرية */
body{font-family:'Poppins',sans-serif;background:#0d1117;color:white;}
.animated-bg{position:fixed;inset:0;z-index:-1;background:radial-gradient(circle at 20% 20%,#1a237e,transparent 60%),radial-gradient(circle at 80% 80%,#8e24aa,transparent 60%),radial-gradient(circle at 50% 50%,#0d47a1,transparent 70%);filter:blur(80px);animation:moveWaves 12s infinite alternate ease-in-out;}
@keyframes moveWaves{0%{transform:scale(1);}100%{transform:scale(1.3);}}
.match-card{background:rgba(255,255,255,0.05);border-radius:18px;padding:25px;border:1px solid rgba(255,255,255,0.08);}
.status-upcoming{color:#0D6EFD;font-weight:700;}
.status-won{color:#00ff88;font-weight:700;}
.status-lost{color:#ff4444;font-weight:700;}
.btn-main{background:linear-gradient(135deg,#0D6EFD,#8a2be2);border:none;padding:10px 25px;border-radius:12px;color:white;}
</style>
</head>

<body>

<div class="animated-bg"></div>

<div class="container" style="margin-top:140px;">

    <h1 class="fw-bold">Team Matches</h1>
    <p style="opacity:0.8;">Track your team's past and upcoming matches.</p>

    <?php if ($is_owner || $is_captain): ?>
    <!-- Add Match -->
    <div class="match-card mb-4">
        <h4>Add New Match</h4>
        <form method="POST">
            <input type="hidden" name="action" value="add">

            <label class="mt-2">Opponent Team</label>
            <select name="opponent_id" class="form-control mb-3" required>
                <?php
                $teams = $db->query("SELECT id, name FROM teams WHERE id != $team_id");
                while($t = $teams->fetch_assoc()):
                ?>
                    <option value="<?= $t['id'] ?>"><?= esc($t['name']) ?></option>
                <?php endwhile; ?>
            </select>

            <label>Match Date</label>
            <input type="datetime-local" name="match_date" class="form-control mb-3" required>

            <button class="btn-main">Add Match</button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Upcoming Matches -->
    <h3 class="mt-4">Upcoming Matches</h3>
    <div class="row g-4 mt-1">
        <?php foreach($matches as $m): ?>
            <?php if ($m['status'] == 'upcoming'): ?>
                <div class="col-md-4">
                    <div class="match-card">
                        <h5><?= esc($m['team1_name']) ?> vs <?= esc($m['team2_name']) ?></h5>
                        <p>Date: <?= esc($m['match_date']) ?></p>
                        <p class="status-upcoming">Upcoming</p>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <!-- Past Matches -->
    <h3 class="mt-5">Past Matches</h3>
    <div class="row g-4 mt-1">
        <?php foreach($matches as $m): ?>
            <?php if ($m['status'] == 'finished'): ?>
                <div class="col-md-4">
                    <div class="match-card">
                        <h5><?= esc($m['team1_name']) ?> vs <?= esc($m['team2_name']) ?></h5>
                        <p>Score: <?= esc($m['score_team1']) ?> - <?= esc($m['score_team2']) ?></p>

                        <?php
                        $won = false;
                        if ($m['team1_id'] == $team_id && $m['score_team1'] > $m['score_team2']) $won = true;
                        if ($m['team2_id'] == $team_id && $m['score_team2'] > $m['score_team1']) $won = true;
                        ?>

                        <p class="<?= $won ? 'status-won' : 'status-lost' ?>">
                            <?= $won ? 'Won' : 'Lost' ?>
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

</div>

</body>
</html>
