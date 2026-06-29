<?php
session_start();

/* ---------- CONFIG ---------- */
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'SquidPro';

/* ---------- HELPERS ---------- */
function esc($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function json_response($data) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($data); exit; }

/* ---------- AUTH ---------- */
if (empty($_SESSION['user_id'])) {
    json_response(['success' => false, 'error' => 'not_authenticated']);
}
$user_id = (int) $_SESSION['user_id'];

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}
$csrf = $_SESSION['csrf_token'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'invalid_method']);
}
$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($csrf, $token)) {
    json_response(['success' => false, 'error' => 'csrf']);
}

$match_id = (int)($_POST['match_id'] ?? 0);
$score_team1 = (int)($_POST['score_team1'] ?? 0);
$score_team2 = (int)($_POST['score_team2'] ?? 0);
if ($match_id <= 0) json_response(['success' => false, 'error' => 'invalid_match']);

/* ---------- DB ---------- */
$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($mysqli->connect_errno) {
    json_response(['success' => false, 'error' => 'db']);
}
$mysqli->set_charset('utf8mb4');

/* Ensure player results table exists */
$mysqli->query("
CREATE TABLE IF NOT EXISTS match_player_results (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  match_id INT NOT NULL,
  user_id INT NOT NULL,
  team_id INT NOT NULL,
  game_id INT NOT NULL,
  score_for INT NOT NULL,
  score_against INT NOT NULL,
  result ENUM('win','loss','draw') NOT NULL,
  submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_match_user (match_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* Ensure notifications table exists */
$mysqli->query("
CREATE TABLE IF NOT EXISTS notifications (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  type VARCHAR(50) NOT NULL,
  title VARCHAR(150) NOT NULL,
  body TEXT DEFAULT NULL,
  match_id INT DEFAULT NULL,
  is_read TINYINT(1) DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_match_type (user_id, match_id, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* Load match info */
$stmt = $mysqli->prepare("SELECT id, team1_id, team2_id, game_id, status, scheduled_at FROM matches WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $match_id);
$stmt->execute();
$stmt->bind_result($m_id, $team1_id, $team2_id, $game_id, $status, $scheduled_at);
if (!$stmt->fetch()) {
    $stmt->close();
    json_response(['success' => false, 'error' => 'not_found']);
}
$stmt->close();

// Check membership
$allowed = false;
$my_team_id = null;
$stmt = $mysqli->prepare("SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ? LIMIT 1");
$stmt->bind_param('ii', $team1_id, $user_id);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) { $allowed = true; $my_team_id = $team1_id; }
$stmt->free_result();
$stmt->close();

if (!$allowed) {
    $stmt = $mysqli->prepare("SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ? LIMIT 1");
    $stmt->bind_param('ii', $team2_id, $user_id);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) { $allowed = true; $my_team_id = $team2_id; }
    $stmt->free_result();
    $stmt->close();
}

// Also allow captains if not in team_members
if (!$allowed) {
    $stmt = $mysqli->prepare("SELECT id FROM teams WHERE (id = ? OR id = ?) AND captain_id = ? LIMIT 1");
    $stmt->bind_param('iii', $team1_id, $team2_id, $user_id);
    $stmt->execute();
    $stmt->bind_result($cap_tid);
    if ($stmt->fetch()) { $allowed = true; $my_team_id = (int)$cap_tid; }
    $stmt->close();
}

if (!$allowed) {
    json_response(['success' => false, 'error' => 'not_allowed']);
}

// Time gate
if (!empty($scheduled_at)) {
    $now = new DateTime('now');
    $sched = new DateTime($scheduled_at);
    if ($now < $sched) {
        json_response(['success' => false, 'error' => 'too_early', 'scheduled_at' => $scheduled_at]);
    }
}

// Compute winner (finalized later)
$winner_team_id = null;
if ($score_team1 > $score_team2) $winner_team_id = $team1_id;
if ($score_team2 > $score_team1) $winner_team_id = $team2_id;

// Store scores early and mark ongoing (finalization happens when all 6 submit)
if ($status !== 'finished') {
    $stmt = $mysqli->prepare("UPDATE matches SET score_team1 = ?, score_team2 = ?, winner_team_id = ?, status = 'ongoing' WHERE id = ?");
    $stmt->bind_param('ssii', $score_team1, $score_team2, $winner_team_id, $match_id);
    $stmt->execute();
    $stmt->close();
}

// Store player result
$score_for = ($my_team_id == $team1_id) ? $score_team1 : $score_team2;
$score_against = ($my_team_id == $team1_id) ? $score_team2 : $score_team1;
if ($score_for === $score_against) $result = 'draw';
elseif ($score_for > $score_against) $result = 'win';
else $result = 'loss';

$stmt = $mysqli->prepare("INSERT INTO match_player_results (match_id, user_id, team_id, game_id, score_for, score_against, result)
                          VALUES (?, ?, ?, ?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE score_for=VALUES(score_for), score_against=VALUES(score_against), result=VALUES(result)");
$stmt->bind_param('iiiiiis', $match_id, $user_id, $my_team_id, $game_id, $score_for, $score_against, $result);
$stmt->execute();
$stmt->close();

// Check if all 3 players per team submitted -> finalize match
function top_three_users($mysqli, $team_id) {
    $users = [];
    $stmt = $mysqli->prepare("
        SELECT user_id
        FROM team_members
        WHERE team_id = ?
        ORDER BY role DESC, joined_at ASC
        LIMIT 3
    ");
    $stmt->bind_param('i', $team_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $users[] = (int)$row['user_id']; }
    $stmt->close();
    return $users;
}

$team1_users = top_three_users($mysqli, $team1_id);
$team2_users = top_three_users($mysqli, $team2_id);

$team1_done = 0;
$team2_done = 0;

if (!empty($team1_users)) {
    $in = implode(',', array_map('intval', $team1_users));
    $q = $mysqli->query("SELECT COUNT(DISTINCT user_id) AS c FROM match_player_results WHERE match_id = " . (int)$match_id . " AND user_id IN ($in)");
    if ($q) { $team1_done = (int)$q->fetch_assoc()['c']; }
}
if (!empty($team2_users)) {
    $in = implode(',', array_map('intval', $team2_users));
    $q = $mysqli->query("SELECT COUNT(DISTINCT user_id) AS c FROM match_player_results WHERE match_id = " . (int)$match_id . " AND user_id IN ($in)");
    if ($q) { $team2_done = (int)$q->fetch_assoc()['c']; }
}

if ($status !== 'finished' && $team1_done >= 3 && $team2_done >= 3) {
    $stmt = $mysqli->prepare("UPDATE matches SET status = 'finished', match_date = NOW() WHERE id = ?");
    $stmt->bind_param('i', $match_id);
    $stmt->execute();
    $stmt->close();

    // notify players (top 3 from each team)
    $notify_users = array_unique(array_merge($team1_users, $team2_users));
    if (!empty($notify_users)) {
        $stmt = $mysqli->prepare("
            INSERT IGNORE INTO notifications (user_id, type, title, body, match_id)
            VALUES (?, 'match_completed', ?, ?, ?)
        ");
        $title = "Match results are ready";
        $body = "Match #{$match_id} is finished. View results now.";
        foreach ($notify_users as $uid) {
            $uid = (int)$uid;
            $stmt->bind_param('issi', $uid, $title, $body, $match_id);
            $stmt->execute();
        }
        $stmt->close();
    }
}

json_response(['success' => true]);
?>
