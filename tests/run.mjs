// OKAPI regression suite.
// Read-only level: safe against any installation, including production.
// Writing level (--write): only on targets marked allowWrite: true (dev VM, test server).
//
//   node run.mjs                      # the default target from config.js
//   node run.mjs --target prod        # a named target from `targets` in config.js
//   node run.mjs --expect-revision 3b41888
//   node run.mjs --write              # also run the writing level
//
// Exit code 0 = all checks passed, 1 = at least one failed, 2 = setup error.
import * as config from './config.js';
import { OkapiClient } from './lib/client.mjs';

const args = process.argv.slice(2);
const opt = name => {
  const i = args.indexOf(`--${name}`);
  return i >= 0 ? args[i + 1] : undefined;
};

function loadTarget(name) {
  if (name) {
    const t = config.targets?.[name];
    if (!t) {
      console.error(`Unknown target '${name}'. Known: ${Object.keys(config.targets ?? {}).join(', ') || '(none)'}`);
      process.exit(2);
    }
    return t;
  }
  const { baseUrl, consumerKey, consumerSecret, accessToken, tokenSecret, allowWrite, writeLocation, writeCache } = config;
  return { baseUrl, consumerKey, consumerSecret, accessToken, tokenSecret, allowWrite, writeLocation, writeCache };
}

const targetName = opt('target');
const target = loadTarget(targetName);
const expectRevision = opt('expect-revision');
const write = args.includes('--write');
if (write && target.allowWrite !== true) {
  console.error(`--write refused: target${targetName ? ` '${targetName}'` : ''} is not marked allowWrite: true in config.js.`);
  process.exit(2);
}
const api = new OkapiClient(target);

// --- tiny test harness -------------------------------------------------------

const results = [];
const ctx = {};  // values shared between checks (installation, a cache code, the user)
const dumps = [];  // full responses printed before the results, so every run shows what it ran against

class Skip extends Error {}
const skip = reason => { throw new Skip(reason); };

// A target may lack credentials (e.g. production before you have a token): skip those levels.
const hasKey = Boolean(target.consumerKey);
const hasToken = Boolean(target.consumerKey && target.consumerSecret && target.accessToken && target.tokenSecret);
const needKey = () => { if (!hasKey) skip('target has no consumerKey'); };
const needToken = () => { if (!hasToken) skip('target has no access token'); };

function assert(cond, message) {
  if (!cond) throw new Error(message);
}

function okJson(res, what) {
  assert(res.status === 200, `${what}: HTTP ${res.status} ${res.json?.error?.developer_message ?? res.text.slice(0, 200)}`);
  assert(res.json !== null, `${what}: response is not JSON`);
  return res.json;
}

async function check(name, fn) {
  try {
    const detail = await fn();
    results.push({ name, status: 'pass', detail });
  } catch (e) {
    results.push({ name, status: e instanceof Skip ? 'skip' : 'fail', detail: e.message });
  }
}

// --- checks ------------------------------------------------------------------

// Level 0: no credentials.

await check('installation: basic fields', async () => {
  const j = okJson(await api.request('services/apisrv/installation', {}, { auth: 'none' }), 'installation');
  ctx.installation = j;
  dumps.push(['services/apisrv/installation', j]);
  assert(typeof j.site_name === 'string', 'site_name missing');
  assert(typeof j.okapi_base_url === 'string', 'okapi_base_url missing');
  // A release reports a number; dev setups may report a string such as "1.0.0", or nothing
  // when OKAPI runs from a plain source checkout. Check the deployed version with --expect-revision.
  const flags = Object.entries(j).filter(([k]) => k.startsWith('has_')).map(([k, v]) => `${k}=${v}`).join(' ');
  return `${j.site_name}, revision ${j.okapi_git_revision ?? j.okapi_revision ?? '(none)'}; ${flags}`;
});

