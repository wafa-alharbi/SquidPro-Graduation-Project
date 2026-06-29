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

/* ---------- Auth / Role check ---------- */
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['role']) || $_SESSION['role'] !== 'player') {
    // If user is organizer/admin, redirect to their dashboards
    if (!empty($_SESSION['role'])) {
        if ($_SESSION['role'] === 'organizer') {
            header('Location: OrganizerDashboard.php');
            exit;
        } elseif ($_SESSION['role'] === 'admin') {
            header('Location: AdminDashboard.php');
            exit;
        }
    }
    // Otherwise force login
    header('Location: login.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];

/* ---------- Connect to DB ---------- */
$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo "Database connection failed.";
    exit;
}
$mysqli->set_charset('utf8mb4');

/* ---------- Ensure notifications table exists ---------- */
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

/* ---------- Handle notifications actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';
    if (hash_equals($_SESSION['csrf_token'], $token)) {
        if ($action === 'mark_read') {
            $nid = (int)($_POST['notification_id'] ?? 0);
            if ($nid > 0) {
                $stmt = $mysqli->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
                $stmt->bind_param('ii', $nid, $user_id);
                $stmt->execute();
                $stmt->close();
            }
        }
        if ($action === 'mark_all_read') {
            $stmt = $mysqli->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $stmt->close();
        }
    }
}

/* ---------- Load user profile ---------- */
$user = null;
if ($stmt = $mysqli->prepare("SELECT id, username, display_name, avatar_url, email FROM users WHERE id = ? LIMIT 1")) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->bind_result($u_id, $u_username, $u_display_name, $u_avatar_url, $u_email);
    if ($stmt->fetch()) {
        $user = [
            'id' => $u_id,
            'username' => $u_username,
            'display_name' => $u_display_name,
            'avatar_url' => $u_avatar_url,
            'email' => $u_email
        ];
    }
    $stmt->close();
}

/* ---------- Load user stats (leaderboard) ---------- */
$stats = [
    'total_points' => 0,
    'wins' => 0,
    'losses' => 0,
    'streak' => 0
];
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

/* ---------- Load teams (captain or member) ---------- */
$teams = [];
$query = "
    SELECT t.id, t.name, t.logo_url, t.created_at, 
           CASE WHEN t.captain_id = ? THEN 1 ELSE 0 END AS is_captain
    FROM teams t
    LEFT JOIN team_members tm ON tm.team_id = t.id AND tm.user_id = ?
    WHERE t.captain_id = ? OR tm.user_id = ?
    GROUP BY t.id
    ORDER BY t.created_at DESC
    LIMIT 10
";
if ($stmt = $mysqli->prepare($query)) {
    $stmt->bind_param('iiii', $user_id, $user_id, $user_id, $user_id);
    $stmt->execute();
    $stmt->bind_result($t_id, $t_name, $t_logo, $t_created, $t_is_captain);
    while ($stmt->fetch()) {
        $teams[] = [
            'id' => $t_id,
            'name' => $t_name,
            'logo_url' => $t_logo,
            'created_at' => $t_created,
            'is_captain' => (bool)$t_is_captain
        ];
    }
    $stmt->close();
}

/* ---------- Load upcoming events (next 6) ---------- */
$events = [];
if ($stmt = $mysqli->prepare("SELECT id, name, start_date, location_name FROM events WHERE start_date >= NOW() ORDER BY start_date ASC LIMIT 6")) {
    $stmt->execute();
    $stmt->bind_result($e_id, $e_name, $e_start, $e_location);
    while ($stmt->fetch()) {
        $events[] = [
            'id' => $e_id,
            'name' => $e_name,
            'start_date' => $e_start,
            'location_name' => $e_location
        ];
    }
    $stmt->close();
}

/* ---------- Load recent matches for user's teams (last 6) ---------- */
$recent_matches = [];
$match_query = "
    SELECT m.id, m.match_date, m.score_team1, m.score_team2, t1.name AS team1_name, t2.name AS team2_name, m.status
    FROM matches m
    LEFT JOIN teams t1 ON t1.id = m.team1_id
    LEFT JOIN teams t2 ON t2.id = m.team2_id
    WHERE m.team1_id IN (SELECT team_id FROM team_members WHERE user_id = ?)
       OR m.team2_id IN (SELECT team_id FROM team_members WHERE user_id = ?)
    ORDER BY m.match_date DESC
    LIMIT 6
