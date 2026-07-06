<?php
/**
 * Hub Profile Template — Duplicate of loan-officer.php with tabs
 *
 * Profile header (Row 1) always visible. Tabs below switch between:
 * - Public: biography, specialties, custom links, social
 * - Internal: checklist + activity feed
 *
 * @package FRSUsers
 * @since 3.1.0
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// Prevent page caching
if (!defined('DONOTCACHEPAGE')) {
    define('DONOTCACHEPAGE', true);
}
nocache_headers();

// Get WordPress author and build profile array from wp_users + wp_usermeta
$author = get_queried_object();
$frs_roles = array_keys(\FRSUsers\Core\Roles::get_wp_roles());
$matched_roles = $author && ($author instanceof \WP_User) ? array_intersect($frs_roles, $author->roles ?? []) : [];
if (!empty($matched_roles)) {
    $matched_role = reset($matched_roles);
    $user_profile = new \FRSUsers\Models\UserProfile($author->ID);
    $profile = [
        'id' => $author->ID,
        'user_id' => $author->ID,
        'email' => $user_profile->get_email(),
        'first_name' => $user_profile->get_first_name(),
        'last_name' => $user_profile->get_last_name(),
        'display_name' => $user_profile->get_display_name(),
        'full_name' => $user_profile->get_full_name(),
        'phone_number' => $user_profile->get_phone_number(),
        'mobile_number' => $user_profile->get_mobile_number(),
        'job_title' => $user_profile->get_job_title() ?: \FRSUsers\Core\Roles::get_role_label($matched_role),
        'nmls' => $user_profile->get_nmls(),
        'city_state' => $user_profile->get_city_state(),
        'region' => $user_profile->get_region(),
        'biography' => $user_profile->get_biography(),
        'headshot_url' => $user_profile->get_headshot_url(),
        'profile_slug' => $author->user_nicename,
        'qr_code_data' => $user_profile->get_qr_code_data(),
        'arrive' => $user_profile->get_arrive_url(),
        'apply_url' => $user_profile->get_arrive_url(),
        'website' => $user_profile->get_website(),
        'facebook_url' => $user_profile->get_facebook_url(),
        'instagram_url' => $user_profile->get_instagram_url(),
        'linkedin_url' => $user_profile->get_linkedin_url(),
        'twitter_url' => $user_profile->get_twitter_url(),
        'specialties_lo' => $user_profile->get_specialties_lo(),
        'namb_certifications' => $user_profile->get_namb_certifications(),
        'service_areas' => $user_profile->get_service_areas(),
        'custom_links' => $user_profile->get_custom_links(),
        'booking_url' => $user_profile->get_booking_url(),
    ];

    // Load vCard sharing preferences
    $raw_vcard = get_user_meta( $author->ID, 'frs_vcard_settings', true );
    if ( is_string( $raw_vcard ) && ! empty( $raw_vcard ) ) {
        $profile['vcard_settings'] = json_decode( $raw_vcard, true ) ?: [];
    } elseif ( is_array( $raw_vcard ) ) {
        $profile['vcard_settings'] = $raw_vcard;
    } else {
        $profile['vcard_settings'] = [];
    }
} else {
    $profile = null;
}

// 404 if no profile
if (empty($profile)) {
    global $wp_query;
    $wp_query->set_404();
    status_header(404);
    nocache_headers();
    include get_404_template();
    exit;
}

// Profile data
$first_name = $profile['first_name'] ?? '';
$last_name = $profile['last_name'] ?? '';
$full_name = trim($first_name . ' ' . $last_name);
$initials = strtoupper(substr($first_name, 0, 1) . substr($last_name, 0, 1));
$job_title = $profile['job_title'] ?? '';
$raw_nmls = $profile['nmls'] ?? '';
// Hide fake placeholder NMLS (1994xxx range)
$nmls = preg_match('/^1994\d{3}$/', $raw_nmls) ? '' : $raw_nmls;
$email = $profile['email'] ?? '';
$phone = $profile['phone_number'] ?? $profile['mobile_number'] ?? '';
$phone = $phone ? \FRSUsers\Core\PhoneFormatter::us( $phone ) : $phone;
$location = $profile['city_state'] ?? '';
$region = $profile['region'] ?? '';
$bio = $profile['biography'] ?? '';
$headshot_url = $profile['headshot_url'] ?? '';
$profile_slug = $profile['profile_slug'] ?? '';
$qr_code_data = $profile['qr_code_data'] ?? '';

// Apply link
$apply_url = $profile['arrive'] ?? $profile['apply_url'] ?? '';

// Booking/scheduling link
$booking_url = $profile['booking_url'] ?? '';

// Social links
$website = $profile['website'] ?? '';
$facebook = $profile['facebook_url'] ?? '';
$instagram = $profile['instagram_url'] ?? '';
$linkedin = $profile['linkedin_url'] ?? '';
$twitter = $profile['twitter_url'] ?? '';

// Professional data
$specialties = $profile['specialties_lo'] ?? [];
$certifications = $profile['namb_certifications'] ?? [];
$service_areas = $profile['service_areas'] ?? [];
$custom_links = $profile['custom_links'] ?? [];

// Ensure arrays
if (!is_array($specialties)) $specialties = [];
if (!is_array($certifications)) $certifications = [];
if (!is_array($service_areas)) $service_areas = [];
if (!is_array($custom_links)) $custom_links = [];

// Video URL
$video_url = defined('FRS_USERS_VIDEO_BG_URL') ? FRS_USERS_VIDEO_BG_URL : '';
$hub_url = home_url();

// State abbreviation to slug mapping for SVG URLs
$abbr_to_slug = [
    'AL' => 'alabama', 'AK' => 'alaska', 'AZ' => 'arizona', 'AR' => 'arkansas',
    'CA' => 'california', 'CO' => 'colorado', 'CT' => 'connecticut', 'DE' => 'delaware',
    'DC' => 'district-of-columbia', 'FL' => 'florida', 'GA' => 'georgia', 'HI' => 'hawaii',
    'ID' => 'idaho', 'IL' => 'illinois', 'IN' => 'indiana', 'IA' => 'iowa',
    'KS' => 'kansas', 'KY' => 'kentucky', 'LA' => 'louisiana', 'ME' => 'maine',
    'MD' => 'maryland', 'MA' => 'massachusetts', 'MI' => 'michigan', 'MN' => 'minnesota',
    'MS' => 'mississippi', 'MO' => 'missouri', 'MT' => 'montana', 'NE' => 'nebraska',
    'NV' => 'nevada', 'NH' => 'new-hampshire', 'NJ' => 'new-jersey', 'NM' => 'new-mexico',
    'NY' => 'new-york', 'NC' => 'north-carolina', 'ND' => 'north-dakota', 'OH' => 'ohio',
    'OK' => 'oklahoma', 'OR' => 'oregon', 'PA' => 'pennsylvania', 'RI' => 'rhode-island',
    'SC' => 'south-carolina', 'SD' => 'south-dakota', 'TN' => 'tennessee', 'TX' => 'texas',
    'UT' => 'utah', 'VT' => 'vermont', 'VA' => 'virginia', 'WA' => 'washington',
    'WV' => 'west-virginia', 'WI' => 'wisconsin', 'WY' => 'wyoming'
];

// State name to abbreviation mapping
$state_map = [
    'alabama' => 'AL', 'alaska' => 'AK', 'arizona' => 'AZ', 'arkansas' => 'AR',
    'california' => 'CA', 'colorado' => 'CO', 'connecticut' => 'CT', 'delaware' => 'DE',
    'florida' => 'FL', 'georgia' => 'GA', 'hawaii' => 'HI', 'idaho' => 'ID',
    'illinois' => 'IL', 'indiana' => 'IN', 'iowa' => 'IA', 'kansas' => 'KS',
    'kentucky' => 'KY', 'louisiana' => 'LA', 'maine' => 'ME', 'maryland' => 'MD',
    'massachusetts' => 'MA', 'michigan' => 'MI', 'minnesota' => 'MN', 'mississippi' => 'MS',
    'missouri' => 'MO', 'montana' => 'MT', 'nebraska' => 'NE', 'nevada' => 'NV',
    'new hampshire' => 'NH', 'new jersey' => 'NJ', 'new mexico' => 'NM', 'new york' => 'NY',
    'north carolina' => 'NC', 'north dakota' => 'ND', 'ohio' => 'OH', 'oklahoma' => 'OK',
    'oregon' => 'OR', 'pennsylvania' => 'PA', 'rhode island' => 'RI', 'south carolina' => 'SC',
    'south dakota' => 'SD', 'tennessee' => 'TN', 'texas' => 'TX', 'utah' => 'UT',
    'vermont' => 'VT', 'virginia' => 'VA', 'washington' => 'WA', 'west virginia' => 'WV',
    'wisconsin' => 'WI', 'wyoming' => 'WY', 'district of columbia' => 'DC'
];

// Base URL for state SVGs
$state_svg_base = FRS_USERS_URL . 'assets/images/states/';

// Permission checks
$can_manage_tasks = current_user_can('edit_users');
$show_tabs = true;

// Activity feed
$recent_posts = get_posts([
    'author'         => $author->ID,
    'posts_per_page' => 5,
    'post_status'    => 'publish',
    'orderby'        => 'date',
    'order'          => 'DESC',
]);

// Integration data
$fub_connected      = ! empty( $user_profile->get_followupboss_api_key() );
$fluentcrm_synced   = $user_profile->get_synced_to_fluentcrm_at();
$telegram_connected = ! empty( get_user_meta( $author->ID, 'wptelegram_user_id', true ) );
$arrive_url         = $user_profile->get_arrive_url();

// Enqueue Interactivity API for QR flip
wp_enqueue_script_module(
    'frs-profile-view',
    FRS_USERS_URL . 'assets/js/profile-view.js',
    array( '@wordpress/interactivity' )
);

// Enqueue Interactivity API for tabs + checklist
if ($show_tabs) {
    wp_enqueue_script_module(
        'frs-users-hub-profile-view',
        FRS_USERS_URL . 'assets/js/hub-profile-view.js',
        array( '@wordpress/interactivity' ),
        FRS_USERS_VERSION
    );
}

// Interactivity context for tabs
$context = [
    'activeTab'      => 'public',
    'userId'         => $author->ID,
    'restNonce'      => wp_create_nonce('wp_rest'),
    'restBase'       => rest_url('frs-users/v1'),
    'canManageTasks' => $can_manage_tasks,
    'tasks'          => [],
    'tasksLoading'   => true,
    'showAddForm'    => false,
    'newTaskTitle'   => '',
    'newTaskDueDate' => '',
    'message'        => null,
];

get_header();
?>

<div class="frs-profile" id="frs-profile"
    <?php if ($show_tabs) : ?>
    data-wp-interactive="frs-users/hub-profile"
    <?php echo wp_interactivity_data_wp_context($context); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    data-wp-init="callbacks.onInit"
    <?php endif; ?>
>
    <!-- Row 1: Profile Card + Action Buttons/Service Areas (ALWAYS VISIBLE) -->
    <div class="frs-profile__row frs-profile__row--main">
        <!-- Profile Card (65%) -->
        <div class="frs-profile__card frs-profile__card--header">
            <!-- Video Header -->
            <div class="frs-profile__hero" style="height:150px;overflow:hidden;position:relative;">
                <?php if ($video_url) : ?>
                    <video autoplay loop muted playsinline style="width:100%;height:100%;object-fit:cover;">
                        <source src="<?php echo esc_url($video_url); ?>" type="video/mp4">
                    </video>
                <?php else : ?>
                    <div class="frs-profile__hero-fallback" style="width:100%;height:100%;background:linear-gradient(135deg,#2dd4da,#2563eb);"></div>
                <?php endif; ?>
            </div>

            <!-- Avatar with QR Flip -->
            <div
                class="frs-profile__avatar-wrap"
                data-wp-interactive="frs/profile"
                data-wp-context='{"isFlipped": false}'
            >
                <div
                    class="frs-profile__avatar-inner"
                    data-wp-class--frs-profile__avatar-inner--flipped="context.isFlipped"
                >
                    <!-- Front: Photo with QR button -->
                    <div class="frs-profile__avatar-front">
                        <?php if ($headshot_url) : ?>
                            <img src="<?php echo esc_url($headshot_url); ?>" alt="<?php echo esc_attr($full_name); ?>">
                        <?php else : ?>
                            <div class="frs-profile__avatar-placeholder"><?php echo esc_html($initials); ?></div>
                        <?php endif; ?>
                        <button
                            class="frs-profile__qr-toggle"
                            data-wp-on--click="actions.flip"
                            aria-label="Show QR code"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="url(#qr-grad)" stroke-width="2">
                                <defs><linearGradient id="qr-grad" x1="0%" y1="0%" x2="100%" y2="0%"><stop offset="0%" style="stop-color:#2dd4da"/><stop offset="100%" style="stop-color:#2563eb"/></linearGradient></defs>
                                <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="3" height="3"/><rect x="18" y="14" width="3" height="3"/><rect x="14" y="18" width="3" height="3"/><rect x="18" y="18" width="3" height="3"/>
                            </svg>
                        </button>
                    </div>
                    <!-- Back: QR Code with Avatar button -->
                    <div class="frs-profile__avatar-back">
                        <?php if ($qr_code_data) : ?>
                            <img src="<?php echo esc_attr($qr_code_data); ?>" alt="QR Code" width="90" height="90">
                        <?php else : ?>
                            <div class="frs-profile__no-qr">QR</div>
                        <?php endif; ?>
                        <button
                            class="frs-profile__qr-toggle"
                            data-wp-on--click="actions.flip"
                            aria-label="Show avatar"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="url(#av-grad)" stroke-width="2">
                                <defs><linearGradient id="av-grad" x1="0%" y1="0%" x2="100%" y2="0%"><stop offset="0%" style="stop-color:#2dd4da"/><stop offset="100%" style="stop-color:#2563eb"/></linearGradient></defs>
                                <circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Profile Info -->
            <div class="frs-profile__info">
                <div class="frs-profile__name-row">
                    <h1 class="frs-profile__name"><?php echo esc_html($full_name); ?></h1>
                    <div class="frs-profile__header-actions">
                        <button class="frs-profile__save-btn" id="save-contact-btn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                                <polyline points="17 21 17 13 7 13 7 21"/>
                                <polyline points="7 3 7 8 15 8"/>
                            </svg>
                            Save Contact
                        </button>
                        <?php if ($apply_url) : ?>
                        <a href="<?php echo esc_url($apply_url); ?>" class="frs-profile__apply-btn" target="_blank" rel="noopener">Apply Now</a>
                        <?php endif; ?>
                    </div>
                </div>
                <p class="frs-profile__title-location">
                    <span class="frs-profile__title">
                        <?php echo esc_html($job_title); ?>
                        <?php if ($nmls) : ?>
                            <span class="frs-profile__nmls">| NMLS# <?php echo esc_html($nmls); ?></span>
                        <?php endif; ?>
                    </span>
                    <?php $where = $location ?: $region; if ($where) : ?>
                        <span class="frs-profile__location">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                                <circle cx="12" cy="10" r="3"/>
                            </svg>
                            <?php echo esc_html($where); ?>
                        </span>
                    <?php endif; ?>
                </p>
                <div class="frs-profile__contact">
                    <?php if ($email) : ?>
                        <a href="mailto:<?php echo esc_attr($email); ?>" class="frs-profile__contact-item">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="20" height="20">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M8 12h8M12 8v8"/>
                            </svg>
                            <?php echo esc_html($email); ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($phone) : ?>
                        <a href="tel:<?php echo esc_attr(preg_replace('/[^\d+]/', '', $phone)); ?>" class="frs-profile__contact-item">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="20" height="20">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M15.05 11.05a3 3 0 0 0-6.1 0M12 14v.01"/>
                            </svg>
                            <?php echo esc_html($phone); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Column (35%) -->
        <div class="frs-profile__sidebar">
            <!-- Action Buttons Card -->
            <div class="frs-profile__card frs-profile__card--actions">
                <?php if ($booking_url) : ?>
                <!-- Schedule button (links to FluentBooking/Calendly) -->
                <a href="<?php echo esc_url($booking_url); ?>" class="frs-profile__action-btn frs-profile__action-btn--primary" target="_blank" rel="noopener">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                        <line x1="16" y1="2" x2="16" y2="6"/>
                        <line x1="8" y1="2" x2="8" y2="6"/>
                        <line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                    Schedule Meeting
                </a>
                <?php else : ?>
                <!-- Contact form button (no booking URL set) -->
                <button class="frs-profile__action-btn" id="open-contact-modal">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                        <polyline points="22,6 12,13 2,6"/>
                    </svg>
                    Contact <?php echo esc_html($first_name); ?>
                </button>
                <?php endif; ?>
                <?php if ($phone) : ?>
                <a href="tel:<?php echo esc_attr(preg_replace('/[^\d+]/', '', $phone)); ?>" class="frs-profile__action-btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
                    </svg>
                    Call Me
                </a>
                <?php endif; ?>
            </div>

            <!-- Service Areas Card -->
            <div class="frs-profile__card frs-profile__card--service-areas">
                <h3 class="frs-profile__card-title">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                        <circle cx="12" cy="10" r="3"/>
                    </svg>
                    Service Areas
                </h3>
                <div class="frs-profile__states-grid">
                    <?php if (!empty($service_areas)) : ?>
                        <?php foreach ($service_areas as $area) :
                            $area_lower = strtolower(trim($area));
                            $abbr = $state_map[$area_lower] ?? (strlen($area) === 2 ? strtoupper($area) : null);
                            if ($abbr && isset($abbr_to_slug[$abbr])) :
                                $state_slug = $abbr_to_slug[$abbr];
                                $svg_url = $state_svg_base . $state_slug . '.svg';
                        ?>
                            <div class="frs-profile__state-card">
                                <img src="<?php echo esc_url($svg_url); ?>" alt="<?php echo esc_attr($abbr); ?>" class="frs-profile__state-svg">
                                <span class="frs-profile__state-abbr"><?php echo esc_html($abbr); ?></span>
                            </div>
                        <?php else : ?>
                            <div class="frs-profile__state-card frs-profile__state-card--text">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="32" height="32">
                                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                                    <circle cx="12" cy="10" r="3"/>
                                </svg>
                                <span class="frs-profile__state-abbr"><?php echo esc_html($area); ?></span>
                            </div>
                        <?php endif; endforeach; ?>
                    <?php else : ?>
                        <p class="frs-profile__empty">No service areas specified.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($show_tabs) : ?>
    <!-- Tab Bar -->
    <div class="hp-tabs" role="tablist">
        <button class="hp-tab-btn" data-wp-class--hp-tab-btn--active="state.isPublicTab" data-wp-on--click="actions.setTab" data-tab="public" role="tab">Public Profile</button>
        <button class="hp-tab-btn" data-wp-class--hp-tab-btn--active="state.isInternalTab" data-wp-on--click="actions.setTab" data-tab="internal" role="tab">Internal</button>
    </div>

    <!-- Message Toast -->
    <div class="hp-message hp-message--success" data-wp-bind--hidden="!state.isSuccessMessage" data-wp-text="state.messageText" hidden></div>
    <div class="hp-message hp-message--error" data-wp-bind--hidden="!state.isErrorMessage" data-wp-text="state.messageText" hidden></div>
    <?php endif; ?>

    <!-- ==================== PUBLIC TAB ==================== -->
    <div <?php if ($show_tabs) : ?>data-wp-bind--hidden="!state.isPublicTab"<?php endif; ?> role="tabpanel">

    <!-- Row 2: Biography + Specialties -->
    <div class="frs-profile__row">
        <!-- Biography Card (65%) -->
        <div class="frs-profile__card frs-profile__card--bio">
            <h3 class="frs-profile__card-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="16" y1="13" x2="8" y2="13"/>
                    <line x1="16" y1="17" x2="8" y2="17"/>
                    <line x1="10" y1="9" x2="8" y2="9"/>
                </svg>
                Professional Biography
            </h3>
            <div class="frs-profile__bio-content">
                <?php if ($bio) : ?>
                    <?php echo wp_kses_post(wpautop($bio)); ?>
                <?php else : ?>
                    <p class="frs-profile__empty">No biography provided.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Specialties Card (35%) -->
        <div class="frs-profile__card frs-profile__card--specialties">
            <h3 class="frs-profile__card-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                    <polyline points="9 11 12 14 22 4"/>
                    <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
                </svg>
                Specialties & Credentials
            </h3>
            <div class="frs-profile__specialties-section">
                <h4>Loan Officer Specialties</h4>
                <div class="frs-profile__badges">
                    <?php if (!empty($specialties)) : ?>
                        <?php foreach ($specialties as $specialty) : ?>
                            <span class="frs-profile__badge"><?php echo esc_html($specialty); ?></span>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <p class="frs-profile__empty frs-profile__empty--small">No specialties selected</p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="frs-profile__specialties-section">
                <h4>NAMB Certifications</h4>
                <div class="frs-profile__badges">
                    <?php if (!empty($certifications)) : ?>
                        <?php foreach ($certifications as $cert) : ?>
                            <span class="frs-profile__badge frs-profile__badge--cert"><?php echo esc_html($cert); ?></span>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <p class="frs-profile__empty frs-profile__empty--small">No certifications selected</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 3: Custom Links + Social -->
    <div class="frs-profile__row">
        <!-- Custom Links Card (65%) -->
        <div class="frs-profile__card frs-profile__card--links">
            <h3 class="frs-profile__card-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                    <polyline points="15 3 21 3 21 9"/>
                    <line x1="10" y1="14" x2="21" y2="3"/>
                </svg>
                Custom Links
            </h3>
            <div class="frs-profile__links-list">
                <?php if (!empty($custom_links)) : ?>
                    <?php foreach ($custom_links as $link) : ?>
                        <a href="<?php echo esc_url($link['url'] ?? '#'); ?>" target="_blank" rel="noopener noreferrer" class="frs-profile__link-item">
                            <div class="frs-profile__link-info">
                                <span class="frs-profile__link-title"><?php echo esc_html($link['title'] ?? 'Link'); ?></span>
                                <span class="frs-profile__link-url"><?php echo esc_html($link['url'] ?? ''); ?></span>
                            </div>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                                <polyline points="15 3 21 3 21 9"/>
                                <line x1="10" y1="14" x2="21" y2="3"/>
                            </svg>
                        </a>
                    <?php endforeach; ?>
                <?php else : ?>
                    <p class="frs-profile__empty frs-profile__empty--center">No custom links added yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Links & Social Card (35%) -->
        <div class="frs-profile__card frs-profile__card--social">
            <h3 class="frs-profile__card-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="2" y1="12" x2="22" y2="12"/>
                    <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                </svg>
                Links & Social
            </h3>
            <div class="frs-profile__social-grid">
                <a href="<?php echo $website ? esc_url($website) : '#'; ?>" class="frs-profile__social-item <?php echo !$website ? 'frs-profile__social-item--empty' : ''; ?>" <?php echo $website ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="2" y1="12" x2="22" y2="12"/>
                        <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                    </svg>
                    <span>Website</span>
                </a>
                <a href="<?php echo $linkedin ? esc_url($linkedin) : '#'; ?>" class="frs-profile__social-item <?php echo !$linkedin ? 'frs-profile__social-item--empty' : ''; ?>" <?php echo $linkedin ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>>
                    <svg viewBox="0 0 24 24" fill="currentColor" width="16" height="16">
                        <path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"/>
                        <rect x="2" y="9" width="4" height="12"/>
                        <circle cx="4" cy="4" r="2"/>
                    </svg>
                    <span>LinkedIn</span>
                </a>
                <a href="<?php echo $facebook ? esc_url($facebook) : '#'; ?>" class="frs-profile__social-item <?php echo !$facebook ? 'frs-profile__social-item--empty' : ''; ?>" <?php echo $facebook ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>>
                    <svg viewBox="0 0 24 24" fill="currentColor" width="16" height="16">
                        <path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>
                    </svg>
                    <span>Facebook</span>
                </a>
                <a href="<?php echo $instagram ? esc_url($instagram) : '#'; ?>" class="frs-profile__social-item <?php echo !$instagram ? 'frs-profile__social-item--empty' : ''; ?>" <?php echo $instagram ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <rect x="2" y="2" width="20" height="20" rx="5" ry="5"/>
                        <path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/>
                        <line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/>
                    </svg>
                    <span>Instagram</span>
                </a>
            </div>
        </div>
    </div>

    </div><!-- /public tab -->

    <!-- ==================== INTERNAL TAB ==================== -->
    <div data-wp-bind--hidden="!state.isInternalTab" role="tabpanel" hidden>

        <!-- Integration Badges -->
        <div class="frs-profile__card hp-internal-card">
            <h3 class="frs-profile__card-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
                Integrations
            </h3>
            <div style="padding: 1rem;">
                <div class="hp-badges">
                    <span class="hp-badge <?php echo $telegram_connected ? 'hp-badge--ok' : 'hp-badge--off'; ?>">
                        <?php echo $telegram_connected ? '&#10003;' : '&#10005;'; ?> Telegram
                    </span>
                    <span class="hp-badge <?php echo $fub_connected ? 'hp-badge--ok' : 'hp-badge--off'; ?>">
                        <?php echo $fub_connected ? '&#10003;' : '&#10005;'; ?> Follow Up Boss
                    </span>
                    <span class="hp-badge <?php echo $fluentcrm_synced ? 'hp-badge--ok' : 'hp-badge--off'; ?>">
                        <?php echo $fluentcrm_synced ? '&#10003;' : '&#10005;'; ?> FluentCRM
                    </span>
                    <?php if ($arrive_url) : ?>
                    <span class="hp-badge hp-badge--ok">&#10003; Arrive</span>
                    <?php else : ?>
                    <span class="hp-badge hp-badge--warn">&#10005; Arrive</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Checklist -->
        <div class="frs-profile__card hp-internal-card">
            <h3 class="frs-profile__card-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                Checklist
            </h3>
            <div style="padding: 1rem;">
                <!-- Progress bar -->
                <div class="hp-checklist-bar">
                    <div class="hp-checklist-bar__track">
                        <div class="hp-checklist-bar__fill" data-wp-style--width="state.completionPercent + '%'"></div>
                    </div>
                    <span class="hp-checklist-bar__text" data-wp-text="state.completionText"></span>
                </div>

                <!-- Loading skeleton -->
                <div data-wp-bind--hidden="!context.tasksLoading">
                    <div class="hp-skeleton" style="width:80%"></div>
                    <div class="hp-skeleton" style="width:60%"></div>
                    <div class="hp-skeleton" style="width:70%"></div>
                </div>

                <!-- Tasks list -->
                <div data-wp-bind--hidden="context.tasksLoading">
                    <div class="hp-checklist-group-label">Profile Completion</div>
                    <template data-wp-each="state.autoTasks" data-wp-each-key="key">
                        <div class="hp-task" data-wp-key="context.item.key">
                            <span class="hp-task__check hp-task__check--auto" data-wp-class--hp-task__check--done="context.item.is_completed">
                                <svg data-wp-bind--hidden="!context.item.is_completed" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2 6l3 3 5-5" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                            <span class="hp-task__text" data-wp-class--hp-task__text--done="context.item.is_completed" data-wp-text="context.item.title"></span>
                        </div>
                    </template>

                    <div class="hp-checklist-group-label">Admin Tasks</div>
                    <template data-wp-each="state.adminTasks" data-wp-each-key="key">
                        <div class="hp-task" data-wp-key="context.item.key" data-wp-context='{}'>
                            <button class="hp-task__check" data-wp-class--hp-task__check--done="context.item.is_completed" data-wp-on--click="actions.toggleTask" data-wp-bind--data-task-key="context.item.key">
                                <svg data-wp-bind--hidden="!context.item.is_completed" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2 6l3 3 5-5" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                            <span class="hp-task__text" data-wp-class--hp-task__text--done="context.item.is_completed" data-wp-text="context.item.title"></span>
                            <span class="hp-task__due" data-wp-text="context.item.due_date" data-wp-bind--hidden="!context.item.due_date"></span>
                            <?php if ($can_manage_tasks) : ?>
                            <button class="hp-task__delete" data-wp-on--click="actions.deleteTask" data-wp-bind--data-task-key="context.item.key" title="Delete task">&times;</button>
                            <?php endif; ?>
                        </div>
                    </template>

                    <?php if ($can_manage_tasks) : ?>
                    <div data-wp-bind--hidden="context.showAddForm">
                        <button class="hp-add-btn--secondary" data-wp-on--click="actions.showAddTask" style="margin-top:0.75rem;">+ Add Task</button>
                    </div>
                    <div class="hp-add-form" data-wp-bind--hidden="!context.showAddForm" hidden>
                        <div class="hp-add-form__field">
                            <label>Task</label>
                            <input type="text" data-field="newTaskTitle" data-wp-on--input="actions.updateTaskField" data-wp-bind--value="context.newTaskTitle" placeholder="Task description...">
                        </div>
                        <div class="hp-add-form__field" style="max-width:10rem;">
                            <label>Due date</label>
                            <input type="date" data-field="newTaskDueDate" data-wp-on--input="actions.updateTaskField" data-wp-bind--value="context.newTaskDueDate">
                        </div>
                        <button class="hp-add-btn" data-wp-on--click="actions.createTask">Add</button>
                        <button class="hp-add-btn--secondary" data-wp-on--click="actions.hideAddTask">Cancel</button>
                    </div>
                    <?php endif; ?>

                    <?php if ( class_exists( 'FRSLendingOnboarding\\OnboardingWizard' ) ) : ?>
                    <a href="?tour=1" class="hp-tour-link">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        Take a guided tour
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!empty($recent_posts)) : ?>
        <!-- Activity Feed -->
        <div class="frs-profile__card hp-internal-card">
            <h3 class="frs-profile__card-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Recent Activity
            </h3>
            <div style="padding: 1rem;">
                <ul class="hp-activity">
                    <?php foreach ($recent_posts as $post_item) : ?>
                    <li>
                        <a href="<?php echo esc_url(get_permalink($post_item)); ?>"><?php echo esc_html(get_the_title($post_item)); ?></a>
                        <span class="hp-activity__date"><?php echo esc_html(get_the_date('M j, Y', $post_item)); ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- Contact Modal -->
    <div class="frs-modal" id="contact-modal">
        <div class="frs-modal__backdrop" id="modal-backdrop"></div>
        <div class="frs-modal__content">
            <button class="frs-modal__close" id="close-modal" aria-label="Close">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
            <h2 class="frs-modal__title">Contact <?php echo esc_html($full_name); ?></h2>
            <p class="frs-modal__subtitle">Send a message and <?php echo esc_html($first_name); ?> will get back to you.</p>
            <div id="lo-contact-data"
                data-id="<?php echo esc_attr($profile['id'] ?? ''); ?>"
                data-email="<?php echo esc_attr($email); ?>"
                data-name="<?php echo esc_attr($full_name); ?>"
                style="display:none;"></div>
            <?php echo do_shortcode('[fluentform id="7"]'); ?>
        </div>
    </div>
</div>

<style>
.frs-profile {
    --frs-cyan: #2dd4da;
    --frs-blue: #2563eb;
    --frs-navy: #020817;
    --frs-text: #374151;
    --frs-text-light: #6b7280;
    --frs-border: #e5e7eb;
    --frs-bg: #ffffff;
    --frs-radius: 8px;
    font-family: 'Mona Sans', -apple-system, BlinkMacSystemFont, sans-serif;
    max-width: 1200px;
    margin: 0 auto;
    padding: 2rem 1rem;
}

.frs-profile [hidden] { display: none !important; }

/* Utility */
.frs-profile .hidden {
    display: none;
}

