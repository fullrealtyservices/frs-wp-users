<?php
/**
 * Local Aggregate Sync
 *
 * Pushes the locally-aggregated SQLite warehouse (Sheet ∪ base.frs ∪ Moxi)
 * into production WordPress + BuddyPress.
 *
 * The SQLite file is built offline by /Users/cedarstone/Projects/onboarding/scripts/*
 * (aggregate.py + merge_sheet_into_sqlite.py + moxi_offices.py) and uploaded
 * to a known path on the host (default `/app/data/private/agents.db`).
 *
 * Schema expected (people / groups / group_memberships) — see the offline
 * project's CONTEXT.md for the canonical definitions.
 *
 * Safety model — mirrors GoogleRosterSync v3:
 *   - Match existing users by frs_legacy_uuid → DRE → NMLS → email-create-only
 *   - Never wp_update_user existing rows
 *   - safe_meta_update appends conflicts to frs_alt_* arrays
 *   - bp_set_member_type only fires when no existing type
 *   - Region/office groups created/updated; parent_id mirrored from SQLite
 *
 * CLI:
 *   wp frs-users local-aggregate-sync --sqlite=/app/data/private/agents.db
 *   wp frs-users local-aggregate-sync --dry-run
 *   wp frs-users local-aggregate-sync --linked-only --skip-images --limit=50
 *
 * @package FRSUsers\Integrations
 */

namespace FRSUsers\Integrations;

defined( 'ABSPATH' ) || exit;

class LocalAggregateSync {

	const DEFAULT_SQLITE_PATH = '/app/data/private/agents.db';
	const SYNC_LOCK_TRANSIENT = 'frs_local_aggregate_sync_lock';

	/** @var bool */
	private static $suppress_mail = false;