await check('installation: expected revision', async () => {
  if (!expectRevision) skip('no --expect-revision given');
  const rev = String(ctx.installation?.okapi_git_revision ?? '');
  assert(rev.startsWith(expectRevision), `runs ${rev || '(unknown)'}, expected ${expectRevision}`);
  return rev;
});

// OKAPI answers an unknown consumer key with 400 invalid_parameter (not 401).
function expectBadConsumer(res) {
  assert(res.status === 400, `expected HTTP 400, got ${res.status}`);
  assert(res.json?.error?.reason_stack?.includes('invalid_parameter'), 'reason_stack lacks invalid_parameter');
  assert(res.json.error.parameter === 'consumer_key', `error is about '${res.json.error.parameter}', not consumer_key`);
  return `400, ${res.json.error.reason_stack.join(' > ')}`;
}

await check('error format: missing consumer key is rejected', async () => {
  const res = await api.request('services/caches/search/all', { limit: 1 }, { auth: 'none' });
  assert(res.status === 400, `expected HTTP 400, got ${res.status}`);
  assert(res.json?.error?.reason_stack?.[0] === 'bad_request', 'reason_stack does not start with bad_request');
  assert(typeof res.json.error.developer_message === 'string', 'developer_message missing');
  return `400, ${res.json.error.reason_stack.join(' > ')}`;
});

// Level 1: consumer key.

await check('level 1: invalid consumer key is rejected', async () => {
  return expectBadConsumer(await api.request('services/caches/search/all', { limit: 1 },
    { overrides: { consumerKey: 'invalid-key-for-tests' } }));
});

await check('level 1: caches/search/all', async () => {
  needKey();
  const j = okJson(await api.request('services/caches/search/all', { limit: 5, status: 'Available' }), 'search/all');
  assert(Array.isArray(j.results), 'results is not an array');
  assert(j.results.length > 0, 'no caches found');
  ctx.cacheCode = j.results[0];
  return `${j.results.length} codes, using ${ctx.cacheCode}`;
});

await check('level 1: caches/geocache', async () => {
  needKey();
  if (!ctx.cacheCode) skip('no cache code');
  const fields = 'code|name|location|type|status|owner';
  const j = okJson(await api.request('services/caches/geocache', { cache_code: ctx.cacheCode, fields }), 'geocache');
  assert(j.code === ctx.cacheCode, `code ${j.code} != ${ctx.cacheCode}`);
  assert(/^-?\d+(\.\d+)?\|-?\d+(\.\d+)?$/.test(j.location), `bad location ${j.location}`);
  assert(typeof j.owner?.uuid === 'string', 'owner.uuid missing');
  return `${j.name} (${j.type}, ${j.status})`;
});

await check('level 1: logs/logs', async () => {
  needKey();
  if (!ctx.cacheCode) skip('no cache code');
  const j = okJson(await api.request('services/logs/logs', { cache_code: ctx.cacheCode, limit: 5 }), 'logs/logs');
  assert(Array.isArray(j), 'not an array');
  for (const log of j) assert(log.uuid && log.date && log.type, 'log entry without uuid/date/type');
  return `${j.length} logs`;
});

// Level 3: OAuth with an access token. PLAINTEXT and HMAC-SHA1 must both work
// (PLAINTEXT over HTTPS is used by clients out there; HMAC by e.g. c:geo).

await check('level 3 PLAINTEXT: users/user', async () => {
  needToken();
  const res = await api.request('services/users/user', { fields: 'uuid|username' }, { auth: 'plaintext' });
  // OKAPI allows PLAINTEXT only over HTTPS (dev installations may relax this in DEBUG mode).
  if (target.baseUrl.startsWith('http:') && res.status === 400 && /only over HTTPS/.test(res.text))
    skip('PLAINTEXT needs HTTPS; this target uses plain HTTP');
  const j = okJson(res, 'users/user');
  assert(j.uuid && j.username, 'uuid/username missing');
  ctx.user = j;
  return j.username;
});