/* Rows */
.frs-profile__row {
    display: grid;
    grid-template-columns: 65% 35%;
    gap: 1rem;
    margin-bottom: 1rem;
}

.frs-profile__row--main {
    grid-template-columns: 65% 35%;
}

@media (max-width: 900px) {
    .frs-profile__row {
        grid-template-columns: 1fr;
    }
}

/* Cards */
.frs-profile__card {
    background: var(--frs-bg);
    border: 1px solid var(--frs-border);
    border-radius: var(--frs-radius);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.frs-profile__card-title {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 1rem;
    font-weight: 600;
    color: var(--frs-navy);
    margin: 0;
    padding: 1rem;
    border-bottom: 1px solid var(--frs-border);
}

/* Profile Header Card */
.frs-profile__card--header {
    overflow: hidden;
}

.frs-profile__hero {
    height: 150px;
    overflow: hidden;
    position: relative;
}

.frs-profile__hero video {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.frs-profile__hero-fallback {
    width: 100%;
    height: 100%;
    background: linear-gradient(135deg, var(--frs-cyan), var(--frs-blue));
}

/* Avatar */
.frs-profile .frs-profile__avatar-wrap {
    width: 148px !important;
    height: 148px !important;
    margin: -74px 0 0 2rem !important;
    perspective: 1000px;
    position: relative !important;
    left: auto !important;
    top: auto !important;
    transform: none !important;
    z-index: 10;
}

.frs-profile .frs-profile__avatar-inner {
    width: 148px !important;
    height: 148px !important;
    position: relative;
    transition: transform 0.7s;
    transform-style: preserve-3d;
    border: none !important;
    border-radius: 0 !important;
    box-shadow: none !important;
    background: transparent !important;
    overflow: visible !important;
}

.frs-profile .frs-profile__avatar-inner--flipped {
    transform: rotateY(-180deg);
}

.frs-profile .frs-profile__avatar-front,
.frs-profile .frs-profile__avatar-back {
    position: absolute;
    width: 148px;
    height: 148px;
    backface-visibility: hidden;
    border-radius: 50%;
    overflow: visible;
    background: linear-gradient(white, white), linear-gradient(135deg, var(--frs-blue), var(--frs-cyan));
    background-clip: padding-box, border-box;
    background-origin: border-box;
    border: 3px solid transparent;
}

.frs-profile .frs-profile__avatar-front img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
}

.frs-profile .frs-profile__avatar-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, var(--frs-cyan), var(--frs-blue));
    color: white;
    font-size: 2.5rem;
    font-weight: 600;
}

