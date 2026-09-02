/* ===========================================================================
   Browser build — views and event wiring.
   Same surfaces as the server build: Play, Roster, Standings, History.
   =========================================================================== */

import * as E from './engine.js';
import * as S from './store.js';
import { mountCourt3d, unmount as unmountCourt3d } from './court3d.js';

let state = S.load();
let view = location.hash.replace('#', '') || 'play';

const $ = (sel, root = document) => root.querySelector(sel);
const app = $('#app');
const esc = (s) => String(s == null ? '' : s)
  .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
  .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

/* --------------------------------------------------------------- icons --- */
const ICONS = {
  court: '<rect x="2.5" y="4.5" width="19" height="15" rx="1.5"/><path d="M12 4.5v15M2.5 12h19"/>',
  users: '<circle cx="9" cy="8" r="3.25"/><path d="M2.75 19.5a6.25 6.25 0 0 1 12.5 0"/><path d="M16.5 5.2a3.25 3.25 0 0 1 0 5.6"/><path d="M18 14.4a6.25 6.25 0 0 1 3.25 5.1"/>',
  trophy: '<path d="M7 4.5h10v4a5 5 0 0 1-10 0z"/><path d="M7 6H4.5v1.5A3.5 3.5 0 0 0 8 11"/><path d="M17 6h2.5v1.5A3.5 3.5 0 0 1 16 11"/><path d="M12 13.5v3M8.5 19.5h7M10 16.5h4"/>',
  clock: '<circle cx="12" cy="12" r="8.75"/><path d="M12 7v5.2l3.3 2"/>',
  play: '<path d="M7.5 5.2v13.6a.7.7 0 0 0 1.07.6l10.7-6.8a.7.7 0 0 0 0-1.2L8.57 4.6a.7.7 0 0 0-1.07.6z"/>',
  check: '<path d="M4.5 12.6 9.5 17.5 19.5 6.8"/>',
  x: '<path d="M6 6l12 12M18 6 6 18"/>',
  rotate: '<path d="M20 12a8 8 0 1 1-2.6-5.9"/><path d="M20.5 4v4.2h-4.2"/>',
  pause: '<rect x="7" y="5" width="3.6" height="14" rx="1.2"/><rect x="13.4" y="5" width="3.6" height="14" rx="1.2"/>',
  'arrow-up': '<path d="M12 19.5v-15M5.75 10.75 12 4.5l6.25 6.25"/>',
  'user-plus': '<circle cx="9.5" cy="8" r="3.25"/><path d="M3.25 19.5a6.25 6.25 0 0 1 12.5 0"/><path d="M18.5 8v5M16 10.5h5"/>',
  download: '<path d="M12 4v11.5M8.2 12l3.8 3.8 3.8-3.8"/><path d="M5.5 19.5h13"/>',
  clipboard: '<path d="M9 4.5H7.5A1.5 1.5 0 0 0 6 6v13.5A1.5 1.5 0 0 0 7.5 21h9a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H15"/><rect x="9" y="3" width="6" height="3.2" rx="1"/><path d="M9 11h6M9 15h4"/>',
  shield: '<path d="M12 3.2 4.8 6v5.4c0 4.3 3 8 7.2 9.4 4.2-1.4 7.2-5.1 7.2-9.4V6z"/><path d="M9.2 12.2 11.3 14.3 15 10.6"/>',
  target: '<circle cx="12" cy="12" r="8.75"/><circle cx="12" cy="12" r="4.75"/><circle cx="12" cy="12" r="1"/>',
  lock: '<rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8.25 10.5V7.75a3.75 3.75 0 0 1 7.5 0v2.75"/>',
  chevron: '<path d="M9 5.5 15.5 12 9 18.5"/>',
};
const icon = (n, size = 20) => ICONS[n]
  ? `<svg class="ico" width="${size}" height="${size}" viewBox="0 0 24 24" fill="none"
      stroke="currentColor" stroke-width="1.75" stroke-linecap="round"
      stroke-linejoin="round" aria-hidden="true">${ICONS[n]}</svg>`
  : '';

const COURT_WHEEL = ['#8fae9d', '#6cb6ff', '#ff8a5c', '#c9a227', '#f472b6', '#2ee89a', '#a78bfa', '#fcc63f'];
const courtColour = (c) => COURT_WHEEL[(c - 1) % COURT_WHEEL.length];

const fmtDupr = (v) => Number(v).toFixed(2);
const fmtGain = (v) => (v > 0 ? '+' : '') + Number(v).toFixed(1);

function toast(msg, tone = 'ok') {
  const el = document.createElement('div');
  el.className = 'flash flash-' + tone;
  el.setAttribute('role', 'status');
  el.textContent = msg;
  $('#flash').appendChild(el);
  setTimeout(() => el.remove(), 4200);
}

function commit(msg, tone) {
  const r = S.save(state);
  if (!r.ok) {
    toast('Could not save — this browser is out of storage. Export a backup now.', 'bad');
  } else if (msg) {
    toast(msg, tone);
  }
  render();
}

/* -------------------------------------------------------- session model -- */

const sessionPlayers = () => {
  if (!state.session) return [];
  return state.players
    .filter((p) => !p.archived && state.session.players[p.id])
    .map((p) => ({ ...p, ...state.session.players[p.id] }));
};

const completed = () => (state.session ? state.session.matches.filter((m) => m.state === 'complete') : []);
const openMatches = () => (state.session ? state.session.matches.filter((m) => m.state !== 'complete') : []);

function nameMap() {
  const m = {};
  state.players.forEach((p) => { m[p.id] = p.name; });
  return m;
}