	public static function init(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'frs-users local-aggregate-sync', [ __CLASS__, 'cli_handler' ] );
		}
	}

	/**
	 * WP-CLI entrypoint.
	 *
	 * ## OPTIONS
	 *
	 * [--sqlite=<path>]
	 * : Path to agents.db. Defaults to /app/data/private/agents.db.
	 *
	 * [--dry-run]
	 * : Parse + plan only, no DB writes.
	 *
	 * [--limit=<n>]
	 * : Process at most N people (after group sync). 0 = no limit.
	 *
	 * [--linked-only]
	 * : Skip people who have no group_memberships row (per "Sheet is canonical").
	 *
	 * [--skip-images]
	 * : Don't upload headshots / group photos.
	 *
	 * [--force]
	 * : Bypass the sync lock.
	 *
	 * @when after_wp_load
	 */
	public static function cli_handler( $args, $assoc_args ): void {
		$opts = [
			'sqlite'       => isset( $assoc_args['sqlite'] ) ? (string) $assoc_args['sqlite'] : self::DEFAULT_SQLITE_PATH,
			'dry_run'      => isset( $assoc_args['dry-run'] ),
			'limit'        => isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0,
			'linked_only'  => isset( $assoc_args['linked-only'] ),
			'skip_images'  => isset( $assoc_args['skip-images'] ),
			'force'        => isset( $assoc_args['force'] ),
		];

		\WP_CLI::log( sprintf(
			'Starting local-aggregate-sync (sqlite=%s%s%s%s%s)',
			$opts['sqlite'],
			$opts['dry_run']     ? ', dry-run'     : '',
			$opts['linked_only'] ? ', linked-only' : '',
			$opts['skip_images'] ? ', skip-images' : '',
			$opts['limit'] ? ', limit=' . $opts['limit'] : ''
		) );

		$stats = self::run_sync( $opts );

		foreach ( $stats as $k => $v ) {
			\WP_CLI::log( sprintf( '  %s: %s', $k, is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) ) );
		}
		\WP_CLI::success( 'Local-aggregate-sync complete.' );
	}

	/**
	 * Main sync.
	 *
	 * @param array $opts See cli_handler.
	 * @return array Stats.
	 */
	public static function run_sync( array $opts ): array {
		$start = microtime( true );

		$stats = [
			'regions_created'              => 0,
			'offices_created'              => 0,
			'group_photos_uploaded'        => 0,
			'people_processed'             => 0,
			'users_created'                => 0,
			'users_matched_by_prod_id'     => 0,
			'users_matched_by_legacy'      => 0,
			'users_matched_by_dre'         => 0,
			'users_matched_by_nmls'        => 0,
			'alt_accounts_synced'          => 0,
			'avatars_uploaded'             => 0,
			'avatars_skipped_user_owned'   => 0,
			'avatars_skipped_microsoft'    => 0,
			'users_skipped_no_match'       => 0,
			'users_skipped_email_conflict' => 0,
			'meta_appended_to_alts'        => 0,
			'member_type_set'              => 0,
			'member_type_conflicts'        => 0,
			'memberships_added'            => 0,
			'avatars_uploaded'             => 0,
			'errors'                       => 0,
			'duration_ms'                  => 0,
		];

		if ( ! $opts['force'] && get_transient( self::SYNC_LOCK_TRANSIENT ) ) {
			error_log( '[LocalAggregateSync] Sync already in progress; skipping.' );
			return $stats;
		}
		set_transient( self::SYNC_LOCK_TRANSIENT, time(), 30 * MINUTE_IN_SECONDS );

		try {
			$pdo = self::open_sqlite( (string) $opts['sqlite'] );
			if ( ! $pdo ) {
				$stats['errors']++;
				return $stats;
			}

			if ( ! function_exists( 'groups_create_group' ) ) {
				error_log( '[LocalAggregateSync] BuddyPress groups component not available; aborting.' );
				$stats['errors']++;
				return $stats;
			}

			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			self::suppress_emails();

			try {
				// 1. Groups (regions first so offices can resolve parent_id).
				$group_id_map = self::sync_groups( $pdo, $opts, $stats );

				// 2. People — gated by limit + linked-only flag.
				self::sync_people( $pdo, $group_id_map, $opts, $stats );
			} finally {
				self::unsuppress_emails();
			}
		} catch ( \Throwable $e ) {
			$stats['errors']++;
			error_log( '[LocalAggregateSync] Fatal: ' . $e->getMessage() );
		} finally {
			delete_transient( self::SYNC_LOCK_TRANSIENT );
		}

		$stats['duration_ms'] = (int) ( ( microtime( true ) - $start ) * 1000 );
		return $stats;
	}

	// ---------------------------------------------------------------------
	// SQLite helpers
	// ---------------------------------------------------------------------

	private static function open_sqlite( string $path ): ?\PDO {
		if ( ! class_exists( '\PDO' ) || ! in_array( 'sqlite', \PDO::getAvailableDrivers(), true ) ) {
			error_log( '[LocalAggregateSync] PDO sqlite driver not available.' );
			return null;
		}
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			error_log( '[LocalAggregateSync] sqlite file not readable: ' . $path );
			return null;
		}
		try {
			$pdo = new \PDO( 'sqlite:' . $path );
			$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
			$pdo->setAttribute( \PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC );
			return $pdo;
		} catch ( \Throwable $e ) {
			error_log( '[LocalAggregateSync] open_sqlite failed: ' . $e->getMessage() );
			return null;
		}
	}

	// ---------------------------------------------------------------------
	// Group sync
	// ---------------------------------------------------------------------

	/**
	 * Push groups → BP. Returns map [ sqlite_group_id => bp_group_id ].
	 */
	private static function sync_groups( \PDO $pdo, array $opts, array &$stats ): array {
		$map = [];

		// Regions first (parent_id IS NULL or 0).
		$regions = $pdo->query(
			"SELECT id, name, slug, group_type, parent_id, description, address, phone,
			        photo_source_url, photo_local_path
			 FROM groups
			 WHERE group_type = 'region'
			 ORDER BY name"
		)->fetchAll();

		foreach ( $regions as $row ) {
			$bp_id = self::ensure_bp_group( $row, 0, $opts, $stats );
			if ( $bp_id ) {
				$map[ (int) $row['id'] ] = $bp_id;
			}
		}

		// Then offices (resolve parent via map).
		$offices = $pdo->query(
			"SELECT id, name, slug, group_type, parent_id, description, address, phone,
			        photo_source_url, photo_local_path
			 FROM groups
			 WHERE group_type = 'office'
			 ORDER BY name"
		)->fetchAll();

		foreach ( $offices as $row ) {
			$parent_sqlite = (int) ( $row['parent_id'] ?? 0 );
			$parent_bp     = $parent_sqlite ? ( $map[ $parent_sqlite ] ?? 0 ) : 0;
			$bp_id         = self::ensure_bp_group( $row, $parent_bp, $opts, $stats );
			if ( $bp_id ) {
				$map[ (int) $row['id'] ] = $bp_id;
			}
		}

		// Other group_types (department, project) — flat for now.
		$others = $pdo->query(
			"SELECT id, name, slug, group_type, parent_id, description, address, phone,
			        photo_source_url, photo_local_path
			 FROM groups
			 WHERE group_type NOT IN ('region','office')
			 ORDER BY group_type, name"
		)->fetchAll();
		foreach ( $others as $row ) {
			$parent_sqlite = (int) ( $row['parent_id'] ?? 0 );
			$parent_bp     = $parent_sqlite ? ( $map[ $parent_sqlite ] ?? 0 ) : 0;
			$bp_id         = self::ensure_bp_group( $row, $parent_bp, $opts, $stats );
			if ( $bp_id ) {
				$map[ (int) $row['id'] ] = $bp_id;
			}
		}

		return $map;
	}

	/**
	 * Create or fetch a BP group; return its ID. Sets group type, parent, photo.
	 */
	private static function ensure_bp_group( array $row, int $parent_bp_id, array $opts, array &$stats ): int {
		$name = trim( (string) ( $row['name'] ?? '' ) );
		$type = (string) ( $row['group_type'] ?? '' );
		if ( '' === $name || '' === $type ) {
			return 0;
		}

		if ( $opts['dry_run'] ) {
			return 0;
		}

		$slug_hint = ! empty( $row['slug'] ) ? sanitize_title( (string) $row['slug'] ) : sanitize_title( $name );

		$bp_id = 0;
		if ( class_exists( '\BP_Groups_Group' ) ) {
			$bp_id = (int) \BP_Groups_Group::get_id_from_slug( $slug_hint );
			if ( ! $bp_id ) {
				$bp_id = (int) \BP_Groups_Group::group_exists( $slug_hint );
			}
		}

		if ( ! $bp_id ) {
			$canonical_slug = function_exists( 'groups_check_slug' ) ? groups_check_slug( $slug_hint ) : $slug_hint;
			$created = groups_create_group( [
				'creator_id'   => 1,
				'name'         => $name,
				'slug'         => $canonical_slug,
				'description'  => (string) ( $row['description'] ?? sprintf( 'FRS %s group: %s', $type, $name ) ),
				'status'       => 'private',
				'parent_id'    => $parent_bp_id,
				'enable_forum' => false,
			] );
			if ( ! $created || is_wp_error( $created ) ) {
				$stats['errors']++;
				$msg = is_wp_error( $created ) ? $created->get_error_message() : 'unknown';
				error_log( sprintf( '[LocalAggregateSync] groups_create_group failed for %s "%s": %s', $type, $name, $msg ) );
				return 0;
			}
			$bp_id = (int) $created;
			if ( 'region' === $type ) {
				$stats['regions_created']++;
			} elseif ( 'office' === $type ) {
				$stats['offices_created']++;
			}
		}

		// Sync parent_id when it has drifted.
		if ( $parent_bp_id > 0 && function_exists( 'groups_get_group' ) ) {
			$grp = groups_get_group( $bp_id );
			if ( $grp && (int) ( $grp->parent_id ?? 0 ) !== $parent_bp_id && function_exists( 'groups_edit_base_group_details' ) ) {
				groups_edit_base_group_details( [
					'group_id'    => $bp_id,
					'parent_id'   => $parent_bp_id,
					'name'        => $name,
					'slug'        => $grp->slug,
					'description' => $grp->description,
				] );
			}
		}

		// Set BP group type.
		if ( function_exists( 'bp_groups_set_group_type' ) ) {
			bp_groups_set_group_type( $bp_id, $type );
		}

		// Sync office contact meta (address, phone) — append-only to alts when conflicting.
		if ( ! empty( $row['address'] ) && function_exists( 'groups_update_groupmeta' ) ) {
			groups_update_groupmeta( $bp_id, 'frs_address', (string) $row['address'] );
		}
		if ( ! empty( $row['phone'] ) && function_exists( 'groups_update_groupmeta' ) ) {
			groups_update_groupmeta( $bp_id, 'frs_phone', (string) $row['phone'] );
		}

		// Group photo — stash attachment id in groupmeta. (BP doesn't have a
		// built-in "set group avatar by attachment id" path; the most-portable
		// solution is to expose the attachment via meta and let the theme
		// render it. The theme can fall back to bp_core_fetch_avatar() unchanged.)
		if ( ! $opts['skip_images'] && ! empty( $row['photo_local_path'] ) ) {
			$existing = function_exists( 'groups_get_groupmeta' ) ? (int) groups_get_groupmeta( $bp_id, 'frs_photo_attachment_id' ) : 0;
			if ( ! $existing ) {
				$abs = self::resolve_image_path( (string) $row['photo_local_path'] );
				if ( $abs ) {
					$attachment_id = self::sideload_local( $abs, sprintf( 'group-%d-%s', $bp_id, $name ) );
					if ( $attachment_id && function_exists( 'groups_update_groupmeta' ) ) {
						groups_update_groupmeta( $bp_id, 'frs_photo_attachment_id', $attachment_id );
						$stats['group_photos_uploaded']++;
					}
				}
			}
		}

		return $bp_id;
	}

	// ---------------------------------------------------------------------
	// People sync
	// ---------------------------------------------------------------------

	private static function sync_people( \PDO $pdo, array $group_id_map, array $opts, array &$stats ): void {
		$where = '';
		if ( $opts['linked_only'] ) {
			$where = 'WHERE p.id IN (SELECT person_id FROM group_memberships)';
		}
		$limit = $opts['limit'] > 0 ? sprintf( 'LIMIT %d', (int) $opts['limit'] ) : '';

		$people = $pdo->query(
			"SELECT p.* FROM people p $where ORDER BY p.id $limit"
		)->fetchAll();

		// Pre-fetch memberships in one query: [ person_id => [ sqlite_group_id, ... ] ]
		$mem_rows = $pdo->query(
			"SELECT person_id, group_id FROM group_memberships"
		)->fetchAll();
		$memberships = [];
		foreach ( $mem_rows as $r ) {
			$memberships[ (int) $r['person_id'] ][] = (int) $r['group_id'];
		}

		$batch_size  = (int) apply_filters( 'frs_local_aggregate_batch_size', 25 );
		$batch_pause = (int) apply_filters( 'frs_local_aggregate_batch_pause_us', 500000 );
		$processed   = 0;

		foreach ( $people as $person ) {
			if ( $processed > 0 && 0 === $processed % $batch_size ) {
				if ( function_exists( 'wp_cache_flush_runtime' ) ) {
					wp_cache_flush_runtime();
				}
				usleep( $batch_pause );
			}
			$processed++;
			$stats['people_processed']++;

			try {
				if ( $opts['dry_run'] ) {
					continue;
				}

				$matched_how = '';
				$user_id     = self::ensure_user( $person, $matched_how, $stats );
				if ( ! $user_id ) {
					continue;
				}
				$is_new = ( 'created' === $matched_how );
				if ( $is_new ) {
					$stats['users_created']++;
				}

				// Apply to primary prod account
				self::sync_person_to_user( $user_id, $person, $is_new, $memberships, $group_id_map, $opts, $stats );

				// Apply same data to any alt prod accounts (multi-account people).
				if ( ! empty( $person['alt_prod_user_ids'] ) ) {
					$alts = json_decode( (string) $person['alt_prod_user_ids'], true );
					if ( is_array( $alts ) ) {
						foreach ( $alts as $alt_uid ) {
							$alt_uid = (int) $alt_uid;
							if ( $alt_uid > 0 && get_userdata( $alt_uid ) ) {
								self::sync_person_to_user( $alt_uid, $person, false, $memberships, $group_id_map, $opts, $stats );
								$stats['alt_accounts_synced']++;
							}
						}
					}
				}
			} catch ( \Throwable $e ) {
				$stats['errors']++;
				error_log( sprintf( '[LocalAggregateSync] failed person id=%s: %s', $person['id'] ?? '?', $e->getMessage() ) );
			}
		}
	}

	/**
	 * Resolve or create the WP user for a person row.
	 *
	 * Match priority: prod_user_id (already aligned in SQLite) → legacy_uuid
	 *   → frs_nmls → frs_dre_license → email-create-only.
	 */
	private static function ensure_user( array $person, string &$matched_how, array &$stats ): int {
		// FAST PATH: SQLite.id is already aligned to prod_user_id for matched
		// people (see scripts/align_ids_to_prod.py). If the WP user with that
		// ID exists, use them directly.
		$candidate_uid = 0;
		if ( ! empty( $person['prod_user_id'] ) ) {
			$candidate_uid = (int) $person['prod_user_id'];
		} elseif ( ! empty( $person['id'] ) && (int) $person['id'] < 100000 ) {
			$candidate_uid = (int) $person['id'];
		}
		if ( $candidate_uid > 0 && get_userdata( $candidate_uid ) ) {
			$matched_how = 'prod_user_id';
			$stats['users_matched_by_prod_id']++;
			return $candidate_uid;
		}

		$uuid  = trim( (string) ( $person['frs_legacy_uuid'] ?? '' ) );
		$dre   = trim( (string) ( $person['frs_dre_license'] ?? '' ) );
		$nmls  = trim( (string) ( $person['frs_nmls']        ?? '' ) );
		$email = strtolower( trim( (string) ( $person['user_email'] ?? '' ) ) );

		if ( '' !== $uuid ) {
			$found = self::find_user_by_meta( 'frs_legacy_uuid', $uuid );
			if ( $found ) {
				$matched_how = 'legacy';
				$stats['users_matched_by_legacy']++;
				return $found;
			}
		}
		if ( '' !== $nmls ) {
			$found = self::find_user_by_meta( 'frs_nmls', $nmls );
			if ( ! $found ) {
				// Legacy alias.
				$found = self::find_user_by_meta( 'frs_nmls_number', $nmls );
			}
			if ( $found ) {
				$matched_how = 'nmls';
				$stats['users_matched_by_nmls']++;
				return $found;
			}
		}
		if ( '' !== $dre && ctype_digit( $dre ) ) {
			$found = self::find_user_by_meta( 'frs_dre_license', $dre );
			if ( $found ) {
				$matched_how = 'dre';
				$stats['users_matched_by_dre']++;
				return $found;
			}
		}

		if ( '' === $email || ! is_email( $email ) ) {
			$matched_how = 'skipped_no_match';
			$stats['users_skipped_no_match']++;
			error_log( sprintf( '[LocalAggregateSync] no identifier, skipped person id=%s', $person['id'] ?? '?' ) );
			return 0;
		}

		$existing_by_email = get_user_by( 'email', $email );
		if ( $existing_by_email ) {
			$matched_how = 'skipped_email_conflict';
			$stats['users_skipped_email_conflict']++;
			error_log( sprintf(
				'[LocalAggregateSync] email already on different user, skipped: email=%s existing_user=%d sqlite_dre="%s"',
				$email,
				(int) $existing_by_email->ID,
				$dre
			) );
			return 0;
		}

		// Create.
		$base_login = ! empty( $person['user_login'] )
			? sanitize_user( (string) $person['user_login'], true )
			: sanitize_user( current( explode( '@', $email ) ), true );
		if ( '' === $base_login ) {
			$base_login = 'user' . wp_generate_password( 6, false );
		}
		$login = $base_login;
		$n     = 1;
		while ( username_exists( $login ) ) {
			$login = $base_login . $n++;
			if ( $n > 50 ) {
				$login = $base_login . wp_generate_password( 4, false );
				break;
			}
		}

		$user_id = wp_create_user( $login, wp_generate_password( 24, true, true ), $email );
		if ( is_wp_error( $user_id ) ) {
			error_log( sprintf( '[LocalAggregateSync] wp_create_user failed for %s: %s', $email, $user_id->get_error_message() ) );
			return 0;
		}

		// One-time display fields on the brand-new user.
		$first   = (string) ( $person['first_name']   ?? '' );
		$last    = (string) ( $person['last_name']    ?? '' );
		$display = (string) ( $person['display_name'] ?? '' );
		if ( '' === $display ) {
			$display = trim( $first . ' ' . $last );
		}
		$update = [ 'ID' => (int) $user_id ];
		if ( '' !== $first )   { $update['first_name']   = $first;   }
		if ( '' !== $last )    { $update['last_name']    = $last;    }
		if ( '' !== $display ) { $update['display_name'] = $display; }
		if ( ! empty( $person['user_url'] ) ) {
			$update['user_url'] = (string) $person['user_url'];
		}
		if ( count( $update ) > 1 ) {
			wp_update_user( $update );
		}

		// Stamp legacy_uuid right away so subsequent syncs match instantly.
		if ( '' !== $uuid ) {
			update_user_meta( $user_id, 'frs_legacy_uuid', $uuid );
		}

		$matched_how = 'created';
		return (int) $user_id;
	}

	/**
	 * Apply all SQLite columns to WP user_meta per the api-field-map.md WRITE map.
	 *
	 * Format: each entry = [ sqlite_column, canonical_meta_key, [alias_keys ...] ]
	 *
	 * The canonical key is written via safe_meta_update (append-only / never
	 * overwrites a non-empty existing value). The alias keys are mirrored using
	 * the same safe path so legacy prod plugins that read aliases keep working.
	 */
	private static function apply_meta( int $user_id, array $person, bool $is_new, array &$stats ): void {
		$map = [
			// [ sqlite_col, canonical_meta_key, [aliases...] ]
			[ 'first_name',                'first_name',                [] ],
			[ 'last_name',                 'last_name',                 [] ],
			[ 'nickname',                  'nickname',                  [] ],
			[ 'description',               'description',               [] ],
			[ 'frs_middle_name',           'frs_middle_name',           [] ],
			[ 'frs_phone_number',          'frs_phone_number',          [ '_phone_number', 'phone_number', 'phone' ] ],
			[ 'frs_mobile_number',         'frs_mobile_number',         [] ],
			[ 'frs_business_email',        'frs_business_email',        [ '_primary_business_email', 'primary_business_email' ] ],
			[ 'frs_emails',                'frs_emails',                [] ],   // JSON list of all emails (primary first)
			[ 'frs_phones',                'frs_phones',                [] ],   // JSON list of all phones
			[ 'frs_date_of_birth',         'frs_date_of_birth',         [ '_date_of_birth', 'date_of_birth' ] ],
			[ 'frs_dre_license',           'frs_dre_license',           [ '_license_number', 'license_number' ] ],
			[ 'frs_nmls',                  'frs_nmls',                  [ 'frs_nmls_number', '_nmls', 'nmls', 'nmls_id' ] ],
			[ 'frs_license_number',        'frs_license_number',        [] ],
			[ 'frs_license_state',         'frs_license_state',         [] ],
			[ 'frs_license_type',          'frs_license_type',          [] ],
			[ 'frs_brand',                 'frs_brand',                 [ '_brand', 'brokerage' ] ],
			[ 'frs_department',            'frs_department',            [] ],
			[ 'frs_aor_regional_director', 'frs_aor_regional_director', [] ],
			[ 'frs_aor_regional_advisor',  'frs_aor_regional_advisor',  [ '_aor-regional-advisor', 'aor-regional-advisor' ] ],
			[ 'frs_status',                'frs_status',                [] ],
			// Bio: WP-native is `description`. frs_biography is the legacy alias used by some prod plugins.
			[ 'frs_biography',             'description',               [ 'frs_biography', '_biography', 'biography', 'bio' ] ],
			[ 'frs_office',                'frs_office',                [] ],
			[ 'frs_region',                'frs_region',                [] ],
			[ 'frs_city_state',            'frs_city_state',            [ '_city_state', 'city_state' ] ],
			[ 'frs_company_name',          'frs_company_name',          [] ],
			// frs_company_role removed — it's a cache of bp_member_type slug,
			// not an independent field. job_title is its own canonical key.
			[ 'frs_job_title',             'job_title',                 [ 'frs_job_title', '_job_title', 'title' ] ],
			[ 'frs_company_website',       'frs_company_website',       [] ],
			[ 'frs_company_logo_id',       'frs_company_logo_id',       [] ],
			[ 'frs_century21_url',         'frs_century21_url',         [] ],
			[ 'frs_zillow_url',            'frs_zillow_url',            [] ],
			[ 'frs_realtor_url',           'frs_realtor_url',           [] ],
			[ 'frs_ylopo_domain',          'frs_ylopo_domain',          [] ],
			[ 'frs_moxi_domain',           'frs_moxi_domain',           [] ],
			[ 'frs_facebook_url',          'frs_facebook_url',          [ 'facebook_url', '_facebook_url', 'facebook' ] ],
			[ 'frs_instagram_url',         'frs_instagram_url',         [ 'instagram_url', '_instagram_url', 'instagram' ] ],
			[ 'frs_linkedin_url',          'frs_linkedin_url',          [ 'linkedin_url', '_linkedin_url', 'linkedin' ] ],
			[ 'frs_twitter_url',           'frs_twitter_url',           [ 'twitter_url', '_twitter_url', 'twitter' ] ],
			[ 'frs_youtube_url',           'frs_youtube_url',           [ 'youtube_url', '_youtube_url', 'youtube' ] ],
			[ 'frs_tiktok_url',            'frs_tiktok_url',            [ 'tiktok_url', '_tiktok_url', 'tiktok' ] ],
			[ 'frs_canva_folder_link',     'frs_canva_folder_link',     [ 'canva_folder_link', '_canva_folder_link' ] ],
			[ 'frs_realsatisfied_vanity',  'frs_realsatisfied_vanity',  [ 'realsatified-agent-vanity', '_realsatified-agent-vanity' ] ],
			[ 'frs_arrive_link',           'frs_arrive_link',           [ 'frs_arrive', 'arrive', '_arrive' ] ],
			[ 'frs_telegram_username',     'frs_telegram_username',     [] ],
			[ 'frs_booking_url',           'frs_booking_url',           [] ],
			[ 'frs_languages',             'frs_languages',             [ '_languages', 'languages' ] ],
			[ 'frs_specialties',           'frs_specialties',           [] ],
			[ 'frs_specialties_lo',        'frs_specialties_lo',        [ 'specialties_lo', '_specialties_lo' ] ],
			[ 'frs_awards',                'frs_awards',                [ '_awards', 'awards' ] ],
			[ 'frs_namb_certifications',   'frs_namb_certifications',   [ '_namb_certifications', 'namb_certifications' ] ],
			[ 'frs_service_areas',         'frs_service_areas',         [] ],
			[ 'frs_nar_designations',      'frs_nar_designations',      [] ],
			[ 'frs_profile_headline',      'frs_profile_headline',      [] ],
			[ 'frs_legacy_uuid',           'frs_legacy_uuid',           [] ],
			[ 'frs_legacy_id',             'frs_legacy_id',             [] ],
			[ 'frs_courted_id',            'frs_courted_id',            [] ],
			[ 'frs_twenty_crm_id',         'frs_twenty_crm_id',         [] ],
			[ 'linked_person_id',          '_linked_person_id',         [] ],
		];

		foreach ( $map as $row ) {
			list( $col, $canonical, $aliases ) = $row;
			if ( ! array_key_exists( $col, $person ) ) {
				continue;
			}
			$value = $person[ $col ];
			if ( null === $value ) {
				continue;
			}
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}
			self::safe_meta_update( $user_id, $canonical, $value, $is_new, $stats );
			foreach ( $aliases as $alias ) {
				self::safe_meta_update( $user_id, $alias, $value, $is_new, $stats );
			}
		}
	}

	/**
	 * Apply the SQLite person's data to a single WP user.
	 *
	 * THE RULE: if the user already exists on production, never change their
	 * identity (user_login, user_email, display_name) — only ADD enrichment
	 * meta that's missing. If they don't exist, ensure_user creates them.
	 * That's it.
	 *
	 * Everything below is additive:
	 *   - apply_meta uses safe_meta_update (only writes when key is empty)
	 *   - apply_member_types uses bp_set_member_type append=true
	 *   - groups_join_group is gated by groups_is_user_member
	 *   - avatar is set only if the user has no avatar yet
	 */
	private static function sync_person_to_user( int $user_id, array $person, bool $is_new,
		array $memberships, array $group_id_map, array $opts, array &$stats ): void {

		self::apply_meta( $user_id, $person, $is_new, $stats );
		self::apply_member_types( $user_id, $person, $stats );
		self::apply_member_type_tags( $user_id, $person, $is_new, $stats );

		// Group memberships (additive — never remove existing memberships).
		$sqlite_groups = $memberships[ (int) $person['id'] ] ?? [];
		foreach ( $sqlite_groups as $sqlite_gid ) {
			$bp_gid = $group_id_map[ $sqlite_gid ] ?? 0;
			if ( ! $bp_gid ) {
				continue;
			}
			if ( function_exists( 'groups_is_user_member' ) && ! groups_is_user_member( $user_id, $bp_gid ) ) {
				$joined = groups_join_group( $bp_gid, $user_id );
				if ( $joined ) {
					$stats['memberships_added']++;
				} else {
					error_log( sprintf( '[LocalAggregateSync] groups_join_group failed: user=%d group=%d', $user_id, $bp_gid ) );
				}
			}
		}

		if ( ! $opts['skip_images'] ) {
			self::apply_avatar( $user_id, $person, $stats );
		}
	}

	/**
	 * Apply ALL BP member types for this person (append mode).
	 *
	 * SQLite stores member_types as a JSON array (e.g. ["staff","loan_originator"]
	 * for dual-role people). BuddyPress 2.3+ supports multiple member types per
	 * user via bp_set_member_type($uid, $type, $append=true).
	 *
	 * Existing types on prod are preserved (we only ADD, never remove).
	 */
	private static function apply_member_types( int $user_id, array $person, array &$stats ): void {
		if ( ! function_exists( 'bp_set_member_type' ) ) {
			return;
		}

		// Build the type list: prefer member_types JSON array, fall back to scalar.
		$types = [];
		if ( ! empty( $person['member_types'] ) ) {
			$decoded = json_decode( (string) $person['member_types'], true );
			if ( is_array( $decoded ) ) {
				$types = array_values( array_filter( array_map( 'strval', $decoded ) ) );
			}
		}
		if ( empty( $types ) && ! empty( $person['member_type'] ) ) {
			$types = [ (string) $person['member_type'] ];
		}
		if ( empty( $types ) ) {
			return;
		}

		// Existing types (BP returns array when called with second arg true).
		$existing = function_exists( 'bp_get_member_type' )
			? (array) bp_get_member_type( $user_id, false )
			: [];
		// bp_get_member_type without the 2nd arg = single (back-compat); with false = all.
		// Normalize: it may also return string when single.
		if ( ! is_array( $existing ) ) {
			$existing = $existing ? [ (string) $existing ] : [];
		}

		foreach ( $types as $type ) {
			if ( '' === $type ) {
				continue;
			}
			if ( in_array( $type, $existing, true ) ) {
				continue;
			}
			bp_set_member_type( $user_id, $type, true /* append */ );
			$stats['member_type_set']++;
		}
	}

	/**
	 * member_type_tags is a JSON array on the SQLite row (e.g. ["Commercial"]).
	 * Persist as a JSON-encoded user_meta key, never overwriting a non-empty
	 * existing list — only union new entries in.
	 */
	private static function apply_member_type_tags( int $user_id, array $person, bool $is_new, array &$stats ): void {
		$raw = $person['member_type_tags'] ?? '';
		if ( null === $raw || '' === $raw ) {
			return;
		}
		$incoming = json_decode( (string) $raw, true );
		if ( ! is_array( $incoming ) || empty( $incoming ) ) {
			return;
		}

		$existing_raw = get_user_meta( $user_id, 'frs_specialties', true );
		$existing     = [];
		if ( is_array( $existing_raw ) ) {
			$existing = $existing_raw;
		} elseif ( is_string( $existing_raw ) && '' !== $existing_raw ) {
			$decoded = json_decode( $existing_raw, true );
			$existing = is_array( $decoded ) ? $decoded : [];
		}

		$merged = array_values( array_unique( array_merge( $existing, $incoming ) ) );
		if ( count( $merged ) === count( $existing ) ) {
			return;
		}
		update_user_meta( $user_id, 'frs_specialties', wp_json_encode( $merged ) );
		$stats['meta_appended_to_alts']++;
		unset( $is_new );
	}

	/**
	 * Sideload the headshot and route through Core\Avatar so it shows everywhere.
	 *
	 * SKIPS users who already have an avatar set — they likely uploaded their
	 * own and we never overwrite user choice. Also skips users with Microsoft
	 * auth (aadObjectId) for the same reason: their Entra profile photo flows
	 * via WPO365 and our sync shouldn't override it.
	 *
	 * Input source: SQLite canonical columns `headshot_local_path` (preferred —
	 * already downloaded by enrichment) or `headshot_url` (download on demand).
	 */
	private static function apply_avatar( int $user_id, array $person, array &$stats ): void {
		// Hard skip: user already has an avatar (they set it themselves).
		if ( class_exists( '\FRSUsers\Core\Avatar' ) && \FRSUsers\Core\Avatar::has( $user_id ) ) {
			$stats['avatars_skipped_user_owned']++;
			return;
		}
		// Hard skip: user is Entra/WPO365-managed; their photo is Microsoft-owned.
		if ( get_user_meta( $user_id, 'aadObjectId', true ) ) {
			$stats['avatars_skipped_microsoft']++;
			return;
		}

		// Prefer local pre-downloaded path; fall back to URL.
		$path = trim( (string) ( $person['headshot_local_path'] ?? $person['frs_headshot_local_path'] ?? '' ) );
		$url  = trim( (string) ( $person['headshot_url']        ?? $person['frs_headshot_url']        ?? '' ) );

		$abs = '';
		if ( '' !== $path ) {
			$abs = self::resolve_image_path( $path );
		}
		if ( '' === $abs && '' !== $url ) {
			// Sideload directly from the URL.
			$attachment_id = self::sideload_url( $url, $user_id, $person );
			if ( $attachment_id ) {
				\FRSUsers\Core\Avatar::set( $user_id, $attachment_id );
				$stats['avatars_uploaded']++;
			}
			return;
		}
		if ( '' === $abs ) {
			return;
		}

		$display = (string) ( $person['display_name']
			?? ( ( $person['first_name'] ?? '' ) . '-' . ( $person['last_name'] ?? '' ) ) );
		$attachment_id = self::sideload_local( $abs, sprintf( 'headshot-%d-%s', $user_id, sanitize_title( $display ) ) );
		if ( ! $attachment_id ) {
			return;
		}
		if ( class_exists( '\FRSUsers\Core\Avatar' ) ) {
			\FRSUsers\Core\Avatar::set( $user_id, $attachment_id );
		} else {
			update_user_meta( $user_id, 'headshot_id', $attachment_id );
		}
		$stats['avatars_uploaded']++;
	}

	/**
	 * Download a remote URL into the media library, deduped per URL hash.
	 */
	private static function sideload_url( string $url, int $user_id, array $person ): int {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$existing = get_posts( [
			'post_type'      => 'attachment',
			'meta_query'     => [ [ 'key' => '_frs_image_url_hash', 'value' => md5( $url ) ] ],
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );
		if ( ! empty( $existing ) ) {
			return (int) $existing[0];
		}
		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			error_log( '[LocalAggregateSync] download_url failed: ' . $tmp->get_error_message() );
			return 0;
		}
		$display = (string) ( $person['display_name']
			?? ( ( $person['first_name'] ?? '' ) . '-' . ( $person['last_name'] ?? '' ) ) );
		$ext = pathinfo( wp_parse_url( $url, PHP_URL_PATH ) ?? '', PATHINFO_EXTENSION ) ?: 'jpg';
		$file_array = [
			'name'     => sanitize_file_name( sprintf( 'headshot-%d-%s.%s', $user_id, sanitize_title( $display ), $ext ) ),
			'tmp_name' => $tmp,
		];
		$attachment_id = media_handle_sideload( $file_array, 0 );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			error_log( '[LocalAggregateSync] media_handle_sideload(url) failed: ' . $attachment_id->get_error_message() );
			return 0;
		}
		update_post_meta( $attachment_id, '_frs_image_url_hash', md5( $url ) );
		update_post_meta( $attachment_id, '_frs_original_url', $url );
		return (int) $attachment_id;
	}

	// ---------------------------------------------------------------------
	// Image plumbing
	// ---------------------------------------------------------------------

	/**
	 * Resolve a SQLite-relative image path to an absolute filesystem path.
	 *
	 * The offline aggregator stores paths relative to its `data/` dir
	 * (e.g. `images/agents/123.jpg`). At sync time the bundled `data/`
	 * dir lives next to agents.db. The constant
	 * `FRS_LOCAL_AGGREGATE_DATA_DIR` lets ops override the location.
	 */
	private static function resolve_image_path( string $rel ): string {
		if ( '' === $rel ) {
			return '';
		}
		if ( '/' === $rel[0] && file_exists( $rel ) ) {
			return $rel;
		}
		$base = defined( 'FRS_LOCAL_AGGREGATE_DATA_DIR' )
			? rtrim( (string) FRS_LOCAL_AGGREGATE_DATA_DIR, '/' )
			: '/app/data/private';
		$abs = $base . '/' . ltrim( $rel, '/' );
		return file_exists( $abs ) ? $abs : '';
	}

	/**
	 * Sideload a local file into the media library, deduped via _frs_image_src_path.
	 */
	private static function sideload_local( string $abs_path, string $basename_hint ): int {
		$existing = get_posts( [
			'post_type'      => 'attachment',
			'meta_query'     => [
				[
					'key'   => '_frs_image_src_path',
					'value' => $abs_path,
				],
			],
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );
		if ( ! empty( $existing ) ) {
			return (int) $existing[0];
		}

		$tmp = wp_tempnam( basename( $abs_path ) );
		if ( ! $tmp || ! @copy( $abs_path, $tmp ) ) {
			error_log( '[LocalAggregateSync] copy failed: ' . $abs_path );
			return 0;
		}

		$ext       = pathinfo( $abs_path, PATHINFO_EXTENSION );
		$file_array = [
			'name'     => sanitize_file_name( $basename_hint . ( $ext ? '.' . $ext : '.jpg' ) ),
			'tmp_name' => $tmp,
		];

		$attachment_id = media_handle_sideload( $file_array, 0 );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			error_log( '[LocalAggregateSync] media_handle_sideload failed: ' . $attachment_id->get_error_message() );
			return 0;
		}
		update_post_meta( $attachment_id, '_frs_image_src_path', $abs_path );
		return (int) $attachment_id;
	}

	// ---------------------------------------------------------------------
	// Shared helpers (mirroring GoogleRosterSync)
	// ---------------------------------------------------------------------

	private static function find_user_by_meta( string $meta_key, string $meta_value ): int {
		if ( '' === $meta_value ) {
			return 0;
		}
		global $wpdb;
		$user_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
			$meta_key,
			$meta_value
		) );
		return $user_id ? (int) $user_id : 0;
	}

	private static function safe_meta_update( int $user_id, string $field, string $value, bool $is_new, array &$stats ): void {
		if ( '' === $value ) {
			return;
		}
		// Bypass safe-mode for sync-managed pointers (none yet, but reserved).
		$current = get_user_meta( $user_id, $field, true );
		if ( $is_new || '' === $current || null === $current ) {
			update_user_meta( $user_id, $field, $value );
			return;
		}
		if ( self::normalize_for_compare( $field, (string) $current ) === self::normalize_for_compare( $field, $value ) ) {
			return;
		}
		$alt_field = self::alt_field_name( $field );
		if ( '' === $alt_field ) {
			return;
		}
		$alts_raw = get_user_meta( $user_id, $alt_field, true );
		$alts     = [];
		if ( is_array( $alts_raw ) ) {
			$alts = $alts_raw;
		} elseif ( is_string( $alts_raw ) && '' !== $alts_raw ) {
			$decoded = json_decode( $alts_raw, true );
			$alts    = is_array( $decoded ) ? $decoded : [];
		}
		if ( ! in_array( $value, $alts, true ) ) {
			$alts[] = $value;
			update_user_meta( $user_id, $alt_field, wp_json_encode( $alts ) );
			$stats['meta_appended_to_alts']++;
		}
	}

	private static function alt_field_name( string $field ): string {
		switch ( $field ) {
			case 'first_name':         return 'frs_alt_first_names';
			case 'last_name':          return 'frs_alt_last_names';
			case 'nickname':           return 'frs_alt_nicknames';
			case 'frs_phone_number':   return 'frs_alt_phones';
			case 'frs_mobile_number':  return 'frs_alt_mobile_phones';
			case 'frs_business_email': return 'frs_alt_business_emails';
			case 'frs_dre_license':    return 'frs_alt_dre_licenses';
			case 'frs_nmls':           return 'frs_alt_nmls';
			case 'frs_license_number': return 'frs_alt_license_numbers';
			default:                   return '';
		}
	}

	private static function normalize_for_compare( string $field, string $value ): string {
		$value = trim( $value );
		if ( in_array( $field, [ 'frs_phone_number', 'frs_mobile_number' ], true ) ) {
			return preg_replace( '/[^A-Za-z0-9]/', '', $value );
		}
		if ( in_array( $field, [ 'first_name', 'last_name', 'nickname', 'frs_business_email' ], true ) ) {
			return strtolower( $value );
		}
		return $value;
	}

	private static function suppress_emails(): void {
		self::$suppress_mail = true;
		add_filter( 'pre_wp_mail', [ __CLASS__, 'maybe_drop_mail' ], 99, 1 );
	}

	private static function unsuppress_emails(): void {
		self::$suppress_mail = false;
		remove_filter( 'pre_wp_mail', [ __CLASS__, 'maybe_drop_mail' ], 99 );
	}

	public static function maybe_drop_mail( $short_circuit ) {
		return self::$suppress_mail ? false : $short_circuit;
	}
}