.frs-profile .frs-profile__avatar-back {
    transform: rotateY(180deg);
    background: white;
    border: 1px solid var(--frs-border);
    display: flex;
    align-items: center;
    justify-content: center;
}

.frs-profile .frs-profile__no-qr {
    color: #ccc;
    font-size: 1.5rem;
}

/* QR Toggle Button */
.frs-profile .frs-profile__qr-toggle {
    position: absolute;
    top: 4px;
    right: -4px;
    width: 40px;
    height: 40px;
    border: 2px solid transparent;
    border-radius: 50%;
    background: linear-gradient(white, white), linear-gradient(90deg, var(--frs-cyan), var(--frs-blue));
    background-clip: padding-box, border-box;
    background-origin: padding-box, border-box;
    cursor: pointer;
    display: flex !important;
    align-items: center;
    justify-content: center;
    z-index: 20;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.frs-profile .frs-profile__qr-toggle svg {
    width: 20px;
    height: 20px;
}

/* Profile Info */
.frs-profile__info {
    padding: 1rem 2rem 1.5rem;
}

.frs-profile__name-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 0.5rem;
    flex-wrap: wrap;
}

.frs-profile__name {
    font-size: 2rem;
    font-weight: 700;
    color: var(--frs-navy);
    margin: 0;
}

