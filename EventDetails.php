<?php
session_start();

/* ================== CONFIG ================== */
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'SquidPro';
$port = 3306; 

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


$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name, $port);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo "Database connection failed.";
    exit;
}
$mysqli->set_charset('utf8mb4');

/* ================== HELPERS ================== */
function esc($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function json_response($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/* ================== AUTH ================== */
if (empty($_SESSION['user_id'])) {
    if (!empty($_REQUEST['action'])) {
        json_response(['success' => false, 'error' => 'not_authenticated']);
    }
    header('Location: login.php');
    exit;
}
$user_id      = (int)$_SESSION['user_id'];
$display_name = $_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Player';

/* ================== CSRF ================== */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}
$csrf = $_SESSION['csrf_token'];

/* ================== ROUTING ================== */
$action = $_REQUEST['action'] ?? '';

/* ---------- Utility: ensure team chat exists and participants added ---------- */
function ensure_team_chat_exists($mysqli, $team_id) {
    // returns chat_id
    $team_id = (int)$team_id;
    $stmt = $mysqli->prepare("SELECT id FROM chats WHERE type='team' AND team_id = ? LIMIT 1");
    $stmt->bind_param('i', $team_id);
    $stmt->execute();
    $stmt->bind_result($chat_id);
    if ($stmt->fetch()) {
        $stmt->close();
        return (int)$chat_id;
    }
    $stmt->close();

    // create chat
    $ins = $mysqli->prepare("INSERT INTO chats (type, team_id, created_at) VALUES ('team', ?, NOW())");
    $ins->bind_param('i', $team_id);
    $ins->execute();
    $new_chat_id = (int)$mysqli->insert_id;
    $ins->close();

    // add all team members as participants
    $sel = $mysqli->prepare("SELECT user_id FROM team_members WHERE team_id = ?");
    $sel->bind_param('i', $team_id);
    $sel->execute();
    $res = $sel->get_result();
    $members = [];
    while ($r = $res->fetch_assoc()) $members[] = (int)$r['user_id'];
    $sel->close();

    if (!empty($members)) {
        $stmtIns = $mysqli->prepare("INSERT INTO chat_participants (chat_id, user_id) VALUES (?, ?)");
        foreach ($members as $m) {
            $stmtIns->bind_param('ii', $new_chat_id, $m);
            $stmtIns->execute();
        }
        $stmtIns->close();
    }

    return $new_chat_id;
}

/* ---------- 1) LIST TEAMMATES (same team only) ---------- */
if ($action === 'list_users') {
    $users = [];

    $sql = "
        SELECT DISTINCT u.id, u.username, u.display_name
        FROM users u
        JOIN team_members tm2 ON tm2.user_id = u.id
        JOIN team_members tm1 ON tm1.team_id = tm2.team_id AND tm1.user_id = ?
        WHERE u.id != ?
        ORDER BY u.username ASC
    ";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('ii', $user_id, $user_id);
    $stmt->execute();
    $stmt->bind_result($uid, $uname, $dname);
    while ($stmt->fetch()) {
        $users[] = [
            'id'   => (int)$uid,
            'name' => $dname ?: $uname,
        ];
    }
    $stmt->close();

    json_response(['success' => true, 'users' => $users]);
}

/* ---------- 2) CREATE PRIVATE CHAT (between teammates only) ---------- */
if ($action === 'create_private_chat') {
    $other_id = (int)($_POST['other_id'] ?? 0);
    if ($other_id <= 0) json_response(['success' => false, 'error' => 'invalid_user']);

    // verify both users share at least one team
    $sql = "
        SELECT 1
        FROM team_members
        WHERE user_id = ?
          AND team_id IN (SELECT team_id FROM team_members WHERE user_id = ?)
        LIMIT 1
    ";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('ii', $other_id, $user_id);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows === 0) {
        $stmt->close();
        json_response(['success' => false, 'error' => 'not_same_team']);
    }
    $stmt->close();

    // check if private chat already exists (two participants)
    $sql = "
        SELECT c.id
        FROM chats c
        JOIN chat_participants p1 ON p1.chat_id = c.id AND p1.user_id = ?
        JOIN chat_participants p2 ON p2.chat_id = c.id AND p2.user_id = ?
        WHERE c.type = 'private'
        LIMIT 1
    ";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('ii', $user_id, $other_id);
    $stmt->execute();
    $stmt->bind_result($existing_id);
    if ($stmt->fetch()) {
        $stmt->close();
        json_response(['success' => true, 'chat_id' => (int)$existing_id]);
    }
    $stmt->close();

    // create new private chat
    $mysqli->query("INSERT INTO chats (type, created_at) VALUES ('private', NOW())");
    $chat_id = (int)$mysqli->insert_id;

    // add participants
    $stmt = $mysqli->prepare("INSERT INTO chat_participants (chat_id, user_id) VALUES (?, ?), (?, ?)");
    $stmt->bind_param('iiii', $chat_id, $user_id, $chat_id, $other_id);
    $stmt->execute();
    $stmt->close();

    json_response(['success' => true, 'chat_id' => $chat_id]);
}

