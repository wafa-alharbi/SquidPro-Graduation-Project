<?php
session_start();

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'SquidPro';
$redirect_on_success = 'Login.php';

/* ---------- Create CSRF token ---------- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}

/* ---------- Helper functions ---------- */
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
$old = ['display_name'=>'','username'=>'','email'=>'','role'=>'player'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $errors[] = 'Invalid request (security token mismatch).';
    }

    $display_name = trim($_POST['display_name'] ?? '');
    $username     = trim($_POST['username'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $password     = $_POST['password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';
    $role         = $_POST['role'] ?? 'player';

    $old['display_name'] = $display_name;
    $old['username'] = $username;
    $old['email'] = $email;
    $old['role'] = $role;

    /* Validation */
    if ($display_name === '') $errors[] = 'Full name is required.';
    if ($username === '') $errors[] = 'Username is required.';
    if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $username)) $errors[] = 'Username must be 3–30 chars, letters/numbers/underscore only.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirm_pass) $errors[] = 'Passwords do not match.';
    if (!in_array($role, ['player','organizer'], true)) $errors[] = 'Invalid role selected.';

    /* If no validation errors, proceed to DB */
    if (empty($errors)) {
        $mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
        if ($mysqli->connect_errno) {
            $errors[] = 'Database connection failed.';
        } else {
            $mysqli->set_charset('utf8mb4');

            /* Check duplicates (username or email) */
            $stmt = $mysqli->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
            if (!$stmt) {
                $errors[] = 'Database error (prepare failed).';
            } else {
                $stmt->bind_param('ss', $username, $email);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows > 0) {
                    $errors[] = 'Username or email already exists.';
                    $stmt->close();
                } else {
                    $stmt->close();

                    /* Hash password */
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);

                    /* Insert user with role */
                    $ins = $mysqli->prepare("INSERT INTO users (username, email, password_hash, display_name, role, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                    if (!$ins) {
                        $errors[] = 'Database error (prepare failed).';
                    } else {
                        $ins->bind_param('sssss', $username, $email, $password_hash, $display_name, $role);
                        if ($ins->execute()) {
                            $ins->close();
                            $mysqli->close();

                            /* regenerate CSRF token after successful action */
                            $_SESSION['csrf_token'] = bin2hex(random_bytes(24));

                            /* Redirect to login */
                            header("Location: " . $redirect_on_success);
                            exit;
                        } else {
                            $errors[] = 'Registration failed. Please try again later.';
                            $ins->close();
                            $mysqli->close();
                        }
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
    <title>Squid Pro | Register</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Poppins', sans-serif; background: #0d1117; color: white; }
        .bg-waves { position: fixed; inset: 0; z-index: -1; background: radial-gradient(circle at 20% 20%, #1a237e, transparent 60%), radial-gradient(circle at 80% 80%, #8e24aa, transparent 60%), radial-gradient(circle at 50% 50%, #0d47a1, transparent 70%); filter: blur(80px); animation: move 12s infinite alternate ease-in-out; }
        @keyframes move { 0% { transform: scale(1); } 100% { transform: scale(1.3); } }
        .navbar { background-color: rgba(0,0,0,0.55) !important; backdrop-filter: blur(6px); }
        .logo-icon { width: 48px; height: 48px; background: linear-gradient(135deg, #0D6EFD, #8a2be2); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 22px; color: #fff; box-shadow: 0 0 20px rgba(13,110,253,0.85); }
        .site-title { font-size: 2.5rem; font-weight: 800; text-align: center; margin-bottom: 12px; background: linear-gradient(90deg, #0D6EFD, #8a2be2, #0D6EFD); -webkit-background-clip: text; color: transparent; animation: glow 3s infinite linear; }
        @keyframes glow { 0% { text-shadow: 0 0 10px #0D6EFD; } 50% { text-shadow: 0 0 25px #8a2be2; } 100% { text-shadow: 0 0 10px #0D6EFD; } }
        .register-box { background: rgba(255,255,255,0.06); padding: 28px; border-radius: 14px; border: 1px solid rgba(255,255,255,0.08); backdrop-filter: blur(8px); box-shadow: 0 0 25px rgba(13,110,253,0.35); }
        .register-box input, .register-box select { background: rgba(255,255,255,0.06); border: none; color: white; }
        .register-box input:focus, .register-box select:focus { box-shadow: 0 0 10px #0D6EFD; }
        .btn-register { background: linear-gradient(135deg, #0D6EFD, #8a2be2); border: none; width: 100%; padding: 12px; border-radius: 50px; color: white; font-size: 1.05rem; }
        .btn-register:hover { transform: scale(1.02); box-shadow: 0 0 20px rgba(138,43,226,0.8); }
        a { color: #0D6EFD; text-decoration: none; }
        .role-row { display:flex; gap:12px; align-items:center; margin-bottom:12px; }
        .role-row label { margin-bottom:0; }
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
        <div class="register-box col-md-6 col-lg-5">

            <div class="site-title">squid pro</div>
            <h3 class="text-center fw-bold mb-3">Create Account</h3>

            <?php echo render_errors($errors); ?>

            <form action="" method="POST" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo esc($_SESSION['csrf_token']); ?>">

                <label class="form-label">Full Name</label>
                <input type="text" name="display_name" class="form-control mb-3" required value="<?php echo esc($old['display_name']); ?>">

                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-control mb-3" required value="<?php echo esc($old['username']); ?>">

                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control mb-3" required value="<?php echo esc($old['email']); ?>">

                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control mb-3" required>

                <label class="form-label">Confirm Password</label>
                <input type="password" name="confirm_password" class="form-control mb-3" required>

                <div class="mb-3">
                    <label class="form-label">Account Type</label>
                    <div class="role-row">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="role" id="rolePlayer" value="player" <?php echo ($old['role'] === 'player') ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="rolePlayer">Player</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="role" id="roleOrganizer" value="organizer" <?php echo ($old['role'] === 'organizer') ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="roleOrganizer">Tournament Organizer</label>
                        </div>
                    </div>
                    <small class="text-white">Choose the account type that matches your role on the platform.</small>
                </div>

                <button class="btn-register" type="submit">Register</button>
            </form>

            <p class="text-center mt-3">Already have an account? <a href="Login.php">Login</a></p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
