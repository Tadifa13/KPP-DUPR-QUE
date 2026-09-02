/**
 * Browser side of the engine cross-check: same scenarios through the JS
 * engine, written as JSON for comparison against the PHP output.
 */
import fs from 'fs';
import { fileURLToPath } from 'url';
import path from 'path';
const here = path.dirname(fileURLToPath(import.meta.url));
const E = await import(path.join(here, '../../docs/js/engine.js'));

const scen = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));

// PHP rows use snake_case; map once so the engine sees its own shape.
const toRoster = (r) => r.map(p => ({
  id: p.id, name: p.name, dupr: p.dupr, status: p.status,
  adjustment: p.adjustment, offsetGames: p.offset_games,
  queuedAt: p.queued_at, priorityBoost: p.priority_boost,
  lastPlayedAt: p.last_played_at, lastSatAt: p.last_sat_at,
}));
const toMatches = (m) => m.map(x => ({
  team1: x.team1, team2: x.team2, s1: x.t1_score, s2: x.t2_score,
  target: x.target, ratingSnapshot: x.rating_snapshot,
}));

// minutesSince uses Date.now(); pin it so both engines see the same wait.
const FIXED = 1788003600000;
const realNow = Date.now;
Date.now = () => FIXED;

const out = scen.map(sc => {
  const roster = toRoster(sc.roster), matches = toMatches(sc.matches);
  const m = E.nextMatch(roster, matches, [], sc.format);
  const fallback = {};
  sc.roster.forEach(p => { fallback[p.id] = Number(p.dupr); });
  const gain = E.computeGainIndex(matches, fallback);
  const st = E.computeStandings(roster, matches, gain);
  return {
    match: m ? { t1: m.team1, t2: m.team2, q: m.quality, b: m.bracket,
                 a1: m.avg1, a2: m.avg2, e: m.exp1, btb: m.backToBack } : null,
    gain: Object.fromEntries(Object.entries(gain).map(([k,v]) => [k, {evidence:v.evidence, gain_index:v.gainIndex}])),
    standings: st.map(r => [r.id, r.w, r.l, r.pf, r.pa, r.gainIndex]),
    credit: E.walkInCredit(roster, matches, null, 3.4),
  };
});
Date.now = realNow;
fs.writeFileSync(process.argv[3], JSON.stringify(out, null, 2));
console.log('  js output written');