.frs-profile__header-actions {
    display: flex;
    gap: 0.5rem;
    align-items: center;
}

.frs-profile__save-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.5rem 1rem;
    border: 2px solid transparent;
    border-radius: 6px;
    background: linear-gradient(white, white), linear-gradient(90deg, var(--frs-cyan), var(--frs-blue));
    background-clip: padding-box, border-box;
    background-origin: padding-box, border-box;
    color: var(--frs-blue);
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    white-space: nowrap;
}

.frs-profile__save-btn:hover {
    opacity: 0.9;
}

.frs-profile__apply-btn {
    display: inline-flex;
    align-items: center;
    padding: 0.5rem 1.25rem;
    border-radius: 6px;
    background: linear-gradient(135deg, var(--frs-blue), var(--frs-cyan));
    color: white;
    font-size: 0.875rem;
    font-weight: 500;
    text-decoration: none;
    white-space: nowrap;
}

.frs-profile__title-location {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
    margin: 0 0 1rem;
    color: var(--frs-blue);
    font-size: 0.9375rem;
}

.frs-profile__nmls {
    font-weight: 400;
}

.frs-profile__location {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}

.frs-profile__contact {
    display: flex;
    flex-wrap: wrap;
    gap: 1.5rem;
}

.frs-profile__contact-item {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--frs-text);
    text-decoration: none;
    font-size: 0.875rem;
}

