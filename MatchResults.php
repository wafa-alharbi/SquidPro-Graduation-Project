<?php
session_start();



$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'SquidPro';

$redirect_map = [
    'player'    => 'PlayerDashboard.php',
    'organizer' => 'OrganizerDashboard.php',
    'admin'     => 'AdminDashboard.php'
];

/* ---------- CSRF token ---------- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}

/* ---------- Helpers ---------- */
function esc($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function render_errors($errors) {
    if (empty($errors)) return '';
    $html = '<div style="background:#1f1f1f;padding:12px;border-radius:8px;border:1px solid rgba(255,255,255,0.04);margin-bottom:16px;">';
    $html .= '<ul style="margin:0;padding-left:18px;color:#ffb3b3;">';
    foreach ($errors as $err) {
        $html .= '<li style="margin-bottom:6px;">' . esc($err) . '</li>';
    }
    $html .= '</ul></div>';
    return $html;
}

/* ---------- Process POST ---------- */
$errors = [];
$old = ['email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $errors[] = 'Invalid request (security token mismatch).';
    }

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $old['email'] = $email;

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if ($password === '') {
        $errors[] = 'Please enter your password.';
    }

    if (empty($errors)) {
        $mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
        if ($mysqli->connect_errno) {
            $errors[] = 'Database connection failed.';
        } else {
            $mysqli->set_charset('utf8mb4');

            $stmt = $mysqli->prepare("SELECT id, username, password_hash, role, display_name, avatar_url FROM users WHERE email = ? LIMIT 1");
            if (!$stmt) {
                $errors[] = 'Database error.';
            } else {
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $stmt->store_result();

                if ($stmt->num_rows === 0) {
                    $errors[] = 'Email or password is incorrect.';
                    $stmt->close();
                    $mysqli->close();
                } else {
                    $stmt->bind_result($id, $username, $password_hash, $role, $display_name, $avatar_url);
                    $stmt->fetch();

                    if (password_verify($password, $password_hash)) {
                        // Successful login
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $id;
                        $_SESSION['username'] = $username;
                        $_SESSION['display_name'] = $display_name;
                        $_SESSION['role'] = $role;
                        $_SESSION['avatar_url'] = $avatar_url;

                        $stmt->close();
                        $mysqli->close();

                        // regenerate CSRF token
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));

                        // Determine redirect target based on role
                        $target = $redirect_map[$role] ?? 'PlayerDashboard.php';
                        header("Location: " . $target);
                        exit;
                    } else {
                        $errors[] = 'Email or password is incorrect.';
                        $stmt->close();
                        $mysqli->close();
                    }
                }
            }
        }
    }
}

/* ---------- HTML output (form + errors) ---------- */
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Squid Pro | Login</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Poppins', sans-serif; background: #0d1117; color: white; }
        .bg-waves { position: fixed; inset: 0; z-index: -1; background: radial-gradient(circle at 20% 20%, #1a237e, transparent 60%), radial-gradient(circle at 80% 80%, #8e24aa, transparent 60%), radial-gradient(circle at 50% 50%, #0d47a1, transparent 70%); filter: blur(80px); animation: move 12s infinite alternate ease-in-out; }
        @keyframes move { 0% { transform: scale(1); } 100% { transform: scale(1.3); } }
        .navbar { background-color: rgba(0,0,0,0.55) !important; backdrop-filter: blur(6px); }
        .logo-icon { width: 48px; height: 48px; background: linear-gradient(135deg, #0D6EFD, #8a2be2); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 22px; color: #fff; box-shadow: 0 0 20px rgba(13,110,253,0.85); }
        .site-title { font-size: 2.5rem; font-weight: 800; text-align: center; margin-bottom: 25px; background: linear-gradient(90deg, #0D6EFD, #8a2be2, #0D6EFD); -webkit-background-clip: text; color: transparent; animation: glow 3s infinite linear; }
        @keyframes glow { 0% { text-shadow: 0 0 10px #0D6EFD; } 50% { text-shadow: 0 0 25px #8a2be2; } 100% { text-shadow: 0 0 10px #0D6EFD; } }
        .login-box { background: rgba(255,255,255,0.06); padding: 35px; border-radius: 18px; border: 1px solid rgba(255,255,255,0.1); backdrop-filter: blur(8px); box-shadow: 0 0 25px rgba(13,110,253,0.4); animation: fadeIn 1.2s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(24px); } to { opacity: 1; transform: translateY(0); } }
        .login-box input { background: rgba(255,255,255,0.1); border: none; color: white; }
        .login-box input:focus { box-shadow: 0 0 10px #0D6EFD; }
        .btn-login { background: linear-gradient(135deg, #0D6EFD, #8a2be2); border: none; width: 100%; padding: 12px; border-radius: 50px; color: white; font-size: 1.1rem; transition: 0.3s; box-shadow: 0 0 15px rgba(13,110,253,0.7); }
        .btn-login:hover { transform: scale(1.03); box-shadow: 0 0 25px rgba(138,43,226,0.9); }
        a { color: #0D6EFD; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .small-note { opacity: 0.85; font-size: 0.95rem; margin-top: 10px; }
    </style>
</head>
<body>
    <div class="bg-waves"></div>

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
                    <li class="nav-item"><a class="nav-link" href="Games.php">Games</a></li>
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

    <div class="container d-flex justify-content-center align-items-center" style="min-height:100vh; padding-top:90px;">
        <div class="login-box col-md-5 col-lg-4">

            <div class="site-title">squid pro</div>

            <h3 class="text-center fw-bold mb-3">Login</h3>

            <?php echo render_errors($errors); ?>

            <form action="" method="POST" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo esc($_SESSION['csrf_token']); ?>">

                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control mb-3" required value="<?php echo esc($old['email']); ?>">

                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control mb-4" required>

                <button class="btn-login" type="submit">Login</button>
            </form>

            <p class="text-center small-note">Don't have an account? <a href="register.php">Create one</a></p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
