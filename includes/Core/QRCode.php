<?php
/**
 * QR Code Generator
 *
 * Generates QR codes for profiles and uploads to R2 CDN for global sync.
 *
 * @package FRSUsers
 * @subpackage Core
 * @since 1.0.0
 */

namespace FRSUsers\Core;

use FRSUsers\Models\Profile;

/**
 * Class QRCode
 *
 * Handles QR code generation and CDN storage.
 * QR codes are generated on the hub and synced globally via webhook.
 *
 * @package FRSUsers\Core
 */
class QRCode {

	/**
	 * Meta key for QR code CDN URL.
	 *
	 * @var string
	 */
	const META_KEY = 'frs_qr_code_data';

	/**
	 * R2 object key prefix.
	 *
	 * @var string
	 */
	const R2_PREFIX = 'qrcodes';

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public static function init() {
		// Auto-generate QR codes when profiles are saved on the hub
		if ( Roles::is_profile_editing_enabled() ) {
			add_action( 'frs_profile_saved', array( __CLASS__, 'maybe_generate_qr_code' ), 15, 2 );
		}
	}

	/**
	 * Generate QR code for a user and upload to CDN.
	 *
	 * @param int         $user_id User ID.
	 * @param string|null $slug    Profile slug. If null, will be fetched from user meta.
	 * @param bool        $force   Force regeneration even if exists.
	 * @return string|false CDN URL on success, false on failure.
	 */
	public static function generate( $user_id, $slug = null, $force = false ) {
		// Check for existing QR code unless forcing
		if ( ! $force ) {
			$existing = get_user_meta( $user_id, self::META_KEY, true );
			if ( ! empty( $existing ) ) {
				return $existing;
			}
		}

		// Get profile slug
		if ( ! $slug ) {
			$slug = get_user_meta( $user_id, 'frs_profile_slug', true );
			if ( ! $slug ) {
				$user = get_userdata( $user_id );
				$slug = $user ? $user->user_nicename : null;
			}
		}

		if ( ! $slug ) {
			error_log( sprintf( 'FRS QRCode: Cannot generate for user %d - no slug', $user_id ) );
			return false;
		}

		// Build QR content URL - always use hub domain for global consistency
		$qr_content_url = self::get_qr_landing_url( $slug );

		// Generate SVG using Node.js script
		$svg = self::generate_svg( $qr_content_url );
		if ( ! $svg ) {
			return false;
		}

		// Upload to R2 CDN if enabled
		if ( R2Storage::is_enabled() ) {
			$cdn_url = self::upload_to_cdn( $slug, $svg );
			if ( $cdn_url ) {
				update_user_meta( $user_id, self::META_KEY, $cdn_url );
				return $cdn_url;
			}
		}

		// Fallback: save locally
		$local_url = self::save_locally( $slug, $svg );
		if ( $local_url ) {
			update_user_meta( $user_id, self::META_KEY, $local_url );
			return $local_url;
		}

		return false;
	}

	/**
	 * Generate SVG QR code using chillerlan/php-qrcode library.
	 *
	 * @param string $content URL or text to encode in QR.
	 * @return string|false SVG content or false on failure.
	 */
	private static function generate_svg( $content ) {
		// Check if the PHP QR code library is available
		$autoload = FRS_USERS_DIR . 'vendor/autoload.php';
		if ( ! file_exists( $autoload ) ) {
			error_log( 'FRS QRCode: Composer autoload not found. Run composer install.' );
			return false;
		}

		require_once $autoload;

		if ( ! class_exists( '\chillerlan\QRCode\QRCode' ) ) {
			error_log( 'FRS QRCode: chillerlan/php-qrcode library not installed.' );
			return false;
		}

		try {
			$options = new \chillerlan\QRCode\QROptions( [
				'outputType'          => \chillerlan\QRCode\Output\QROutputInterface::MARKUP_SVG,
				'outputBase64'        => false,
				'eccLevel'            => \chillerlan\QRCode\Common\EccLevel::M,
				'addQuietzone'        => false,
				'drawCircularModules' => true,
				'circleRadius'        => 0.4,
				'svgDefs'             => '
					<linearGradient id="qrGrad" x1="0%" y1="0%" x2="100%" y2="100%">
						<stop offset="0%" style="stop-color:#2dd4da"/>
						<stop offset="100%" style="stop-color:#2563eb"/>
					</linearGradient>
				',
				'moduleValues'        => [
					// Dark modules use gradient
					\chillerlan\QRCode\Data\QRMatrix::M_DATA_DARK      => 'url(#qrGrad)',
					\chillerlan\QRCode\Data\QRMatrix::M_FINDER_DARK    => 'url(#qrGrad)',
					\chillerlan\QRCode\Data\QRMatrix::M_ALIGNMENT_DARK => 'url(#qrGrad)',
					\chillerlan\QRCode\Data\QRMatrix::M_TIMING_DARK    => 'url(#qrGrad)',
					\chillerlan\QRCode\Data\QRMatrix::M_FORMAT_DARK    => 'url(#qrGrad)',
					\chillerlan\QRCode\Data\QRMatrix::M_VERSION_DARK   => 'url(#qrGrad)',
					\chillerlan\QRCode\Data\QRMatrix::M_DARKMODULE     => 'url(#qrGrad)',
				],
			] );

			$qrcode = new \chillerlan\QRCode\QRCode( $options );
			$svg    = $qrcode->render( $content );

			return $svg;

		} catch ( \Exception $e ) {
			error_log( sprintf( 'FRS QRCode: Generation failed - %s', $e->getMessage() ) );
			return false;
		}
	}

