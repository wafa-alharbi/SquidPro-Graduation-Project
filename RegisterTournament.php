<?php
session_start();



/* ---------- Configuration ---------- */
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'SquidPro';

/* Avatar upload settings */
$upload_dir = __DIR__ . '/uploads/avatars/';
$max_file_size = 2 * 1024 * 1024; // 2 MB
$allowed_types = ['image/jpeg', 'image/png', 'image/webp'];

/* ---------- Helpers ---------- */
function esc($s) {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function flash_html($msg, $type = 'info') {
    $bg = $type === 'success' ? '#163a0f' : '#2b1b1b';
    return '<div style="padding:10px;border-radius:6px;margin-bottom:12px;background:' . $bg . ';color:#fff;">' . esc($msg) . '</div>';
}

/* ---------- Auth / Role check ---------- */
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

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

/* ---------- Initialize state ---------- */
$errors = [];
$success = '';

/* ---------- Handle POST (profile update) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $errors[] = 'Invalid request (CSRF token mismatch).';
    }

    $display_name = trim($_POST['display_name'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($display_name === '') {
        $errors[] = 'Display name is required.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email is required.';
    }

    /* If avatar uploaded, validate and move */
    $avatar_path_db = null;
    if (!empty($_FILES['avatar']['name'])) {
        $file = $_FILES['avatar'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Avatar upload error.';
        } elseif ($file['size'] > $max_file_size) {
            $errors[] = 'Avatar file is too large (max 2MB).';
        } else {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!in_array($mime, $allowed_types, true)) {
                $errors[] = 'Avatar must be JPEG, PNG, or WEBP.';
            } else {
                /* ensure upload dir exists */
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                $ext = '';
                if ($mime === 'image/jpeg') $ext = '.jpg';
                if ($mime === 'image/png') $ext = '.png';
                if ($mime === 'image/webp') $ext = '.webp';
                $filename = 'avatar_' . $user_id . '_' . bin2hex(random_bytes(6)) . $ext;
                $dest = $upload_dir . $filename;
                if (!move_uploaded_file($file['tmp_name'], $dest)) {
                    $errors[] = 'Failed to save avatar file.';
                } else {
                    /* store relative path for DB (web-accessible) */
                    $avatar_path_db = 'uploads/avatars/' . $filename;
                }
            }
        }
    }

    /* If no errors, update DB */
    if (empty($errors)) {
        /* Check email uniqueness (exclude current user) */
        $chk = $mysqli->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
        $chk->bind_param('si', $email, $user_id);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $errors[] = 'Email is already used by another account.';
            $chk->close();
        } else {
            $chk->close();

            if ($avatar_path_db !== null) {
                $upd = $mysqli->prepare("UPDATE users SET display_name = ?, email = ?, avatar_url = ?, updated_at = NOW() WHERE id = ?");
                $upd->bind_param('sssi', $display_name, $email, $avatar_path_db, $user_id);
            } else {
                $upd = $mysqli->prepare("UPDATE users SET display_name = ?, email = ?, updated_at = NOW() WHERE id = ?");
                $upd->bind_param('ssi', $display_name, $email, $user_id);
            }

            if ($upd->execute()) {
                $success = 'Profile updated successfully.';
                /* refresh session display_name if needed */
                $_SESSION['display_name'] = $display_name;
                $upd->close();
            } else {
                $errors[] = 'Failed to update profile.';
                $upd->close();
            }
        }
    }
}

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

/* ---------- Load user stats ---------- */
$stats = ['total_points' => 0, 'wins' => 0, 'losses' => 0, 'streak' => 0];
if ($stmt = $mysqli->prepare("SELECT total_points, wins, losses, streak FROM user_stats WHERE user_id = ? LIMIT 1")) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->bind_result($s_points, $s_wins, $s_losses, $s_streak);
    if ($stmt->fetch()) {
        $stats['total_points'] = (int)$s_points;
        $stats['wins'] = (int)$s_wins;
        $stats['losses'] = (int)$s_losses;
        $stats['streak'] = (int)$s_streak;
    }
    $stmt->close();
}

