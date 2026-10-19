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
  secret is rejected; `logs/capabilities`; `user_coords` when `has_user_coords` is set

Levels whose credentials a target lacks are skipped, not failed. PLAINTEXT is skipped
on plain-HTTP targets, since OKAPI accepts it only over HTTPS.

## Writing level

```bash
node run.mjs --write                      # read-only checks, then the writing level
```

Only for dev and test installations. `--write` is refused unless the target is
marked `allowWrite: true` in `config.js`. Never set it for production.

It writes only per-user data, to an existing cache named by `writeCache` in the
target, and removes all of it again; the cache itself is never changed:
1. `caches/save_user_coords`: set, read back via `saved_value` and `user_coords`, remove
2. `caches/save_personal_notes`: set, read back, clear
3. `logs/submit` a Comment, `logs/edit`, `logs/delete`

On OCPL, user coordinates are accepted only on Multi, Quiz and Other caches, so pick
`writeCache` of one of those types there.

Without `writeCache` these checks are skipped.
