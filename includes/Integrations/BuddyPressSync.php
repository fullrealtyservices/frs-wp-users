<?php
/**
 * BuddyPress XProfile Sync
 *
 * One-way sync from `frs_*` user_meta keys to BuddyPress XProfile fields.
 * Listens on `updated_user_meta` and `added_user_meta`. Recursion-guarded so
 * our own xprofile writes don't trigger infinite loops. Skips any meta key
 * that has no `frs_bp_xprofile_map_*` site option mapping. Coerces values
 * for multiselect fields (JSON / CSV / scalar -> array).
 *
 * @package FRSUsers\Integrations
 */

namespace FRSUsers\Integrations;

defined( 'ABSPATH' ) || exit;

class BuddyPressSync {

	/**
	 * Recursion guard. WP-CLI / PHP-FPM are single-threaded per request so a
	 * static flag is sufficient — no instance state needed.
	 *
	 * @var bool
	 */
	private static $writing = false;

	/**
	 * Register hooks. No-op when BP XProfile isn't loaded.
	 */
	public static function init(): void {
		if ( ! function_exists( 'xprofile_set_field_data' ) ) {
			return;
		}

		add_action( 'updated_user_meta', array( __CLASS__, 'on_user_meta_updated' ), 10, 4 );
		add_action( 'added_user_meta', array( __CLASS__, 'on_user_meta_updated' ), 10, 4 );
	}

	/**
	 * Handle a single user_meta write and mirror it to XProfile.
	 *
	 * @param int    $meta_id    Meta row ID (unused).
	 * @param int    $user_id    User ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New meta value.
	 */
	public static function on_user_meta_updated( $meta_id, $user_id, $meta_key, $meta_value ): void {
		if ( self::$writing ) {
			return;
		}

		if ( strpos( $meta_key, 'frs_' ) !== 0 ) {
			return;
		}

		$field_id = (int) get_site_option( 'frs_bp_xprofile_map_' . $meta_key, 0 );
		if ( ! $field_id ) {
			return;
		}

		$coerced_value = self::coerce_value( $field_id, $meta_value );

		/**
		 * Filter the value about to be written to a BP XProfile field.
		 *
		 * @param mixed  $coerced_value Value after type coercion.
		 * @param int    $field_id      XProfile field ID.
		 * @param string $meta_key      Source user_meta key.
		 * @param int    $user_id       User ID.
		 */
		$coerced_value = apply_filters( 'frs_bp_sync_value', $coerced_value, $field_id, $meta_key, $user_id );

		self::$writing = true;
		try {
			xprofile_set_field_data( $field_id, $user_id, $coerced_value );
		} finally {
			self::$writing = false;
		}
	}

	/**
	 * Backfill helper — mirror every mapped `frs_*` meta key for one user.
	 *
	 * @param int $user_id User ID.
	 * @return array{synced:int,skipped_no_mapping:int,errors:array<int,string>}
	 */
	public static function sync_user( int $user_id ): array {
		$result = array(
			'synced'             => 0,
			'skipped_no_mapping' => 0,
			'errors'             => array(),
		);

		if ( ! function_exists( 'xprofile_set_field_data' ) ) {
			$result['errors'][] = 'BuddyPress XProfile is not loaded.';
			return $result;
		}

		$all_meta = get_user_meta( $user_id );
		if ( ! is_array( $all_meta ) ) {
			return $result;
		}

		foreach ( $all_meta as $meta_key => $values ) {
			if ( strpos( $meta_key, 'frs_' ) !== 0 ) {
				continue;
			}

			$field_id = (int) get_site_option( 'frs_bp_xprofile_map_' . $meta_key, 0 );
			if ( ! $field_id ) {
				++$result['skipped_no_mapping'];
				continue;
			}

			$raw_value     = is_array( $values ) ? reset( $values ) : $values;
			$coerced_value = self::coerce_value( $field_id, $raw_value );

			/** This filter is documented in includes/Integrations/BuddyPressSync.php */
			$coerced_value = apply_filters( 'frs_bp_sync_value', $coerced_value, $field_id, $meta_key, $user_id );

			self::$writing = true;
			try {
				$ok = xprofile_set_field_data( $field_id, $user_id, $coerced_value );
				if ( $ok ) {
					++$result['synced'];
				} else {
					$result['errors'][] = sprintf( 'Failed to write field %d for meta key %s', $field_id, $meta_key );
				}
			} catch ( \Exception $e ) {
				$result['errors'][] = sprintf( 'Exception writing field %d for meta key %s: %s', $field_id, $meta_key, $e->getMessage() );
			} finally {
				self::$writing = false;
			}
		}

		return $result;
	}

	/**
	 * Coerce a raw user_meta value into the shape the target XProfile field expects.
	 *
	 * Multiselect fields require arrays — accept arrays, JSON strings, CSV strings,
	 * or scalars. Everything else is cast to string.
	 *
	 * @param int   $field_id  XProfile field ID.
	 * @param mixed $raw_value Value from user_meta.
	 * @return mixed
	 */
	private static function coerce_value( int $field_id, $raw_value ) {
		$field_type = '';
		if ( function_exists( 'xprofile_get_field' ) ) {
			$field = xprofile_get_field( $field_id );
			if ( $field && isset( $field->type ) ) {
				$field_type = (string) $field->type;
			}
		}

		if ( 'multiselectbox' === $field_type ) {
			if ( is_array( $raw_value ) ) {
				return array_values( $raw_value );
			}

			if ( is_string( $raw_value ) ) {
				$trimmed = ltrim( $raw_value );
				if ( '' !== $trimmed && ( '[' === $trimmed[0] || '{' === $trimmed[0] ) ) {
					$decoded = json_decode( $raw_value, true );
					if ( is_array( $decoded ) ) {
						return array_values( $decoded );
					}
				}

				if ( false !== strpos( $raw_value, ',' ) ) {
					return array_map( 'trim', explode( ',', $raw_value ) );
				}

				return array( $raw_value );
			}

			return array( $raw_value );
		}

		if ( is_scalar( $raw_value ) ) {
			return (string) $raw_value;
		}

		return is_null( $raw_value ) ? '' : (string) wp_json_encode( $raw_value );
	}
}
