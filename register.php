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

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}
$csrf = $_SESSION['csrf_token'];

$match_id = (int)($_GET['match_id'] ?? 0);
if ($match_id <= 0) { die('Invalid match.'); }

$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($mysqli->connect_errno) { die('Database connection failed.'); }
$mysqli->set_charset('utf8mb4');

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
    SELECT m.*, t1.name AS team1_name, t2.name AS team2_name
    FROM matches m
    JOIN teams t1 ON t1.id = m.team1_id
    JOIN teams t2 ON t2.id = m.team2_id
    WHERE m.id = ? AND m.game_id = 1
    LIMIT 1
");
$stmt->bind_param('i', $match_id);
$stmt->execute();
$match = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$match) { die('Match not found or wrong game.'); }

if ($match['status'] === 'finished') { die('Match already finished.'); }

// membership check
$allowed = false;
$my_team_id = null;
$stmt = $mysqli->prepare("SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ? LIMIT 1");
foreach ([(int)$match['team1_id'], (int)$match['team2_id']] as $tid) {
    $stmt->bind_param('ii', $tid, $user_id);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) { $allowed = true; $my_team_id = $tid; break; }
}
$stmt->close();
if (!$allowed) { die('Access denied.'); }

// Record check-in
$team1_id = (int)$match['team1_id'];
$team2_id = (int)$match['team2_id'];
$stmt = $mysqli->prepare("INSERT IGNORE INTO match_checkins (match_id, user_id, team_id) VALUES (?, ?, ?)");
$stmt->bind_param('iii', $match_id, $user_id, $my_team_id);
$stmt->execute();
$stmt->close();
// time gate
if (!empty($match['scheduled_at'])) {
    $now = new DateTime('now');
    $sched = new DateTime($match['scheduled_at']);
    if ($now < $sched) { die('Match has not started yet.'); }
}

// mark ongoing if not finished
if ($match['status'] !== 'finished' && $match['status'] !== 'ongoing') {
    $upd = $mysqli->prepare("UPDATE matches SET status = 'ongoing' WHERE id = ?");
    $upd->bind_param('i', $match_id);
    $upd->execute();
    $upd->close();
}

$mysqli->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>squid pro Hub | Rock Paper Scissors</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">

