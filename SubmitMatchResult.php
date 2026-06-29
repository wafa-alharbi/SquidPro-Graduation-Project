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
function flash_html($msg, $type = 'info') {
    $bg = $type === 'success' ? '#163a0f' : '#2b1b1b';
    return '<div style="padding:10px;border-radius:6px;margin-bottom:12px;background:' . $bg . ';color:#fff;">' . esc($msg) . '</div>';
}

/* ---------- Auth / Role check ---------- */
if (empty($_SESSION['user_id'])) {
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

/* ---------- Ensure user_settings row exists ---------- */
$ensure = $mysqli->prepare("INSERT IGNORE INTO user_settings (user_id) VALUES (?)");
if ($ensure) {
    $ensure->bind_param('i', $user_id);
    $ensure->execute();
    $ensure->close();
}

/* ---------- Initialize state ---------- */
$errors = [];
$success = '';

/* ---------- Handle POST (save settings) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $errors[] = 'Invalid CSRF token.';
    }

    $display_name = trim($_POST['display_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $language = $_POST['language'] ?? 'en';
    $theme = $_POST['theme'] ?? 'dark';
    $notifications = isset($_POST['notifications']) ? 1 : 0;
    $privacy_level = $_POST['privacy_level'] ?? 'public';

    if ($display_name === '') $errors[] = 'Display name is required.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
    $allowed_langs = ['en','ar','es','fr'];
    if (!in_array($language, $allowed_langs, true)) $language = 'en';
    $allowed_themes = ['dark','light','neon'];
    if (!in_array($theme, $allowed_themes, true)) $theme = 'dark';
    $allowed_privacy = ['public','friends','private'];
    if (!in_array($privacy_level, $allowed_privacy, true)) $privacy_level = 'public';

    if (empty($errors)) {
        /* Check email uniqueness (exclude current user) */
        $chk = $mysqli->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
        if ($chk) {
            $chk->bind_param('si', $email, $user_id);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) {
                $errors[] = 'Email is already used by another account.';
            }
            $chk->close();
        } else {
            $errors[] = 'Database error.';
        }
    }

    if (empty($errors)) {
        /* Update users table (display_name, email) */
        $upd_user = $mysqli->prepare("UPDATE users SET display_name = ?, email = ? WHERE id = ?");
        if ($upd_user) {
            $upd_user->bind_param('ssi', $display_name, $email, $user_id);
            $upd_user->execute();
            $upd_user->close();
            $_SESSION['display_name'] = $display_name;
        }

        /* Update user_settings table */
        $upd = $mysqli->prepare("UPDATE user_settings SET language = ?, theme = ?, notifications = ?, privacy_level = ? WHERE user_id = ?");
        if ($upd) {
            $upd->bind_param('ssisi', $language, $theme, $notifications, $privacy_level, $user_id);
            if ($upd->execute()) {
                $success = 'Settings saved successfully.';
            } else {
                $errors[] = 'Failed to save settings.';
            }
            $upd->close();
        } else {
            $errors[] = 'Database error.';
        }
    }
}

/* ---------- Load current user and settings ---------- */
$user = ['username' => '', 'display_name' => '', 'email' => '', 'avatar_url' => ''];
if ($stmt = $mysqli->prepare("SELECT username, display_name, email, avatar_url FROM users WHERE id = ? LIMIT 1")) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->bind_result($u_username, $u_display_name, $u_email, $u_avatar_url);
    if ($stmt->fetch()) {
        $user['username'] = $u_username;
        $user['display_name'] = $u_display_name;
        $user['email'] = $u_email;
        $user['avatar_url'] = $u_avatar_url;
    }
    $stmt->close();
}

$settings = ['language' => 'en', 'theme' => 'dark', 'notifications' => 1, 'privacy_level' => 'public'];
if ($stmt = $mysqli->prepare("SELECT language, theme, notifications, privacy_level FROM user_settings WHERE user_id = ? LIMIT 1")) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->bind_result($s_language, $s_theme, $s_notifications, $s_privacy);
    if ($stmt->fetch()) {
        $settings['language'] = $s_language ?? 'en';
        $settings['theme'] = $s_theme ?? 'dark';
        $settings['notifications'] = (int)($s_notifications ?? 1);
        $settings['privacy_level'] = $s_privacy ?? 'public';
    }
    $stmt->close();
}

/* ---------- Close DB ---------- */
$mysqli->close();

