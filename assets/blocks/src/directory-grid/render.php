<?php
/**
 * Directory Grid Block - Server-side render
 *
 * Loads profile data and renders the card grid with proper Interactivity API state.
 * Uses exact same CSS classes as the original loan-officer-directory block.
 *
 * @package FRSUsers
 */

defined( 'ABSPATH' ) || exit;

$per_page       = $attributes['perPage'] ?? 12;
$show_count     = $attributes['showCount'] ?? true;
$show_load_more = $attributes['showLoadMore'] ?? true;

// Load profiles from local WordPress data.
$profiles_data = array();
$profiles      = \FRSUsers\Models\Profile::get_all( array( 'type' => 'loan_originator' ) );

if ( ! empty( $profiles ) ) {
	foreach ( $profiles as $profile ) {
		$profile_array = is_array( $profile ) ? $profile : ( method_exists( $profile, 'toArray' ) ? $profile->toArray() : (array) $profile );
		// Only include profiles with an NMLS number.
		$nmls = $profile_array['nmls'] ?? $profile_array['nmls_number'] ?? '';
		if ( ! empty( $nmls ) ) {
			$profiles_data[] = $profile_array;
		}
	}
}

// Sort alphabetically by first name, then last name.
usort(
	$profiles_data,
	function ( $a, $b ) {
		$first_cmp = strcasecmp( $a['first_name'] ?? '', $b['first_name'] ?? '' );
		if ( $first_cmp !== 0 ) {
			return $first_cmp;
		}
		return strcasecmp( $a['last_name'] ?? '', $b['last_name'] ?? '' );
	}
);

// Excluded names (configurable via WP option).
$excluded_names = get_option( 'frs_directory_excluded', array() );

// Filter out excluded names and deduplicate.
$seen_emails = array();
$profiles_data = array_values(
	array_filter(
		$profiles_data,
		function ( $p ) use ( $excluded_names, &$seen_emails ) {
			$full_name = trim( ( $p['first_name'] ?? '' ) . ' ' . ( $p['last_name'] ?? '' ) );
			if ( in_array( $full_name, $excluded_names, true ) ) {
				return false;
			}
			$email = strtolower( $p['email'] ?? '' );
			if ( $email && isset( $seen_emails[ $email ] ) ) {
				return false;
			}
			if ( $email ) {
				$seen_emails[ $email ] = true;
			}
			return true;
		}
	)
);

// State name mapping.
$state_names = array(
	'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California',
	'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware', 'FL' => 'Florida', 'GA' => 'Georgia',
	'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa',
	'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland',
	'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi', 'MO' => 'Missouri',
	'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey',
	'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio',
	'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina',
	'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont',
	'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
);
$all_states = array_keys( $state_names );

// Normalize state name to abbreviation.
$name_to_abbr = array_flip( $state_names );
$normalize_state = function ( $state ) use ( $name_to_abbr ) {
	if ( ! $state ) {
		return '';
	}
	if ( isset( $name_to_abbr[ $state ] ) ) {
		return $name_to_abbr[ $state ];
	}
	return strtoupper( $state );
};

// Extract service area counts.
$service_area_counts = array_fill_keys( $all_states, 0 );
foreach ( $profiles_data as $p ) {
	$areas = $p['service_areas'] ?? array();
	if ( is_string( $areas ) ) {
		$areas = json_decode( $areas, true ) ?: array();
	}
	if ( is_array( $areas ) ) {
		foreach ( $areas as $area ) {
			$abbr = $normalize_state( $area );
			if ( isset( $service_area_counts[ $abbr ] ) ) {
				$service_area_counts[ $abbr ]++;
			}
		}
	}
}
$available_service_areas = array_keys( array_filter( $service_area_counts, fn( $c ) => $c > 0 ) );

// Get initial filters from URL.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$initial_search = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$initial_areas = isset( $_GET['areas'] ) ? array_map( 'sanitize_text_field', explode( ',', wp_unslash( $_GET['areas'] ) ) ) : array();

// Apply filters to compute initial filtered profiles.
$filtered_profiles = $profiles_data;

