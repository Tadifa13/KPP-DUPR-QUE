/* ===========================================================================
   KAMRYNNE QUE — matchmaking and rating engine, browser build.
   ---------------------------------------------------------------------------
   A faithful port of lib/rating.php and lib/matchmaker.php. The PHP build is
   the reference; this exists so the app can run with no server at all.

   Two implementations of the same maths is a real divergence risk, so it is
   controlled two ways: every constant below is copied from config/config.php,
   and tests.html runs the same assertions as tests/run.php. If you change a
   weight, change it in both and re-run both suites.
   =========================================================================== */

export const CFG = {
  DUPR_MIN: 2.00,
  DUPR_MAX: 8.00,
  DUPR_DEFAULT: 3.00,
  BRACKET_CUT: 3.00,
  BRACKET_SOFT_EDGE: 0.10,
  EXPECT_DIVISOR: 0.60,
  QUALITY_SLOPE: 220,
  ADJUST_CLAMP: 1.00,
  EVIDENCE_GAMES: 3,
  MAX_COURTS: 8,
  VALID_TARGETS: [11, 15, 21],
  DEFAULT_TARGET: 11,

  W_PLAYERS_AT_FLOOR: 10000,
  W_BACK_TO_BACK: 3000,
  W_MAX_GAMES: 400,
  W_RATING_SPREAD: 150,
  W_WAIT: 2,
  W_BOOST: 20,
  W_PAIRING_COST: 40,

  C_TEAM_GAP: 3.00,
  C_PARTNER_REPEAT: 0.25,
  C_OPPONENT_REPEAT: 0.04,

  SEARCH_POOL_CAP: 28,
};

/**
 * PHP's round(), reproduced exactly. Two differences from Math.round matter:
 *
 *   1. PHP rounds half away from zero; Math.round rounds half toward +Infinity,
 *      so they disagree on negatives like -0.5. gainIndex is routinely
 *      negative, so this is not hypothetical.
 *   2. Scaling by Math.pow(10, p) introduces binary error: 4.725 * 100 is
 *      472.49999999999994, which rounds to 4.72 while PHP gives 4.73. PHP's
 *      round() compensates internally. Shifting through the decimal string
 *      instead ("4.725e2" -> 472.5) sidesteps the multiply entirely.
 *
 * Caught by the PHP/JS cross-check, which disagreed on 3 of 40 scenarios
 * before this.
 */
export function phpRound(value, precision = 0) {
  if (!Number.isFinite(value)) return value;
  const sign = value < 0 ? -1 : 1;

  // PHP pre-rounds to 15 significant digits before rounding to the requested
  // precision. That is what makes round(4.575, 2) give 4.58 when the stored
  // double is really 4.5749999999999993. Skipping this step is a genuine
  // mismatch, not a rounding nicety — it moved a team average by 0.01.
  const abs = Number(Math.abs(value).toPrecision(15));

  // A value already in exponent form cannot take another exponent suffix.
  const s = String(abs);
  if (s.indexOf('e') !== -1 || s.indexOf('E') !== -1) {
    const f = Math.pow(10, precision);
    return sign * (Math.round(abs * f) / f);
  }

  const shifted = Number(s + 'e' + precision);
  const rounded = Math.round(shifted);          // abs value, so half-up == half-away
  return sign * Number(rounded + 'e-' + precision);
}

export const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));
export const mean = (a) => (a.length ? a.reduce((s, n) => s + n, 0) / a.length : 0);

export const pairKey = (a, b) => [a, b].sort().join('|');

export function ordinal(n) {
  const abs = Math.abs(n) % 100;
  if (abs >= 11 && abs <= 13) return n + 'th';
  return n + ['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'][Math.abs(n) % 10];
}

export function minutesSince(ms) {
  if (!ms) return 0;
  return Math.max(0, Math.round((Date.now() - ms) / 60000));
}

/* ------------------------------------------------------------- rating ---- */

/** E = 1 / (1 + 10 ^ ((r2 - r1) / EXPECT_DIVISOR)) */
export function expectedScore(r1, r2) {
  return 1 / (1 + Math.pow(10, (r2 - r1) / CFG.EXPECT_DIVISOR));
}

/** 100 at a coin-flip, 0 by roughly 95.5/4.5. */
export function matchQuality(expected) {
  return phpRound(clamp(100 - Math.abs(expected - 0.5) * CFG.QUALITY_SLOPE, 0, 100));
}

export function effectiveRating(dupr, adjustment = 0) {
  return phpRound(clamp(dupr + adjustment, CFG.DUPR_MIN, CFG.DUPR_MAX), 2);
}

