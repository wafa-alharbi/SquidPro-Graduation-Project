<?php


session_start();

/* ---------- Helper to clear session cookie ---------- */
function clear_session_cookie() {
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
}

/* ---------- Optional: update user's last_logout in DB if possible ---------- */
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'SquidPro';

$user_id = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;



/* ---------- Destroy session and clear cookies ---------- */
// Unset all session variables
$_SESSION = [];

// Clear session cookie
clear_session_cookie();

session_destroy();

header('Location: index.php');
exit;