function gainAndStandings() {
  const roster = sessionPlayers();
  const fallback = {};
  roster.forEach((p) => { fallback[p.id] = Number(p.dupr); });
  const done = completed();
  const gain = E.computeGainIndex(done, fallback);
  return { gain, standings: E.computeStandings(roster, done, gain), done, roster };
}

/* ---------------------------------------------------------------- views -- */

function chrome() {
  const tabs = [
    ['play', 'Play', 'court'],
    ['roster', 'Roster', 'users'],
    ['standings', 'Standings', 'trophy'],
    ['reclub', 'Reclub', 'clipboard'],
    ['history', 'History', 'clock'],
    ['settings', 'Settings', 'shield'],
  ];
  const nav = tabs.map(([k, label, ic]) =>
    `<a href="#${k}"${view === k ? ' class="on" aria-current="page"' : ''}>${icon(ic, 17)}${label}</a>`).join('');

  $('#topnav').innerHTML = nav;
  $('#tabnav').innerHTML = nav;
}

function viewPlay() {
  const players = state.players.filter((p) => !p.archived);

  if (!state.session) {
    const last = state.history[0];
    const courts = last ? last.courts : 2;
    return `
      <section class="hero">
        <div class="hero-art"><img src="assets/art-paddle.svg" alt="" width="420" height="320"></div>
        <div class="hero-body">
          <p class="eyebrow">No session running</p>
          <h1>Start tonight's social</h1>
          <p>The roster seeds from your club list — ${players.length} active player${players.length === 1 ? '' : 's'}.</p>
          ${players.length < 4 ? `
            <div class="callout">
              <span class="callout-ring">${icon('users', 24)}</span>
              <div class="callout-body">
                <p style="margin:0 0 12px">You need at least four players before a session can call a match.</p>
                <a class="btn btn-primary" href="#roster">Add players ${icon('chevron', 15)}</a>
              </div>
            </div>` : `
            <form class="callout" style="display:block" data-act="start-session">
              <div class="field"><label for="sname">Session name</label>
                <input type="text" id="sname" name="name" value="KAMRYNNE QUE" required></div>
              <div class="field-row">
                <div class="field"><label for="scourts">Courts</label>
                  <select id="scourts" name="courts">${Array.from({ length: E.CFG.MAX_COURTS }, (_, i) =>
                    `<option value="${i + 1}"${i + 1 === courts ? ' selected' : ''}>${i + 1}</option>`).join('')}</select></div>
                <div class="field"><label for="starget">Games to</label>
                  <select id="starget" name="target">${E.CFG.VALID_TARGETS.map((t) =>
                    `<option value="${t}"${t === E.CFG.DEFAULT_TARGET ? ' selected' : ''}>${t}</option>`).join('')}</select></div>
                <div class="field"><label for="sformat">Format</label>
                  <select id="sformat" name="format"><option value="doubles">Doubles</option><option value="singles">Singles</option></select></div>
              </div>
              <button class="btn btn-primary btn-block" type="submit">${icon('play', 18)}Start session</button>
            </form>`}
        </div>
      </section>
      <div class="statgrid">
        ${statCard('users', players.length, 'Active players', 'On the club list')}
        ${statCard('court', courts, 'Courts', 'Ready to open')}
        ${statCard('trophy', 0, 'Matches', 'Played tonight')}
        ${statCard('clock', 0, 'Queue', 'Players waiting')}
      </div>`;
  }

  const s = state.session;
  const names = nameMap();
  const roster = sessionPlayers();
  const games = E.gamesPlayed(roster, s.matches);
  const onCourt = {};
  openMatches().forEach((m) => { onCourt[m.court] = m; });
  const ready = roster.filter((p) => p.status === 'ready')
    .sort((a, b) => (games[a.id] - games[b.id]) || (a.queuedAt - b.queuedAt) || (a.name < b.name ? -1 : 1));
  const floor = ready.length ? Math.min(...ready.map((p) => games[p.id] || 0)) : 0;
  const notIn = state.players.filter((p) => !p.archived && !s.players[p.id]);

  let html = `
    <div class="split">
      <div><p class="eyebrow"><span class="live-dot"></span>Session live</p><h1>${esc(s.name)}</h1></div>
      <div class="chips">
        <span class="chip chip-muted">${esc(s.format)}</span>
        <span class="chip chip-muted">To ${s.target}</span>
        <span class="chip chip-muted">${completed().length} games</span>
      </div>
    </div>
    <div class="statgrid" style="margin-top:var(--s4)">
      ${statCard('users', ready.length, 'Ready', 'In the queue')}
      ${statCard('court', Object.keys(onCourt).length + '/' + s.courts, 'On court', 'In play now')}
      ${statCard('trophy', completed().length, 'Matches', 'Played tonight')}
      ${statCard('clipboard', roster.length, 'Roster', 'In this session')}
    </div>
    ${court3dBlock(s, onCourt)}
    <h2>Courts</h2>`;

  for (let c = 1; c <= s.courts; c++) {
    const m = onCourt[c];
    if (!m) {
      const need = s.format === 'singles' ? 2 : 4;
      html += `<div class="card court-card empty">
        <div class="card-head"><span class="court-no">
          <span class="dot" style="display:inline-block;width:9px;height:9px;border-radius:50%;background:${courtColour(c)}"></span>
          Court ${c}</span><span class="chip chip-muted">Open</span></div>
        <button class="btn btn-primary btn-block" data-act="call" data-court="${c}"${ready.length < need ? ' disabled' : ''}>
          ${icon('play', 18)}Call next match</button>
        ${ready.length < need ? '<p class="reason">Not enough ready players to fill a court.</p>' : ''}
      </div>`;
      continue;
    }
    html += `<div class="card court-card ${m.state}">
      <div class="card-head">
        <span class="court-no">
          <span class="dot" style="display:inline-block;width:9px;height:9px;border-radius:50%;background:${courtColour(c)}"></span>
          Court ${c}</span>
        <div class="chips">${bracketChip(m.bracket)}
          <span class="chip ${m.quality >= 75 ? 'chip-good' : m.quality >= 45 ? 'chip-warn' : 'chip-bad'}">${icon('target', 11)}${m.quality}% even</span>
          <span class="chip ${m.state === 'live' ? 'chip-good' : 'chip-warn'}">${m.state === 'live' ? 'On court' : 'Called'}</span>
        </div>
      </div>
      <div class="matchup">
        <div class="side">${m.team1.map((id) => `<span class="pname">${esc(names[id])}</span>`).join('')}</div>
        <div class="vs">VS</div>
        <div class="side right">${m.team2.map((id) => `<span class="pname">${esc(names[id])}</span>`).join('')}</div>
      </div>
      ${m.reason ? `<p class="reason">${esc(m.reason)}</p>` : ''}
      ${m.state === 'pending' ? `
        <div class="btn-row" style="margin-top:12px">
          <button class="btn btn-primary" style="flex:2" data-act="start" data-id="${m.id}">${icon('play', 18)}Start game</button>
          <button class="btn btn-ghost" data-act="cancel" data-id="${m.id}">${icon('rotate', 16)}Redraw</button>
        </div>` : `
        <form style="margin-top:12px" data-act="score" data-id="${m.id}">
          <div class="field-row">
            <div class="field score-input"><label>Side 1</label>
              <input type="number" name="s1" min="0" max="99" inputmode="numeric" required></div>
            <div class="field score-input"><label>Side 2</label>
              <input type="number" name="s2" min="0" max="99" inputmode="numeric" required></div>
          </div>
          <button class="btn btn-primary btn-block" type="submit">${icon('check', 18)}Record result</button>
          <p class="hint">Winner must finish exactly on ${m.target}.</p>
        </form>
        <button class="btn btn-ghost btn-sm" data-act="cancel" data-id="${m.id}">${icon('x', 15)}Abandon game</button>`}
    </div>`;
  }

  html += `<h2>Queue <span class="muted tiny">· ${ready.length} ready</span></h2>`;
  html += ready.length ? `<div class="plist">${ready.map((p) => {
    const g = games[p.id] || 0;
    const eff = E.effectiveRating(Number(p.dupr), Number(p.adjustment) || 0);
    return `<div class="prow">
      <div class="grow">
        <div class="nm">${esc(p.name)}
          ${g === floor ? '<span class="chip chip-good">next up</span>' : ''}
          ${p.priorityBoost > 0 ? `<span class="chip chip-warn">boost ×${p.priorityBoost}</span>` : ''}
        </div>
        <div class="meta">${g} game${g === 1 ? '' : 's'} · waiting ${E.minutesSince(p.queuedAt)}m · ${E.bracketOf(eff)}</div>
      </div>
      <span class="dupr">${fmtDupr(eff)}</span>
      <button class="btn btn-ghost btn-sm" data-act="boost" data-id="${p.id}" aria-label="Move up the queue">${icon('arrow-up', 16)}</button>
      <button class="btn btn-ghost btn-sm" data-act="sit" data-id="${p.id}">${icon('pause', 15)}Sit</button>
    </div>`;
  }).join('')}</div>` : '<div class="empty">Nobody is waiting.</div>';

  const resting = roster.filter((p) => p.status === 'resting');
  if (resting.length) {
    html += `<h2>Sitting out</h2><div class="plist">${resting.map((p) => `
      <div class="prow is-resting"><div class="grow"><div class="nm">${esc(p.name)}</div></div>
        <button class="btn btn-sm" data-act="rejoin" data-id="${p.id}">${icon('play', 15)}Back in</button>
        <button class="btn btn-ghost btn-sm" data-act="left" data-id="${p.id}">Left</button></div>`).join('')}</div>`;
  }

  if (notIn.length) {
    html += `<h2>Add a walk-in</h2>
      <form class="card tight" data-act="walkin">
        <div class="field-row">
          <select name="player">${notIn.map((p) => `<option value="${p.id}">${esc(p.name)} · ${fmtDupr(p.dupr)}</option>`).join('')}</select>
          <button class="btn btn-amber" type="submit" style="flex:0 0 auto">${icon('user-plus', 17)}Add</button>
        </div>
        <p class="hint">A late arrival is credited with the current games floor, so the queue stays unblocked.</p>
      </form>`;
  }

  html += `<hr class="divider">
    <div class="btn-row">
      <button class="btn" data-act="export-csv">${icon('download', 17)}Reclub CSV</button>
      <button class="btn btn-ghost" data-act="backup">${icon('shield', 16)}Backup</button>
      <button class="btn btn-danger" data-act="end-session">${icon('x', 17)}End session</button>
    </div>`;
  return html;
}