export const bracketOf = (r) => (r >= CFG.BRACKET_CUT ? 'Intermediate' : 'Novice');

/** With a soft edge, a rating near the cut is eligible for both brackets. */
export function eligibleBrackets(rating) {
  const primary = bracketOf(rating);
  if (CFG.BRACKET_SOFT_EDGE <= 0) return [primary];
  if (Math.abs(rating - CFG.BRACKET_CUT) <= CFG.BRACKET_SOFT_EDGE) {
    return ['Novice', 'Intermediate'];
  }
  return [primary];
}

export function commonBracket(ratings) {
  if (!ratings.length) return null;
  let shared = eligibleBrackets(ratings[0]);
  for (let i = 1; i < ratings.length; i++) {
    const s = eligibleBrackets(ratings[i]);
    shared = shared.filter((b) => s.includes(b));
  }
  if (!shared.length) return null;
  const votes = {};
  ratings.forEach((r) => { const b = bracketOf(r); votes[b] = (votes[b] || 0) + 1; });
  for (const b of shared) {
    if ((votes[b] || 0) * 2 >= ratings.length) return b;
  }
  return shared[0];
}

/** Both whole, different, winner exactly on target, nobody negative. */
export function validScore(s1, s2, target) {
  return Number.isInteger(s1) && Number.isInteger(s2)
    && s1 !== s2 && Math.max(s1, s2) === target && Math.min(s1, s2) >= 0;
}

/**
 * gainIndex — performance against expectation.
 * Baselines are frozen at match time; margin of victory is continuous;
 * confidence ramps in over EVIDENCE_GAMES.
 */
export function computeGainIndex(matches, fallback) {
  const acc = {};
  Object.keys(fallback).forEach((id) => { acc[id] = { sum: 0, n: 0 }; });

  for (const m of matches) {
    const snap = m.ratingSnapshot || {};
    const base = (id) => (snap[id] && snap[id].official !== undefined)
      ? Number(snap[id].official)
      : Number(fallback[id] !== undefined ? fallback[id] : CFG.DUPR_DEFAULT);

    const t1 = m.team1 || [], t2 = m.team2 || [];
    if (!t1.length || !t2.length) continue;

    const avg1 = mean(t1.map(base));
    const avg2 = mean(t2.map(base));
    const exp1 = expectedScore(avg1, avg2);

    const s1 = Number(m.s1), s2 = Number(m.s2);
    const target = Number(m.target) || Math.max(s1, s2) || CFG.DEFAULT_TARGET;
    const margin = clamp(Math.abs(s1 - s2) / Math.max(1, target), 0, 1);
    const actual1 = s1 > s2 ? 0.5 + 0.5 * margin : 0.5 - 0.5 * margin;

    t1.forEach((id) => { if (acc[id]) { acc[id].sum += actual1 - exp1; acc[id].n++; } });
    t2.forEach((id) => { if (acc[id]) { acc[id].sum += (1 - actual1) - (1 - exp1); acc[id].n++; } });
  }

  const out = {};
  for (const [id, a] of Object.entries(acc)) {
    const confidence = a.n ? Math.min(1, a.n / CFG.EVIDENCE_GAMES) : 0;
    out[id] = {
      evidence: a.n,
      gainIndex: a.n ? phpRound((a.sum / a.n) * 100 * confidence, 1) : 0,
    };
  }
  return out;
}

/** Deliberately conservative: a full 100 maps to half the clamp. */
export function suggestedAdjustment(gainIndex, evidence) {
  if (evidence < CFG.EVIDENCE_GAMES) return 0;
  return phpRound(clamp((gainIndex / 100) * (CFG.ADJUST_CLAMP / 2),
    -CFG.ADJUST_CLAMP, CFG.ADJUST_CLAMP), 2);
}

/** Wins, then point differential, then games played, then id. */
export function computeStandings(roster, matches, gain = {}) {
  const rows = {};
  roster.forEach((p) => {
    rows[p.id] = {
      id: p.id, name: p.name, g: 0, w: 0, l: 0, pf: 0, pa: 0,
      gainIndex: (gain[p.id] && gain[p.id].gainIndex) || 0,
      evidence: (gain[p.id] && gain[p.id].evidence) || 0,
    };
  });

  for (const m of matches) {
    const s1 = Number(m.s1), s2 = Number(m.s2);
    const t1Won = s1 > s2;
    (m.team1 || []).forEach((id) => {
      const r = rows[id]; if (!r) return;
      r.g++; r[t1Won ? 'w' : 'l']++; r.pf += s1; r.pa += s2;
    });
    (m.team2 || []).forEach((id) => {
      const r = rows[id]; if (!r) return;
      r.g++; r[t1Won ? 'l' : 'w']++; r.pf += s2; r.pa += s1;
    });
  }

  return Object.values(rows).filter((r) => r.g > 0).sort((a, b) =>
    (b.w - a.w)
    || ((b.pf - b.pa) - (a.pf - a.pa))
    || (b.g - a.g)
    || (a.id < b.id ? -1 : a.id > b.id ? 1 : 0));
}

