// OKAPI client for the test runner: one target, OAuth 1.0a with PLAINTEXT or HMAC-SHA1.
import crypto from 'node:crypto';

const USER_AGENT = 'okapi-test-suite/1.0';

// RFC 3986 percent-encoding, as OAuth 1.0a requires (encodeURIComponent leaves !'()* alone).
function enc(s) {
  return encodeURIComponent(String(s)).replace(/[!'()*]/g, c => '%' + c.charCodeAt(0).toString(16).toUpperCase());
}

function nonce() {
  return crypto.randomBytes(16).toString('hex');
}

export class OkapiClient {
  // target: { baseUrl, consumerKey, consumerSecret, accessToken, tokenSecret }
  constructor(target) {
    this.t = target;
    this.base = target.baseUrl.replace(/\/+$/, '') + '/okapi/';
  }

  url(path) {
    return new URL(path, this.base);
  }

  oauthParams(t, signatureMethod) {
    return {
      oauth_consumer_key: t.consumerKey,
      oauth_token: t.accessToken,
      oauth_signature_method: signatureMethod,
      oauth_timestamp: String(Math.floor(Date.now() / 1000)),
      oauth_nonce: nonce(),
      oauth_version: '1.0',
    };
  }

  // Signature base string per OAuth 1.0a 3.4.1: method & normalized URL & sorted parameters.
  hmacSignature(method, url, params, consumerSecret, tokenSecret) {
    const normalized = `${url.protocol}//${url.host}${url.pathname}`;
    const pairs = Object.entries(params).map(([k, v]) => [enc(k), enc(v)]);
    pairs.sort((a, b) => (a[0] === b[0] ? (a[1] < b[1] ? -1 : 1) : (a[0] < b[0] ? -1 : 1)));
    const paramString = pairs.map(([k, v]) => `${k}=${v}`).join('&');
    const baseString = [method, enc(normalized), enc(paramString)].join('&');
    const key = `${enc(consumerSecret)}&${enc(tokenSecret)}`;
    return crypto.createHmac('sha1', key).update(baseString).digest('base64');
  }

  // auth: 'none' (no credentials), 'key' (consumer_key only), 'plaintext', 'hmac'.
  // overrides: replace credentials for negative tests (e.g. a wrong secret).
  async request(path, params = {}, { auth = 'key', method = 'GET', overrides = {} } = {}) {
    const t = { ...this.t, ...overrides };
    const url = this.url(path);
    const headers = { 'User-Agent': USER_AGENT };
    const query = { ...params };

    if (auth === 'key') {
      query.consumer_key = t.consumerKey;
    } else if (auth === 'plaintext' || auth === 'hmac') {
      const oauth = this.oauthParams(t, auth === 'hmac' ? 'HMAC-SHA1' : 'PLAINTEXT');
      oauth.oauth_signature = auth === 'hmac'
        ? this.hmacSignature(method, url, { ...query, ...oauth }, t.consumerSecret, t.tokenSecret)
        : `${enc(t.consumerSecret)}&${enc(t.tokenSecret)}`;
      headers.Authorization = 'OAuth ' + Object.entries(oauth).map(([k, v]) => `${k}="${enc(v)}"`).join(', ');
    }

    let body;
    if (method === 'GET') {
      for (const [k, v] of Object.entries(query)) url.searchParams.set(k, v);
    } else {
      headers['Content-Type'] = 'application/x-www-form-urlencoded';
      body = new URLSearchParams(query).toString();
    }

    const res = await fetch(url, { method, headers, body });
    const text = await res.text();
    let json = null;
    try { json = JSON.parse(text); } catch { /* not JSON */ }
    return { status: res.status, json, text };
  }
}