/* The one animated element on the page: court occupancy at a glance. */
function court3dBlock(session, onCourt) {
  const data = [];
  for (let c = 1; c <= session.courts; c++) {
    const m = onCourt[c];
    data.push({ n: c, state: m ? (m.state === 'live' ? 'live' : 'pending') : 'empty' });
  }
  pendingCourt3d = data;
  return `<div class="court3d" data-court3d>
    <canvas role="img" aria-label="Perspective view of ${data.length} courts showing which are in play"></canvas>
  </div>`;
}

function statCard(ic, value, label, sub) {
  return `<div class="statcard"><span class="statcard-ring">${icon(ic, 21)}</span>
    <div><div class="v">${esc(value)}</div><div class="k">${esc(label)}</div>
    ${sub ? `<p class="sub">${esc(sub)}</p>` : ''}</div></div>`;
}
const bracketChip = (b) => b ? `<span class="chip chip-${b === 'Intermediate' ? 'int' : 'nov'}">${esc(b)}</span>` : '';

function viewRoster() {
  const players = state.players.filter((p) => !p.archived);
  return `
    <p class="eyebrow">Club list</p><h1>Roster</h1>
    <p class="sub">${players.length} active player${players.length === 1 ? '' : 's'}. DUPR here is the official rating — session adjustments never overwrite it.</p>
    <form class="card tight" data-act="add-player">
      <div class="field-row">
        <div class="field" style="flex:3"><label for="pname">Name</label>
          <input type="text" id="pname" name="name" required placeholder="Dana Whitfield"></div>
        <div class="field"><label for="pdupr">DUPR</label>
          <input type="number" id="pdupr" name="dupr" step="0.01" min="${E.CFG.DUPR_MIN}" max="${E.CFG.DUPR_MAX}" value="${E.CFG.DUPR_DEFAULT}" required inputmode="decimal"></div>
      </div>
      <button class="btn btn-primary btn-block" type="submit">${icon('user-plus', 17)}Add player</button>
    </form>
    <details class="card tight"><summary style="cursor:pointer;font-weight:600">Paste a list</summary>
      <form style="margin-top:12px" data-act="import-block">
        <div class="field"><label for="block">One player per line, "Name DUPR"</label>
          <textarea id="block" name="block" placeholder="Dana Whitfield 3.25&#10;Marco Reyes 2.80"></textarea></div>
        <button class="btn btn-block" type="submit">Import</button>
      </form>
    </details>
    <h2>Players</h2>
    ${players.length ? `<div class="plist">${players.map((p) => `
      <div class="prow">
        <div class="grow"><div class="nm">${esc(p.name)}</div><div class="meta">${E.bracketOf(p.dupr)}</div></div>
        <span class="dupr">${fmtDupr(p.dupr)}</span>
        <button class="btn btn-ghost btn-sm" data-act="archive" data-id="${p.id}">Archive</button>
      </div>`).join('')}</div>` : `
      <section class="panel"><div class="panel-body"><div class="emptystate">
        <span class="emptystate-ring">${icon('users', 26)}</span>
        <p class="t">No players yet</p><p class="d">Add a few above to get started.</p>
      </div></div></section>`}`;
}

