/**
 * Directory Grid Block - View Script
 *
 * Handles filtering and rendering of loan officer cards.
 * State is initialized server-side via wp_interactivity_state().
 */
import { store, getElement } from '@wordpress/interactivity';

/**
 * Format phone number for display.
 */
function formatPhone( phone ) {
	if ( ! phone ) return '';
	const digits = phone.replace( /\D/g, '' );
	if ( digits.length === 10 ) {
		return `(${ digits.slice( 0, 3 ) }) ${ digits.slice( 3, 6 ) }-${ digits.slice( 6 ) }`;
	}
	if ( digits.length === 11 && digits[ 0 ] === '1' ) {
		return `(${ digits.slice( 1, 4 ) }) ${ digits.slice( 4, 7 ) }-${ digits.slice( 7 ) }`;
	}
	return phone;
}

/**
 * Normalize state name to abbreviation.
 */
function normalizeState( state, stateNames ) {
	if ( ! state ) return '';
	// Check if it's already an abbreviation
	if ( stateNames[ state ] ) return state;
	// Find abbreviation from full name
	for ( const [ abbr, name ] of Object.entries( stateNames ) ) {
		if ( name === state ) return abbr;
	}
	return state.toUpperCase();
}

/**
 * Get profile image URL.
 */
function getProfileImage( profile ) {
	if ( profile.headshot_url && profile.headshot_url.trim() !== '' ) {
		return profile.headshot_url;
	}
	if ( profile.avatar_url && profile.avatar_url.trim() !== '' && ! profile.avatar_url.includes( 'gravatar.com/avatar' ) ) {
		return profile.avatar_url;
	}
	return '';
}

/**
 * Create a card element for a loan officer.
 */
