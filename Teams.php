<?php
session_start();

/* DB CONFIG */
$db = new mysqli("localhost", "root", "", "squidpro");
$db->set_charset("utf8mb4");

function esc($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* AUTH */
if (empty($_SESSION['user_id'])) {
    header("Location: Login.php");
    exit;
}
$user_id = (int)$_SESSION['user_id'];
$user_display = $_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Player';

/* CSRF */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['csrf_token'];

/* GET TEAM ID */
$team_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($team_id <= 0) die("Invalid team.");

/* FETCH TEAM */
$stmt = $db->prepare("SELECT id, name, description, owner_id, captain_id FROM teams WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $team_id);
$stmt->execute();
$team = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$team) die("Team not found.");

/* CHECK USER ROLE IN TEAM */
$stmt = $db->prepare("SELECT role FROM team_members WHERE team_id = ? AND user_id = ? LIMIT 1");
$stmt->bind_param("ii", $team_id, $user_id);
$stmt->execute();
$role_row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$user_role = $role_row['role'] ?? null; // null = not member
$is_owner   = ((int)$team['owner_id'] === $user_id);
$is_captain = ($user_role === 'captain' || $user_role === 'co-captain' || $is_owner);
$is_member  = ($user_role === 'member' || $is_captain || $is_owner);

/* FETCH TEAM MEMBERS */
$members = [];
$q = $db->prepare("
    SELECT tm.user_id, tm.role, u.display_name
    FROM team_members tm
    JOIN users u ON u.id = tm.user_id
    WHERE tm.team_id = ?
    ORDER BY FIELD(tm.role, 'owner','captain','co-captain','member') ASC, u.display_name ASC
");
$q->bind_param("i", $team_id);
$q->execute();
$res = $q->get_result();
while($row = $res->fetch_assoc()) $members[] = $row;
$q->close();

/* FETCH JOIN REQUESTS (captain/owner only) */
$requests = [];
if ($is_captain) {
    $q = $db->prepare("
        SELECT r.user_id, r.message, r.created_at, u.display_name
        FROM team_join_requests r
        JOIN users u ON u.id = r.user_id
        WHERE r.team_id = ? AND r.status = 'pending'
        ORDER BY r.created_at ASC
    ");
    $q->bind_param("i", $team_id);
    $q->execute();
    $requests = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    $q->close();
}

/* FETCH PENDING MATCH INVITES (for this team) */
$pending_matches = [];
$q = $db->prepare("
    SELECT m.id, m.organizer_id, m.opponent_team_id, m.scheduled_at, m.status, u.display_name AS organizer_name, t2.name AS opponent_name
    FROM matches m
    LEFT JOIN users u ON u.id = m.organizer_id
    LEFT JOIN teams t2 ON t2.id = m.opponent_team_id
    WHERE m.team_id = ? AND m.status = 'pending'
    ORDER BY m.scheduled_at ASC, m.created_at ASC
");
$q->bind_param("i", $team_id);
$q->execute();
$pending_matches = $q->get_result()->fetch_all(MYSQLI_ASSOC);
$q->close();

/* FETCH PAST MATCHES (finished) and show results */
$past_matches = [];
$q = $db->prepare("
    SELECT m.id, m.team_id, m.opponent_team_id, m.team_score, m.opponent_score, m.scheduled_at,
           t1.name AS team_name, t2.name AS opponent_name
    FROM matches m
    LEFT JOIN teams t1 ON t1.id = m.team_id
    LEFT JOIN teams t2 ON t2.id = m.opponent_team_id
    WHERE (m.team_id = ? OR m.opponent_team_id = ?) AND m.status = 'finished'
    ORDER BY m.scheduled_at DESC
    LIMIT 50
");
$q->bind_param("ii", $team_id, $team_id);
$q->execute();
$res = $q->get_result();
while ($row = $res->fetch_assoc()) {
    // determine result from perspective of $team_id
    $team_score = (int)$row['team_score'];
    $opp_score  = (int)$row['opponent_score'];
    if ($row['team_id'] == $team_id) {
        $our_score = $team_score;
        $their_score = $opp_score;
        $our_name = $row['team_name'];
        $their_name = $row['opponent_name'] ?? 'Opponent';
    } else {
        // if this team is stored as opponent_team_id, swap
        $our_score = $opp_score;
        $their_score = $team_score;
        $our_name = $row['opponent_name'] ?? 'Opponent';
        $their_name = $row['team_name'];
    }
    if ($our_score > $their_score) $result = 'Win';
    elseif ($our_score < $their_score) $result = 'Loss';
    else $result = 'Draw';

    $past_matches[] = [
        'id' => (int)$row['id'],
        'our_name' => $our_name,
        'their_name' => $their_name,
        'our_score' => $our_score,
        'their_score' => $their_score,
        'scheduled_at' => $row['scheduled_at'],
        'result' => $result
    ];
}
$q->close();

/* HANDLE ACTIONS */
$errors = [];
$success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $errors[] = "Invalid CSRF token.";
    } else {
        $action = $_POST['action'] ?? '';

        /* Approve Join Request */
        if ($action === 'approve' && $is_captain) {
            $req_user = (int)($_POST['user_id'] ?? 0);
            if ($req_user <= 0) $errors[] = "Invalid user.";
            if (empty($errors)) {
                // check capacity
                $stmt = $db->prepare("SELECT COUNT(*) FROM team_members WHERE team_id = ?");
                $stmt->bind_param("i", $team_id);
                $stmt->execute();
                $stmt->bind_result($count);
                $stmt->fetch();
                $stmt->close();

                if ($count >= 3) {
                    $errors[] = "Team is full. Cannot approve more members.";
                } else {
                    $db->begin_transaction();
                    try {
                        $u = $db->prepare("UPDATE team_join_requests SET status='approved', processed_at = NOW(), processed_by = ? WHERE team_id = ? AND user_id = ? AND status = 'pending'");
                        $u->bind_param("iii", $user_id, $team_id, $req_user);
                        $u->execute();
                        $u->close();

                        $chk = $db->prepare("SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ? LIMIT 1");
                        $chk->bind_param("ii", $team_id, $req_user);
                        $chk->execute();
                        $chk->store_result();
                        if ($chk->num_rows === 0) {
                            $chk->close();
                            $ins = $db->prepare("INSERT INTO team_members (team_id, user_id, role, joined_at) VALUES (?, ?, 'member', NOW())");
                            $ins->bind_param("ii", $team_id, $req_user);
                            $ins->execute();
                            $ins->close();

                            // ensure team chat exists and add participant
                            $stmt = $db->prepare("SELECT id FROM chats WHERE type='team' AND team_id = ? LIMIT 1");
                            $stmt->bind_param("i", $team_id);
                            $stmt->execute();
                            $stmt->bind_result($team_chat_id);
                            if ($stmt->fetch()) {
                                $stmt->close();
                                $chk2 = $db->prepare("SELECT 1 FROM chat_participants WHERE chat_id = ? AND user_id = ? LIMIT 1");
                                $chk2->bind_param("ii", $team_chat_id, $req_user);
                                $chk2->execute();
                                $chk2->store_result();
                                if ($chk2->num_rows === 0) {
                                    $chk2->close();
                                    $ins2 = $db->prepare("INSERT INTO chat_participants (chat_id, user_id, joined_at) VALUES (?, ?, NOW())");
                                    $ins2->bind_param("ii", $team_chat_id, $req_user);
                                    $ins2->execute();
                                    $ins2->close();
                                } else {
                                    $chk2->close();
                                }
                            } else {
                                $stmt->close();
                                // create chat and add all members
                                $insc = $db->prepare("INSERT INTO chats (type, team_id, created_at) VALUES ('team', ?, NOW())");
                                $insc->bind_param("i", $team_id);
                                $insc->execute();
                                $new_chat_id = (int)$db->insert_id;
                                $insc->close();

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
                            $chk->close();
                        }

                        $db->commit();
                        $success = "Join request approved.";
                    } catch (Exception $e) {
                        $db->rollback();
                        $errors[] = "Failed to approve request.";
                    }
                }
            }
        }

        /* Reject Join Request */
        if ($action === 'reject' && $is_captain) {
            $req_user = (int)($_POST['user_id'] ?? 0);
            if ($req_user > 0) {
                $stmt = $db->prepare("UPDATE team_join_requests SET status='rejected', processed_at = NOW(), processed_by = ? WHERE team_id = ? AND user_id = ? AND status = 'pending'");
                $stmt->bind_param("iii", $user_id, $team_id, $req_user);
                $stmt->execute();
                $stmt->close();
                $success = "Join request rejected.";
            } else {
                $errors[] = "Invalid user.";
            }
        }

        /* Accept Match Invite */
        if ($action === 'accept_match' && $is_captain) {
            $match_id = (int)($_POST['match_id'] ?? 0);
            if ($match_id > 0) {
                $stmt = $db->prepare("SELECT id FROM matches WHERE id = ? AND team_id = ? AND status = 'pending' LIMIT 1");
                $stmt->bind_param("ii", $match_id, $team_id);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows === 1) {
                    $stmt->close();
                    $stmt2 = $db->prepare("UPDATE matches SET status = 'accepted', responded_at = NOW(), responded_by = ? WHERE id = ?");
                    $stmt2->bind_param("ii", $user_id, $match_id);
                    $stmt2->execute();
                    $stmt2->close();
                    $success = "Match accepted.";
                } else {
                    $stmt->close();
                    $errors[] = "Match not found or already processed.";
                }
            } else {
                $errors[] = "Invalid match.";
            }
        }

        /* Reject Match Invite */
        if ($action === 'reject_match' && $is_captain) {
            $match_id = (int)($_POST['match_id'] ?? 0);
            if ($match_id > 0) {
                $stmt = $db->prepare("SELECT id FROM matches WHERE id = ? AND team_id = ? AND status = 'pending' LIMIT 1");
                $stmt->bind_param("ii", $match_id, $team_id);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows === 1) {
                    $stmt->close();
                    $stmt2 = $db->prepare("UPDATE matches SET status = 'rejected', responded_at = NOW(), responded_by = ? WHERE id = ?");
                    $stmt2->bind_param("ii", $user_id, $match_id);
                    $stmt2->execute();
                    $stmt2->close();
                    $success = "Match rejected.";
                } else {
                    $stmt->close();
                    $errors[] = "Match not found or already processed.";
                }
            } else {
                $errors[] = "Invalid match.";
            }
        }

        /* Remove Member */
        if ($action === 'remove' && ($is_owner || $is_captain)) {
            $remove_id = (int)($_POST['user_id'] ?? 0);
            if ($remove_id > 0 && $remove_id != (int)$team['owner_id']) {
                $stmt = $db->prepare("DELETE FROM team_members WHERE team_id = ? AND user_id = ?");
                $stmt->bind_param("ii", $team_id, $remove_id);
                $stmt->execute();
                $stmt->close();

                // remove from chat participants if exists
                $stmt = $db->prepare("SELECT id FROM chats WHERE type='team' AND team_id = ? LIMIT 1");
                $stmt->bind_param("i", $team_id);
                $stmt->execute();
                $stmt->bind_result($team_chat_id);
                if ($stmt->fetch()) {
                    $stmt->close();
                    $del = $db->prepare("DELETE FROM chat_participants WHERE chat_id = ? AND user_id = ?");
                    $del->bind_param("ii", $team_chat_id, $remove_id);
                    $del->execute();
                    $del->close();
                } else {
                    $stmt->close();
                }

                $success = "Member removed.";
            } else {
                $errors[] = "Cannot remove this user.";
            }
        }

        /* Promote to Captain */
        if ($action === 'promote' && $is_owner) {
            $promote_id = (int)($_POST['user_id'] ?? 0);
            if ($promote_id > 0) {
                $stmt = $db->prepare("UPDATE team_members SET role='co-captain' WHERE team_id = ? AND user_id = ?");
                $stmt->bind_param("ii", $team_id, $promote_id);
                $stmt->execute();
                $stmt->close();
                $success = "Member promoted to co-captain.";
            } else {
                $errors[] = "Invalid user.";
            }
        }

        /* Leave Team */
        if ($action === 'leave' && $is_member) {
            if ((int)$team['owner_id'] === $user_id) {
                $errors[] = "Owner cannot leave the team. Transfer ownership or delete the team.";
            } else {
                $del = $db->prepare("DELETE FROM team_members WHERE team_id = ? AND user_id = ?");
                $del->bind_param("ii", $team_id, $user_id);
                if ($del->execute()) {
                    $del->close();
                    header("Location: PlayerDashboard.php");
                    exit;
                } else {
                    $errors[] = "Failed to leave team.";
                }
            }
        }

        /* Delete Team */
        if ($action === 'delete' && $is_owner) {
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
                header("Location: PlayerDashboard.php");
                exit;
            } catch (Exception $e) {
                $db->rollback();
                $errors[] = "Failed to delete team.";
            }
        }
    }

    // refresh page
    header("Location: TeamDetails.php?id={$team_id}");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>squid pro | Team Details</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">
<style>
    :root { --accent1:#0D6EFD; --accent2:#8a2be2; }
  body { margin:0; font-family:'Poppins',sans-serif; background:#0d1117; color:#fff; }
  .animated-bg { position:fixed; inset:0; z-index:-1; background: radial-gradient(circle at 20% 20%, #1a237e, transparent 60%), radial-gradient(circle at 80% 80%, #8e24aa, transparent 60%), radial-gradient(circle at 50% 50%, #0d47a1, transparent 70%); filter: blur(80px); }
  .logo-icon { width:55px;height:55px;background:linear-gradient(135deg,var(--accent1),var(--accent2));border-radius:14px;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:24px;color:#fff;box-shadow:0 0 25px rgba(13,110,253,0.9); }
  .navbar { background-color: rgba(0,0,0,0.55) !important; backdrop-filter: blur(6px); }
  .page-title { margin-top:100px; text-align:center; font-size:2.6rem; font-weight:800; }
  :root { --accent1:#0D6EFD; --accent2:#8a2be2; }
  body { margin:0; font-family:'Poppins',sans-serif; background:#0d1117; color:#fff; }
  .animated-bg{position:fixed;inset:0;z-index:-1;background:radial-gradient(circle at 20% 20%,#1a237e,transparent 60%),radial-gradient(circle at 80% 80%,#8e24aa,transparent 60%),radial-gradient(circle at 50% 50%,#0d47a1,transparent 70%);filter:blur(80px);}
  .details-card{background:rgba(255,255,255,0.05);border-radius:18px;padding:30px;border:1px solid rgba(255,255,255,0.08);}
  .member-card{background:rgba(255,255,255,0.03);border-radius:12px;padding:14px;text-align:center;border:1px solid rgba(255,255,255,0.06);}
  .btn-main{background:linear-gradient(135deg,var(--accent1),var(--accent2));border:none;padding:10px 20px;border-radius:999px;color:#fff;}
  .muted{color:rgba(255,255,255,0.7);}
  .result-win{color:#28a745;font-weight:700;}
  .result-loss{color:#dc3545;font-weight:700;}
  .result-draw{color:#ffc107;font-weight:700;}
</style>
</head>
<body>

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

<div class="container" style="margin-top:120px;">
  <div class="details-card">
    <div class="d-flex justify-content-between align-items-start">
      <div>
        <h2><?php echo esc($team['name']); ?></h2>
        <p class="muted"><?php echo esc($team['description']); ?></p>
      </div>
      <div class="text-end">
        <?php if ($is_owner): ?>
          <form method="POST" onsubmit="return confirm('Delete team?');">
            <input type="hidden" name="csrf_token" value="<?php echo esc($csrf); ?>">
            <button name="action" value="delete" class="btn btn-danger">Delete Team</button>
          </form>
        <?php elseif ($is_member): ?>
          <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo esc($csrf); ?>">
            <button name="action" value="leave" class="btn btn-outline-light">Leave Team</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <hr class="my-4">

    <h4>Members</h4>
    <div class="row g-3">
      <?php foreach($members as $m): ?>
        <div class="col-12 col-md-3">
          <div class="member-card">
            <h5><?php echo esc($m['display_name']); ?></h5>
            <p class="muted"><?php echo esc($m['role']); ?></p>

            <?php if (($is_owner || $is_captain) && (int)$m['user_id'] !== $user_id && (int)$m['user_id'] !== (int)$team['owner_id']): ?>
              <form method="POST" style="margin-top:8px;">
                <input type="hidden" name="csrf_token" value="<?php echo esc($csrf); ?>">
                <input type="hidden" name="user_id" value="<?php echo (int)$m['user_id']; ?>">
                <?php if ($is_owner): ?>
                  <button name="action" value="promote" class="btn btn-sm btn-warning">Promote</button>
                <?php endif; ?>
                <button name="action" value="remove" class="btn btn-sm btn-danger">Remove</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if (!empty($errors)): ?>
      <div class="mt-3">
        <?php foreach($errors as $e): ?>
          <div class="alert alert-danger"><?php echo esc($e); ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
      <div class="mt-3">
        <div class="alert alert-success"><?php echo esc($success); ?></div>
      </div>
    <?php endif; ?>

    <?php if ($is_captain): ?>
      <hr class="my-4">
      <h4>Join Requests</h4>
      <?php if (empty($requests)): ?>
        <p class="muted">No pending requests.</p>
      <?php else: ?>
        <div class="row g-3">
          <?php foreach($requests as $r): ?>
            <div class="col-12 col-md-4">
              <div class="member-card">
                <h5><?php echo esc($r['display_name']); ?></h5>
                <p class="muted"><?php echo esc($r['message']); ?></p>
                <p class="muted small">Requested at: <?php echo esc($r['created_at']); ?></p>

                <form method="POST" class="d-flex gap-2 justify-content-center mt-2">
                  <input type="hidden" name="csrf_token" value="<?php echo esc($csrf); ?>">
                  <input type="hidden" name="user_id" value="<?php echo (int)$r['user_id']; ?>">
                  <button name="action" value="approve" class="btn btn-sm btn-main">Approve</button>
                  <button name="action" value="reject" class="btn btn-sm btn-outline-light">Reject</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <hr class="my-4">
    <h4>Pending Match Invitations</h4>
    <p class="muted">Matches created by organizers that require captain approval.</p>

    <?php if (empty($pending_matches)): ?>
      <p class="muted">No pending match invitations.</p>
    <?php else: ?>
      <div class="row g-3">
        <?php foreach($pending_matches as $m): ?>
          <div class="col-12 col-md-6">
            <div class="member-card">
              <h5>Opponent: <?php echo esc($m['opponent_name'] ?: 'TBD'); ?></h5>
              <p class="muted">Organizer: <?php echo esc($m['organizer_name'] ?: 'Organizer'); ?></p>
              <p class="muted">Scheduled: <?php echo esc($m['scheduled_at'] ?: 'TBD'); ?></p>

              <?php if ($is_captain): ?>
                <form method="POST" class="d-flex gap-2 justify-content-center mt-2">
                  <input type="hidden" name="csrf_token" value="<?php echo esc($csrf); ?>">
                  <input type="hidden" name="match_id" value="<?php echo (int)$m['id']; ?>">
                  <button name="action" value="accept_match" class="btn btn-sm btn-main">Accept</button>
                  <button name="action" value="reject_match" class="btn btn-sm btn-outline-light">Reject</button>
                </form>
              <?php else: ?>
                <p class="muted small">Waiting for captain response.</p>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <hr class="my-4">
    <h4>Past Matches and Results</h4>
    <?php if (empty($past_matches)): ?>
      <p class="muted">No past matches recorded.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-dark table-striped">
          <thead>
            <tr>
              <th>Date</th>
              <th>Our Team</th>
              <th>Score</th>
              <th>Opponent</th>
              <th>Result</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($past_matches as $pm): ?>
              <tr>
                <td><?php echo esc($pm['scheduled_at']); ?></td>
                <td><?php echo esc($pm['our_name']); ?></td>
                <td><?php echo esc($pm['our_score']) . ' - ' . esc($pm['their_score']); ?></td>
                <td><?php echo esc($pm['their_name']); ?></td>
                <td>
                  <?php if ($pm['result'] === 'Win'): ?>
                    <span class="result-win">Win</span>
                  <?php elseif ($pm['result'] === 'Loss'): ?>
                    <span class="result-loss">Loss</span>
                  <?php else: ?>
                    <span class="result-draw">Draw</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