function viewStandings() {
  if (!state.session && !state.history.length) {
    return `<p class="eyebrow">Standings</p><h1>No session</h1>
      <div class="empty">Start a session to see standings.</div>`;
  }
  const { standings, gain, roster } = gainAndStandings();
  if (!standings.length) {
    return `<p class="eyebrow">Standings</p><h1>${esc(state.session ? state.session.name : 'Standings')}</h1>
      <div class="empty">No completed games yet.</div>`;
  }

  const pod = standings.slice(0, 3);
  const podium = pod.length === 3 ? `<div class="podium">${pod.map((r, i) => `
    <div class="pod pod-${i + 1}">
      <div class="pod-medal">${E.ordinal(i + 1)}</div>
      <div style="min-width:0">
        <div class="pod-name">${esc(r.name)}</div>
        <div class="pod-line">${r.w}W · ${r.l}L</div>
        <div class="pod-diff">${r.pf - r.pa > 0 ? '+' : ''}${r.pf - r.pa} diff</div>
      </div>
    </div>`).join('')}</div>` : '';

  return `
    <p class="eyebrow">${state.session ? 'Live' : 'Archived'}</p>
    <h1>${esc(state.session ? state.session.name : 'Standings')}</h1>
    <p class="sub">${completed().length} games · sorted by wins, then point differential, then games played.</p>
    ${podium}
    <div class="card"><div class="table-wrap"><table>
      <thead><tr><th class="rank">Place</th><th>Player</th><th class="num">W</th><th class="num">L</th><th class="num">Diff</th><th>Form</th></tr></thead>
      <tbody>${standings.map((r, i) => `<tr>
        <td class="rank"><span class="rank-o${i < 3 ? ' m' + (i + 1) : ''}">${E.ordinal(i + 1)}</span></td>
        <td><strong>${esc(r.name)}</strong></td>
        <td class="num">${r.w}</td><td class="num">${r.l}</td>
        <td class="num">${r.pf - r.pa > 0 ? '+' : ''}${r.pf - r.pa}</td>
        <td>${gainChip(r.gainIndex, r.evidence)}</td>
      </tr>`).join('')}</tbody>
    </table></div>
    <p class="hint">Form is <strong>gainIndex</strong>: performance against what their rating predicted, weighted by margin of victory. Provisional until ${E.CFG.EVIDENCE_GAMES} games.</p>
    </div>`;
}

function gainChip(gain, evidence) {
  if (!evidence) return '<span class="chip chip-muted">no data</span>';
  const tone = evidence < E.CFG.EVIDENCE_GAMES ? 'muted' : (gain > 8 ? 'good' : gain < -8 ? 'bad' : 'neutral');
  return `<span class="chip chip-${tone}">${fmtGain(gain)}${evidence < E.CFG.EVIDENCE_GAMES ? ' · provisional' : ''}</span>`;
}

