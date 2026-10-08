/**
 * AI Godmode: off-site audit log Worker.
 *
 * This is the append-only front door to one R2 bucket per site. Only this
 * Worker holds the bucket bindings, so a WordPress site can reach its log only
 * through the routes written below, and can reach no other site's log at all.
 *
 * Routes:
 *   GET  /health   no key.      Liveness. Says nothing about the contents.
 *   POST /ping     ingest key.  Validates the ingest key. Writes nothing.
 *   POST /append   ingest key.  Writes exactly one record.
 *   GET  /query    viewer key.  Reads records back, with a chain check.
 *   GET  /view     viewer key.  The same thing as a standalone HTML page, so
 *                               the log stays readable when the site is gone.
 *
 * Isolation, which is the point of this version:
 *
 *   Every site gets its own R2 bucket, its own ingest key and its own viewer
 *   key. The key itself names the site: a presented key is hashed and looked
 *   up in a KV registry, and the answer decides which bucket the request may
 *   touch. A site cannot reach another site's bucket even by lying about its
 *   own name in the record, because the name in the record is checked against
 *   the name the key resolved to.
 *
 *   Adding a site adds one bucket binding and three KV entries. It changes
 *   nothing about any site already registered. The version before this one
 *   shared one bucket and one pair of keys across every site in the account,
 *   so connecting a new site silently cut off every existing one and
 *   invalidated the operator's only copy of the viewer key.
 *
 * There is deliberately no delete route, and no call to any R2 delete or
 * overwrite method anywhere in this file. That is the first of two layers:
 * a fully compromised site holding an ingest key cannot remove or rewrite a
 * record, because no key reaches a door that does not exist. It cannot read
 * its own log either, because the viewer key never touches the site.
 *
 * The second layer is underneath: every append is a conditional write keyed on
 * the sequence number, so reusing a sequence number fails instead of
 * overwriting, and an R2 bucket lock applied at provisioning time stops
 * deletion below this Worker entirely. The lock covers the records prefix
 * rather than the whole bucket, so records are immutable while the bucket
 * itself stays manageable.
 *
 * The limit, stated rather than hidden: the Cloudflare account owner can
 * remove the lock rules and then delete, because it is their account. That is
 * the correct trust boundary. What is eliminated is the AI doing it.
 *
 * Bindings expected:
 *   KEYS         kv_namespace  the site registry, described below
 *   S_<hex>      r2_bucket     one per site, named in that site's registry row
 *
 * Registry layout in KV:
 *   k/i/<sha256 of an ingest key>  ->  site slug
 *   k/v/<sha256 of a viewer key>   ->  site slug
 *   site/<slug>                    ->  JSON:
 *       {
 *         binding:  "S_1a2b3c4d",     the r2_bucket binding for this site
 *         bucket:   "...",            informational, for the viewer header
 *         prefix:   "records/",        where records live inside the bucket
 *         created:  "2026-09-18T...",
 *         rotated:  "2026-09-18T...",
 *         archives: [ { binding, prefix, label } ]   read-only, never written
 *       }
 *
 * Archives exist for one job: a site moved off the old shared bucket keeps its
 * old records readable through the same viewer key, without those records ever
 * being copied, rewritten or renumbered.
 */

const SERVICE = "ai-god-mode-audit";
// The endpoint's own version, reported by /health and read by the plugin before
// it connects a site. A site must never hand its key to code older than the
// plugin that minted it: that code has never heard of the key registry and the
// site would fail closed. The plugin refuses to connect until this matches.
const WORKER_VERSION = "0.5.1";
const MAX_BODY_BYTES = 256 * 1024;
const SEQ_PAD = 12;
const MAX_SEQ = 999999999999;
const DEFAULT_PREFIX = "records/";
const DEFAULT_QUERY_LIMIT = 200;
const MAX_QUERY_LIMIT = 1000;

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    const path = url.pathname.replace(/\/+$/, "") || "/";

    try {
      if (request.method === "GET" && path === "/health") {
        return json({ ok: true, service: SERVICE, version: WORKER_VERSION, time: new Date().toISOString() });
      }
      if (request.method === "POST" && path === "/ping") {
        return await handlePing(request, env);
      }
      if (request.method === "POST" && path === "/append") {
        return await handleAppend(request, env);
      }
      if (request.method === "GET" && path === "/query") {
        return await handleQuery(request, env, url);
      }
      if (request.method === "GET" && path === "/view") {
        return await handleView(request, env, url);
      }
      return json({ ok: false, error: "not_found" }, 404);
    } catch (err) {
      // Never leak internals to a caller. The message is deliberately flat.
      return json({ ok: false, error: "internal_error" }, 500);
    }
  },
};

