<?php
/**
 * Google Roster Sync
 *
 * Pulls the live FRS agent roster from a Google Sheet via a service-account-
 * authenticated Sheets API call, then syncs into BuddyPress groups (Regions
 * and Offices), WordPress users, and user_meta.
 *
 * Tab semantics in the source sheet:
 *   - "Region <name>"        → spreadsheet section divider — SKIPPED
 *   - " Offices & Staff"     → index/lookup tab — SKIPPED
 *   - "Loan Officers"        → loan_originator member type (sales_associate if also in office)
 *   - "Staff Members"        → staff member type (overrides everything)
 *   - "Commercial Agents"    → sales_associate + frs_specialties=["Commercial"]
 *   - any other (48 office tabs, color-coded) → office groups + sales_associate
 *
 * Region grouping is derived from each office tab's tabColor via REGION_COLOR_MAP.
 * Region groups are created with parent_id=0; office groups with parent_id=region_id.
 *
 * Auth: a JWT (RS256) is signed locally with the service-account private key,
 * exchanged for a 1-hour OAuth2 access token, and cached in a 50-minute transient.
 *
 * Concurrency: a 10-minute transient lock (`frs_google_roster_sync_lock`) prevents
 * overlapping runs (in case a sync exceeds the 15-minute scheduler interval).
 *
 * SAFETY MODEL (v3 — safe-mode rewrite):
 *   - Match existing users by DRE → NMLS → never by email-overwrite
 *   - Never call wp_update_user on existing users
 *   - safe_meta_update appends to alt-history rather than overwriting primary
 *   - bp_set_member_type only fires when no existing type
 *   - Group-membership lookup has a slug-based fallback
 *
 * Extension points:
 *   - frs_google_roster_sheet_id          (override SHEET_ID)
 *   - frs_google_roster_region_color_map  (override REGION_COLOR_MAP)
 *   - frs_google_roster_pre_sync_user     (fires before each user create/update)
 *   - frs_google_roster_sync_complete     (fires after sync with stats array)
 *
 * @package FRSUsers\Integrations
 */

namespace FRSUsers\Integrations;

defined( 'ABSPATH' ) || exit;

class GoogleRosterSync {

	const SHEET_ID              = '1izseyeL9GyAtwAah2SooloV-rkmEWLvaERGN_ylws-g';
	const TOKEN_TRANSIENT_KEY   = 'frs_google_sheets_access_token';
	const SYNC_LOCK_TRANSIENT   = 'frs_google_roster_sync_lock';
	const SCHEDULER_HOOK        = 'frs_google_roster_sync_tick';

	/**
	 * Map of office tab color (hex, uppercase) → canonical region name.
	 * Filterable via `frs_google_roster_region_color_map`.
	 */
	const REGION_COLOR_MAP = [
		'#8989EB' => 'Bay Area',
		'#6FA8DC' => 'Monterey Bay',
		'#6AA84F' => 'Central Coast',
		'#FF0000' => 'Ventura and Northern LA',
		'#E69138' => 'Greater Los Angeles',
		'#FFFF00' => 'Greater LA & Inland Empire',
		'#4DD0E1' => 'Inland Empire and San Diego',
	];

	/**
	 * Process-wide flag toggled by suppress_emails() / unsuppress_emails().
	 *
	 * @var bool
	 */
	private static $suppress_mail = false;

