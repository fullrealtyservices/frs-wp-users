<?php
/**
 * LO Profile Template - Bento Card Layout
 *
 * Displays a single loan officer profile page with bento card design.
 *
 * @package FRSProfileDirectory
 *
 * @var array|null $profile Profile data from API.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// Prevent page caching - this page shows dynamic profile data
if (!defined('DONOTCACHEPAGE')) {
    define('DONOTCACHEPAGE', true);
}
nocache_headers();

// Check for remote profile data first (set by TemplateLoader on marketing sites).
$profile = get_query_var( 'frs_remote_profile', null );

// Fall back to local WordPress user data.
if ( ! $profile ) {
    $author = get_queried_object();
    if ($author && ($author instanceof \WP_User) && in_array('loan_officer', $author->roles ?? [], true)) {
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
            'job_title' => $user_profile->get_job_title() ?: 'Loan Officer',
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
    }
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
$job_title = $profile['job_title'] ?? 'Loan Officer';
$raw_nmls = $profile['nmls'] ?? '';
// Hide fake placeholder NMLS (1994xxx range)
$nmls = preg_match('/^1994\d{3}$/', $raw_nmls) ? '' : $raw_nmls;
$email = $profile['email'] ?? '';
$secondary_email = $profile['secondary_email'] ?? '';
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

// Enqueue Interactivity API script for profile
wp_enqueue_script_module(
    'frs-profile-view',
    FRS_USERS_URL . 'assets/js/profile-view.js',
    array( '@wordpress/interactivity' )
);

get_header();

// Build JSON-LD structured data for SEO
$profile_url = home_url( '/lo/' . $profile_slug . '/' );
$company_name = get_bloginfo( 'name' ) ?: '21st Century Lending';
$company_url = home_url();

$json_ld = [
    '@context' => 'https://schema.org',
    '@type' => 'Person',
    'name' => $full_name,
    'givenName' => $first_name,
    'familyName' => $last_name,
    'jobTitle' => $job_title,
    'url' => $profile_url,
    'worksFor' => [
        '@type' => 'Organization',
        'name' => $company_name,
        'url' => $company_url,
    ],
];

// Add optional fields only if they have values
if ( $headshot_url ) {
    $json_ld['image'] = $headshot_url;
}
if ( $email ) {
    $json_ld['email'] = 'mailto:' . $email;
}
if ( $phone ) {
    $json_ld['telephone'] = $phone;
}
if ( $bio ) {
    $json_ld['description'] = wp_strip_all_tags( $bio );
}
if ( $location ) {
    $json_ld['workLocation'] = [
        '@type' => 'Place',
        'name' => $location,
    ];
}
if ( $nmls ) {
    // NMLS is a credential/identifier for mortgage professionals
    $json_ld['hasCredential'] = [
        '@type' => 'EducationalOccupationalCredential',
        'credentialCategory' => 'license',
        'name' => 'NMLS License',
        'identifier' => $nmls,
        'recognizedBy' => [
            '@type' => 'Organization',
            'name' => 'Nationwide Multistate Licensing System',
            'url' => 'https://www.nmlsconsumeraccess.org/',
        ],
    ];
}

// Add social/same-as links
$same_as = array_filter( [ $linkedin, $facebook, $instagram, $twitter, $website ] );
if ( ! empty( $same_as ) ) {
    $json_ld['sameAs'] = array_values( $same_as );
}

// Add service areas as areaServed
if ( ! empty( $service_areas ) ) {
    $areas_served = [];
    foreach ( $service_areas as $area ) {
        $state_name = $area['state'] ?? '';
        if ( $state_name ) {
            $areas_served[] = [
                '@type' => 'State',
                'name' => $state_name,
            ];
        }
    }
    if ( ! empty( $areas_served ) ) {
        $json_ld['worksFor']['areaServed'] = $areas_served;
    }
}

// Add specialties as knowsAbout
if ( ! empty( $specialties ) ) {
    $json_ld['knowsAbout'] = $specialties;
}
?>

<script type="application/ld+json">
<?php echo wp_json_encode( $json_ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ); ?>
</script>

<?php
$directory_url = get_option( 'frs_directory_url', '' ) ?: home_url( '/directory/' );
$directory_url = apply_filters( 'frs_directory_url', $directory_url );
?>
<div class="frs-profile" id="frs-profile">
    <!-- Back to Directory -->
    <a href="<?php echo esc_url( $directory_url ); ?>" class="frs-profile__back">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><polyline points="15 18 9 12 15 6"/></svg>
        <?php esc_html_e( 'Back to Directory', 'frs-users' ); ?>
    </a>

    <!-- Row 1: Profile Card + Action Buttons/Service Areas -->
    <div class="frs-profile__row frs-profile__row--main">
        <!-- Profile Card (65%) -->
        <div class="frs-profile__card frs-profile__card--header">
            <!-- Video Header -->
            <div class="frs-profile__hero">
                <?php if ($video_url) : ?>
                    <video autoplay loop muted playsinline>
                        <source src="<?php echo esc_url($video_url); ?>" type="video/mp4">
                    </video>
                <?php else : ?>
                    <div class="frs-profile__hero-fallback"></div>
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
                        <!-- QR button on front face -->
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
                        <!-- Avatar button on back face -->
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
                    <?php if ($secondary_email) : ?>
                        <a href="mailto:<?php echo esc_attr($secondary_email); ?>" class="frs-profile__contact-item">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="20" height="20">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M8 12h8M12 8v8"/>
                            </svg>
                            <?php echo esc_html($secondary_email); ?>
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
                <!-- Contact form button (always present) -->
                <button class="frs-profile__action-btn" id="open-contact-modal">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                        <polyline points="22,6 12,13 2,6"/>
                    </svg>
                    Contact <?php echo esc_html($first_name); ?>
                </button>
                <?php if ($phone) : ?>
                <a href="tel:<?php echo esc_attr(preg_replace('/[^\d+]/', '', $phone)); ?>" class="frs-profile__action-btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
                    </svg>
                    Call Me
                </a>
                <?php endif; ?>
                <!-- Book Appointment: links out if booking URL exists, otherwise opens contact modal -->
                <?php if ($booking_url) : ?>
                <a href="<?php echo esc_url($booking_url); ?>" class="frs-profile__action-btn frs-profile__action-btn--book" target="_blank" rel="noopener">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                        <line x1="16" y1="2" x2="16" y2="6"/>
                        <line x1="8" y1="2" x2="8" y2="6"/>
                        <line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                    Book Appointment
                </a>
                <?php else : ?>
                <button class="frs-profile__action-btn frs-profile__action-btn--book" id="open-book-modal">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                        <line x1="16" y1="2" x2="16" y2="6"/>
                        <line x1="8" y1="2" x2="8" y2="6"/>
                        <line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                    Book Appointment
                </button>
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
            <!-- Hidden data for Fluent Form to pick up -->
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

/* Back to Directory */
.frs-profile__back {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--frs-text-light);
    text-decoration: none;
    margin-bottom: 1rem;
    transition: color 0.15s;
}

