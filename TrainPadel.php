<?php
session_start();

/* Configuration */
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'SquidPro';


/* If role is not player, redirect to appropriate dashboard */
if (empty($_SESSION['role']) || $_SESSION['role'] !== 'player') {
    if (!empty($_SESSION['role'])) {
        if ($_SESSION['role'] === 'organizer') {
            header('Location: OrganizerDashboard.php');
            exit;
        } elseif ($_SESSION['role'] === 'admin') {
            header('Location: AdminDashboard.php');
            exit;
        }
    }
    header('Location: login.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];

/* ---------- CSRF token ---------- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}

/* ---------- Connect to DB ---------- */
$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo "Database connection failed.";
    exit;
}
$mysqli->set_charset('utf8mb4');

/* ---------- Load current user data ---------- */
$user = null;
if ($stmt = $mysqli->prepare("SELECT id, username, display_name, email, avatar_url FROM users WHERE id = ? LIMIT 1")) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->bind_result($u_id, $u_username, $u_display_name, $u_email, $u_avatar_url);
    if ($stmt->fetch()) {
        $user = [
            'id' => $u_id,
            'username' => $u_username,
            'display_name' => $u_display_name,
            'email' => $u_email,
            'avatar_url' => $u_avatar_url
        ];
    }
    $stmt->close();
}
/* Helpers */
function esc($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* Auth check */
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

/* Role redirect if not player (keeps sidebar for all roles) */
if (empty($_SESSION['role'])) {
    header('Location: login.php');
    exit;
}

/* Connect DB */
$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo "Database connection failed.";
    exit;
}
$mysqli->set_charset('utf8mb4');

/* Search / filter */
$search = trim($_GET['q'] ?? '');
$status_filter = $_GET['status'] ?? '';

/* Build query with safe parameters */
$sql = "
  SELECT t.id, t.name AS tournament_name, t.start_date, t.status,
         g.name AS game_name,
         u.display_name AS organizer_name
  FROM tournaments t
  LEFT JOIN games g ON g.id = t.game_id
  LEFT JOIN users u ON u.id = t.organizer_id
  WHERE 1=1
";
$params = [];
$types = '';

if ($search !== '') {
    $sql .= " AND (t.name LIKE CONCAT('%', ?, '%') OR g.name LIKE CONCAT('%', ?, '%')) ";
    $params[] = $search; $params[] = $search;
    $types .= 'ss';
}
if ($status_filter !== '') {
    $sql .= " AND t.status = ? ";
    $params[] = $status_filter;
    $types .= 's';
}

$sql .= " ORDER BY t.start_date ASC LIMIT 200";

