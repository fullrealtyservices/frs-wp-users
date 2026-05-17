<?php
/**
 * BuddyPress XProfile Backfill
 *
 * WP-CLI command that backfills BuddyPress XProfile data from existing
 * frs_* user_meta keys. Per-user write is delegated to
 * \FRSUsers\Integrations\BuddyPressSync::sync_user.
 *
 * @package FRSUsers\Integrations
 */

namespace FRSUsers\Integrations;

defined( 'ABSPATH' ) || exit;

class BuddyPressBackfill {

	/**
	 * Register the WP-CLI command (CLI-only).
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		\WP_CLI::add_command( 'frs-users bp-backfill', array( __CLASS__, 'cli_backfill' ) );
	}

	/**
	 * Backfill BuddyPress XProfile data from frs_* user_meta keys.
	 *
	 * ## OPTIONS
	 *
	 * [--user_id=<id>]
	 * : Only backfill the specified user.
	 *
	 * [--limit=<n>]
	 * : Limit to N users.
	 *
	 * [--dry-run]
	 * : Don't actually write — report what would happen.
	 *
	 * [--reset-version]
	 * : Delete the schema-version option, forcing XProfile schema reinstall on next bp_init.
	 *
	 * ## EXAMPLES
	 *
	 *     wp frs-users bp-backfill
	 *     wp frs-users bp-backfill --user_id=2
	 *     wp frs-users bp-backfill --limit=10 --dry-run
	 *     wp frs-users bp-backfill --reset-version
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Flag args.
	 * @return void
	 */
	public static function cli_backfill( array $args, array $assoc_args ): void {
		$user_id_arg = isset( $assoc_args['user_id'] ) ? (int) $assoc_args['user_id'] : 0;
		$limit       = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;
		$dry_run     = isset( $assoc_args['dry-run'] );
		$reset_ver   = isset( $assoc_args['reset-version'] );

		// Optionally reset the schema-version site option first so XProfile
		// schema is reinstalled on the next bp_init.
		if ( $reset_ver ) {
			if ( is_multisite() ) {
				delete_site_option( 'frs_bp_xprofile_schema_version' );
			} else {
				delete_option( 'frs_bp_xprofile_schema_version' );
			}
			\WP_CLI::log( 'Deleted frs_bp_xprofile_schema_version — XProfile schema will reinstall on next bp_init.' );
		}

		// Pre-flight: BP XProfile must be loaded.
		if ( ! function_exists( 'xprofile_set_field_data' ) ) {
			\WP_CLI::error( 'BuddyPress XProfile is not loaded (xprofile_set_field_data() missing). Activate BuddyPress with the XProfile component first.' );
		}

		// Pre-flight: BuddyPressSync::sync_user must exist.
		if ( ! class_exists( '\FRSUsers\Integrations\BuddyPressSync' )
			|| ! method_exists( '\FRSUsers\Integrations\BuddyPressSync', 'sync_user' ) ) {
			\WP_CLI::error( '\FRSUsers\Integrations\BuddyPressSync::sync_user() does not exist yet — cannot backfill.' );
		}

		// Build the user list.
		if ( $user_id_arg > 0 ) {
			$user_ids = array( $user_id_arg );
		} else {
			$query_args = array(
				'fields' => 'ID',
			);
			if ( $limit > 0 ) {
				$query_args['number'] = $limit;
			}
			if ( is_multisite() ) {
				$query_args['blog_id'] = 0;
			}
			$user_ids = get_users( $query_args );
		}

		$total = count( $user_ids );

		if ( $total === 0 ) {
			\WP_CLI::warning( 'No users found to backfill.' );
			return;
		}

		\WP_CLI::log( sprintf(
			'Backfilling BP XProfile for %d user(s)%s%s.',
			$total,
			$dry_run ? ' (DRY RUN)' : '',
			$limit > 0 && $user_id_arg === 0 ? sprintf( ' [limit=%d]', $limit ) : ''
		) );

		$progress = null;
		if ( $total > 10 ) {
			$progress = \WP_CLI\Utils\make_progress_bar( 'Backfilling XProfile', $total );
		}

		$total_synced  = 0;
		$total_skipped = 0;
		$total_errors  = 0;
		$processed     = 0;

		foreach ( $user_ids as $uid ) {
			$uid = (int) $uid;
			$processed++;

			if ( $dry_run ) {
				// Dry-run path: count what WOULD sync without writing.
				// We invoke sync_user only when not dry, so for dry-run we
				// approximate by inspecting frs_* meta keys present on the user.
				$meta = get_user_meta( $uid );
				$frs_keys = 0;
				if ( is_array( $meta ) ) {
					foreach ( array_keys( $meta ) as $key ) {
						if ( strpos( (string) $key, 'frs_' ) === 0 ) {
							$frs_keys++;
						}
					}
				}
				\WP_CLI::log( sprintf(
					'  [DRY RUN] user %d — would consider %d frs_* meta key(s) for sync',
					$uid,
					$frs_keys
				) );
				$total_synced += $frs_keys;
			} else {
				$result = \FRSUsers\Integrations\BuddyPressSync::sync_user( $uid );

				$synced  = isset( $result['synced'] ) ? (int) $result['synced'] : 0;
				$skipped = isset( $result['skipped_no_mapping'] ) ? (int) $result['skipped_no_mapping'] : 0;
				$errors  = ( isset( $result['errors'] ) && is_array( $result['errors'] ) ) ? count( $result['errors'] ) : 0;

				$total_synced  += $synced;
				$total_skipped += $skipped;
				$total_errors  += $errors;

				\WP_CLI::log( sprintf(
					'  user %d — synced=%d skipped_no_mapping=%d errors=%d',
					$uid,
					$synced,
					$skipped,
					$errors
				) );

				if ( $errors > 0 ) {
					foreach ( (array) $result['errors'] as $err ) {
						\WP_CLI::warning( sprintf( '    user %d: %s', $uid, is_string( $err ) ? $err : wp_json_encode( $err ) ) );
					}
				}
			}

			if ( $progress ) {
				$progress->tick();
			}
		}

		if ( $progress ) {
			$progress->finish();
		}

		\WP_CLI::log( '' );
		\WP_CLI::log( '=== Totals ===' );
		\WP_CLI::log( sprintf( 'Users processed:   %d', $processed ) );
		\WP_CLI::log( sprintf( 'Fields synced:     %d', $total_synced ) );
		\WP_CLI::log( sprintf( 'Skipped (no map):  %d', $total_skipped ) );
		\WP_CLI::log( sprintf( 'Errors:            %d', $total_errors ) );

		\WP_CLI::success( $dry_run ? 'Dry-run backfill complete.' : 'BP XProfile backfill complete.' );
	}
}