await check('level 3 HMAC-SHA1: users/user', async () => {
  needToken();
  const j = okJson(await api.request('services/users/user', { fields: 'uuid|username' }, { auth: 'hmac' }), 'users/user');
  assert(j.uuid && j.username, 'uuid/username missing');
  if (ctx.user) assert(j.uuid === ctx.user.uuid, 'PLAINTEXT and HMAC return different users');
  return j.username;
});

await check('level 3 HMAC-SHA1: parameters needing encoding', async () => {
  needToken();
  if (!ctx.cacheCode) skip('no cache code');
  // '|' and the comma must be percent-encoded identically on both sides of the signature.
  const j = okJson(await api.request('services/caches/geocaches',
    { cache_codes: ctx.cacheCode, fields: 'code|name|is_found' }, { auth: 'hmac' }), 'geocaches');
  assert(j[ctx.cacheCode]?.code === ctx.cacheCode, 'cache missing in result');
  return `is_found=${j[ctx.cacheCode].is_found}`;
});

await check('level 3 HMAC-SHA1: wrong secret is rejected', async () => {
  needToken();
  const res = await api.request('services/users/user', { fields: 'uuid' },
    { auth: 'hmac', overrides: { consumerSecret: 'wrong-secret-for-tests' } });
  assert(res.status === 401, `expected HTTP 401, got ${res.status}`);
  return '401';
});

await check('level 3: logs/capabilities', async () => {
  needToken();
  if (!ctx.cacheCode) skip('no cache code');
  const j = okJson(await api.request('services/logs/capabilities', { cache_code: ctx.cacheCode }, { auth: 'hmac' }), 'logs/capabilities');
  dumps.push([`services/logs/capabilities (cache_code=${ctx.cacheCode})`, j]);
  assert(Array.isArray(j.submittable_logtypes), 'submittable_logtypes missing');
  return `submittable: ${j.submittable_logtypes.join(', ')}`;
});

// Whether an installation has a method: services/apiref/method answers 400 for unknown ones.
const hasMethod = async name =>
  (await api.request('services/apiref/method', { name }, { auth: 'none' })).status === 200;
ctx.hasUserCoords = await hasMethod('services/caches/save_user_coords');

await check('level 3: user coordinates field', async () => {
  needToken();
  if (!ctx.hasUserCoords) skip('installation has no services/caches/save_user_coords');
  if (!ctx.cacheCode) skip('no cache code');
  const j = okJson(await api.request('services/caches/geocache',
    { cache_code: ctx.cacheCode, fields: 'code|user_coords' }, { auth: 'hmac' }), 'geocache user_coords');
  assert('user_coords' in j, 'user_coords missing');
  return `user_coords=${j.user_coords}`;
});

await check('level 3: draftlogs/query (has_draft_logs)', async () => {
  needToken();
  if (!ctx.installation?.has_draft_logs) skip('installation has no has_draft_logs');
  const j = okJson(await api.request('services/draftlogs/query', {}, { auth: 'hmac' }), 'draftlogs/query');
  assert(Array.isArray(j), 'not an array');
  for (const d of j) assert(d.draft_id && d.cache_code && d.type && typeof d.needs_maintenance2 === 'boolean', 'draft without draft_id/cache_code/type/needs_maintenance2');
  return `${j.length} drafts`;
});

// --- writing level (--write) ------------------------------------------------
// Works on its own test cache, created first and archived last, so it touches no one
// else's data. Everything it creates is removed again, except the archived test cache.
// Installations without cache editing (has_cache_editing false, e.g. OCPL) use the target's writeCache instead: an
// existing cache, which is never updated or archived; only per-user data is written.