.frs-profile__contact-item:hover {
    color: var(--frs-blue);
}

/* Sidebar */
.frs-profile__sidebar {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

/* Action Buttons */
.frs-profile__card--actions {
    padding: 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.frs-profile__action-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.75rem 1rem;
    border: 2px solid transparent;
    border-radius: 6px;
    background: linear-gradient(white, white), linear-gradient(90deg, var(--frs-cyan), var(--frs-blue));
    background-clip: padding-box, border-box;
    background-origin: padding-box, border-box;
    color: var(--frs-blue);
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    text-decoration: none;
    transition: opacity 0.2s;
}

.frs-profile__action-btn:hover {
    opacity: 0.9;
}

/* Modal */
.frs-modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}

.frs-modal--open {
    display: flex;
}

.frs-modal__backdrop {
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(4px);
}

.frs-modal__content {
    position: relative;
    background: white;
    border-radius: 16px;
    padding: 2rem;
    width: 100%;
    max-width: 420px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
}

.frs-modal__close {
    position: absolute;
    top: 1rem;
    right: 1rem;
    background: none;
    border: none;
    cursor: pointer;
    color: var(--frs-text-light);
    padding: 0.25rem;
    border-radius: 4px;
    transition: color 0.2s;
}

.frs-modal__close:hover {
    color: var(--frs-navy);
}