/* Prepare and execute */
$stmt = $mysqli->prepare($sql);
if ($stmt === false) {
    echo "Query prepare failed.";
    exit;
}
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$tournaments = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$mysqli->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Squid Pro | Tournaments</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">
<style>
  body { font-family:'Poppins',sans-serif; background:#0d1117; color:#fff; margin:0; }
  .bg-waves { position:fixed; inset:0; z-index:-2; background: radial-gradient(circle at 20% 20%, #1a237e, transparent 60%), radial-gradient(circle at 80% 80%, #8e24aa, transparent 60%), radial-gradient(circle at 50% 50%, #0d47a1, transparent 70%); filter: blur(80px); }
  .sidebar { width:260px; height:100vh; background:rgba(0,0,0,0.55); backdrop-filter:blur(10px); position:fixed; left:0; top:0; padding:25px 20px; border-right:1px solid rgba(255,255,255,0.1); }
  .sidebar h2 { font-weight:800; font-size:1.9rem; background:linear-gradient(90deg,#0D6EFD,#8a2be2); -webkit-background-clip:text; color:transparent; text-align:center; margin-bottom:24px; }
  .sidebar a { display:block; padding:12px 15px; margin-bottom:12px; border-radius:10px; color:white; text-decoration:none; font-size:1.05rem; transition:0.3s; }
  .sidebar a:hover, .sidebar a.active { background:linear-gradient(135deg,#0D6EFD,#8a2be2); box-shadow:0 0 15px rgba(13,110,253,0.6); }
  .main { margin-left:270px; padding:32px; }
  .card { background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.06); padding:16px; border-radius:12px; }
  a.nav-link { color:#fff; display:block; padding:10px 0; text-decoration:none; }
  a.nav-link.active, a.nav-link:hover { background:linear-gradient(135deg,#0D6EFD,#8a2be2); color:#fff; border-radius:8px; padding-left:12px; }
</style>
</head>
<body>
  <div class="bg-waves"></div>

   <div class="sidebar">
    <h3 style="font-weight:800; background:linear-gradient(90deg,#0D6EFD,#8a2be2); -webkit-background-clip:text; color:transparent;">squid pro</h3>

    <div style="margin-top:18px;">
      <div style="display:flex;gap:12px;align-items:center;">
        <img src="<?php echo esc($user['avatar_url'] ?: 'https://via.placeholder.com/72x72?text=Avatar'); ?>" alt="avatar" style="width:72px;height:72px;border-radius:12px;object-fit:cover;">
        <div>
          <div style="font-weight:700;"><?php echo esc($user['display_name'] ?: $user['username']); ?></div>
          <div style="opacity:0.8;font-size:0.9rem;"><?php echo esc($user['username']); ?></div>
        </div>
      </div>
    </div>

    <nav style="margin-top:20px;">
       <a href="PlayerDashboard.php" class="nav-link active">🏠 Dashboard</a>
  <a href="Profile.php" class="nav-link">👤 Profile</a>
  <a href="Tournaments.php" class="nav-link">🏆 Tournaments</a>
  <a href="Locations.php" class="nav-link">📍 Locations</a>
  <a href="Chat.php" class="nav-link">💬 Chat</a>
  <a href="ReportIssue.php" class="nav-link">📝 Report / Objection</a>
  <a href="AI-Coach.php" class="nav-link">🤖 AI Coach</a>
  <a href="index.php" class="nav-link">🏠 Home</a>
  <a href="Logout.php" class="nav-link">🚪 Logout</a>
    </nav>
  </div>

  <div class="main">
    <h1 style="font-weight:800;">Tournaments</h1>
    <p style="opacity:0.8;">Browse upcoming and past tournaments.</p>

    <div class="card mb-3">
      <form class="row g-2" method="GET" action="Tournaments.php">
        <div class="col-md-6">
          <input type="search" name="q" class="form-control" placeholder="Search tournaments or games" value="<?php echo esc($search); ?>">
        </div>
        <div class="col-md-3">
          <select name="status" class="form-select">
            <option value="">All statuses</option>
            <option value="pending" <?php if($status_filter==='pending') echo 'selected'; ?>>Pending</option>
            <option value="validated" <?php if($status_filter==='validated') echo 'selected'; ?>>Validated</option>
            <option value="ongoing" <?php if($status_filter==='ongoing') echo 'selected'; ?>>Ongoing</option>
            <option value="finished" <?php if($status_filter==='finished') echo 'selected'; ?>>Finished</option>
          </select>
        </div>
        <div class="col-md-3">
          <button class="btn btn-primary w-100" type="submit">Filter</button>
        </div>
      </form>
    </div>

    <?php if (empty($tournaments)): ?>
      <div class="card text-white">No tournaments found.</div>
    <?php else: ?>
      <div class="row g-3">
        <?php foreach ($tournaments as $t): ?>
          <div class="col-md-6">
            <div class="card">
              <div style="display:flex;justify-content:space-between;align-items:center;">
                <div>
                  <h5 style="margin:0;color:#ccc;"><?php echo esc($t['tournament_name']); ?></h5>
                  <div style="opacity:0.8;color:#ccc;"><?php echo esc($t['game_name'] ?: 'Unknown Game'); ?></div>
                  <div style="margin-top:6px;font-size:0.9rem;color:#ccc;">Organizer: <?php echo esc($t['organizer_name'] ?: '—'); ?></div>
                </div>
                <div style="text-align:right;color:#ccc;">
                  <div style="font-weight:700;color:#ccc;"><?php echo esc(date('M j, Y', strtotime($t['start_date'] ?? 'now'))); ?></div>
                  <div style="opacity:0.85; color:#ccc;"><?php echo esc(ucfirst($t['status'])); ?></div>
                </div>
              </div>
              <div style="margin-top:10px;text-align:right;">
                <a href="TournamentView.php?id=<?php echo (int)$t['id']; ?>" class="btn btn-sm btn-outline-light">View</a>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </div>
</body>
</html>