<style>
    body {
        font-family: 'Poppins', sans-serif;
        background: #0d1117;
        color: white;
        overflow-x: hidden;
        text-align: center;
    }
    .animated-bg { position: fixed; inset: 0; z-index: -1; background: radial-gradient(circle at 20% 20%, #1a237e, transparent 60%), radial-gradient(circle at 80% 80%, #8e24aa, transparent 60%), radial-gradient(circle at 50% 50%, #0d47a1, transparent 70%); filter: blur(80px); animation: move 12s infinite alternate ease-in-out; }
    @keyframes move { 0%{transform:scale(1);} 100%{transform:scale(1.3);} }
    .game-box { margin-top: 120px; background: rgba(255,255,255,0.05); border-radius: 20px; padding: 40px; width: 70%; margin-left: auto; margin-right: auto; border: 1px solid rgba(255,255,255,0.1); backdrop-filter: blur(8px); box-shadow: 0 0 25px rgba(13,110,253,0.4); animation: fadeIn 1.2s ease-out; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(40px); } to { opacity: 1; transform: translateY(0); } }
    .player-turn { font-size: 1.7rem; font-weight: 800; color: #0D6EFD; margin-bottom: 20px; text-shadow: 0 0 15px rgba(13,110,253,0.7); }
    .choices button { background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); padding: 20px 30px; border-radius: 14px; margin: 10px; font-size: 2.5rem; color: white; transition: 0.3s; box-shadow: 0 0 15px rgba(13,110,253,0.3); }
    .choices button:hover { background: #0D6EFD; transform: scale(1.15) rotate(5deg); box-shadow: 0 0 25px rgba(13,110,253,0.8); }
    .result-box { margin-top: 30px; font-size: 2rem; font-weight: 800; transition: 0.3s; }
    .score-box { margin-top: 20px; font-size: 1.3rem; }
    .progress { height: 12px; border-radius: 10px; background: rgba(255,255,255,0.1); margin-top: 20px; }
    .progress-bar { background: linear-gradient(135deg, #0D6EFD, #8a2be2); }
    .btn-reset { margin-top: 25px; padding: 12px 30px; border-radius: 50px; background: linear-gradient(135deg, #0D6EFD, #8a2be2); border: none; color: white; font-size: 1.1rem; transition: 0.3s; }
    .btn-reset:hover { transform: scale(1.05); box-shadow: 0 0 25px rgba(138,43,226,0.9); }
    .victory-screen { position: fixed; inset: 0; background: rgba(0,0,0,0.85); display: none; align-items: center; justify-content: center; flex-direction: column; z-index: 10; color: white; text-align: center; }
    .victory-screen h1 { font-size: 3rem; font-weight: 900; text-shadow: 0 0 25px #0D6EFD; }
</style>
</head>

<body>
<div class="animated-bg"></div>

<h1 class="fw-bold mt-5">Rock Paper Scissors Match</h1>
<p style="opacity:0.8;">Team 1: <?php echo esc($match['team1_name']); ?> • Team 2: <?php echo esc($match['team2_name']); ?></p>

<div class="game-box">

    <div id="turn" class="player-turn">Team 1 Turn</div>

    <div class="choices">
        <button onclick="choose('rock')">🪨</button>
        <button onclick="choose('paper')">📄</button>
        <button onclick="choose('scissors')">✂️</button>
    </div>

    <div class="result-box" id="resultText">Make your move!</div>

    <div class="score-box">
        <p><?php echo esc($match['team1_name']); ?> Score: <span id="p1Score">0</span></p>
        <p><?php echo esc($match['team2_name']); ?> Score: <span id="p2Score">0</span></p>
    </div>

    <div class="progress">
        <div id="progressBar" class="progress-bar" style="width: 0%;"></div>
    </div>

    <button class="btn-reset" onclick="resetGame()">Reset Game</button>

</div>

<!-- Victory Screen -->
<div class="victory-screen" id="victoryScreen">
    <h1 id="winnerText"></h1>
    <button class="btn-reset mt-4" onclick="goBack()">Back to Match</button>
</div>

<script>
    const matchId = <?php echo (int)$match_id; ?>;
    const csrfToken = "<?php echo esc($csrf); ?>";
    let submitted = false;

    let playerTurn = 1;
    let p1Choice = "";
    let p2Choice = "";
    let p1Score = 0;
    let p2Score = 0;

    function choose(choice) {
        playSound();

        if (playerTurn === 1) {
            p1Choice = choice;
            playerTurn = 2;
            document.getElementById("turn").innerText = "Team 2 Turn";
            document.getElementById("resultText").innerText = "Team 2, make your move!";
        } else {
            p2Choice = choice;
            playerTurn = 1;
            document.getElementById("turn").innerText = "Team 1 Turn";
            checkWinner();
        }
    }

    function checkWinner() {
        let result = "";

        if (p1Choice === p2Choice) {
            result = "It's a Tie!";
        } else if (
            (p1Choice === "rock" && p2Choice === "scissors") ||
            (p1Choice === "paper" && p2Choice === "rock") ||
            (p1Choice === "scissors" && p2Choice === "paper")
        ) {
            p1Score++;
            result = "Team 1 Wins!";
            winSound();
        } else {
            p2Score++;
            result = "Team 2 Wins!";
            loseSound();
        }

        document.getElementById("resultText").innerText = result;
        document.getElementById("p1Score").innerText = p1Score;
        document.getElementById("p2Score").innerText = p2Score;

        updateProgress();

        if (p1Score === 5 || p2Score === 5) {
            showVictoryScreen();
        }
    }

    function updateProgress() {
        let total = p1Score + p2Score;
        let progress = (total / 10) * 100;
        document.getElementById("progressBar").style.width = progress + "%";
    }

    function showVictoryScreen() {
        let winner = p1Score === 5 ? "Team 1 Wins the Match!" : "Team 2 Wins the Match!";
        document.getElementById("winnerText").innerText = winner;
        document.getElementById("victoryScreen").style.display = "flex";
        submitResult();
    }

    async function submitResult() {
        if (submitted) return;
        submitted = true;
        const form = new FormData();
        form.append('match_id', matchId);
        form.append('score_team1', p1Score);
        form.append('score_team2', p2Score);
        form.append('csrf_token', csrfToken);
        const res = await fetch('SubmitMatchResult.php', { method: 'POST', body: form });
        const data = await res.json();
        if (!data.success) {
            alert('Failed to save result');
        }
    }

    function resetGame() {
        p1Score = 0;
        p2Score = 0;
        p1Choice = "";
        p2Choice = "";
        document.getElementById("p1Score").innerText = 0;
        document.getElementById("p2Score").innerText = 0;
        document.getElementById("resultText").innerText = "Make your move!";
        document.getElementById("turn").innerText = "Team 1 Turn";
        document.getElementById("progressBar").style.width = "0%";
        document.getElementById("victoryScreen").style.display = "none";
        submitted = false;
    }

    function goBack() {
        window.location.href = 'PlayMatch.php?match_id=' + matchId;
    }

    function playSound() {
        const audio = new AudioContext();
        const oscillator = audio.createOscillator();
        oscillator.frequency.value = 300;
        oscillator.connect(audio.destination);
        oscillator.start();
        oscillator.stop(audio.currentTime + 0.1);
    }

    function winSound() {
        const audio = new AudioContext();
        const oscillator = audio.createOscillator();
        oscillator.frequency.value = 600;
        oscillator.connect(audio.destination);
        oscillator.start();
        oscillator.stop(audio.currentTime + 0.15);
    }

    function loseSound() {
        const audio = new AudioContext();
        const oscillator = audio.createOscillator();
        oscillator.frequency.value = 150;
        oscillator.connect(audio.destination);
        oscillator.start();
        oscillator.stop(audio.currentTime + 0.15);
    }
</script>

</body>
</html>