/* -------------------------------------------------------- matchmaking ---- */

const PAIRINGS = [
  [[0, 1], [2, 3]],
  [[0, 2], [1, 3]],
  [[0, 3], [1, 2]],
];

export function combinations(items, k) {
  const out = [];
  const n = items.length;
  if (k > n || k <= 0) return out;
  const idx = Array.from({ length: k }, (_, i) => i);
  for (;;) {
    out.push(idx.map((i) => items[i]));
    let i = k - 1;
    while (i >= 0 && idx[i] === i + n - k) i--;
    if (i < 0) return out;
    idx[i]++;
    for (let j = i + 1; j < k; j++) idx[j] = idx[j - 1] + 1;
  }
}

export function pairHistory(matches) {
  const partners = {}, opponents = {};
  for (const m of matches) {
    for (const side of [m.team1 || [], m.team2 || []]) {
      if (side.length > 1) {
        for (const pair of combinations(side, 2)) {
          const k = pairKey(pair[0], pair[1]);
          partners[k] = (partners[k] || 0) + 1;
        }
      }
    }
    for (const a of (m.team1 || [])) {
      for (const b of (m.team2 || [])) {
        const k = pairKey(a, b);
        opponents[k] = (opponents[k] || 0) + 1;
      }
    }
  }
  return { partners, opponents };
}

/** Arrival credit plus every match they appear in, in progress included. */
export function gamesPlayed(roster, matches) {
  const counts = {};
  roster.forEach((p) => { counts[p.id] = Number(p.offsetGames) || 0; });
  for (const m of matches) {
    [...(m.team1 || []), ...(m.team2 || [])].forEach((id) => {
      if (counts[id] !== undefined) counts[id]++;
    });
  }
  return counts;
}

export function buildCandidates(roster, matches, exclude = []) {
  const games = gamesPlayed(roster, matches);
  const skip = new Set(exclude);
  const out = [];

  for (const p of roster) {
    if (p.status !== 'ready' || skip.has(p.id)) continue;
    out.push({
      id: p.id,
      name: p.name,
      eff: effectiveRating(Number(p.dupr), Number(p.adjustment) || 0),
      games: games[p.id] || 0,
      wait: minutesSince(p.queuedAt),
      boost: Number(p.priorityBoost) || 0,
      backToBack: (Number(p.lastPlayedAt) || 0) > (Number(p.lastSatAt) || 0),
      queuedAt: Number(p.queuedAt) || 0,
    });
  }

  // Stable, explainable ordering: fewest games, then longest wait, then id.
  out.sort((a, b) => (a.games - b.games)
    || (a.queuedAt - b.queuedAt)
    || (a.id < b.id ? -1 : a.id > b.id ? 1 : 0));
  return out;
}

export function pairingCost(team1, team2, history) {
  const avg1 = mean(team1.map((p) => p.eff));
  const avg2 = mean(team2.map((p) => p.eff));

  let partnerRepeats = 0;
  for (const side of [team1, team2]) {
    if (side.length > 1) {
      partnerRepeats += history.partners[pairKey(side[0].id, side[1].id)] || 0;
    }
  }
  let opponentRepeats = 0;
  for (const a of team1) {
    for (const b of team2) opponentRepeats += history.opponents[pairKey(a.id, b.id)] || 0;
  }

  return Math.abs(avg1 - avg2) * CFG.C_TEAM_GAP
    + partnerRepeats * CFG.C_PARTNER_REPEAT
    + opponentRepeats * CFG.C_OPPONENT_REPEAT;
}

export function bestPairing(four, history) {
  let best = null;
  for (const split of PAIRINGS) {
    const t1 = [four[split[0][0]], four[split[0][1]]];
    const t2 = [four[split[1][0]], four[split[1][1]]];
    const cost = pairingCost(t1, t2, history);
    if (best === null || cost < best.cost) {
      best = { team1: t1, team2: t2, cost, avg1: mean(t1.map(p => p.eff)), avg2: mean(t2.map(p => p.eff)) };
    }
  }
  best.diff = Math.abs(best.avg1 - best.avg2);
  return best;
}