function viewReclub() {
  const src = state.session || state.history[0];
  if (!src) {
    return `<p class="eyebrow">Reclub</p><h1>Nothing to export</h1>
      <section class="panel"><div class="panel-body"><div class="emptystate">
        <span class="emptystate-ring">${icon('clipboard', 26)}</span>
        <p class="t">No games yet</p><p class="d">Play some games and the entry list appears here.</p>
      </div></div></section>`;
  }
  const names = nameMap();
  const done = src.matches.filter((m) => m.state === 'complete');
  const entered = done.filter((m) => m.reclubEntered).length;

  return `
    <p class="eyebrow">${state.session ? 'Live session' : 'Most recent'}</p>
    <h1>Reclub entry list</h1>
    <p class="sub">${done.length} completed game${done.length === 1 ? '' : 's'} · ${entered}/${done.length} entered</p>

    <div class="card">
      <div class="btn-row">
        <button class="btn btn-primary" data-act="export-csv" data-id="${src.id}">${icon('download', 17)}Download CSV</button>
        <button class="btn" data-act="export-txt" data-id="${src.id}">${icon('clipboard', 16)}Download list</button>
        <button class="btn btn-ghost" data-act="backup">${icon('shield', 16)}Full backup</button>
      </div>
      <p class="hint">CSV columns match Reclub's import exactly — the same order the server build writes.</p>
    </div>

    ${done.length ? `
      <button class="btn btn-block" data-act="mark-all" data-id="${src.id}" style="margin-bottom:var(--s3)">
        ${icon('check', 16)}Mark all as entered</button>
      ${done.map((m, i) => {
        const t1Won = m.s1 > m.s2;
        const cls = m.reclubEntered ? 'court-card live' : 'court-card';
        return `<div class="card ${cls}">
          <div class="card-head">
            <span class="court-no">Game ${i + 1} · Court ${m.court}</span>
            <div class="chips">${bracketChip(m.bracket)}
              <span class="chip ${m.reclubEntered ? 'chip-good' : 'chip-muted'}">${m.reclubEntered ? 'entered' : 'not entered'}</span></div>
          </div>
          <div class="matchup">
            <div class="side">${m.team1.map((id) => `<span class="pname">${esc(names[id])}</span>`).join('')}
              <span class="score-big${t1Won ? '' : ' muted'}">${m.s1}</span></div>
            <div class="vs">VS</div>
            <div class="side right">${m.team2.map((id) => `<span class="pname">${esc(names[id])}</span>`).join('')}
              <span class="score-big${t1Won ? ' muted' : ''}">${m.s2}</span></div>
          </div>
          <button class="btn btn-sm btn-block" style="margin-top:10px" data-act="toggle-entered"
                  data-session="${src.id}" data-id="${m.id}">
            ${m.reclubEntered ? 'Mark as not entered' : 'Mark as entered in Reclub'}</button>
        </div>`;
      }).join('')}` : `
      <section class="panel"><div class="panel-body"><div class="emptystate">
        <span class="emptystate-ring">${icon('clipboard', 26)}</span>
        <p class="t">No completed games</p></div></div></section>`}`;
}

function viewSettings() {
  const s = state.session;
  const snaps = S.snapshots();
  const bytes = (() => { try { return (localStorage.getItem('kamrynne-que:v1') || '').length * 2; } catch (e) { return 0; } })();

  return `
    <p class="eyebrow">${icon('shield', 13)}This device</p>
    <h1>Settings</h1>
    <p class="sub">Everything lives in this browser. Nothing is sent anywhere.</p>

    <section class="panel">
      <p class="panel-head">${icon('download', 15)}Backup and restore</p>
      <div class="panel-body">
        <p class="panel-note">
          This data lives only in this browser on this device. Clearing site data,
          switching phones or using a different browser loses it. Download a backup
          before any of those — and after a big night.
        </p>
        <div class="btn-row" style="margin-bottom:var(--s3)">
          <button class="btn btn-primary" data-act="backup">${icon('download', 17)}Save backup file</button>
        </div>
        <div class="field">
          <label for="restore">Restore from a backup file</label>
          <input type="file" id="restore" accept="application/json,.json" data-act="restore-file">
          <p class="hint">Replaces everything currently in this browser. You will be asked to confirm.</p>
        </div>
      </div>
    </section>

    <section class="panel">
      <p class="panel-head">${icon('clipboard', 15)}Storage</p>
      <div class="panel-body">
        <div class="statgrid">
          ${statCard('users', state.players.length, 'Players', 'On the club list')}
          ${statCard('clock', state.history.length, 'Sessions', 'Archived')}
          ${statCard('shield', (bytes / 1024).toFixed(1) + ' KB', 'Stored', 'In this browser')}
          ${statCard('rotate', snaps.length, 'Snapshots', 'Automatic')}
        </div>
      </div>
    </section>

    ${s ? `
    <section class="panel">
      <p class="panel-head">${icon('court', 15)}Session</p>
      <div class="panel-body">
        <form data-act="session-settings">
          <div class="field-row">
            <div class="field"><label for="ccourts">Courts</label>
              <select id="ccourts" name="courts">${Array.from({ length: E.CFG.MAX_COURTS }, (_, i) =>
                `<option value="${i + 1}"${i + 1 === s.courts ? ' selected' : ''}>${i + 1}</option>`).join('')}</select></div>
            <div class="field"><label for="ctarget">Games to</label>
              <select id="ctarget" name="target">${E.CFG.VALID_TARGETS.map((t) =>
                `<option value="${t}"${t === s.target ? ' selected' : ''}>${t}</option>`).join('')}</select></div>
          </div>
          <button class="btn btn-block" type="submit">${icon('check', 16)}Save</button>
        </form>
      </div>
    </section>` : ''}

    <section class="panel" style="border-color:rgba(255,107,129,.34)">
      <p class="panel-head" style="color:var(--danger)">${icon('x', 15)}Danger zone</p>
      <div class="panel-body">
        <p class="panel-note">Erases every player, session and result in this browser. Download a backup first — this cannot be undone.</p>
        <button class="btn btn-danger btn-block" data-act="wipe">${icon('x', 16)}Erase all data on this device</button>
      </div>
    </section>`;
}