";
if ($stmt = $mysqli->prepare($match_query)) {
    $stmt->bind_param('ii', $user_id, $user_id);
    $stmt->execute();
    $stmt->bind_result($m_id, $m_date, $m_score1, $m_score2, $m_t1name, $m_t2name, $m_status);
    while ($stmt->fetch()) {
        $recent_matches[] = [
            'id' => $m_id,
            'match_date' => $m_date,
            'score_team1' => $m_score1,
            'score_team2' => $m_score2,
            'team1_name' => $m_t1name,
            'team2_name' => $m_t2name,
            'status' => $m_status
        ];
    }
    $stmt->close();
}

/* ---------- Load notifications (latest 5) ---------- */
$notifications = [];
if ($stmt = $mysqli->prepare("
    SELECT id, title, body, match_id, is_read, created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 5
")) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->bind_result($n_id, $n_title, $n_body, $n_match_id, $n_is_read, $n_created);
    while ($stmt->fetch()) {
        $notifications[] = [
            'id' => $n_id,
            'title' => $n_title,
            'body' => $n_body,
            'match_id' => $n_match_id,
            'is_read' => (int)$n_is_read,
            'created_at' => $n_created
        ];
    }
    $stmt->close();
}

/* ---------- Load upcoming matches for user's teams (next 6) ---------- */
$upcoming_matches = [];
$up_query = "
    SELECT m.id, m.scheduled_at, m.status, t1.name AS team1_name, t2.name AS team2_name, g.name AS game_name
    FROM matches m
    LEFT JOIN teams t1 ON t1.id = m.team1_id
    LEFT JOIN teams t2 ON t2.id = m.team2_id
    LEFT JOIN games g ON g.id = m.game_id
    WHERE (m.team1_id IN (SELECT team_id FROM team_members WHERE user_id = ?)
       OR m.team2_id IN (SELECT team_id FROM team_members WHERE user_id = ?))
      AND m.status IN ('pending','accepted','ongoing')
    ORDER BY m.scheduled_at ASC
    LIMIT 6
";
if ($stmt = $mysqli->prepare($up_query)) {
    $stmt->bind_param('ii', $user_id, $user_id);
    $stmt->execute();
    $stmt->bind_result($um_id, $um_sched, $um_status, $um_t1, $um_t2, $um_game);
    while ($stmt->fetch()) {
        $upcoming_matches[] = [
            'id' => $um_id,
            'scheduled_at' => $um_sched,
            'status' => $um_status,
            'team1_name' => $um_t1,
            'team2_name' => $um_t2,
            'game_name' => $um_game
        ];
    }
    $stmt->close();
}

/* ---------- Load my reports (latest 5) ---------- */
$my_reports = [];
$has_response_cols = false;
$colCheck = $mysqli->query("
    SELECT COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reports'
");
$cols = [];
if ($colCheck) {
    while ($r = $colCheck->fetch_assoc()) { $cols[$r['COLUMN_NAME']] = true; }
}
$has_response_cols = isset($cols['response_text']) && isset($cols['responded_at']);

$report_sql = $has_response_cols
    ? "SELECT id, issue_type, description, status, created_at, response_text, responded_at FROM reports WHERE reporter_id = ? ORDER BY created_at DESC LIMIT 5"
    : "SELECT id, issue_type, description, status, created_at FROM reports WHERE reporter_id = ? ORDER BY created_at DESC LIMIT 5";

if ($stmt = $mysqli->prepare($report_sql)) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    if ($has_response_cols) {
        $stmt->bind_result($r_id, $r_issue, $r_desc, $r_status, $r_created, $r_response, $r_responded_at);
        while ($stmt->fetch()) {
            $my_reports[] = [
                'id' => $r_id,
                'issue_type' => $r_issue,
                'description' => $r_desc,
                'status' => $r_status,
                'created_at' => $r_created,
                'response_text' => $r_response,
                'responded_at' => $r_responded_at
            ];
        }
    } else {
        $stmt->bind_result($r_id, $r_issue, $r_desc, $r_status, $r_created);
        while ($stmt->fetch()) {
            $my_reports[] = [
                'id' => $r_id,
                'issue_type' => $r_issue,
                'description' => $r_desc,
                'status' => $r_status,
                'created_at' => $r_created,
                'response_text' => null,
                'responded_at' => null
            ];
        }
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
<title>Squid Pro | Player Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">
<style>
  body { margin:0; font-family:'Poppins',sans-serif; background:#0d1117; color:#fff; }
  .pulse-grid { position:fixed; inset:0; z-index:-1; background: repeating-linear-gradient(to bottom, rgba(255,255,255,0.03) 0px, rgba(255,255,255,0.03) 1px, transparent 2px, transparent 4px); }
  .bg-waves { position:fixed; inset:0; z-index:-3; background: radial-gradient(circle at 20% 20%, #1a237e, transparent 60%), radial-gradient(circle at 80% 80%, #8e24aa, transparent 60%), radial-gradient(circle at 50% 50%, #0d47a1, transparent 70%); filter: blur(90px); }
  .navbar { background-color: rgba(0,0,0,0.55) !important; backdrop-filter: blur(6px); }.sidebar { width:260px; height:100vh; background:rgba(0,0,0,0.55); backdrop-filter:blur(10px); position:fixed; left:0; top:0; padding:25px 20px; border-right:1px solid rgba(255,255,255,0.1); }
  .sidebar h2 { font-weight:800; font-size:1.9rem; background:linear-gradient(90deg,#0D6EFD,#8a2be2); -webkit-background-clip:text; color:transparent; text-align:center; margin-bottom:24px; }
  .sidebar a { display:block; padding:12px 15px; margin-bottom:12px; border-radius:10px; color:white; text-decoration:none; font-size:1.05rem; transition:0.3s; }
  .sidebar a:hover, .sidebar a.active { background:linear-gradient(135deg,#0D6EFD,#8a2be2); box-shadow:0 0 15px rgba(13,110,253,0.6); }.sidebar { width:250px; height:100vh; position:fixed; left:0; top:0; padding:24px; background:rgba(0,0,0,0.55); border-right:1px solid rgba(255,255,255,0.06); }
   a.nav-link { color:#fff; display:block; padding:10px 0; text-decoration:none; }
  a.nav-link.active, a.nav-link:hover { background:linear-gradient(135deg,#0D6EFD,#8a2be2); color:#fff; border-radius:8px; padding-left:12px; }
  .main { margin-left:270px; padding:32px; }
  .card { background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.06); border-radius:12px; padding:18px; }
  .avatar { width:72px; height:72px; border-radius:12px; object-fit:cover; }
  a { color:#0D6EFD; text-decoration:none; }
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
      <a href="PlayerMatchHistory.php" class="nav-link">🧾 Match History</a>
      <a href="AI-Coach.php" class="nav-link">🤖 AI Coach</a>
      <a href="index.php" class="nav-link">🏠 Home</a>
      <a href="Logout.php" class="nav-link">🚪 Logout</a>
    </nav>
  </div>

  <div class="main">
    <h1 style="font-weight:800;">Welcome back, <?php echo esc($user['display_name'] ?: $user['username']); ?> </h1>
    <p style="opacity:0.8;">Your personalized player overview.</p>

   

    <div class="row mt-4 g-3">
      <div class="col-md-6">
        <h4 style="font-weight:700;">Your Teams</h4>
        <?php if (empty($teams)): ?>
          <div class="card text-white">You are not a member of any team yet.</div>
        <?php else: ?>
          <?php foreach ($teams as $t): ?>
            <div class="card mb-2 d-flex align-items-center">
              <div style="display:flex;gap:12px;align-items:center;">
               
                <div>
                  <div style="font-weight:700;color:white"><?php echo esc($t['name']); ?></div>
                  <div style="opacity:0.8;font-size:0.9rem;color:white"><?php echo $t['is_captain'] ? 'Captain' : 'Member'; ?></div>
                </div>
              </div>
              <div style="margin-left:auto;">
                <a href="Team.php?id=<?php echo (int)$t['id']; ?>" class="btn btn-sm btn-outline-primary">Open</a>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="col-md-6">
        <h4 style="font-weight:700;">Upcoming Events</h4>
        <?php if (empty($events)): ?>
          <div class="card text-white">No upcoming events found.</div>
        <?php else: ?>
          <?php foreach ($events as $ev): ?>
            <div class="card mb-2">
              <div style="display:flex;justify-content:space-between;align-items:center;">
                <div>
                  <div style="font-weight:700;color:white"><?php echo esc($ev['name']); ?></div>
                  <div style="opacity:0.8;color:white"><?php echo esc($ev['location_name'] ?: 'Online / TBD'); ?></div>
                </div>
                <div style="text-align:right;color:white">
                  <div style="font-weight:700;color:white"><?php echo esc(date('M j, Y H:i', strtotime($ev['start_date']))); ?></div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="mt-4">
      <h4 style="font-weight:700;">Upcoming Matches</h4>
      <?php if (empty($upcoming_matches)): ?>
        <div class="card text-white">No upcoming matches.</div>
      <?php else: ?>
        <?php foreach ($upcoming_matches as $um): ?>
          <div class="card mb-2">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <div>
                <div style="font-weight:700;color:white"><?php echo esc($um['team1_name']); ?> vs <?php echo esc($um['team2_name']); ?></div>
                <div style="opacity:0.8;color:white"><?php echo esc($um['game_name'] ?: 'Game'); ?> • <?php echo esc($um['status']); ?></div>
                <div style="opacity:0.8;color:white"><?php echo esc($um['scheduled_at']); ?></div>
              </div>
              <div>
                <?php
                  $can_play = true;
                  if (!empty($um['scheduled_at'])) {
                      $can_play = (strtotime($um['scheduled_at']) <= time());
                  }
                ?>
                <a href="PlayMatch.php?match_id=<?php echo (int)$um['id']; ?>" class="btn btn-sm btn-primary <?php echo $can_play ? '' : 'disabled'; ?>">Start</a>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="mt-4">
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <h4 style="font-weight:700;">Notifications</h4>
        <form method="POST" style="margin:0;">
          <input type="hidden" name="csrf_token" value="<?php echo esc($_SESSION['csrf_token']); ?>">
          <input type="hidden" name="action" value="mark_all_read">
          <button class="btn btn-sm btn-outline-light">Mark All Read</button>
        </form>
      </div>
      <?php if (empty($notifications)): ?>
        <div class="card text-white">No notifications yet.</div>
      <?php else: ?>
        <?php foreach ($notifications as $n): ?>
          <div class="card mb-2">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <div>
                <div style="font-weight:700;color:white"><?php echo esc($n['title']); ?> <?php if (!$n['is_read']): ?><span class="badge bg-warning text-dark ms-2">New</span><?php endif; ?></div>
                <div style="opacity:0.8;color:white"><?php echo esc($n['body']); ?></div>
                <div style="opacity:0.7;color:white;font-size:0.9rem;"><?php echo esc($n['created_at']); ?></div>
              </div>
              <div>
                <?php if (!empty($n['match_id'])): ?>
                  <a href="MatchResults.php?match_id=<?php echo (int)$n['match_id']; ?>&notif_id=<?php echo (int)$n['id']; ?>" class="btn btn-sm btn-outline-primary">View</a>
                <?php endif; ?>
                <?php if (!$n['is_read']): ?>
                  <form method="POST" style="display:inline-block;margin-left:6px;">
                    <input type="hidden" name="csrf_token" value="<?php echo esc($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="action" value="mark_read">
                    <input type="hidden" name="notification_id" value="<?php echo (int)$n['id']; ?>">
                    <button class="btn btn-sm btn-outline-light">Mark Read</button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="mt-4">
      <h4 style="font-weight:700;">Recent Matches</h4>
      <?php if (empty($recent_matches)): ?>
        <div class="card text-white">No recent matches for your teams.</div>
      <?php else: ?>
        <?php foreach ($recent_matches as $m): ?>
          <div class="card mb-2">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <div>
                <div style="font-weight:700;color:white"><?php echo esc($m['team1_name']); ?> vs <?php echo esc($m['team2_name']); ?></div>
                <div style="opacity:0.8;color:white"><?php echo esc($m['status']); ?>  <?php echo esc(date('M j, Y H:i', strtotime($m['match_date']))); ?></div>
              </div>
              <div style="font-weight:700;color:white"><?php echo esc($m['score_team1'] ?: '-'); ?> : <?php echo esc($m['score_team2'] ?: '-'); ?></div>
            </div>
            <?php if ($m['status'] === 'finished'): ?>
              <div class="mt-2">
                <a href="MatchResults.php?match_id=<?php echo (int)$m['id']; ?>" class="btn btn-sm btn-outline-primary">View Results</a>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="mt-4">
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <h4 style="font-weight:700;">My Reports</h4>
        <a href="ReportIssue.php" class="btn btn-sm btn-outline-primary">Create Report</a>
      </div>
      <?php if (empty($my_reports)): ?>
        <div class="card text-white">You have not submitted any reports yet.</div>
      <?php else: ?>
        <?php foreach ($my_reports as $rp): ?>
          <div class="card mb-2">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <div>
                <div style="font-weight:700;color:white">#<?php echo esc($rp['id']); ?> — <?php echo esc($rp['issue_type'] ?: 'Report'); ?></div>
                <div style="opacity:0.8;color:white"><?php echo esc(date('M j, Y H:i', strtotime($rp['created_at']))); ?> • Status: <?php echo esc($rp['status']); ?></div>
                <div style="margin-top:6px;color:white"><?php echo esc($rp['description']); ?></div>
                <?php if (!empty($rp['response_text'])): ?>
                  <div style="margin-top:8px;color:#9ad0ff;"><strong>Organizer Reply:</strong> <?php echo esc($rp['response_text']); ?></div>
                  <div style="opacity:0.8;color:white;font-size:0.9rem;">Replied at: <?php echo esc($rp['responded_at']); ?></div>
                <?php else: ?>
                  <div style="opacity:0.7;color:white;font-size:0.9rem;margin-top:6px;">No reply yet.</div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

  </div>
</body>
</html>
