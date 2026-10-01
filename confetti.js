// Lightweight confetti animation
const canvas = document.getElementById("confetti-canvas");
const ctx = canvas.getContext("2d");
let confettiParticles = [];
let animationId = null;

function resizeCanvas() {
  canvas.width = window.innerWidth;
  canvas.height = window.innerHeight;
}

window.addEventListener("resize", resizeCanvas);
resizeCanvas();

class ConfettiParticle {
  constructor() {
    this.x = Math.random() * canvas.width;
    this.y = Math.random() * canvas.height - canvas.height;
    this.size = Math.random() * 3 + 2; // smaller particles
    this.speedY = Math.random() * 2.5 + 1.5;
    this.speedX = Math.random() * 1.5 - 0.75;
    this.color = this.randomColor();
    this.angle = Math.random() * 360;
    this.spin = Math.random() * 8 - 4;
  }

  randomColor() {
    const colors = [
      "#DC143C",
      "#FFD700",
      "#FF6347",
      "#FFA500",
      "#FF1493",
      "#00CED1",
      "#32CD32",
      "#FF69B4",
    ];
    return colors[Math.floor(Math.random() * colors.length)];
  }

  update() {
    this.y += this.speedY;
    this.x += this.speedX;
    this.angle += this.spin;

    if (this.y > canvas.height) {
      this.y = -6;
      this.x = Math.random() * canvas.width;
    }
  }

  draw() {
    ctx.save();
    ctx.translate(this.x, this.y);
    ctx.rotate((this.angle * Math.PI) / 180);
    ctx.fillStyle = this.color;
    ctx.fillRect(-this.size / 2, -this.size / 2, this.size, this.size);
    ctx.restore();
  }
}

function createConfetti() {
  confettiParticles = [];
  for (let i = 0; i < 60; i++) { // 60 instead of 150
    confettiParticles.push(new ConfettiParticle());
  }
}

function animateConfetti() {
  ctx.clearRect(0, 0, canvas.width, canvas.height);

  for (let i = 0; i < confettiParticles.length; i++) {
    const particle = confettiParticles[i];
    particle.update();
    particle.draw();
  }

  animationId = requestAnimationFrame(animateConfetti);
}

function startConfetti() {
  if (animationId) return;
  createConfetti();
  animateConfetti();
}

function stopConfetti() {
  if (animationId) {
    cancelAnimationFrame(animationId);
    animationId = null;
  }
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  confettiParticles = [];
}