/* -------------------------------------------------------------------------
 * Section: authentication and site resolution.
 *
 * A key is never compared against a stored key, because no key is stored.
 * What is stored is the SHA-256 of each key, used directly as a KV lookup,
 * so a wrong key produces a miss and tells the caller nothing beyond that.
 * ---------------------------------------------------------------------- */

async function sha256Hex(text) {
  const bytes = new TextEncoder().encode(text);
  const digest = await crypto.subtle.digest("SHA-256", bytes);
  return [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, "0")).join("");
}

/** Bearer header first, then ?key= for the HTML view, which cannot set headers. */
function presentedKey(request, url) {
  const header = request.headers.get("authorization") || "";
  const match = /^Bearer\s+(.+)$/i.exec(header.trim());
  if (match) {
    return match[1].trim();
  }
  const xKey = request.headers.get("x-audit-key");
  if (xKey) {
    return xKey.trim();
  }
  if (url) {
    const q = url.searchParams.get("key");
    if (q) {
      return q.trim();
    }
  }
  return "";
}

async function resolveSite(env, presented, kind) {
  if (!env.KEYS || typeof presented !== "string" || presented === "") {
    return null;
  }
  const slug = await env.KEYS.get("k/" + kind + "/" + (await sha256Hex(presented)));
  if (!slug) {
    return null;
  }
  return await loadSite(env, slug);
}

async function loadSite(env, slug) {
  const raw = await env.KEYS.get("site/" + slug);
  if (!raw) {
    return null;
  }
  let record;
  try {
    record = JSON.parse(raw);
  } catch (err) {
    return null;
  }
  if (!record || typeof record.binding !== "string") {
    return null;
  }
  record.slug = slug;
  record.prefix = typeof record.prefix === "string" && record.prefix !== "" ? record.prefix : DEFAULT_PREFIX;
  record.archives = Array.isArray(record.archives) ? record.archives : [];
  return record;
}

/** The bucket a site's records live in, or null when the binding is missing. */
function bucketFor(env, site) {
  const bucket = env[site.binding];
  return bucket && typeof bucket.get === "function" ? bucket : null;
}

/* -------------------------------------------------------------------------
 * Section: keys and validation.
 *
 * Object keys are derived from the sequence number, zero padded so that R2's
 * lexicographic listing is also numeric order. There is no site component in
 * the path any more, because the bucket is the site.
 * ---------------------------------------------------------------------- */

function siteSlug(site) {
  return String(site)
    .toLowerCase()
    .replace(/[^a-z0-9.-]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 253);
}

function objectKey(prefix, seq) {
  return `${prefix}${String(seq).padStart(SEQ_PAD, "0")}.json`;
}

function isHex64(value) {
  return typeof value === "string" && /^[0-9a-f]{64}$/.test(value);
}

/**
 * Structural validation only. The ingest key belongs to the site, so a
 * compromised site can append records that are true garbage semantically.
 * That is accepted by the threat model. What is enforced here is that the
 * log stays parseable and orderable, because an unreadable log is no log.
 */
function validateRecord(record) {
  if (!record || typeof record !== "object" || Array.isArray(record)) {
    return "record must be a JSON object";
  }
  if (!Number.isInteger(record.seq) || record.seq < 1 || record.seq > MAX_SEQ) {
    return "seq must be a positive integer";
  }
  if (!isHex64(record.hash)) {
    return "hash must be 64 lowercase hex characters";
  }
  if (!isHex64(record.prev_hash)) {
    return "prev_hash must be 64 lowercase hex characters";
  }
  if (typeof record.site !== "string" || record.site.trim() === "") {
    return "site must be a non-empty string";
  }
  if (typeof record.event !== "string" || record.event.trim() === "") {
    return "event must be a non-empty string";
  }
  if (siteSlug(record.site) === "") {
    return "site did not survive normalisation";
  }
  return null;
}

