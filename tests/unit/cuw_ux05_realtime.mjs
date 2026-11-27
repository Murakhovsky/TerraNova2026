import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const code = fs.readFileSync(new URL('../../symfony/assets/controllers/operations_snapshot_controller.js', import.meta.url), 'utf8')
    .replace("import { Controller } from '@hotwired/stimulus';", 'class Controller {}')
    .replace('export default class extends Controller', 'class Snapshot extends Controller');
const nav = { onLine: true };
const doc = { activeElement: null };
let partial = 'false', contentExists = true, focused = false, loads = 0, timerId = 0;
const callbacks = new Map();
const frame = {
    get src() { return this.url; },
    set src(url) { this.url = url; loads++; },
    reload() { loads++; },
    contains() { return focused; },
    querySelector() { return contentExists ? { dataset: { partial } } : null; },
};
const window = {
    location: { href: 'https://cos.example/workspace/operations?source=workflow' },
    setInterval(fn) { callbacks.set(++timerId, fn); return timerId; },
    setTimeout(fn) { callbacks.set(++timerId, fn); return timerId; },
    clearTimeout(id) { callbacks.delete(id); },
    clearInterval(id) { callbacks.delete(id); },
};
const ctx = vm.createContext({ window, navigator: nav, document: doc, URL });
const s = vm.runInContext(code + '\nnew Snapshot()', ctx);
s.frameTarget = frame;
s.stateTarget = { dataset: {} };
const state = () => s.stateTarget.dataset.connection;
const loaded = () => s.loaded({ target: frame });
s.connect();
assert.equal(state(), 'stale');
s.refresh(false); loaded();
assert.equal(state(), 'stale', 'polling alone cannot attest realtime');
s.signal(); loaded();
assert.equal(state(), 'live');
partial = 'true';
s.signal(); loaded();
assert.equal(state(), 'stale', 'partial provider failure is not live');
partial = 'false';
contentExists = false;
s.signal(); loaded();
assert.equal(state(), 'stale', 'missing SSR content is not live');
contentExists = true;
s.signal(); const inFlight = loads;
s.signal(); assert.equal(loads, inFlight);
loaded(); assert.equal(loads, inFlight + 1, 'signal during fetch triggers another read');
assert.equal(state(), 'stale'); loaded(); assert.equal(state(), 'live');
nav.onLine = false; s.offline(); assert.equal(state(), 'offline');
const offlineLoads = loads; s.signal(); assert.equal(loads, offlineLoads);
nav.onLine = true; s.online(); assert.equal(state(), 'reconnecting');
loaded(); // signal while offline must not establish realtime
s.refresh(false); loaded(); assert.equal(state(), 'stale');
s.signal(); s.failed({target: {}}); assert.equal(s.pending, true);
callbacks.get(s.watchdog)(); assert.equal(state(), 'stale'); assert.equal(s.pending, false);
focused = true; doc.activeElement = { closest: () => ({}) };
const beforeDecision = loads; s.signal(); assert.equal(loads, beforeDecision);
focused = false; doc.activeElement = null; s.refresh(false); loaded();
assert.equal(state(), 'live');
s.disconnect(); assert.equal(callbacks.size, 0);
console.log('UX-05 realtime: polling, attested refresh, partial/missing snapshot, race, offline, reconnect, watchdog, focused decision passed.');
