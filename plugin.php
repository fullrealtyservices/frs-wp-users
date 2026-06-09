<?php
/**
 * Main Plugin Class
 *
 * @package FRSUsers
 * @since 1.0.0
 */

use FRSUsers\Core\Avatar;
use FRSUsers\Core\QRCode;
use FRSUsers\Core\ProfileStorage;
use FRSUsers\Core\R2Storage;
use FRSUsers\Core\CLI;
use FRSUsers\Core\ProfileApi;
use FRSUsers\Core\PluginDependencies;
use FRSUsers\Core\Template;
use FRSUsers\Core\TemplateLoader;
use FRSUsers\Core\CORS;
use FRSUsers\Core\EmbeddablePages;
use FRSUsers\Core\SettingsPage;
use FRSUsers\Core\PostComposer;
use FRSUsers\Core\NewsletterTaxonomy;
use FRSUsers\Core\BlockPatterns;
use FRSUsers\Controllers\Shortcodes;
use FRSUsers\Routes\Api;
use FRSUsers\Integrations\FRSSync;
use FRSUsers\Integrations\FluentCRMSync;
use FRSUsers\Integrations\FluentBookingSync;
use FRSUsers\Integrations\FollowUpBoss;
use FRSUsers\Integrations\TwentyCRMSync;
use FRSUsers\Integrations\FluentCRMNotifications;
use FRSUsers\Integrations\FluentFormRouting;
use FRSUsers\Controllers\Blocks;
use FRSUsers\Abilities\AbilitiesRegistry;
use FRSUsers\Core\ActivityRecorder;
use FRSUsers\Traits\Base;

defined( 'ABSPATH' ) || exit;

/**
 * Class FRSUsers
 *
 * The main class for the FRS Users plugin, responsible for initialization and setup.
 *
 * @since 1.0.0
 */
final class FRSUsers {

	use Base;

