<?php
/**
 * Welcome Email
 *
 * Sends a branded welcome email to a loan officer the first time their
 * profile becomes complete and public (has an NMLS number and Arrive link).
 * Sent from the hub, switching to the /lending/ blog so it routes through
 * WPO365/Microsoft Graph as experience@fullrealtyservices.com — the main
 * site's SMTP path is not reliable for outbound mail.
 *
 * @package FRSUsers
 * @subpackage Integrations
 * @since   2.5.0
 */

namespace FRSUsers\Integrations;

defined( 'ABSPATH' ) || exit;

class WelcomeEmail {

	/**
	 * Blog ID of the /lending/ subsite (WPO365-configured mail sender).
	 *
	 * @var int
	 */
	const LENDING_BLOG_ID = 2;

	/**
	 * Meta key marking a profile as already welcomed (or exempted).
	 *
	 * @var string
	 */
	const SENT_META_KEY = 'frs_welcome_email_sent';

	/**
	 * Safety backstop: never send to an account older than this, even if the
	 * sent-flag is somehow missing (e.g. a backfill gap). A real new hire's
	 * profile reaches nmls+arrive completeness within this window.
	 *
	 * @var int
	 */
	const MAX_ACCOUNT_AGE_HOURS = 48;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'frs_profile_saved', array( __CLASS__, 'maybe_send' ), 30, 2 );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'frs-users backfill-welcome-email-sent', array( __CLASS__, 'cli_backfill' ) );
		}
	}

	/**
	 * Send the welcome email if this profile just became complete and public
	 * for the first time.
	 *
	 * @param int   $profile_id   Profile/user ID.
	 * @param array $profile_data Profile data from Profile::toArray().
	 * @return void
	 */
	public static function maybe_send( int $profile_id, array $profile_data ): void {
		if ( get_user_meta( $profile_id, self::SENT_META_KEY, true ) ) {
			return;
		}

		$user = get_userdata( $profile_id );
		if ( ! $user || ! in_array( 'loan_officer', (array) $user->roles, true ) ) {
			return;
		}

		// Defense in depth: never send to an account that's been around a
		// while, even without the sent-flag (e.g. a missed backfill).
		$registered_ts = strtotime( $user->user_registered . ' UTC' );
		if ( ! $registered_ts || ( time() - $registered_ts ) > self::MAX_ACCOUNT_AGE_HOURS * HOUR_IN_SECONDS ) {
			update_user_meta( $profile_id, self::SENT_META_KEY, 1 );
			return;
		}

		$nmls   = $profile_data['nmls'] ?? get_user_meta( $profile_id, 'frs_nmls', true );
		$arrive = $profile_data['arrive'] ?? get_user_meta( $profile_id, 'frs_arrive', true );
		if ( empty( $nmls ) || empty( $arrive ) ) {
			return; // Not eligible/public yet — a later save() will re-check.
		}

		$slug        = $profile_data['profile_slug'] ?? $user->user_nicename;
		$first_name  = $profile_data['first_name'] ?? $user->first_name;
		$profile_url = 'https://21stcenturylending.com/lo/' . $slug . '/';
		$hub_url     = home_url( '/' );

		$sent = self::send( $user->user_email, $first_name, $profile_url, $hub_url, $user->user_email );

		if ( $sent ) {
			update_user_meta( $profile_id, self::SENT_META_KEY, time() );
		}
	}

	/**
	 * Manually (re-)send the welcome email for a specific user, optionally
	 * with one-off extra BCC recipients for this send only (the permanent
	 * PERMANENT_BCC is always included regardless).
	 *
	 * @param int      $profile_id User ID.
	 * @param string[] $extra_bcc  Additional BCC recipients for this send only.
	 * @return bool
	 */
	public static function send_manual( int $profile_id, array $extra_bcc = array() ): bool {
		$user = get_userdata( $profile_id );
		if ( ! $user ) {
			return false;
		}

		$profile     = \FRSUsers\Models\Profile::find( $profile_id );
		$slug        = $profile ? $profile->profile_slug : $user->user_nicename;
		$first_name  = $profile ? $profile->first_name : $user->first_name;
		$profile_url = 'https://21stcenturylending.com/lo/' . $slug . '/';
		$hub_url     = home_url( '/' );

		$sent = self::send( $user->user_email, $first_name, $profile_url, $hub_url, $user->user_email, $extra_bcc );

		if ( $sent ) {
			update_user_meta( $profile_id, self::SENT_META_KEY, time() );
		}

		return $sent;
	}

	/**
	 * Always BCC'd on every welcome email, so the team has a running record
	 * of what new agents received.
	 *
	 * @var string
	 */
	const PERMANENT_BCC = 'experience@fullrealtyservices.com';

	/**
	 * @param string   $to           Recipient email.
	 * @param string   $first_name   Recipient first name.
	 * @param string   $profile_url  Live public profile URL.
	 * @param string   $hub_url      myhub21.com login URL.
	 * @param string   $work_email   Recipient's work email (for the login instructions).
	 * @param string[] $extra_bcc    Additional one-off BCC recipients for this send only.
	 * @return bool
	 */
	private static function send( string $to, string $first_name, string $profile_url, string $hub_url, string $work_email, array $extra_bcc = array() ): bool {
		$subject = sprintf( 'Welcome to 21st Century Lending, %s!', $first_name );
		$body    = self::render( $first_name, $profile_url, $hub_url, $work_email );

		$headers   = array();
		$headers[] = 'Content-Type: text/html; charset=UTF-8';
		$headers[] = 'From: 21st Century Lending Experience <experience@fullrealtyservices.com>';
		foreach ( array_merge( array( self::PERMANENT_BCC ), $extra_bcc ) as $bcc ) {
			$headers[] = 'Bcc: ' . $bcc;
		}

		// Site 2 (/lending) is the WPO365-configured sender — routes through
		// Microsoft Graph as experience@fullrealtyservices.com.
		$switched = false;
		if ( is_multisite() && get_current_blog_id() !== self::LENDING_BLOG_ID ) {
			switch_to_blog( self::LENDING_BLOG_ID );
			$switched = true;
		}

		$ok = wp_mail( $to, $subject, $body, $headers );

		if ( $switched ) {
			restore_current_blog();
		}

		if ( ! $ok ) {
			error_log( 'FRS WelcomeEmail: wp_mail failed for ' . $to );
		}

		return $ok;
	}

	/**
	 * Render the email template to a string.
	 *
	 * @param string $first_name  Recipient first name.
	 * @param string $profile_url Live public profile URL.
	 * @param string $hub_url     myhub21.com login URL.
	 * @param string $work_email  Recipient's work email.
	 * @return string
	 */
	private static function render( string $first_name, string $profile_url, string $hub_url, string $work_email ): string {
		ob_start();
		include FRS_USERS_DIR . 'templates/emails/welcome-loan-officer.php';
		return (string) ob_get_clean();
	}

	/**
	 * WP-CLI: wp frs-users backfill-welcome-email-sent
	 *
	 * Marks every existing loan_officer profile as already-welcomed, so this
	 * feature doesn't retroactively email the entire roster once deployed.
	 * Run this once, immediately after deploying.
	 *
	 * @when after_wp_load
	 */
	public static function cli_backfill(): void {
		$users = get_users( array(
			'role__in' => array( 'loan_officer' ),
			'fields'   => array( 'ID' ),
		) );

		$count = 0;
		foreach ( $users as $u ) {
			if ( ! get_user_meta( $u->ID, self::SENT_META_KEY, true ) ) {
				update_user_meta( $u->ID, self::SENT_META_KEY, 1 );
				$count++;
			}
		}

		\WP_CLI::success( sprintf( 'Backfilled %d existing profiles as already-welcomed.', $count ) );
	}
}
