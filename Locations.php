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
$team_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($team_id <= 0) die("Invalid team.");

/* FETCH TEAM */
$stmt = $db->prepare("SELECT * FROM teams WHERE id = ?");
$stmt->bind_param("i", $team_id);
$stmt->execute();
$team = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$team) die("Team not found.");

/* CHECK IF USER IS ALREADY MEMBER */
$stmt = $db->prepare("SELECT id FROM team_members WHERE team_id=? AND user_id=?");
$stmt->bind_param("ii", $team_id, $user_id);
$stmt->execute();
$is_member = $stmt->get_result()->num_rows > 0;
$stmt->close();

/* CHECK IF USER HAS PENDING REQUEST */
$stmt = $db->prepare("SELECT status FROM team_join_requests WHERE team_id=? AND user_id=?");
$stmt->bind_param("ii", $team_id, $user_id);
$stmt->execute();
$req = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* HANDLE JOIN REQUEST */
$success = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_member && !$req) {
    $main_game = $_POST['main_game'];
    $message   = $_POST['message'];

    $stmt = $db->prepare("
        INSERT INTO team_join_requests (team_id, user_id, main_game, message, status)
        VALUES (?, ?, ?, ?, 'pending')
    ");
    $stmt->bind_param("iiss", $team_id, $user_id, $main_game, $message);
    $stmt->execute();
    $stmt->close();

    $success = "Your join request has been submitted.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>squid pro | Join Team</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">

<style>
/* نفس الهوية البصرية */
body{font-family:'Poppins',sans-serif;background:#0d1117;color:white;}
.animated-bg{position:fixed;inset:0;z-index:-1;background:radial-gradient(circle at 20% 20%,#1a237e,transparent 60%),radial-gradient(circle at 80% 80%,#8e24aa,transparent 60%),radial-gradient(circle at 50% 50%,#0d47a1,transparent 70%);filter:blur(80px);animation:moveWaves 12s infinite alternate ease-in-out;}
@keyframes moveWaves{0%{transform:scale(1);}100%{transform:scale(1.3);}}
.join-card{background:rgba(255,255,255,0.05);border-radius:18px;padding:30px;border:1px solid rgba(255,255,255,0.08);backdrop-filter:blur(6px);margin-top:150px;}
.btn-main{background:linear-gradient(135deg,#0D6EFD,#8a2be2);border:none;padding:12px 30px;border-radius:50px;color:white;font-size:1.1rem;transition:0.3s;}
.btn-main:hover{transform:scale(1.05);box-shadow:0 0 25px rgba(138,43,226,0.9);}
</style>
</head>

<body>

<div class="animated-bg"></div>

<div class="container">
    <div class="col-md-6 mx-auto join-card">

        <h2 class="fw-bold text-center mb-3">Join <?= esc($team['name']) ?></h2>
        <p class="text-center" style="opacity:0.8;">Submit your request to join the team.</p>

        <?php if ($is_member): ?>
            <div class="alert alert-info">You are already a member of this team.</div>

        <?php elseif ($req): ?>
            <div class="alert alert-warning">Your request is already <?= esc($req['status']) ?>.</div>

        <?php elseif ($success): ?>
            <div class="alert alert-success"><?= esc($success) ?></div>

        <?php else: ?>
        <form method="POST" class="mt-4">

            <label>Main Game</label>
            <select name="main_game" class="form-control mb-3">
                <option>FIFA</option>
                <option>Call of Duty</option>
                <option>Padel</option>
                <option>Baloot</option>
            </select>

            <label>Why do you want to join?</label>
            <textarea name="message" class="form-control mb-3" rows="4" placeholder="Tell the team about your skills..."></textarea>

            <button class="btn-main w-100">Submit Request</button>

        </form>
        <?php endif; ?>

    </div>
</div>

</body>
</html>