	/**
	 * Class constructor.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function __construct() {
		// Constants are defined in main plugin file
	}

	/**
	 * Main execution point where the plugin will fire up.
	 *
	 * Initializes necessary components for both admin and frontend.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function init() {
		// Run any pending migrations
		$this->maybe_run_migrations();

		// Ensure WP roles are registered (idempotent — add_role is a no-op if role exists)
		\FRSUsers\Core\Migration::register_roles();

		// Auto-flush rewrite rules on version change
		$stored_version = get_option( 'frs_users_version', '' );
		if ( defined( 'FRS_USERS_VERSION' ) && $stored_version !== FRS_USERS_VERSION ) {
			update_option( 'frs_users_flush_rewrite_rules', true );
			update_option( 'frs_users_version', FRS_USERS_VERSION );
		}

		// Check plugin dependencies (must run first)
		PluginDependencies::get_instance()->init();

		// Initialize core components with error isolation
		$core_components = array(
			'Avatar'         => array( Avatar::class, 'init' ),
			'ProfileStorage' => array( ProfileStorage::class, 'init' ),
			'R2Storage'      => array( R2Storage::class, 'init' ),
			'QRCode'         => array( QRCode::class, 'init' ),
			'Api'            => array( Api::class, 'init' ),
			'ProfileApi'     => array( ProfileApi::class, 'get_instance' ),
			'NetworkSync'    => array( '\FRSUsers\Admin\NetworkSyncPage', 'init' ),
			'Blocks'         => array( Blocks::class, 'init' ),
			'Shortcodes'     => array( Shortcodes::class, 'init' ),
		);

		foreach ( $core_components as $name => $callable ) {
			try {
				$result = call_user_func( $callable );
				// If get_instance was called, also call init
				if ( $name === 'ProfileApi' && $result ) {
					$result->init();
				}
			} catch ( \Throwable $e ) {
				error_log( sprintf( 'FRS Users: Failed to init %s - %s', $name, $e->getMessage() ) );
			}
		}

		// Initialize REST API routes (hook-based, less likely to fail)
		add_action( 'rest_api_init', array( '\FRSUsers\Routes\TwentyCRMApi', 'register_routes' ) );
		add_action( 'rest_api_init', array( '\FRSUsers\Routes\NetworkSyncApi', 'register_routes' ) );
		\FRSUsers\Routes\SyncRoutes::init();

		// Initialize template handler for public profiles (legacy /profile/{slug})
		Template::get_instance()->init();

		// Initialize new template loader for WordPress author pages with URL masking
		TemplateLoader::get_instance()->init();

		// Initialize CORS handler for REST API
		CORS::get_instance()->init();

		// Initialize embeddable pages for Nextcloud integration
		EmbeddablePages::get_instance()->init();

		// Initialize frontend settings page (hub user settings)
		SettingsPage::get_instance()->init();

		// Initialize post composer (minimal block editor for activity tab)
		PostComposer::get_instance()->init();

		// Initialize user tasks (checklist + admin tasks)
		\FRSUsers\Core\UserTasks::get_instance()->init();

		// Register newsletter taxonomy on posts
		NewsletterTaxonomy::init();

		// Register hub block patterns (directory + profile)
		BlockPatterns::init();

		// Initialize WP-CLI commands
		CLI::init();

		// Initialize FRS Sync integration
		FRSSync::init();

		// Ensure every new user is a member of both main site (hub) and /lending.
		// Without main-site membership, ms_site_check blocks the post-SSO redirect.
		\FRSUsers\Integrations\MultisiteProvisioning::init();

		// Initialize FluentCRM real-time sync integration
		FluentCRMSync::get_instance()->init();

		// Initialize FluentCRM event-based notifications (tags, lists, admin alerts)
		FluentCRMNotifications::init();

		// Twenty CRM disabled (2026-04-29): direct hub→marketing pipeline only.
		// Re-enable by uncommenting the line below if Twenty CRM is needed again.
		// TwentyCRMSync::init();

		// Initialize FluentBooking Outlook OAuth proxy (bypasses fluentbooking.com)
		FluentBookingSync::init();

		// Initialize FluentBooking auto-host creation on onboarding completion (site 2)
		FluentBookingSync::init_auto_host();

		// Initialize OAuth listener on all sites (for portal iframe communication)
		FluentBookingSync::init_oauth_listener();

		// Initialize activity recording hooks
		ActivityRecorder::init();

		// Initialize profile sync between hub and marketing sites
		\FRSUsers\Core\ProfileSync::init();

		// Initialize Arrive URL auto-population for loan officers
		\FRSUsers\Integrations\ArriveAutoPopulate::init();

		// Initialize Follow Up Boss integration
		FollowUpBoss::init();

		// BuddyPress integration: relabel Members→People / Friend→Connection,
		// register Member Types + Group Types, install XProfile schema mirroring
		// frs_* user_meta keys, one-way sync user_meta → xprofile, backfill CLI.
		// Each class no-ops if BuddyPress isn't loaded, so this is safe on
		// blogs/environments where BP is inactive.
		\FRSUsers\Integrations\BuddyPressLabels::init();
		\FRSUsers\Integrations\BuddyPressBootstrap::init();
		\FRSUsers\Integrations\BuddyPressXProfileSchema::init();
		\FRSUsers\Integrations\BuddyPressSync::init();
		\FRSUsers\Integrations\BuddyPressBackfill::init();
		\FRSUsers\Integrations\GroupHierarchyMembership::init();

		// GoogleRosterSync removed June 2026 — sync was creating malformed users
		// from the Sheet and re-importing them after delete attempts. Plugin file
		// deleted and init call removed. Roster updates now happen via the
		// LocalAggregateSync path or manual import.

		// Pushes the locally-aggregated SQLite warehouse (Sheet ∪ base.frs ∪ Moxi)
		// into production. Append-only safety model. CLI-only:
		// `wp frs-users local-aggregate-sync --sqlite=/app/data/private/agents.db`.
		\FRSUsers\Integrations\LocalAggregateSync::init();

		// Dual-write aliasing between legacy `frs_*` user_meta keys and the
		// new canonical names (native WP keys, vendor prefixes, bare names).
		// Lets plugins be rewritten one at a time without breakage. Flip the
		// `frs_meta_key_alias_enabled` filter to false once all consumers
		// are migrated, then run `wp frs-users meta-alias --cleanup-legacy`.
		\FRSUsers\Sync\MetaKeyAlias::init();

		// Keep `office`, `region`, `department` user_meta cache in sync with
		// the user's BP group memberships. Whenever a user joins/leaves a
		// group, the human-readable name is re-cached on their user_meta so
		// templates and the People & Places API can read it without joins.
		\FRSUsers\Sync\GroupNameCache::init();

		// Route Fluent Form 7 (Schedule A Call) emails to the loan officer
		FluentFormRouting::init();

		// Initialize WordPress Abilities API integration
		AbilitiesRegistry::init();

		// Initialize admin interface with error isolation
		if ( is_admin() ) {
			$admin_components = array(
				'ProfilesAdminPage'    => '\FRSUsers\Admin\ProfilesAdminPage',
				'ProfileEditPage'      => '\FRSUsers\Admin\ProfileEditPage',
				'ProfileAddPage'       => '\FRSUsers\Admin\ProfileAddPage',
				'UserProfileFields'    => '\FRSUsers\Admin\UserProfileFields',
				'CsvImportExport'      => '\FRSUsers\Admin\CsvImportExport',
				'TwentyCRMSettingsPage' => '\FRSUsers\Admin\TwentyCRMSettingsPage',
				'NotificationsPage'    => '\FRSUsers\Admin\NotificationsPage',
			);

			foreach ( $admin_components as $name => $class ) {
				try {
					if ( method_exists( $class, 'get_instance' ) ) {
						$class::get_instance()->init();
					} else {
						$class::init();
					}
				} catch ( \Throwable $e ) {
					error_log( sprintf( 'FRS Users: Failed to init %s - %s', $name, $e->getMessage() ) );
				}
			}
		}

		// Initialize internationalization
		add_action( 'init', array( $this, 'i18n' ) );

		// Check dependencies and show admin notices
		add_action( 'admin_notices', array( $this, 'check_dependencies' ) );

		// Allow other plugins to hook into FRS Users
		do_action( 'frs_users_loaded' );
	}

	/**
	 * Check plugin dependencies and show admin notices
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function check_dependencies() {
		$missing = array();

		// Check for FluentCRM (optional)
		if ( !function_exists('FluentCrmApi') ) {
			$missing[] = 'FluentCRM (optional - required for automatic contact sync)';
		}

		// Show notice if optional dependencies are missing
		if ( !empty($missing) ) {
			?>
			<div class="notice notice-info is-dismissible">
				<p>
					<strong>FRS User Profiles</strong> - Optional integrations:
				</p>
				<ul style="list-style: disc; margin-left: 20px;">
					<?php foreach ($missing as $plugin): ?>
						<li><?php echo esc_html($plugin); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php
		}
	}

	/**
	 * Internationalization setup for language translations.
	 *
	 * Loads the plugin text domain for localization.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function i18n() {
		load_plugin_textdomain( 'frs-users', false, dirname( plugin_basename( FRS_USERS_PLUGIN_FILE ) ) . '/languages/' );
	}

	/**
	 * Run any pending database migrations.
	 *
	 * @since 2.1.0
	 * @return void
	 */
	private function maybe_run_migrations() {
		$db_version = get_option( 'frs_users_db_version', '0' );
		if ( version_compare( $db_version, '3.2.0', '<' ) ) {
			\FRSUsers\Core\UserTasks::maybe_create_table();
			\FRSUsers\Models\ActivityLog::maybe_create_table();
			update_option( 'frs_users_db_version', '3.2.0' );
		}
	}
}
