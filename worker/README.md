# Off-site audit log: Worker and R2

The append-only front door to the operator's own R2 bucket. The WordPress side
POSTs one small JSON record per mutation, synchronously, before the mutation
runs. This Worker is the only thing holding the R2 binding.

## Why it is shaped this way

A local audit log inside AI Godmode is worthless against the thing it exists
to record: the plugin has complete power over its own host, and `run-php` can
delete the logger. So the record has to leave the machine before the action
runs, and land somewhere the site cannot reach back into.

Two independent layers keep it there.

**Layer one, this Worker is the only door.** It exposes append and query and
nothing else. There is no delete route in the source, and no call to any R2
delete or overwrite method, so no key of any kind reaches one. Two separate
keys sit in front: an ingest key that reaches only append and lives on the
WordPress site, and a viewer key that reaches only query and never touches the
site at all. A fully compromised site can append, and nothing else. It cannot
even read back its own log.

**Layer two, R2 enforces it underneath.** Every append is a conditional write
(`onlyIf: { etagDoesNotMatch: "*" }`) on a key derived from the sequence
number, so a second write to an existing sequence number fails instead of
overwriting. A bucket lock applied at provisioning time blocks deletion below
the Worker entirely.

The limit, stated rather than hidden: the Cloudflare account owner can remove
the lock rules and then delete, because it is their account. That is the
correct trust boundary. What has been eliminated is the AI doing it.

## Routes

| Route | Key | Purpose |
| --- | --- | --- |
| `GET /health` | none | Liveness. Reveals nothing about contents. |
| `POST /ping` | ingest | Validates the ingest key without writing. |
| `POST /append` | ingest | Writes exactly one record. |
| `GET /query` | viewer | Reads records back with a chain check. |
| `GET /view` | viewer | The same as a standalone HTML page. |

Object keys are `sites/<site>/<12-digit sequence>.json`, zero padded so R2's
lexicographic listing is also numeric order.

## The chain check

Three questions, reported separately because they mean different things:

- `missing` a sequence number never written, or written and now gone. This is
  the alarm. Note that gaps are only reported *between* records that exist, so
  a log that starts mid-stream (because off-site logging was switched on after
  the site had already been running) does not report everything before it as
  missing.
- `broken_links` record N's `prev_hash` does not name record N-1's `hash`.
- `hash_mismatches` a record's contents do not match its own hash.

The last one needs the record re-encoded exactly the way PHP's `json_encode`
did, which differs from `JSON.stringify` in escaping forward slashes and every
non-ASCII character. `phpJsonEncode` in the Worker reimplements that, and
`test/check-php-json-encoding.mjs` checks it against real PHP output over
emoji, CJK, control characters, slashes and empty-array-versus-object cases.
The WordPress admin viewer, which uses the real `wp_json_encode`, stays
authoritative.

## Verification status

Deployed and verified live on 2026-09-14. 33 of 33 checks passed, including
key separation both ways, all five no-delete-route probes, the conditional
write refusing to overwrite an existing sequence number, chain verification
over eight PHP-generated records, gap detection, and the HTML view.

The bucket lock was then applied and proved separately: deleting the object
through the Cloudflare API with full account credentials fails with
`10069: The object is locked by the bucket policy`.

One thing worth knowing: the empty-a-bucket API (`DELETE .../objects?prefix=`)
does **not** refuse. It accepts the request and returns a job whose result
reads `isBucketClear: true`, which looks like success. The job then completes
having deleted nothing, because the lock holds. Judge that operation by
`deletedObjects`, not by the job succeeding.

## Tests

```
php test/gen-fixtures.php > fixtures.json     # PHP-hashed records
node test/check-php-json-encoding.mjs         # JS encoder vs real PHP
AUDIT_BASE=... AUDIT_INGEST=... AUDIT_VIEWER=... ./test/live-matrix.sh
```