if ( $initial_search ) {
	$q = strtolower( trim( $initial_search ) );
	$words = preg_split( '/\s+/', $q );
	$filtered_profiles = array_values(
		array_filter(
			$filtered_profiles,
			function ( $p ) use ( $words, $state_names, $normalize_state ) {
				$name = strtolower( ( $p['first_name'] ?? '' ) . ' ' . ( $p['last_name'] ?? '' ) );
				$loc = strtolower( $p['city_state'] ?? '' );
				$region = strtolower( $p['region'] ?? '' );
				$title = strtolower( $p['job_title'] ?? '' );
				$areas = $p['service_areas'] ?? array();
				if ( is_string( $areas ) ) {
					$areas = json_decode( $areas, true ) ?: array();
				}
				$areas_text = strtolower( implode( ' ', $areas ) );
				$state_full_names = array_map( fn( $a ) => strtolower( $state_names[ $normalize_state( $a ) ] ?? '' ), $areas );
				$search_text = $name . ' ' . $loc . ' ' . $region . ' ' . $title . ' ' . $areas_text . ' ' . implode( ' ', $state_full_names );
				foreach ( $words as $word ) {
					if ( $word && strpos( $search_text, $word ) !== false ) {
						return true;
					}
				}
				return false;
			}
		)
	);
}

if ( ! empty( $initial_areas ) ) {
	$filtered_profiles = array_values(
		array_filter(
			$filtered_profiles,
			function ( $p ) use ( $initial_areas, $normalize_state ) {
				$areas = $p['service_areas'] ?? array();
				if ( is_string( $areas ) ) {
					$areas = json_decode( $areas, true ) ?: array();
				}
				if ( ! is_array( $areas ) ) {
					return false;
				}
				$normalized = array_map( $normalize_state, $areas );
				foreach ( $initial_areas as $area ) {
					if ( in_array( $area, $normalized, true ) ) {
						return true;
					}
				}
				return false;
			}
		)
	);
}

$total_count     = count( $filtered_profiles );
$displayed_count = min( $per_page, $total_count );
$has_more        = $displayed_count < $total_count;
$has_filters     = ! empty( $initial_search ) || ! empty( $initial_areas );
$hub_url         = trailingslashit( home_url( '/' . \FRSUsers\Core\Roles::get_url_prefix( 'loan_officer' ) . '/' ) );

// Initialize Interactivity API state.
wp_interactivity_state(
	'frs/directory',
	array(
		'profiles'              => $profiles_data,
		'filteredProfiles'      => $filtered_profiles,
		'serviceAreaCounts'     => $service_area_counts,
		'availableServiceAreas' => $available_service_areas,
		'searchQuery'           => $initial_search,
		'selectedServiceAreas'  => $initial_areas,
		'perPage'               => $per_page,
		'displayedCount'        => $displayed_count,
		'isLoading'             => false,
		'isInitialized'         => true,
		'hubUrl'                => $hub_url,
		'excludedNames'         => $excluded_names,
		'stateNames'            => $state_names,
		// QR popup state.
		'qrPopupOpen'           => false,
		'qrImageSrc'            => '',
		'qrName'                => '',
		// Derived state (also computed in JS).
		'hasFilters'            => $has_filters,
		'hasMoreProfiles'       => $has_more,
		'totalCount'            => $total_count,
	)
);

$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class'               => 'frs-directory-grid',
		'data-wp-interactive' => 'frs/directory',
		'data-wp-init'        => 'callbacks.initEscapeHandler',
	)
);

/**
 * Helper function to format phone numbers.
 */
if ( ! function_exists( 'frs_format_phone' ) ) {
	function frs_format_phone( $phone ) {
		if ( ! $phone ) {
			return '';
		}
		$digits = preg_replace( '/\D/', '', $phone );
		if ( strlen( $digits ) === 10 ) {
			return sprintf( '(%s) %s-%s', substr( $digits, 0, 3 ), substr( $digits, 3, 3 ), substr( $digits, 6, 4 ) );
		}
		if ( strlen( $digits ) === 11 && $digits[0] === '1' ) {
			return sprintf( '(%s) %s-%s', substr( $digits, 1, 3 ), substr( $digits, 4, 3 ), substr( $digits, 7, 4 ) );
		}
		return $phone;
	}
}