function viewHistory() {
  const names = nameMap();
  const which = location.hash.split('/')[1];
  const target = which
    ? (state.session && state.session.id === which ? state.session : state.history.find((h) => h.id === which))
    : null;

  if (target) {
    const done = target.matches.filter((m) => m.state === 'complete');
    return `
      <p class="eyebrow">${icon('clock', 13)}Game log</p>
      <h1>${esc(target.name)}</h1>
      <p class="sub">${new Date(target.startedAt).toLocaleDateString()} · ${esc(target.format)} · games to ${target.target}</p>
      <div class="btn-row" style="margin-bottom:var(--s4)">
        <a class="btn btn-ghost" href="#history">${icon('chevron', 15)}All sessions</a>
      </div>
      ${done.length ? `<section class="panel"><p class="panel-head">${icon('clipboard', 15)}Game log</p>
        <div class="panel-body"><div class="table-wrap"><table class="log-table">
          <thead><tr><th style="width:64px">Match</th><th style="width:110px">Court</th><th>Players</th><th class="num" style="width:110px">Score</th></tr></thead>
          <tbody>${done.map((m, i) => {
            const t1Won = m.s1 > m.s2;
            return `<tr>
              <td><span class="log-game">${i + 1}</span></td>
              <td><span class="log-court"><span class="dot" style="background:${courtColour(m.court)}"></span>Court ${m.court}</span></td>
              <td><div class="matchup">
                <div class="log-side ${t1Won ? 'win' : 'lose'}">${m.team1.map((id) => `<span class="p">${esc(names[id])}</span>`).join('')}</div>
                <span class="vs">VS</span>
                <div class="log-side ${t1Won ? 'lose' : 'win'}">${m.team2.map((id) => `<span class="p">${esc(names[id])}</span>`).join('')}</div>
              </div></td>
              <td class="num"><span class="log-score"><span class="${t1Won ? 'w' : 'l'}">${m.s1}</span><span class="sep">–</span><span class="${t1Won ? 'l' : 'w'}">${m.s2}</span></span></td>
            </tr>`;
          }).join('')}</tbody>
        </table></div></div></section>` : `
        <section class="panel"><div class="panel-body"><div class="emptystate">
          <span class="emptystate-ring">${icon('clipboard', 26)}</span>
          <p class="t">No completed matches</p></div></div></section>`}`;
  }

  const rows = [];
  if (state.session) rows.push({ ...state.session, live: true });
  state.history.forEach((h) => rows.push(h));

  return `
    <p class="eyebrow">${icon('clock', 13)}Archive</p><h1>Past sessions</h1>
    <p class="sub">Every session keeps its full game log and the ratings frozen onto each match.</p>
    ${rows.length ? rows.map((s) => {
      const done = s.matches.filter((m) => m.state === 'complete');
      return `<section class="panel" style="margin-bottom:var(--s3)${s.live ? ';border-color:rgba(46,232,154,.4)' : ''}">
        <div class="panel-body">
          <div class="card-head" style="margin-bottom:6px">
            <h3 style="margin:0">${esc(s.name)}</h3>
            <div class="chips">${s.live ? '<span class="chip chip-good"><span class="live-dot"></span>live</span>' : ''}
              <span class="chip ${done.length ? 'chip-good' : 'chip-muted'}">${done.length} matches</span></div>
          </div>
          <p class="panel-note">${new Date(s.startedAt).toLocaleString()} · ${s.courts} court${s.courts === 1 ? '' : 's'}</p>
          <div class="btn-row">
            <a class="btn btn-sm" href="#history/${s.id}">${icon('clipboard', 15)}Game log</a>
            <button class="btn btn-sm btn-ghost" data-act="export-csv" data-id="${s.id}">${icon('download', 15)}CSV</button>
          </div>
        </div></section>`;
    }).join('') : `
      <section class="panel"><div class="panel-body"><div class="emptystate">
        <span class="emptystate-ring">${icon('clock', 26)}</span>
        <p class="t">No sessions yet</p><p class="d">They appear here once you play.</p>
      </div></div></section>`}`;
}

/* -------------------------------------------------------------- actions -- */

