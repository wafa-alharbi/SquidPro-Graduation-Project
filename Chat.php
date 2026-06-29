<?php
session_start();



/* ---------- Configuration ---------- */
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








/* Read OpenAI API key from environment variable */
$openai_api_key = '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'; // set this in your server environment

/* Rate limiting: max requests per session per minute */
$RATE_LIMIT_PER_MIN = 10;

/* ---------- Helpers ---------- */
function esc($s) {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}
function json_response($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/* ---------- Authentication check ---------- */
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$user_id = (int) $_SESSION['user_id'];
$display_name = $_SESSION['display_name'] ?? $_SESSION['username'] ?? 'User';

/* ---------- CSRF token ---------- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}

/* ---------- Database connection ---------- */
$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo "Database connection failed.";
    exit;
}
$mysqli->set_charset('utf8mb4');

/* ---------- Ensure logs table exists ---------- */
$create_logs_sql = "
CREATE TABLE IF NOT EXISTS ai_coach_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  prompt TEXT NOT NULL,
  response TEXT NOT NULL,
  tokens_used INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";
$mysqli->query($create_logs_sql);

/* ---------- AJAX endpoint: ask_ai ---------- */
$action = $_REQUEST['action'] ?? '';
if ($action === 'ask_ai' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        json_response(['success' => false, 'error' => 'csrf']);
    }

    /* Session-based rate limiting */
    if (!isset($_SESSION['ai_requests'])) {
        $_SESSION['ai_requests'] = [];
    }
    $now = time();
    $_SESSION['ai_requests'] = array_filter($_SESSION['ai_requests'], function($t) use ($now) {
        return ($now - $t) < 60;
    });
    if (count($_SESSION['ai_requests']) >= $RATE_LIMIT_PER_MIN) {
        json_response(['success' => false, 'error' => 'rate_limited', 'message' => 'Too many requests, please wait.']);
    }

    $prompt = trim($_POST['prompt'] ?? '');
    if ($prompt === '') json_response(['success' => false, 'error' => 'empty_prompt']);

    if (empty($openai_api_key)) {
        json_response(['success' => false, 'error' => 'no_api_key', 'message' => 'OpenAI API key not configured on server.']);
    }

    /* Build messages for ChatCompletion */
    $system_msg = "You are a helpful AI coach for a hybrid esports and real-life sports platform. Provide concise, actionable training tips, drills, and strategy suggestions. Keep responses friendly and practical. Prefer Arabic if the user writes in Arabic, otherwise respond in the user's language.";
    $messages = [
        ['role' => 'system', 'content' => $system_msg],
        ['role' => 'user', 'content' => $prompt]
    ];

    /* Call OpenAI Chat Completions API */
    $api_url = "https://api.openai.com/v1/chat/completions";
    $payload = [
        'model' => 'gpt-4.1-nano', // change to a model available on your account
        'messages' => $messages,
        'max_tokens' => 500,
        'temperature' => 0.8,
        'n' => 1
    ];

    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer " . $openai_api_key
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $http_code >= 400) {
        json_response(['success' => false, 'error' => 'api_error', 'details' => $err ?: $resp]);
    }

    $data = json_decode($resp, true);
    if (!isset($data['choices'][0]['message']['content'])) {
        json_response(['success' => false, 'error' => 'invalid_response', 'raw' => $data]);
    }

    $ai_text = $data['choices'][0]['message']['content'];
    $tokens_used = $data['usage']['total_tokens'] ?? null;

    /* Save log to database */
    $ins = $mysqli->prepare("INSERT INTO ai_coach_logs (user_id, prompt, response, tokens_used) VALUES (?, ?, ?, ?)");
    if ($ins) {
        $ins->bind_param('issi', $user_id, $prompt, $ai_text, $tokens_used);
        $ins->execute();
        $ins->close();
    }

    /* record request timestamp for rate limiting */
    $_SESSION['ai_requests'][] = time();

    json_response(['success' => true, 'response' => $ai_text]);
}

