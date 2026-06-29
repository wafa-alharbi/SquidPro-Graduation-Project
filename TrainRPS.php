<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>squid pro | Padel Training</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">
<style>
  body { margin:0; font-family:'Poppins',sans-serif; background:#050712; color:white; overflow:hidden; }
  .animated-bg { position: fixed; inset: 0; z-index: -2; background: radial-gradient(circle at 20% 20%, #1a237e, transparent 60%), radial-gradient(circle at 80% 80%, #8e24aa, transparent 60%), radial-gradient(circle at 50% 50%, #0d47a1, transparent 70%); filter: blur(90px); animation: move 14s infinite alternate ease-in-out; }
  @keyframes move { 0% { transform: scale(1); } 100% { transform: scale(1.4) translate(-40px, -40px); } }
  .top-bar { position: fixed; top: 35px; left: 0; right: 0; text-align: center; z-index: 2; pointer-events: none; }
  .title { font-size: 1.6rem; font-weight: 800; text-shadow: 0 0 18px #0D6EFD; }
  .score { margin-top: 4px; font-size: 1.1rem; opacity: 0.9; }
  .hint { margin-top: 4px; font-size: 0.9rem; opacity: 0.7; }
  canvas { position: fixed; inset: 0; margin: auto; max-width: 100vw; max-height: 100vh; z-index: 1; box-shadow: 0 0 40px rgba(13,110,253,0.7); border-radius: 18px; }
  .btn-back { position: fixed; right: 20px; top: 20px; z-index: 3; }
</style>
</head>
<body>
<div class="animated-bg"></div>
<div class="top-bar">
  <div class="title">Padel Training (vs Bot)</div>
  <div class="score">You (W/S): <span id="score1">0</span> — Bot (AI): <span id="score2">0</span></div>
  <div class="hint">First to 5 points wins • Bot adapts as you play</div>
</div>
<a href="Games.php" class="btn btn-outline-light btn-back">Back to Games</a>
<canvas id="gameCanvas" width="900" height="500"></canvas>
<div class="position-fixed" style="left:20px; top:20px; z-index:3; max-width:260px;">
  <div class="card p-2 text-white" style="background:rgba(0,0,0,0.55); border:1px solid rgba(255,255,255,0.08);">
    <label class="form-label mb-1">Bot Difficulty</label>
    <select id="difficulty" class="form-select form-select-sm">
      <option value="easy">Easy</option>
      <option value="normal" selected>Normal</option>
      <option value="hard">Hard</option>
    </select>
    <div class="small mt-2" style="opacity:0.8;">Progressive Training: <span id="progLabel">On</span></div>
    <button id="toggleProg" class="btn btn-sm btn-outline-light mt-2">Toggle</button>
  </div>
</div>

<script>
const canvas = document.getElementById('gameCanvas');
const ctx = canvas.getContext('2d');

let paddleHeight = 90;
const paddleWidth = 14;
let paddle1Y = canvas.height / 2 - paddleHeight / 2;
let paddle2Y = canvas.height / 2 - paddleHeight / 2;

const paddleSpeed = 7;
let baseBotSpeed = 6.5;
let baseBotError = 18;
let baseBotReactionMs = 140;
let botSpeed = baseBotSpeed;
let botError = baseBotError;
let botReactionMs = baseBotReactionMs;
let lastBotUpdate = 0;
let progressive = true;
let ballX = canvas.width / 2;
let ballY = canvas.height / 2;
let ballRadius = 10;
let ballSpeedX = 6;
let ballSpeedY = 3;

let score1 = 0;
let score2 = 0;
const maxScore = 5;

let keys = {};

document.addEventListener('keydown', e => keys[e.key] = true);
document.addEventListener('keyup', e => keys[e.key] = false);

const diffSelect = document.getElementById('difficulty');
const progLabel = document.getElementById('progLabel');
const toggleProg = document.getElementById('toggleProg');

function applyDifficulty() {
  const diff = diffSelect.value;
  if (diff === 'easy') {
    baseBotSpeed = 5.6; baseBotError = 28; baseBotReactionMs = 220;
  } else if (diff === 'hard') {
    baseBotSpeed = 7.8; baseBotError = 8; baseBotReactionMs = 90;
  } else {
    baseBotSpeed = 6.5; baseBotError = 18; baseBotReactionMs = 140;
  }
  botSpeed = baseBotSpeed;
  botError = baseBotError;
  botReactionMs = baseBotReactionMs;
}
diffSelect.addEventListener('change', applyDifficulty);
toggleProg.addEventListener('click', () => {
  progressive = !progressive;
  progLabel.textContent = progressive ? 'On' : 'Off';
});
applyDifficulty();

function drawCourt() {
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  const grd = ctx.createLinearGradient(0, 0, canvas.width, canvas.height);
  grd.addColorStop(0, 'rgba(13,110,253,0.15)');
  grd.addColorStop(1, 'rgba(138,43,226,0.25)');
  ctx.fillStyle = grd; ctx.fillRect(0, 0, canvas.width, canvas.height);
  ctx.setLineDash([10,10]);
  ctx.strokeStyle = 'rgba(255,255,255,0.4)'; ctx.lineWidth = 2;
  ctx.beginPath(); ctx.moveTo(canvas.width/2, 20); ctx.lineTo(canvas.width/2, canvas.height-20); ctx.stroke();
  ctx.setLineDash([]);
  ctx.strokeStyle = 'rgba(255,255,255,0.6)'; ctx.lineWidth = 3;
  ctx.strokeRect(20, 20, canvas.width-40, canvas.height-40);
}

function drawPaddles() {
  const grad1 = ctx.createLinearGradient(0, 0, paddleWidth, paddleHeight);
  grad1.addColorStop(0, '#0D6EFD'); grad1.addColorStop(1, '#8a2be2');
  ctx.fillStyle = grad1; ctx.shadowColor = '#0D6EFD'; ctx.shadowBlur = 18;
  ctx.fillRect(30, paddle1Y, paddleWidth, paddleHeight);

  const grad2 = ctx.createLinearGradient(0, 0, paddleWidth, paddleHeight);
  grad2.addColorStop(0, '#ff4081'); grad2.addColorStop(1, '#ffea00');
  ctx.fillStyle = grad2; ctx.shadowColor = '#ff4081'; ctx.shadowBlur = 18;
  ctx.fillRect(canvas.width - 30 - paddleWidth, paddle2Y, paddleWidth, paddleHeight);
  ctx.shadowBlur = 0;
}

function drawBall() {
  const ballGrad = ctx.createRadialGradient(ballX, ballY, 2, ballX, ballY, ballRadius);
  ballGrad.addColorStop(0, '#ffffff'); ballGrad.addColorStop(1, '#0D6EFD');
  ctx.fillStyle = ballGrad; ctx.beginPath(); ctx.arc(ballX, ballY, ballRadius, 0, Math.PI*2); ctx.fill();
}

function movePaddles() {
  if (keys['w'] || keys['W']) paddle1Y -= paddleSpeed;
  if (keys['s'] || keys['S']) paddle1Y += paddleSpeed;
  // AI paddle predicts ball with reaction delay + error
  const now = performance.now();
  if (now - lastBotUpdate > botReactionMs) {
    lastBotUpdate = now;
    let targetY = ballY;
    // Predict landing y when ball moves towards bot
    if (ballSpeedX > 0) {
      const timeToReach = (canvas.width - 30 - paddleWidth - ballX) / ballSpeedX;
      let projected = ballY + ballSpeedY * timeToReach;
      // reflect on top/bottom walls
      const top = 20 + ballRadius;
      const bottom = canvas.height - 20 - ballRadius;
      while (projected < top || projected > bottom) {
        if (projected < top) projected = top + (top - projected);
        if (projected > bottom) projected = bottom - (projected - bottom);
      }
      targetY = projected;
    }
    // add error
    targetY += (Math.random() * botError * 2 - botError);
    paddle2Y += Math.sign(targetY - (paddle2Y + paddleHeight/2)) * botSpeed;
  }

  paddle1Y = Math.max(20, Math.min(canvas.height - 20 - paddleHeight, paddle1Y));
  paddle2Y = Math.max(20, Math.min(canvas.height - 20 - paddleHeight, paddle2Y));
}

function moveBall() {
  ballX += ballSpeedX; ballY += ballSpeedY;
  if (ballY - ballRadius <= 20 || ballY + ballRadius >= canvas.height - 20) ballSpeedY = -ballSpeedY;

  if (ballX - ballRadius <= 30 + paddleWidth && ballY >= paddle1Y && ballY <= paddle1Y + paddleHeight && ballSpeedX < 0) {
    ballSpeedX = -ballSpeedX * 1.05; ballSpeedY *= 1.05;
  }
  if (ballX + ballRadius >= canvas.width - 30 - paddleWidth && ballY >= paddle2Y && ballY <= paddle2Y + paddleHeight && ballSpeedX > 0) {
    ballSpeedX = -ballSpeedX * 1.05; ballSpeedY *= 1.05;
  }

  if (ballX + ballRadius < 0) { score2++; updateScore(); resetBall(1); }
  if (ballX - ballRadius > canvas.width) { score1++; updateScore(); resetBall(-1); }
}

function resetBall(direction) {
  ballX = canvas.width / 2; ballY = canvas.height / 2;
  ballSpeedX = 6 * direction; ballSpeedY = (Math.random()*4 - 2);
}

function updateScore() {
  document.getElementById('score1').innerText = score1;
  document.getElementById('score2').innerText = score2;
  if (progressive) {
    botSpeed = Math.min(baseBotSpeed + (score1 + score2) * 0.15, baseBotSpeed + 2.2);
    botError = Math.max(baseBotError - (score1 + score2) * 0.8, 6);
    botReactionMs = Math.max(baseBotReactionMs - (score1 + score2) * 6, 60);
  }
  if (score1 >= maxScore || score2 >= maxScore) {
    submitProgress(score1, score2);
    score1 = 0; score2 = 0; // auto reset for training
    applyDifficulty();
  }
}

function gameLoop() {
  drawCourt(); movePaddles(); moveBall(); drawPaddles(); drawBall();
  requestAnimationFrame(gameLoop);
}

resetBall(Math.random() > 0.5 ? 1 : -1);
updateScore();
gameLoop();

async function submitProgress(s1, s2) {
  try {
    const form = new FormData();
    form.append('game_id', 3);
    form.append('mode', 'training');
    form.append('score_for', s1);
    form.append('score_against', s2);
    const res = await fetch('TrainProgress.php', { method: 'POST', body: form });
    await res.json();
  } catch (e) { /* ignore */ }
}
</script>
</body>
</html>