const ACTIONS = {
  'start-session'(form) {
    const fd = new FormData(form);
    const s = {
      id: S.uid('s_'),
      name: String(fd.get('name') || 'KAMRYNNE QUE'),
      format: fd.get('format') === 'singles' ? 'singles' : 'doubles',
      courts: Number(fd.get('courts')) || 2,
      target: Number(fd.get('target')) || E.CFG.DEFAULT_TARGET,
      startedAt: Date.now(),
      status: 'active',
      players: {},
      matches: [],
    };
    state.players.filter((p) => !p.archived).forEach((p) => {
      s.players[p.id] = {
        status: 'ready', queuedAt: Date.now(), offsetGames: 0,
        priorityBoost: 0, adjustment: 0, lastPlayedAt: 0, lastSatAt: Date.now(),
      };
    });
    state.session = s;
    commit('Session started — roster seeded with the club list.');
  },

  call(el) {
    const court = Number(el.dataset.court);
    const s = state.session;
    const committed = new Set();
    openMatches().forEach((m) => [...m.team1, ...m.team2].forEach((id) => committed.add(id)));
    const proposal = E.nextMatch(sessionPlayers(), s.matches, [...committed], s.format);
    if (!proposal) {
      toast('No legal match right now — not enough ready players in one bracket.', 'warn');
      return;
    }
    s.matches.push({
      id: S.uid('m_'), court, target: s.target, format: s.format,
      bracket: proposal.bracket, team1: proposal.team1, team2: proposal.team2,
      avg1: proposal.avg1, avg2: proposal.avg2, exp1: proposal.exp1,
      quality: proposal.quality, ratingSnapshot: proposal.ratingSnapshot,
      reason: proposal.reason, backToBack: proposal.backToBack,
      state: 'pending', s1: null, s2: null, createdAt: Date.now(),
    });
    commit('Court ' + court + ' called.');
  },

  start(el) {
    const m = state.session.matches.find((x) => x.id === el.dataset.id);
    if (!m || m.state !== 'pending') return;
    m.state = 'live';
    [...m.team1, ...m.team2].forEach((id) => { state.session.players[id].status = 'playing'; });
    commit('Game started.');
  },

  cancel(el) {
    const s = state.session;
    const i = s.matches.findIndex((x) => x.id === el.dataset.id);
    if (i < 0 || s.matches[i].state === 'complete') return;
    [...s.matches[i].team1, ...s.matches[i].team2].forEach((id) => {
      const sp = s.players[id];
      if (sp && sp.status === 'playing') { sp.status = 'ready'; sp.queuedAt = Date.now(); }
    });
    s.matches.splice(i, 1);
    commit('Match cleared, players returned to the queue.', 'warn');
  },

  score(form) {
    const m = state.session.matches.find((x) => x.id === form.dataset.id);
    if (!m) return;
    const fd = new FormData(form);
    const s1 = Number(fd.get('s1')), s2 = Number(fd.get('s2'));
    if (!E.validScore(s1, s2, m.target)) {
      toast('Scores rejected — the winner must land exactly on ' + m.target + '.', 'bad');
      return;
    }
    const now = Date.now();
    m.s1 = s1; m.s2 = s2; m.state = 'complete'; m.endedAt = now;
    const played = new Set([...m.team1, ...m.team2]);
    played.forEach((id) => {
      const sp = state.session.players[id];
      if (sp) { sp.status = 'ready'; sp.queuedAt = now; sp.lastPlayedAt = now; }
    });
    Object.entries(state.session.players).forEach(([id, sp]) => {
      if (!played.has(id) && sp.status === 'ready') sp.lastSatAt = now;
    });
    commit('Result recorded.');
  },

  boost(el) { const p = state.session.players[el.dataset.id]; p.priorityBoost = Math.min(5, (p.priorityBoost || 0) + 1); commit(); },
  sit(el)   { state.session.players[el.dataset.id].status = 'resting'; commit(); },
  rejoin(el){ const p = state.session.players[el.dataset.id]; p.status = 'ready'; p.queuedAt = Date.now(); commit(); },
  left(el)  { state.session.players[el.dataset.id].status = 'done'; commit(); },

  walkin(form) {
    const id = new FormData(form).get('player');
    const p = state.players.find((x) => x.id === id);
    if (!p) return;
    const credit = E.walkInCredit(sessionPlayers(), state.session.matches, id, Number(p.dupr));
    state.session.players[id] = {
      status: 'ready', queuedAt: Date.now(), offsetGames: credit,
      priorityBoost: 0, adjustment: 0, lastPlayedAt: 0, lastSatAt: Date.now(),
    };
    commit('Walk-in added, credited with ' + credit + ' game' + (credit === 1 ? '' : 's') + '.');
  },

  'end-session'() {
    if (!confirm('End the session? It is archived to History. Export your Reclub list first.')) return;
    state.session.status = 'ended';
    state.session.endedAt = Date.now();
    state.history.unshift(state.session);
    state.session = null;
    commit('Session ended and archived.');
  },

  'add-player'(form) {
    const fd = new FormData(form);
    const name = String(fd.get('name') || '').trim();
    const dupr = Number(fd.get('dupr'));
    if (!name) { toast('A player needs a name.', 'bad'); return; }
    if (!(dupr >= E.CFG.DUPR_MIN && dupr <= E.CFG.DUPR_MAX)) {
      toast(`DUPR must be between ${E.CFG.DUPR_MIN.toFixed(2)} and ${E.CFG.DUPR_MAX.toFixed(2)}.`, 'bad');
      return;
    }
    const id = S.uid('p_');
    state.players.push({ id, name, dupr: E.phpRound(dupr, 2), archived: false });
    if (state.session) {
      const credit = E.walkInCredit(sessionPlayers(), state.session.matches, id, dupr);
      state.session.players[id] = {
        status: 'ready', queuedAt: Date.now(), offsetGames: credit,
        priorityBoost: 0, adjustment: 0, lastPlayedAt: 0, lastSatAt: Date.now(),
      };
    }
    form.reset();
    commit(name + ' added.');
  },

  'import-block'(form) {
    const parsed = E.parseRosterBlock(new FormData(form).get('block'));
    parsed.valid.forEach((row) => {
      state.players.push({ id: S.uid('p_'), name: row.name, dupr: row.dupr, archived: false });
    });
    parsed.errors.slice(0, 3).forEach((err) => toast(err, 'warn'));
    if (parsed.valid.length) { form.reset(); commit(parsed.valid.length + ' player(s) imported.'); }
    else render();
  },

  archive(el) {
    const p = state.players.find((x) => x.id === el.dataset.id);
    if (!p || !confirm('Archive ' + p.name + '? Their history is kept.')) return;
    p.archived = true;
    commit('Player archived.', 'warn');
  },

  'export-csv'(el) {
    const src = pickSession(el.dataset && el.dataset.id);
    if (!src) { toast('Nothing to export yet.', 'warn'); return; }
    const done = src.matches.filter((m) => m.state === 'complete');
    if (!done.length) { toast('No completed games to export.', 'warn'); return; }
    const stamp = new Date(src.startedAt).toISOString().slice(0, 10);
    S.download('reclub-' + stamp + '.csv', E.exportCsv(src, done, nameMap()), 'text/csv');
  },

  backup() {
    S.download('kamrynne-que-backup-' + new Date().toISOString().slice(0, 10) + '.json',
      S.exportJson(state), 'application/json');
    toast('Backup downloaded. Keep it — this data lives only in this browser.');
  },

  'export-txt'(el) {
    const src = pickSession(el.dataset && el.dataset.id);
    if (!src) { toast('Nothing to export yet.', 'warn'); return; }
    const done = src.matches.filter((m) => m.state === 'complete');
    if (!done.length) { toast('No completed games to export.', 'warn'); return; }
    const stamp = new Date(src.startedAt).toISOString().slice(0, 10);
    S.download('reclub-' + stamp + '.txt', E.exportText(src, done, nameMap()), 'text/plain');
  },

  'toggle-entered'(el) {
    const src = pickSession(el.dataset.session);
    if (!src) return;
    const m = src.matches.find((x) => x.id === el.dataset.id);
    if (!m) return;
    m.reclubEntered = !m.reclubEntered;
    commit();
  },

  'mark-all'(el) {
    const src = pickSession(el.dataset.id);
    if (!src) return;
    src.matches.filter((m) => m.state === 'complete').forEach((m) => { m.reclubEntered = true; });
    commit('All games marked as entered in Reclub.');
  },

  'session-settings'(form) {
    const fd = new FormData(form);
    const courts = Number(fd.get('courts'));
    const target = Number(fd.get('target'));
    const open = openMatches().map((m) => m.court);
    // Refuse to close a court that has players on it rather than orphaning them.
    if (open.some((c) => c > courts)) {
      toast('Finish or clear the games on the higher courts first.', 'warn');
      return;
    }
    state.session.courts = Math.max(1, Math.min(E.CFG.MAX_COURTS, courts));
    if (E.CFG.VALID_TARGETS.includes(target)) state.session.target = target;
    commit('Session settings saved.');
  },

  restore(file) {
    const reader = new FileReader();
    reader.onload = () => {
      const next = S.importJson(String(reader.result));
      if (!next) { toast('That file is not a KAMRYNNE QUE backup.', 'bad'); return; }
      const summary = `${next.players.length} player(s), ${next.history.length} archived session(s)`
        + (next.session ? ', 1 running session' : '');
      if (!confirm(`Restore ${summary}?\n\nEverything currently in this browser is replaced. This cannot be undone.`)) return;
      state = next;
      commit('Backup restored.');
    };
    reader.onerror = () => toast('Could not read that file.', 'bad');
    reader.readAsText(file);
  },

  wipe() {
    if (!confirm('Erase every player, session and result stored in this browser?\n\nThis cannot be undone. Download a backup first.')) return;
    if (!confirm('Really erase everything? Last chance.')) return;
    state = S.blank();
    commit('All data erased.', 'warn');
  },
};