.frs-modal__title {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--frs-navy);
    margin: 0 0 0.25rem;
}

.frs-modal__subtitle {
    font-size: 0.875rem;
    color: var(--frs-text-light);
    margin: 0 0 1.5rem;
}

/* Service Areas */
.frs-profile__card--service-areas {
    flex: 1;
}

.frs-profile__states-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0.5rem;
    padding: 0.75rem;
}

.frs-profile__state-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 0.5rem 0.25rem;
    border: 1px solid var(--frs-border);
    border-radius: 6px;
    background: var(--frs-bg-light);
    transition: border-color 0.2s, box-shadow 0.2s, transform 0.2s;
}

.frs-profile__state-card:hover {
    border-color: var(--frs-cyan);
    box-shadow: 0 2px 8px rgba(45, 212, 218, 0.2);
    transform: translateY(-2px);
}

.frs-profile__state-svg {
    width: 40px;
    height: 40px;
    object-fit: contain;
    margin-bottom: 0.125rem;
}

.frs-profile__state-abbr {
    font-size: 0.75rem;
    font-weight: 700;
    background: linear-gradient(90deg, var(--frs-cyan), var(--frs-blue));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

/* Biography */
.frs-profile__card--bio {
    min-height: 200px;
}

.frs-profile__bio-content {
    padding: 1rem;
    font-size: 0.9375rem;
    line-height: 1.6;
    color: var(--frs-text);
}

.frs-profile__bio-content p {
    margin: 0 0 1rem;
}

.frs-profile__bio-content p:last-child {
    margin-bottom: 0;
}

/* Specialties */
.frs-profile__card--specialties {
    min-height: 200px;
}

.frs-profile__specialties-section {
    padding: 1rem;
    border-bottom: 1px solid var(--frs-border);
}

.frs-profile__specialties-section:last-child {
    border-bottom: none;
}

.frs-profile__specialties-section h4 {
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--frs-text);
    margin: 0 0 0.75rem;
}