/** Weights spaced to act as a priority ladder, not a blend. */
export function groupScore(group, floor, cost) {
  const atFloor = group.filter((p) => p.games === floor).length;
  const backToBack = group.filter((p) => p.backToBack).length;
  const maxGames = Math.max(...group.map((p) => p.games));
  const ratings = group.map((p) => p.eff);
  const spread = Math.max(...ratings) - Math.min(...ratings);
  const waitTerm = group.reduce((s, p) => s + p.wait + p.boost * CFG.W_BOOST, 0);

  return atFloor * CFG.W_PLAYERS_AT_FLOOR
    - backToBack * CFG.W_BACK_TO_BACK
    - maxGames * CFG.W_MAX_GAMES
    - spread * CFG.W_RATING_SPREAD
    + waitTerm * CFG.W_WAIT
    - cost * CFG.W_PAIRING_COST;
}

export function gamesFloor(candidates, groupSize) {
  const byBracket = {};
  candidates.forEach((c) => {
    eligibleBrackets(c.eff).forEach((b) => { (byBracket[b] = byBracket[b] || []).push(c); });
  });
  let floor = null;
  for (const members of Object.values(byBracket)) {
    if (members.length < groupSize) continue;
    const m = Math.min(...members.map((x) => x.games));
    floor = floor === null ? m : Math.min(floor, m);
  }
  return floor;
}

/**
 * Credit for a mid-session arrival, measured against the bracket they can
 * actually play in — a bracket too small to field a match leaves its members
 * stuck on zero forever, and copying that zero re-opens the deadlock.
 */
export function walkInCredit(roster, matches, joiningId = null, joiningRating = null) {
  const games = gamesPlayed(roster, matches);
  const brackets = joiningRating !== null ? eligibleBrackets(joiningRating) : null;
  const peers = [], field = [];

  for (const p of roster) {
    if (p.id === joiningId || p.status === 'done') continue;
    const n = games[p.id] || 0;
    field.push(n);
    if (brackets) {
      const eff = effectiveRating(Number(p.dupr), Number(p.adjustment) || 0);
      if (eligibleBrackets(eff).some((b) => brackets.includes(b))) peers.push(n);
    }
  }
  const source = peers.length ? peers : field;
  return source.length ? Math.max(0, Math.min(...source)) : 0;
}

function buildReason(bracket, backToBack, diff, trimmed) {
  const parts = [
    bracket + ' only',
    'fewest games first',
    backToBack > 0
      ? `${backToBack} consecutive player${backToBack === 1 ? '' : 's'} unavoidable for fairness`
      : 'fresh rotation — no back-to-back players',
    'team gap ' + diff.toFixed(2),
  ];
  if (trimmed) parts.push(`large field — searched the longest-waiting ${CFG.SEARCH_POOL_CAP}`);
  return parts.join(' · ');
}

/** Pick the next match, or null when none is legal. */
export function nextMatch(roster, matches, exclude = [], format = 'doubles') {
  const groupSize = format === 'singles' ? 2 : 4;
  const candidates = buildCandidates(roster, matches, exclude);
  if (candidates.length < groupSize) return null;

  const floor = gamesFloor(candidates, groupSize);
  if (floor === null) return null;

  // Both prunings are exact — neither can discard a legal group.
  const pool = candidates.filter((c) => c.games <= floor + 1);
  const byBracket = {};
  pool.forEach((c) => {
    eligibleBrackets(c.eff).forEach((b) => { (byBracket[b] = byBracket[b] || []).push(c); });
  });

  const history = pairHistory(matches);
  let best = null, trimmed = false;

  for (const [bracket, all] of Object.entries(byBracket)) {
    let members = all;
    if (members.length < groupSize) continue;
    if (members.length > CFG.SEARCH_POOL_CAP) {
      members = members.slice(0, CFG.SEARCH_POOL_CAP);
      trimmed = true;
    }

    for (const group of combinations(members, groupSize)) {
      const g = group.map((x) => x.games);
      if (Math.min(...g) > floor || Math.max(...g) > floor + 1) continue;
      if (commonBracket(group.map((x) => x.eff)) === null) continue;

      let pairing;
      if (groupSize === 2) {
        const t1 = [group[0]], t2 = [group[1]];
        pairing = {
          team1: t1, team2: t2, cost: pairingCost(t1, t2, history),
          avg1: group[0].eff, avg2: group[1].eff,
          diff: Math.abs(group[0].eff - group[1].eff),
        };
      } else {
        pairing = bestPairing(group, history);
      }

      const score = groupScore(group, floor, pairing.cost);
      // Strict improvement only; candidates are pre-sorted, so ties resolve to
      // the group that has been waiting longest.
      if (best === null || score > best.score) {
        best = { score, group, pairing, bracket };
      }
    }
  }

  if (best === null) return null;

  const p = best.pairing;
  const exp1 = expectedScore(p.avg1, p.avg2);
  const backToBack = best.group.filter((x) => x.backToBack).length;
  const snapshot = {};
  best.group.forEach((x) => { snapshot[x.id] = { official: x.eff }; });

  return {
    team1: p.team1.map((x) => x.id),
    team2: p.team2.map((x) => x.id),
    avg1: phpRound(p.avg1, 2),
    avg2: phpRound(p.avg2, 2),
    exp1: phpRound(exp1, 4),
    quality: matchQuality(exp1),
    bracket: best.bracket,
    backToBack,
    ratingSnapshot: snapshot,
    score: best.score,
    reason: buildReason(best.bracket, backToBack, p.diff, trimmed),
  };
}

