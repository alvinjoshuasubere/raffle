// Confetti animation
const canvas = document.getElementById("confetti-canvas");
const ctx = canvas.getContext("2d");
let confettiParticles = [];
let animationId = null;

// Set canvas size
function resizeCanvas() {
  canvas.width = window.innerWidth;
  canvas.height = window.innerHeight;
}

window.addEventListener("resize", resizeCanvas);
resizeCanvas();

// Confetti particle class
class ConfettiParticle {
  constructor() {
    this.x = Math.random() * canvas.width;
    this.y = Math.random() * canvas.height - canvas.height;
    this.size = Math.random() * 8 + 5;
    this.speedY = Math.random() * 3 + 2;
    this.speedX = Math.random() * 2 - 1;
    this.color = this.randomColor();
    this.angle = Math.random() * 360;
    this.spin = Math.random() * 10 - 5;
  }

  randomColor() {
    const colors = [
      "#DC143C", // Red
      "#FFD700", // Gold
      "#FF6347", // Tomato
      "#FFA500", // Orange
      "#FF1493", // Deep Pink
      "#00CED1", // Dark Turquoise
      "#32CD32", // Lime Green
      "#FF69B4", // Hot Pink
    ];
    return colors[Math.floor(Math.random() * colors.length)];
  }

  update() {
    this.y += this.speedY;
    this.x += this.speedX;
    this.angle += this.spin;

    // Reset particle if it goes off screen
    if (this.y > canvas.height) {
      this.y = -10;
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

// Create confetti particles
function createConfetti() {
  confettiParticles = [];
  for (let i = 0; i < 150; i++) {
    confettiParticles.push(new ConfettiParticle());
  }
}

// Animate confetti
function animateConfetti() {
  ctx.clearRect(0, 0, canvas.width, canvas.height);

  confettiParticles.forEach((particle) => {
    particle.update();
    particle.draw();
  });

  animationId = requestAnimationFrame(animateConfetti);
}

// Start confetti
function startConfetti() {
  createConfetti();
  animateConfetti();
}

// Stop confetti
function stopConfetti() {
  if (animationId) {
    cancelAnimationFrame(animationId);
    animationId = null;
  }
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  confettiParticles = [];
}