.frs-profile__badges {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.frs-profile__badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    background: #f3f4f6;
    border-radius: 9999px;
    font-size: 0.75rem;
    color: var(--frs-text);
}

.frs-profile__badge--cert {
    background: #fae8ff;
    color: #86198f;
}

/* Custom Links */
.frs-profile__card--links {
    min-height: 150px;
}

.frs-profile__links-list {
    padding: 1rem;
}

.frs-profile__link-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.75rem;
    border: 1px solid var(--frs-border);
    border-radius: 8px;
    margin-bottom: 0.5rem;
    text-decoration: none;
    transition: border-color 0.2s, background 0.2s;
}

.frs-profile__link-item:last-child {
    margin-bottom: 0;
}

.frs-profile__link-item:hover {
    border-color: var(--frs-blue);
    background: rgba(37, 99, 235, 0.02);
}

.frs-profile__link-info {
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
    min-width: 0;
}

.frs-profile__link-title {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--frs-navy);
}

.frs-profile__link-url {
    font-size: 0.75rem;
    color: var(--frs-text-light);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.frs-profile__link-item svg {
    color: var(--frs-text-light);
    flex-shrink: 0;
}

.frs-profile__link-item:hover svg {
    color: var(--frs-blue);
}

/* Social Links */
.frs-profile__card--social {
    min-height: 150px;
}

.frs-profile__social-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.5rem;
    padding: 1rem;
}

.frs-profile__social-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem;
    border: 1px solid var(--frs-border);
    border-radius: 6px;
    text-decoration: none;
    color: var(--frs-text);
    font-size: 0.875rem;
    transition: border-color 0.2s;
}

.frs-profile__social-item:hover {
    border-color: var(--frs-blue);
}

.frs-profile__social-item--empty {
    color: var(--frs-text-light);
    pointer-events: none;
}

/* Empty states */
.frs-profile__empty {
    color: var(--frs-text-light);
    font-style: italic;
    font-size: 0.875rem;
    margin: 0;
}

.frs-profile__empty--small {
    font-size: 0.75rem;
}

.frs-profile__empty--center {
    text-align: center;
    padding: 2rem 0;
}