/**
 * Helper function to get profile image.
 */
if ( ! function_exists( 'frs_get_profile_image' ) ) {
	function frs_get_profile_image( $profile ) {
		if ( ! empty( $profile['headshot_url'] ) && trim( $profile['headshot_url'] ) !== '' ) {
			return $profile['headshot_url'];
		}
		if ( ! empty( $profile['avatar_url'] ) && trim( $profile['avatar_url'] ) !== '' && strpos( $profile['avatar_url'], 'gravatar.com/avatar' ) === false ) {
			return $profile['avatar_url'];
		}
		return '';
	}
}
?>

<div <?php echo $wrapper_attributes; ?>>
	<!-- Loading State (hidden on server since isLoading=false) -->
	<div class="frs-directory__loading" data-wp-bind--hidden="!state.isLoading" hidden>
		<div class="frs-directory__spinner"></div>
		<p><?php esc_html_e( 'Loading loan officers...', 'frs-users' ); ?></p>
	</div>

	<!-- Main Layout -->
	<div class="frs-directory__layout" data-wp-bind--hidden="state.isLoading">
		<!-- Sidebar -->
		<aside class="frs-directory__sidebar">
			<div class="frs-sidebar__header">
				<h3><?php esc_html_e( 'Filter Results', 'frs-users' ); ?></h3>
				<button
					class="frs-sidebar__clear"
					data-wp-on-async--click="actions.clearFilters"
					data-wp-bind--hidden="!state.hasFilters"
					<?php echo ! $has_filters ? 'hidden' : ''; ?>
				>
					<?php esc_html_e( 'Clear All', 'frs-users' ); ?>
				</button>
			</div>

			<!-- Search -->
			<div class="frs-sidebar__section">
				<label class="frs-sidebar__label" for="frs-search"><?php esc_html_e( 'Search', 'frs-users' ); ?></label>
				<div class="frs-sidebar__input-wrap">
					<input
						type="text"
						id="frs-search"
						placeholder="<?php esc_attr_e( 'Name or location...', 'frs-users' ); ?>"
						class="frs-sidebar__input"
						value="<?php echo esc_attr( $initial_search ); ?>"
						data-wp-bind--value="state.searchQuery"
						data-wp-on--input="actions.setSearchQuery"
					>
					<svg class="frs-sidebar__input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
					</svg>
				</div>
			</div>

			<!-- Service Areas Filter -->
			<div class="frs-sidebar__section">
				<label class="frs-sidebar__label"><?php esc_html_e( 'Service Areas', 'frs-users' ); ?></label>
				<p class="frs-sidebar__hint"><?php esc_html_e( 'Click to filter by service area', 'frs-users' ); ?></p>
				<div class="frs-service-areas" id="frs-service-areas">
					<?php
					foreach ( $available_service_areas as $area ) :
						$is_selected = in_array( $area, $initial_areas, true );
						$count       = $service_area_counts[ $area ] ?? 0;
						$full_name   = $state_names[ $area ] ?? $area;
						$classes     = 'frs-service-area-chip';
						if ( $is_selected ) {
							$classes .= ' frs-service-area-chip--selected';
						}
						?>
						<div
							class="<?php echo esc_attr( $classes ); ?>"
							data-service-area="<?php echo esc_attr( $area ); ?>"
							title="<?php echo esc_attr( $full_name . ' (' . $count . ')' ); ?>"
							data-wp-on-async--click="actions.toggleServiceArea"
							data-wp-class--frs-service-area-chip--selected="state.selectedServiceAreas.includes('<?php echo esc_js( $area ); ?>')"
						>
							<?php echo esc_html( $area ); ?>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</aside>

		<!-- Main Content -->
		<main class="frs-directory__main">
			<?php if ( $show_count ) : ?>
				<div class="frs-directory__results-header">
					<span class="frs-directory__count">
						<span data-wp-text="state.totalCount"><?php echo esc_html( $total_count ); ?></span>
						<?php esc_html_e( 'loan officers', 'frs-users' ); ?>
					</span>
				</div>
			<?php endif; ?>

			<!-- Grid Container - Server-render initial cards -->
			<div
				class="frs-directory__grid"
				id="frs-grid"
			>
		<?php
		$visible_profiles = array_slice( $filtered_profiles, 0, $displayed_count );
		foreach ( $visible_profiles as $lo ) :
			$first_name     = $lo['first_name'] ?? '';
			$last_name      = $lo['last_name'] ?? '';
			$full_name      = trim( $first_name . ' ' . $last_name );
			$initials       = strtoupper( substr( $first_name, 0, 1 ) . substr( $last_name, 0, 1 ) ) ?: '?';
			$raw_title      = trim( $lo['job_title'] ?? '' );
			if ( $raw_title === '' || strcasecmp( $raw_title, 'Loan Originator' ) === 0 ) {
				$title = 'Loan Originator';
			} else {
				$title = 'Loan Originator / ' . $raw_title;
			}
			$nmls           = $lo['nmls'] ?? $lo['nmls_number'] ?? '';
			$email          = $lo['email'] ?? '';
			$phone          = $lo['phone_number'] ?? $lo['mobile_number'] ?? '';
			$phone_formatted = frs_format_phone( $phone );
			$headshot       = frs_get_profile_image( $lo );
			$slug           = $lo['profile_slug'] ?? $lo['id'] ?? '';
			$profile_url    = $hub_url . $slug . '/';
			$qr_data        = $lo['qr_code_data'] ?? '';
			$region         = $lo['region'] ?? '';
			$service_areas  = $lo['service_areas'] ?? array();
			if ( is_string( $service_areas ) ) {
				$service_areas = json_decode( $service_areas, true ) ?: array();
			}
			$normalized_areas = array_map( $normalize_state, $service_areas );
			$display_areas    = array_slice( $normalized_areas, 0, 4 );
			$remaining        = count( $normalized_areas ) - 4;
			?>
			<div class="frs-card">
				<div class="frs-card__header">
					<?php if ( $qr_data ) : ?>
						<button class="frs-card__qr-btn" aria-label="<?php esc_attr_e( 'Show QR code', 'frs-users' ); ?>" data-qr="<?php echo esc_attr( $qr_data ); ?>" data-name="<?php echo esc_attr( $full_name ); ?>" data-wp-on-async--click="actions.openQrPopup">
							<svg viewBox="0 0 24 24" fill="none" stroke="url(#qr-grad-<?php echo esc_attr( $slug ); ?>)" stroke-width="2">
								<defs><linearGradient id="qr-grad-<?php echo esc_attr( $slug ); ?>" x1="0%" y1="0%" x2="100%" y2="0%"><stop offset="0%" stop-color="#2dd4da"/><stop offset="100%" stop-color="#2563eb"/></linearGradient></defs>
								<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
								<rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="3" height="3"/>
								<rect x="18" y="14" width="3" height="3"/><rect x="14" y="18" width="3" height="3"/>
								<rect x="18" y="18" width="3" height="3"/>
							</svg>
						</button>
					<?php endif; ?>
				</div>
				<div class="frs-card__avatar">
					<?php if ( $headshot ) : ?>
						<img src="<?php echo esc_url( $headshot ); ?>" alt="<?php echo esc_attr( $full_name ); ?>" loading="lazy">
					<?php else : ?>
						<div class="frs-card__avatar-placeholder"><?php echo esc_html( $initials ); ?></div>
					<?php endif; ?>
				</div>
				<div class="frs-card__content">
					<h3 class="frs-card__name"><?php echo esc_html( $full_name ); ?></h3>
					<p class="frs-card__title"><?php echo esc_html( $title ); ?></p>
					<?php if ( $nmls ) : ?>
						<p class="frs-card__nmls">NMLS# <?php echo esc_html( $nmls ); ?></p>
					<?php endif; ?>
					<?php if ( $region ) : ?>
						<p class="frs-card__region">
							<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
							<?php echo esc_html( $region ); ?>
						</p>
					<?php endif; ?>
					<?php if ( ! empty( $display_areas ) ) : ?>
						<div class="frs-card__service-areas">
							<?php foreach ( $display_areas as $area ) : ?>
								<span class="frs-card__area-tag"><?php echo esc_html( $area ); ?></span>
							<?php endforeach; ?>
							<?php if ( $remaining > 0 ) : ?>
								<span class="frs-card__area-tag frs-card__area-tag--more">+<?php echo esc_html( $remaining ); ?> more</span>
							<?php endif; ?>
						</div>
					<?php endif; ?>
					<div class="frs-card__contact">
						<?php if ( $phone_formatted ) : ?>
							<div class="frs-card__contact-row">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
								<a href="tel:<?php echo esc_attr( preg_replace( '/\D/', '', $phone ) ); ?>"><?php echo esc_html( $phone_formatted ); ?></a>
							</div>
						<?php endif; ?>
						<?php if ( $email ) : ?>
							<div class="frs-card__contact-row">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
								<a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a>
							</div>
						<?php endif; ?>
					</div>
				</div>
				<div class="frs-card__actions">
					<a href="<?php echo esc_url( $profile_url ); ?>" class="frs-card__btn frs-card__btn--primary"><?php esc_html_e( 'View Profile', 'frs-users' ); ?></a>
				</div>
			</div>
		<?php endforeach; ?>
			</div>

			<!-- No Results -->
			<div 
				class="frs-directory__no-results" 
				data-wp-bind--hidden="state.totalCount > 0 || state.isLoading"
				<?php echo $total_count > 0 ? 'hidden' : ''; ?>
			>
				<p><?php esc_html_e( 'No loan officers found matching your criteria.', 'frs-users' ); ?></p>
				<button 
					class="frs-btn frs-btn--outline"
					data-wp-on-async--click="actions.clearFilters"
				>
					<?php esc_html_e( 'Clear Filters', 'frs-users' ); ?>
				</button>
			</div>

			<?php if ( $show_load_more ) : ?>
				<!-- Load More -->
				<div 
					class="frs-directory__load-more"
					data-wp-bind--hidden="!state.hasMoreProfiles"
					<?php echo ! $has_more ? 'hidden' : ''; ?>
				>
					<button 
						class="frs-btn frs-btn--outline"
						data-wp-on-async--click="actions.loadMore"
					>
						<?php esc_html_e( 'Load More', 'frs-users' ); ?>
					</button>
				</div>
			<?php endif; ?>

			<!-- Clear Filters (below Load More, only when filters active) -->
			<div
				class="frs-directory__clear-filters"
				data-wp-bind--hidden="!state.hasFilters"
				<?php echo ! $has_filters ? 'hidden' : ''; ?>
			>
				<button
					class="frs-btn frs-btn--outline"
					data-wp-on-async--click="actions.clearFilters"
				>
					<?php esc_html_e( 'Clear Filters', 'frs-users' ); ?>
				</button>
			</div>
		</main>
	</div><!-- .frs-directory__layout -->

	<!-- QR Popup -->
	<div class="frs-qr-popup" id="frs-qr-popup" data-wp-class--frs-qr-popup--open="state.qrPopupOpen">
		<div class="frs-qr-popup__backdrop" data-wp-on-async--click="actions.closeQrPopup"></div>
		<div class="frs-qr-popup__content">
			<button class="frs-qr-popup__close" data-wp-on-async--click="actions.closeQrPopup" aria-label="<?php esc_attr_e( 'Close', 'frs-users' ); ?>">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
					<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
				</svg>
			</button>
			<div class="frs-qr-popup__qr">
				<img id="frs-qr-image" data-wp-bind--src="state.qrImageSrc" src="" alt="QR Code">
			</div>
			<p class="frs-qr-popup__name" data-wp-text="state.qrName"></p>
			<p class="frs-qr-popup__hint"><?php esc_html_e( 'Scan to view profile', 'frs-users' ); ?></p>
		</div>
	</div>
</div>
