<?php


session_start();

/* ---------- Configuration ---------- */
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'SquidPro';

/* ---------- Helpers ---------- */
function esc($s) {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

/* ---------- Connect to DB ---------- */
$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo "Database connection failed.";
    exit;
}
$mysqli->set_charset('utf8mb4');

/* ---------- Load games (only active) ---------- */
$search = trim($_GET['q'] ?? '');
$games = [];

if ($search === '') {
    $stmt = $mysqli->prepare("SELECT id, name, icon, description FROM games WHERE is_active = 1 ORDER BY name ASC");
    $stmt->execute();
} else {
    $stmt = $mysqli->prepare("SELECT id, name, icon, description FROM games WHERE is_active = 1 AND (name LIKE CONCAT('%', ?, '%') OR description LIKE CONCAT('%', ?, '%')) ORDER BY name ASC");
    $stmt->bind_param('ss', $search, $search);
    $stmt->execute();
}
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $games[] = $row;
}
$stmt->close();
$mysqli->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Squid Pro | Games</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">

<style>
  :root { --accent1:#0D6EFD; --accent2:#8a2be2; }
  body { margin:0; font-family:'Poppins',sans-serif; background:#0d1117; color:#fff; }
  .animated-bg { position:fixed; inset:0; z-index:-1; background: radial-gradient(circle at 20% 20%, #1a237e, transparent 60%), radial-gradient(circle at 80% 80%, #8e24aa, transparent 60%), radial-gradient(circle at 50% 50%, #0d47a1, transparent 70%); filter: blur(80px); }
  .particles span { position:absolute; width:6px; height:6px; background:var(--accent1); border-radius:50%; opacity:0.7; animation:float 6s infinite ease-in-out; }
  @keyframes float { 0%{transform:translateY(0);opacity:0.4;}50%{transform:translateY(-40px);opacity:1;}100%{transform:translateY(0);opacity:0.4;} }
  .logo-icon { width:55px;height:55px;background:linear-gradient(135deg,var(--accent1),var(--accent2));border-radius:14px;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:24px;color:#fff;box-shadow:0 0 25px rgba(13,110,253,0.9); }
  .navbar { background-color: rgba(0,0,0,0.55) !important; backdrop-filter: blur(6px); }
  .page-title { margin-top:100px; text-align:center; font-size:2.6rem; font-weight:800; color:#fff; }
  .page-sub { text-align:center; opacity:0.8; margin-top:6px; }
  .game-card { background: rgba(255,255,255,0.04); border-radius:16px; padding:18px; text-align:center; border:1px solid rgba(255,255,255,0.06); transition:0.25s; display:flex; flex-direction:column; justify-content:space-between; height:100%; }
  .game-card:hover { transform:translateY(-8px); box-shadow:0 0 25px rgba(13,110,253,0.25); border-color:var(--accent1); }
  .game-icon img { max-width:160px; max-height:160px; border-radius:12px; object-fit:cover; }
  .game-icon-emoji { font-size:56px; display:inline-block; }
  .muted { color:rgba(255,255,255,0.75); }
  .search-bar { max-width:720px; margin:18px auto 0; display:flex; gap:8px; }
  .btn-primary { background:linear-gradient(135deg,var(--accent1),var(--accent2)); border:none; }
  @media (max-width:767px) {
    .page-title { font-size:1.8rem; margin-top:80px; }
  }
</style>
</head>
<body>

<div class="animated-bg"></div>

<!-- Floating particles -->
<div class="particles">
  <span style="top:20%; left:10%; animation-delay:0s;"></span>
  <span style="top:40%; left:80%; animation-delay:1s;"></span>
  <span style="top:70%; left:30%; animation-delay:2s;"></span>
  <span style="top:85%; left:60%; animation-delay:3s;"></span>
  <span style="top:10%; left:50%; animation-delay:1.5s;"></span>
</div>

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
        <li class="nav-item"><a class="nav-link active" href="Games.php">Games</a></li>
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

<!-- Page header -->
<div class="container">
  <h1 class="page-title">Available Games</h1>
  <p class="page-sub">Browse available games, join tournaments, and find opponents.</p>

  <!-- Search -->
  <form method="GET" action="Games.php" class="search-bar" role="search" aria-label="Search games">
    <input name="q" class="form-control form-control-dark" placeholder="Search games or description..." value="<?php echo esc($search); ?>">
    <button class="btn btn-primary" type="submit">Search</button>
  </form>
</div>

<!-- Games grid -->
<div class="container mt-4 mb-5">
  <div class="row g-4">
    <?php if (empty($games)): ?>
      <div class="col-12">
        <div class="game-card text-center">
          <h4 class="mb-2">No games available</h4>
          <p class="muted">More games will be added soon.</p>
        </div>
      </div>
    <?php else: foreach ($games as $g): ?>
      <div class="col-sm-6 col-md-4 col-lg-3">
        <div class="game-card">
          <div>
            <div class="game-icon mb-3">
              <?php
             $icon = trim($g['icon'] ?? '');

                if ($icon !== '' && preg_match('/\.(png|jpg|jpeg|webp|gif)$/i', $icon)) {
                    
                    echo '<img src="image/' . esc($icon) . '" alt="' . esc($g['name']) . '">';
                } else {

                    echo '<img src="image/default.jpg" alt="Game">';
                }

              ?>
            </div>

            <h4><?php echo esc($g['name']); ?></h4>
            <p class="muted"><?php echo esc($g['description']); ?></p>
          </div>

          <div style="margin-top:12px;">
            <?php if ((int)$g['id'] === 1): ?>
              <a href="TrainRPS.php" class="btn btn-outline-light w-100 mb-2">Training Mode</a>
            <?php elseif ((int)$g['id'] === 3): ?>
              <a href="TrainPadel.php" class="btn btn-outline-light w-100 mb-2">Training Mode</a>
            <?php elseif (!empty($g['game_url'])): ?>
              <a href="<?php echo esc($g['game_url']); ?>" target="_blank" class="btn btn-outline-light w-100 mb-2">Train</a>
            <?php endif; ?>

            <a href="Tournaments.php?game=<?php echo urlencode($g['name']); ?>" class="btn btn-primary w-100">View Tournaments</a>
          </div>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