/* ---------- Render HTML ---------- */
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Squid Pro | Settings</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">
<style>
  body { margin:0; font-family:'Poppins',sans-serif; background:#0d1117; color:#fff; overflow-x:hidden; }
  .bg-waves { position:fixed; inset:0; z-index:-3; background: radial-gradient(circle at 20% 20%, #1a237e, transparent 60%), radial-gradient(circle at 80% 80%, #8e24aa, transparent 60%), radial-gradient(circle at 50% 50%, #0d47a1, transparent 70%); filter: blur(90px); }
  .sidebar { width:260px; height:100vh; background:rgba(0,0,0,0.55); backdrop-filter: blur(10px); position:fixed; left:0; top:0; padding:25px 20px; border-right:1px solid rgba(255,255,255,0.1); }
  .sidebar h2 { font-weight:800; font-size:1.9rem; background:linear-gradient(90deg,#0D6EFD,#8a2be2); -webkit-background-clip:text; color:transparent; text-align:center; margin-bottom:24px; }
  .sidebar a { display:block; padding:12px 15px; margin-bottom:12px; border-radius:10px; color:white; text-decoration:none; font-size:1.05rem; transition:0.3s; }
  .sidebar a:hover, .sidebar a.active { background:linear-gradient(135deg,#0D6EFD,#8a2be2); box-shadow:0 0 15px rgba(13,110,253,0.6); }
  .main { margin-left:280px; padding:40px; }
  .settings-card { background:rgba(255,255,255,0.06); border-radius:18px; padding:25px; border:1px solid rgba(255,255,255,0.1); margin-bottom:20px; }
  label { color:#ddd; font-weight:600; }
  .avatar-small { width:56px; height:56px; border-radius:10px; object-fit:cover; }
  .btn-primary { background:linear-gradient(135deg,#0D6EFD,#8a2be2); border:none; }
</style>
</head>
<body>
  <div class="bg-waves"></div>

  <div class="sidebar">
    <h2>squid pro</h2>
    <nav style="margin-top:20px;">
      <a href="PlayerDashboard.php">🏠 Dashboard</a>
      <a href="Profile.php">👤 Profile</a>
      <a href="Tournaments.php">🏆 Tournaments</a>
      <a href="Locations.php">📍 Locations</a>
      <a href="Chat.php">💬 Chat</a>
      <a href="AI-Coach.php">🤖 AI Coach</a>
      <a href="Settings.php" class="active">⚙️ Settings</a>
      <a href="Logout.php">🚪 Logout</a>
    </nav>
  </div>

  <div class="main container">
    <h1 style="font-weight:800;">Settings</h1>
    <p style="opacity:0.8;">Manage your account, privacy, and notification preferences.</p>

    <?php
      if (!empty($success)) echo flash_html($success, 'success');
      if (!empty($errors)) {
          foreach ($errors as $err) echo flash_html($err, 'error');
      }
    ?>

    <div class="settings-card">
      <h4>Account</h4>
      <form method="POST" action="Settings.php" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo esc($_SESSION['csrf_token']); ?>">
        <div class="row mt-3">
          <div class="col-md-6">
            <label for="display_name">Display Name</label>
            <input id="display_name" name="display_name" type="text" class="form-control mb-3" value="<?php echo esc($user['display_name']); ?>" required>
          </div>
          <div class="col-md-6">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" class="form-control mb-3" value="<?php echo esc($user['email']); ?>" required>
          </div>
        </div>

        <div class="row mt-2">
          <div class="col-md-4">
            <label for="language">Language</label>
            <select id="language" name="language" class="form-select mb-3">
              <option value="en" <?php if($settings['language']==='en') echo 'selected'; ?>>English</option>
              <option value="ar" <?php if($settings['language']==='ar') echo 'selected'; ?>>Arabic</option>
              <option value="es" <?php if($settings['language']==='es') echo 'selected'; ?>>Spanish</option>
              <option value="fr" <?php if($settings['language']==='fr') echo 'selected'; ?>>French</option>
            </select>
          </div>
          <div class="col-md-4">
            <label for="theme">Theme</label>
            <select id="theme" name="theme" class="form-select mb-3">
              <option value="dark" <?php if($settings['theme']==='dark') echo 'selected'; ?>>Dark</option>
              <option value="light" <?php if($settings['theme']==='light') echo 'selected'; ?>>Light</option>
              <option value="neon" <?php if($settings['theme']==='neon') echo 'selected'; ?>>Neon</option>
            </select>
          </div>
          <div class="col-md-4">
            <label for="privacy_level">Privacy</label>
            <select id="privacy_level" name="privacy_level" class="form-select mb-3">
              <option value="public" <?php if($settings['privacy_level']==='public') echo 'selected'; ?>>Public</option>
              <option value="friends" <?php if($settings['privacy_level']==='friends') echo 'selected'; ?>>Friends</option>
              <option value="private" <?php if($settings['privacy_level']==='private') echo 'selected'; ?>>Private</option>
            </select>
          </div>
        </div>

        <div class="form-check form-switch mt-3">
          <input class="form-check-input" type="checkbox" id="notifications" name="notifications" <?php if($settings['notifications']) echo 'checked'; ?>>
          <label class="form-check-label" for="notifications">Enable notifications</label>
        </div>

        <div style="display:flex;gap:12px;margin-top:18px;">
          <button class="btn btn-primary" type="submit">Save Changes</button>
          <a href="PlayerDashboard.php" class="btn btn-outline-light">Back to Dashboard</a>
        </div>
      </form>
    </div>

    <div class="settings-card">
      <h4>Security</h4>
      <p style="opacity:0.85;">Change password or manage sessions from your account page.</p>
      <div style="display:flex;gap:12px;margin-top:12px;">
        <a href="ChangePassword.php" class="btn btn-outline-light">Change Password</a>
        <a href="Sessions.php" class="btn btn-outline-light">Manage Sessions</a>
      </div>
    </div>

    <div class="settings-card">
      <h4>Account Actions</h4>
      <p style="opacity:0.85;">Deactivate or delete your account. These actions are irreversible.</p>
      <div style="display:flex;gap:12px;margin-top:12px;">
        <form method="POST" action="AccountActions.php" onsubmit="return confirm('Are you sure you want to deactivate your account?');">
          <input type="hidden" name="action" value="deactivate">
          <button class="btn btn-warning" type="submit">Deactivate Account</button>
        </form>
        <form method="POST" action="AccountActions.php" onsubmit="return confirm('This will permanently delete your account. Continue?');">
          <input type="hidden" name="action" value="delete">
          <button class="btn btn-danger" type="submit">Delete Account</button>
        </form>
      </div>
    </div>

  </div>
</body>
</html>