/* -------------------------------------------------------------------------
 * Section: routes that write.
 * ---------------------------------------------------------------------- */

async function handlePing(request, env) {
  const site = await resolveSite(env, presentedKey(request, null), "i");
  if (!site) {
    return json({ ok: false, error: "unauthorized" }, 401);
  }
  return json({ ok: true, service: SERVICE, route: "ping", site: site.slug, time: new Date().toISOString() });
}

async function handleAppend(request, env) {
  const site = await resolveSite(env, presentedKey(request, null), "i");
  if (!site) {
    return json({ ok: false, error: "unauthorized" }, 401);
  }

  const raw = await request.text();
  if (raw.length > MAX_BODY_BYTES) {
    return json({ ok: false, error: "record_too_large", limit: MAX_BODY_BYTES }, 413);
  }

  let record;
  try {
    record = JSON.parse(raw);
  } catch (err) {
    return json({ ok: false, error: "invalid_json" }, 400);
  }

  const problem = validateRecord(record);
  if (problem) {
    return json({ ok: false, error: "invalid_record", detail: problem }, 400);
  }

  // The key decided which site this is. The record only gets to agree with it.
  // Without this check a site holding a valid key could file records under a
  // neighbour's name, and the neighbour's log would carry entries it never
  // wrote. The bucket would still be this site's, so nothing would be
  // overwritten, but the record would be a lie and lies are what the log
  // exists to prevent.
  if (siteSlug(record.site) !== site.slug) {
    return json({ ok: false, error: "site_mismatch", expected: site.slug }, 403);
  }

  const bucket = bucketFor(env, site);
  if (!bucket) {
    return json({ ok: false, error: "bucket_unavailable" }, 503);
  }

  const key = objectKey(site.prefix, record.seq);

  // The conditional write. etagDoesNotMatch "*" means "only if no object is
  // already at this key". A second write to the same sequence number returns
  // null and stores nothing, so history cannot be rewritten through this
  // route even if the Worker itself were replaced by something careless.
  //
  // Two different things can stop that write, and both mean the same thing to
  // a caller: this sequence number is taken. R2 returns null when the
  // precondition fails, and throws when the bucket's immutability lock refuses
  // the overwrite. The throw used to escape into the top-level handler and
  // come back as a flat 500, which is the worst possible answer here: an
  // operator staring at a 500 cannot tell an attempted rewrite of history from
  // a broken endpoint, and telling those two apart is the entire job of this
  // alarm. So the throw is caught, and the question is settled by asking
  // whether the object is actually there.
  let put;
  try {
    put = await bucket.put(key, raw, {
      onlyIf: { etagDoesNotMatch: "*" },
      httpMetadata: { contentType: "application/json" },
      customMetadata: {
        seq: String(record.seq),
        hash: record.hash,
        event: String(record.event).slice(0, 64),
        received_at: new Date().toISOString(),
      },
    });
  } catch (err) {
    const existing = await bucket.head(key).catch(() => null);
    if (existing) {
      return json({ ok: false, error: "sequence_exists", seq: record.seq }, 409);
    }
    throw err;
  }

  if (put === null) {
    return json({ ok: false, error: "sequence_exists", seq: record.seq }, 409);
  }

  return json({ ok: true, seq: record.seq, site: site.slug, key }, 201);
}

/* -------------------------------------------------------------------------
 * Section: routes that read.
 * ---------------------------------------------------------------------- */

/** Which store a read is against: the site's own bucket, or one of its archives. */
function readTarget(env, site, archiveIndex) {
  if (archiveIndex === null) {
    return { bucket: bucketFor(env, site), prefix: site.prefix, label: null };
  }
  const archive = site.archives[archiveIndex];
  if (!archive || typeof archive.binding !== "string") {
    return null;
  }
  const bucket = env[archive.binding];
  if (!bucket || typeof bucket.get !== "function") {
    return null;
  }
  return {
    bucket,
    prefix: typeof archive.prefix === "string" ? archive.prefix : "",
    label: typeof archive.label === "string" ? archive.label : "archive",
  };
}

