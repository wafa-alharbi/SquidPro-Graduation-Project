<?php
session_start();

/* ---------- CHECK ORGANIZER ROLE ---------- */
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'organizer') {
    die("Access denied. Only organizers can create tournaments.");
}

/* ---------- DB CONFIG ---------- */
$db = new mysqli("localhost", "root", "", "SquidPro");
$db->set_charset("utf8mb4");

function esc($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* ---------- LOAD GAMES ---------- */
$games = [];
$res = $db->query("SELECT id, name FROM games WHERE is_active = 1 ORDER BY name ASC");
while ($row = $res->fetch_assoc()) {
    $games[] = $row;
}

/* ---------- HANDLE FORM SUBMISSION ---------- */
$success = false;
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name = trim($_POST['name'] ?? '');
    $game_id = (int)($_POST['game_id'] ?? 0);
    $format = trim($_POST['format'] ?? '');
    $start_date = trim($_POST['start_date'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $organizer_id = $_SESSION['user_id'];

    if ($name === "" || $game_id <= 0 || $start_date === "") {
        $error = "Please fill all required fields.";
    } else {
        $stmt = $db->prepare("
            INSERT INTO tournaments (name, game_id, organizer_id, format, start_date, description, status)
            VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->bind_param("siisss", $name, $game_id, $organizer_id, $format, $start_date, $description);

        if ($stmt->execute()) {
            $success = true;
        } else {
            $error = "Database error: " . $stmt->error;
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Squid Pro | Register Tournament</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">

<style>
body { background:#0d1117; color:white; font-family:'Poppins',sans-serif; }
.animated-bg { position:fixed; inset:0; z-index:-1;
    background:radial-gradient(circle at 20% 20%,#1a237e,transparent 60%),
               radial-gradient(circle at 80% 80%,#8e24aa,transparent 60%),
               radial-gradient(circle at 50% 50%,#0d47a1,transparent 70%);
    filter:blur(80px);
}
.navbar { background:rgba(0,0,0,0.55) !important; backdrop-filter:blur(6px); }
.form-card { background:rgba(255,255,255,0.05); padding:25px; border-radius:16px;
             border:1px solid rgba(255,255,255,0.08); margin-top:120px; }
.btn-main { background:linear-gradient(135deg,#0D6EFD,#8a2be2); border:none; color:white; }
</style>
</head>

<body>

<div class="animated-bg"></div>

<nav class="navbar navbar-expand-lg navbar-dark fixed-top shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-bold fs-3" href="index.php">squid pro</a>

        <button class="navbar-toggler" data-bs-toggle="collapse" data-bs-target="#navMenu">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item"><a class="nav-link" href="Tournaments.php">Tournaments</a></li>
                <li class="nav-item"><a class="nav-link" href="Games.php">Games</a></li>
            </ul>

            <div class="ms-3">
                <a href="Profile.php" class="btn btn-outline-light me-2">Profile</a>
                <a href="Logout.php" class="btn btn-outline-light">Logout</a>
            </div>
        </div>
    </div>
</nav>

<div class="container">
    <div class="form-card">

        <h2 class="fw-bold mb-3">Create New Tournament</h2>
        <p class="opacity-75">Fill the form below to register a new tournament.</p>

        <?php if ($success): ?>
            <div class="alert alert-success">Tournament created successfully!</div>
        <?php elseif ($error): ?>
            <div class="alert alert-danger"><?php echo esc($error); ?></div>
        <?php endif; ?>

        <form method="POST">

            <label class="mt-3">Tournament Name *</label>
            <input type="text" name="name" class="form-control" required>

            <label class="mt-3">Game *</label>
            <select name="game_id" class="form-control" required>
                <option value="">Select game...</option>
                <?php foreach ($games as $g): ?>
                    <option value="<?php echo $g['id']; ?>"><?php echo esc($g['name']); ?></option>
                <?php endforeach; ?>
            </select>

            <label class="mt-3">Format</label>
            <input type="text" name="format" class="form-control" placeholder="e.g., 1v1, 2v2">

            <label class="mt-3">Start Date *</label>
            <input type="datetime-local" name="start_date" class="form-control" required>

            <label class="mt-3">Description</label>
            <textarea name="description" class="form-control" rows="4"></textarea>

            <button class="btn-main w-100 mt-4 py-2">Create Tournament</button>

        </form>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