function createCard( lo, hubUrl, stateNames ) {
	const firstName = lo.first_name || '';
	const lastName = lo.last_name || '';
	const fullName = `${ firstName } ${ lastName }`.trim();
	const initials = ( firstName.charAt( 0 ) + lastName.charAt( 0 ) ).toUpperCase() || '?';
	const rawTitle = ( lo.job_title || '' ).trim();
	const title = ( ! rawTitle || rawTitle.toLowerCase() === 'loan originator' )
		? 'Loan Originator'
		: `Loan Originator / ${ rawTitle }`;
	const nmls = lo.nmls || lo.nmls_number || '';
	const email = lo.email || '';
	const phone = lo.phone_number || lo.mobile_number || '';
	const phoneFormatted = formatPhone( phone );
	const headshot = getProfileImage( lo );
	const slug = lo.profile_slug || lo.id;
	const profileUrl = `${ hubUrl }${ slug }/`;
	const qrData = lo.qr_code_data || '';
	const region = lo.region || '';
	let serviceAreas = lo.service_areas || [];

	if ( typeof serviceAreas === 'string' ) {
		try {
			serviceAreas = JSON.parse( serviceAreas );
		} catch ( e ) {
			serviceAreas = [];
		}
	}

	// Region line (placed above service-area tags)
	const regionLine = region
		? `<p class="frs-card__region"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>${ region }</p>`
		: '';

	const card = document.createElement( 'div' );
	card.className = 'frs-card';

	// Service areas tags
	let serviceAreasTags = '';
	if ( Array.isArray( serviceAreas ) && serviceAreas.length > 0 ) {
		const normalizedAreas = serviceAreas.map( ( a ) => normalizeState( a, stateNames ) );
		const displayAreas = normalizedAreas.slice( 0, 4 );
		const remaining = normalizedAreas.length - 4;
		serviceAreasTags = `<div class="frs-card__service-areas">${ displayAreas.map( ( a ) => `<span class="frs-card__area-tag">${ a }</span>` ).join( '' ) }${ remaining > 0 ? `<span class="frs-card__area-tag frs-card__area-tag--more">+${ remaining } more</span>` : '' }</div>`;
	}

	card.innerHTML = `
		<div class="frs-card__header">
			${ qrData ? `<button class="frs-card__qr-btn" aria-label="Show QR code" data-qr="${ qrData }" data-name="${ fullName }">
				<svg viewBox="0 0 24 24" fill="none" stroke="url(#qr-grad-${ slug })" stroke-width="2">
					<defs><linearGradient id="qr-grad-${ slug }" x1="0%" y1="0%" x2="100%" y2="0%"><stop offset="0%" stop-color="#2dd4da"/><stop offset="100%" stop-color="#2563eb"/></linearGradient></defs>
					<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
					<rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="3" height="3"/>
					<rect x="18" y="14" width="3" height="3"/><rect x="14" y="18" width="3" height="3"/>
					<rect x="18" y="18" width="3" height="3"/>
				</svg>
			</button>` : '' }
		</div>
		<div class="frs-card__avatar">
			${ headshot ? `<img src="${ headshot }" alt="${ fullName }" loading="lazy">` : `<div class="frs-card__avatar-placeholder">${ initials }</div>` }
		</div>
		<div class="frs-card__content">
			<h3 class="frs-card__name">${ fullName }</h3>
			<p class="frs-card__title">${ title }</p>
			${ nmls ? `<p class="frs-card__nmls">NMLS# ${ nmls }</p>` : '' }
			${ regionLine }
			${ serviceAreasTags }
			<div class="frs-card__contact">
				${ phoneFormatted ? `<div class="frs-card__contact-row"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg><a href="tel:${ phone.replace( /\D/g, '' ) }">${ phoneFormatted }</a></div>` : '' }
				${ email ? `<div class="frs-card__contact-row"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg><a href="mailto:${ email }">${ email }</a></div>` : '' }
			</div>
		</div>
		<div class="frs-card__actions">
			<a href="${ profileUrl }" class="frs-card__btn frs-card__btn--primary">View Profile</a>
		</div>
	`;

	return card;
}

/**
 * Store definition - state is initialized server-side via wp_interactivity_state().
 */
const { state, actions } = store( 'frs/directory', {
	state: {
		// Derived state getters (also computed server-side for SSR)
		get hasFilters() {
			return ( state.searchQuery && state.searchQuery.length > 0 ) || ( state.selectedServiceAreas && state.selectedServiceAreas.length > 0 );
		},
		get hasMoreProfiles() {
			return state.displayedCount < state.filteredProfiles.length;
		},
		get totalCount() {
			return state.filteredProfiles ? state.filteredProfiles.length : 0;
		},
	},

	actions: {
		/**
		 * Set search query from input event.
		 */
		setSearchQuery( event ) {
			state.searchQuery = event.target.value;
			actions.applyFilters();
			actions.updateURL();
			actions.renderGrid();
		},

		/**
		 * Handle search form submission.
		 */
		onSearchSubmit( event ) {
			event.preventDefault();
		},

		/**
		 * Toggle a service area filter.
		 */
		toggleServiceArea() {
			const element = getElement();
			const area = element.ref.dataset.serviceArea;

			if ( ! area ) return;

			if ( state.selectedServiceAreas.includes( area ) ) {
				state.selectedServiceAreas = state.selectedServiceAreas.filter( ( a ) => a !== area );
			} else {
				state.selectedServiceAreas = [ ...state.selectedServiceAreas, area ];
			}

			actions.applyFilters();
			actions.updateURL();
			actions.renderGrid();
		},

		/**
		 * Clear all filters.
		 */
		clearFilters() {
			state.searchQuery = '';
			state.selectedServiceAreas = [];

			actions.applyFilters();
			actions.updateURL();
			actions.renderGrid();
		},

		/**
		 * Apply current filters to profiles.
		 */
		applyFilters() {
			// Only include profiles with an NMLS number.
			let filtered = state.profiles.filter( ( p ) => p.nmls || p.nmls_number );
			const stateNames = state.stateNames || {};

			// Search filter
			if ( state.searchQuery ) {
				const q = state.searchQuery.toLowerCase().trim();
				const words = q.split( /\s+/ ).filter( ( w ) => w.length > 0 );

				filtered = filtered.filter( ( p ) => {
					const name = `${ p.first_name || '' } ${ p.last_name || '' }`.toLowerCase();
					const loc = ( p.city_state || '' ).toLowerCase();
					const region = ( p.region || '' ).toLowerCase();
					const title = ( p.job_title || '' ).toLowerCase();

					let rawAreas = p.service_areas || [];
					if ( typeof rawAreas === 'string' ) {
						try {
							rawAreas = JSON.parse( rawAreas );
						} catch ( e ) {
							rawAreas = [];
						}
					}
					const areas = Array.isArray( rawAreas ) ? rawAreas : [];
					const areasText = areas.map( ( a ) => a.toLowerCase() ).join( ' ' );
					const stateFullNames = areas.map( ( a ) => ( stateNames[ normalizeState( a, stateNames ) ] || '' ).toLowerCase() ).join( ' ' );

					const searchText = `${ name } ${ loc } ${ region } ${ title } ${ areasText } ${ stateFullNames }`;
					return words.some( ( word ) => searchText.includes( word ) );
				} );
			}

			// Service areas filter
			if ( state.selectedServiceAreas && state.selectedServiceAreas.length > 0 ) {
				filtered = filtered.filter( ( p ) => {
					let areas = p.service_areas;
					if ( typeof areas === 'string' ) {
						try {
							areas = JSON.parse( areas );
						} catch ( e ) {
							return false;
						}
					}
					if ( ! areas || ! Array.isArray( areas ) ) return false;
					const normalizedAreas = areas.map( ( a ) => normalizeState( a, stateNames ) );
					return state.selectedServiceAreas.some( ( area ) => normalizedAreas.includes( area ) );
				} );
			}

			state.filteredProfiles = filtered;
			state.displayedCount = Math.min( state.perPage, filtered.length );
		},

		/**
		 * Load more profiles.
		 */
		loadMore() {
			state.displayedCount = Math.min(
				state.displayedCount + state.perPage,
				state.filteredProfiles.length
			);
			actions.renderGrid();
		},

		/**
		 * Update URL with current filters.
		 */
		updateURL() {
			const params = new URLSearchParams();
			if ( state.searchQuery ) params.set( 'search', state.searchQuery );
			if ( state.selectedServiceAreas && state.selectedServiceAreas.length ) {
				params.set( 'areas', state.selectedServiceAreas.join( ',' ) );
			}

			const newURL = params.toString()
				? `${ window.location.pathname }?${ params }`
				: window.location.pathname;
			window.history.replaceState( {}, '', newURL );
		},

		/**
		 * Render the card grid.
		 */
		renderGrid() {
			const grid = document.getElementById( 'frs-grid' );
			if ( ! grid ) return;

			grid.innerHTML = '';

			const visibleProfiles = state.filteredProfiles.slice( 0, state.displayedCount );
			const stateNames = state.stateNames || {};

			visibleProfiles.forEach( ( lo ) => {
				grid.appendChild( createCard( lo, state.hubUrl, stateNames ) );
			} );

			// Attach QR popup handlers to dynamically created cards
			// (innerHTML cards don't get Interactivity API directives)
			grid.querySelectorAll( '.frs-card__qr-btn' ).forEach( ( btn ) => {
				btn.addEventListener( 'click', () => {
					const qrData = btn.dataset.qr;
					const name = btn.dataset.name;
					if ( qrData ) {
						state.qrImageSrc = qrData;
						state.qrName = name || '';
						state.qrPopupOpen = true;
					}
				} );
			} );

		},

		/**
		 * Open QR popup from a card's QR button click.
		 */
		openQrPopup() {
			const element = getElement();
			const qrData = element.ref.dataset.qr;
			const name = element.ref.dataset.name;
			if ( qrData ) {
				state.qrImageSrc = qrData;
				state.qrName = name || '';
				state.qrPopupOpen = true;
			}
		},

		/**
		 * Close QR popup.
		 */
		closeQrPopup() {
			state.qrPopupOpen = false;
		},

	},

	callbacks: {
		/**
		 * Listen for Escape key to close QR popup.
		 */
		initEscapeHandler() {
			document.addEventListener( 'keydown', ( e ) => {
				if ( e.key === 'Escape' ) {
					if ( state.qrPopupOpen ) {
						state.qrPopupOpen = false;
					}
				}
			} );
		},
	},
} );
