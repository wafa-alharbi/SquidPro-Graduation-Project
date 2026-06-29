<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Squid Pro | Home</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">

<style>
  :root { --accent1:#0D6EFD; --accent2:#8a2be2; }

  body {
    margin:0;
    font-family:'Poppins',sans-serif;
    background:#0d1117;
    color:#fff;
    overflow-x:hidden;
  }

  /* Background */
  .animated-bg {
    position:fixed; inset:0; z-index:-1;
    background: radial-gradient(circle at 20% 20%, #1a237e, transparent 60%),
                radial-gradient(circle at 80% 80%, #8e24aa, transparent 60%),
                radial-gradient(circle at 50% 50%, #0d47a1, transparent 70%);
    filter: blur(80px);
    animation: moveWaves 12s infinite alternate ease-in-out;
  }
  @keyframes moveWaves {
    0%{transform:scale(1);}
    100%{transform:scale(1.3);}
  }

  /* Particles */
  .particles span {
    position:absolute;
    width:6px; height:6px;
    background:var(--accent1);
    border-radius:50%;
    opacity:0.7;
    animation:float 6s infinite ease-in-out;
  }
  @keyframes float {
    0%{transform:translateY(0);opacity:0.4;}
    50%{transform:translateY(-40px);opacity:1;}
    100%{transform:translateY(0);opacity:0.4;}
  }

  /* Logo */
  .logo-icon {
    width:55px; height:55px;
    background:linear-gradient(135deg,var(--accent1),var(--accent2));
    border-radius:14px;
    display:flex; align-items:center; justify-content:center;
    font-weight:900; font-size:24px; color:#fff;
    box-shadow:0 0 25px rgba(13,110,253,0.9);
  }

  /* Navbar */
  .navbar {
    background-color:rgba(0,0,0,0.55) !important;
    backdrop-filter:blur(6px);
  }

  /* Hero */
  .hero {
    height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    text-align:center;
    padding:0 20px;
  }
  .hero h1 {
    font-size:3.6rem;
    font-weight:800;
  }
  .hero p {
    font-size:1.3rem;
    opacity:0.85;
  }
  .start-btn {
    margin-top:25px;
    padding:14px 50px;
    font-size:1.2rem;
    border-radius:50px;
    background:linear-gradient(135deg,var(--accent1),var(--accent2));
    border:none;
    color:white;
    transition:0.3s;
    box-shadow:0 0 20px rgba(13,110,253,0.7);
  }
  .start-btn:hover {
    transform:scale(1.1);
    box-shadow:0 0 30px rgba(138,43,226,0.9);
  }
</style>
</head>

<body>

<div class="animated-bg"></div>

<div class="particles">
  <span style="top:20%; left:10%; animation-delay:0s;"></span>
  <span style="top:40%; left:80%; animation-delay:1s;"></span>
  <span style="top:70%; left:30%; animation-delay:2s;"></span>
  <span style="top:85%; left:60%; animation-delay:3s;"></span>
  <span style="top:10%; left:50%; animation-delay:1.5s;"></span>
</div>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark fixed-top shadow-sm">
  <div class="container">

    <div class="d-flex align-items-center">
      <div class="logo-icon me-3">S</div>
      <a class="navbar-brand fw-bold fs-4" href="index.php" style="color:#fff;text-decoration:none;">squid pro</a>
    </div>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navMenu">
      <ul class="navbar-nav ms-auto me-0">
        <li class="nav-item"><a class="nav-link active" href="index.php">Home</a></li>
        <li class="nav-item"><a class="nav-link" href="Games.php">Games</a></li>
        <li class="nav-item"><a class="nav-link" href="Teams.php">Teams</a></li>
        <li class="nav-item"><a class="nav-link" href="Tournaments.php">Tournaments</a></li>
        <li class="nav-item"><a class="nav-link" href="AI-Coach.php">AI Coach</a></li>
      </ul>

      <div class="d-flex ms-3">
        <?php if (!empty($_SESSION['user_id'])): ?>
          <a href="Profile.php" class="btn btn-outline-light me-2">Profile</a>
          <a href="Logout.php" class="btn btn-outline-light">Logout</a>
        <?php else: ?>
          <a href="login.php" class="btn btn-primary">Login</a>
        <?php endif; ?>
      </div>
    </div>

  </div>
</nav>

<!-- Hero Section -->
<header class="hero">
  <div class="container">
    <h1>The Future of squid pro Starts Here</h1>
    <p>Unifying real‑world sports and digital gaming into one powerful platform.</p>
    <a href="Games.php" class="start-btn">Get Started</a>
  </div>
</header>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
