<?php
session_start();

$db = new mysqli("localhost", "root", "", "SquidPro");
$db->set_charset("utf8mb4");

function esc($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'player';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}

/* load games */
$games = [];
$res = $db->query("SELECT id, name FROM games WHERE is_active = 1 ORDER BY name ASC");
while ($row = $res->fetch_assoc()) $games[] = $row;

/* helper: check if user is member of any team */
$stmt = $db->prepare("SELECT team_id FROM team_members WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($user_team_id);
$user_is_in_any_team = false;
if ($stmt->fetch()) {
    $user_is_in_any_team = (int)$user_team_id > 0;
}
$stmt->close();

/* actions */
$errors = [];
$success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = "Invalid CSRF token.";
    } else {

        $action = $_POST['action'] ?? '';

        /* CREATE TEAM: allowed if user is not in any team OR user is organizer/admin */
        if ($action === "create" && (in_array($role, ['organizer','admin']) || !$user_is_in_any_team)) {

            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $game_id = (int)($_POST['game_id'] ?? 0);
            $captain_id = $user_id;

            if ($name === "" || $game_id <= 0) {
                $errors[] = "Team name and game are required.";
            }

            if ($user_is_in_any_team && !in_array($role, ['organizer','admin'])) {
                $errors[] = "You must leave your current team before creating a new one.";
            }

            if (empty($errors)) {
                $db->begin_transaction();
                try {
                    $stmt = $db->prepare("
                        INSERT INTO teams (name, game_id, description, owner_id, captain_id, created_at)
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->bind_param("sisii", $name, $game_id, $description, $user_id, $captain_id);
                    if (!$stmt->execute()) {
                        throw new Exception("DB insert team failed: " . $stmt->error);
                    }
                    $team_id = (int)$db->insert_id;
                    $stmt->close();

                    // add creator as captain member
                    $stmt = $db->prepare("INSERT INTO team_members (team_id, user_id, role, joined_at) VALUES (?, ?, 'captain', NOW())");
                    $stmt->bind_param("ii", $team_id, $captain_id);
                    if (!$stmt->execute()) {
                        throw new Exception("DB insert team_member failed: " . $stmt->error);
                    }
                    $stmt->close();

                    // create team chat and add participants (initially only captain)
                    $stmt = $db->prepare("INSERT INTO chats (type, team_id, created_at) VALUES ('team', ?, NOW())");
                    $stmt->bind_param("i", $team_id);
                    if (!$stmt->execute()) {
                        throw new Exception("DB insert chat failed: " . $stmt->error);
                    }
                    $chat_id = (int)$db->insert_id;
                    $stmt->close();

                    $stmt = $db->prepare("INSERT INTO chat_participants (chat_id, user_id, joined_at) VALUES (?, ?, NOW())");
                    $stmt->bind_param("ii", $chat_id, $captain_id);
                    if (!$stmt->execute()) {
                        throw new Exception("DB insert chat_participant failed: " . $stmt->error);
                    }
                    $stmt->close();

                    $db->commit();
                    $success = "Team created successfully.";
                    // refresh user team flag
                    $user_is_in_any_team = true;
                } catch (Exception $e) {
                    $db->rollback();
                    $errors[] = "Failed to create team.";
                }
            }
        }

        /* EDIT TEAM (admin/organizer) */
        elseif ($action === "edit" && in_array($role, ['organizer','admin'])) {

            $team_id = (int)($_POST['team_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $game_id = (int)($_POST['game_id'] ?? 0);

            if ($team_id <= 0 || $name === "" || $game_id <= 0) {
                $errors[] = "Invalid data.";
            }

            if (empty($errors)) {
                $stmt = $db->prepare("UPDATE teams SET name=?, game_id=?, description=? WHERE id=?");
                $stmt->bind_param("sisi", $name, $game_id, $description, $team_id);
                if ($stmt->execute()) {
                    $success = "Team updated successfully.";
                } else {
                    $errors[] = "Failed to update team.";
                }
                $stmt->close();
            }
        }

        /* DELETE TEAM (admin/organizer) */
        elseif ($action === "delete" && in_array($role, ['organizer','admin'])) {
            $team_id = (int)($_POST['team_id'] ?? 0);
            if ($team_id > 0) {
                $db->begin_transaction();
                try {
                    $stmt = $db->prepare("DELETE FROM team_members WHERE team_id = ?");
                    $stmt->bind_param("i", $team_id);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $db->prepare("DELETE FROM chats WHERE team_id = ?");
                    $stmt->bind_param("i", $team_id);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $db->prepare("DELETE FROM teams WHERE id = ?");
                    $stmt->bind_param("i", $team_id);
                    $stmt->execute();
                    $stmt->close();

                    $db->commit();
                    $success = "Team deleted successfully.";
                } catch (Exception $e) {
                    $db->rollback();
                    $errors[] = "Failed to delete team.";
                }
            } else {
                $errors[] = "Invalid team id.";
            }
        }

        /* JOIN TEAM: only if team has less than 3 members and user not in any team */
        elseif ($action === "join") {

            $team_id = (int)($_POST['team_id'] ?? 0);

            // check user not already in any team
            $chk = $db->prepare("SELECT 1 FROM team_members WHERE user_id = ? LIMIT 1");
            $chk->bind_param("i", $user_id);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) {
                $errors[] = "You are already a member of a team.";
                $chk->close();
            } else {
                $chk->close();

                // check team size
                $stmt = $db->prepare("SELECT COUNT(*) FROM team_members WHERE team_id = ?");
                $stmt->bind_param("i", $team_id);
                $stmt->execute();
                $stmt->bind_result($count);
                $stmt->fetch();
                $stmt->close();

                if ($count >= 3) {
                    $errors[] = "This team is full (maximum 3 members).";
                } else {
                    $ins = $db->prepare("INSERT INTO team_members (team_id, user_id, role, joined_at) VALUES (?, ?, 'member', NOW())");
                    $ins->bind_param("ii", $team_id, $user_id);
                    if ($ins->execute()) {
                        $success = "You joined the team.";
                        // ensure team chat exists and add participant
                        $ins->close();
                        $stmt = $db->prepare("SELECT id FROM chats WHERE type='team' AND team_id = ? LIMIT 1");
                        $stmt->bind_param("i", $team_id);
                        $stmt->execute();
                        $stmt->bind_result($team_chat_id);
                        if ($stmt->fetch()) {
                            $stmt->close();
                            // add participant if not exists
                            $chk = $db->prepare("SELECT 1 FROM chat_participants WHERE chat_id = ? AND user_id = ? LIMIT 1");
                            $chk->bind_param("ii", $team_chat_id, $user_id);
                            $chk->execute();
                            $chk->store_result();
                            if ($chk->num_rows === 0) {
                                $chk->close();
                                $ins2 = $db->prepare("INSERT INTO chat_participants (chat_id, user_id, joined_at) VALUES (?, ?, NOW())");
                                $ins2->bind_param("ii", $team_chat_id, $user_id);
                                $ins2->execute();
                                $ins2->close();
                            } else {
                                $chk->close();
                            }
                        } else {
                            $stmt->close();
                            // create chat and add participants
                            $insc = $db->prepare("INSERT INTO chats (type, team_id, created_at) VALUES ('team', ?, NOW())");
                            $insc->bind_param("i", $team_id);
                            $insc->execute();
                            $new_chat_id = (int)$db->insert_id;
                            $insc->close();

                            // add all current team members to chat_participants
                            $sel = $db->prepare("SELECT user_id FROM team_members WHERE team_id = ?");
                            $sel->bind_param("i", $team_id);
                            $sel->execute();
                            $resm = $sel->get_result();
                            $stmtIns = $db->prepare("INSERT INTO chat_participants (chat_id, user_id, joined_at) VALUES (?, ?, NOW())");
                            while ($r = $resm->fetch_assoc()) {
                                $uid = (int)$r['user_id'];
                                $stmtIns->bind_param("ii", $new_chat_id, $uid);
                                $stmtIns->execute();
                            }
                            $stmtIns->close();
                            $sel->close();
                        }
                    } else {
                        $errors[] = "Failed to join team.";
                    }
                }
            }
        }

        /* LEAVE TEAM */
        elseif ($action === "leave") {

            $team_id = (int)($_POST['team_id'] ?? 0);

            // remove from team_members
            $del = $db->prepare("DELETE FROM team_members WHERE team_id = ? AND user_id = ?");
            $del->bind_param("ii", $team_id, $user_id);
            if ($del->execute()) {
                $success = "You left the team.";
                // remove from chat_participants if chat exists
                $del->close();
                $stmt = $db->prepare("SELECT id FROM chats WHERE type='team' AND team_id = ? LIMIT 1");
                $stmt->bind_param("i", $team_id);
                $stmt->execute();
                $stmt->bind_result($team_chat_id);
                if ($stmt->fetch()) {
                    $stmt->close();
                    $del2 = $db->prepare("DELETE FROM chat_participants WHERE chat_id = ? AND user_id = ?");
                    $del2->bind_param("ii", $team_chat_id, $user_id);
                    $del2->execute();
                    $del2->close();
                } else {
                    $stmt->close();
                }
                // if user was captain, optionally transfer captain or leave as is (no transfer here)
            } else {
                $errors[] = "Failed to leave team.";
            }
        }
    }
}

/* LOAD TEAMS */
$search = trim($_GET['q'] ?? '');
$teams = [];

if ($search === "") {
    $sql = "
        SELECT t.*, COUNT(tm.user_id) AS members
        FROM teams t
        LEFT JOIN team_members tm ON tm.team_id = t.id
        GROUP BY t.id
        ORDER BY members DESC, t.name ASC
    ";
    $res = $db->query($sql);
} else {
    $stmt = $db->prepare("
        SELECT t.*, COUNT(tm.user_id) AS members
        FROM teams t
        LEFT JOIN team_members tm ON tm.team_id = t.id
        WHERE t.name LIKE CONCAT('%', ?, '%')
        GROUP BY t.id
        ORDER BY members DESC, t.name ASC
    ");
    $stmt->bind_param("s", $search);
    $stmt->execute();
    $res = $stmt->get_result();
}

while ($row = $res->fetch_assoc()) $teams[] = $row;

/* CHECK MEMBERSHIP PER TEAM */
$membership = [];
foreach ($teams as $t) {
    $tid = $t['id'];
    $chk = $db->prepare("SELECT role FROM team_members WHERE team_id=? AND user_id=? LIMIT 1");
    $chk->bind_param("ii", $tid, $user_id);
    $chk->execute();
    $chk->bind_result($role_in_team);
    $is_member = false;
    $role_in_team_val = null;
    if ($chk->fetch()) {
        $is_member = true;
        $role_in_team_val = $role_in_team;
    }
    $chk->close();
    $membership[$tid] = ['is_member' => $is_member, 'role' => $role_in_team_val];
}

?>
<!DOCTYPE html>
<html lang="ar">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Squid Pro | Teams</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">
<style>
  :root { --accent1:#0D6EFD; --accent2:#8a2be2; }
  body { margin:0; font-family:'Poppins',sans-serif; background:#0d1117; color:#fff; }
  .animated-bg { position:fixed; inset:0; z-index:-1; background: radial-gradient(circle at 20% 20%, #1a237e, transparent 60%), radial-gradient(circle at 80% 80%, #8e24aa, transparent 60%), radial-gradient(circle at 50% 50%, #0d47a1, transparent 70%); filter: blur(80px); }
  .logo-icon { width:55px;height:55px;background:linear-gradient(135deg,var(--accent1),var(--accent2));border-radius:14px;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:24px;color:#fff;box-shadow:0 0 25px rgba(13,110,253,0.9); }
  .navbar { background-color: rgba(0,0,0,0.55) !important; backdrop-filter: blur(6px); }
  .page-title { margin-top:100px; text-align:center; font-size:2.6rem; font-weight:800; }
  .team-card { background: rgba(255,255,255,0.04); border-radius:14px; padding:18px; border:1px solid rgba(255,255,255,0.06); transition:0.25s; height:100%; display:flex; flex-direction:column; justify-content:space-between; }
  .team-card:hover { transform:translateY(-8px); box-shadow:0 0 25px rgba(13,110,253,0.25); border-color:var(--accent1); }
  .team-icon { font-size:48px; margin-bottom:12px; color:var(--accent1); }
  .btn-join { background:linear-gradient(135deg,var(--accent1),var(--accent2)); border:none; color:#fff; border-radius:999px; padding:8px 18px; }
  .btn-outline { border-radius:999px; padding:8px 18px; color:#fff; border:1px solid rgba(255,255,255,0.08); background:transparent; }
  .muted { color:rgba(255,255,255,0.6); }
</style>
</head>

<nav class="navbar navbar-expand-lg navbar-dark fixed-top shadow-sm">
    <div class="container">
        <div class="d-flex align-items-center">
            <div class="logo-icon me-3">S</div>
            <a class="navbar-brand fw-bold fs-4" href="index.php">squid pro</a>
        </div>

        <button class="navbar-toggler" data-bs-toggle="collapse" data-bs-target="#navMenu">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item"><a class="nav-link" href="index.php">Home</a></li>
                <li class="nav-item"><a class="nav-link" href="Games.php">Games</a></li>
                <li class="nav-item"><a class="nav-link active" href="Teams.php">Teams</a></li>
                <li class="nav-item"><a class="nav-link" href="Tournaments.php">Tournaments</a></li>
                <li class="nav-item"><a class="nav-link" href="AI-Coach.php">AI Coach</a></li>
            </ul>

            <div class="ms-3">
                <a href="Profile.php" class="btn btn-outline-light me-2">Profile</a>
                <a href="Logout.php" class="btn btn-outline-light">Logout</a>
            </div>
        </div>
    </div>
</nav>

<div class="animated-bg"></div>

<div class="container">
    <h1 class="page-title">Teams & Squads</h1>
    <p class="text-center muted">Browse teams, join squads, or create your own team.</p>

    <div class="d-flex justify-content-between align-items-center mt-3">
        <form method="GET" class="d-flex gap-2">
            <input name="q" class="form-control" placeholder="Search teams..." value="<?php echo esc($search); ?>">
            <button class="btn btn-primary">Search</button>
        </form>

        <?php if (in_array($role, ['organizer','admin']) || !$user_is_in_any_team): ?>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTeamModal">Create Team</button>
        <?php endif; ?>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success mt-3"><?php echo esc($success); ?></div>
    <?php endif; ?>

    <?php foreach ($errors as $e): ?>
        <div class="alert alert-danger mt-2"><?php echo esc($e); ?></div>
    <?php endforeach; ?>

    <div class="row g-4 mt-4">
        <?php if (empty($teams)): ?>
            <div class="col-12 text-center muted">No teams found.</div>
        <?php endif; ?>

        <?php foreach ($teams as $t): $tid = (int)$t['id']; $is_member = $membership[$tid]['is_member']; $role_in_team = $membership[$tid]['role']; ?>
        <div class="col-sm-6 col-md-4 col-lg-3">
            <div class="team-card">
                <div>
                    <div class="team-icon"><?php echo esc($t['icon'] ?: "🛡️"); ?></div>
                    <h4><?php echo esc($t['name']); ?></h4>
                    <p class="muted"><?php echo esc($t['description']); ?></p>
                    <p class="muted"><?php echo (int)$t['members']; ?> members</p>
                    <?php if ($is_member): ?>
                        <div class="muted">You are a member<?php if ($role_in_team) echo " ({$role_in_team})"; ?>.</div>
                    <?php endif; ?>
                </div>

                <div class="d-flex gap-2 mt-3">
                    <a href="TeamDetails.php?id=<?php echo $tid; ?>" class="btn btn-outline w-100">View</a>

                    <?php
                        // show Join if not member, team not full (<3), and user not in any team
                        $team_full = ((int)$t['members'] >= 3);
                        $can_join = !$is_member && !$team_full && !$user_is_in_any_team;
                    ?>

                    <?php if ($is_member): ?>
                        <form method="POST" class="w-100">
                            <input type="hidden" name="csrf_token" value="<?php echo esc($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="leave">
                            <input type="hidden" name="team_id" value="<?php echo $tid; ?>">
                            <button class="btn btn-outline w-100">Leave</button>
                        </form>
                    <?php else: ?>
                        <?php if ($can_join): ?>
                            <form method="POST" class="w-100">
                                <input type="hidden" name="csrf_token" value="<?php echo esc($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="join">
                                <input type="hidden" name="team_id" value="<?php echo $tid; ?>">
                                <button class="btn btn-join w-100">Join</button>
                            </form>
                        <?php else: ?>
                            <?php if ($team_full): ?>
                                <button class="btn btn-outline w-100" disabled>Full</button>
                            <?php else: ?>
                                <button class="btn btn-outline w-100" disabled>Cannot join</button>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php if (in_array($role, ['organizer','admin'])): ?>
                <div class="d-flex gap-2 mt-2">
                    <button class="btn btn-outline w-100" data-bs-toggle="modal" data-bs-target="#editTeam<?php echo $tid; ?>">Edit</button>

                    <form method="POST" class="w-100" onsubmit="return confirm('Delete this team?');">
                        <input type="hidden" name="csrf_token" value="<?php echo esc($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="team_id" value="<?php echo $tid; ?>">
                        <button class="btn btn-outline w-100">Delete</button>
                    </form>
                </div>

                <div class="modal fade" id="editTeam<?php echo $tid; ?>">
                    <div class="modal-dialog modal-lg modal-dialog-centered">
                        <div class="modal-content" style="background:#0d1117;color:white;">
                            <div class="modal-header">
                                <h5>Edit Team</h5>
                                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <form method="POST" id="editForm<?php echo $tid; ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo esc($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="action" value="edit">
                                    <input type="hidden" name="team_id" value="<?php echo $tid; ?>">

                                    <label>Team Name</label>
                                    <input name="name" class="form-control" value="<?php echo esc($t['name']); ?>">

                                    <label class="mt-3">Game</label>
                                    <select name="game_id" class="form-control">
                                        <?php foreach ($games as $g): ?>
                                            <option value="<?php echo $g['id']; ?>" <?php if ($g['id']==$t['game_id']) echo "selected"; ?>>
                                                <?php echo esc($g['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <label class="mt-3">Description</label>
                                    <textarea name="description" class="form-control"><?php echo esc($t['description']); ?></textarea>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
                                <button class="btn btn-primary" form="editForm<?php echo $tid; ?>">Save</button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php if (in_array($role, ['organizer','admin']) || !$user_is_in_any_team): ?>
<div class="modal fade" id="createTeamModal">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="background:#0d1117;color:white;">
            <div class="modal-header">
                <h5>Create Team</h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="createTeamForm">
                    <input type="hidden" name="csrf_token" value="<?php echo esc($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="action" value="create">

                    <label>Team Name</label>
                    <input name="name" class="form-control" required>

                    <label class="mt-3">Game *</label>
                    <select name="game_id" class="form-control" required>
                        <option value="">Select a game...</option>
                        <?php foreach ($games as $g): ?>
                            <option value="<?php echo $g['id']; ?>"><?php echo esc($g['name']); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label class="mt-3">Description</label>
                    <textarea name="description" class="form-control" rows="4"></textarea>
                </form>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="createTeamForm" class="btn btn-primary">Create Team</button>
            </div>

        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
