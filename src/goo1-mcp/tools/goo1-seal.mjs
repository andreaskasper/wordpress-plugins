#!/usr/bin/env node
/**
 * goo1-seal.mjs — seal a secret for a Claude Bridge (goo1-mcp) site.
 *
 * Reads one entry from your local vault file or from Vaultwarden, encrypts it
 * to the one-time public key of a secret request on the target site, and
 * prints ONLY the envelope plus the non-secret fields. The secret itself is
 * never written to stdout/stderr, so it never reaches the AI conversation.
 *
 * Usage
 *   node goo1-seal.mjs --item wasabi-johnlindo --site https://example.com --request-id sr_…
 *   node goo1-seal.mjs --list                       # entry names only
 *
 * The public key is fetched from the target site itself
 * (/wp-json/goo1-mcp/v1/secrets/public-key/<request>), never taken from the
 * command line, so nobody relaying the command can substitute their own key.
 *
 * allowed-sites.txt in the vault folder is REQUIRED: one site per line
 * ("example.com", "*.example.com", optional ":port"), optionally followed by
 * the entry names that site may receive ("example.com wasabi-example").
 * Whoever writes the command (e.g. an AI) can only seal for listed sites, and
 * the site must report the same host it was contacted on.
 * In a terminal the script asks for confirmation (skip with --yes).
 * Options
 *   --source auto|file|vaultwarden   default auto: file first, then Vaultwarden
 *   --dir <folder>                   folder with goo1-vault.json and bw.env
 *                                    (default $GOO1_VAULT_DIR or ~/mnt/p/Büro/Secrets)
 *
 * Vault file (goo1-vault.json), same shape as a Bitwarden login item:
 *   { "items": { "wasabi-johnlindo": { "username": "<access key>", "password": "<secret key>",
 *       "fields": { "endpoint": "s3.us-central-1.wasabisys.com", "region": "us-central-1", "bucket": "backup.wp.johnlindo" } } } }
 *   Field names starting with "secret_" (or Vaultwarden fields of type "hidden") are treated as secret.
 *
 * bw.env (Vaultwarden machine user, see the Bridge docs):
 *   BW_SERVER=https://vault.example.com
 *   BW_CLIENTID=user.xxxxxxxx
 *   BW_CLIENTSECRET=xxxxxxxx
 *   BW_PASSWORD=<master password of the machine user>
 *   BW_COLLECTION=WP-Secrets          (recommended: only items in this collection)
 *
 * Envelope format: see includes/class-seal.php ("goo1seal1").
 */
import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const X25519_SPKI_PREFIX = Buffer.from('302a300506032b656e032100', 'hex');
const INFO = 'goo1-mcp-seal-v1';
// Only these entry fields are ever printed (everything else may be secret).
const PUBLIC_FIELDS = ['endpoint', 'path', 'bucket', 'region', 'bucket_access_style', 'signature_version'];
const BW_CLI = '@bitwarden/cli@2026.10.0';

function die(msg, code = 1) {
  process.stdout.write(JSON.stringify({ ok: false, error: msg }) + '\n');
  process.exit(code);
}

// Never let a stack trace (which can quote parsed input, i.e. vault data) reach the output.
process.on('uncaughtException', () => die('Internal error (details suppressed so no secret can leak).'));
process.on('unhandledRejection', () => die('Internal error (details suppressed so no secret can leak).'));

function parseJson(text, what) {
  try { return JSON.parse(text); } catch { die(what + ' returned unreadable output'); }
}

function args() {
  const a = process.argv.slice(2), o = {};
  for (let i = 0; i < a.length; i++) {
    if (!a[i].startsWith('--')) continue;
    const k = a[i].slice(2);
    if (i + 1 < a.length && !a[i + 1].startsWith('--')) { o[k] = a[++i]; } else { o[k] = true; }
  }
  return o;
}

const b64url = (buf) => Buffer.from(buf).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
const unb64url = (s) => Buffer.from(String(s).replace(/-/g, '+').replace(/_/g, '/'), 'base64');