/* ---------- 3) LIST CHATS (global + team + private) ---------- */
if ($action === 'list_chats') {
    $chats = [];

    // ensure global chat exists
    $res = $mysqli->query("SELECT id FROM chats WHERE type = 'global' LIMIT 1");
    if ($row = $res->fetch_assoc()) {
        $chats[] = ['id' => (int)$row['id'], 'title' => 'Global Chat', 'type' => 'global'];
    } else {
        $mysqli->query("INSERT INTO chats (type, created_at) VALUES ('global', NOW())");
        $gid = (int)$mysqli->insert_id;
        $chats[] = ['id' => $gid, 'title' => 'Global Chat', 'type' => 'global'];
    }

    // team chats: ensure chat exists for each team user belongs to, then list
    $stmt = $mysqli->prepare("SELECT DISTINCT t.id, t.name FROM teams t JOIN team_members tm ON tm.team_id = t.id WHERE tm.user_id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->bind_result($tid, $tname);
    $team_ids = [];
    while ($stmt->fetch()) {
        $team_ids[] = ['id' => (int)$tid, 'name' => $tname];
    }
    $stmt->close();

    foreach ($team_ids as $t) {
        $chat_id = ensure_team_chat_exists($mysqli, $t['id']);
        $chats[] = ['id' => $chat_id, 'title' => 'Team: ' . $t['name'], 'type' => 'team', 'team_id' => $t['id']];
    }

    // private chats where current user is participant
    $sql = "
        SELECT DISTINCT c.id, u.username, u.display_name
        FROM chats c
        JOIN chat_participants cp1 ON cp1.chat_id = c.id AND cp1.user_id = ?
        JOIN chat_participants cp2 ON cp2.chat_id = c.id AND cp2.user_id != ?
        JOIN users u ON u.id = cp2.user_id
        WHERE c.type = 'private'
    ";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('ii', $user_id, $user_id);
    $stmt->execute();
    $stmt->bind_result($pcid, $uname, $dname);
    while ($stmt->fetch()) {
        $chats[] = [
            'id'    => (int)$pcid,
            'title' => $dname ?: $uname,
            'type'  => 'private',
        ];
    }
    $stmt->close();

    json_response(['success' => true, 'chats' => $chats]);
}

/* ---------- 4) FETCH MESSAGES ---------- */
if ($action === 'fetch_messages') {
    $chat_id = (int)($_GET['chat_id'] ?? 0);
    $since   = $_GET['since'] ?? '';
    $since_id = (int)($_GET['since_id'] ?? 0);

    if ($chat_id <= 0) json_response(['success' => false, 'error' => 'invalid_chat']);

    // permission: global OR team member OR chat participant
    $allowed = false;
    $ctype   = null;
    $team_id = null;

    $stmt = $mysqli->prepare("SELECT type, team_id FROM chats WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $chat_id);
    $stmt->execute();
    $stmt->bind_result($ctype, $team_id);
    if ($stmt->fetch()) {
        if ($ctype === 'global') {
            $allowed = true;
        } elseif ($ctype === 'team' && $team_id) {
            // check team membership
            $stmt->close();
            $stmt = $mysqli->prepare("SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ? LIMIT 1");
            $stmt->bind_param('ii', $team_id, $user_id);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) $allowed = true;
        } else {
            $stmt->close();
            $stmt = $mysqli->prepare("SELECT 1 FROM chat_participants WHERE chat_id = ? AND user_id = ? LIMIT 1");
            $stmt->bind_param('ii', $chat_id, $user_id);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) $allowed = true;
        }
    }
    $stmt->close();

    if (!$allowed) json_response(['success' => false, 'error' => 'not_allowed']);

    if ($since_id > 0) {
        $sql = "
            SELECT cm.id, cm.sender_id, u.display_name, u.username, cm.message, cm.created_at
            FROM chat_messages cm
            LEFT JOIN users u ON u.id = cm.sender_id
            WHERE cm.chat_id = ? AND cm.id > ?
            ORDER BY cm.id ASC
        ";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('ii', $chat_id, $since_id);
    } elseif ($since) {
        $sql = "
            SELECT cm.id, cm.sender_id, u.display_name, u.username, cm.message, cm.created_at
            FROM chat_messages cm
            LEFT JOIN users u ON u.id = cm.sender_id
            WHERE cm.chat_id = ? AND cm.created_at > ?
            ORDER BY cm.created_at ASC
        ";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('is', $chat_id, $since);
    } else {
        $sql = "
            SELECT cm.id, cm.sender_id, u.display_name, u.username, cm.message, cm.created_at
            FROM chat_messages cm
            LEFT JOIN users u ON u.id = cm.sender_id
            WHERE cm.chat_id = ?
            ORDER BY cm.created_at ASC
        ";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('i', $chat_id);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $messages = [];
    while ($row = $res->fetch_assoc()) {
        $messages[] = [
            'id'           => (int)$row['id'],
            'sender_id'    => (int)$row['sender_id'],
            'display_name' => $row['display_name'] ?: $row['username'],
            'message'      => $row['message'],
            'created_at'   => $row['created_at'],
        ];
    }
    $stmt->close();

    json_response(['success' => true, 'messages' => $messages]);
}

/* ---------- 5) SEND MESSAGE ---------- */
if ($action === 'send_message' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $chat_id = (int)($_POST['chat_id'] ?? 0);
    $msg     = trim($_POST['message'] ?? '');
    $token   = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrf, $token)) json_response(['success' => false, 'error' => 'csrf']);
    if ($chat_id <= 0 || $msg === '') json_response(['success' => false, 'error' => 'invalid_input']);

    // permission: global OR team member OR chat participant
    $allowed = false;
    $ctype   = null;
    $team_id = null;

    $stmt = $mysqli->prepare("SELECT type, team_id FROM chats WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $chat_id);
    $stmt->execute();
    $stmt->bind_result($ctype, $team_id);
    if ($stmt->fetch()) {
        if ($ctype === 'global') {
            $allowed = true;
        } elseif ($ctype === 'team' && $team_id) {
            $stmt->close();
            $stmt = $mysqli->prepare("SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ? LIMIT 1");
            $stmt->bind_param('ii', $team_id, $user_id);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) $allowed = true;
        } else {
            $stmt->close();
            $stmt = $mysqli->prepare("SELECT 1 FROM chat_participants WHERE chat_id = ? AND user_id = ? LIMIT 1");
            $stmt->bind_param('ii', $chat_id, $user_id);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) $allowed = true;
        }
    }
    $stmt->close();

    if (!$allowed) json_response(['success' => false, 'error' => 'not_allowed']);

    $stmt = $mysqli->prepare("INSERT INTO chat_messages (chat_id, sender_id, message, created_at) VALUES (?, ?, ?, NOW())");
    if (!$stmt) json_response(['success' => false, 'error' => 'db_prepare']);
    $stmt->bind_param('iis', $chat_id, $user_id, $msg);
    $stmt->execute();
    $new_id = (int)$mysqli->insert_id;
    $stmt->close();

    // return DB timestamp to prevent duplicates
    $created_at = date('Y-m-d H:i:s');
    $stmt = $mysqli->prepare("SELECT created_at FROM chat_messages WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $new_id);
        $stmt->execute();
        $stmt->bind_result($db_created);
        if ($stmt->fetch()) $created_at = $db_created;
        $stmt->close();
    }

    json_response(['success' => true, 'id' => $new_id, 'created_at' => $created_at]);
}

