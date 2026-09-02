/* ===========================================================================
   Persistence for the browser build.
   ---------------------------------------------------------------------------
   The server build keeps everything in SQLite. With no server there is nowhere
   else to put it, so state lives in localStorage on this device — which is the
   model the original app used, with the same caveat: it is device-specific and
   clearing site data ends the session. Export a backup before switching phones.

   Writes go through save(), which keeps a rolling ring of three snapshots and
   drops the ring first if the quota is ever hit, so a full store degrades to
   "lost the undo history" rather than "lost the session".
   =========================================================================== */

const KEY = 'kamrynne-que:v1';
const SNAP_KEY = 'kamrynne-que:v1:snapshots';

export function blank() {
  return { v: 1, players: [], session: null, history: [], revision: 0 };
}

export function load() {
  try {
    const raw = localStorage.getItem(KEY);
    if (!raw) return blank();
    const data = JSON.parse(raw);
    return data && typeof data === 'object' ? { ...blank(), ...data } : blank();
  } catch (e) {
    return blank();
  }
}

export function save(state) {
  state.revision = (Number(state.revision) || 0) + 1;
  const json = JSON.stringify(state);
  const writeMain = () => localStorage.setItem(KEY, json);

  try {
    writeMain();
    if (state.revision % 10 === 0) {
      let ring = [];
      try { ring = JSON.parse(localStorage.getItem(SNAP_KEY) || '[]'); } catch (e) { ring = []; }
      const next = [{ at: Date.now(), data: state }, ...(Array.isArray(ring) ? ring : [])].slice(0, 3);
      localStorage.setItem(SNAP_KEY, JSON.stringify(next));
    }
    return { ok: true, recovered: false };
  } catch (e) {
    // Out of quota: sacrifice the undo ring, keep the session.
    try {
      localStorage.removeItem(SNAP_KEY);
      writeMain();
      return { ok: true, recovered: true };
    } catch (e2) {
      return { ok: false, recovered: false, error: e2 };
    }
  }
}

export function snapshots() {
  try { return JSON.parse(localStorage.getItem(SNAP_KEY) || '[]'); } catch (e) { return []; }
}

export function uid(prefix = '') {
  const r = (crypto && crypto.randomUUID)
    ? crypto.randomUUID().replace(/-/g, '').slice(0, 12)
    : Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
  return prefix + r;
}

/** Whole-store backup, matching the shape the PHP build exports. */
export function exportJson(state) {
  return JSON.stringify({
    app: 'KAMRYNNE QUE',
    build: 'browser',
    exported: new Date().toISOString(),
    ...state,
  }, null, 2);
}

/** Restore from a backup file. Returns null when the file is not one of ours. */
export function importJson(text) {
  try {
    const d = JSON.parse(text);
    if (!d || typeof d !== 'object' || !Array.isArray(d.players)) return null;
    return {
      v: 1,
      players: d.players,
      session: d.session || null,
      history: Array.isArray(d.history) ? d.history : [],
      revision: Number(d.revision) || 0,
    };
  } catch (e) {
    return null;
  }
}

/** Trigger a download without leaving the page. */
export function download(filename, body, mime = 'text/plain') {
  const url = URL.createObjectURL(new Blob([body], { type: mime }));
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  a.click();
  setTimeout(() => URL.revokeObjectURL(url), 500);
}