	/**
	 * Upload QR code SVG to R2 CDN.
	 *
	 * @param string $slug Profile slug.
	 * @param string $svg  SVG content.
	 * @return string|false CDN URL or false on failure.
	 */
	private static function upload_to_cdn( $slug, $svg ) {
		$api_key = get_option( R2Storage::OPTION_API_KEY, '' );
		if ( empty( $api_key ) ) {
			return false;
		}

		$object_key = self::R2_PREFIX . '/' . sanitize_file_name( $slug ) . '.svg';
		$upload_url = R2Storage::get_cdn_url() . '/upload';

		$response = wp_remote_post( $upload_url, array(
			'timeout' => 30,
			'headers' => array(
				'X-API-Key'    => $api_key,
				'X-Filename'   => $object_key,
				'Content-Type' => 'image/svg+xml',
			),
			'body'    => $svg,
		) );

		if ( is_wp_error( $response ) ) {
			error_log( sprintf( 'FRS QRCode: Upload failed - %s', $response->get_error_message() ) );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['success'] ) ) {
			error_log( sprintf( 'FRS QRCode: Upload failed - HTTP %d', $code ) );
			return false;
		}

		return $body['url'] ?? R2Storage::get_url( $object_key );
	}

	/**
	 * Save QR code SVG locally as fallback.
	 *
	 * @param string $slug Profile slug.
	 * @param string $svg  SVG content.
	 * @return string|false Local URL or false on failure.
	 */
	private static function save_locally( $slug, $svg ) {
		$upload_dir  = wp_upload_dir();
		$qr_dir      = $upload_dir['basedir'] . '/frs-qr-codes';
		$qr_url_base = $upload_dir['baseurl'] . '/frs-qr-codes';

		if ( ! file_exists( $qr_dir ) ) {
			wp_mkdir_p( $qr_dir );
		}

		$filename = sanitize_file_name( $slug ) . '.svg';
		$filepath = $qr_dir . '/' . $filename;

		if ( file_put_contents( $filepath, $svg ) ) {
			return $qr_url_base . '/' . $filename;
		}

		return false;
	}

	/**
	 * Get the redirect URL for QR codes.
	 *
	 * Uses /qr/{slug}/ URLs which redirect to the actual profile.
	 * This makes QR codes "dynamic" - we can change where profiles live
	 * without regenerating QR codes.
	 *
	 * @param string $slug Profile slug.
	 * @return string QR redirect URL.
	 */
	public static function get_qr_landing_url( $slug ) {
		// Use configurable redirect URL - change this setting to redirect ALL QR codes
		// without regenerating them (truly dynamic QR codes)
		$redirect_url = get_option( 'frs_qr_redirect_url', '' );
		if ( empty( $redirect_url ) ) {
			// Fallback to legacy option or default
			$redirect_url = get_option( 'frs_public_site_url', 'https://21stcenturylending.com' );
		}
		return rtrim( $redirect_url, '/' ) . '/qr/' . $slug . '/';
	}

	/**
	 * Check if URL is a CDN URL.
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	private static function is_cdn_url( $url ) {
		$cdn_url = R2Storage::get_cdn_url();
		return $cdn_url && strpos( $url, $cdn_url ) === 0;
	}

	/**
	 * Hook: Auto-generate QR code when profile is saved on hub.
	 *
	 * Only generates if no QR code exists. Never overwrites existing QR codes
	 * to preserve consistency across synced sites.
	 *
	 * @param int   $profile_id   Profile/user ID.
	 * @param array $profile_data Profile data that was saved.
	 * @return void
	 */
	public static function maybe_generate_qr_code( $profile_id, $profile_data ) {
		// Never overwrite existing QR codes - they're synced across sites
		$existing = get_user_meta( $profile_id, self::META_KEY, true );
		if ( ! empty( $existing ) ) {
			return;
		}

		$profile = Profile::find( $profile_id );
		if ( ! $profile || ! $profile->is_active ) {
			return;
		}

		// Get profile slug
		$slug = $profile->profile_slug;
		if ( ! $slug && $profile->user_id ) {
			$user = get_userdata( $profile->user_id );
			$slug = $user ? $user->user_nicename : null;
		}

		if ( ! $slug ) {
			return;
		}

		// Generate and upload
		$cdn_url = self::generate( $profile_id, $slug, true );

		if ( $cdn_url ) {
			error_log( sprintf(
				'FRS QRCode: Generated QR for user %d (%s) → %s',
				$profile_id,
				$slug,
				$cdn_url
			) );

			// ProfileSync::send_webhook_on_save (priority 10) already fired
			// with the pre-generation snapshot, so a brand-new profile's
			// first sync goes out without a QR code. Send one more webhook
			// with the QR code merged in so the satellite gets it right away
			// instead of waiting on some unrelated later save. Calling the
			// sender directly (rather than re-firing frs_profile_saved)
			// avoids duplicating the *other* seven hooks on that action
			// (activity log, CRM syncs, notifications, etc).
			if ( method_exists( '\FRSUsers\Core\ProfileSync', 'send_webhook_on_save' ) ) {
				$profile_data['qr_code_data'] = $cdn_url;
				\FRSUsers\Core\ProfileSync::send_webhook_on_save( $profile_id, $profile_data );
			}
		}
	}

	/**
	 * Extract profile slug from QR code URL.
	 *
	 * @param string $url QR code URL.
	 * @return string|null Slug or null.
	 */
	private static function extract_slug_from_url( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( $path ) {
			$filename = basename( $path );
			return pathinfo( $filename, PATHINFO_FILENAME );
		}
		return null;
	}

	/**
	 * Bulk generate QR codes for multiple users.
	 *
	 * @param array $user_ids Array of user IDs.
	 * @param bool  $force    Force regeneration.
	 * @return array Results with 'success', 'failed', 'skipped' counts.
	 */
	public static function bulk_generate( $user_ids, $force = false ) {
		$results = array(
			'success' => 0,
			'failed'  => 0,
			'skipped' => 0,
		);

		foreach ( $user_ids as $user_id ) {
			$existing = get_user_meta( $user_id, self::META_KEY, true );

			if ( ! $force && $existing && self::is_cdn_url( $existing ) ) {
				++$results['skipped'];
				continue;
			}

			$cdn_url = self::generate( $user_id, null, $force );

			if ( $cdn_url ) {
				++$results['success'];
			} else {
				++$results['failed'];
			}
		}

		return $results;
	}

	/**
	 * Get QR code URL for a user.
	 *
	 * @param int $user_id User ID.
	 * @return string|null QR code URL or null.
	 */
	public static function get_url( $user_id ) {
		return get_user_meta( $user_id, self::META_KEY, true ) ?: null;
	}

	/**
	 * Delete QR code for a user (from CDN and meta).
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function delete( $user_id ) {
		$existing = get_user_meta( $user_id, self::META_KEY, true );

		if ( $existing && self::is_cdn_url( $existing ) ) {
			// Extract object key and delete from R2
			$cdn_base = R2Storage::get_cdn_url();
			$object_key = str_replace( $cdn_base . '/', '', $existing );
			R2Storage::delete( $object_key );
		}

		return delete_user_meta( $user_id, self::META_KEY );
	}
}
