<?php
session_start();
if ($_SESSION['role'] !== 'admin') die("Access denied.");

$db = new mysqli("localhost", "root", "", "SquidPro");
$db->set_charset("utf8mb4");

$id = (int)$_GET['id'];
$game = $db->query("SELECT * FROM games WHERE id=$id")->fetch_assoc();

if (!$game) die("Game not found.");

$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $icon = $game['icon'];

    if (!empty($_FILES['icon']['name'])) {
        $icon = basename($_FILES['icon']['name']);
        move_uploaded_file($_FILES['icon']['tmp_name'], "image/" . $icon);
    }

    $stmt = $db->prepare("UPDATE games SET name=?, icon=?, description=? WHERE id=?");
    $stmt->bind_param("sssi", $name, $icon, $description, $id);
    $stmt->execute();
    $success = true;
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Edit Game</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body style="background:#0d1117;color:white;">

<div class="container" style="margin-top:80px;">
    <h1>Edit Game</h1>

    <?php if ($success): ?>
        <div class="alert alert-success">Game updated successfully!</div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <label>Name</label>
        <input type="text" name="name" class="form-control" value="<?php echo $game['name']; ?>">

        <label class="mt-3">Description</label>
        <textarea name="description" class="form-control"><?php echo $game['description']; ?></textarea>

        <label class="mt-3">Current Image</label><br>
        <img src="image/<?php echo $game['icon']; ?>" width="120">

        <label class="mt-3">Upload New Image</label>
        <input type="file" name="icon" class="form-control">

        <button class="btn btn-warning mt-4">Save Changes</button>
    </form>
</div>

</body>
</html>