.frs-profile__back:hover {
    color: var(--frs-blue);
}

.frs-profile__back svg {
    flex-shrink: 0;
}

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

/* Avatar - scoped to .frs-profile to avoid conflict with profile-editor block */
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
    border-radius: 50%;
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

.frs-profile__nmls--pending {
    color: #9ca3af;
    font-style: italic;
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

.frs-modal__form {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

.frs-modal__field {
    position: relative;
    width: 100%;
}

.frs-modal__input,
.frs-modal__textarea {
    width: 100%;
    padding: 1rem 1rem 0.75rem;
    border: none;
    border-radius: 12px;
    font-size: 1rem;
    font-family: inherit;
    transition: box-shadow 0.2s, background 0.2s;
    background: #f3f4f6;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.frs-modal__input:focus,
.frs-modal__textarea:focus {
    outline: none;
    box-shadow: 0 0 0 2px var(--frs-blue), 0 4px 12px rgba(37, 99, 235, 0.1);
    background: white;
}

.frs-modal__label {
    position: absolute;
    left: 1rem;
    top: 50%;
    transform: translateY(-50%);
    font-size: 1rem;
    color: var(--frs-text-light);
    pointer-events: none;
    transition: all 0.2s ease;
    background: transparent;
    padding: 0;
}

.frs-modal__textarea ~ .frs-modal__label {
    top: 1rem;
    transform: none;
}

.frs-modal__input:focus ~ .frs-modal__label,
.frs-modal__input:not(:placeholder-shown) ~ .frs-modal__label,
.frs-modal__textarea:focus ~ .frs-modal__label,
.frs-modal__textarea:not(:placeholder-shown) ~ .frs-modal__label {
    top: 0;
    transform: translateY(-50%);
    font-size: 0.75rem;
    color: var(--frs-blue);
    background: white;
    padding: 0.125rem 0.5rem;
    border-radius: 4px;
}

.frs-modal__textarea {
    resize: vertical;
    min-height: 100px;
    padding-top: 1.25rem;
}

.frs-modal__submit {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 1rem;
    border: none;
    border-radius: 10px;
    background: linear-gradient(135deg, var(--frs-blue), var(--frs-cyan));
    color: white;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: opacity 0.2s, transform 0.1s;
    margin-top: 0.5rem;
}

.frs-modal__submit:hover {
    opacity: 0.95;
}

.frs-modal__submit:active {
    transform: scale(0.98);
}

.frs-modal__submit:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

/* Spin animation for loading */
@keyframes frs-spin {
    to { transform: rotate(360deg); }
}

.frs-spin {
    animation: frs-spin 0.8s linear infinite;
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
    /* No filter - let the SVG gradient show through */
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
</style>

<script>
(function() {
    // Save Contact (vCard)
    const saveBtn = document.getElementById('save-contact-btn');
    if (saveBtn) {
        saveBtn.addEventListener('click', function() {
            const profile = <?php echo wp_json_encode($profile); ?>;
            const vcard = [
                'BEGIN:VCARD',
                'VERSION:3.0',
                'FN:' + (profile.first_name || '') + ' ' + (profile.last_name || ''),
                'N:' + (profile.last_name || '') + ';' + (profile.first_name || '') + ';;;',
                'ORG:uMortgage',
                'TITLE:' + (profile.job_title || 'Loan Officer'),
                profile.email ? 'EMAIL;TYPE=WORK:' + profile.email : '',
                profile.phone_number ? 'TEL;TYPE=WORK:' + profile.phone_number : '',
                profile.mobile_number ? 'TEL;TYPE=CELL:' + profile.mobile_number : '',
                profile.nmls ? 'NOTE:NMLS# ' + profile.nmls : '',
                'URL:' + window.location.href,
                'END:VCARD'
            ].filter(Boolean).join('\r\n');

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

    const openBookBtn = document.getElementById('open-book-modal');

    if (openModalBtn) openModalBtn.addEventListener('click', openModal);
    if (openBookBtn) openBookBtn.addEventListener('click', openModal);
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

        // Find hidden fields in Fluent Form - look for frs_loan_officer_id field
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
    if (openBookBtn) {
        openBookBtn.addEventListener('click', function() {
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