	/**
	 * Initialize the integration.
	 *
	 * Registers the WP-CLI command, the Action Scheduler hook handler, and a
	 * recurring 15-minute schedule (idempotent — won't double-schedule).
	 */
	public static function init(): void {
		add_action( self::SCHEDULER_HOOK, [ __CLASS__, 'run_sync' ] );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'frs-users roster-sync', [ __CLASS__, 'cli_handler' ] );
		}

		// Schedule recurring sync via Action Scheduler if available. AS is loaded
		// by FluentForm/FluentCRM on this stack, but guard for safety.
		add_action( 'init', [ __CLASS__, 'maybe_schedule' ], 20 );
	}

	/**
	 * Schedule the recurring 15-minute sync if not already scheduled.
	 */
	public static function maybe_schedule(): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}

		if ( false === as_next_scheduled_action( self::SCHEDULER_HOOK ) ) {
			as_schedule_recurring_action( time() + 60, 15 * MINUTE_IN_SECONDS, self::SCHEDULER_HOOK, [], 'frs-users' );
		}
	}

	/**
	 * Main sync entrypoint.
	 *
	 * @param array $args Optional flags: ['force' => bool, 'dry_run' => bool].
	 * @return array Stats array.
	 */
	public static function run_sync( array $args = [] ): array {
		$force   = ! empty( $args['force'] );
		$dry_run = ! empty( $args['dry_run'] );
		$start   = microtime( true );

		error_log( '[GoogleRosterSync] starting sync v3 (safe-mode: DRE/NMLS match, append-only)' );

		$stats = [
			'regions_created'             => 0,
			'offices_created'             => 0,
			'users_created'               => 0,
			'users_updated'               => 0,
			'users_matched_by_dre'        => 0,
			'users_matched_by_nmls'       => 0,
			'users_skipped_no_match'      => 0,
			'users_skipped_email_conflict'=> 0,
			'meta_appended_to_alts'       => 0,
			'member_type_conflicts'       => 0,
			'memberships_added'           => 0,
			'errors'                      => 0,
			'duration_ms'                 => 0,
		];

		// Concurrency lock.
		if ( ! $force && get_transient( self::SYNC_LOCK_TRANSIENT ) ) {
			error_log( '[GoogleRosterSync] Sync already in progress (lock held); skipping.' );
			$stats['duration_ms'] = (int) ( ( microtime( true ) - $start ) * 1000 );
			return $stats;
		}

		set_transient( self::SYNC_LOCK_TRANSIENT, time(), 10 * MINUTE_IN_SECONDS );

		try {
			$token = self::get_access_token();
			if ( ! $token ) {
				$stats['errors']++;
				error_log( '[GoogleRosterSync] Failed to acquire access token; aborting.' );
				return $stats;
			}

			$sheet_id = (string) apply_filters( 'frs_google_roster_sheet_id', self::SHEET_ID );

			$tabs = self::fetch_metadata( $token, $sheet_id );
			if ( empty( $tabs ) ) {
				$stats['errors']++;
				error_log( '[GoogleRosterSync] No tabs returned from spreadsheet metadata; aborting.' );
				return $stats;
			}

			$color_map = (array) apply_filters( 'frs_google_roster_region_color_map', self::REGION_COLOR_MAP );
			$color_map = array_change_key_case( $color_map, CASE_UPPER );

			// Bucket tabs by role.
			$office_tabs        = []; // [ tab_title => [ 'gid' => int, 'color' => hex|null ] ]
			$loan_officer_tabs  = [];
			$staff_tabs         = [];
			$commercial_tabs    = [];

			foreach ( $tabs as $tab ) {
				$title = (string) $tab['title'];
				$gid   = (int) $tab['gid'];
				$color = isset( $tab['color_hex'] ) ? strtoupper( (string) $tab['color_hex'] ) : null;

				$trimmed = trim( $title );

				// SKIP: spreadsheet section dividers and the lookup index.
				if ( 0 === strpos( $trimmed, 'Region ' ) ) {
					continue;
				}
				if ( ' Offices & Staff' === $title || 'Offices & Staff' === $trimmed ) {
					continue;
				}

				if ( 'Loan Officers' === $trimmed ) {
					$loan_officer_tabs[ $title ] = [ 'gid' => $gid, 'color' => $color ];
					continue;
				}
				if ( 'Staff Members' === $trimmed ) {
					$staff_tabs[ $title ] = [ 'gid' => $gid, 'color' => $color ];
					continue;
				}
				if ( 'Commercial Agents' === $trimmed ) {
					$commercial_tabs[ $title ] = [ 'gid' => $gid, 'color' => $color ];
					continue;
				}

				$office_tabs[ $title ] = [ 'gid' => $gid, 'color' => $color ];
			}

			// Pull values for every relevant tab.
			$office_data    = []; // [ region_name => [ office_name => [ agent[], ... ] ] ]
			$loan_officers  = []; // [ email_lower => agent ]
			$staff_only     = []; // [ email_lower => agent ]
			$commercial     = []; // [ email_lower => agent ]

			foreach ( $office_tabs as $title => $info ) {
				try {
					$rows        = self::fetch_tab_values( $token, $sheet_id, $title );
					$parsed      = self::parse_office_tab( $rows, $title );
					$office_name = $parsed['office_name'];
					$agents      = $parsed['agents'];

					$region_name = $color_map[ $info['color'] ] ?? 'Unassigned';
					if ( ! isset( $office_data[ $region_name ] ) ) {
						$office_data[ $region_name ] = [];
					}
					$office_data[ $region_name ][ $office_name ] = $agents;
				} catch ( \Throwable $e ) {
					$stats['errors']++;
					error_log( sprintf( '[GoogleRosterSync] Failed parsing office tab "%s": %s', $title, $e->getMessage() ) );
				}
			}

			foreach ( $loan_officer_tabs as $title => $info ) {
				try {
					$rows = self::fetch_tab_values( $token, $sheet_id, $title );
					foreach ( self::parse_simple_people_tab( $rows ) as $agent ) {
						$key = strtolower( (string) $agent['email'] );
						if ( '' === $key ) {
							continue;
						}
						$loan_officers[ $key ] = $agent;
					}
				} catch ( \Throwable $e ) {
					$stats['errors']++;
					error_log( sprintf( '[GoogleRosterSync] Failed parsing Loan Officers tab: %s', $e->getMessage() ) );
				}
			}

			foreach ( $staff_tabs as $title => $info ) {
				try {
					$rows = self::fetch_tab_values( $token, $sheet_id, $title );
					foreach ( self::parse_simple_people_tab( $rows ) as $agent ) {
						$key = strtolower( (string) $agent['email'] );
						if ( '' === $key ) {
							continue;
						}
						$staff_only[ $key ] = $agent;
					}
				} catch ( \Throwable $e ) {
					$stats['errors']++;
					error_log( sprintf( '[GoogleRosterSync] Failed parsing Staff Members tab: %s', $e->getMessage() ) );
				}
			}

			foreach ( $commercial_tabs as $title => $info ) {
				try {
					$rows = self::fetch_tab_values( $token, $sheet_id, $title );
					foreach ( self::parse_simple_people_tab( $rows ) as $agent ) {
						$key = strtolower( (string) $agent['email'] );
						if ( '' === $key ) {
							continue;
						}
						$commercial[ $key ] = $agent;
					}
				} catch ( \Throwable $e ) {
					$stats['errors']++;
					error_log( sprintf( '[GoogleRosterSync] Failed parsing Commercial Agents tab: %s', $e->getMessage() ) );
				}
			}

			if ( $dry_run ) {
				$office_count = 0;
				foreach ( $office_data as $offices ) {
					$office_count += count( $offices );
				}
				error_log( sprintf(
					'[GoogleRosterSync] DRY RUN — would sync %d region(s), %d office(s), %d LO-only, %d staff-only, %d commercial.',
					count( $office_data ),
					$office_count,
					count( $loan_officers ),
					count( $staff_only ),
					count( $commercial )
				) );
				$stats['duration_ms'] = (int) ( ( microtime( true ) - $start ) * 1000 );
				return $stats;
			}

			// Pre-flight: BuddyPress groups must be loaded.
			if ( ! function_exists( 'groups_create_group' ) || ! function_exists( 'groups_join_group' ) ) {
				$stats['errors']++;
				error_log( '[GoogleRosterSync] BuddyPress groups component not available; aborting writes.' );
				return $stats;
			}

			self::suppress_emails();

			try {
				// Build region groups + office groups; remember IDs for join calls.
				$region_ids = []; // [ region_name => group_id ]
				$office_ids = []; // [ office_name => group_id ]

				foreach ( $office_data as $region_name => $offices ) {
					$region_id = self::ensure_group( $region_name, 'region', 0, $stats );
					if ( ! $region_id ) {
						continue;
					}
					$region_ids[ $region_name ] = $region_id;

					foreach ( $offices as $office_name => $_agents ) {
						$office_id = self::ensure_group( $office_name, 'office', $region_id, $stats );
						if ( $office_id ) {
							$office_ids[ $office_name ] = $office_id;
						}
					}
				}

				// Diagnostic: log how many office_ids were resolved up-front.
				// If this is 0 we know group joins below will all fail and we can
				// catch the regression early in the logs.
				error_log( sprintf( '[GoogleRosterSync] resolved %d office group ID(s) before per-user loop', count( $office_ids ) ) );

				// Aggregate agents by email across all sources to dedupe writes.
				// Each entry tracks: data, offices the agent belongs to, and the
				// presence flags used for member-type priority.
				$people = []; // [ email_lower => [ 'agent' => array, 'offices' => [ office_name, ... ], 'in_office' => bool, 'in_lo' => bool, 'in_staff' => bool, 'in_commercial' => bool ] ]

				foreach ( $office_data as $region_name => $offices ) {
					foreach ( $offices as $office_name => $agents ) {
						foreach ( $agents as $agent ) {
							$email = strtolower( (string) $agent['email'] );
							if ( '' === $email ) {
								continue;
							}
							if ( ! isset( $people[ $email ] ) ) {
								$people[ $email ] = [
									'agent'         => $agent,
									'offices'       => [],
									'in_office'     => false,
									'in_lo'         => false,
									'in_staff'      => false,
									'in_commercial' => false,
								];
							}
							$people[ $email ]['in_office'] = true;
							$people[ $email ]['offices'][] = $office_name;
							// Prefer the most complete record as canonical.
							$people[ $email ]['agent'] = self::merge_agent( $people[ $email ]['agent'], $agent );
						}
					}
				}

				foreach ( $loan_officers as $email => $agent ) {
					if ( ! isset( $people[ $email ] ) ) {
						$people[ $email ] = [
							'agent'         => $agent,
							'offices'       => [],
							'in_office'     => false,
							'in_lo'         => true,
							'in_staff'      => false,
							'in_commercial' => false,
						];
					} else {
						$people[ $email ]['in_lo']    = true;
						$people[ $email ]['agent']   = self::merge_agent( $people[ $email ]['agent'], $agent );
					}
				}

				foreach ( $staff_only as $email => $agent ) {
					if ( ! isset( $people[ $email ] ) ) {
						$people[ $email ] = [
							'agent'         => $agent,
							'offices'       => [],
							'in_office'     => false,
							'in_lo'         => false,
							'in_staff'      => true,
							'in_commercial' => false,
						];
					} else {
						$people[ $email ]['in_staff'] = true;
						$people[ $email ]['agent']    = self::merge_agent( $people[ $email ]['agent'], $agent );
					}
				}

				foreach ( $commercial as $email => $agent ) {
					if ( ! isset( $people[ $email ] ) ) {
						$people[ $email ] = [
							'agent'         => $agent,
							'offices'       => [],
							'in_office'     => false,
							'in_lo'         => false,
							'in_staff'      => false,
							'in_commercial' => true,
						];
					} else {
						$people[ $email ]['in_commercial'] = true;
						$people[ $email ]['agent']         = self::merge_agent( $people[ $email ]['agent'], $agent );
					}
				}

				// Now apply each person.
				// Throttle: pause + flush in-memory cache every BATCH_SIZE iterations
				// to give MySQL connection pool + PHP heap breathing room. Without this,
				// a tight loop of ~1700 wp_create_user + group joins exhausts host RAM
				// and triggers MySQL OOM-pressure cascades (observed v1 failure mode).
				$batch_size    = (int) apply_filters( 'frs_google_roster_batch_size', 25 );
				$batch_pause_us = (int) apply_filters( 'frs_google_roster_batch_pause_us', 500000 ); // 0.5s
				$processed_count = 0;
				foreach ( $people as $email => $entry ) {
					if ( $processed_count > 0 && 0 === $processed_count % $batch_size ) {
						if ( function_exists( 'wp_cache_flush_runtime' ) ) {
							wp_cache_flush_runtime();
						}
						usleep( $batch_pause_us );
					}
					$processed_count++;
					try {
						$member_type = self::determine_member_type(
							$entry['in_office'],
							$entry['in_lo'],
							$entry['in_staff']
						);

						/**
						 * Fires before each user create/update during roster sync.
						 *
						 * @param array  $agent_data  Agent record (first/last/email/phone/dre/...).
						 * @param string $member_type Resolved BP member type for this user.
						 * @param array  $entry       Full bucket entry (offices, presence flags).
						 */
						do_action( 'frs_google_roster_pre_sync_user', $entry['agent'], $member_type, $entry );

						// ensure_user returns the user_id and reports back via $matched_how
						// what kind of resolution happened (created, dre, nmls, skipped_*).
						$matched_how = '';
						$user_id     = self::ensure_user( $entry['agent'], $member_type, $matched_how, $stats );

						if ( ! $user_id ) {
							// Skip stats already incremented inside ensure_user when applicable.
							continue;
						}

						$is_new_user = ( 'created' === $matched_how );
						if ( $is_new_user ) {
							$stats['users_created']++;
						} else {
							$stats['users_updated']++;
						}

						// ---- Safe meta updates ----
						// First/last name
						if ( ! empty( $entry['agent']['first'] ) ) {
							self::safe_meta_update( $user_id, 'first_name', (string) $entry['agent']['first'], $is_new_user, $stats );
						}
						if ( ! empty( $entry['agent']['last'] ) ) {
							self::safe_meta_update( $user_id, 'last_name', (string) $entry['agent']['last'], $is_new_user, $stats );
						}
						if ( ! empty( $entry['agent']['phone'] ) ) {
							self::safe_meta_update( $user_id, 'frs_phone_number', (string) $entry['agent']['phone'], $is_new_user, $stats );
						}
						if ( ! empty( $entry['agent']['mobile'] ) ) {
							self::safe_meta_update( $user_id, 'frs_mobile_number', (string) $entry['agent']['mobile'], $is_new_user, $stats );
						}
						if ( ! empty( $entry['agent']['dre'] ) ) {
							self::safe_meta_update( $user_id, 'frs_dre_license', (string) $entry['agent']['dre'], $is_new_user, $stats );
						}
						if ( ! empty( $entry['agent']['nmls'] ) ) {
							// nmls is sync-managed identifier; safe-update treats it like other meta.
							self::safe_meta_update( $user_id, 'frs_nmls', (string) $entry['agent']['nmls'], $is_new_user, $stats );
						}
						if ( ! empty( $entry['agent']['agent_id'] ) ) {
							self::safe_meta_update( $user_id, 'frs_agent_id', (string) $entry['agent']['agent_id'], $is_new_user, $stats );
						}

						// Member-type assignment: only set when no existing type.
						// Never overwrite — if sheet implies a different type, log a conflict.
						if ( function_exists( 'bp_set_member_type' ) ) {
							$existing_type = function_exists( 'bp_get_member_type' ) ? (string) bp_get_member_type( $user_id ) : '';
							if ( '' === $existing_type ) {
								bp_set_member_type( $user_id, $member_type );
							} elseif ( $existing_type !== $member_type ) {
								$stats['member_type_conflicts']++;
								error_log( sprintf(
									'[GoogleRosterSync] user %d member_type conflict: existing=%s sheet=%s — kept existing',
									$user_id,
									$existing_type,
									$member_type
								) );
							}
						}

						// Office memberships.
						$primary_office = '';
						foreach ( array_unique( $entry['offices'] ) as $office_name ) {
							if ( '' === $primary_office ) {
								$primary_office = $office_name;
							}
							$gid = $office_ids[ $office_name ] ?? 0;
							if ( ! $gid ) {
								// Fallback: look up by exact name match in DB. Handles edge
								// cases where ensure_group returned 0 silently or the office
								// was created in a prior run with a slug we don't have cached.
								if ( class_exists( '\BP_Groups_Group' ) ) {
									$gid = (int) \BP_Groups_Group::group_exists( sanitize_title( $office_name ) );
									if ( ! $gid ) {
										$gid = (int) \BP_Groups_Group::get_id_from_slug( sanitize_title( $office_name ) );
									}
								}
								if ( $gid ) {
									$office_ids[ $office_name ] = $gid; // cache for future iterations
								}
							}
							if ( $gid && function_exists( 'groups_is_user_member' ) && ! groups_is_user_member( $user_id, $gid ) ) {
								$joined = groups_join_group( $gid, $user_id );
								if ( $joined ) {
									$stats['memberships_added']++;
								} else {
									error_log( sprintf( '[GoogleRosterSync] groups_join_group failed: user=%d group=%d name=%s', $user_id, $gid, $office_name ) );
								}
							}
						}
						if ( '' !== $primary_office ) {
							// frs_office is a sync-managed pointer; primary may be overwritten
							// to reflect the user's currently-listed primary office in the sheet.
							update_user_meta( $user_id, 'frs_office', $primary_office );
						}

						// Commercial specialty tag.
						if ( $entry['in_commercial'] ) {
							$existing = get_user_meta( $user_id, 'frs_specialties', true );
							$list     = [];
							if ( is_string( $existing ) && '' !== $existing ) {
								$decoded = json_decode( $existing, true );
								if ( is_array( $decoded ) ) {
									$list = $decoded;
								}
							} elseif ( is_array( $existing ) ) {
								$list = $existing;
							}
							if ( ! in_array( 'Commercial', $list, true ) ) {
								$list[] = 'Commercial';
							}
							update_user_meta( $user_id, 'frs_specialties', wp_json_encode( $list ) );
						}
					} catch ( \Throwable $e ) {
						$stats['errors']++;
						error_log( sprintf( '[GoogleRosterSync] Failed syncing user %s: %s', $email, $e->getMessage() ) );
					}
				}
			} finally {
				self::unsuppress_emails();
			}
		} catch ( \Throwable $e ) {
			$stats['errors']++;
			error_log( '[GoogleRosterSync] Fatal sync error: ' . $e->getMessage() );
		} finally {
			delete_transient( self::SYNC_LOCK_TRANSIENT );
		}

		$stats['duration_ms'] = (int) ( ( microtime( true ) - $start ) * 1000 );

		/**
		 * Fires after the roster sync completes (success or partial failure).
		 *
		 * @param array $stats Final stats array.
		 */
		do_action( 'frs_google_roster_sync_complete', $stats );

		return $stats;
	}

	/**
	 * WP-CLI command handler.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Bypass the sync lock.
	 *
	 * [--dry-run]
	 * : Fetch and parse the sheet, but don't write groups/users.
	 *
	 * ## EXAMPLES
	 *
	 *     wp frs-users roster-sync
	 *     wp frs-users roster-sync --dry-run
	 *     wp frs-users roster-sync --force
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Flag args.
	 * @return void
	 */
	public static function cli_handler( $args, $assoc_args ): void {
		$force   = isset( $assoc_args['force'] );
		$dry_run = isset( $assoc_args['dry-run'] );

		\WP_CLI::log( 'Starting Google Sheet roster sync' . ( $dry_run ? ' (DRY RUN)' : '' ) . '...' );

		$stats = self::run_sync( [
			'force'   => $force,
			'dry_run' => $dry_run,
		] );

		foreach ( $stats as $k => $v ) {
			\WP_CLI::log( sprintf( '  %s: %s', $k, is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) ) );
		}

		\WP_CLI::success( 'Roster sync complete.' );
	}

	// ---------------------------------------------------------------------
	// Auth helpers
	// ---------------------------------------------------------------------

	/**
	 * Get a Google OAuth2 access token, using a cached transient when fresh.
	 *
	 * @return string Access token, or empty string on failure.
	 */
	private static function get_access_token(): string {
		$cached = get_transient( self::TOKEN_TRANSIENT_KEY );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		if ( ! defined( 'FRS_GOOGLE_SA_CREDENTIALS' ) ) {
			error_log( '[GoogleRosterSync] FRS_GOOGLE_SA_CREDENTIALS constant is not defined.' );
			return '';
		}

		$path = (string) FRS_GOOGLE_SA_CREDENTIALS;
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			error_log( '[GoogleRosterSync] Service-account credentials file is not readable: ' . $path );
			return '';
		}

		$raw = file_get_contents( $path );
		if ( false === $raw ) {
			error_log( '[GoogleRosterSync] Failed to read credentials file: ' . $path );
			return '';
		}

		$creds = json_decode( $raw, true );
		if ( ! is_array( $creds ) || empty( $creds['client_email'] ) || empty( $creds['private_key'] ) ) {
			error_log( '[GoogleRosterSync] Credentials file is missing client_email or private_key.' );
			return '';
		}

		try {
			$jwt = self::build_jwt( $creds );
		} catch ( \Throwable $e ) {
			error_log( '[GoogleRosterSync] JWT build failed: ' . $e->getMessage() );
			return '';
		}

		$response = wp_remote_post( 'https://oauth2.googleapis.com/token', [
			'timeout' => 20,
			'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
			'body'    => [
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $jwt,
			],
		] );

		if ( is_wp_error( $response ) ) {
			error_log( '[GoogleRosterSync] Token endpoint error: ' . $response->get_error_message() );
			return '';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 || ! is_array( $json ) || empty( $json['access_token'] ) ) {
			error_log( sprintf( '[GoogleRosterSync] Token exchange failed (HTTP %d): %s', $code, substr( $body, 0, 500 ) ) );
			return '';
		}

		$token       = (string) $json['access_token'];
		$expires_in  = isset( $json['expires_in'] ) ? (int) $json['expires_in'] : 3600;
		$cache_ttl   = max( 60, min( $expires_in - 600, 50 * MINUTE_IN_SECONDS ) );

		set_transient( self::TOKEN_TRANSIENT_KEY, $token, $cache_ttl );

		return $token;
	}

	/**
	 * Build a signed JWT for the service-account flow.
	 *
	 * @param array $creds Decoded credentials JSON (client_email, private_key).
	 * @return string Compact-serialized JWT.
	 * @throws \RuntimeException If signing fails.
	 */
	private static function build_jwt( array $creds ): string {
		$now    = time();
		$header = [ 'alg' => 'RS256', 'typ' => 'JWT' ];
		$claims = [
			'iss'   => $creds['client_email'],
			'scope' => 'https://www.googleapis.com/auth/spreadsheets.readonly',
			'aud'   => 'https://oauth2.googleapis.com/token',
			'iat'   => $now,
			'exp'   => $now + 3600,
		];

		$segments = [
			self::base64url_encode( wp_json_encode( $header ) ),
			self::base64url_encode( wp_json_encode( $claims ) ),
		];
		$signing_input = implode( '.', $segments );

		$pkey = openssl_pkey_get_private( (string) $creds['private_key'] );
		if ( ! $pkey ) {
			throw new \RuntimeException( 'openssl_pkey_get_private returned false' );
		}

		$sig = '';
		if ( ! openssl_sign( $signing_input, $sig, $pkey, 'SHA256' ) ) {
			if ( PHP_VERSION_ID < 80000 && function_exists( 'openssl_free_key' ) ) {
				openssl_free_key( $pkey );
			}
			throw new \RuntimeException( 'openssl_sign failed' );
		}
		if ( PHP_VERSION_ID < 80000 && function_exists( 'openssl_free_key' ) ) {
			openssl_free_key( $pkey );
		}

		$segments[] = self::base64url_encode( $sig );

		return implode( '.', $segments );
	}

	/**
	 * Base64url-encode a string (no padding, +/ → -_).
	 */
	private static function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	// ---------------------------------------------------------------------
	// Sheet I/O helpers
	// ---------------------------------------------------------------------

	/**
	 * Fetch spreadsheet metadata (tab IDs, titles, colors).
	 *
	 * @param string $token    Access token.
	 * @param string $sheet_id Spreadsheet ID.
	 * @return array List of tabs: [ [ 'gid' => int, 'title' => string, 'color_hex' => string|null, 'rows' => int ], ... ]
	 */
	private static function fetch_metadata( string $token, string $sheet_id = '' ): array {
		if ( '' === $sheet_id ) {
			$sheet_id = self::SHEET_ID;
		}

		$url = sprintf(
			'https://sheets.googleapis.com/v4/spreadsheets/%s?fields=%s',
			rawurlencode( $sheet_id ),
			rawurlencode( 'properties.title,sheets.properties(sheetId,title,tabColor,gridProperties)' )
		);

		$response = wp_remote_get( $url, [
			'timeout' => 30,
			'headers' => [ 'Authorization' => 'Bearer ' . $token ],
		] );

		if ( is_wp_error( $response ) ) {
			error_log( '[GoogleRosterSync] Metadata fetch error: ' . $response->get_error_message() );
			return [];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 || ! is_array( $json ) || empty( $json['sheets'] ) ) {
			error_log( sprintf( '[GoogleRosterSync] Metadata fetch failed (HTTP %d): %s', $code, substr( $body, 0, 500 ) ) );
			return [];
		}

		$out = [];
		foreach ( (array) $json['sheets'] as $sheet ) {
			$props = $sheet['properties'] ?? [];
			if ( empty( $props['title'] ) ) {
				continue;
			}
			$tab_color = $props['tabColor'] ?? null;
			$hex = null;
			if ( is_array( $tab_color ) ) {
				$hex = self::rgb_to_hex(
					(float) ( $tab_color['red']   ?? 0 ),
					(float) ( $tab_color['green'] ?? 0 ),
					(float) ( $tab_color['blue']  ?? 0 )
				);
			}
			$rows = 0;
			if ( isset( $props['gridProperties']['rowCount'] ) ) {
				$rows = (int) $props['gridProperties']['rowCount'];
			}
			$out[] = [
				'gid'       => (int) ( $props['sheetId'] ?? 0 ),
				'title'     => (string) $props['title'],
				'color_hex' => $hex,
				'rows'      => $rows,
			];
		}

		return $out;
	}

	/**
	 * Fetch the raw rows of a single tab.
	 *
	 * @param string $token     Access token.
	 * @param string $sheet_id  Spreadsheet ID.
	 * @param string $tab_name  Tab title (will be URL-encoded).
	 * @return array Raw rows array (each row is an array of cell strings).
	 */
	private static function fetch_tab_values( string $token, string $sheet_id, string $tab_name ): array {
		// The Sheets API expects the range as "<tab>!A1:G1000". Tab names with
		// spaces or special chars must be wrapped in single quotes.
		$range = sprintf( "'%s'!A1:G1000", str_replace( "'", "''", $tab_name ) );

		$url = sprintf(
			'https://sheets.googleapis.com/v4/spreadsheets/%s/values/%s',
			rawurlencode( $sheet_id ),
			rawurlencode( $range )
		);

		// Retry transient errors (cURL timeouts, 5xx) up to 3 times with exponential backoff.
		// Earlier sync (v1) hit cURL error 28 (timeout) on ~4 tabs; retrying recovers them.
		$max_attempts    = 3;
		$backoff_seconds = 5;
		$last_error      = '';

		for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
			$response = wp_remote_get( $url, [
				'timeout' => 60,
				'headers' => [ 'Authorization' => 'Bearer ' . $token ],
			] );

			if ( is_wp_error( $response ) ) {
				$last_error = $response->get_error_message();
				error_log( sprintf( '[GoogleRosterSync] values fetch attempt %d/%d for "%s" failed: %s', $attempt, $max_attempts, $tab_name, $last_error ) );
				if ( $attempt < $max_attempts ) {
					sleep( $backoff_seconds );
					$backoff_seconds *= 2;
				}
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = (string) wp_remote_retrieve_body( $response );
			$json = json_decode( $body, true );

			if ( $code >= 200 && $code < 300 && is_array( $json ) ) {
				return isset( $json['values'] ) && is_array( $json['values'] ) ? $json['values'] : [];
			}

			$last_error = sprintf( 'HTTP %d: %s', $code, substr( $body, 0, 300 ) );
			error_log( sprintf( '[GoogleRosterSync] values fetch attempt %d/%d for "%s" failed: %s', $attempt, $max_attempts, $tab_name, $last_error ) );

			// Only retry on 5xx; 4xx is permanent (auth, missing tab, etc.).
			if ( $code >= 500 && $attempt < $max_attempts ) {
				sleep( $backoff_seconds );
				$backoff_seconds *= 2;
				continue;
			}

			return [];
		}

		error_log( sprintf( '[GoogleRosterSync] giving up on "%s" after %d attempts: %s', $tab_name, $max_attempts, $last_error ) );
		return [];
	}

	/**
	 * Parse an office tab.
	 *
	 * Layout: R1=office name, R2="Office Address: ...", R3=header row,
	 * R4+=agent rows.
	 *
	 * @param array  $rows         Raw rows from the API.
	 * @param string $tab_title    Falls back to office_name when R1 is empty.
	 * @return array { 'office_name' => string, 'address' => string, 'agents' => agent[] }
	 */
	private static function parse_office_tab( array $rows, string $tab_title ): array {
		$office_name = isset( $rows[0][0] ) && '' !== trim( (string) $rows[0][0] )
			? trim( (string) $rows[0][0] )
			: trim( $tab_title );

		$address = '';
		if ( isset( $rows[1][0] ) ) {
			$raw = trim( (string) $rows[1][0] );
			if ( 0 === stripos( $raw, 'Office Address:' ) ) {
				$address = trim( substr( $raw, strlen( 'Office Address:' ) ) );
			} else {
				$address = $raw;
			}
		}

		$agents = [];
		// R4+ in 1-indexed === index 3+ in 0-indexed.
		$count = count( $rows );
		for ( $i = 3; $i < $count; $i++ ) {
			$row = $rows[ $i ];
			if ( ! is_array( $row ) ) {
				continue;
			}
			$agent = self::row_to_agent( $row, $office_name );
			if ( '' === $agent['first'] && '' === $agent['last'] && '' === $agent['email'] ) {
				continue;
			}
			$agents[] = $agent;
		}

		return [
			'office_name' => $office_name,
			'address'     => $address,
			'agents'      => $agents,
		];
	}

	/**
	 * Parse a "simple" people tab (Loan Officers, Staff Members, Commercial Agents).
	 *
	 * These tabs use the same column layout but do not have the office-name +
	 * address preamble. We auto-detect the header row by looking for "Email".
	 *
	 * @param array $rows Raw rows from the API.
	 * @return array agent[]
	 */
	private static function parse_simple_people_tab( array $rows ): array {
		if ( empty( $rows ) ) {
			return [];
		}

		// Find the header row (within the first 5 rows, conservatively).
		$header_idx = -1;
		$max_scan   = min( 5, count( $rows ) );
		for ( $i = 0; $i < $max_scan; $i++ ) {
			$row = $rows[ $i ];
			if ( ! is_array( $row ) ) {
				continue;
			}
			foreach ( $row as $cell ) {
				if ( is_string( $cell ) && false !== stripos( $cell, 'Email' ) ) {
					$header_idx = $i;
					break 2;
				}
			}
		}

		// If we couldn't find a header, assume row 0 is the header.
		$start = $header_idx >= 0 ? $header_idx + 1 : 1;

		$agents = [];
		$count  = count( $rows );
		for ( $i = $start; $i < $count; $i++ ) {
			$row = $rows[ $i ];
			if ( ! is_array( $row ) ) {
				continue;
			}
			$agent = self::row_to_agent( $row, '' );
			if ( '' === $agent['first'] && '' === $agent['last'] && '' === $agent['email'] ) {
				continue;
			}
			$agents[] = $agent;
		}

		return $agents;
	}

	/**
	 * Map a sheet row (A-G) to the canonical agent shape.
	 */
	private static function row_to_agent( array $row, string $office_name ): array {
		return [
			'first'   => isset( $row[0] ) ? trim( (string) $row[0] ) : '',
			'last'    => isset( $row[1] ) ? trim( (string) $row[1] ) : '',
			'display' => isset( $row[2] ) ? trim( (string) $row[2] ) : '',
			'phone'   => isset( $row[3] ) ? trim( (string) $row[3] ) : '',
			'email'   => isset( $row[4] ) ? strtolower( trim( (string) $row[4] ) ) : '',
			'dre'     => isset( $row[5] ) ? trim( (string) $row[5] ) : '',
			'notes'   => isset( $row[6] ) ? trim( (string) $row[6] ) : '',
			'office'  => $office_name,
		];
	}

	/**
	 * Merge two agent records, preferring non-empty values from $new.
	 */
	private static function merge_agent( array $existing, array $new ): array {
		foreach ( $new as $k => $v ) {
			if ( is_string( $v ) && '' !== $v ) {
				$existing[ $k ] = $v;
			} elseif ( ! isset( $existing[ $k ] ) ) {
				$existing[ $k ] = $v;
			}
		}
		return $existing;
	}

	/**
	 * Convert Sheets API tabColor (red/green/blue floats 0-1) to "#RRGGBB".
	 */
	private static function rgb_to_hex( float $r, float $g, float $b ): string {
		$ri = max( 0, min( 255, (int) round( $r * 255 ) ) );
		$gi = max( 0, min( 255, (int) round( $g * 255 ) ) );
		$bi = max( 0, min( 255, (int) round( $b * 255 ) ) );
		return strtoupper( sprintf( '#%02X%02X%02X', $ri, $gi, $bi ) );
	}

	// ---------------------------------------------------------------------
	// User helpers
	// ---------------------------------------------------------------------

	/**
	 * Suppress all wp_mail sends (used during sync to avoid welcome-email storms).
	 */
	private static function suppress_emails(): void {
		self::$suppress_mail = true;
		add_filter( 'pre_wp_mail', [ __CLASS__, 'maybe_drop_mail' ], 99, 1 );
	}

	/**
	 * Re-enable wp_mail after sync.
	 */
	private static function unsuppress_emails(): void {
		self::$suppress_mail = false;
		remove_filter( 'pre_wp_mail', [ __CLASS__, 'maybe_drop_mail' ], 99 );
	}

	/**
	 * pre_wp_mail short-circuit. Returns false to silently drop the mail when
	 * suppression is active, otherwise null to let WP proceed normally.
	 *
	 * @param mixed $short_circuit Existing short-circuit value.
	 * @return mixed
	 */
	public static function maybe_drop_mail( $short_circuit ) {
		return self::$suppress_mail ? false : $short_circuit;
	}

	/**
	 * Resolve an existing user_id by user_meta lookup.
	 *
	 * @param string $meta_key   Meta key to query.
	 * @param string $meta_value Meta value to match.
	 * @return int User ID or 0.
	 */
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

	/**
	 * Resolve an existing user from an agent record using SAFE matching.
	 *
	 * Match priority:
	 *   1. DRE license  (frs_dre_license user_meta)
	 *   2. NMLS         (frs_nmls user_meta)
	 *   3. Email-create-only (only if email isn't already on a different user)
	 *
	 * If none of those produce a match (or attempting to create would conflict
	 * with an existing email belonging to a different user) the record is
	 * SKIPPED and 0 is returned. We never call wp_update_user on existing users.
	 *
	 * @param array  $agent       Agent record.
	 * @param string $member_type Resolved BP member type (kept for hook parity; unused here).
	 * @param string $matched_how Out param: 'created' | 'dre' | 'nmls' | 'skipped_no_match' | 'skipped_email_conflict'.
	 * @param array  $stats       Stats array (mutated for skip / match counts).
	 * @return int User ID, or 0 on skip/failure.
	 */
	private static function ensure_user( array $agent, string $member_type, string &$matched_how = '', array &$stats = [] ): int {
		unset( $member_type ); // role/type assignment handled in the per-user loop.

		$dre   = isset( $agent['dre'] )   ? trim( (string) $agent['dre'] )   : '';
		$nmls  = isset( $agent['nmls'] )  ? trim( (string) $agent['nmls'] )  : '';
		$email = isset( $agent['email'] ) ? strtolower( trim( (string) $agent['email'] ) ) : '';

		// 1. DRE match
		if ( '' !== $dre ) {
			$found = self::find_user_by_meta( 'frs_dre_license', $dre );
			if ( $found ) {
				$matched_how = 'dre';
				if ( is_array( $stats ) && isset( $stats['users_matched_by_dre'] ) ) {
					$stats['users_matched_by_dre']++;
				}
				return $found;
			}
		}

		// 2. NMLS match
		if ( '' !== $nmls ) {
			$found = self::find_user_by_meta( 'frs_nmls', $nmls );
			if ( $found ) {
				$matched_how = 'nmls';
				if ( is_array( $stats ) && isset( $stats['users_matched_by_nmls'] ) ) {
					$stats['users_matched_by_nmls']++;
				}
				return $found;
			}
		}

		// 3. Try to create — only if we have a valid email AND that email
		//    isn't already on a different user (which would be the duplicate-DRE
		//    case we want to surface as a warning, not silently overwrite).
		if ( '' === $email || ! is_email( $email ) ) {
			$matched_how = 'skipped_no_match';
			if ( is_array( $stats ) && isset( $stats['users_skipped_no_match'] ) ) {
				$stats['users_skipped_no_match']++;
			}
			error_log( sprintf(
				'[GoogleRosterSync] missing identifier, skipped: dre="%s" nmls="%s" email="%s"',
				$dre,
				$nmls,
				$email
			) );
			return 0;
		}

		$existing_by_email = get_user_by( 'email', $email );
		if ( $existing_by_email ) {
			// Email already in the system but DRE/NMLS didn't match this row.
			// Two real-world causes: (a) the existing user predates DRE capture, or
			// (b) the sheet has a row for somebody else who shares this email.
			// Either way, do NOT mutate that user — log a warning and skip.
			$matched_how = 'skipped_email_conflict';
			if ( is_array( $stats ) && isset( $stats['users_skipped_email_conflict'] ) ) {
				$stats['users_skipped_email_conflict']++;
			}
			error_log( sprintf(
				'[GoogleRosterSync] potential duplicate, skipped: email=%s existing_user=%d sheet_dre="%s" sheet_nmls="%s"',
				$email,
				(int) $existing_by_email->ID,
				$dre,
				$nmls
			) );
			return 0;
		}

		// Safe to create a brand new user.
		$base_login = sanitize_user( current( explode( '@', $email ) ), true );
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
			error_log( sprintf( '[GoogleRosterSync] wp_create_user failed for %s: %s', $email, $user_id->get_error_message() ) );
			return 0;
		}

		// On a freshly created user it is safe to populate display name once.
		// We deliberately skip wp_update_user on existing matched users.
		$first   = isset( $agent['first'] )   ? (string) $agent['first']   : '';
		$last    = isset( $agent['last'] )    ? (string) $agent['last']    : '';
		$display = isset( $agent['display'] ) ? (string) $agent['display'] : '';
		if ( '' === $display ) {
			$display = trim( $first . ' ' . $last );
		}
		$update = [ 'ID' => (int) $user_id ];
		if ( '' !== $first )   { $update['first_name']   = $first; }
		if ( '' !== $last )    { $update['last_name']    = $last; }
		if ( '' !== $display ) { $update['display_name'] = $display; }
		if ( count( $update ) > 1 ) {
			wp_update_user( $update );
		}

		$matched_how = 'created';
		return (int) $user_id;
	}

	/**
	 * Safely update a user_meta field.
	 *
	 * - If the user is brand new OR the field is currently empty, write the
	 *   sheet value as the primary meta value.
	 * - If the existing primary value matches (after normalization), no-op.
	 * - Otherwise the existing primary is preserved and the sheet value is
	 *   appended to a JSON-encoded "alt history" list at frs_alt_<basename>.
	 *
	 * @param int    $user_id     Target user.
	 * @param string $field       Primary meta key.
	 * @param string $sheet_value New value from the sheet.
	 * @param bool   $is_new_user True if the user was just created this run.
	 * @param array  $stats       Stats array (mutated for append counts).
	 */
	private static function safe_meta_update( int $user_id, string $field, string $sheet_value, bool $is_new_user, array &$stats = [] ): void {
		if ( '' === $sheet_value ) {
			return;
		}

		// frs_office is sync-managed and intentionally allowed to overwrite —
		// the per-user loop handles it directly, so we just bail here defensively.
		if ( 'frs_office' === $field ) {
			return;
		}

		$current = get_user_meta( $user_id, $field, true );

		if ( $is_new_user || '' === $current || null === $current ) {
			update_user_meta( $user_id, $field, $sheet_value );
			return;
		}

		if ( self::normalize_for_compare( $field, (string) $current ) === self::normalize_for_compare( $field, $sheet_value ) ) {
			// Already the same value, nothing to do.
			return;
		}

		// Primary differs from sheet — append to alts list rather than overwrite.
		$alt_field = self::alt_field_name( $field );
		if ( '' === $alt_field ) {
			// No alt-bucket configured for this field; preserve primary, no-op.
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

		if ( ! in_array( $sheet_value, $alts, true ) ) {
			$alts[] = $sheet_value;
			update_user_meta( $user_id, $alt_field, wp_json_encode( $alts ) );
			if ( is_array( $stats ) && isset( $stats['meta_appended_to_alts'] ) ) {
				$stats['meta_appended_to_alts']++;
			}
		}
	}

	/**
	 * Map a primary meta key to its alt-history bucket key.
	 *
	 * Returns '' for fields that have no alt bucket (sync-managed or
	 * not-tracked); callers should treat empty as "skip alt write".
	 */
	private static function alt_field_name( string $field ): string {
		switch ( $field ) {
			case 'first_name':
				return 'frs_alt_first_names';
			case 'last_name':
				return 'frs_alt_last_names';
			case 'frs_phone_number':
				return 'frs_alt_phones';
			case 'frs_mobile_number':
				return 'frs_alt_mobile_phones';
			case 'frs_dre_license':
				return 'frs_alt_dre_licenses';
			case 'frs_nmls':
				return 'frs_alt_nmls';
			case 'frs_agent_id':
				return 'frs_alt_agent_ids';
			default:
				return '';
		}
	}

	/**
	 * Normalize a meta value for equality comparison.
	 *
	 * Phone-like fields are stripped of non-alphanumerics so "(555) 123-4567"
	 * compares equal to "5551234567". Everything else is lowercased + trimmed.
	 */
	private static function normalize_for_compare( string $field, string $value ): string {
		$value = trim( $value );
		if ( 'frs_phone_number' === $field || 'frs_mobile_number' === $field ) {
			return preg_replace( '/[^A-Za-z0-9]/', '', $value );
		}
		return strtolower( $value );
	}

	/**
	 * Ensure a BuddyPress group exists, creating it if necessary.
	 *
	 * @param string $name      Group name.
	 * @param string $type      Group type slug ('region' or 'office').
	 * @param int    $parent_id Parent group ID (0 for top-level).
	 * @param array  $stats     Stats array (mutated for created counts).
	 * @return int Group ID, or 0 on failure.
	 */
	private static function ensure_group( string $name, string $type, int $parent_id, array &$stats ): int {
		if ( ! function_exists( 'groups_create_group' ) || ! class_exists( '\BP_Groups_Group' ) ) {
			return 0;
		}

		$slug = groups_check_slug( sanitize_title( $name ) );

		$existing_id = (int) \BP_Groups_Group::group_exists( $slug );
		if ( ! $existing_id ) {
			// Fall back to a name-based search to avoid creating duplicates with
			// suffixed slugs ("foo-2") when the slug-canonicalized form differs.
			$existing_id = (int) \BP_Groups_Group::get_id_from_slug( sanitize_title( $name ) );
		}

		if ( $existing_id ) {
			$group_id = $existing_id;
		} else {
			$group_id = groups_create_group( [
				'creator_id'   => 1,
				'name'         => $name,
				'slug'         => $slug,
				'description'  => sprintf( 'FRS %s group: %s', $type, $name ),
				'status'       => 'private',
				'parent_id'    => $parent_id,
				'enable_forum' => false,
			] );
			if ( ! $group_id || is_wp_error( $group_id ) ) {
				error_log( sprintf( '[GoogleRosterSync] groups_create_group failed for %s "%s"', $type, $name ) );
				return 0;
			}
			$group_id = (int) $group_id;

			if ( 'region' === $type ) {
				$stats['regions_created']++;
			} elseif ( 'office' === $type ) {
				$stats['offices_created']++;
			}
		}

		// Always ensure parent_id is set correctly (offices may have been
		// created before their region existed in a prior run).
		if ( $parent_id > 0 ) {
			$grp = groups_get_group( $group_id );
			if ( $grp && (int) ( $grp->parent_id ?? 0 ) !== $parent_id ) {
				groups_edit_base_group_details( [
					'group_id'   => $group_id,
					'parent_id'  => $parent_id,
					'name'       => $name,
					'slug'       => $slug,
					'description'=> $grp->description,
				] );
			}
		}

		// Set group type.
		if ( function_exists( 'bp_groups_set_group_type' ) ) {
			bp_groups_set_group_type( $group_id, $type );
		}

		return $group_id;
	}

	/**
	 * Member-type priority: staff > office=sales_associate > LO-only.
	 */
	private static function determine_member_type( bool $in_office, bool $in_loan_officers, bool $in_staff ): string {
		if ( $in_staff ) {
			return 'staff';  // staff overrides everything
		}
		if ( $in_office ) {
			return 'sales_associate';  // office presence = agent first
		}
		if ( $in_loan_officers ) {
			return 'loan_originator';  // pure LO
		}
		return 'sales_associate';  // fallback
	}
}