/* ---------- Render HTML UI (English strings) ---------- */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Squid Pro | AI Coach</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">
<style>
  .navbar { background-color: rgba(0,0,0,0.55) !important; backdrop-filter: blur(6px); }
  .sidebar { width:260px; height:100vh; background:rgba(0,0,0,0.55); backdrop-filter:blur(10px); position:fixed; left:0; top:0; padding:25px 20px; border-right:1px solid rgba(255,255,255,0.1); }
  .sidebar h2 { font-weight:800; font-size:1.9rem; background:linear-gradient(90deg,#0D6EFD,#8a2be2); -webkit-background-clip:text; color:transparent; text-align:center; margin-bottom:24px; }
  .sidebar a { display:block; padding:12px 15px; margin-bottom:12px; border-radius:10px; color:white; text-decoration:none; font-size:1.05rem; transition:0.3s; }
  .sidebar a:hover, .sidebar a.active { background:linear-gradient(135deg,#0D6EFD,#8a2be2); box-shadow:0 0 15px rgba(13,110,253,0.6); }
 a.nav-link { color:#fff; display:block; padding:10px 0; text-decoration:none; }
  a.nav-link.active, a.nav-link:hover { background:linear-gradient(135deg,#0D6EFD,#8a2be2); color:#fff; border-radius:8px; padding-left:12px; }

  body { margin:0; font-family:'Poppins',sans-serif; background:#0d1117; color:white; overflow-x:hidden; }
  .animated-bg { position:fixed; inset:0; z-index:-3; background: radial-gradient(circle at 20% 20%, #1a237e, transparent 60%), radial-gradient(circle at 80% 80%, #8e24aa, transparent 60%), radial-gradient(circle at 50% 50%, #0d47a1, transparent 70%); filter: blur(80px); }
  .pulse-grid { position:fixed; inset:0; z-index:-2; background: repeating-linear-gradient(to bottom, rgba(255,255,255,0.03) 0px, rgba(255,255,255,0.03) 1px, transparent 2px, transparent 4px); }
  .scan-beam { position:fixed; top:0; left:0; width:100%; height:2px; background: linear-gradient(90deg, transparent, #0D6EFD, transparent); opacity:0.6; z-index:-1; }
  .logo-icon { width:55px; height:55px; background:linear-gradient(135deg,#0D6EFD,#8a2be2); border-radius:14px; display:flex; align-items:center; justify-content:center; font-weight:900; font-size:24px; color:white; box-shadow:0 0 25px rgba(13,110,253,0.9); }
  .sidebar { width:260px; height:100vh; background:rgba(0,0,0,0.55); backdrop-filter: blur(10px); position:fixed; left:0; top:0; padding:25px 20px; border-right:1px solid rgba(255,255,255,0.1); }
  .main { margin-left:280px; padding:40px; }
  .ai-holo-container { position:relative; width:260px; height:260px; margin:0 auto; perspective:800px; }
  .ai-holo { width:100%; height:100%; border-radius:50%; background: radial-gradient(circle, #0D6EFD, #8a2be2); box-shadow:0 0 40px rgba(13,110,253,0.8); transform-style:preserve-3d; transition: transform 0.15s ease-out; }
  .ring { position:absolute; inset:-20px; border-radius:50%; border:3px solid rgba(13,110,253,0.4); }
  .chat-box { margin-top:50px; background: rgba(255,255,255,0.05); border-radius:18px; padding:25px; border:1px solid rgba(255,255,255,0.08); backdrop-filter: blur(6px); }
  .chat-messages { max-height:320px; overflow-y:auto; margin-bottom:15px; }
  .msg { padding:10px 14px; border-radius:14px; margin-bottom:8px; font-size:0.95rem; }
  .msg-user { background: rgba(13,110,253,0.3); text-align:right; }
  .msg-ai { background: rgba(255,255,255,0.06); border-left:3px solid #0D6EFD; }
  .chat-input { width:100%; padding:12px; border-radius:12px; border:none; outline:none; background:rgba(255,255,255,0.1); color:white; }
  .send-btn { margin-top:15px; padding:10px 35px; border-radius:50px; background:linear-gradient(135deg,#0D6EFD,#8a2be2); border:none; color:white; box-shadow:0 0 15px rgba(13,110,253,0.7); }
</style>
</head>
<body>
  <div class="animated-bg"></div>
  <div class="pulse-grid"></div>
  <div class="scan-beam"></div>

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

  <div class="main container" style="margin-left:240px">
    <h1 class="page-title">AI Smart Coach</h1>
    <p class="page-subtitle">Your smart coach for esports and real-life sports improvement.</p>

    <div class="row align-items-center mt-4">
      <div class="col-md-5 text-center">
        <div class="ai-holo-container" id="holoContainer">
          <div class="ring"></div>
          <div class="ring"></div>
          <div class="ai-holo" id="aiHolo"></div>
        </div>
      </div>
      <div class="col-md-7">
        <h3 class="fw-bold">Personal Squid Pro Mentor</h3>
        <p style="opacity:0.85;">The coach analyzes match history, suggests drills, and gives tactical advice.</p>
      </div>
    </div>

    <div class="chat-box mt-5">
      <h4 class="mb-3">Ask the AI Coach</h4>
      <div class="chat-messages" id="chatMessages">
        <div class="msg msg-ai">Hello, I am your AI Coach. Ask about aim, strategy, teamwork, or training.</div>
      </div>

      <div style="display:flex;gap:10px;">
        <input id="chatInput" class="chat-input" placeholder="Type your question here..." autocomplete="off">
        <button id="sendBtn" class="send-btn">Send</button>
      </div>
    </div>
  </div>

<script>
  // Hologram parallax effect
  const holoContainer = document.getElementById('holoContainer');
  const aiHolo = document.getElementById('aiHolo');
  holoContainer.addEventListener('mousemove', (e) => {
    const rect = holoContainer.getBoundingClientRect();
    const x = e.clientX - rect.left - rect.width / 2;
    const y = e.clientY - rect.top - rect.height / 2;
    const rotateX = (y / rect.height) * -15;
    const rotateY = (x / rect.width) * 15;
    aiHolo.style.transform = `rotateX(${rotateX}deg) rotateY(${rotateY}deg) scale(1.05)`;
  });
  holoContainer.addEventListener('mouseleave', () => {
    aiHolo.style.transform = 'rotateX(0deg) rotateY(0deg) scale(1)';
  });

  // Chat interaction with server-side OpenAI call
  const chatInput = document.getElementById('chatInput');
  const sendBtn = document.getElementById('sendBtn');
  const chatMessages = document.getElementById('chatMessages');
  const csrfToken = '<?php echo esc($_SESSION['csrf_token']); ?>';

  function appendMessage(text, cls) {
    const div = document.createElement('div');
    div.className = 'msg ' + cls;
    div.textContent = text;
    chatMessages.appendChild(div);
    chatMessages.scrollTop = chatMessages.scrollHeight;
  }

  async function askAI() {
    const text = chatInput.value.trim();
    if (!text) return;
    appendMessage(text, 'msg-user');
    chatInput.value = '';
    appendMessage('AI coach is thinking...', 'msg-ai');

    const form = new FormData();
    form.append('action', 'ask_ai');
    form.append('prompt', text);
    form.append('csrf_token', csrfToken);

    try {
      const res = await fetch('AI-Coach.php', { method: 'POST', body: form });
      const data = await res.json();
      // remove the last AI placeholder
      const aiPlaceholders = Array.from(document.querySelectorAll('.msg-ai'));
      if (aiPlaceholders.length) {
        aiPlaceholders[aiPlaceholders.length - 1].remove();
      }
      if (data.success) {
        appendMessage(data.response, 'msg-ai');
      } else {
        appendMessage('Error: ' + (data.error || 'Unknown error'), 'msg-ai');
      }
    } catch (err) {
      appendMessage('Server connection error.', 'msg-ai');
      console.error(err);
    }
  }

  sendBtn.addEventListener('click', askAI);
  chatInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') askAI();
  });
</script>
</body>
</html>