async function listRecords(target, limit, cursor, startAfter) {
  const listing = await target.bucket.list({
    prefix: target.prefix,
    limit,
    cursor: cursor || undefined,
    startAfter: !cursor && startAfter ? startAfter : undefined,
    // Without this, R2 omits customMetadata from listings and the server-side
    // receipt time silently comes back null. Caught in live testing.
    include: ["customMetadata"],
  });

  const records = [];
  for (const object of listing.objects) {
    const body = await target.bucket.get(object.key);
    if (!body) {
      continue;
    }
    const text = await body.text();
    let parsed;
    try {
      parsed = JSON.parse(text);
    } catch (err) {
      records.push({ _key: object.key, _unparseable: true });
      continue;
    }
    parsed._key = object.key;
    parsed._received_at = object.customMetadata ? object.customMetadata.received_at : null;
    records.push(parsed);
  }

  return {
    records,
    cursor: listing.truncated ? listing.cursor : null,
    truncated: Boolean(listing.truncated),
  };
}

/**
 * The chain check. Three separate questions, reported separately, because
 * they fail for different reasons and mean different things.
 *
 *  missing        a sequence number that was never written, or was written
 *                 and is gone. This is the alarm the whole design exists for.
 *  broken_links   record N's prev_hash does not name record N-1's hash.
 *  hash_mismatch  the record's own hash does not match its contents.
 *
 * This alarm is only worth having if it stays quiet during ordinary use. The
 * version before this one shared one sequence counter between the local log
 * and the off-site log while sending only mutations off-site, so every read
 * burned a number that never arrived here and the check reported missing
 * records on a perfectly healthy site. The plugin now keeps a separate
 * counter per sink, so the off-site sequence is dense by construction.
 *
 * The hash recomputation re-encodes the record the way PHP's json_encode
 * does, which is what the plugin hashed. It is a faithful reimplementation
 * and it is checked by the test suite, but the WordPress admin viewer, which
 * uses the real wp_json_encode, remains authoritative. A mismatch reported
 * here on an otherwise healthy log is worth checking there before panicking.
 */
async function verifyChain(records) {
  const usable = records.filter((r) => !r._unparseable && Number.isInteger(r.seq));
  usable.sort((a, b) => a.seq - b.seq);

  const missing = [];
  const brokenLinks = [];
  const hashMismatches = [];
  const unparseable = records.filter((r) => r._unparseable).map((r) => r._key);

  for (let i = 0; i < usable.length; i++) {
    const record = usable[i];

    if (i > 0) {
      const previous = usable[i - 1];
      for (let gap = previous.seq + 1; gap < record.seq; gap++) {
        missing.push(gap);
        if (missing.length > 500) {
          break;
        }
      }
      if (record.prev_hash !== previous.hash && record.seq === previous.seq + 1) {
        brokenLinks.push({ seq: record.seq, expected: previous.hash, found: record.prev_hash });
      }
    }

    const recomputed = await recomputeHash(record);
    if (recomputed !== null && recomputed !== record.hash) {
      hashMismatches.push({ seq: record.seq, expected: recomputed, found: record.hash });
    }
  }

  return {
    ok: missing.length === 0 && brokenLinks.length === 0 && hashMismatches.length === 0 && unparseable.length === 0,
    first_seq: usable.length ? usable[0].seq : null,
    last_seq: usable.length ? usable[usable.length - 1].seq : null,
    count: usable.length,
    missing,
    broken_links: brokenLinks,
    hash_mismatches: hashMismatches,
    unparseable,
  };
}

async function recomputeHash(record) {
  const fields = ["seq", "prev_hash", "time", "site", "event", "ability", "mutation", "user_id", "input", "extra"];
  const rebuilt = {};
  for (const field of fields) {
    if (!(field in record)) {
      return null;
    }
    rebuilt[field] = record[field];
  }
  return await sha256Hex(record.prev_hash + phpJsonEncode(rebuilt));
}

