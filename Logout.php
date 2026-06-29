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





/* Google Maps API key (replace with your key) */
$google_maps_api_key = 'AIzaSyCbWxWqIM7Laq6uwkXWmk0R0geRZ5QKENw';

/* ---------- Helpers ---------- */
function esc($s) {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

/* ---------- Auth / Role check ---------- */
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$user_id = (int) $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'player';

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

/* ---------- Ensure event join table exists ---------- */
$mysqli->query("
CREATE TABLE IF NOT EXISTS event_join_requests (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  user_id INT NOT NULL,
  status ENUM('pending','approved','rejected') DEFAULT 'pending',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  responded_at DATETIME DEFAULT NULL,
  responded_by INT DEFAULT NULL,
  UNIQUE KEY uk_event_user (event_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* ---------- Handle POST actions: add / edit / delete (organizer only) ---------- */
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        if ($action === 'join_event' && $role === 'player') {
            $event_id = (int) ($_POST['event_id'] ?? 0);
            if ($event_id <= 0) {
                $errors[] = 'Invalid event id.';
            } else {
                // ensure event exists and approved
                $stmt = $mysqli->prepare("SELECT id FROM events WHERE id = ? AND status = 'approved' LIMIT 1");
                $stmt->bind_param('i', $event_id);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows === 0) {
                    $errors[] = 'Event not available.';
                }
                $stmt->close();

                if (empty($errors)) {
                    // check existing request
                    $chk = $mysqli->prepare("SELECT status FROM event_join_requests WHERE event_id = ? AND user_id = ? LIMIT 1");
                    $chk->bind_param('ii', $event_id, $user_id);
                    $chk->execute();
                    $chk->bind_result($existing_status);
                    if ($chk->fetch()) {
                        if ($existing_status === 'rejected') {
                            $upd = $mysqli->prepare("UPDATE event_join_requests SET status='pending', created_at=NOW(), responded_at=NULL, responded_by=NULL WHERE event_id=? AND user_id=?");
                            $upd->bind_param('ii', $event_id, $user_id);
                            $upd->execute();
                            $upd->close();
                            $success = 'Join request re-submitted.';
                        } else {
                            $errors[] = 'You already requested to join this event.';
                        }
                    } else {
                        $ins = $mysqli->prepare("INSERT INTO event_join_requests (event_id, user_id, status, created_at) VALUES (?, ?, 'pending', NOW())");
                        $ins->bind_param('ii', $event_id, $user_id);
                        if ($ins->execute()) {
                            $success = 'Join request sent.';
                        } else {
                            $errors[] = 'Failed to send join request.';
                        }
                        $ins->close();
                    }
                    $chk->close();
                }
            }
        } elseif ($action === 'add' && $role === 'organizer') {
            $name = trim($_POST['name'] ?? '');
            $type = $_POST['type'] ?? 'other';
            $latitude = trim($_POST['latitude'] ?? '');
            $longitude = trim($_POST['longitude'] ?? '');
            $address = trim($_POST['address'] ?? '');

            if ($name === '') $errors[] = 'Name is required.';
            if ($latitude === '' || $longitude === '' || !is_numeric($latitude) || !is_numeric($longitude)) $errors[] = 'Valid latitude and longitude are required.';

            if (empty($errors)) {
                $ins = $mysqli->prepare("INSERT INTO locations (name, type, latitude, longitude, address, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                if ($ins) {
                    $ins->bind_param('ssdds', $name, $type, $latitude, $longitude, $address);
                    if ($ins->execute()) {
                        $success = 'Location added successfully.';
                    } else {
                        $errors[] = 'Failed to add location.';
                    }
                    $ins->close();
                } else {
                    $errors[] = 'Database error.';
                }
            }
        } elseif ($action === 'edit' && $role === 'organizer') {
            $loc_id = (int) ($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $type = $_POST['type'] ?? 'other';
            $latitude = trim($_POST['latitude'] ?? '');
            $longitude = trim($_POST['longitude'] ?? '');
            $address = trim($_POST['address'] ?? '');

            if ($loc_id <= 0) $errors[] = 'Invalid location id.';
            if ($name === '') $errors[] = 'Name is required.';
            if ($latitude === '' || $longitude === '' || !is_numeric($latitude) || !is_numeric($longitude)) $errors[] = 'Valid latitude and longitude are required.';

            if (empty($errors)) {
                $upd = $mysqli->prepare("UPDATE locations SET name = ?, type = ?, latitude = ?, longitude = ?, address = ? WHERE id = ?");
                if ($upd) {
                    $upd->bind_param('ssddsi', $name, $type, $latitude, $longitude, $address, $loc_id);
                    if ($upd->execute()) {
                        $success = 'Location updated successfully.';
                    } else {
                        $errors[] = 'Failed to update location.';
                    }
                    $upd->close();
                } else {
                    $errors[] = 'Database error.';
                }
            }
        } elseif ($action === 'delete' && $role === 'organizer') {
            $loc_id = (int) ($_POST['id'] ?? 0);
            if ($loc_id <= 0) $errors[] = 'Invalid location id.';
            if (empty($errors)) {
                $del = $mysqli->prepare("DELETE FROM locations WHERE id = ?");
                if ($del) {
                    $del->bind_param('i', $loc_id);
                    if ($del->execute()) {
                        $success = 'Location deleted successfully.';
                    } else {
                        $errors[] = 'Failed to delete location.';
                    }
                    $del->close();
                } else {
                    $errors[] = 'Database error.';
                }
            }
        } else {
            $errors[] = 'Unauthorized action or insufficient permissions.';
        }
    }
}

/* ---------- Load locations for display and map ---------- */
$search = trim($_GET['q'] ?? '');
$locations = [];

if ($search === '') {
    $stmt = $mysqli->prepare("SELECT id, name, type, latitude, longitude, address, created_at FROM locations ORDER BY created_at DESC LIMIT 1000");
    $stmt->execute();
} else {
    $stmt = $mysqli->prepare("SELECT id, name, type, latitude, longitude, address, created_at FROM locations WHERE name LIKE CONCAT('%', ?, '%') OR address LIKE CONCAT('%', ?, '%') ORDER BY created_at DESC LIMIT 1000");
    $stmt->bind_param('ss', $search, $search);
    $stmt->execute();
}
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $locations[] = $row;
}

/* ---------- Load approved events by location ---------- */
$events_by_location = [];
$event_ids = [];
$event_join_status = [];

$evq = $mysqli->query("
    SELECT id, name, start_date, location_id, location_name
    FROM events
    WHERE status = 'approved'
    ORDER BY start_date ASC
");
if ($evq) {
    while ($ev = $evq->fetch_assoc()) {
        $lid = (int) ($ev['location_id'] ?? 0);
        if ($lid > 0) {
            if (!isset($events_by_location[$lid])) $events_by_location[$lid] = [];
            $events_by_location[$lid][] = $ev;
            $event_ids[] = (int) $ev['id'];
        }
    }
}

/* ---------- Load my join status for events ---------- */
if (!empty($event_ids)) {
    $in = implode(',', array_map('intval', array_unique($event_ids)));
    $qs = $mysqli->query("SELECT event_id, status FROM event_join_requests WHERE user_id = " . (int)$user_id . " AND event_id IN ($in)");
    if ($qs) {
        while ($r = $qs->fetch_assoc()) {
            $event_join_status[(int)$r['event_id']] = $r['status'];
        }
    }
}



$stmt->close();
$mysqli->close();

/* ---------- Render HTML (UI text in English inside code) ---------- */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Squid Pro Hub | Locations</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">
<style>
  body { margin:0; font-family:'Poppins',sans-serif; background:#0d1117; color:white; overflow:hidden; }
  .bg-waves { position:fixed; inset:0; z-index:-3; background: radial-gradient(circle at 20% 20%, #1a237e, transparent 60%), radial-gradient(circle at 80% 80%, #8e24aa, transparent 60%), radial-gradient(circle at 50% 50%, #0d47a1, transparent 70%); filter: blur(90px); }
  .sidebar { width:260px; height:100vh; background:rgba(0,0,0,0.55); backdrop-filter:blur(10px); position:fixed; left:0; top:0; padding:25px 20px; border-right:1px solid rgba(255,255,255,0.1); }
  .sidebar h2 { font-weight:800; font-size:1.9rem; background:linear-gradient(90deg,#0D6EFD,#8a2be2); -webkit-background-clip:text; color:transparent; text-align:center; margin-bottom:24px; }
  .sidebar a { display:block; padding:12px 15px; margin-bottom:12px; border-radius:10px; color:white; text-decoration:none; font-size:1.05rem; transition:0.3s; }
  .sidebar a:hover, .sidebar a.active { background:linear-gradient(135deg,#0D6EFD,#8a2be2); box-shadow:0 0 15px rgba(13,110,253,0.6); }
  #map { height:100vh; width:calc(100% - 260px); margin-left:260px; }
  .panel { position: absolute; top: 18px; left: 280px; z-index: 10; width: 420px; max-width: calc(100% - 300px); }
  .panel .card { background: rgba(0,0,0,0.6); border:1px solid rgba(255,255,255,0.06); color:#fff; }
  .locations-list { max-height: 60vh; overflow:auto; }
  .small-muted { color: rgba(255,255,255,0.7); font-size:0.9rem; }
  .btn-outline-light { color:#fff; border-color:rgba(255,255,255,0.12); }
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

  <!-- Control panel -->
  <div class="panel">
    <div class="card p-3">
      <form class="row g-2" method="GET" action="Locations.php">
        <div class="col-8">
          <input type="search" name="q" class="form-control form-control-dark" placeholder="Search locations or address" value="<?php echo esc($search); ?>">
        </div>
        <div class="col-4">
          <button class="btn btn-primary w-100" type="submit">Search</button>
        </div>
      </form>

      <?php if ($success): ?>
        <div class="alert alert-success mt-3"><?php echo esc($success); ?></div>
      <?php endif; ?>
      <?php if ($errors): foreach ($errors as $e): ?>
        <div class="alert alert-danger mt-3"><?php echo esc($e); ?></div>
      <?php endforeach; endif; ?>

      <div class="mt-3 locations-list">
        <?php if (empty($locations)): ?>
          <div class="text-muted small-muted">No locations found.</div>
        <?php else: ?>
          <?php foreach ($locations as $loc): ?>
            <div class="d-flex align-items-start mb-2">
              <div style="flex:1;">
                <div style="font-weight:700;"><?php echo esc($loc['name']); ?></div>
                <div class="small-muted"><?php echo esc($loc['type']); ?> <?php echo esc($loc['address'] ?: 'No address'); ?></div>
                <div class="small-muted">Lat: <?php echo esc($loc['latitude']); ?>, Lng: <?php echo esc($loc['longitude']); ?></div>
                <?php if (!empty($events_by_location[(int)$loc['id']])): ?>
                  <div class="mt-2">
                    <div class="small-muted">Events:</div>
                    <?php foreach ($events_by_location[(int)$loc['id']] as $ev): ?>
                      <div class="d-flex justify-content-between align-items-center mt-1">
                        <div class="small-muted" style="max-width:260px;">
                          <strong><?php echo esc($ev['name']); ?></strong><br>
                          <?php echo esc(date('M j, Y H:i', strtotime($ev['start_date']))); ?>
                        </div>
                        <div>
                          <?php
                            $ev_id = (int)$ev['id'];
                            $st = $event_join_status[$ev_id] ?? '';
                          ?>
                          <?php if ($st === 'approved'): ?>
                            <span class="badge bg-success">Joined</span>
                          <?php elseif ($st === 'pending'): ?>
                            <span class="badge bg-warning text-dark">Pending</span>
                          <?php elseif ($st === 'rejected'): ?>
                            <form method="POST" style="display:inline;">
                              <input type="hidden" name="csrf_token" value="<?php echo esc($_SESSION['csrf_token']); ?>">
                              <input type="hidden" name="action" value="join_event">
                              <input type="hidden" name="event_id" value="<?php echo $ev_id; ?>">
                              <button class="btn btn-sm btn-outline-light">Re-apply</button>
                            </form>
                          <?php else: ?>
                            <form method="POST" style="display:inline;">
                              <input type="hidden" name="csrf_token" value="<?php echo esc($_SESSION['csrf_token']); ?>">
                              <input type="hidden" name="action" value="join_event">
                              <input type="hidden" name="event_id" value="<?php echo $ev_id; ?>">
                              <button class="btn btn-sm btn-primary">Join</button>
                            </form>
                          <?php endif; ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
              <div style="margin-left:8px;">
                <button class="btn btn-sm btn-outline-light" onclick="focusMarker(<?php echo esc($loc['latitude']); ?>, <?php echo esc($loc['longitude']); ?>, '<?php echo esc(addslashes($loc['name'])); ?>')">View</button>
                <a target="_blank" class="btn btn-sm btn-outline-light" href="https://www.google.com/maps/search/?api=1&query=<?php echo urlencode($loc['latitude'] . ',' . $loc['longitude']); ?>">Map</a>
              </div>
            </div>
            <hr style="border-color: rgba(255,255,255,0.04);">
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <?php if ($role === 'organizer'): ?>
        <hr style="border-color: rgba(255,255,255,0.06);">
        <div>
          <h6 style="margin:0 0 8px 0;">Add Location</h6>
          <form method="POST" action="Locations.php" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?php echo esc($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="add">
            <div class="col-12">
              <input name="name" class="form-control form-control-dark" placeholder="Location name" required>
            </div>
            <div class="col-6">
              <select name="type" class="form-select form-select-dark">
                <option value="other">Other</option>
                <option value="padel_court">Padel Court</option>
                <option value="arena">Arena</option>
                <option value="club">Club</option>
              </select>
            </div>
            <div class="col-6">
              <input name="address" class="form-control form-control-dark" placeholder="Address (optional)">
            </div>
            <div class="col-6">
              <input name="latitude" class="form-control form-control-dark" placeholder="Latitude" required>
            </div>
            <div class="col-6">
              <input name="longitude" class="form-control form-control-dark" placeholder="Longitude" required>
            </div>
            <div class="col-12">
              <button class="btn btn-success w-100" type="submit">Add Location</button>
            </div>
          </form>
        </div>
      <?php endif; ?>

    </div>
  </div>

  <!-- Map -->
  <div id="map"></div>

  <script>
    // Locations data injected from server
    const locations = <?php echo json_encode($locations, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
    const eventsByLocation = <?php echo json_encode($events_by_location, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
    let map;
    let markers = [];

    function initMap() {
      const center = locations.length ? { lat: parseFloat(locations[0].latitude), lng: parseFloat(locations[0].longitude) } : { lat: 24.7136, lng: 46.6753 };
      map = new google.maps.Map(document.getElementById('map'), {
        zoom: 12,
        center: center,
        styles: [
          { elementType: "geometry", stylers: [{ color: "#1a1a1a" }] },
          { elementType: "labels.text.fill", stylers: [{ color: "#ffffff" }] },
          { elementType: "labels.text.stroke", stylers: [{ color: "#000000" }] },
          { featureType: "poi", stylers: [{ visibility: "off" }] },
          { featureType: "road", stylers: [{ color: "#2c2c2c" }] }
        ]
      });

      const bounds = new google.maps.LatLngBounds();

      locations.forEach((loc, idx) => {
        const lat = parseFloat(loc.latitude);
        const lng = parseFloat(loc.longitude);
        if (isNaN(lat) || isNaN(lng)) return;

        const marker = new google.maps.Marker({
          position: { lat, lng },
          map,
          title: loc.name
        });
        const evList = (eventsByLocation[loc.id] || []).map(ev => {
          return `<div style="margin-top:4px;"><strong>${escapeHtml(ev.name)}</strong> — ${escapeHtml(ev.start_date || '')}</div>`;
        }).join('');

        const infoHtml = `
          <div style="color:#000;">
            <strong>${escapeHtml(loc.name)}</strong><br>
            <small>${escapeHtml(loc.type)} â€” ${escapeHtml(loc.address || '')}</small><br>
            ${evList ? `<div style="margin-top:6px;"><strong>Events:</strong>${evList}</div>` : ''}
            <a target="_blank" href="https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(lat + ',' + lng)}">Open in Google Maps</a>
          </div>
        `;

        const infowindow = new google.maps.InfoWindow({ content: infoHtml });
        marker.addListener('click', () => infowindow.open(map, marker));

        markers.push({ marker, infowindow });
        bounds.extend(marker.position);
      });

      if (!bounds.isEmpty()) {
        map.fitBounds(bounds);
      }
    }

    function focusMarker(lat, lng, title) {
      const position = { lat: parseFloat(lat), lng: parseFloat(lng) };
      map.panTo(position);
      map.setZoom(15);
      // open the first marker that matches coordinates
      for (const m of markers) {
        const pos = m.marker.getPosition();
        if (Math.abs(pos.lat() - position.lat) < 0.00001 && Math.abs(pos.lng() - position.lng) < 0.00001) {
          m.infowindow.open(map, m.marker);
          break;
        }
      }
    }

    function escapeHtml(text) {
      if (!text) return '';
      return text.replace(/[&<>"'`=\/]/g, function (s) {
        return ({
          '&': '&amp;',
          '<': '&lt;',
          '>': '&gt;',
          '"': '&quot;',
          "'": '&#39;',
          '/': '&#x2F;',
          '`': '&#x60;',
          '=': '&#x3D;'
        })[s];
      });
    }
  </script>

  <!-- Google Maps API -->
  <script src="https://maps.googleapis.com/maps/api/js?key=<?php echo esc($google_maps_api_key); ?>&callback=initMap" async defer></script>
</body>
</html>
