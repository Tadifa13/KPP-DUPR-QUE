/* ===========================================================================
   Live 3D court view — Canvas 2D with a hand-rolled perspective projection.

   Every 3D library worth using ships from a CDN, and this app has to keep
   working with no internet under `default-src 'self'`. The maths here is small
   enough to write out: rotate the world about X, divide by depth, draw.

   It earns its place by being informational, not decorative — court occupancy,
   bracket and rally state readable at a glance from across the room.

   Reads state from a JSON script tag; degrades to nothing if absent.
   Honours prefers-reduced-motion by drawing one static frame.
   Pauses when off-screen or backgrounded so it costs nothing idle.
   =========================================================================== */
(function () {
  'use strict';

  var host = document.querySelector('[data-court3d]');
  if (!host) return;

  var canvas = host.querySelector('canvas');
  var dataEl = document.getElementById('court3d-data');
  if (!canvas || !dataEl) return;

  var courts;
  try {
    courts = JSON.parse(dataEl.textContent);
  } catch (e) {
    return;
  }
  if (!Array.isArray(courts) || !courts.length) return;

  var ctx = canvas.getContext('2d');
  if (!ctx) return;

  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // ---- palette, read from the stylesheet so themes stay in one place ----
  var css = getComputedStyle(document.documentElement);
  var C = {
    live:    (css.getPropertyValue('--primary') || '#2ee89a').trim(),
    pending: (css.getPropertyValue('--accent') || '#fcc63f').trim(),
    idle:    'rgba(255,255,255,.16)',
    line:    'rgba(255,255,255,.55)',
    dim:     'rgba(255,255,255,.28)'
  };

  // ---- geometry ---------------------------------------------------------
  // A pickleball court is 20ft x 44ft. Kitchen is 7ft either side of the net.
  var CW = 20, CL = 44, KITCHEN = 7, GAP = 9;
  var n = courts.length;
  var totalW = n * CW + (n - 1) * GAP;

  var cam = { pitch: 0.92, dist: 74, focal: 0, yaw: 0 };

  var W = 0, H = 0, dpr = 1;
  // Camera fit, solved from the scene instead of guessed. A fixed focal length
  // made a single court tiny and sliced four courts off the canvas edges.
  var fitFocal = 900, originY = 0;

  function fitCamera() {
    var cy = Math.cos(cam.pitch), sy = Math.sin(cam.pitch);
    var maxAbsX = 0, minY = Infinity, maxY = -Infinity;
    for (var i = 0; i < n; i++) {
      var x0 = -totalW / 2 + i * (CW + GAP);
      var xs = [x0, x0 + CW], zs = [-CL / 2, CL / 2], ys = [0, -6];
      for (var a = 0; a < xs.length; a++) {
        for (var b = 0; b < zs.length; b++) {
          for (var c = 0; c < ys.length; c++) {
            var yr = ys[c] * cy - zs[b] * sy;
            var zr = ys[c] * sy + zs[b] * cy;
            var inv = 1 / (zr + cam.dist);
            maxAbsX = Math.max(maxAbsX, Math.abs(xs[a] * inv));
            minY = Math.min(minY, yr * inv);
            maxY = Math.max(maxY, yr * inv);
          }
        }
      }
    }
    var byWidth = maxAbsX > 0 ? (W * 0.46) / maxAbsX : 900;
    var spanY = maxY - minY;
    var byHeight = spanY > 0 ? (H * 0.86) / spanY : 900;
    fitFocal = Math.max(60, Math.min(byWidth, byHeight));
    originY = H / 2 - ((minY + maxY) / 2) * fitFocal;
  }

  function resize() {
    var rect = host.getBoundingClientRect();
    if (!rect.width) return false;
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    W = rect.width; H = rect.height;
    canvas.width = Math.round(W * dpr);
    canvas.height = Math.round(H * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    fitCamera();
    return true;
  }

  /* Rotate about X by cam.pitch, then perspective-divide. */
  function project(x, y, z) {
    var cy = Math.cos(cam.pitch), sy = Math.sin(cam.pitch);
    var yr = y * cy - z * sy;
    var zr = y * sy + z * cy;
    var d = fitFocal / (zr + cam.dist);
    return {
      x: W / 2 + x * d,
      y: originY + yr * d,
      s: d
    };
  }

  function poly(pts, fill, stroke, width) {
    ctx.beginPath();
    for (var i = 0; i < pts.length; i++) {
      var p = pts[i];
      if (i === 0) ctx.moveTo(p.x, p.y); else ctx.lineTo(p.x, p.y);
    }
    ctx.closePath();
    if (fill) { ctx.fillStyle = fill; ctx.fill(); }
    if (stroke) { ctx.strokeStyle = stroke; ctx.lineWidth = width || 1; ctx.stroke(); }
  }

  function line(a, b, stroke, width) {
    ctx.beginPath();
    ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y);
    ctx.strokeStyle = stroke; ctx.lineWidth = width || 1; ctx.stroke();
  }

  function drawCourt(court, index, t) {
    var x0 = -totalW / 2 + index * (CW + GAP);
    var x1 = x0 + CW;
    var z0 = -CL / 2, z1 = CL / 2;

    var state = court.state || 'empty';
    var accent = state === 'live' ? C.live : (state === 'pending' ? C.pending : C.idle);

    var a = project(x0, 0, z0), b = project(x1, 0, z0),
        c = project(x1, 0, z1), d = project(x0, 0, z1);

    // Surface, tinted by state.
    var g = ctx.createLinearGradient(a.x, a.y, d.x, d.y);
    if (state === 'live') {
      g.addColorStop(0, 'rgba(46,232,154,.20)');
      g.addColorStop(1, 'rgba(46,232,154,.05)');
    } else if (state === 'pending') {
      g.addColorStop(0, 'rgba(252,198,63,.17)');
      g.addColorStop(1, 'rgba(252,198,63,.04)');
    } else {
      g.addColorStop(0, 'rgba(255,255,255,.05)');
      g.addColorStop(1, 'rgba(255,255,255,.015)');
    }
    poly([a, b, c, d], g, accent, state === 'empty' ? 1 : 1.6);

    // Kitchen lines and the centre service line.
    var lineCol = state === 'empty' ? C.dim : C.line;
    line(project(x0, 0, -KITCHEN), project(x1, 0, -KITCHEN), lineCol, 1);
    line(project(x0, 0, KITCHEN), project(x1, 0, KITCHEN), lineCol, 1);
    var midX = (x0 + x1) / 2;
    line(project(midX, 0, -KITCHEN), project(midX, 0, z0), lineCol, 1);
    line(project(midX, 0, KITCHEN), project(midX, 0, z1), lineCol, 1);

    // Net: posts plus a hanging band, drawn in world space so it foreshortens.
    var netH = 3.2;
    var pL = project(x0, 0, 0), pR = project(x1, 0, 0);
    var tL = project(x0, -netH, 0), tR = project(x1, -netH, 0);
    poly([pL, pR, tR, tL], 'rgba(255,255,255,.10)', null);
    line(tL, tR, 'rgba(255,255,255,.6)', 1.6);
    line(pL, tL, C.dim, 1.4);
    line(pR, tR, C.dim, 1.4);

    // Rally: a ball arcing over the net while the court is live.
    if (state === 'live' && !reduced) {
      var period = 2.6;
      var phase = ((t / 1000 + index * 0.55) % period) / period;
      var dir = phase < 0.5 ? 1 : -1;
      var u = phase < 0.5 ? phase * 2 : (phase - 0.5) * 2;
      var bz = (dir === 1 ? -1 : 1) * (1 - u * 2) * (CL * 0.34);
      var by = -(Math.sin(u * Math.PI) * 5.4 + 0.5);
      var bx = midX + Math.sin(u * Math.PI * 2 + index) * 3.2;
      var bp = project(bx, by, bz);
      var r = Math.max(2.2, 4.2 * bp.s / 14);

      // Shadow on the surface reads as height.
      var sp = project(bx, 0, bz);
      ctx.beginPath();
      ctx.ellipse(sp.x, sp.y, r * 1.5, r * 0.55, 0, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(0,0,0,.28)'; ctx.fill();

      ctx.beginPath();
      ctx.arc(bp.x, bp.y, r, 0, Math.PI * 2);
      ctx.fillStyle = C.pending; ctx.fill();
      ctx.shadowColor = C.pending; ctx.shadowBlur = 12;
      ctx.fill();
      ctx.shadowBlur = 0;
    }

    // Court number, standing upright at the back edge.
    var label = project(midX, -5.6, z0);
    ctx.save();
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.font = '700 ' + Math.max(13, Math.round(24 * label.s / 14)) +
               "px 'Barlow Condensed', sans-serif";
    ctx.fillStyle = accent === C.idle ? 'rgba(255,255,255,.4)' : accent;
    ctx.fillText(String(court.n), label.x, label.y);
    ctx.restore();
  }

  function frame(t) {
    ctx.clearRect(0, 0, W, H);

    // Very slow camera drift, purely to keep the scene from feeling like a
    // static image. Disabled under reduced motion.
    if (!reduced) {
      cam.pitch = 0.92 + Math.sin(t / 9000) * 0.045;
      fitCamera();
    }

    // Painter's algorithm: farthest court first.
    var order = courts.map(function (c, i) { return i; });
    for (var i = 0; i < order.length; i++) {
      drawCourt(courts[order[i]], order[i], t);
    }
  }

  // ---- run loop, paused when not visible --------------------------------
  var running = false, rafId = null;

  function loop(t) {
    if (!running) return;
    frame(t);
    rafId = requestAnimationFrame(loop);
  }

  function start() {
    if (running || reduced) return;
    running = true;
    rafId = requestAnimationFrame(loop);
  }

  function stop() {
    running = false;
    if (rafId) cancelAnimationFrame(rafId);
    rafId = null;
  }

  if (!resize()) return;
  frame(0);

  if (reduced) return;   // one static frame is the whole job

  if ('IntersectionObserver' in window) {
    new IntersectionObserver(function (entries) {
      entries[0].isIntersecting ? start() : stop();
    }, { threshold: 0.05 }).observe(host);
  } else {
    start();
  }

  document.addEventListener('visibilitychange', function () {
    document.hidden ? stop() : start();
  });

  var rt;
  window.addEventListener('resize', function () {
    clearTimeout(rt);
    rt = setTimeout(function () { if (resize()) frame(performance.now()); }, 150);
  });
}());
