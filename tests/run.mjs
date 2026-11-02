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
  const { baseUrl, consumerKey, consumerSecret, accessToken, tokenSecret, allowWrite, writeCache } = config;
  return { baseUrl, consumerKey, consumerSecret, accessToken, tokenSecret, allowWrite, writeCache };
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
  // PLAINTEXT is skipped over plain HTTP (OKAPI not in DEBUG mode), so the user is recorded here too.
  ctx.user = j;
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

// --- writing level (--write) ------------------------------------------------
// Works on the target's writeCache, an existing cache that is never changed itself:
// only per-user data is written to it (own coordinates, a personal note, one Comment
// log), and all of it is removed again.

if (write) {
  const stamp = new Date().toISOString().replace(/[-:]/g, '').slice(0, 15);
  const w = { code: target.writeCache };  // state of the writing level
  const post = async (path, params, what) =>
    okJson(await api.request(path, params, { auth: 'hmac', method: 'POST' }), what);
  const read = async fields =>
    okJson(await api.request('services/caches/geocache', { cache_code: w.code, fields }, { auth: 'hmac' }), 'geocache');
  const needCache = () => { needToken(); if (!w.code) skip('target has no writeCache'); };

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

  await check('write: caches/edit passwd set and clear', async () => {
    needCache();
    const c = await read('owner|req_passwd');
    const edit = async passwd => okJson(await api.request('services/caches/edit',
      { cache_code: w.code, passwd }, { auth: 'hmac', method: 'POST' }), 'caches/edit');
    if (c.owner.uuid !== ctx.user?.uuid) {
      // Only owners reach the password code; others must get a clean refusal.
      const r = await api.request('services/caches/edit', { cache_code: w.code, passwd: 'x' }, { auth: 'hmac', method: 'POST' });
      assert(r.status === 400, `not the owner: HTTP ${r.status}, expected 400`);
      skip(`writeCache ${w.code} is not the test user's cache; refusal checked`);
    }
    assert(c.req_passwd === false, `${w.code} already has a log password`);
    try {
      const set = await edit('okapitest');
      assert(set.success === true, `set failed: ${JSON.stringify(set.messages)}`);
      assert((await read('req_passwd')).req_passwd === true, 'req_passwd not true after setting it');
      const long = await edit('x'.repeat(ctx.installation?.geocache_passwd_max_length + 1));
      assert(long.success === false && long.messages?.passwd, 'a too long password was not refused');
    } finally {
      const clear = await edit('');
      assert(clear.success === true, `clear failed: ${JSON.stringify(clear.messages)}`);
    }
    assert((await read('req_passwd')).req_passwd === false, 'req_passwd not false after clearing it');
    return 'set, too long refused, cleared';
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

  await check('write: logs/images add and delete', async () => {
    needCache();
    const res = await api.request('services/logs/submit',
      { cache_code: w.code, logtype: 'Comment', comment: `okapi-test image log ${stamp}`, comment_format: 'plaintext' }, { auth: 'hmac', method: 'POST' });
    if (/OcHTMLPurifier/.test(res.text)) skip('OKAPI runs without the website (OcHTMLPurifier missing)');
    const j = okJson(res, 'logs/submit');
    assert(j.success === true && j.log_uuids?.length, `submit failed: ${j.message ?? JSON.stringify(j)}`);
    const uuid = j.log_uuids[0];
    let deleted = false;
    try {
      // A 16x16 PNG.
      const image = 'iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAAUElEQVR4nJXCERaFAABE0becWcQsLAiCD0EQBEEQfAiCoAW2hnfPJUNVMlYlU1Xyq0rmqmSpStaqZKtK9qrkqErOquRflVxVyV2VPFXJW/UDj4L6AdKjvyUAAAAASUVORK5CYII=';
      const ar = await api.request('services/logs/images/add',
        { log_uuid: uuid, image, caption: `okapi-test image ${stamp}`, is_spoiler: 'false' }, { auth: 'hmac', method: 'POST' });
      const a = okJson(ar, 'logs/images/add');
      assert(a.success === true && a.image_uuid, `add failed: ${a.message ?? JSON.stringify(a)}`);
      const d = await post('services/logs/images/delete', { image_uuid: a.image_uuid }, 'logs/images/delete');
      assert(d.success === true, `image delete failed: ${JSON.stringify(d)}`);
      const imgs = okJson(await api.request('services/logs/entry', { log_uuid: uuid, fields: 'images' }, { auth: 'hmac' }), 'logs/entry').images;
      assert(Array.isArray(imgs) && imgs.length === 0, `log still has ${imgs?.length} image(s)`);
      // logs/delete also deletes the images of a log: add one more and delete the log with it.
      const b = await post('services/logs/images/add',
        { log_uuid: uuid, image, caption: `okapi-test image ${stamp} (2)`, is_spoiler: 'false' }, 'logs/images/add');
      assert(b.success === true, `second add failed: ${b.message ?? JSON.stringify(b)}`);
      const del = await post('services/logs/delete', { log_uuid: uuid }, 'logs/delete');
      assert(del.success === true, `log delete failed: ${del.message ?? JSON.stringify(del)}`);
      deleted = true;
    } finally {
      if (!deleted) await api.request('services/logs/delete', { log_uuid: uuid }, { auth: 'hmac', method: 'POST' });
    }
    return `log ${uuid}: image added and deleted, log with an image deleted`;
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