/* -------------------------------------------------------------------------
 * Section: PHP-compatible JSON encoding.
 *
 * PHP's json_encode with default flags differs from JSON.stringify in two
 * ways that matter to a byte-exact hash: it escapes the forward slash, and it
 * escapes every non-ASCII character as a \u sequence. Everything else, key
 * order included, already matches.
 * ---------------------------------------------------------------------- */

function phpJsonEncode(value) {
  if (value === null || value === undefined) {
    return "null";
  }
  const type = typeof value;
  if (type === "boolean") {
    return value ? "true" : "false";
  }
  if (type === "number") {
    return Number.isFinite(value) ? String(value) : "0";
  }
  if (type === "string") {
    return phpJsonString(value);
  }
  if (Array.isArray(value)) {
    return "[" + value.map(phpJsonEncode).join(",") + "]";
  }
  const parts = [];
  for (const key of Object.keys(value)) {
    parts.push(phpJsonString(key) + ":" + phpJsonEncode(value[key]));
  }
  return "{" + parts.join(",") + "}";
}

function phpJsonString(text) {
  let out = '"';
  for (let i = 0; i < text.length; i++) {
    const code = text.charCodeAt(i);
    const char = text[i];
    if (char === '"') {
      out += '\\"';
    } else if (char === "\\") {
      out += "\\\\";
    } else if (char === "/") {
      out += "\\/";
    } else if (char === "\b") {
      out += "\\b";
    } else if (char === "\f") {
      out += "\\f";
    } else if (char === "\n") {
      out += "\\n";
    } else if (char === "\r") {
      out += "\\r";
    } else if (char === "\t") {
      out += "\\t";
    } else if (code < 0x20 || code > 0x7e) {
      out += "\\u" + code.toString(16).padStart(4, "0");
    } else {
      out += char;
    }
  }
  return out + '"';
}

/* -------------------------------------------------------------------------
 * Section: query and view.
 * ---------------------------------------------------------------------- */

function archiveIndexFrom(url) {
  const raw = url.searchParams.get("archive");
  if (raw === null || raw === "") {
    return null;
  }
  const n = parseInt(raw, 10);
  return Number.isInteger(n) && n >= 0 ? n : null;
}

async function handleQuery(request, env, url) {
  const site = await resolveSite(env, presentedKey(request, url), "v");
  if (!site) {
    return json({ ok: false, error: "unauthorized" }, 401);
  }

  const target = readTarget(env, site, archiveIndexFrom(url));
  if (!target || !target.bucket) {
    return json({ ok: false, error: "bucket_unavailable" }, 503);
  }

  let limit = parseInt(url.searchParams.get("limit") || String(DEFAULT_QUERY_LIMIT), 10);
  if (!Number.isFinite(limit) || limit < 1) {
    limit = DEFAULT_QUERY_LIMIT;
  }
  limit = Math.min(limit, MAX_QUERY_LIMIT);

  const from = url.searchParams.get("from");
  const startAfter = from ? objectKey(target.prefix, parseInt(from, 10) - 1) : undefined;

  const page = await listRecords(target, limit, url.searchParams.get("cursor"), startAfter);
  const check = await verifyChain(page.records);

  return json({
    ok: true,
    site: site.slug,
    archive: target.label,
    count: page.records.length,
    truncated: page.truncated,
    next_cursor: page.cursor,
    check,
    records: page.records,
  });
}

async function handleView(request, env, url) {
  const key = url.searchParams.get("key") || "";
  const site = await resolveSite(env, presentedKey(request, url), "v");
  if (!site) {
    return new Response(loginPage(), {
      status: 401,
      headers: { "content-type": "text/html; charset=utf-8" },
    });
  }

  const archiveIndex = archiveIndexFrom(url);
  const target = readTarget(env, site, archiveIndex);
  if (!target || !target.bucket) {
    return html(shell("Audit log", `<h1>${esc(site.slug)}</h1><p class="sub">This log's storage is not reachable from the endpoint right now.</p>`), 503);
  }

  let limit = parseInt(url.searchParams.get("limit") || "200", 10);
  if (!Number.isFinite(limit) || limit < 1) {
    limit = 200;
  }
  limit = Math.min(limit, MAX_QUERY_LIMIT);

  const page = await listRecords(target, limit, url.searchParams.get("cursor"), undefined);
  const check = await verifyChain(page.records);
  return html(logPage(site, target, page, check, key, archiveIndex));
}

