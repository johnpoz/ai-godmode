<?php
/**
 * Cloudflare provisioning for the off-site audit log.
 *
 * The operator pastes one scoped Cloudflare token. This class then builds the
 * whole sink on their own account: an R2 bucket for this site, the shared
 * Worker in front of it, the bucket lock, and a freshly minted pair of keys
 * belonging to this site alone. Then it throws the token away and never writes
 * it anywhere.
 *
 * Throwing the token away is the entire point. A Cloudflare token that can
 * create Workers, sitting in a wp_options row that run-php can read, turns the
 * blast radius from "an attacker can append junk to their own log" into "an
 * attacker owns the operator's Cloudflare account". What remains stored is the
 * endpoint URL and an ingest key that can do exactly one thing: append to this
 * site's bucket.
 *
 * The viewer key is returned to the caller to be shown once and never stored,
 * because a site that holds the key to read its own log can be made to lie
 * about it.
 *
 * One Worker per account, one bucket per site.
 *
 *   The Worker code is identical for every site, so one copy per account keeps
 *   a single thing to upgrade. Storage and keys are per site, because that is
 *   where isolation has to be real. Setting up site number two must not be
 *   able to touch site number one, and before this version it did exactly
 *   that: one bucket and one shared pair of secrets meant each new setup run
 *   cut off every site already connected and invalidated the operator's only
 *   copy of the viewer key, with no warning to anybody.
 *
 *   What makes the new shape safe is that adding a site only ever adds. A new
 *   bucket, a new binding alongside the existing ones, and new rows in the key
 *   registry. Nothing existing is rewritten, so nothing existing can break.
 *
 * Required token scopes:
 *   Account Settings    Read
 *   Workers Scripts     Edit
 *   Workers R2 Storage  Edit
 *   Workers KV Storage  Edit
 *
 * @package AIGodmode
 */

namespace AIGodmode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Cloudflare_Provisioner {

	private const API          = 'https://api.cloudflare.com/client/v4';

	// The Worker keeps the pre-0.4.0 spelling on purpose. Accounts already
	// running one have a workers.dev address printed on screens and saved in
	// browsers, and renaming it would strand them.
	private const SCRIPT       = 'ai-god-mode-audit';

	// Buckets are new in this version, so they get the current spelling. An R2
	// bucket cannot be renamed, so this prefix is permanent once shipped.
	private const BUCKET_PREFIX = 'godmode-audit-';

	// The key registry. One namespace per account, shared by every site, and
	// it holds only hashes: a leak of it grants nothing.
	private const KV_TITLE     = 'ai-godmode-audit-keys';

	private const RECORD_PREFIX = 'records/';

	// The endpoint software version this plugin expects to be talking to. It
	// must match the WORKER_VERSION constant in worker/audit-sink.js. Connecting
	// a site to an endpoint older than this is refused, because the key this
	// plugin mints lives in a registry that older code does not read: the site
	// would store a key the endpoint has never heard of and then fail closed on
	// every action. That is a silent outage, and it is exactly what happened to
	// nobody only because it was caught before anyone ran setup.
	private const WORKER_VERSION = '0.5.1';
	private const COMPAT_DATE  = '2026-09-01';
	private const HTTP_TIMEOUT = 30;

	private Settings $settings;

	/** @var string[] Human readable trace of what was done, for the result screen. */
	private array $trace = array();

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function trace(): array {
		return $this->trace;
	}

	private function note( string $line ): void {
		$this->trace[] = $line;
	}

	// -----------------------------------------------------------------------
	// Section: Naming.
	//
	// Both names are derived from the site host and a short digest of it. The
	// digest is there so that two hosts that flatten to the same slug, say
	// example.com and example-com.net, cannot collide on one bucket.
	// -----------------------------------------------------------------------

