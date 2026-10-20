// Copy to config.js (gitignored) and fill in an OKAPI consumer and an access token
// issued for your test user on the target installation.
export const consumerKey    = 'YOUR_CONSUMER_KEY';
export const consumerSecret = 'YOUR_CONSUMER_SECRET';
export const accessToken    = 'YOUR_ACCESS_TOKEN';
export const tokenSecret    = 'YOUR_TOKEN_SECRET';
export const baseUrl        = 'https://okapi.example.org';
// Allow run.mjs --write on this target (dev/test installations only, never production).
export const allowWrite     = false;
// The existing cache run.mjs --write writes per-user data to (own coordinates, personal
// note, a list, draft logs, a Comment log). On OCPL use a Multi, Quiz or Other cache (user coordinates).
export const writeCache     = '';

// Optional named targets for run.mjs (`node run.mjs --target prod`). Each needs its own
// credentials, issued by that installation. Leave fields empty to skip the levels that
// need them (no consumerKey: only level 0; no access token: no level 3).
export const targets = {
  prod: {
    baseUrl:        'https://www.opencaching.de',
    consumerKey:    '',
    consumerSecret: '',
    accessToken:    '',
    tokenSecret:    '',
    allowWrite:     false,   // never true for production
    // writeCache:  'OC1234', // an existing cache for --write; only per-user data is written to it
  },
};