/* Mobile adjustments */
@media (max-width: 640px) {
    .frs-profile__name-row {
        flex-direction: column;
        align-items: center;
    }

    .frs-profile__header-actions {
        width: 100%;
        flex-direction: column;
    }

    .frs-profile__save-btn,
    .frs-profile__apply-btn {
        width: 100%;
        justify-content: center;
    }

    .frs-profile__avatar-wrap {
        margin-left: auto;
        margin-right: auto;
    }

    .frs-profile__info {
        text-align: center;
    }

    .frs-profile__title-location {
        justify-content: center;
    }

    .frs-profile__contact {
        justify-content: center;
    }

    .frs-profile__states-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

/* ==================== TAB & INTERNAL STYLES ==================== */

/* Tab bar */
.hp-tabs {
    display: flex;
    gap: 0;
    background: #e5e7eb;
    border-radius: 8px;
    padding: 0.25rem;
    margin-bottom: 1rem;
}
.hp-tab-btn {
    flex: 1;
    padding: 0.5rem 1rem;
    border: none;
    background: transparent;
    border-radius: 6px;
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--frs-text-light);
    cursor: pointer;
    transition: background 0.15s, color 0.15s;
}
.hp-tab-btn:hover { color: var(--frs-navy); }
.hp-tab-btn--active {
    background: var(--frs-bg);
    color: var(--frs-navy);
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

/* Internal card spacing */
.hp-internal-card { margin-bottom: 1rem; }

/* Message toast */
.hp-message { padding: 0.5rem 0.75rem; border-radius: 8px; font-size: 0.8125rem; margin-bottom: 1rem; }
.hp-message--success { background: #dcfce7; color: #16a34a; }
.hp-message--error { background: #fef2f2; color: #dc2626; }

/* Integration badges */
.hp-badges { display: flex; flex-wrap: wrap; gap: 0.5rem; }
.hp-badge {
    display: inline-flex; align-items: center; gap: 0.375rem;
    padding: 0.375rem 0.75rem; border-radius: 999px;
    font-size: 0.8125rem; font-weight: 500;
}
.hp-badge--ok { background: #dcfce7; color: #16a34a; }
.hp-badge--warn { background: #fef3c7; color: #d97706; }
.hp-badge--off { background: #f8f7f9; color: #6b7280; }

/* Checklist */
.hp-checklist-bar { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem; }
.hp-checklist-bar__track { flex: 1; height: 0.5rem; background: #e5e7eb; border-radius: 999px; overflow: hidden; }
.hp-checklist-bar__fill { height: 100%; background: linear-gradient(135deg, var(--frs-blue), var(--frs-cyan)); border-radius: 999px; transition: width 0.3s ease; }
.hp-checklist-bar__text { font-size: 0.8125rem; color: var(--frs-text-light); white-space: nowrap; }
.hp-checklist-group-label { font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #9ca3af; margin: 1rem 0 0.5rem; }
.hp-checklist-group-label:first-child { margin-top: 0; }
.hp-task { display: flex; align-items: flex-start; gap: 0.5rem; padding: 0.5rem 0; border-bottom: 1px solid #f3f4f6; font-size: 0.875rem; }
.hp-task:last-child { border-bottom: none; }
.hp-task__check { width: 1.125rem; height: 1.125rem; border-radius: 0.25rem; border: 2px solid #e5e7eb; flex-shrink: 0; cursor: pointer; display: flex; align-items: center; justify-content: center; margin-top: 0.0625rem; background: transparent; padding: 0; transition: border-color 0.15s, background 0.15s; }
.hp-task__check:hover { border-color: var(--frs-blue); }
.hp-task__check--done { background: var(--frs-blue); border-color: var(--frs-blue); }
.hp-task__check--auto { cursor: default; opacity: 0.7; }
.hp-task__check svg { width: 0.75rem; height: 0.75rem; }
.hp-task__text { flex: 1; line-height: 1.4; }
.hp-task__text--done { text-decoration: line-through; color: #9ca3af; }
.hp-task__due { font-size: 0.75rem; color: var(--frs-text-light); white-space: nowrap; }
.hp-task__delete { background: none; border: none; color: #e5e7eb; cursor: pointer; padding: 0.125rem; font-size: 1rem; line-height: 1; }
.hp-task__delete:hover { color: #dc2626; }

/* Add task form */
.hp-add-form { display: flex; gap: 0.5rem; align-items: flex-end; padding-top: 0.75rem; border-top: 1px solid #f3f4f6; margin-top: 0.5rem; }
.hp-add-form__field { flex: 1; }
.hp-add-form__field label { display: block; font-size: 0.75rem; font-weight: 500; color: var(--frs-text-light); margin-bottom: 0.25rem; }
.hp-add-form__field input { width: 100%; padding: 0.375rem 0.5rem; border: 1px solid #e5e7eb; border-radius: 0.375rem; font-size: 0.8125rem; }
.hp-add-btn, .hp-add-btn--secondary { padding: 0.375rem 0.75rem; border-radius: 0.375rem; font-size: 0.8125rem; font-weight: 500; cursor: pointer; white-space: nowrap; border: none; }
.hp-add-btn { background: var(--frs-blue); color: #fff; }
.hp-add-btn:hover { opacity: 0.9; }
.hp-add-btn--secondary { background: #f3f4f6; color: var(--frs-text-light); }

/* Tour link */
.hp-tour-link { display: flex; align-items: center; gap: 0.375rem; margin-top: 1rem; padding-top: 0.75rem; border-top: 1px solid #f3f4f6; font-size: 0.8125rem; color: var(--frs-blue); text-decoration: none; }
.hp-tour-link:hover { opacity: 0.8; }
.hp-tour-link svg { flex-shrink: 0; }

/* Loading skeleton */
.hp-skeleton { background: linear-gradient(90deg, #f3f4f6 25%, #e5e7eb 50%, #f3f4f6 75%); background-size: 200% 100%; animation: hp-shimmer 1.5s infinite; border-radius: 0.375rem; height: 1rem; margin-bottom: 0.5rem; }
@keyframes hp-shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }

/* Activity feed */
.hp-activity { list-style: none; margin: 0; padding: 0; }
.hp-activity li { padding: 0.5rem 0; border-bottom: 1px solid #f3f4f6; font-size: 0.875rem; }
.hp-activity li:last-child { border-bottom: none; }
.hp-activity__date { font-size: 0.75rem; color: #9ca3af; }
.hp-activity a { color: var(--frs-blue); text-decoration: none; }
.hp-activity a:hover { text-decoration: underline; }
</style>

<script>
(function() {
    // Save Contact (vCard) — respects LO sharing preferences
    const saveBtn = document.getElementById('save-contact-btn');
    if (saveBtn) {
        saveBtn.addEventListener('click', function() {
            const profile = <?php echo wp_json_encode($profile); ?>;
            const vs = profile.vcard_settings || {};
            // Helper: check if a field is included (default true for backwards compat)
            const inc = (key) => vs[key] !== undefined ? !!vs[key] : true;
            // Biography defaults to false
            const incBio = vs['include_biography'] !== undefined ? !!vs['include_biography'] : false;

            const lines = [
                'BEGIN:VCARD',
                'VERSION:3.0',
                'FN:' + (profile.first_name || '') + ' ' + (profile.last_name || ''),
                'N:' + (profile.last_name || '') + ';' + (profile.first_name || '') + ';;;',
                'ORG:' + (<?php echo wp_json_encode( get_option( 'frs_company_name', get_bloginfo( 'name' ) ) ); ?>),
                'TITLE:' + (profile.job_title || 'Loan Officer'),
            ];

            if (inc('include_email') && profile.email) {
                lines.push('EMAIL;TYPE=WORK:' + profile.email);
            }
            if (inc('include_office_phone') && profile.phone_number) {
                lines.push('TEL;TYPE=WORK:' + profile.phone_number);
            }
            if (inc('include_mobile') && profile.mobile_number) {
                lines.push('TEL;TYPE=CELL:' + profile.mobile_number);
            }

            const notes = [];
            if (inc('include_nmls') && profile.nmls) {
                notes.push('NMLS# ' + profile.nmls);
            }
            if (incBio && profile.biography) {
                notes.push(profile.biography.replace(/<[^>]*>/g, ''));
            }
            if (notes.length) {
                lines.push('NOTE:' + notes.join(' | '));
            }

            // Profile URL always included
            lines.push('URL:' + window.location.href);
            lines.push('END:VCARD');

            const vcard = lines.join('\r\n');
            const blob = new Blob([vcard], { type: 'text/vcard' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = (profile.first_name || 'contact') + '-' + (profile.last_name || '') + '.vcf';
            a.click();
            URL.revokeObjectURL(url);
        });
    }

    // Modal
    const modal = document.getElementById('contact-modal');
    const openModalBtn = document.getElementById('open-contact-modal');
    const closeModalBtn = document.getElementById('close-modal');
    const modalBackdrop = document.getElementById('modal-backdrop');

    function openModal() {
        modal.classList.add('frs-modal--open');
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        modal.classList.remove('frs-modal--open');
        document.body.style.overflow = '';
    }

    if (openModalBtn) openModalBtn.addEventListener('click', openModal);
    if (closeModalBtn) closeModalBtn.addEventListener('click', closeModal);
    if (modalBackdrop) modalBackdrop.addEventListener('click', closeModal);

    // Close on Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal.classList.contains('frs-modal--open')) {
            closeModal();
        }
    });

    // Fluent Form: Pre-fill hidden fields with LO data when modal opens
    const loData = document.getElementById('lo-contact-data');

    function prefillFluentForm() {
        if (!loData) return;
        const loId = loData.dataset.id;
        const loEmail = loData.dataset.email;
        const loName = loData.dataset.name;

        const modal = document.getElementById('contact-modal');
        if (!modal) return;

        const idFields = modal.querySelectorAll('input[name*="frs_loan_officer_id"], input[name*="loan_officer"]');
        idFields.forEach(f => f.value = loId);

        console.log('Prefilled LO data:', { loId, loEmail, loName });
    }

    // Prefill on page load
    prefillFluentForm();

    // Also prefill when modal opens (in case form loads late)
    if (openModalBtn) {
        openModalBtn.addEventListener('click', function() {
            setTimeout(prefillFluentForm, 100);
        });
    }

    // Close modal after Fluent Form submission
    document.addEventListener('fluentform_submission_success', function() {
        setTimeout(() => closeModal(), 1500);
    });
})();
</script>

<?php
get_footer();