/** The live session, an archived one by id, or the most recent. */
function pickSession(id) {
  if (id) {
    if (state.session && state.session.id === id) return state.session;
    const h = state.history.find((x) => x.id === id);
    if (h) return h;
  }
  return state.session || state.history[0] || null;
}

/* --------------------------------------------------------------- render -- */

let pendingCourt3d = null;

function render() {
  unmountCourt3d();
  pendingCourt3d = null;
  chrome();
  const views = {
    play: viewPlay, roster: viewRoster, standings: viewStandings,
    reclub: viewReclub, history: viewHistory, settings: viewSettings,
  };
  const fn = views[view] || viewPlay;
  app.innerHTML = fn();
  app.scrollTop = 0;
  if (pendingCourt3d) mountCourt3d(app.querySelector('[data-court3d]'), pendingCourt3d);
}

document.addEventListener('click', (ev) => {
  const el = ev.target.closest('[data-act]');
  if (!el || el.tagName === 'FORM') return;
  const fn = ACTIONS[el.dataset.act];
  if (!fn) return;
  ev.preventDefault();
  fn(el);
});

document.addEventListener('submit', (ev) => {
  const form = ev.target.closest('form[data-act]');
  if (!form) return;
  ev.preventDefault();
  const fn = ACTIONS[form.dataset.act];
  if (fn) fn(form);
});

document.addEventListener('change', (ev) => {
  const el = ev.target.closest('input[type=file][data-act="restore-file"]');
  if (!el || !el.files || !el.files[0]) return;
  ACTIONS.restore(el.files[0]);
  el.value = '';
});

window.addEventListener('hashchange', () => {
  view = (location.hash.replace('#', '').split('/')[0]) || 'play';
  render();
});

if ('serviceWorker' in navigator && window.isSecureContext) {
  window.addEventListener('load', () => navigator.serviceWorker.register('sw.js').catch(() => {}));
}

render();