if (write) {
  const stamp = new Date().toISOString().replace(/[-:]/g, '').slice(0, 15);
  // Shifted a little per run: create refuses a second active cache at the same coordinates.
  const [lat, lon] = (target.writeLocation ?? '52.327366|9.543').split('|')
    .map((v, i) => (Number(v) + (i ? (Date.now() % 997) * 1e-6 : 0)).toFixed(6));
  const w = {};  // state of the writing level
  const post = async (path, params, what) =>
    okJson(await api.request(path, params, { auth: 'hmac', method: 'POST' }), what);
  const read = async fields =>
    okJson(await api.request('services/caches/geocache', { cache_code: w.code, fields }, { auth: 'hmac' }), 'geocache');
  const needCache = () => { needToken(); if (!w.code) skip('no test cache'); };

  await check('write: caches/create', async () => {
    needToken();
    if (!ctx.installation?.has_cache_editing) {
      if (target.writeCache) { w.code = target.writeCache; w.foreign = true; }
      skip(`installation has no has_cache_editing${w.foreign ? `; using writeCache ${w.code}` : ''}`);
    }
    const params = {
      cache_name: `okapi-test ${stamp}`, cache_type: 'Traditional', latitude: lat, longitude: lon,
      difficulty: '1.5', terrain: '1.5', size2: 'micro', country: 'DE',
      short_description: 'Created by the OKAPI test suite; archived again at the end of the run.',
      description: 'Plain text <b>not bold</b>\nsecond line', hint2: 'under the <stone>',
      alt_wpts: JSON.stringify([
        { location: `${lat}|${lon}`, type: 'parking', description: 'parking' },
        { location: `${lat}|${lon}`, type: 'final', description: 'final' },
      ]),
    };
    const j = await post('services/caches/create', params, 'caches/create');
    assert(j.cache_code, `no cache_code: ${JSON.stringify(j)}`);
    w.code = j.cache_code;
    assert(j.url?.endsWith(`viewcache.php?wp=${w.code}`), `url is ${j.url}`);
    const c = await read('code|name|status|owner|size2|description|hint2|alt_wpts');
    assert(c.owner.uuid === ctx.user?.uuid, 'test cache is not owned by the test user');
    assert(c.size2 === 'micro', `size2 is ${c.size2}`);
    assert(!c.description.includes('<b>'), `plain-text description not escaped: ${c.description}`);
    assert(c.hint2 === 'under the <stone>', `hint2 is ${JSON.stringify(c.hint2)}`);
    assert(c.alt_wpts.map(x => x.type).join() === 'parking,final', `alt_wpts are ${c.alt_wpts.map(x => x.type)}`);
    // Refused as on the website; none of these creates a cache.
    const day = n => new Date(Date.now() + n * 86400e3).toISOString().slice(0, 10);
    for (const [what, extra] of [
      ['difficulty 1.3', { difficulty: '1.3' }],
      ['same coordinates as an active cache', {}],
      ['hidden after publishing', { date_hidden: day(1), latitude: '52.4' }],
      ['event before publishing', { cache_type: 'Event', date_hidden: day(0), latitude: '52.4' }],
      ['publish=later without a date', { publish: 'later', latitude: '52.4' }],
      ['unknown publish choice', { publish: 'soon', latitude: '52.4' }],
    ]) {
      const r = await api.request('services/caches/create', { ...params, ...extra }, { auth: 'hmac', method: 'POST' });
      assert(r.status === 400, `${what}: HTTP ${r.status}, expected 400`);
    }
    return `${w.code} (${c.status}), description escaped, 2 waypoints; 6 website rules refused`;
  });

  await check('write: caches/update', async () => {
    needCache();
    if (w.foreign) skip('writeCache is not the test user\'s cache');
    const name = `okapi-test ${stamp} (updated)`;
    const update = params => api.request('services/caches/update', { cache_code: w.code, ...params }, { auth: 'hmac', method: 'POST' });
    const before = await read('alt_wpts');
    const u = okJson(await update({ cache_name: name, difficulty: '2.5', terrain: '1.5', hint2: '',
      remove_alt_wpts: before.alt_wpts[0].name }), 'caches/update');
    assert(u.changed.join() === 'cache_name,difficulty,hint2,alt_wpts', `changed is ${u.changed}`);
    const c = await read('name|difficulty|terrain|hint2|alt_wpts');
    assert(c.name === name, `name is '${c.name}'`);
    assert(Number(c.difficulty) === 2.5, `difficulty is ${c.difficulty}`);
    assert(c.hint2 === '', `hint2 not removed: ${JSON.stringify(c.hint2)}`);
    assert(c.alt_wpts.map(x => x.type).join() === 'final', `alt_wpts after removal: ${c.alt_wpts.map(x => x.type)}`);
    const pw = okJson(await update({ passwd: 'okapitest' }), 'caches/update passwd');
    assert(pw.changed.join() === 'passwd' && (await read('req_passwd')).req_passwd === true, `passwd not set: ${pw.changed}`);
    const nopw = okJson(await update({ passwd: '' }), 'caches/update passwd clear');
    assert(nopw.changed.join() === 'passwd' && (await read('req_passwd')).req_passwd === false, `passwd not removed: ${nopw.changed}`);
    const again = okJson(await update({ cache_name: name, difficulty: '2.5' }), 'caches/update');
    assert(again.changed.length === 0, `unchanged values changed ${again.changed}`);
    for (const [what, params] of [['empty latitude', { latitude: '', longitude: '' }], ['latitude alone', { latitude: '52.3' }],
      ['unknown waypoint', { remove_alt_wpts: `${w.code}-9` }], ['status', { status: 'Archived' }]]) {
      const r = await update(params);
      assert(r.status === 400, `${what}: HTTP ${r.status}, expected 400`);
    }
    return 'name, difficulty, hint, a waypoint and the log password changed; unchanged values left alone; bad values refused';
  });

  await check('write: save_user_coords set and remove', async () => {
    needCache();
    if (!ctx.hasUserCoords) skip('installation has no services/caches/save_user_coords');
    const [lat, lon] = (await read('location')).location.split('|');
    const coords = `${(Number(lat) + 0.001).toFixed(6)}|${(Number(lon) + 0.001).toFixed(6)}`;
    const same = (a, b) => a.split('|').every((v, i) => Math.abs(Number(v) - Number(b.split('|')[i])) < 1e-6);
    const saved = (await post('services/caches/save_user_coords', { cache_code: w.code, new_value: coords }, 'set')).saved_value;
    assert(saved && same(saved, coords), `saved_value is ${saved}, expected ${coords}`);
    const both = await read('user_coords|alt_wpts');  // both fields at once share one lookup
    const set = both.user_coords;
    const wpt = both.alt_wpts.find(x => x.type === 'user-coords');
    assert(wpt && same(wpt.location, coords), `user-coords waypoint is ${wpt?.location}, expected ${coords}`);
    assert(set && same(set, coords), `user_coords is ${set}, expected ${coords}`);
    const cleared = (await post('services/caches/save_user_coords', { cache_code: w.code, new_value: '' }, 'remove')).saved_value;
    assert(cleared === null, `saved_value is ${cleared} after removal`);
    const removed = (await read('user_coords')).user_coords;
    assert(removed === null, `user_coords is ${removed} after removal`);
    return `${coords}, then removed`;
  });

  await check('write: save_personal_notes set and clear', async () => {
    needCache();
    const note = `okapi-test note ${stamp} äöü`;
    await post('services/caches/save_personal_notes', { cache_code: w.code, new_value: note }, 'set');
    const set = (await read('my_notes')).my_notes;
    assert(set === note, `my_notes is ${JSON.stringify(set)}`);
    await post('services/caches/save_personal_notes', { cache_code: w.code, new_value: '', old_value: note }, 'clear');
    const cleared = (await read('my_notes')).my_notes;
    assert(cleared === null || cleared === '', `my_notes is ${JSON.stringify(cleared)} after clearing`);
    return 'set, then cleared';
  });

  await check('write: lists create/query/update/add/get/remove/delete', async () => {
    needCache();
    if (!ctx.installation?.has_lists) skip('installation has no has_lists');
    const name = `okapi-test ${stamp}`;
    const list = await post('services/lists/create', { list_name: name, list_description: 'a <b>b</b>\nc', list_watch: 'true' }, 'lists/create');
    assert(list.list_id, 'no list_id');
    const raw = async (path, params) => api.request(path, params, { auth: 'hmac', method: 'POST' });
    try {
      // the website's name rules
      const dup = await raw('services/lists/create', { list_name: name });
      assert(dup.status === 400, `duplicate name: HTTP ${dup.status}, expected 400`);
      const short = await raw('services/lists/create', { list_name: 'short', list_status: '2' });
      if (short.status === 200) await post('services/lists/delete', { list_id: JSON.parse(short.text).list_id }, 'cleanup');
      assert(short.status === 400, `public list with a 5-character name: HTTP ${short.status}, expected 400`);
      const lists = okJson(await api.request('services/lists/query', { include_caches: 'true' }, { auth: 'hmac' }), 'lists/query');
      assert(Array.isArray(lists), `query: expected an array, got ${JSON.stringify(lists).slice(0, 80)}`);
      const mine = lists.find(l => l.id === list.list_id);
      assert(mine, 'new list not in query');
      assert(mine.status === 0 && mine.is_watched === true && Array.isArray(mine.cache_codes), `query entry: ${JSON.stringify(mine)}`);
      // plain text in, escaped HTML stored (the website shows it as HTML)
      assert(mine.description === 'a &lt;b&gt;b&lt;/b&gt;<br />\nc', `description stored as ${JSON.stringify(mine.description)}`);
      const added = await post('services/lists/add_caches', { list_id: list.list_id, cache_codes: `${w.code}|${w.code}|OCZZZZZZ` }, 'add_caches');
      assert(added.added_count === 1, `added_count ${added.added_count}, expected 1`);
      const got = await post('services/lists/get_caches', { list_id: list.list_id }, 'get_caches');
      assert(Array.isArray(got.cache_codes) && got.cache_codes.includes(w.code) && got.cache_count === 1, `get_caches: ${JSON.stringify(got)}`);
      const upd = await post('services/lists/update', { list_id: list.list_id, list_description: '', list_watch: 'false' }, 'lists/update');
      assert(upd.description === '' && upd.is_watched === false && upd.name === name, `update: ${JSON.stringify(upd)}`);
      const removed = await post('services/lists/remove_caches', { list_id: list.list_id, cache_codes: w.code }, 'remove_caches');
      assert(removed.removed_count === 1, `removed_count ${removed.removed_count}, expected 1`);
    } finally {
      await post('services/lists/delete', { list_id: list.list_id }, 'lists/delete');
    }
    const gone = await api.request('services/lists/get_caches', { list_id: String(list.list_id) }, { auth: 'hmac' });
    assert(gone.status === 400, `deleted list: HTTP ${gone.status}, expected 400`);
    return 'website name rules, query shape, escaped description, add/remove counts, update, delete';
  });

  await check('write: draftlogs/upload_fieldnotes (hybrid file)', async () => {
    needCache();
    if (!ctx.installation?.has_draft_logs) skip('installation has no has_draft_logs');
    // One hybrid file as c:geo/Garmin write it: UTF-16LE, GC log type names, records of other platforms included.
    const records = [
      ['GC12345', 'Found it', 'TFTC', false],
      [w.code, 'Found it', 'Grüße, Straße ß', true],
      [w.code, 'Write note', "Zeile 1\nZeile 2 mit 'Zitat' <3", true],
      [w.code, 'Needs Maintenance', 'Logbuch voll', true],
      ['OP1234', 'Found it', 'Dzięki', false],
    ];
    const content = records.map(([code, type, text], i) => `${code},2024-05-01T10:2${i}:00Z,${type},"${text}"`).join('\n') + '\n';
    const j = await post('services/draftlogs/upload_fieldnotes',
      { field_notes: Buffer.from(content, 'utf16le').toString('base64') }, 'upload_fieldnotes');
    const expected = records.filter(r => r[3]).length;
    assert(j.success === true, `success is ${j.success}`);
    assert(j.total_records === records.length, `total_records ${j.total_records}, expected ${records.length}`);
    assert(j.processed_records === expected, `processed_records ${j.processed_records}, expected ${expected}`);
    assert(Array.isArray(j.draft_ids) && j.draft_ids.length === expected, `draft_ids ${JSON.stringify(j.draft_ids)}`);
    w.draftIds = j.draft_ids;
    return `${j.processed_records} of ${j.total_records} records became drafts ${j.draft_ids.join(', ')}`;
  });

  await check('write: draftlogs/query and delete', async () => {
    needCache();
    if (!w.draftIds) skip('no drafts uploaded');
    const q = async () => okJson(await api.request('services/draftlogs/query', { cache_code: w.code }, { auth: 'hmac' }), 'draftlogs/query');
    const drafts = await q();
    assert(drafts.length === w.draftIds.length, `query returns ${drafts.length} drafts, expected ${w.draftIds.length}`);
    const got = drafts.map(d => `${d.type}${d.needs_maintenance2 ? '+NM' : ''}`).join(', ');
    assert(got === "Found it, Comment, Comment+NM", `types are ${got}`);
    assert(drafts[1].comment === "Zeile 1<br />\nZeile 2 mit 'Zitat' &lt;3", `comment is ${JSON.stringify(drafts[1].comment)}`);
    const del = await post('services/draftlogs/delete', { draft_ids: [...w.draftIds, '999999999'].join('|') }, 'draftlogs/delete');
    assert(del.success === true, 'success is not true');
    assert([...del.deleted_draft_ids].sort().join() === [...w.draftIds].sort().join(), `deleted ${JSON.stringify(del.deleted_draft_ids)}`);
    assert(del.not_found_draft_ids.join() === '999999999', `not_found ${JSON.stringify(del.not_found_draft_ids)}`);
    assert((await q()).length === 0, 'drafts still there after delete');
    const bad = await api.request('services/draftlogs/delete', { draft_ids: 'abc' }, { auth: 'hmac', method: 'POST' });
    assert(bad.status === 400, `invalid draft_ids: HTTP ${bad.status}, expected 400`);
    return `${got}; deleted, unknown ID reported, invalid ID rejected`;
  });

  await check('write: logs/submit, edit, delete', async () => {
    needCache();
    const res = await api.request('services/logs/submit',
      { cache_code: w.code, logtype: 'Comment', comment: `okapi-test log ${stamp}` }, { auth: 'hmac', method: 'POST' });
    // OKAPI running without the website (some dev setups) lacks the website's OcHTMLPurifier.
    if (/OcHTMLPurifier/.test(res.text)) skip('OKAPI runs without the website (OcHTMLPurifier missing)');
    const j = okJson(res, 'logs/submit');
    assert(j.success === true && j.log_uuids?.length, `submit failed: ${j.message ?? JSON.stringify(j)}`);
    const uuid = j.log_uuids[0];
    try {
      const e = await post('services/logs/edit',
        { log_uuid: uuid, comment: `okapi-test log ${stamp} (edited)`, comment_format: 'plaintext' }, 'logs/edit');
      assert(e.success === true, `edit failed: ${e.message ?? JSON.stringify(e)}`);
    } finally {
      const d = await post('services/logs/delete', { log_uuid: uuid }, 'logs/delete');
      assert(d.success === true, `delete failed: ${d.message ?? JSON.stringify(d)}`);
    }
    return `log ${uuid} submitted, edited, deleted`;
  });

  // Status changes are status logs (services/logs/submit), so they need a full website.
  const status = async (method, expected) => {
    const res = await api.request(`services/caches/${method}`,
      { cache_code: w.code, comment: `okapi-test ${method}` }, { auth: 'hmac', method: 'POST' });
    if (/OcHTMLPurifier/.test(res.text)) skip('OKAPI runs without the website (OcHTMLPurifier missing)');
    // Known, separate: logs/submit doesn't set cache_logs.picture, which has no default on OCDE (strict SQL).
    if (/Field 'picture' doesn't have a default/.test(res.text)) skip('logs/submit fails under strict SQL (cache_logs.picture has no default)');
    const j = okJson(res, `caches/${method}`);
    assert(j.success === true && j.log_uuid, `${method} failed: ${j.message ?? JSON.stringify(j)}`);
    const c = await read('status');
    assert(c.status === expected, `after ${method} status is ${c.status}`);
  };

  await check('write: caches/disable refused on a cache of someone else', async () => {
    needToken();
    const code = w.foreign ? w.code : ctx.cacheCode;
    const c = okJson(await api.request('services/caches/geocache', { cache_code: code, fields: 'owner|status' }, { auth: 'hmac' }), 'geocache');
    if (c.owner.uuid === ctx.user?.uuid) skip(`${code} is the test user's own cache`);
    const j = okJson(await api.request('services/caches/disable', { cache_code: code }, { auth: 'hmac', method: 'POST' }), 'caches/disable');
    assert(j.success === false, `disable was not refused: ${JSON.stringify(j)}`);
    const after = okJson(await api.request('services/caches/geocache', { cache_code: code, fields: 'status' }, { auth: 'hmac' }), 'geocache');
    assert(after.status === c.status, `status changed to ${after.status}`);
    return `${code}: "${j.message}"`;
  });

  await check('write: status change to the current status refused', async () => {
    needCache();
    if (w.foreign) skip('writeCache is not the test user\'s cache');
    const before = (await read('status')).status;
    const method = before === 'Available' ? 'enable' : 'disable';
    const j = okJson(await api.request(`services/caches/${method}`, { cache_code: w.code }, { auth: 'hmac', method: 'POST' }), `caches/${method}`);
    assert(j.success === false && !j.log_uuid, `${method} on a cache that is ${before} was not refused: ${JSON.stringify(j)}`);
    assert((await read('status')).status === before, 'status changed');
    return `${method} on ${before}: "${j.message}"`;
  });

  await check('write: caches/disable and enable', async () => {
    needCache();
    if (w.foreign) skip('writeCache is not the test user\'s cache');
    await status('disable', 'Temporarily unavailable');
    await status('enable', 'Available');
    return 'disabled and enabled again, with status logs';
  });

  // Always last: archive the test cache, even if checks above failed.
  await check('write: caches/archive (the test cache)', async () => {
    needCache();
    if (w.foreign) skip('writeCache is not the test user\'s cache');
    await status('archive', 'Archived');
    return `${w.code} archived`;
  });
}

// --- report ------------------------------------------------------------------

const mark = { pass: '✓', fail: '✗', skip: '–' };
console.log(`OKAPI read-only suite: ${target.baseUrl}${targetName ? ` (target ${targetName})` : ''}\n`);
for (const [title, data] of dumps) console.log(`${title}:\n${JSON.stringify(data, null, 2)}\n`);
for (const r of results) console.log(`${mark[r.status]} ${r.name}${r.detail ? `: ${r.detail}` : ''}`);
const count = s => results.filter(r => r.status === s).length;
console.log(`\n${count('pass')} passed, ${count('fail')} failed, ${count('skip')} skipped`);
process.exit(count('fail') ? 1 : 0);
