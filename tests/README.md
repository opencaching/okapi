# OKAPI regression suite

A Node.js (ESM, Node 18 or newer, no dependencies) suite that checks an OKAPI
installation from the outside, over HTTP. It runs against any installation (OCDE,
OCPL, a dev setup), so a change can be tested on every platform with the same checks.

## Setup

```bash
cp config.example.js config.js
# edit config.js: the target's baseUrl, a consumer key and an access token
```

`config.js` is gitignored. Never commit it. Credentials are per installation: get a
consumer key from the installation's `okapi/signup.html` and an access token for your
test user.

## Read-only level

```bash
node run.mjs                              # the default target (baseUrl etc. in config.js)
node run.mjs --target prod                # a named target from `targets` in config.js
node run.mjs --expect-revision 3b41888    # also check the deployed okapi_git_revision
```

Exit code 0 = all checks passed, 1 = a check failed, 2 = setup error. It only reads,
so it is safe against any installation, including production.

The output starts with the full responses of `apisrv/installation` (what the
installation can do) and `logs/capabilities` (what the user may log on the test
cache), so every run documents what it ran against.

What it checks:
- level 0: `apisrv/installation` (fields, `has_*` flags, optionally the revision);
  error format for a missing consumer key
- level 1: unknown consumer key rejected; `caches/search/all`, `caches/geocache`, `logs/logs`
- level 3: `users/user` signed with PLAINTEXT and with HMAC-SHA1 (both must work and
  return the same user); HMAC with parameters that need percent-encoding; a wrong
  secret is rejected; `logs/capabilities`; `user_coords` when the installation has `save_user_coords`;
  `draftlogs/query` when `has_draft_logs` is set

Levels whose credentials a target lacks are skipped, not failed. PLAINTEXT is skipped
on plain-HTTP targets, since OKAPI accepts it only over HTTPS.

## Writing level

```bash
node run.mjs --write                      # read-only checks, then the writing level
```

Only for dev and test installations. `--write` is refused unless the target is
marked `allowWrite: true` in `config.js`. Never set it for production.

It works on its own test cache, so it touches no one else's data:
1. `caches/create` a cache `okapi-test <timestamp>` owned by the test user
   (location: `writeLocation` in the target, `'lat|lon'`, default N52.327 E9.543)
2. `caches/update` name and difficulty, read back
3. `caches/save_user_coords`: set, read back via `saved_value` and `user_coords`, remove
4. `caches/save_personal_notes`: set, read back, clear
5. `lists/*`: create, add the cache, get, remove, delete the list
   (only when `has_lists` is set)
6. `draftlogs/upload_fieldnotes` with one hybrid file (UTF-16LE, GC log type names,
   records of other platforms), then `draftlogs/query` and `draftlogs/delete`
   (only when `has_draft_logs` is set)
7. `logs/submit` a Comment, `logs/edit`, `logs/delete`
8. `caches/delete`: the test cache is archived, always last

Left behind per run: the archived test cache.

`caches/create`, `update` and `delete` run only where `has_cache_editing` is set (OCDE). Elsewhere (OCPL), set
`writeCache` in the target to an existing cache code; steps 3–7 then run on that
cache and steps 1, 2 and 8 are skipped, so the cache itself is never changed. Only
per-user data is written to it, and all of it is removed again. OCPL accepts user
coordinates only on Multi, Quiz and Other caches, so pick one of those types there.