/* ---------- NO ACTION: RENDER PAGE ---------- */
?>
<!DOCTYPE html>
<html lang="ar">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Squid Pro | Chat</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { margin:0; font-family:'Poppins',sans-serif; background:#0d1117; color:white; overflow:hidden; }
.bg-waves { position:fixed; inset:0; z-index:-3; background: radial-gradient(circle at 20% 20%, #1a237e, transparent 60%), radial-gradient(circle at 80% 80%, #8e24aa, transparent 60%), radial-gradient(circle at 50% 50%, #0d47a1, transparent 70%); filter: blur(90px); }
.sidebar { width:260px; height:100vh; background:rgba(0,0,0,0.55); backdrop-filter: blur(10px); position:fixed; left:0; top:0; padding:25px 20px; border-right:1px solid rgba(255,255,255,0.1); box-shadow:4px 0 20px rgba(0,0,0,0.4); }
.sidebar h2 { font-weight:800; font-size:1.9rem; background:linear-gradient(90deg,#0D6EFD,#8a2be2); -webkit-background-clip:text; color:transparent; text-align:center; margin-bottom:24px; }
.sidebar a { display:block; padding:12px 15px; margin-bottom:12px; border-radius:10px; color:white; text-decoration:none; font-size:1.05rem; transition:0.3s; }
.sidebar a:hover, .sidebar a.active { background:linear-gradient(135deg,#0D6EFD,#8a2be2); box-shadow:0 0 15px rgba(13,110,253,0.6); }
.chat-container { margin-left:260px; height:100vh; display:flex; }
.chats-list { width:320px; background:rgba(255,255,255,0.03); border-right:1px solid rgba(255,255,255,0.06); padding:18px; overflow-y:auto; }
.chat-item { padding:10px 12px; border-radius:10px; margin-bottom:8px; cursor:pointer; background:rgba(255,255,255,0.02); transition:0.15s; font-size:0.95rem; }
.chat-item:hover { background:rgba(255,255,255,0.08); }
.chat-item.active { background:linear-gradient(135deg,#0D6EFD,#8a2be2); color:#000; }
.chat-window { flex:1; display:flex; flex-direction:column; padding:18px; }
.messages { flex:1; overflow-y:auto; padding:12px; }
.msg { max-width:70%; padding:10px 14px; border-radius:12px; margin-bottom:10px; font-size:0.95rem; }
.msg.me { margin-left:auto; background:linear-gradient(135deg,#0D6EFD,#8a2be2); color:#000; }
.msg.other { background:rgba(255,255,255,0.06); }
.input-area { display:flex; gap:10px; margin-top:12px; }
.chat-input { flex:1; padding:12px; border-radius:10px; border:none; outline:none; background:rgba(255,255,255,0.06); color:#fff; }
.send-btn { background:linear-gradient(135deg,#0D6EFD,#8a2be2); border:none; padding:12px 20px; border-radius:10px; color:#000; font-weight:700; }
.small-muted { color:rgba(255,255,255,0.7); font-size:0.85rem; }
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

<div class="chat-container">
  <div class="chats-list" id="chatsList">
    <h5 class="fw-bold">Chats</h5>
    <div id="chatsPlaceholder" class="small-muted">Loading chats...</div>

    <h6 class="mt-4">Teammates</h6>
    <div id="usersList" class="small-muted">Loading teammates...</div>
  </div>

  <div class="chat-window">
    <div style="display:flex;justify-content:space-between;align-items:center;">
      <div>
        <h4 id="chatTitle">Choose chat</h4>
        <div id="chatSubtitle" class="small-muted">—</div>
      </div>
      <div>
        <span class="small-muted">User: <?php echo esc($display_name); ?></span>
      </div>
    </div>

    <div class="messages" id="messages"></div>

    <div class="input-area">
      <input id="chatInput" class="chat-input" placeholder="Write a message..." autocomplete="off" disabled>
      <button id="sendBtn" class="send-btn" disabled>Send</button>
    </div>
  </div>
</div>

<script>
const csrfToken = "<?php echo esc($csrf); ?>";
let currentChatId = null;
let lastFetchTime = "";
let lastFetchId = 0;
let isSending = false;

/* Escape HTML */
function escHtml(s) {
  if (!s) return "";
  return s.replace(/[&<>"'`=\/]/g, c => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;','/':'&#x2F;','`':'&#x60;','=':'&#x3D;'
  }[c]));
}

/* Load chats */
async function loadChats() {
  try {
    const res = await fetch("Chat.php?action=list_chats");
    const data = await res.json();
    const list = document.getElementById("chatsList");
    document.getElementById("chatsPlaceholder").style.display = "none";

    [...list.querySelectorAll(".chat-item.chat-entry")].forEach(e => e.remove());

    if (!data.success || !data.chats || !data.chats.length) {
      document.getElementById("chatsPlaceholder").textContent = "No chats.";
      return;
    }

    data.chats.forEach(c => {
      const div = document.createElement("div");
      div.className = "chat-item chat-entry";
      div.dataset.chatId = c.id;
      div.dataset.type = c.type || '';
      div.innerHTML = `<strong>${escHtml(c.title)}</strong><br><span class="small-muted">${escHtml(c.type)}</span>`;
      div.onclick = () => selectChat(c.id, c.title);
      list.insertBefore(div, document.getElementById("chatsPlaceholder").nextSibling);
    });

    if (!currentChatId && data.chats.length) {
      selectChat(data.chats[0].id, data.chats[0].title);
    }
  } catch (e) {
    console.error(e);
  }
}

/* Load teammates */
async function loadUsers() {
  try {
    const res = await fetch("Chat.php?action=list_users");
    const data = await res.json();
    const box = document.getElementById("usersList");
    box.innerHTML = "";

    if (!data.success || !data.users || !data.users.length) {
      box.textContent = "No teammates found.";
      return;
    }

    data.users.forEach(u => {
      const div = document.createElement("div");
      div.className = "chat-item";
      div.textContent = u.name;
      div.onclick = () => startPrivateChat(u.id, u.name);
      box.appendChild(div);
    });
  } catch (e) {
    console.error(e);
  }
}

/* Start private chat */
async function startPrivateChat(otherId, name) {
  try {
    const form = new FormData();
    form.append("action", "create_private_chat");
    form.append("other_id", otherId);

    const res = await fetch("Chat.php", { method: "POST", body: form });
    const data = await res.json();
    if (data.success) {
      await loadChats();
      selectChat(data.chat_id, name);
    } else {
      alert("Cannot start chat: " + (data.error || "error"));
    }
  } catch (e) {
    console.error(e);
  }
}

/* Select chat */
async function selectChat(chatId, title) {
  currentChatId = chatId;
  lastFetchTime = ""; // reset for this chat
  lastFetchId = 0;
  document.getElementById("chatTitle").textContent = title;
  document.getElementById("chatSubtitle").textContent = "";
  document.getElementById("chatInput").disabled = false;
  document.getElementById("sendBtn").disabled = false;
  document.getElementById("messages").innerHTML = "";

  document.querySelectorAll(".chat-item.chat-entry").forEach(el => {
    el.classList.toggle("active", el.dataset.chatId == chatId);
  });

  await fetchMessages();
}

/* Fetch messages */
async function fetchMessages() {
  if (!currentChatId) return;
  const url = "Chat.php?action=fetch_messages&chat_id=" + encodeURIComponent(currentChatId)
            + (lastFetchId ? "&since_id=" + encodeURIComponent(lastFetchId) : "");
  try {
    const res = await fetch(url);
    const data = await res.json();
    if (!data.success) return;
    const msgs = data.messages || [];
    if (!msgs.length) return;

    const box = document.getElementById("messages");
    msgs.forEach(m => {
      if (document.querySelector('.msg[data-id="' + m.id + '"]')) {
        return;
      }
      const div = document.createElement("div");
      const isMe = m.sender_id == <?php echo (int)$user_id; ?>;
      div.className = "msg " + (isMe ? "me" : "other");
      div.dataset.id = m.id;
      div.innerHTML = `
        <div style="font-weight:700;">${escHtml(m.display_name)}</div>
        <div style="margin-top:4px;">${escHtml(m.message)}</div>
        <div class="small-muted" style="margin-top:4px;">${escHtml(m.created_at)}</div>
      `;
      box.appendChild(div);
    });
    lastFetchTime = msgs[msgs.length - 1].created_at;
    lastFetchId = msgs[msgs.length - 1].id;
    box.scrollTop = box.scrollHeight;
  } catch (e) {
    console.error(e);
  }
}

/* Send message */
async function sendMessage() {
  const input = document.getElementById("chatInput");
  const text = input.value.trim();
  if (!text || !currentChatId) return;
  if (isSending) return;
  isSending = true;

  const form = new FormData();
  form.append("action", "send_message");
  form.append("chat_id", currentChatId);
  form.append("message", text);
  form.append("csrf_token", csrfToken);

  try {
    const res = await fetch("Chat.php", { method: "POST", body: form });
    const data = await res.json();
    if (!data.success) {
      alert("Failed to send: " + (data.error || "error"));
      isSending = false;
      return;
    }
    input.value = "";

    // append locally using server timestamp to avoid duplicates
    const box = document.getElementById("messages");
    const div = document.createElement("div");
    div.className = "msg me";
    const now = data.created_at || new Date().toISOString().slice(0,19).replace('T',' ');
    if (data.id) div.dataset.id = data.id;
    div.innerHTML = `
      <div style="font-weight:700;"><?php echo esc($display_name); ?></div>
      <div style="margin-top:4px;">${escHtml(text)}</div>
      <div class="small-muted" style="margin-top:4px;">${escHtml(now)}</div>
    `;
    box.appendChild(div);
    box.scrollTop = box.scrollHeight;

    lastFetchTime = now;
    if (data.id) lastFetchId = data.id;
  } catch (e) {
    console.error(e);
  } finally {
    isSending = false;
  }
}

/* Events */
document.getElementById("sendBtn").addEventListener("click", sendMessage);
document.getElementById("chatInput").addEventListener("keydown", e => {
  if (e.key === "Enter" && !e.shiftKey) {
    e.preventDefault();
    sendMessage();
  }
});

/* Initial load */
loadChats();
loadUsers();
setInterval(() => { if (currentChatId) fetchMessages(); }, 2000);
setInterval(loadChats, 30000);
</script>
</body>
</html>
