/* ===========================================================================
   Live 3D court view — browser build.

   Same hand-rolled perspective projection as assets/court3d.js in the server
   build: rotate the world about X, divide by depth, draw. Restructured as a
   module with mount/update, because this app re-renders its views rather than
   reloading the page, so the canvas has to be re-bound and the old animation
   loop stopped.
   =========================================================================== */

let running = false;
let rafId = null;
let teardown = null;

const CW = 20, CL = 44, KITCHEN = 7, GAP = 9;

export function mountCourt3d(host, courts) {
  unmount();
  if (!host || !Array.isArray(courts) || !courts.length) return;

  const canvas = host.querySelector('canvas');
  const ctx = canvas && canvas.getContext('2d');
  if (!ctx) return;

  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const css = getComputedStyle(document.documentElement);
  const C = {
    live: (css.getPropertyValue('--primary') || '#2ee89a').trim(),
    pending: (css.getPropertyValue('--accent') || '#fcc63f').trim(),
    idle: 'rgba(255,255,255,.16)',
    line: 'rgba(255,255,255,.55)',
    dim: 'rgba(255,255,255,.28)',
  };

  const n = courts.length;
  const totalW = n * CW + (n - 1) * GAP;
  const cam = { pitch: 0.92, dist: 74, focal: 0 };
  let W = 0, H = 0;
  // Camera fit, recomputed on resize: solved from the scene rather than
  // guessed, so any number of courts fills the canvas without spilling off it.
  // A fixed focal length made one court tiny and sliced four courts in half.
  let fitFocal = 900, originY = 0;

  function fitCamera() {
    const cy = Math.cos(cam.pitch), sy = Math.sin(cam.pitch);
    let maxAbsX = 0, minY = Infinity, maxY = -Infinity;

    for (let i = 0; i < n; i++) {
      const x0 = -totalW / 2 + i * (CW + GAP);
      const xs = [x0, x0 + CW];
      const zs = [-CL / 2, CL / 2];
      // y = 0 is the surface; -6 clears the net and the court number above it.
      const ys = [0, -6];
      for (const x of xs) for (const z of zs) for (const y of ys) {
        const yr = y * cy - z * sy;
        const zr = y * sy + z * cy;
        const inv = 1 / (zr + cam.dist);
        maxAbsX = Math.max(maxAbsX, Math.abs(x * inv));
        minY = Math.min(minY, yr * inv);
        maxY = Math.max(maxY, yr * inv);
      }
    }

    const byWidth = maxAbsX > 0 ? (W * 0.46) / maxAbsX : 900;
    const spanY = maxY - minY;
    const byHeight = spanY > 0 ? (H * 0.86) / spanY : 900;
    fitFocal = Math.max(60, Math.min(byWidth, byHeight));
    originY = H / 2 - ((minY + maxY) / 2) * fitFocal;
  }

  function resize() {
    const rect = host.getBoundingClientRect();
    if (!rect.width) return false;
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    W = rect.width; H = rect.height;
    canvas.width = Math.round(W * dpr);
    canvas.height = Math.round(H * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    fitCamera();
    return true;
  }

  const project = (x, y, z) => {
    const cy = Math.cos(cam.pitch), sy = Math.sin(cam.pitch);
    const yr = y * cy - z * sy;
    const zr = y * sy + z * cy;
    const d = fitFocal / (zr + cam.dist);
    return { x: W / 2 + x * d, y: originY + yr * d, s: d };
  };

  const poly = (pts, fill, stroke, width) => {
    ctx.beginPath();
    pts.forEach((p, i) => (i ? ctx.lineTo(p.x, p.y) : ctx.moveTo(p.x, p.y)));
    ctx.closePath();
    if (fill) { ctx.fillStyle = fill; ctx.fill(); }
    if (stroke) { ctx.strokeStyle = stroke; ctx.lineWidth = width || 1; ctx.stroke(); }
  };
  const line = (a, b, stroke, width) => {
    ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y);
    ctx.strokeStyle = stroke; ctx.lineWidth = width || 1; ctx.stroke();
  };

  function drawCourt(court, index, t) {
    const x0 = -totalW / 2 + index * (CW + GAP);
    const x1 = x0 + CW;
    const z0 = -CL / 2, z1 = CL / 2;
    const state = court.state || 'empty';
    const accent = state === 'live' ? C.live : (state === 'pending' ? C.pending : C.idle);

    const a = project(x0, 0, z0), b = project(x1, 0, z0),
          c = project(x1, 0, z1), d = project(x0, 0, z1);

    const g = ctx.createLinearGradient(a.x, a.y, d.x, d.y);
    if (state === 'live') {
      g.addColorStop(0, 'rgba(46,232,154,.20)'); g.addColorStop(1, 'rgba(46,232,154,.05)');
    } else if (state === 'pending') {
      g.addColorStop(0, 'rgba(252,198,63,.17)'); g.addColorStop(1, 'rgba(252,198,63,.04)');
    } else {
      g.addColorStop(0, 'rgba(255,255,255,.05)'); g.addColorStop(1, 'rgba(255,255,255,.015)');
    }
    poly([a, b, c, d], g, accent, state === 'empty' ? 1 : 1.6);

    const lineCol = state === 'empty' ? C.dim : C.line;
    line(project(x0, 0, -KITCHEN), project(x1, 0, -KITCHEN), lineCol, 1);
    line(project(x0, 0, KITCHEN), project(x1, 0, KITCHEN), lineCol, 1);
    const midX = (x0 + x1) / 2;
    line(project(midX, 0, -KITCHEN), project(midX, 0, z0), lineCol, 1);
    line(project(midX, 0, KITCHEN), project(midX, 0, z1), lineCol, 1);

    const netH = 3.2;
    const pL = project(x0, 0, 0), pR = project(x1, 0, 0);
    const tL = project(x0, -netH, 0), tR = project(x1, -netH, 0);
    poly([pL, pR, tR, tL], 'rgba(255,255,255,.10)', null);
    line(tL, tR, 'rgba(255,255,255,.6)', 1.6);
    line(pL, tL, C.dim, 1.4);
    line(pR, tR, C.dim, 1.4);

    if (state === 'live' && !reduced) {
      const period = 2.6;
      const phase = ((t / 1000 + index * 0.55) % period) / period;
      const dir = phase < 0.5 ? 1 : -1;
      const u = phase < 0.5 ? phase * 2 : (phase - 0.5) * 2;
      const bz = (dir === 1 ? -1 : 1) * (1 - u * 2) * (CL * 0.34);
      const by = -(Math.sin(u * Math.PI) * 5.4 + 0.5);
      const bx = midX + Math.sin(u * Math.PI * 2 + index) * 3.2;
      const bp = project(bx, by, bz);
      const r = Math.max(2.2, 4.2 * bp.s / 14);

      const sp = project(bx, 0, bz);
      ctx.beginPath();
      ctx.ellipse(sp.x, sp.y, r * 1.5, r * 0.55, 0, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(0,0,0,.28)'; ctx.fill();

      ctx.beginPath();
      ctx.arc(bp.x, bp.y, r, 0, Math.PI * 2);
      ctx.fillStyle = C.pending; ctx.fill();
      ctx.shadowColor = C.pending; ctx.shadowBlur = 12; ctx.fill(); ctx.shadowBlur = 0;
    }

    const label = project(midX, -5.6, z0);
    ctx.save();
    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.font = '700 ' + Math.max(13, Math.round(24 * label.s / 14)) + "px 'Barlow Condensed', sans-serif";
    ctx.fillStyle = accent === C.idle ? 'rgba(255,255,255,.4)' : accent;
    ctx.fillText(String(court.n), label.x, label.y);
    ctx.restore();
  }

  function frame(t) {
    ctx.clearRect(0, 0, W, H);
    if (!reduced) { cam.pitch = 0.92 + Math.sin(t / 9000) * 0.045; fitCamera(); }
    courts.forEach((c, i) => drawCourt(c, i, t));
  }

  function loop(t) { if (!running) return; frame(t); rafId = requestAnimationFrame(loop); }
  function start() { if (running || reduced) return; running = true; rafId = requestAnimationFrame(loop); }
  function stop() { running = false; if (rafId) cancelAnimationFrame(rafId); rafId = null; }

  if (!resize()) return;
  frame(0);
  if (reduced) return;

  let io = null;
  if ('IntersectionObserver' in window) {
    io = new IntersectionObserver((e) => (e[0].isIntersecting ? start() : stop()), { threshold: 0.05 });
    io.observe(host);
  } else {
    start();
  }

  const onVis = () => (document.hidden ? stop() : start());
  document.addEventListener('visibilitychange', onVis);

  let rt;
  const onResize = () => {
    clearTimeout(rt);
    rt = setTimeout(() => { if (resize()) frame(performance.now()); }, 150);
  };
  window.addEventListener('resize', onResize);

  teardown = () => {
    stop();
    if (io) io.disconnect();
    document.removeEventListener('visibilitychange', onVis);
    window.removeEventListener('resize', onResize);
    clearTimeout(rt);
  };
}

/** Stop the previous instance before a re-render replaces the canvas. */
export function unmount() {
  if (teardown) { teardown(); teardown = null; }
  running = false;
  if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
}