export function seal(payload, publicKeyB64url, rid) {
  const pk = unb64url(publicKeyB64url);
  if (pk.length !== 32) throw new Error('public key must be 32 bytes (base64url)');
  if (!/^sr_[0-9a-f]{16}$/.test(rid)) throw new Error('request id looks wrong (expected sr_ + 16 hex)');
  const recipient = crypto.createPublicKey({ key: Buffer.concat([X25519_SPKI_PREFIX, pk]), format: 'der', type: 'spki' });
  const eph = crypto.generateKeyPairSync('x25519');
  const epk = eph.publicKey.export({ format: 'der', type: 'spki' }).subarray(-32);
  const shared = crypto.diffieHellman({ privateKey: eph.privateKey, publicKey: recipient });
  const key = Buffer.from(crypto.hkdfSync('sha256', shared, Buffer.concat([epk, pk]), Buffer.from(INFO), 32));
  const nonce = crypto.randomBytes(12);
  const c = crypto.createCipheriv('chacha20-poly1305', key, nonce, { authTagLength: 16 });
  c.setAAD(Buffer.from('goo1seal1|' + rid));
  const ct = Buffer.concat([c.update(Buffer.from(JSON.stringify(payload))), c.final(), c.getAuthTag()]);
  const env = { v: 1, rid, epk: b64url(epk), n: b64url(nonce), ct: b64url(ct) };
  return 'goo1seal1.' + b64url(Buffer.from(JSON.stringify(env)));
}

function defaultDir() {
  if (process.env.GOO1_VAULT_DIR) return process.env.GOO1_VAULT_DIR;
  return path.join(os.homedir(), 'mnt', 'p', 'Büro', 'Secrets');
}

/* ---------------- sources ---------------- */

function fromFile(dir, name) {
  const file = path.join(dir, 'goo1-vault.json');
  if (!fs.existsSync(file)) return { missing: true };
  let data;
  try { data = JSON.parse(fs.readFileSync(file, 'utf8')); } catch (e) { die('goo1-vault.json is not valid JSON'); }
  const items = data.items || {};
  if (name === null) return { names: Object.keys(items) };
  const it = items[name];
  if (!it) return { notFound: true };
  const fields = it.fields || {};
  const secret = {}, pub = {};
  for (const [k, v] of Object.entries(fields)) {
    if (k.startsWith('secret_')) secret[k.slice(7)] = v; else pub[k] = v;
  }
  return { item: { username: it.username || '', password: it.password || '', secretFields: secret, publicFields: pub } };
}