/* -------------------------------------------------------------------------
 * Section: HTML. Self contained, no external requests, readable on a phone.
 * ---------------------------------------------------------------------- */

function esc(value) {
  return String(value === null || value === undefined ? "" : value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

const STYLE = `
:root { color-scheme: light dark; --bg:#fbfbfa; --fg:#16150f; --muted:#5f5c50;
  --line:#e2dfd5; --card:#ffffff; --ok:#1f6f43; --okbg:#e8f5ed; --bad:#a3231c; --badbg:#fdeceb; }
@media (prefers-color-scheme: dark) { :root { --bg:#15150f; --fg:#f0eee4; --muted:#a5a294;
  --line:#32302a; --card:#1e1d16; --ok:#63c68f; --okbg:#15291f; --bad:#f08c84; --badbg:#2c1715; } }
* { box-sizing:border-box; }
body { margin:0; padding:24px 16px; background:var(--bg); color:var(--fg);
  font:15px/1.5 ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif; }
.wrap { max-width:1100px; margin:0 auto; }
h1 { font-size:1.35rem; margin:0 0 4px; letter-spacing:-0.01em; }
.sub { color:var(--muted); margin:0 0 20px; font-size:0.9rem; }
.banner { padding:12px 16px; border-radius:8px; margin:0 0 20px; font-weight:600; }
.banner.ok { background:var(--okbg); color:var(--ok); }
.banner.bad { background:var(--badbg); color:var(--bad); }
.banner ul { font-weight:400; margin:8px 0 0; padding-left:20px; }
.scroll { overflow-x:auto; border:1px solid var(--line); border-radius:8px; background:var(--card); }
table { border-collapse:collapse; width:100%; font-size:0.86rem; }
th, td { text-align:left; padding:8px 10px; border-bottom:1px solid var(--line); vertical-align:top; }
th { font-weight:600; color:var(--muted); font-size:0.78rem; text-transform:uppercase; letter-spacing:0.04em; white-space:nowrap; }
tr:last-child td { border-bottom:none; }
td.num { font-variant-numeric:tabular-nums; white-space:nowrap; }
code { font:0.82em ui-monospace,SFMono-Regular,Menlo,monospace; word-break:break-all; }
.tag { display:inline-block; padding:1px 7px; border-radius:99px; font-size:0.75rem; border:1px solid var(--line); }
.tag.mut { background:var(--badbg); color:var(--bad); border-color:transparent; }
details summary { cursor:pointer; color:var(--muted); font-size:0.8rem; }
pre { margin:6px 0 0; padding:8px; background:var(--bg); border-radius:6px; overflow-x:auto; font-size:0.78rem; }
a { color:inherit; }
form { display:flex; gap:8px; flex-wrap:wrap; margin:16px 0; }
input { padding:8px 10px; border:1px solid var(--line); border-radius:6px; background:var(--card); color:var(--fg); font:inherit; }
button { padding:8px 16px; border:none; border-radius:6px; background:var(--fg); color:var(--bg); font:inherit; font-weight:600; cursor:pointer; }
.where { margin:0 0 16px; font-size:0.85rem; color:var(--muted); }
.where a { font-weight:600; }
`;

function shell(title, body) {
  return `<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>${esc(title)}</title><style>${STYLE}</style></head>
<body><div class="wrap">${body}</div></body></html>`;
}

function html(markup, status = 200) {
  return new Response(markup, {
    status,
    headers: {
      "content-type": "text/html; charset=utf-8",
      "cache-control": "no-store",
      "x-robots-tag": "noindex, nofollow",
    },
  });
}

function loginPage() {
  return shell(
    "AI Godmode audit log",
    `<h1>AI Godmode audit log</h1>
<p class="sub">This view needs the viewer key for one site. The viewer key is the one that is never stored on the WordPress site, and each site has its own.</p>
<form method="get" action="/view">
  <input type="password" name="key" placeholder="Viewer key" autofocus required>
  <button type="submit">Open the log</button>
</form>`
  );
}

/** Links to this site's archives, if it has any. The key names the site, so
 *  there is nothing to choose between except which store to read. */
function whereLine(site, key, archiveIndex) {
  if (!site.archives.length) {
    return "";
  }
  const links = [];
  const current = (index) => index === archiveIndex;
  links.push(
    current(null)
      ? "<strong>Current log</strong>"
      : `<a href="/view?key=${encodeURIComponent(key)}">Current log</a>`
  );
  site.archives.forEach((archive, index) => {
    const label = esc(archive.label || `Archive ${index + 1}`);
    links.push(
      current(index)
        ? `<strong>${label}</strong>`
        : `<a href="/view?key=${encodeURIComponent(key)}&amp;archive=${index}">${label}</a>`
    );
  });
  return `<p class="where">${links.join(" &middot; ")}</p>`;
}

function logPage(site, target, page, check, key, archiveIndex) {
  const banner = check.ok
    ? `<div class="banner ok">Chain intact. ${check.count} records, sequence ${check.first_seq} to ${check.last_seq}, no gaps and no altered records.</div>`
    : `<div class="banner bad">Chain check FAILED.<ul>
${check.missing.length ? `<li>Missing sequence numbers: ${esc(check.missing.slice(0, 50).join(", "))}${check.missing.length > 50 ? " and more" : ""}</li>` : ""}
${check.broken_links.length ? `<li>Broken chain links at sequence: ${esc(check.broken_links.map((b) => b.seq).join(", "))}</li>` : ""}
${check.hash_mismatches.length ? `<li>Records whose contents do not match their own hash, at sequence: ${esc(check.hash_mismatches.map((h) => h.seq).join(", "))}</li>` : ""}
${check.unparseable.length ? `<li>Unreadable objects: ${esc(check.unparseable.join(", "))}</li>` : ""}
</ul></div>`;

  const rows = page.records
    .slice()
    .sort((a, b) => (b.seq || 0) - (a.seq || 0))
    .map((r) => {
      if (r._unparseable) {
        return `<tr><td colspan="6">Unreadable object <code>${esc(r._key)}</code></td></tr>`;
      }
      const input = r.input ? `<details><summary>input</summary><pre>${esc(JSON.stringify(r.input, null, 2))}</pre></details>` : "";
      const extra = r.extra && Object.keys(r.extra).length ? `<details><summary>extra</summary><pre>${esc(JSON.stringify(r.extra, null, 2))}</pre></details>` : "";
      return `<tr>
<td class="num">${esc(r.seq)}</td>
<td class="num">${esc(r.time)}</td>
<td>${esc(r.event)}${r.mutation ? ' <span class="tag mut">mutation</span>' : ""}</td>
<td>${esc(r.ability || "")}</td>
<td class="num">${esc(r.user_id)}</td>
<td>${input}${extra}<details><summary>hash</summary><pre>${esc(r.hash)}</pre></details></td>
</tr>`;
    })
    .join("");

  const archiveParam = archiveIndex === null ? "" : `&amp;archive=${archiveIndex}`;
  const more = page.cursor
    ? `<p class="sub"><a href="/view?key=${encodeURIComponent(key)}${archiveParam}&amp;cursor=${encodeURIComponent(page.cursor)}">Next page</a></p>`
    : "";

  const heading = target.label ? `${site.slug} <span class="tag">${esc(target.label)}</span>` : esc(site.slug);

  return shell(
    `${site.slug} audit log`,
    `<h1>${heading}</h1>
<p class="sub">Off-site audit log, newest first. This page is served by the Worker and does not depend on the WordPress site being alive. This key opens this site and no other.</p>
${whereLine(site, key, archiveIndex)}
${banner}
<div class="scroll"><table>
<thead><tr><th>Seq</th><th>Time (UTC)</th><th>Event</th><th>Ability</th><th>User</th><th>Detail</th></tr></thead>
<tbody>${rows}</tbody></table></div>
${more}`
  );
}

function json(payload, status = 200) {
  return new Response(JSON.stringify(payload), {
    status,
    headers: { "content-type": "application/json; charset=utf-8", "cache-control": "no-store" },
  });
}