	public static function site_host(): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return '' === $host ? 'site' : $host;
	}

	public static function site_slug( string $host = '' ): string {
		$host = '' === $host ? self::site_host() : $host;
		$slug = strtolower( $host );
		$slug = preg_replace( '/[^a-z0-9.-]+/', '-', $slug );
		$slug = trim( (string) $slug, '-' );
		return '' === $slug ? 'site' : substr( $slug, 0, 253 );
	}

	/**
	 * R2 bucket names allow lowercase letters, digits and hyphens, must start
	 * and end alphanumeric, and cap at 63 characters. Dots are legal in a host
	 * and not in a bucket name, so they become hyphens here.
	 */
	public static function bucket_name( string $host = '' ): string {
		$host  = '' === $host ? self::site_host() : $host;
		$flat  = strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $host ) ?? '' );
		$flat  = trim( $flat, '-' );
		$flat  = substr( $flat, 0, 40 );
		$flat  = trim( $flat, '-' );
		$short = substr( hash( 'sha256', strtolower( $host ) ), 0, 6 );
		$name  = self::BUCKET_PREFIX . ( '' === $flat ? 'site' : $flat ) . '-' . $short;
		return substr( $name, 0, 63 );
	}

	/**
	 * The Worker binding name for an archived bucket. Derived from the bucket
	 * name so that two sites naming the same old bucket share one binding
	 * rather than each adding their own copy of it.
	 */
	public static function archive_binding_name( string $bucket ): string {
		return 'A_' . substr( hash( 'sha256', strtolower( $bucket ) ), 0, 8 );
	}

	/** The Worker binding name for this site's bucket. Must be a JS identifier. */
	public static function binding_name( string $host = '' ): string {
		$host = '' === $host ? self::site_host() : $host;
		return 'S_' . substr( hash( 'sha256', strtolower( $host ) ), 0, 8 );
	}

	// -----------------------------------------------------------------------
	// Section: The whole flow.
	//
	// $token is never stored, never logged, and goes out of scope with this
	// request. $retention is either the string "indefinite" or a positive
	// number of days.
	//
	// Returns array{endpoint:string, viewer_key:string, warning:?string} or
	// WP_Error.
	// -----------------------------------------------------------------------
	public function provision( string $token, $retention = 'indefinite', string $account_id = '' ) {
		$token = trim( $token );
		if ( '' === $token ) {
			return new \WP_Error( 'godmode_cf_no_token', 'No Cloudflare token was supplied.' );
		}

		// 1. Which account. A scoped token usually sees exactly one.
		if ( '' === $account_id ) {
			$account_id = $this->discover_account( $token );
			if ( is_wp_error( $account_id ) ) {
				return $account_id;
			}
		}
		$this->note( 'Using Cloudflare account ' . $account_id . '.' );

		// 1b. If this account already runs the endpoint, its code must be the
		//     version this plugin expects BEFORE anything is created. Adding a
		//     binding does not change deployed code, so an older endpoint would
		//     be handed a key it cannot look up and this site would fail closed
		//     on every action afterwards. Checked here, ahead of the bucket and
		//     the registry, so that a refusal leaves the account untouched.
		$stale = $this->endpoint_too_old( $token, $account_id );
		if ( is_wp_error( $stale ) ) {
			return $stale;
		}

		$host    = self::site_host();
		$slug    = self::site_slug( $host );
		$bucket  = self::bucket_name( $host );
		$binding = self::binding_name( $host );

		// 2. This site's own bucket. Existing is fine: re-provisioning the same
		//    site must reuse its records rather than orphan them.
		$made = $this->api( $token, 'POST', "/accounts/{$account_id}/r2/buckets", array( 'name' => $bucket ) );
		if ( is_wp_error( $made ) ) {
			$existing = $this->api( $token, 'GET', "/accounts/{$account_id}/r2/buckets/{$bucket}" );
			if ( is_wp_error( $existing ) ) {
				return new \WP_Error( 'godmode_cf_bucket', 'Could not create or find the R2 bucket for this site: ' . $made->get_error_message() );
			}
			$this->note( 'R2 bucket ' . $bucket . ' already existed and was reused.' );
		} else {
			$this->note( 'Created R2 bucket ' . $bucket . ' for ' . $slug . '.' );
		}

		// 3. The lock, on this bucket, covering the records prefix. Scoping it
		//    to the prefix keeps records immutable while leaving the bucket
		//    itself manageable by its owner.
		$lock = $this->apply_lock( $token, $account_id, $bucket, $retention );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		// 4. The key registry.
		$namespace_id = $this->ensure_kv_namespace( $token, $account_id );
		if ( is_wp_error( $namespace_id ) ) {
			return $namespace_id;
		}

		// 5. The Worker, and this site's binding on it. Adding a binding never
		//    disturbs the bindings already there.
		$ready = $this->ensure_worker( $token, $account_id, $namespace_id, $binding, $bucket );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		// 6. Publish it and work out the URL.
		$endpoint = $this->endpoint_url( $token, $account_id );
		if ( is_wp_error( $endpoint ) ) {
			return $endpoint;
		}
		$this->note( 'Published at ' . $endpoint . '.' );

		// 7. Mint this site's keys and register them. The registry stores only
		//    hashes, so nothing that can act is written to Cloudflare's KV.
		$ingest = $this->mint_key();
		$viewer = $this->mint_key();

		$registered = $this->register_site(
			$token,
			$account_id,
			$namespace_id,
			$slug,
			array(
				'binding'  => $binding,
				'bucket'   => $bucket,
				'prefix'   => self::RECORD_PREFIX,
				'created'  => gmdate( 'c' ),
				'rotated'  => gmdate( 'c' ),
				'archives' => $this->archives_for( $slug ),
			),
			$ingest,
			$viewer,
			(string) $this->settings->get( 'cf_ingest_key', '' )
		);
		if ( is_wp_error( $registered ) ) {
			return $registered;
		}
		$this->note( 'Registered ' . $slug . ' with its own ingest key and viewer key.' );

		// 8. Store first, verify second. All of this now exists in the
		//    operator's Cloudflare account whether or not the first request
		//    happens to get through, and a site holding no key at all is worse
		//    off than one holding a key that is still propagating.
		$this->settings->set( 'cf_endpoint', $endpoint );
		$this->settings->set( 'cf_ingest_key', $ingest );
		$this->settings->set( 'cf_account_id', $account_id );
		$this->settings->set( 'cf_retention', $retention );
		$this->settings->set( 'cf_site_slug', $slug );
		$this->settings->set( 'cf_bucket', $bucket );
		$this->settings->set( 'cf_binding', $binding );
		$this->settings->set( 'cf_kv_namespace', $namespace_id );
		$this->settings->set( 'cf_provisioned_utc', gmdate( 'c' ) );

		$warning = null;
		$live    = $this->wait_until_live( $endpoint, $ingest, true );
		if ( is_wp_error( $live ) ) {
			$warning = $live->get_error_message();
			$this->note( 'Could not confirm it yet: ' . $warning );
		} else {
			( new Health( $this->settings ) )->record_success();
			$this->note( 'Confirmed the endpoint answers and accepts this site\'s ingest key.' );
		}

		// The token is not stored. It simply goes out of scope here.
		unset( $token );
		$this->note( 'Discarded the Cloudflare token. It was never written to the database.' );

		return array(
			'endpoint'   => $endpoint,
			'viewer_key' => $viewer,
			'warning'    => $warning,
		);
	}

	// -----------------------------------------------------------------------
	// Section: Key rotation.
	//
	// Rotation now touches one site: this one. It writes two new registry rows
	// and removes the two old ones. No other site's keys are read, written or
	// invalidated, and no Worker code is uploaded, because an upload is a
	// change to every site at once and rotation is not.
	// -----------------------------------------------------------------------
	public function rotate_keys( string $token, string $account_id = '' ) {
		$token = trim( $token );
		if ( '' === $token ) {
			return new \WP_Error( 'godmode_cf_no_token', 'No Cloudflare token was supplied.' );
		}
		if ( '' === $account_id ) {
			$account_id = (string) $this->settings->get( 'cf_account_id', '' );
		}
		if ( '' === $account_id ) {
			$account_id = $this->discover_account( $token );
			if ( is_wp_error( $account_id ) ) {
				return $account_id;
			}
		}

		$host    = self::site_host();
		$slug    = (string) $this->settings->get( 'cf_site_slug', self::site_slug( $host ) );
		$binding = (string) $this->settings->get( 'cf_binding', self::binding_name( $host ) );
		$bucket  = (string) $this->settings->get( 'cf_bucket', self::bucket_name( $host ) );

		$namespace_id = (string) $this->settings->get( 'cf_kv_namespace', '' );
		if ( '' === $namespace_id ) {
			$namespace_id = $this->ensure_kv_namespace( $token, $account_id );
			if ( is_wp_error( $namespace_id ) ) {
				return $namespace_id;
			}
		}

		$ingest = $this->mint_key();
		$viewer = $this->mint_key();

		$registered = $this->register_site(
			$token,
			$account_id,
			$namespace_id,
			$slug,
			array(
				'binding'  => $binding,
				'bucket'   => $bucket,
				'prefix'   => self::RECORD_PREFIX,
				'created'  => (string) $this->settings->get( 'cf_provisioned_utc', gmdate( 'c' ) ),
				'rotated'  => gmdate( 'c' ),
				'archives' => $this->archives_for( $slug ),
			),
			$ingest,
			$viewer,
			(string) $this->settings->get( 'cf_ingest_key', '' )
		);
		if ( is_wp_error( $registered ) ) {
			return $registered;
		}

		// Store the new key BEFORE verifying it.
		//
		// The moment the registry accepted the new hash and dropped the old
		// one, the old key stopped working. There is nothing to roll back to,
		// so a site that declines to adopt the new key is not being cautious,
		// it is guaranteeing its own breakage.
		$endpoint = (string) $this->settings->get( 'cf_endpoint', '' );
		$this->settings->set( 'cf_ingest_key', $ingest );
		$this->settings->set( 'cf_site_slug', $slug );
		$this->settings->set( 'cf_kv_namespace', $namespace_id );
		$this->settings->set( 'cf_rotated_utc', gmdate( 'c' ) );
		$this->note( 'Stored the new ingest key for ' . $slug . '. The previous pair stopped working the moment the new one was registered. No other site was touched.' );

		$warning = null;
		if ( '' !== $endpoint ) {
			$live = $this->wait_until_live( $endpoint, $ingest, false );
			if ( is_wp_error( $live ) ) {
				// Advisory, not fatal. The key is correct as far as Cloudflare
				// is concerned; KV may simply still be propagating.
				$warning = $live->get_error_message();
				$this->note( 'Could not confirm the new key yet: ' . $warning );
			} else {
				( new Health( $this->settings ) )->record_success();
				$this->note( 'Confirmed the endpoint accepts the new ingest key.' );
			}
		}

		return array(
			'endpoint'   => $endpoint,
			'viewer_key' => $viewer,
			'warning'    => $warning,
		);
	}

	// -----------------------------------------------------------------------
	// Section: Archives.
	//
	// An archive is a bucket this site used to log into, kept readable through
	// this site's CURRENT viewer key so that replacing a log does not mean
	// losing the one it replaced. Nothing ever writes to an archive.
	//
	// Declaring one is not enough on its own: the Worker needs a binding to
	// that bucket, and the site's registry row has to name it. This does both,
	// without touching either key, because changing what a site can read back
	// is not a reason to invalidate the key it reads with.
	// -----------------------------------------------------------------------
	public function apply_archives( string $token, string $account_id = '' ) {
		$token = trim( $token );
		if ( '' === $token ) {
			return new \WP_Error( 'godmode_cf_no_token', 'No Cloudflare token was supplied.' );
		}
		if ( '' === $account_id ) {
			$account_id = (string) $this->settings->get( 'cf_account_id', '' );
		}
		if ( '' === $account_id ) {
			$account_id = $this->discover_account( $token );
			if ( is_wp_error( $account_id ) ) {
				return $account_id;
			}
		}

		$slug = (string) $this->settings->get( 'cf_site_slug', '' );
		if ( '' === $slug ) {
			return new \WP_Error( 'godmode_cf_not_provisioned', 'This site is not connected to an endpoint yet, so there is nothing to attach an archive to. Run setup first.' );
		}

		$namespace_id = (string) $this->settings->get( 'cf_kv_namespace', '' );
		if ( '' === $namespace_id ) {
			return new \WP_Error( 'godmode_cf_not_provisioned', 'This site has no key registry recorded. Run setup again.' );
		}

		$binding = (string) $this->settings->get( 'cf_binding', self::binding_name() );
		$bucket  = (string) $this->settings->get( 'cf_bucket', self::bucket_name() );

		$ready = $this->ensure_worker( $token, $account_id, $namespace_id, $binding, $bucket );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$row = array(
			'binding'  => $binding,
			'bucket'   => $bucket,
			'prefix'   => self::RECORD_PREFIX,
			'created'  => (string) $this->settings->get( 'cf_provisioned_utc', gmdate( 'c' ) ),
			'rotated'  => (string) $this->settings->get( 'cf_rotated_utc', gmdate( 'c' ) ),
			'archives' => $this->archives_for( $slug ),
		);
		$written = $this->kv_put( $token, $account_id, $namespace_id, 'site/' . $slug, wp_json_encode( $row ) );
		if ( is_wp_error( $written ) ) {
			return new \WP_Error( 'godmode_cf_kv', 'Could not update the key registry: ' . $written->get_error_message() );
		}

		$this->note( 'Attached ' . count( $row['archives'] ) . ' archive(s) to ' . $slug . '. Neither key was changed.' );
		return true;
	}

	// -----------------------------------------------------------------------
	// Section: Updating the endpoint software.
	//
	// Separated from rotation on purpose. One Worker serves every site in the
	// account, so replacing its code is an account-wide act and deserves its
	// own button and its own warning, rather than riding along with a
	// single site's key change the way it used to.
	//
	// The upload replaces the binding list, so the list is rebuilt from what is
	// already deployed and handed back unchanged apart from anything missing.
	// Every binding this Worker uses is fully reconstructible from the settings
	// endpoint, which is a direct consequence of holding no secrets any more.
	// -----------------------------------------------------------------------
	public function update_endpoint_software( string $token, string $account_id = '' ) {
		$token = trim( $token );
		if ( '' === $token ) {
			return new \WP_Error( 'godmode_cf_no_token', 'No Cloudflare token was supplied.' );
		}
		if ( '' === $account_id ) {
			$account_id = (string) $this->settings->get( 'cf_account_id', '' );
		}
		if ( '' === $account_id ) {
			$account_id = $this->discover_account( $token );
			if ( is_wp_error( $account_id ) ) {
				return $account_id;
			}
		}

		$source = $this->worker_source();
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$bindings = $this->current_bindings( $token, $account_id );
		if ( is_wp_error( $bindings ) ) {
			return $bindings;
		}
		if ( empty( $bindings ) ) {
			return new \WP_Error( 'godmode_cf_upload', 'There is no endpoint deployed on this account yet, so there is nothing to update. Run setup instead.' );
		}

		$upload = $this->upload_worker( $token, $account_id, $source, $bindings );
		if ( is_wp_error( $upload ) ) {
			return new \WP_Error( 'godmode_cf_upload', 'Could not update the endpoint software: ' . $upload->get_error_message() );
		}
		$this->note( 'Updated the endpoint software, keeping all ' . count( $bindings ) . ' existing bindings.' );
		return true;
	}

	// -----------------------------------------------------------------------
	// Section: The Worker.
	// -----------------------------------------------------------------------

	/**
	 * Make sure the Worker exists and can reach this site's bucket.
	 *
	 * First site in an account: upload the code with the registry binding and
	 * this site's bucket binding. Every site after that: leave the code alone
	 * and add one binding, because uploading code is an account-wide change and
	 * connecting a site is not.
	 */
	private function ensure_worker( string $token, string $account_id, string $namespace_id, string $binding, string $bucket ) {
		$existing = $this->current_bindings( $token, $account_id );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		if ( empty( $existing ) ) {
			$source = $this->worker_source();
			if ( is_wp_error( $source ) ) {
				return $source;
			}
			$bindings = array(
				array( 'type' => 'kv_namespace', 'name' => 'KEYS', 'namespace_id' => $namespace_id ),
				array( 'type' => 'r2_bucket', 'name' => $binding, 'bucket_name' => $bucket ),
			);
			foreach ( $this->archives_for( '' ) as $archive ) {
				if ( '' !== $archive['bucket'] ) {
					$bindings[] = array( 'type' => 'r2_bucket', 'name' => $archive['binding'], 'bucket_name' => $archive['bucket'] );
				}
			}
			$upload = $this->upload_worker( $token, $account_id, $source, $bindings );
			if ( is_wp_error( $upload ) ) {
				return new \WP_Error( 'godmode_cf_upload', 'Could not upload the Worker: ' . $upload->get_error_message() );
			}
			$this->note( 'Uploaded the Worker (' . number_format_i18n( strlen( $source ) ) . ' bytes) with the key registry and this site\'s bucket bound.' );
			return true;
		}

		$bindings = $existing;
		$changed  = false;

		if ( ! $this->has_binding( $bindings, 'KEYS' ) ) {
			$bindings[] = array( 'type' => 'kv_namespace', 'name' => 'KEYS', 'namespace_id' => $namespace_id );
			$changed    = true;
		}
		if ( ! $this->has_binding( $bindings, $binding ) ) {
			$bindings[] = array( 'type' => 'r2_bucket', 'name' => $binding, 'bucket_name' => $bucket );
			$changed    = true;
		}
		foreach ( $this->archives_for( '' ) as $archive ) {
			if ( '' === $archive['bucket'] || $this->has_binding( $bindings, $archive['binding'] ) ) {
				continue;
			}
			$bindings[] = array( 'type' => 'r2_bucket', 'name' => $archive['binding'], 'bucket_name' => $archive['bucket'] );
			$changed    = true;
		}

		if ( ! $changed ) {
			$this->note( 'The endpoint already reaches this site\'s bucket. Nothing about it was changed.' );
			return true;
		}

		$patched = $this->patch_bindings( $token, $account_id, $bindings );
		if ( is_wp_error( $patched ) ) {
			return new \WP_Error( 'godmode_cf_binding', 'Could not attach this site\'s bucket to the endpoint: ' . $patched->get_error_message() );
		}
		$this->note( 'Attached this site\'s bucket to the existing endpoint, leaving ' . ( count( $bindings ) - 1 ) . ' other bindings untouched.' );
		return true;
	}

	/**
	 * Refuse to connect this site to an endpoint older than this plugin.
	 *
	 * Returns a WP_Error when the account already runs an endpoint whose
	 * version does not match, and true otherwise, including when no endpoint
	 * exists yet, because then setup will deploy the current one itself.
	 */
	private function endpoint_too_old( string $token, string $account_id ) {
		$existing = $this->current_bindings( $token, $account_id );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( empty( $existing ) ) {
			return true;
		}

		$deployed = $this->deployed_worker_version( $token, $account_id );
		if ( $deployed === self::WORKER_VERSION ) {
			return true;
		}

		return new \WP_Error(
			'godmode_cf_stale_endpoint',
			sprintf(
				'This Cloudflare account already runs the audit endpoint on %s, and this plugin needs %s. '
				. 'Connecting this site now would hand it a key that the older endpoint cannot recognise, and this '
				. 'site would then refuse every action. Nothing has been created or changed: not on this site, not '
				. 'in Cloudflare. Go to any site already connected to this account, use "Update endpoint software" '
				. 'there, then run setup here again. Updating the endpoint changes it for every site in the account '
				. 'at once, which is why it is a separate and deliberate step.',
				'' === $deployed ? 'a version older than 0.5.1' : 'version ' . $deployed,
				self::WORKER_VERSION
			)
		);
	}

	/**
	 * The version of the endpoint software currently deployed, or '' if the
	 * script carries no version marker at all, which means it predates 0.5.1.
	 *
	 * Read from the deployed source rather than from /health, because at this
	 * point in setup the endpoint URL is not yet known and, on a first run for
	 * this site, the site holds no key to ask with.
	 */
	private function deployed_worker_version( string $token, string $account_id ): string {
		$body = $this->api_raw( $token, 'GET', "/accounts/{$account_id}/workers/scripts/" . self::SCRIPT );
		if ( is_wp_error( $body ) || ! is_string( $body ) ) {
			return '';
		}
		if ( preg_match( '/WORKER_VERSION\s*=\s*[\'"]([0-9]+\.[0-9]+\.[0-9]+)[\'"]/', $body, $m ) ) {
			return $m[1];
		}
		return '';
	}

	private function has_binding( array $bindings, string $name ): bool {
		foreach ( $bindings as $binding ) {
			if ( isset( $binding['name'] ) && $binding['name'] === $name ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The Worker's current bindings, in the shape the API wants them back.
	 * An empty array means the Worker does not exist yet.
	 */
	private function current_bindings( string $token, string $account_id ) {
		$settings = $this->api( $token, 'GET', "/accounts/{$account_id}/workers/scripts/" . self::SCRIPT . '/settings' );
		if ( is_wp_error( $settings ) ) {
			// A missing script is not an error here, it is the first run.
			if ( false !== strpos( $settings->get_error_message(), '10007' ) || false !== strpos( strtolower( $settings->get_error_message() ), 'not found' ) ) {
				return array();
			}
			return $settings;
		}
		$out = array();
		foreach ( (array) ( $settings['bindings'] ?? array() ) as $binding ) {
			if ( ! isset( $binding['type'], $binding['name'] ) ) {
				continue;
			}
			if ( 'r2_bucket' === $binding['type'] && isset( $binding['bucket_name'] ) ) {
				$out[] = array( 'type' => 'r2_bucket', 'name' => $binding['name'], 'bucket_name' => $binding['bucket_name'] );
			} elseif ( 'kv_namespace' === $binding['type'] ) {
				$out[] = array(
					'type'         => 'kv_namespace',
					'name'         => $binding['name'],
					'namespace_id' => (string) ( $binding['namespace_id'] ?? '' ),
				);
			} else {
				// Anything else is preserved by name rather than by value.
				$out[] = array( 'type' => 'inherit', 'name' => $binding['name'] );
			}
		}
		return $out;
	}

	/** Add or change bindings without uploading code. Multipart, per the API. */
	private function patch_bindings( string $token, string $account_id, array $bindings ) {
		$boundary = '----AIGodmode' . bin2hex( random_bytes( 8 ) );
		$eol      = "\r\n";
		$body     = '--' . $boundary . $eol
			. 'Content-Disposition: form-data; name="settings"' . $eol
			. 'Content-Type: application/json' . $eol . $eol
			. wp_json_encode( array( 'bindings' => array_values( $bindings ) ) ) . $eol
			. '--' . $boundary . '--' . $eol;

		$response = wp_remote_request(
			self::API . "/accounts/{$account_id}/workers/scripts/" . self::SCRIPT . '/settings',
			array(
				'method'  => 'PATCH',
				'timeout' => self::HTTP_TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $body,
			)
		);
		return $this->unwrap( $response, 'attaching a bucket to the Worker' );
	}

	/**
	 * Worker module upload. Multipart, with the metadata part naming the main
	 * module and declaring every binding the Worker is to keep.
	 */
	private function upload_worker( string $token, string $account_id, string $source, array $bindings ) {
		$metadata = array(
			'main_module'        => 'audit-sink.js',
			'compatibility_date' => self::COMPAT_DATE,
			'bindings'           => array_values( $bindings ),
		);

		$boundary = '----AIGodmode' . bin2hex( random_bytes( 8 ) );
		$eol      = "\r\n";
		$body     = '--' . $boundary . $eol
			. 'Content-Disposition: form-data; name="metadata"' . $eol
			. 'Content-Type: application/json' . $eol . $eol
			. wp_json_encode( $metadata ) . $eol
			. '--' . $boundary . $eol
			. 'Content-Disposition: form-data; name="audit-sink.js"; filename="audit-sink.js"' . $eol
			. 'Content-Type: application/javascript+module' . $eol . $eol
			. $source . $eol
			. '--' . $boundary . '--' . $eol;

		$response = wp_remote_request(
			self::API . "/accounts/{$account_id}/workers/scripts/" . self::SCRIPT,
			array(
				'method'  => 'PUT',
				'timeout' => self::HTTP_TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $body,
			)
		);
		return $this->unwrap( $response, 'uploading the Worker' );
	}

	private function endpoint_url( string $token, string $account_id ) {
		$sub = $this->api(
			$token,
			'POST',
			"/accounts/{$account_id}/workers/scripts/" . self::SCRIPT . '/subdomain',
			array( 'enabled' => true, 'previews_enabled' => false )
		);
		if ( is_wp_error( $sub ) ) {
			return new \WP_Error( 'godmode_cf_subdomain', 'Could not publish the Worker: ' . $sub->get_error_message() );
		}
		$acct_sub = $this->api( $token, 'GET', "/accounts/{$account_id}/workers/subdomain" );
		if ( is_wp_error( $acct_sub ) || empty( $acct_sub['subdomain'] ) ) {
			return new \WP_Error( 'godmode_cf_subdomain', 'The Worker was uploaded but its workers.dev address could not be read. Add a workers.dev subdomain in the Cloudflare dashboard and provision again.' );
		}
		return 'https://' . self::SCRIPT . '.' . $acct_sub['subdomain'] . '.workers.dev';
	}

	// -----------------------------------------------------------------------
	// Section: The key registry.
	// -----------------------------------------------------------------------

	/** Find the registry namespace by title, or make it. */
	private function ensure_kv_namespace( string $token, string $account_id ) {
		$stored = (string) $this->settings->get( 'cf_kv_namespace', '' );
		if ( '' !== $stored ) {
			return $stored;
		}

		$page = 1;
		do {
			$list = $this->api( $token, 'GET', "/accounts/{$account_id}/storage/kv/namespaces?per_page=100&page={$page}" );
			if ( is_wp_error( $list ) ) {
				return new \WP_Error( 'godmode_cf_kv', 'Could not read the Workers KV namespaces. The token needs Workers KV Storage Edit: ' . $list->get_error_message() );
			}
			foreach ( (array) $list as $namespace ) {
				if ( isset( $namespace['title'], $namespace['id'] ) && self::KV_TITLE === $namespace['title'] ) {
					$this->note( 'Found the existing key registry.' );
					return (string) $namespace['id'];
				}
			}
			$page++;
		} while ( is_array( $list ) && count( $list ) >= 100 && $page <= 20 );

		$made = $this->api( $token, 'POST', "/accounts/{$account_id}/storage/kv/namespaces", array( 'title' => self::KV_TITLE ) );
		if ( is_wp_error( $made ) || empty( $made['id'] ) ) {
			$detail = is_wp_error( $made ) ? $made->get_error_message() : 'no namespace id was returned';
			return new \WP_Error( 'godmode_cf_kv', 'Could not create the key registry. The token needs Workers KV Storage Edit: ' . $detail );
		}
		$this->note( 'Created the key registry.' );
		return (string) $made['id'];
	}

	/**
	 * Write this site's registry rows, then drop the rows the old keys used.
	 *
	 * Order matters. The new rows go in first, so a failure half way leaves the
	 * site with two working ingest keys rather than none. Removing the old one
	 * last means the worst case is a key that outlives its replacement by a few
	 * seconds, which is recoverable, instead of a site that can no longer write
	 * to its own log, which is not.
	 */
	private function register_site( string $token, string $account_id, string $namespace_id, string $slug, array $record, string $ingest, string $viewer, string $old_ingest ) {
		$writes = array(
			'site/' . $slug          => wp_json_encode( $record ),
			'k/i/' . hash( 'sha256', $ingest ) => $slug,
			'k/v/' . hash( 'sha256', $viewer ) => $slug,
		);
		foreach ( $writes as $key => $value ) {
			$written = $this->kv_put( $token, $account_id, $namespace_id, (string) $key, (string) $value );
			if ( is_wp_error( $written ) ) {
				return new \WP_Error( 'godmode_cf_kv', 'Could not write the key registry: ' . $written->get_error_message() );
			}
		}

		if ( '' !== $old_ingest ) {
			// Best effort. A stale row that maps a retired key to this site is
			// untidy, not dangerous, and it must never turn a successful
			// rotation into a reported failure.
			$this->kv_delete( $token, $account_id, $namespace_id, 'k/i/' . hash( 'sha256', $old_ingest ) );
		}

		return true;
	}

	/**
	 * Any archives this site should keep readable through its viewer key.
	 *
	 * Stored as a plain setting so a migration can fill it once and the value
	 * survives every later rotation. Nothing writes to an archive, ever.
	 */
	private function archives_for( string $slug ): array {
		$archives = $this->settings->get( 'cf_archives', array() );
		if ( ! is_array( $archives ) ) {
			return array();
		}
		$clean = array();
		foreach ( $archives as $archive ) {
			if ( ! is_array( $archive ) || empty( $archive['binding'] ) ) {
				continue;
			}
			$clean[] = array(
				'binding' => (string) $archive['binding'],
				'bucket'  => (string) ( $archive['bucket'] ?? '' ),
				'prefix'  => (string) ( $archive['prefix'] ?? '' ),
				'label'   => (string) ( $archive['label'] ?? 'Archive' ),
			);
		}
		return $clean;
	}

	private function kv_put( string $token, string $account_id, string $namespace_id, string $key, string $value ) {
		$boundary = '----AIGodmode' . bin2hex( random_bytes( 8 ) );
		$eol      = "\r\n";
		$body     = '--' . $boundary . $eol
			. 'Content-Disposition: form-data; name="value"' . $eol . $eol
			. $value . $eol
			. '--' . $boundary . $eol
			. 'Content-Disposition: form-data; name="metadata"' . $eol . $eol
			. '{}' . $eol
			. '--' . $boundary . '--' . $eol;

		$response = wp_remote_request(
			self::API . "/accounts/{$account_id}/storage/kv/namespaces/{$namespace_id}/values/" . rawurlencode( $key ),
			array(
				'method'  => 'PUT',
				'timeout' => self::HTTP_TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $body,
			)
		);
		return $this->unwrap( $response, 'writing the key registry' );
	}

	private function kv_delete( string $token, string $account_id, string $namespace_id, string $key ) {
		return $this->api(
			$token,
			'DELETE',
			"/accounts/{$account_id}/storage/kv/namespaces/{$namespace_id}/values/" . rawurlencode( $key )
		);
	}

	// -----------------------------------------------------------------------
	// Section: Pieces.
	// -----------------------------------------------------------------------

	/** 64 hex characters from the CSPRNG. */
	private function mint_key(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * The Worker source that provisioning uploads to Cloudflare.
	 *
	 * This ships inside the plugin at worker/audit-sink.js and is read at run
	 * time, which makes it a runtime dependency rather than documentation. One
	 * release was packaged without it. The plugin installed, activated and ran
	 * normally, and only failed at the moment somebody tried to build an
	 * off-site log, which is the one feature that cannot be tested without a
	 * Cloudflare account. The error below therefore names the cause and the fix
	 * rather than just the missing path, and tools/package.sh now refuses to
	 * build a zip that omits it.
	 */
	private function worker_source() {
		$path = GODMODE_DIR . 'worker/audit-sink.js';
		if ( ! is_readable( $path ) ) {
			return new \WP_Error(
				'godmode_cf_source',
				'This copy of the plugin is incomplete: it was packaged without worker/audit-sink.js, '
				. 'which is the Worker source this setup uploads to Cloudflare. Nothing is wrong with '
				. 'your Cloudflare account or your token. Install a complete copy of the plugin '
				. 'and run setup again.'
			);
		}
		$source = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $source || '' === $source ) {
			return new \WP_Error(
				'godmode_cf_source',
				'The bundled Worker source at worker/audit-sink.js is present but unreadable. Check '
				. 'file permissions on the plugin directory, then run setup again.'
			);
		}
		return $source;
	}

	private function discover_account( string $token ) {
		$accounts = $this->api( $token, 'GET', '/accounts' );
		if ( is_wp_error( $accounts ) ) {
			return new \WP_Error( 'godmode_cf_token', 'Cloudflare rejected the token, or it cannot read account settings: ' . $accounts->get_error_message() );
		}
		if ( empty( $accounts ) || ! isset( $accounts[0]['id'] ) ) {
			return new \WP_Error( 'godmode_cf_token', 'The token is valid but can see no Cloudflare accounts. It needs Account Settings Read.' );
		}
		if ( count( $accounts ) > 1 ) {
			$this->note( 'The token can see ' . count( $accounts ) . ' accounts; using the first. Supply an account ID to choose a different one.' );
		}
		return (string) $accounts[0]['id'];
	}

	/**
	 * The lock covers the records prefix rather than the whole bucket.
	 *
	 * Records are what must be immutable. Locking everything would also freeze
	 * the bucket's own housekeeping and make an empty, abandoned bucket
	 * impossible to tidy up, which buys nothing: an attacker who cannot delete
	 * records gains nothing from deleting the bucket's other objects, because
	 * there are none.
	 */
	private function apply_lock( string $token, string $account_id, string $bucket, $retention ) {
		if ( 'indefinite' === $retention ) {
			$condition = array( 'type' => 'Indefinite' );
			$label     = 'indefinitely';
		} else {
			$days      = max( 1, (int) $retention );
			$condition = array( 'type' => 'Age', 'maxAgeSeconds' => $days * DAY_IN_SECONDS );
			$label     = 'for ' . $days . ' days';
		}

		$lock = $this->api(
			$token,
			'PUT',
			"/accounts/{$account_id}/r2/buckets/{$bucket}/lock",
			array(
				'rules' => array(
					array(
						'id'        => 'godmode-audit-records',
						'enabled'   => true,
						'prefix'    => self::RECORD_PREFIX,
						'condition' => $condition,
					),
				),
			)
		);
		if ( is_wp_error( $lock ) ) {
			return new \WP_Error( 'godmode_cf_lock', 'The bucket exists but its lock could not be applied, so records would be deletable: ' . $lock->get_error_message() );
		}
		$this->note( 'Applied a bucket lock retaining every record ' . $label . '.' );
		return true;
	}

	/**
	 * Poll /health, then prove the minted ingest key is really installed by
	 * calling /ping, which validates the key and writes nothing.
	 */
	private function wait_until_live( string $endpoint, string $ingest, bool $new_route ) {
		// Kept deliberately short. This runs inside one admin request, and an
		// earlier version could spend nearly three minutes here, long enough to
		// hit PHP's execution limit and kill the request part way through.
		if ( $new_route ) {
			$health = false;
			for ( $i = 0; $i < 6; $i++ ) {
				$r = wp_remote_get( trailingslashit( $endpoint ) . 'health', array( 'timeout' => 6 ) );
				if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) {
					$health = true;
					break;
				}
				sleep( 2 );
			}
			if ( ! $health ) {
				return new \WP_Error( 'godmode_cf_unreachable', 'The endpoint did not answer yet. Cloudflare can take a minute to publish a new address.' );
			}
		}

		for ( $i = 0; $i < 5; $i++ ) {
			$r = wp_remote_post(
				trailingslashit( $endpoint ) . 'ping',
				array(
					'timeout' => 6,
					'headers' => array( 'Authorization' => 'Bearer ' . $ingest ),
				)
			);
			if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) {
				return true;
			}
			sleep( 2 );
		}
		return new \WP_Error( 'godmode_cf_key', 'The endpoint has not accepted the new key yet. The key registry takes a few seconds to spread across Cloudflare, so this usually clears by itself.' );
	}

	// -----------------------------------------------------------------------
	// Section: Cloudflare API helper. Returns the result payload or WP_Error
	// carrying Cloudflare's own message, which is usually the useful one.
	// -----------------------------------------------------------------------
	private function api( string $token, string $method, string $path, $body = null ) {
		$args = array(
			'method'  => $method,
			'timeout' => self::HTTP_TIMEOUT,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		return $this->unwrap( wp_remote_request( self::API . $path, $args ), $method . ' ' . $path );
	}

	/**
	 * Like api(), but returns the response body verbatim.
	 *
	 * Fetching a Worker's script returns JavaScript, not the usual JSON
	 * envelope, so unwrap() would reject it as unreadable.
	 */
	private function api_raw( string $token, string $method, string $path ) {
		$response = wp_remote_request(
			self::API . $path,
			array(
				'method'  => $method,
				'timeout' => self::HTTP_TIMEOUT,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'godmode_cf_http', 'Could not reach Cloudflare while ' . $method . ' ' . $path . ': ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'godmode_cf_api', 'Cloudflare answered HTTP ' . $code . ' while ' . $method . ' ' . $path . '.' );
		}
		return (string) wp_remote_retrieve_body( $response );
	}

	private function unwrap( $response, string $what ) {
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'godmode_cf_http', 'Could not reach Cloudflare while ' . $what . ': ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'godmode_cf_api', 'Cloudflare returned an unreadable response (HTTP ' . $code . ') while ' . $what . '.' );
		}
		if ( empty( $data['success'] ) ) {
			$messages = array();
			foreach ( (array) ( $data['errors'] ?? array() ) as $error ) {
				$messages[] = trim( ( isset( $error['code'] ) ? $error['code'] . ': ' : '' ) . ( $error['message'] ?? '' ) );
			}
			$detail = $messages ? implode( '; ', $messages ) : 'HTTP ' . $code;
			return new \WP_Error( 'godmode_cf_api', $detail );
		}
		return $data['result'] ?? true;
	}
}
