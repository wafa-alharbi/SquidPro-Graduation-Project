<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>squid pro Hub | Play Game</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">

<style>
    body {
        font-family: 'Poppins', sans-serif;
        background: #0d1117;
        color: white;
        overflow-x: hidden;
        text-align: center;
    }

    /* Background */
    .animated-bg {
        position: fixed;
        inset: 0;
        z-index: -1;
        background: radial-gradient(circle at 20% 20%, #1a237e, transparent 60%),
                    radial-gradient(circle at 80% 80%, #8e24aa, transparent 60%),
                    radial-gradient(circle at 50% 50%, #0d47a1, transparent 70%);
        filter: blur(80px);
        animation: move 12s infinite alternate ease-in-out;
    }
    @keyframes move { 0%{transform:scale(1);} 100%{transform:scale(1.3);} }

    /* Game Box */
    .game-box {
        margin-top: 150px;
        background: rgba(255,255,255,0.05);
        border-radius: 20px;
        padding: 40px;
        width: 70%;
        margin-left: auto;
        margin-right: auto;
        border: 1px solid rgba(255,255,255,0.1);
        backdrop-filter: blur(8px);
        box-shadow: 0 0 25px rgba(13,110,253,0.4);
        animation: fadeIn 1.2s ease-out;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(40px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .player-turn {
        font-size: 1.7rem;
        font-weight: 800;
        color: #0D6EFD;
        margin-bottom: 20px;
        text-shadow: 0 0 15px rgba(13,110,253,0.7);
    }

    /* Choices */
    .choices button {
        background: rgba(255,255,255,0.1);
        border: 1px solid rgba(255,255,255,0.2);
        padding: 20px 30px;
        border-radius: 14px;
        margin: 10px;
        font-size: 2.5rem;
        color: white;
        transition: 0.3s;
        box-shadow: 0 0 15px rgba(13,110,253,0.3);
    }

    .choices button:hover {
        background: #0D6EFD;
        transform: scale(1.15) rotate(5deg);
        box-shadow: 0 0 25px rgba(13,110,253,0.8);
    }

    /* Result */
    .result-box {
        margin-top: 30px;
        font-size: 2rem;
        font-weight: 800;
        transition: 0.3s;
    }

    /* Score */
    .score-box {
        margin-top: 20px;
        font-size: 1.3rem;
    }

    /* Progress Bar */
    .progress {
        height: 12px;
        border-radius: 10px;
        background: rgba(255,255,255,0.1);
        margin-top: 20px;
    }

    .progress-bar {
        background: linear-gradient(135deg, #0D6EFD, #8a2be2);
    }

    /* Reset Button */
    .btn-reset {
        margin-top: 25px;
        padding: 12px 30px;
        border-radius: 50px;
        background: linear-gradient(135deg, #0D6EFD, #8a2be2);
        border: none;
        color: white;
        font-size: 1.1rem;
        transition: 0.3s;
    }

    .btn-reset:hover {
        transform: scale(1.05);
        box-shadow: 0 0 25px rgba(138,43,226,0.9);
    }

    /* Victory Screen */
    .victory-screen {
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.85);
        display: none;
        align-items: center;
        justify-content: center;
        flex-direction: column;
        z-index: 10;
        color: white;
        text-align: center;
    }

    .victory-screen h1 {
        font-size: 4rem;
        font-weight: 900;
        text-shadow: 0 0 25px #0D6EFD;
    }

</style>
</head>

<body>

<div class="animated-bg"></div>

<h1 class="fw-bold mt-5">🎮 Enhanced Rock – Paper – Scissors</h1>
<p style="opacity:0.8;">Switch players, earn points, and win the match!</p>

<div class="game-box">

    <div id="turn" class="player-turn">Player 1 Turn</div>

    <div class="choices">
        <button onclick="choose('rock')">🪨</button>
        <button onclick="choose('paper')">📄</button>
        <button onclick="choose('scissors')">✂️</button>
    </div>

    <div class="result-box" id="resultText">Make your move!</div>

    <div class="score-box">
        <p>Player 1 Score: <span id="p1Score">0</span></p>
        <p>Player 2 Score: <span id="p2Score">0</span></p>
    </div>

    <div class="progress">
        <div id="progressBar" class="progress-bar" style="width: 0%;"></div>
    </div>

    <button class="btn-reset" onclick="resetGame()">Reset Game</button>

</div>

<!-- Victory Screen -->
<div class="victory-screen" id="victoryScreen">
    <h1 id="winnerText"></h1>
    <button class="btn-reset mt-4" onclick="resetGame()">Play Again</button>
</div>

<script>
    let playerTurn = 1;
    let p1Choice = "";
    let p2Choice = "";
    let p1Score = 0;
    let p2Score = 0;

    function choose(choice) {
        playSound();

        if (playerTurn === 1) {
            p1Choice = choice;
            playerTurn = 2;
            document.getElementById("turn").innerText = "Player 2 Turn";
            document.getElementById("resultText").innerText = "Player 2, make your move!";
        } else {
            p2Choice = choice;
            playerTurn = 1;
            document.getElementById("turn").innerText = "Player 1 Turn";
            checkWinner();
        }
    }

    function checkWinner() {
        let result = "";

        if (p1Choice === p2Choice) {
            result = "It's a Tie!";
        } else if (
            (p1Choice === "rock" && p2Choice === "scissors") ||
            (p1Choice === "paper" && p2Choice === "rock") ||
            (p1Choice === "scissors" && p2Choice === "paper")
        ) {
            p1Score++;
            result = "Player 1 Wins!";
            winSound();
        } else {
            p2Score++;
            result = "Player 2 Wins!";
            loseSound();
        }

        document.getElementById("resultText").innerText = result;
        document.getElementById("p1Score").innerText = p1Score;
        document.getElementById("p2Score").innerText = p2Score;

        updateProgress();

        if (p1Score === 5 || p2Score === 5) {
            showVictoryScreen();
        }
    }

    function updateProgress() {
        let total = p1Score + p2Score;
        let progress = (total / 10) * 100;
        document.getElementById("progressBar").style.width = progress + "%";
    }

    function showVictoryScreen() {
        let winner = p1Score === 5 ? "Player 1 Wins the Match!" : "Player 2 Wins the Match!";
        document.getElementById("winnerText").innerText = winner;
        document.getElementById("victoryScreen").style.display = "flex";
    }

    function resetGame() {
        p1Score = 0;
        p2Score = 0;
        p1Choice = "";
        p2Choice = "";
        document.getElementById("p1Score").innerText = 0;
        document.getElementById("p2Score").innerText = 0;
        document.getElementById("resultText").innerText = "Make your move!";
        document.getElementById("turn").innerText = "Player 1 Turn";
        document.getElementById("progressBar").style.width = "0%";
        document.getElementById("victoryScreen").style.display = "none";
    }

    /* Sound Effects */
    function playSound() {
        const audio = new AudioContext();
        const oscillator = audio.createOscillator();
        oscillator.frequency.value = 300;
        oscillator.connect(audio.destination);
        oscillator.start();
        oscillator.stop(audio.currentTime + 0.1);
    }

    function winSound() {
        const audio = new AudioContext();
        const oscillator = audio.createOscillator();
        oscillator.frequency.value = 600;
        oscillator.connect(audio.destination);
        oscillator.start();
        oscillator.stop(audio.currentTime + 0.15);
    }

    function loseSound() {
        const audio = new AudioContext();
        const oscillator = audio.createOscillator();
        oscillator.frequency.value = 150;
        oscillator.connect(audio.destination);
        oscillator.start();
        oscillator.stop(audio.currentTime + 0.15);
    }
</script>

</body>
</html>
