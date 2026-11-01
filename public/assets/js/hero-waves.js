// public/assets/js/hero-waves.js
// Hypnotic 3D architectural ribbon-wave animation inspired by Pinterest reference.
// Palette aligned to CIT Scheduler Crextio tokens:
// Warm Cream (#FCF7E7), Butter Yellow (#F8D96B), Soft Yellow (#F6EABE),
// Pale Champagne (#F2EBD0), Charcoal (#2F2E2E), Deep Charcoal (#242424).

(function initHeroWaves() {
  const canvas = document.getElementById("hero-waves-canvas");
  if (!canvas) return;

  const ctx = canvas.getContext("2d");
  if (!ctx) return;

  let width = 0;
  let height = 0;
  let dpr = 1;

  let mouseX = 0;
  let mouseY = 0;
  let curMouseX = 0;
  let curMouseY = 0;

  function resize() {
    width = window.innerWidth || document.documentElement.clientWidth || 1920;
    height = window.innerHeight || document.documentElement.clientHeight || 1080;
    dpr = Math.min(window.devicePixelRatio || 1, 2);

    canvas.width = Math.floor(width * dpr);
    canvas.height = Math.floor(height * dpr);
    canvas.style.width = width + "px";
    canvas.style.height = height + "px";
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }

  window.addEventListener("resize", resize, { passive: true });
  resize();

  window.addEventListener("mousemove", (e) => {
    mouseX = (e.clientX / (width || window.innerWidth || 1) - 0.5) * 2;
    mouseY = (e.clientY / (height || window.innerHeight || 1) - 0.5) * 2;
  }, { passive: true });

  const NUM_RIBBONS = 24;

  let animId = null;
  let isVisible = true;

  document.addEventListener("visibilitychange", () => {
    isVisible = !document.hidden;
    if (isVisible && !animId) {
      animId = requestAnimationFrame(render);
    }
  });

  function render(time) {
    if (!isVisible) {
      animId = null;
      return;
    }

    const t = (typeof time === "number" ? time : performance.now()) * 0.001;

    // Smooth lerp mouse tracking
    curMouseX += (mouseX - curMouseX) * 0.04;
    curMouseY += (mouseY - curMouseY) * 0.04;

    ctx.clearRect(0, 0, width, height);

    // Deep architectural background with soft warm ambient spotlight
    const bgGrad = ctx.createLinearGradient(0, 0, width, height);
    bgGrad.addColorStop(0, "#131312");
    bgGrad.addColorStop(0.45, "#1C1B19");
    bgGrad.addColorStop(1, "#262420");
    ctx.fillStyle = bgGrad;
    ctx.fillRect(0, 0, width, height);

    // Warm ambient glow originating from the bottom left fan vertex
    const glowX = width * 0.12 + curMouseX * 40;
    const glowY = height * 0.88 + curMouseY * 40;
    const radGlow = ctx.createRadialGradient(glowX, glowY, 15, glowX, glowY, Math.max(width, height) * 0.95);
    radGlow.addColorStop(0, "rgba(248, 217, 107, 0.22)");
    radGlow.addColorStop(0.35, "rgba(242, 235, 208, 0.08)");
    radGlow.addColorStop(1, "rgba(18, 18, 17, 0)");
    ctx.fillStyle = radGlow;
    ctx.fillRect(0, 0, width, height);

    const scale = Math.max(0.85, Math.min(width, height) / 900);

    // Render ribbons from outer background (right/gold) to foreground (left/charcoal)
    for (let i = NUM_RIBBONS - 1; i >= 0; i--) {
      const u = i / (NUM_RIBBONS - 1); // 0 (left/inner) to 1 (right/outer)

      // Harmonic oscillation wave
      const phase = t * 1.15 - i * 0.20;
      const waveMain = Math.sin(phase) * (34 * scale);
      const waveSub = Math.cos(phase * 0.75 + 1.2) * (24 * scale);
      const waveTwist = Math.sin(phase * 0.5 + i * 0.12) * (18 * scale);

      // Slat origin along the bottom left fan vertex
      const ox = width * (-0.08 + u * 0.36) + curMouseX * 25 + waveSub * 0.4;
      const oy = height * 1.08 + curMouseY * 20;

      // Slat endpoint fanning across the top and right perimeter
      let tx, ty;
      if (u < 0.58) {
        // Upper top edge fanning from left to top right
        const frac = u / 0.58;
        tx = width * (0.04 + frac * 0.92) + waveSub;
        ty = -height * 0.08 + waveTwist;
      } else {
        // Right edge fanning downwards
        const frac = (u - 0.58) / 0.42;
        tx = width * 1.06 + waveTwist;
        ty = height * (0.02 + frac * 0.88) + waveSub;
      }

      // First Bezier control point (rising steeply from bottom with sweeping arch)
      const cp1x = ox + (tx - ox) * 0.18 - width * (0.07 + (1 - u) * 0.12) + curMouseX * 45 + waveMain;
      const cp1y = oy - height * 0.46 + curMouseY * 30 + waveTwist;

      // Second Bezier control point (carrying the arch toward top/right)
      const cp2x = ox + (tx - ox) * 0.72 + width * 0.06 + curMouseX * 30 + waveSub;
      const cp2y = oy - height * 0.85 + curMouseY * 15 + waveMain * 0.6;

      // Calculate tangent and normal unit vector at midpoint for slat thickness
      const mx_t = (cp2x - cp1x);
      const my_t = (cp2y - cp1y);
      const m_dist = Math.hypot(mx_t, my_t) || 1;
      const nx = -my_t / m_dist;
      const ny = mx_t / m_dist;

      // Ribbon slat thickness with breathing pulsation
      const thickness = (32 + (1 - u) * 44 + Math.sin(phase + 0.6) * 8) * scale;
      const bx = ox + thickness * 0.6;
      const by = oy;

      ctx.save();

      // Atmospheric drop shadow for volumetric 3D layered slats
      ctx.shadowColor = "rgba(0, 0, 0, 0.70)";
      ctx.shadowBlur = 24 * scale;
      ctx.shadowOffsetX = -12 * scale;
      ctx.shadowOffsetY = 14 * scale;

      ctx.beginPath();
      ctx.moveTo(ox, oy);
      ctx.bezierCurveTo(cp1x, cp1y, cp2x, cp2y, tx, ty);

      // Outer contour offset along normal
      const tx2 = tx + nx * thickness * 0.82;
      const ty2 = ty + ny * thickness * 0.82;
      const cp2x_b = cp2x + nx * thickness;
      const cp2y_b = cp2y + ny * thickness;
      const cp1x_b = cp1x + nx * thickness;
      const cp1y_b = cp1y + ny * thickness;

      ctx.lineTo(tx2, ty2);
      ctx.bezierCurveTo(cp2x_b, cp2y_b, cp1x_b, cp1y_b, bx, by);
      ctx.closePath();

      // Color shading gradient perpendicular to the slat body
      const bladeGrad = ctx.createLinearGradient(cp1x, cp1y, cp1x_b, cp1y_b);

      if (i >= 14) {
        // Outer energetic ribbons (right): Butter Yellow & Amber Gold with cream crests
        bladeGrad.addColorStop(0, "#FCF7E7");      // Warm Cream highlight
        bladeGrad.addColorStop(0.18, "#F8D96B");   // Butter Yellow crest
        bladeGrad.addColorStop(0.55, "#D8A730");   // Golden Amber
        bladeGrad.addColorStop(0.85, "#463C26");   // Warm shadow depth
        bladeGrad.addColorStop(1, "#201E18");      // Deep under-slat shadow
      } else if (i >= 7) {
        // Middle ribbons (center): Crisp White and Warm Cream architectural blades
        bladeGrad.addColorStop(0, "#FFFFFF");      // Crisp White crest
        bladeGrad.addColorStop(0.22, "#FCF7E7");   // Warm Cream
        bladeGrad.addColorStop(0.52, "#F2EBD0");   // Pale Champagne
        bladeGrad.addColorStop(0.80, "#484337");   // Soft Graphite shadow
        bladeGrad.addColorStop(1, "#222220");      // Charcoal under-slat
      } else {
        // Inner ribbons (left): Graphite & Charcoal blades with delicate champagne rim
        bladeGrad.addColorStop(0, "#F2EBD0");      // Pale Champagne rim
        bladeGrad.addColorStop(0.14, "#7E786E");   // Muted stone grey
        bladeGrad.addColorStop(0.42, "#33312E");   // Deep Charcoal body
        bladeGrad.addColorStop(0.82, "#1D1C1B");   // Obsidian
        bladeGrad.addColorStop(1, "#141413");      // Base
      }

      ctx.fillStyle = bladeGrad;
      ctx.fill();

      // Sharp luminous blade crest highlight line
      ctx.shadowColor = "transparent";
      ctx.strokeStyle = i >= 14
        ? "rgba(252, 247, 231, 0.90)"
        : i >= 7
        ? "rgba(248, 217, 107, 0.70)"
        : "rgba(242, 235, 208, 0.45)";
      ctx.lineWidth = 1.4 * scale;
      ctx.stroke();

      ctx.restore();
    }

    animId = requestAnimationFrame(render);
  }

  // Initial immediate paint + animation loop start
  resize();
  render(performance.now());
})();
