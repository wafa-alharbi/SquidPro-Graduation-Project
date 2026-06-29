<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>squid pro Hub | padal</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Google Font -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">

<style>
    body {
        margin: 0;
        font-family: 'Poppins', sans-serif;
        background: #050712;
        color: white;
        overflow: hidden;
    }

    .animated-bg {
        position: fixed;
        inset: 0;
        z-index: -2;
        background: radial-gradient(circle at 20% 20%, #1a237e, transparent 60%),
                    radial-gradient(circle at 80% 80%, #8e24aa, transparent 60%),
                    radial-gradient(circle at 50% 50%, #0d47a1, transparent 70%);
        filter: blur(90px);
        animation: move 14s infinite alternate ease-in-out;
    }
    @keyframes move {
        0% { transform: scale(1); }
        100% { transform: scale(1.4) translate(-40px, -40px); }
    }

    .top-bar {
        position: fixed;
        top: 45px;
        left: 0;
        right: 0;
        text-align: center;
        z-index: 2;
        pointer-events: none;
    }

    .title {
        font-size: 1.8rem;
        font-weight: 800;
        text-shadow: 0 0 18px #0D6EFD;
    }

    .score {
        margin-top: 4px;
        font-size: 1.1rem;
        opacity: 0.9;
    }

    .hint {
        margin-top: 4px;
        font-size: 0.9rem;
        opacity: 0.7;
    }

    canvas {
        position: fixed;
        inset: 0;
        margin: auto;
        max-width: 100vw;
        max-height: 100vh;
        z-index: 1;
        box-shadow: 0 0 40px rgba(13,110,253,0.7);
        border-radius: 18px;
    }

    .victory-screen {
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.85);
        display: none;
        align-items: center;
        justify-content: center;
        flex-direction: column;
        z-index: 5;
        color: white;
        text-align: center;
    }

    .victory-screen h1 {
        font-size: 3rem;
        font-weight: 900;
        text-shadow: 0 0 25px #0D6EFD;
        margin-bottom: 10px;
    }

    .victory-screen p {
        opacity: 0.8;
        margin-bottom: 20px;
    }

    .btn-main {
        background: linear-gradient(135deg, #0D6EFD, #8a2be2);
        border: none;
        padding: 12px 30px;
        border-radius: 50px;
        color: white;
        font-size: 1rem;
        cursor: pointer;
        box-shadow: 0 0 20px rgba(138,43,226,0.8);
        transition: 0.25s;
    }

    .btn-main:hover {
        transform: scale(1.05);
        box-shadow: 0 0 30px rgba(138,43,226,1);
    }

    .shake {
        animation: shake 0.25s linear;
    }

    @keyframes shake {
        0% { transform: translate(0,0); }
        25% { transform: translate(-8px, 4px); }
        50% { transform: translate(8px, -4px); }
        75% { transform: translate(-6px, 3px); }
        100% { transform: translate(0,0); }
    }
    body {
            font-family: 'Poppins', sans-serif;
            background: #0d1117;
            color: white;
            overflow-x: hidden;
        }

        /* Animated Gradient Waves Background */
        .animated-bg {
            position: fixed;
            inset: 0;
            z-index: -1;
            background: radial-gradient(circle at 20% 20%, #1a237e, transparent 60%),
                        radial-gradient(circle at 80% 80%, #8e24aa, transparent 60%),
                        radial-gradient(circle at 50% 50%, #0d47a1, transparent 70%);
            filter: blur(80px);
            animation: moveWaves 12s infinite alternate ease-in-out;
        }

        @keyframes moveWaves {
            0% { transform: scale(1) translate(0, 0); }
            100% { transform: scale(1.3) translate(-40px, -40px); }
        }

        /* Floating Particles */
        .particles span {
            position: absolute;
            width: 6px;
            height: 6px;
            background: #0D6EFD;
            border-radius: 50%;
            opacity: 0.7;
            animation: float 6s infinite ease-in-out;
        }

        @keyframes float {
            0% { transform: translateY(0); opacity: 0.4; }
            50% { transform: translateY(-40px); opacity: 1; }
            100% { transform: translateY(0); opacity: 0.4; }
        }

        /* Neon Logo */
        .logo-icon {
            width: 55px;
            height: 55px;
            background: linear-gradient(135deg, #0D6EFD, #8a2be2);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 24px;
            color: white;
            box-shadow: 0 0 25px rgba(13,110,253,0.9);
            animation: pulse 2.2s infinite ease-in-out;
        }

        @keyframes pulse {
            0% { transform: scale(1); box-shadow: 0 0 15px #0D6EFD; }
            50% { transform: scale(1.15); box-shadow: 0 0 35px #8a2be2; }
            100% { transform: scale(1); box-shadow: 0 0 15px #0D6EFD; }
        }

        /* Page Title */
        .page-title {
            margin-top: 120px;
            text-align: center;
            font-size: 3rem;
            font-weight: 800;
            animation: fadeUp 1.5s ease-out;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(40px); }
            to { opacity: 1; transform: translateY(0); }
        }

</style>
</head>
<body>
 <!-- Animated Background -->
    <div class="animated-bg"></div>

    <!-- Floating Particles -->
    <div class="particles">
        <span style="top: 20%; left: 10%; animation-delay: 0s;"></span>
        <span style="top: 40%; left: 80%; animation-delay: 1s;"></span>
        <span style="top: 70%; left: 30%; animation-delay: 2s;"></span>
        <span style="top: 85%; left: 60%; animation-delay: 3s;"></span>
        <span style="top: 10%; left: 50%; animation-delay: 1.5s;"></span>
    </div>

    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark fixed-top shadow-sm">
        <div class="container">

            <div class="logo-icon me-2">S</div>
            <a class="navbar-brand fw-bold fs-3" href="index.html">squid pro Hub</a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navMenu">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="index.html">Home</a></li>
                    <li class="nav-item"><a class="nav-link active" href="Games.html">Games</a></li>
                    <li class="nav-item"><a class="nav-link" href="#">Teams</a></li>
                    <li class="nav-item"><a class="nav-link" href="#">Tournaments</a></li>
                    <li class="nav-item"><a class="nav-link" href="#">AI Coach</a></li>
                </ul>
                <a href="#" class="btn btn-primary ms-3">Login</a>
            </div>

        </div>
    </nav>

<div class="animated-bg"></div>

<div class="top-bar">
   
    <div class="score">
        Player 1 (W/S): <span id="score1">0</span> — 
        Player 2 (↑/↓): <span id="score2">0</span>
    </div>
    <div class="hint">First to 5 points wins • Move paddles: W/S & Arrow Up/Down</div>
</div>

<canvas id="gameCanvas" width="900" height="500"></canvas>

<div class="victory-screen" id="victoryScreen">
    <h1 id="winnerText"></h1>
    <p>Legendary rally! Want to play another match?</p>
    <button class="btn-main" onclick="resetGame()">Play Again</button>
</div>

<script>
    const canvas = document.getElementById("gameCanvas");
    const ctx = canvas.getContext("2d");

    let paddleHeight = 90;
    const paddleWidth = 14;
    let paddle1Y = canvas.height / 2 - paddleHeight / 2;
    let paddle2Y = canvas.height / 2 - paddleHeight / 2;

    const paddleSpeed = 8;

    let ballX = canvas.width / 2;
    let ballY = canvas.height / 2;
    let ballRadius = 10;
    let ballSpeedX = 6;
    let ballSpeedY = 3;

    let score1 = 0;
    let score2 = 0;
    const maxScore = 5;

    let keys = {};

    document.addEventListener("keydown", e => keys[e.key] = true);
    document.addEventListener("keyup", e => keys[e.key] = false);

    function drawCourt() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);

     
        const grd = ctx.createLinearGradient(0, 0, canvas.width, canvas.height);
        grd.addColorStop(0, "rgba(13,110,253,0.15)");
        grd.addColorStop(1, "rgba(138,43,226,0.25)");
        ctx.fillStyle = grd;
        ctx.fillRect(0, 0, canvas.width, canvas.height);

       
        ctx.setLineDash([10, 10]);
        ctx.strokeStyle = "rgba(255,255,255,0.4)";
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(canvas.width / 2, 20);
        ctx.lineTo(canvas.width / 2, canvas.height - 20);
        ctx.stroke();
        ctx.setLineDash([]);

        // حدود الملعب
        ctx.strokeStyle = "rgba(255,255,255,0.6)";
        ctx.lineWidth = 3;
        ctx.strokeRect(20, 20, canvas.width - 40, canvas.height - 40);
    }

    function drawPaddles() {
        // Paddle 1
        const grad1 = ctx.createLinearGradient(0, 0, paddleWidth, paddleHeight);
        grad1.addColorStop(0, "#0D6EFD");
        grad1.addColorStop(1, "#8a2be2");
        ctx.fillStyle = grad1;
        ctx.shadowColor = "#0D6EFD";
        ctx.shadowBlur = 18;
        ctx.fillRect(30, paddle1Y, paddleWidth, paddleHeight);

        // Paddle 2
        const grad2 = ctx.createLinearGradient(0, 0, paddleWidth, paddleHeight);
        grad2.addColorStop(0, "#ff4081");
        grad2.addColorStop(1, "#ffea00");
        ctx.fillStyle = grad2;
        ctx.shadowColor = "#ff4081";
        ctx.shadowBlur = 18;
        ctx.fillRect(canvas.width - 30 - paddleWidth, paddle2Y, paddleWidth, paddleHeight);

        ctx.shadowBlur = 0;
    }

    function drawBall() {
        const ballGrad = ctx.createRadialGradient(ballX, ballY, 2, ballX, ballY, ballRadius);
        ballGrad.addColorStop(0, "#ffffff");
        ballGrad.addColorStop(1, "#0D6EFD");
        ctx.fillStyle = ballGrad;
        ctx.beginPath();
        ctx.arc(ballX, ballY, ballRadius, 0, Math.PI * 2);
        ctx.fill();
        ctx.shadowColor = "#0D6EFD";
        ctx.shadowBlur = 20;
        ctx.shadowBlur = 0;
    }

    function movePaddles() {
        // Player 1: W / S
        if (keys["w"] || keys["W"]) paddle1Y -= paddleSpeed;
        if (keys["s"] || keys["S"]) paddle1Y += paddleSpeed;

        // Player 2: Arrow Up / Down
        if (keys["ArrowUp"]) paddle2Y -= paddleSpeed;
        if (keys["ArrowDown"]) paddle2Y += paddleSpeed;

        // حدود
        paddle1Y = Math.max(20, Math.min(canvas.height - 20 - paddleHeight, paddle1Y));
        paddle2Y = Math.max(20, Math.min(canvas.height - 20 - paddleHeight, paddle2Y));
    }

    function moveBall() {
        ballX += ballSpeedX;
        ballY += ballSpeedY;

        // ارتداد من الأعلى والأسفل
        if (ballY - ballRadius <= 20 || ballY + ballRadius >= canvas.height - 20) {
            ballSpeedY = -ballSpeedY;
            playPing();
        }

        // تصادم مع Paddle 1
        if (ballX - ballRadius <= 30 + paddleWidth &&
            ballY >= paddle1Y &&
            ballY <= paddle1Y + paddleHeight &&
            ballSpeedX < 0) {

            ballSpeedX = -ballSpeedX * 1.05;
            ballSpeedY *= 1.05;
            addSpin(paddle1Y);
            playHit();
        }

        // تصادم مع Paddle 2
        if (ballX + ballRadius >= canvas.width - 30 - paddleWidth &&
            ballY >= paddle2Y &&
            ballY <= paddle2Y + paddleHeight &&
            ballSpeedX > 0) {

            ballSpeedX = -ballSpeedX * 1.05;
            ballSpeedY *= 1.05;
            addSpin(paddle2Y);
            playHit();
        }

        // هدف على Player 1
        if (ballX + ballRadius < 0) {
            score2++;
            updateScore();
            goalEffect();
            resetBall(1);
        }

        // هدف على Player 2
        if (ballX - ballRadius > canvas.width) {
            score1++;
            updateScore();
            goalEffect();
            resetBall(-1);
        }
    }

    function addSpin(paddleY) {
        const paddleCenter = paddleY + paddleHeight / 2;
        const diff = ballY - paddleCenter;
        ballSpeedY += diff * 0.03;
    }

    function resetBall(direction) {
        ballX = canvas.width / 2;
        ballY = canvas.height / 2;
        ballSpeedX = 6 * direction;
        ballSpeedY = (Math.random() * 4 - 2);
        checkWin();
    }

    function updateScore() {
        document.getElementById("score1").innerText = score1;
        document.getElementById("score2").innerText = score2;
    }

    function checkWin() {
        if (score1 >= maxScore || score2 >= maxScore) {
            const winner = score1 > score2 ? "Player 1 Wins! 🏆" : "Player 2 Wins! 🏆";
            document.getElementById("winnerText").innerText = winner;
            document.getElementById("victoryScreen").style.display = "flex";
        }
    }

    function goalEffect() {
        playGoal();
        canvas.classList.add("shake");
        setTimeout(() => canvas.classList.remove("shake"), 250);
    }

    function gameLoop() {
        drawCourt();
        movePaddles();
        moveBall();
        drawPaddles();
        drawBall();
        requestAnimationFrame(gameLoop);
    }

    function resetGame() {
        score1 = 0;
        score2 = 0;
        updateScore();
        paddle1Y = canvas.height / 2 - paddleHeight / 2;
        paddle2Y = canvas.height / 2 - paddleHeight / 2;
        resetBall(Math.random() > 0.5 ? 1 : -1);
        document.getElementById("victoryScreen").style.display = "none";
    }

    // أصوات بسيطة باستخدام Web Audio API
    function playTone(freq, duration, volume = 0.2) {
        const audio = new (window.AudioContext || window.webkitAudioContext)();
        const osc = audio.createOscillator();
        const gain = audio.createGain();
        osc.frequency.value = freq;
        gain.gain.value = volume;
        osc.connect(gain);
        gain.connect(audio.destination);
        osc.start();
        osc.stop(audio.currentTime + duration);
    }

    function playHit() { playTone(600, 0.08, 0.25); }
    function playPing() { playTone(300, 0.06, 0.18); }
    function playGoal() { playTone(150, 0.25, 0.35); }

    
    resetBall(Math.random() > 0.5 ? 1 : -1);
    gameLoop();
</script>

</body>
</html>
