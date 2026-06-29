<?php
session_start();
if ($_SESSION['role'] !== 'admin') die("Access denied.");

$db = new mysqli("localhost", "root", "", "SquidPro");
$db->set_charset("utf8mb4");

$success = false;
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $icon = "";

    if (!empty($_FILES['icon']['name'])) {
        $icon = basename($_FILES['icon']['name']);
        move_uploaded_file($_FILES['icon']['tmp_name'], "image/" . $icon);
    }

    if ($name === "") {
        $error = "Name is required.";
    } else {
        $stmt = $db->prepare("INSERT INTO games (name, icon, description, is_active) VALUES (?, ?, ?, 1)");
        $stmt->bind_param("sss", $name, $icon, $description);
        $stmt->execute();
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Add Game</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body style="background:#0d1117;color:white;">

<div class="container" style="margin-top:80px;">
    <h1>Add New Game</h1>

    <?php if ($success): ?>
        <div class="alert alert-success">Game added successfully!</div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <label>Name</label>
        <input type="text" name="name" class="form-control">

        <label class="mt-3">Description</label>
        <textarea name="description" class="form-control"></textarea>

        <label class="mt-3">Game Image</label>
        <input type="file" name="icon" class="form-control">

        <button class="btn btn-success mt-4">Add Game</button>
    </form>
</div>

</body>
</html>