function readEnv(file) {
  const env = {};
  if (!fs.existsSync(file)) return null;
  for (const line of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
    const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/);
    if (m) env[m[1]] = m[2].replace(/^["']|["']$/g, '');
  }
  return env;
}

function bw(cfg, argv, extraEnv = {}) {
  const env = { ...process.env, ...extraEnv, BITWARDENCLI_APPDATA_DIR: cfg.appdata, BW_NOINTERACTION: 'true' };
  let cmd = 'bw', full = argv;
  try { execFileSync('bw', ['--version'], { stdio: 'ignore', env }); } catch { cmd = 'npx'; full = ['-y', BW_CLI, ...argv]; }
  try {
    return execFileSync(cmd, full, { env, stdio: ['ignore', 'pipe', 'pipe'], encoding: 'utf8', timeout: 120000 }).trim();
  } catch (e) {
    // Never echo bw output (could contain data); only a generic reason.
    throw new Error('bw ' + argv[0] + ' failed' + (e.status ? ' (exit ' + e.status + ')' : ''));
  }
}

function fromVaultwarden(dir, name) {
  const env = readEnv(path.join(dir, 'bw.env'));
  if (!env) return { missing: true };
  for (const k of ['BW_SERVER', 'BW_CLIENTID', 'BW_CLIENTSECRET', 'BW_PASSWORD']) {
    if (!env[k]) die('bw.env is missing ' + k);
  }
  const cfg = { appdata: path.join(dir, '.bw-appdata') };
  fs.mkdirSync(cfg.appdata, { recursive: true, mode: 0o700 });
  let status = {};
  try { status = JSON.parse(bw(cfg, ['status'])); } catch { status = {}; }
  if (!status || typeof status !== 'object') status = {};
  if (status.status === 'unauthenticated' && status.serverUrl !== env.BW_SERVER) {
    bw(cfg, ['config', 'server', env.BW_SERVER]);
  }
  if (status.status === 'unauthenticated' || !status.status) {
    bw(cfg, ['login', '--apikey'], { BW_CLIENTID: env.BW_CLIENTID, BW_CLIENTSECRET: env.BW_CLIENTSECRET });
  }
  // The session goes through the environment (BW_SESSION), not argv, so it never shows up in `ps`.
  const session = bw(cfg, ['unlock', '--passwordenv', 'BW_PASSWORD', '--raw'], { BW_PASSWORD: env.BW_PASSWORD });
  const S = { BW_SESSION: session };
  try {
    bw(cfg, ['sync'], S);
    let collectionId = null;
    if (env.BW_COLLECTION) {
      const cols = parseJson(bw(cfg, ['list', 'collections', '--search', env.BW_COLLECTION], S), 'bw list collections');
      const c = cols.find((x) => x.name === env.BW_COLLECTION);
      if (!c) die('Vaultwarden collection "' + env.BW_COLLECTION + '" not visible for the machine user');
      collectionId = c.id;
    }
    const listArgs = ['list', 'items'];
    if (collectionId) listArgs.push('--collectionid', collectionId);
    if (name !== null) listArgs.push('--search', name);
    const items = parseJson(bw(cfg, listArgs, S), 'bw list items');
    if (!Array.isArray(items)) die('bw list items returned unreadable output');
    if (name === null) return { names: items.filter((i) => i.type === 1).map((i) => i.name) };
    const matches = items.filter((i) => i.name === name && i.type === 1);
    if (matches.length === 0) return { notFound: true };
    if (matches.length > 1) die('More than one Vaultwarden login item is named "' + name + '"');
    const it = matches[0];
    const secret = {}, pub = {};
    for (const f of it.fields || []) {
      if (f.type === 1 || String(f.name).startsWith('secret_')) secret[String(f.name).replace(/^secret_/, '')] = f.value;
      else if (f.type === 0) pub[f.name] = f.value;
    }
    return { item: { username: it.login?.username || '', password: it.login?.password || '', secretFields: secret, publicFields: pub } };
  } finally {
    try { bw(cfg, ['lock']); } catch { /* ignore */ }
  }
}

/* ---------------- main ---------------- */

function siteUrl(site) {
  let u;
  try { u = new URL(String(site).replace(/\/+$/, '') + '/'); } catch { die('--site is not a URL'); }
  if (u.protocol !== 'https:' && !['localhost', '127.0.0.1'].includes(u.hostname)) die('--site must be https');
  return u;
}

async function fetchRequest(u, rid) {
  const url = u.origin + u.pathname + '?rest_route=' + encodeURIComponent('/goo1-mcp/v1/secrets/public-key/' + rid);
  let r;
  try { r = await fetch(url, { headers: { 'User-Agent': 'goo1-seal/1' }, redirect: 'error' }); } catch { die('Could not reach ' + u.origin); }
  if (r.status !== 200) die('Site has no open secret request ' + rid + ' (HTTP ' + r.status + ')');
  let j;
  try { j = await r.json(); } catch { die('Unexpected answer from site'); }
  if (!j || j.request_id !== rid || !j.public_key) die('Unexpected answer from site');
  let reported = '';
  try { reported = new URL(String(j.site)).hostname.toLowerCase(); } catch { /* empty */ }
  if (reported !== u.hostname.toLowerCase()) die('The site at ' + u.host + ' reports a different address (' + (reported || 'none') + ') - refusing.');
  return { publicKey: j.public_key, purpose: j.purpose, expires: j.expires_at };
}

/**
 * allowed-sites.txt (required): "host [entry …]" per line, "*.domain"
 * wildcards, "#" comments. Returns the entry names allowed for the host
 * (empty array = any entry) or null if the host is not listed.
 */
function siteRule(dir, host) {
  const f = path.join(dir, 'allowed-sites.txt');
  if (!fs.existsSync(f)) die('allowed-sites.txt is missing in ' + dir + ' - create it with the sites this script may seal for (see allowed-sites.example.txt).');
  const h = host.toLowerCase(), bare = h.replace(/:\d+$/, '');
  for (const raw of fs.readFileSync(f, 'utf8').split(/\r?\n/)) {
    const line = raw.trim();
    if (!line || line.startsWith('#')) continue;
    const [first, ...items] = line.split(/\s+/);
    const pat = first.toLowerCase().replace(/^https?:\/\//, '').replace(/\/.*$/, '');
    const hit = pat === h || pat === bare || (pat.startsWith('*.') && (bare === pat.slice(2) || bare.endsWith(pat.slice(1))));
    if (hit) return items;
  }
  return null;
}

async function confirm(question) {
  if (!process.stdin.isTTY) return true;
  const rl = (await import('node:readline')).createInterface({ input: process.stdin, output: process.stderr });
  const answer = await new Promise((res) => rl.question(question + ' [y/N] ', res));
  rl.close();
  return /^(y|yes|j|ja)$/i.test(String(answer).trim());
}

const o = args();
const dir = o.dir || defaultDir();
const source = o.source || 'auto';
const listMode = !!o.list;
const name = listMode ? null : (o.item || '');
if (!listMode && (!name || !o['request-id'] || !o.site)) {
  die('Usage: node goo1-seal.mjs --item <name> --site <https://target> --request-id <sr_…> | --list');
}
let target = null, siteU = null;
if (!listMode) {
  siteU = siteUrl(o.site);
  // Check the allowlist before contacting anything.
  const items = siteRule(dir, siteU.host);
  if (items === null) die('Site ' + siteU.host + ' is not in allowed-sites.txt');
  if (items.length && !items.includes(name)) die('Entry "' + name + '" is not allowed for ' + siteU.host + ' in allowed-sites.txt');
  if (!/^sr_[0-9a-f]{16}$/.test(String(o['request-id']))) die('request id looks wrong (expected sr_ + 16 hex)');
  target = await fetchRequest(siteU, o['request-id']);
  if (target.purpose !== 's3') die('Unsupported request purpose');
}

let res = null, used = null;
const order = source === 'auto' ? ['file', 'vaultwarden'] : [source];
const listed = {};
for (const s of order) {
  const r = s === 'file' ? fromFile(dir, name) : s === 'vaultwarden' ? fromVaultwarden(dir, name) : die('unknown --source ' + s);
  if (r.missing) continue;
  if (listMode) { listed[s] = r.names; continue; }
  if (r.item) { res = r.item; used = s; break; }
}

if (listMode) {
  process.stdout.write(JSON.stringify({ ok: true, dir, items: listed }, null, 1) + '\n');
  process.exit(0);
}
if (!res) die('Entry "' + name + '" not found in ' + order.join(' / ') + ' (folder ' + dir + ')');

if (!res.username || !res.password) die('Entry "' + name + '" needs username (access key) and password (secret key)');
const payload = { type: 's3', access_key: res.username, secret_key: res.password, ...res.secretFields };

if (!o.yes && !(await confirm('Seal "' + name + '" for ' + siteU.origin + ' (request ' + o['request-id'] + ')?'))) die('Cancelled.');

let envelope;
try { envelope = seal(payload, target.publicKey, o['request-id']); } catch { die('Sealing failed (bad public key from the site?)'); }
process.stdout.write(JSON.stringify({ ok: true, item: name, source: used, site: siteU.origin, request_id: o['request-id'], expires_at: target.expires, public_fields: Object.fromEntries(Object.entries(res.publicFields || {}).filter(([k]) => PUBLIC_FIELDS.includes(k))), envelope }, null, 1) + '\n');