/* ---------- Close DB ---------- */
$mysqli->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Squid Pro | Profile</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">
<style>
  body { font-family:'Poppins',sans-serif; background:#0d1117; color:#fff; margin:0; }
  .bg-waves { position:fixed; inset:0; z-index:-2; background: radial-gradient(circle at 20% 20%, #1a237e, transparent 60%), radial-gradient(circle at 80% 80%, #8e24aa, transparent 60%), radial-gradient(circle at 50% 50%, #0d47a1, transparent 70%); filter: blur(80px); }
  .pulse-grid { position:fixed; inset:0; z-index:-1; background: repeating-linear-gradient(to bottom, rgba(255,255,255,0.03) 0px, rgba(255,255,255,0.03) 1px, transparent 2px, transparent 4px); }
  .navbar { background-color: rgba(0,0,0,0.55) !important; backdrop-filter: blur(6px); }
  .sidebar { width:260px; height:100vh; background:rgba(0,0,0,0.55); backdrop-filter:blur(10px); position:fixed; left:0; top:0; padding:25px 20px; border-right:1px solid rgba(255,255,255,0.1); }
  .sidebar h2 { font-weight:800; font-size:1.9rem; background:linear-gradient(90deg,#0D6EFD,#8a2be2); -webkit-background-clip:text; color:transparent; text-align:center; margin-bottom:24px; }
  .sidebar a { display:block; padding:12px 15px; margin-bottom:12px; border-radius:10px; color:white; text-decoration:none; font-size:1.05rem; transition:0.3s; }
  .sidebar a:hover, .sidebar a.active { background:linear-gradient(135deg,#0D6EFD,#8a2be2); box-shadow:0 0 15px rgba(13,110,253,0.6); }
  .main { margin-left:270px; padding:32px; }
  .card { background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.06); padding:20px; border-radius:12px; }
  .avatar-large { width:140px; height:140px; border-radius:50%; object-fit:cover; box-shadow:0 0 25px rgba(13,110,253,0.8); }
  label { font-weight:600; color:#ddd; }
  .btn-primary { background:linear-gradient(135deg,#0D6EFD,#8a2be2); border:none; }
  a.nav-link { color:#fff; display:block; padding:10px 0; text-decoration:none; }
  a.nav-link.active, a.nav-link:hover { background:linear-gradient(135deg,#0D6EFD,#8a2be2); color:#fff; border-radius:8px; padding-left:12px; }
</style>
</head>
<body>
  <div class="bg-waves"></div>
  <div class="pulse-grid"></div>

  <!-- Sidebar (kept with navigation links) -->
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

  <!-- Main Content -->
  <div class="main">
    <h1 style="font-weight:800;">Player Profile</h1>
    <p style="opacity:0.8;">Manage your profile information and avatar.</p>

    <?php
      if (!empty($success)) echo flash_html($success, 'success');
      if (!empty($errors)) {
          foreach ($errors as $err) {
              echo flash_html($err, 'error');
          }
      }
    ?>

    <div class="card d-flex gap-3">
      <div style="display:flex;gap:20px;align-items:center;">
        <div>
          <img src="<?php echo esc($user['avatar_url'] ?: 'https://via.placeholder.com/140x140?text=Avatar'); ?>" alt="avatar" class="avatar-large">
        </div>
        <div>
          <h3 style="margin:0;color:white" ><?php echo esc($user['display_name'] ?: $user['username']); ?></h3>
          <div style="opacity:0.8;color:white"><?php echo esc($user['username']); ?></div>
          <div style="margin-top:8px; color:white">Points: <strong><?php echo esc($stats['total_points']); ?></strong></div>
        </div>
      </div>

      <hr style="border-color: rgba(255,255,255,0.06);">

      <form action="" method="POST" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo esc($_SESSION['csrf_token']); ?>">

        <div class="mb-3">
          <label for="display_name">Display Name</label>
          <input id="display_name" name="display_name" class="form-control" value="<?php echo esc($user['display_name']); ?>" required>
        </div>

        <div class="mb-3">
          <label for="email">Email</label>
          <input id="email" name="email" type="email" class="form-control" value="<?php echo esc($user['email']); ?>" required>
        </div>

        <div class="mb-3">
          <label for="avatar">Avatar (JPEG, PNG, WEBP) — max 2MB</label>
          <input id="avatar" name="avatar" type="file" accept="image/jpeg,image/png,image/webp" class="form-control">
        </div>

        <div style="display:flex;gap:12px;">
          <button class="btn btn-primary" type="submit">Save Changes</button>
          <a href="PlayerDashboard.php" class="btn btn-outline-light">Back to Dashboard</a>
        </div>
      </form>
    </div>
  </div>
</body>
</html>