/* -------------------------------------------------------------- input ---- */

/** One player per line, "Name D.DD", with per-line error reporting. */
export function parseRosterBlock(text, min = CFG.DUPR_MIN, max = CFG.DUPR_MAX) {
  const valid = [], errors = [];
  const lines = String(text || '').split(/\r\n|\r|\n/);

  lines.forEach((raw, i) => {
    const line = raw.trim();
    if (line === '') return;
    const m = line.match(/^(.*?)\s+([0-9](?:\.[0-9]{1,2})?)$/);
    if (!m) {
      errors.push(`Line ${i + 1}: use "Name DUPR", e.g. "Dana Whitfield 3.25"`);
      return;
    }
    const name = m[1].trim();
    const dupr = Number(m[2]);
    if (!name || !Number.isFinite(dupr) || dupr < min || dupr > max) {
      errors.push(`Line ${i + 1}: DUPR must be between ${min.toFixed(2)} and ${max.toFixed(2)}`);
      return;
    }
    valid.push({ name, dupr: phpRound(dupr, 2) });
  });

  return { valid, errors };
}

/** Reclub-ready CSV. Columns match the PHP build byte for byte. */
export function exportCsv(session, matches, names) {
  const q = (v) => '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"';
  const rows = [[
    'Game', 'Format', 'Court', 'Target',
    'Side 1 Player 1', 'Side 1 Player 2', 'Side 1 Score', 'Side 1 Result',
    'Side 2 Player 1', 'Side 2 Player 2', 'Side 2 Score', 'Side 2 Result',
    'Reclub Status',
  ]];
  matches.forEach((m, i) => {
    const t1Won = Number(m.s1) > Number(m.s2);
    rows.push([
      i + 1, m.format || session.format, m.court, m.target || CFG.DEFAULT_TARGET,
      names[m.team1[0]] || '', names[m.team1[1]] || '', m.s1, t1Won ? 'WINNER' : 'LOSER',
      names[m.team2[0]] || '', names[m.team2[1]] || '', m.s2, t1Won ? 'LOSER' : 'WINNER',
      m.reclubEntered ? 'Entered' : 'Not entered',
    ]);
  });
  return rows.map((r) => r.map(q).join(',')).join('\r\n');
}

/** Plain-text entry list, for pasting into Reclub by hand. */
export function exportText(session, matches, names) {
  const lines = [session.name, `${matches.length} completed games — Reclub entry list`, ''];
  matches.forEach((m, i) => {
    const singles = (m.format || session.format) === 'singles';
    const label = singles ? 'Player' : 'Team';
    const t1Won = Number(m.s1) > Number(m.s2);
    const side = (ids) => ids.map((id) => names[id] || 'Player').join(' & ');
    lines.push(`Game ${i + 1} · ${singles ? 'SINGLES' : 'DOUBLES'} · Court ${m.court} · To ${m.target || CFG.DEFAULT_TARGET}`);
    lines.push(`${label} 1 — ${side(m.team1)} — ${m.s1} — ${t1Won ? 'WINNER' : 'LOSER'}`);
    lines.push(`${label} 2 — ${side(m.team2)} — ${m.s2} — ${t1Won ? 'LOSER' : 'WINNER'}`);
    lines.push('');
  });
  if (!matches.length) lines.push('No completed games recorded.');
  return lines.join('\n');
}
