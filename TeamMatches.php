<?php
session_start();

/* DB CONFIG */
$db = new mysqli("localhost", "root", "", "squidpro");
$db->set_charset("utf8mb4");

function esc($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/* USER MUST BE LOGGED IN */
if (!isset($_SESSION['user_id'])) {
    header("Location: Login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

/* GET TEAM ID */
$team_id = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;
if ($team_id <= 0) die("Invalid team.");

/* CHECK IF USER IS MEMBER */
$stmt = $db->prepare("SELECT id FROM team_members WHERE team_id=? AND user_id=?");
$stmt->bind_param("ii", $team_id, $user_id);
$stmt->execute();
$is_member = $stmt->get_result()->num_rows > 0;
$stmt->close();

if (!$is_member) {
    die("<h2 style='color:white;text-align:center;margin-top:50px;'>You are not a member of this team.</h2>");
}

/* FETCH TEAM MEMBERS */
$members = [];
$q = $db->prepare("
    SELECT u.display_name 
    FROM team_members tm
    JOIN users u ON u.id = tm.user_id
    WHERE tm.team_id = ?
");
$q->bind_param("i", $team_id);
$q->execute();
$res = $q->get_result();
while($row = $res->fetch_assoc()) $members[] = $row;
$q->close();

/* GET CHAT ID FOR THIS TEAM */
$stmt = $db->prepare("SELECT id FROM chats WHERE team_id=? LIMIT 1");
$stmt->bind_param("i", $team_id);
$stmt->execute();
$chat = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$chat) {
    // Create chat if not exists
    $stmt = $db->prepare("INSERT INTO chats (type, team_id) VALUES ('team', ?)");
    $stmt->bind_param("i", $team_id);
    $stmt->execute();
    $chat_id = $stmt->insert_id;
    $stmt->close();
} else {
    $chat_id = $chat['id'];
}

/* HANDLE SEND MESSAGE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    $msg = trim($_POST['message']);
    if ($msg !== "") {
        $stmt = $db->prepare("
            INSERT INTO chat_messages (chat_id, sender_id, message)
            VALUES (?, ?, ?)
        ");
        $stmt->bind_param("iis", $chat_id, $user_id, $msg);
        $stmt->execute();
        $stmt->close();
    }
    exit; // AJAX response
}

/* FETCH MESSAGES */
$messages = [];
$stmt = $db->prepare("
    SELECT cm.*, u.display_name
    FROM chat_messages cm
    JOIN users u ON u.id = cm.sender_id
    WHERE cm.chat_id = ?
    ORDER BY cm.created_at ASC
");
$stmt->bind_param("i", $chat_id);
$stmt->execute();
$res = $stmt->get_result();
while($row = $res->fetch_assoc()) $messages[] = $row;
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>squid pro Hub | Team Chat</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">

<style>
/* نفس الهوية البصرية */
body{margin:0;font-family:'Poppins',sans-serif;background:#0d1117;color:white;overflow:hidden;}
.animated-bg{position:fixed;inset:0;z-index:-1;background:radial-gradient(circle at 20% 20%,#1a237e,transparent 60%),radial-gradient(circle at 80% 80%,#8e24aa,transparent 60%),radial-gradient(circle at 50% 50%,#0d47a1,transparent 70%);filter:blur(80px);animation:move 12s infinite alternate ease-in-out;}
@keyframes move{0%{transform:scale(1);}100%{transform:scale(1.3);}}
.chat-container{margin-top:120px;height:calc(100vh - 120px);display:flex;}
.members-list{width:260px;background:rgba(255,255,255,0.05);border-right:1px solid rgba(255,255,255,0.1);padding:20px;overflow-y:auto;}
.member{padding:12px;margin-bottom:10px;border-radius:12px;background:rgba(255,255,255,0.07);}
.chat-window{flex:1;display:flex;flex-direction:column;padding:25px;}
.messages{flex:1;overflow-y:auto;padding-right:10px;}
.msg{max-width:70%;padding:12px 16px;border-radius:14px;margin-bottom:12px;font-size:0.95rem;}
.msg-user{background:#0D6EFD;margin-left:auto;border-bottom-right-radius:0;}
.msg-other{background:rgba(255,255,255,0.1);border-bottom-left-radius:0;}
.input-area{display:flex;gap:10px;margin-top:15px;}
.chat-input{flex:1;padding:12px;border-radius:12px;border:none;background:rgba(255,255,255,0.1);color:white;}
.send-btn{background:linear-gradient(135deg,#0D6EFD,#8a2be2);border:none;padding:12px 25px;border-radius:12px;color:white;font-weight:600;}
</style>
</head>

<body>

<div class="animated-bg"></div>

<!-- Chat Layout -->
<div class="chat-container">

    <!-- Members List -->
    <div class="members-list">
        <h5 class="fw-bold mb-3">Team Members</h5>

        <?php foreach($members as $m): ?>
            <div class="member"><?= esc($m['display_name']) ?></div>
        <?php endforeach; ?>
    </div>

    <!-- Chat Window -->
    <div class="chat-window">

        <h4 class="fw-bold mb-3">Team Chat</h4>

        <div class="messages" id="messages">
            <?php foreach($messages as $msg): ?>
                <div class="msg <?= $msg['sender_id']==$user_id ? 'msg-user' : 'msg-other' ?>">
                    <strong><?= esc($msg['display_name']) ?>:</strong><br>
                    <?= esc($msg['message']) ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="input-area">
            <input type="text" id="chatInput" class="chat-input" placeholder="Type your message...">
            <button class="send-btn" id="sendBtn">Send</button>
        </div>

    </div>

</div>

<script>
const input = document.getElementById("chatInput");
const sendBtn = document.getElementById("sendBtn");
const messages = document.getElementById("messages");

function sendMessage() {
    const text = input.value.trim();
    if (!text) return;

    const formData = new FormData();
    formData.append("message", text);

    fetch("", { method: "POST", body: formData })
        .then(() => {
            input.value = "";
            loadMessages();
        });
}

function loadMessages() {
    fetch("TeamChat.php?team_id=<?= $team_id ?>&ajax=1")
        .then(res => res.text())
        .then(html => {
            messages.innerHTML = html;
            messages.scrollTop = messages.scrollHeight;
        });
}

sendBtn.addEventListener("click", sendMessage);
input.addEventListener("keydown", e => { if (e.key === "Enter") sendMessage(); });

setInterval(loadMessages, 2000);
</script>

</body>
</html>
