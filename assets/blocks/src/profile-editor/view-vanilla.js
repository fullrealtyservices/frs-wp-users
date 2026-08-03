/**
 * Profile Editor - Vanilla JS Implementation
 * Handles edit mode toggle, QR flip, preview devices, and save functionality.
 */
(function() {
	'use strict';

	// Global toast for notifications
	let globalToast = null;

	function createGlobalToast() {
		if (globalToast) return globalToast;
		globalToast = document.createElement('div');
		globalToast.className = 'frs-profile__toast';
		globalToast.hidden = true;
		document.body.appendChild(globalToast);
		return globalToast;
	}

	function showGlobalToast(message, type, duration) {
		var toast = createGlobalToast();
		toast.textContent = message;
		toast.className = 'frs-profile__toast frs-profile__toast--' + (type || 'success');
		toast.hidden = false;
		clearTimeout(toast._timeout);
		toast._timeout = setTimeout(function() {
			toast.hidden = true;
		}, duration || 3000);
	}

	// Field validation helpers
	function validateEmail(email) {
		if (!email) return true; // Optional
		return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
	}

	function validateUrl(url) {
		if (!url) return true; // Optional
		try {
			new URL(url);
			return true;
		} catch (e) {
			return false;
		}
	}

	function validatePhone(phone) {
		if (!phone) return true; // Optional
		// Allow digits, spaces, dashes, parentheses, plus sign
		return /^[\d\s\-\(\)\+\.]+$/.test(phone) && phone.replace(/\D/g, '').length >= 10;
	}

	// Mirrors PhoneFormatter::us() on the PHP side so the post-save DOM patch
	// matches what a fresh page load would render.
	function formatPhoneDisplay(phone) {
		if (!phone) return phone;
		var digits = phone.replace(/\D/g, '');
		if (digits.length === 11 && digits[0] === '1') {
			digits = digits.slice(1);
		}
		if (digits.length !== 10) return phone;
		return '(' + digits.slice(0, 3) + ') ' + digits.slice(3, 6) + '-' + digits.slice(6);
	}

	function setFieldError(input, message) {
		if (!input) return;
		input.classList.add('frs-profile__edit-input--error');
		// Remove existing error
		var existing = input.parentNode.querySelector('.frs-profile__field-error');
		if (existing) existing.remove();
		// Add error message
		if (message) {
			var errorEl = document.createElement('span');
			errorEl.className = 'frs-profile__field-error';
			errorEl.textContent = message;
			input.parentNode.appendChild(errorEl);
		}
	}

	function clearFieldError(input) {
		if (!input) return;
		input.classList.remove('frs-profile__edit-input--error');
		var existing = input.parentNode.querySelector('.frs-profile__field-error');
		if (existing) existing.remove();
	}

	function clearAllFieldErrors(container) {
		container.querySelectorAll('.frs-profile__edit-input--error').forEach(function(input) {
			clearFieldError(input);
		});
	}

	function initProfileEditor() {
		const container = document.getElementById('frs-profile');
		if (!container) return;

		// State
		let isEditing = false;
		let isFlipped = false;
		let previewDevice = 'desktop';
		let isSaving = false;

		// Elements
		const avatarInner = container.querySelector('.frs-profile__avatar-inner');
		const previewFrame = container.querySelector('.frs-profile__preview-frame');
		const previewContent = container.querySelector('.frs-profile__preview-content');
		const previewBtns = container.querySelectorAll('.frs-profile__preview-btn');
		const editBtn = container.querySelector('.frs-profile__bar-edit-btn');
		const cancelBtn = container.querySelector('.frs-profile__bar-cancel-btn');
		const saveBtn = container.querySelector('.frs-profile__bar-save-btn');
		const saveText = container.querySelector('.frs-profile__save-text');
		const flipBtns = container.querySelectorAll('.frs-profile__qr-toggle');
		const viewElements = container.querySelectorAll('[data-view-mode]');
		const editElements = container.querySelectorAll('[data-edit-mode]');
		const avatarUploadBtn = container.querySelector('#avatar-upload-trigger');
		const avatarFileInput = container.querySelector('#avatar-file-input');
		const avatarImg = container.querySelector('.frs-profile__avatar-front img');

		// Avatar Upload
		if (avatarUploadBtn && avatarFileInput) {
			avatarUploadBtn.addEventListener('click', function(e) {
				e.preventDefault();
				avatarFileInput.click();
			});

			avatarFileInput.addEventListener('change', async function(e) {
				const file = e.target.files[0];
				if (!file) return;

				// Validate file type
				if (!file.type.startsWith('image/')) {
					showGlobalToast('Please select an image file', 'error', 4000);
					return;
				}

				// Validate file size (max 5MB)
				if (file.size > 5 * 1024 * 1024) {
					showGlobalToast('Image must be less than 5MB', 'error', 4000);
					return;
				}

				const config = window.frsProfileEditor || {};
				const userId = config.userId;

				if (!userId) {
					showGlobalToast('User ID not found', 'error', 4000);
					return;
				}

				// Show uploading state
				avatarUploadBtn.textContent = 'Uploading...';
				avatarUploadBtn.disabled = true;

				try {
					// Create FormData for file upload
					const formData = new FormData();
					formData.append('file', file);

					// Upload via WP REST API media endpoint
					const uploadResponse = await fetch('/wp-json/wp/v2/media', {
						method: 'POST',
						headers: {
							'X-WP-Nonce': config.nonce || ''
						},
						body: formData
					});

					if (!uploadResponse.ok) {
						throw new Error('Failed to upload image');
					}

					const mediaData = await uploadResponse.json();
					const attachmentId = mediaData.id;
					const imageUrl = mediaData.source_url;

					// Update user's headshot meta
					const updateResponse = await fetch('/wp-json/frs-users/v1/profiles/' + userId, {
						method: 'PUT',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': config.nonce || ''
						},
						body: JSON.stringify({ headshot_id: attachmentId })
					});

					if (!updateResponse.ok) {
						throw new Error('Failed to update profile');
					}

					// Update avatar image on page
					if (avatarImg) {
						avatarImg.src = imageUrl;
					}

					// Reset button
					avatarUploadBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> Change Photo';
					avatarUploadBtn.disabled = false;

				} catch (err) {
					showGlobalToast('Upload failed: ' + err.message, 'error', 5000);
					avatarUploadBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> Change Photo';
					avatarUploadBtn.disabled = false;
				}

				// Clear file input
				avatarFileInput.value = '';
			});
		}

		// QR Flip
		flipBtns.forEach(function(btn) {
			btn.addEventListener('click', function(e) {
				e.preventDefault();
				isFlipped = !isFlipped;
				if (avatarInner) {
					avatarInner.classList.toggle('frs-profile__avatar-inner--flipped', isFlipped);
				}
			});
		});

		// Preview device buttons
		previewBtns.forEach(function(btn) {
			btn.addEventListener('click', function(e) {
				e.preventDefault();
				const span = this.querySelector('span');
				const device = span ? span.textContent.trim().toLowerCase() : 'desktop';
				previewDevice = device;

				// Update active states
				previewBtns.forEach(function(b) { b.classList.remove('active'); });
				this.classList.add('active');

				// Update frame classes
				if (previewFrame) {
					previewFrame.classList.remove('frs-profile__preview-frame--tablet', 'frs-profile__preview-frame--mobile');
				}
				if (previewContent) {
					previewContent.classList.remove('frs-profile__preview-content--tablet', 'frs-profile__preview-content--mobile');
				}

				if (device === 'tablet') {
					if (previewFrame) previewFrame.classList.add('frs-profile__preview-frame--tablet');
					if (previewContent) previewContent.classList.add('frs-profile__preview-content--tablet');
				} else if (device === 'mobile') {
					if (previewFrame) previewFrame.classList.add('frs-profile__preview-frame--mobile');
					if (previewContent) previewContent.classList.add('frs-profile__preview-content--mobile');
				}
			});
		});

		// Toggle edit mode
		function setEditMode(editing) {
			isEditing = editing;

			// Toggle buttons
			if (editBtn) editBtn.hidden = editing;
			if (cancelBtn) cancelBtn.hidden = !editing;
			if (saveBtn) saveBtn.hidden = !editing;

			// Toggle view/edit elements using hidden attribute
			viewElements.forEach(function(el) { el.hidden = editing; });
			editElements.forEach(function(el) { el.hidden = !editing; });

			// Add class to container for CSS hooks
			container.classList.toggle('is-editing', editing);
		}

		// Edit button
		if (editBtn) {
			editBtn.addEventListener('click', function(e) {
				e.preventDefault();
				setEditMode(true);
			});
		}

		// Cancel button
		if (cancelBtn) {
			cancelBtn.addEventListener('click', function(e) {
				e.preventDefault();
				setEditMode(false);
			});
		}

		// Save button
		if (saveBtn) {
			saveBtn.addEventListener('click', async function(e) {
				e.preventDefault();
				if (isSaving) return;

				isSaving = true;
				saveBtn.disabled = true;
				if (saveText) {
					saveText.innerHTML = '<span class="frs-profile__spinner"></span> Saving...';
				}

				try {
					// Clear previous errors
					clearAllFieldErrors(container);

					// Gather form data from inputs
					const formData = {};
					container.querySelectorAll('[data-field]').forEach(function(input) {
						formData[input.dataset.field] = input.value;
					});

					// Validate fields
					var hasErrors = false;
					var emailInput = container.querySelector('[data-field="email"]');
					if (emailInput && !validateEmail(emailInput.value)) {
						setFieldError(emailInput, 'Please enter a valid email address');
						hasErrors = true;
					}

					var phoneInput = container.querySelector('[data-field="phone_number"]');
					if (phoneInput && !validatePhone(phoneInput.value)) {
						setFieldError(phoneInput, 'Please enter a valid phone number');
						hasErrors = true;
					}

					var websiteInput = container.querySelector('[data-field="website"]');
					if (websiteInput && !validateUrl(websiteInput.value)) {
						setFieldError(websiteInput, 'Please enter a valid URL (e.g., https://example.com)');
						hasErrors = true;
					}

					// Validate social URLs
					['facebook_url', 'instagram_url', 'linkedin_url', 'twitter_url', 'youtube_url', 'tiktok_url'].forEach(function(field) {
						var input = container.querySelector('[data-field="' + field + '"]');
						if (input && !validateUrl(input.value)) {
							setFieldError(input, 'Please enter a valid URL');
							hasErrors = true;
						}
					});

					if (hasErrors) {
						throw new Error('Please fix the errors below');
					}

					// Gather checkboxes for arrays
					formData.specialties_lo = Array.from(container.querySelectorAll('[data-specialty]:checked')).map(function(cb) { return cb.value; });
					formData.namb_certifications = Array.from(container.querySelectorAll('[data-certification]:checked')).map(function(cb) { return cb.value; });
					formData.service_areas = Array.from(container.querySelectorAll('[data-service-area]:checked')).map(function(cb) { return cb.value; });

					// Gather custom links
					var links = [];
					container.querySelectorAll('.frs-profile__link-edit-item').forEach(function(item) {
						var titleInput = item.querySelector('[data-link-title]');
						var urlInput = item.querySelector('[data-link-url]');
						var title = titleInput ? titleInput.value.trim() : '';
						var url = urlInput ? urlInput.value.trim() : '';
						if (title || url) {
							links.push({ title: title, url: url });
						}
					});
					formData.custom_links = links;

					const config = window.frsProfileEditor || {};
					const userId = config.userId;

					if (!userId) {
						throw new Error('User ID not found');
					}

					const response = await fetch('/wp-json/frs-users/v1/profiles/' + userId, {
						method: 'PUT',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': config.nonce || ''
						},
						body: JSON.stringify(formData)
					});

				if (!response.ok) {
					const errorData = await response.json().catch(function() { return {}; });
					throw new Error(errorData.message || 'Failed to save profile');
				}

				// Parse response and update UI
				const result = await response.json();
				if (result.success && result.data) {
					// Update view mode elements from response
					updateViewModeFromResponse(result.data);
				}

				// Exit edit mode
				isSaving = false;
				saveBtn.disabled = false;
				if (saveText) saveText.textContent = 'Save Changes';
				setEditMode(false);

				// Dispatch success event
				window.dispatchEvent(new CustomEvent('frs:profileSaved', {
					detail: { success: true, data: result.data }
				}));

				// Show success toast
				showGlobalToast('Profile saved successfully', 'success', 3000);
			} catch (err) {
				showGlobalToast('Failed to save: ' + err.message, 'error', 5000);
				isSaving = false;
				saveBtn.disabled = false;
				if (saveText) saveText.textContent = 'Save Changes';
			}
			});
		}

		/**
		 * Update view mode elements from API response data.
		 */
		function updateViewModeFromResponse(data) {
			if (!data) return;

			// Update text fields
			var fieldMap = {
				'job_title': '.frs-profile__title [data-view-mode]',
				'city_state': '.frs-profile__location [data-view-mode]',
				'biography': '.frs-profile__bio-content [data-view-mode]',
			};

			for (var field in fieldMap) {
				if (data[field] !== undefined) {
					var el = container.querySelector(fieldMap[field]);
					if (el) {
						if (field === 'biography') {
							el.innerHTML = data[field] ? '<p>' + data[field].replace(/\n/g, '</p><p>') + '</p>' : '<p class="frs-profile__empty">No biography provided.</p>';
						} else {
							el.textContent = data[field];
						}
					}
				}
			}

			// Update email link (text lives in a dedicated span so this never
			// clobbers the icon <svg> sitting next to it)
			if (data.email) {
				var emailLink = container.querySelector('.frs-profile__contact-item[href^="mailto:"]');
				if (emailLink) {
					emailLink.href = 'mailto:' + data.email;
					var emailText = emailLink.querySelector('.frs-profile__contact-text');
					if (emailText) emailText.textContent = data.email;
				}
			}

			// Update phone link (same span-targeted approach as email)
			if (data.phone_number) {
				var phoneLink = container.querySelector('.frs-profile__contact-item[href^="tel:"]');
				if (phoneLink) {
					phoneLink.href = 'tel:' + data.phone_number.replace(/[^\d+]/g, '');
					var phoneText = phoneLink.querySelector('.frs-profile__contact-text');
					if (phoneText) phoneText.textContent = formatPhoneDisplay(data.phone_number);
				}
			}
		}

		// ============================================
		// REAL-TIME FIELD VALIDATION
		// ============================================
		container.querySelectorAll('[data-field]').forEach(function(input) {
			input.addEventListener('blur', function() {
				var field = this.dataset.field;
				var value = this.value;

				// Clear error first
				clearFieldError(this);

				// Validate based on field type
				if (field === 'email' && value && !validateEmail(value)) {
					setFieldError(this, 'Please enter a valid email address');
				} else if (field === 'phone_number' && value && !validatePhone(value)) {
					setFieldError(this, 'Please enter a valid phone number');
				} else if (field === 'website' && value && !validateUrl(value)) {
					setFieldError(this, 'Please enter a valid URL');
				} else if (field.endsWith('_url') && value && !validateUrl(value)) {
					setFieldError(this, 'Please enter a valid URL');
				}
			});

			// Clear error on input
			input.addEventListener('input', function() {
				clearFieldError(this);
			});
		});

		// ============================================
		// TAB SWITCHING
		// ============================================
		let settingsLoaded = false;
		let activityLoaded = false;
		const config = window.frsProfileEditor || {};

		const tabs = container.querySelectorAll('.frs-profile__tab');
		const tabPanels = container.querySelectorAll('.frs-profile__tab-panel');

		tabs.forEach(function(tab) {
			tab.addEventListener('click', function(e) {
				e.preventDefault();
				const targetTab = this.dataset.tab;

				// Update tab active states
				tabs.forEach(function(t) { t.classList.remove('frs-profile__tab--active'); });
				this.classList.add('frs-profile__tab--active');

				// Show/hide panels
				tabPanels.forEach(function(panel) {
					if (panel.dataset.tabPanel === targetTab) {
						panel.hidden = false;
						panel.classList.add('frs-profile__tab-panel--active');
					} else {
						panel.hidden = true;
						panel.classList.remove('frs-profile__tab-panel--active');
					}
				});

				// Activity feed is server-rendered (blog posts only).
				if (targetTab === 'activity') {
					activityLoaded = true;
				}

				// Load settings on first visit
				if (targetTab === 'settings' && !settingsLoaded) {
					loadSettings();
				}
			});
		});

		// ============================================
		// SETTINGS (auto-save on toggle)
		// ============================================
		const settingsToast = document.getElementById('frs-settings-toast');

		async function loadSettings() {
			// Show loading state
			var settingsPanel = container.querySelector('[data-tab-panel="settings"]');
			var settingsCards = settingsPanel ? settingsPanel.querySelectorAll('.frs-profile__card') : [];
			settingsCards.forEach(function(card) {
				card.classList.add('frs-profile__card--loading');
			});

			try {
				const response = await fetch(config.restUrl + 'profiles/me/settings', {
					headers: { 'X-WP-Nonce': config.nonce || '' }
				});
				if (!response.ok) {
					throw new Error('Failed to load settings');
				}

				const result = await response.json();
				const data = result.data || {};

				// Set notification toggles
				if (data.notifications) {
					Object.entries(data.notifications).forEach(function([key, value]) {
						const input = container.querySelector('[data-setting="notifications"][data-key="' + key + '"]');
						if (input) input.checked = !!value;
					});
				}

				// Set privacy toggles
				if (data.privacy) {
					Object.entries(data.privacy).forEach(function([key, value]) {
						const input = container.querySelector('[data-setting="privacy"][data-key="' + key + '"]');
						if (input) input.checked = !!value;
					});
				}

				settingsLoaded = true;
			} catch (err) {
				console.error('Failed to load settings:', err);
				showGlobalToast('Failed to load settings', 'error', 4000);
			} finally {
				// Remove loading state
				settingsCards.forEach(function(card) {
					card.classList.remove('frs-profile__card--loading');
				});
			}
		}

		function showToast(message) {
			if (!settingsToast) return;
			settingsToast.textContent = message || 'Saved';
			settingsToast.hidden = false;
			clearTimeout(settingsToast._timeout);
			settingsToast._timeout = setTimeout(function() {
				settingsToast.hidden = true;
			}, 2000);
		}

		// Auto-save on toggle change
		container.querySelectorAll('[data-setting]').forEach(function(input) {
			input.addEventListener('change', async function() {
				const settingType = this.dataset.setting; // 'notifications' or 'privacy'
				const key = this.dataset.key;
				const value = this.checked;

				// Gather all values for this setting type
				const settings = {};
				container.querySelectorAll('[data-setting="' + settingType + '"]').forEach(function(inp) {
					settings[inp.dataset.key] = inp.checked;
				});

				try {
					const response = await fetch(config.restUrl + 'profiles/me/settings/' + settingType, {
						method: 'PUT',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': config.nonce || ''
						},
						body: JSON.stringify(settings)
					});

					if (response.ok) {
						showToast('Saved');
					} else {
						showToast('Failed to save');
						showGlobalToast('Setting change failed. Please try again.', 'error', 4000);
						// Revert toggle
						this.checked = !value;
					}
				} catch (err) {
					showToast('Failed to save');
					showGlobalToast('Connection error. Please check your internet.', 'error', 4000);
					this.checked = !value;
				}
			});
		});

		// ============================================
		// ACTIVITY FEED
		// ============================================
		let activityPage = 1;
		let activityPages = 1;
		const activityFeed = document.getElementById('frs-activity-feed');
		const activityMore = document.getElementById('frs-activity-more');
		const loadMoreBtn = document.getElementById('frs-load-more-activity');

		const ACTION_ICONS = {
			profile_updated: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
			meeting_requested: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
			post_published: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
			lesson_completed: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
			course_enrolled: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>',
		};

		const ACTION_LABELS = {
			profile_updated: 'Profile',
			meeting_requested: 'Meeting',
			post_published: 'Published',
			lesson_completed: 'Lesson',
			course_enrolled: 'Course',
		};

		function renderActivityItem(item) {
			const icon = ACTION_ICONS[item.action] || ACTION_ICONS['profile_updated'];
			const iconClass = 'frs-profile__feed-icon--' + item.action.replace(/_/g, '-');
			const badge = ACTION_LABELS[item.action] || 'Activity';

			return '<div class="frs-profile__feed-item">' +
				'<div class="frs-profile__feed-icon ' + iconClass + '">' + icon + '</div>' +
				'<div class="frs-profile__feed-body">' +
					'<div class="frs-profile__feed-meta">' +
						'<span class="frs-profile__feed-badge">' + escapeHtml(badge) + '</span>' +
						'<span class="frs-profile__feed-time">' + escapeHtml(item.time_ago) + '</span>' +
					'</div>' +
					'<p class="frs-profile__feed-summary">' + escapeHtml(item.summary) + '</p>' +
				'</div>' +
			'</div>';
		}

		function escapeHtml(str) {
			const div = document.createElement('div');
			div.textContent = str;
			return div.innerHTML;
		}

		async function loadActivityFeed(append) {
			if (!activityFeed) return;

			// Show loading state
			if (loadMoreBtn) {
				loadMoreBtn.disabled = true;
				loadMoreBtn.innerHTML = '<span class="frs-profile__spinner"></span> Loading...';
			}

			try {
				const userId = config.userId;
				const response = await fetch(config.restUrl + 'profiles/' + userId + '/activity?page=' + activityPage + '&per_page=20', {
					headers: { 'X-WP-Nonce': config.nonce || '' }
				});

				if (!response.ok) throw new Error('Failed to load activity');

				const result = await response.json();
				activityPages = result.pages || 1;

				// Append activity items after the server-rendered posts
				if (result.data && result.data.length > 0) {
					result.data.forEach(function(item) {
						activityFeed.insertAdjacentHTML('beforeend', renderActivityItem(item));
					});
				}

				// Show/hide load more
				if (activityMore) {
					activityMore.hidden = activityPage >= activityPages;
				}

				activityLoaded = true;
			} catch (err) {
				console.error('Activity feed error:', err);
				showGlobalToast('Failed to load activity. Please try again.', 'error', 4000);
				// Revert page number on failure
				if (append && activityPage > 1) activityPage--;
			} finally {
				// Reset load more button
				if (loadMoreBtn) {
					loadMoreBtn.disabled = false;
					loadMoreBtn.textContent = 'Load more';
				}
			}
		}

		if (loadMoreBtn) {
			loadMoreBtn.addEventListener('click', function(e) {
				e.preventDefault();
				activityPage++;
				loadActivityFeed(true);
			});
		}

		// ============================================
		// CUSTOM LINKS (Add / Remove)
		// ============================================
		const addLinkBtn = document.getElementById('add-link-btn');
		const linksEditList = container.querySelector('.frs-profile__links-edit-list');
		let linkIndex = container.querySelectorAll('.frs-profile__link-edit-item').length;

		if (addLinkBtn && linksEditList) {
			addLinkBtn.addEventListener('click', function(e) {
				e.preventDefault();
				var idx = linkIndex++;
				var item = document.createElement('div');
				item.className = 'frs-profile__link-edit-item';
				item.dataset.index = idx;
				item.innerHTML =
					'<input type="text" class="frs-profile__edit-input" value="" data-link-title="' + idx + '" placeholder="Link Title">' +
					'<input type="url" class="frs-profile__edit-input" value="" data-link-url="' + idx + '" placeholder="https://example.com">' +
					'<button type="button" class="frs-profile__link-remove-btn" data-link-remove="' + idx + '" title="Remove link">' +
						'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
							'<line x1="18" y1="6" x2="6" y2="18"></line>' +
							'<line x1="6" y1="6" x2="18" y2="18"></line>' +
						'</svg>' +
					'</button>';
				linksEditList.appendChild(item);
			});
		}

		// Delegate remove clicks
		if (linksEditList) {
			linksEditList.addEventListener('click', function(e) {
				var removeBtn = e.target.closest('.frs-profile__link-remove-btn');
				if (removeBtn) {
					e.preventDefault();
					var item = removeBtn.closest('.frs-profile__link-edit-item');
					if (item) item.remove();
				}
			});
		}

		// ============================================
		// POST COMPOSER (Tumblr-style)
		// ============================================
		initComposer();
	}

	/**
	 * Initialize the Tumblr-like post composer in the Activity tab.
	 */
	function initComposer() {
		var composer = document.getElementById('frs-post-composer');
		if (!composer) return;

		var config = window.frsProfileEditor || {};
		var collapsed = document.getElementById('frs-composer-collapsed');
		var trigger = document.getElementById('frs-composer-trigger');
		var overlay = document.getElementById('frs-composer-overlay');
		var expanded = document.getElementById('frs-composer-expanded');
		var closeBtn = document.getElementById('frs-composer-close');
		var iframe = document.getElementById('frs-composer-iframe');
		var loading = document.getElementById('frs-composer-loading');
		var tagInput = document.getElementById('frs-composer-tags');
		var tagList = document.getElementById('frs-composer-tag-list');
		var publishBtn = document.getElementById('frs-composer-publish');
		var formatBtns = composer.querySelectorAll('.frs-composer__fmt');

		var currentPostId = null;
		var selectedFormat = 'standard';
		var tags = [];
		var editorReady = false;

		// Show iframe when it finishes loading with a smooth fade-in.
		if (iframe) {
			iframe.style.opacity = '0';
			iframe.style.transition = 'opacity 0.2s ease';

			iframe.addEventListener('load', function() {
				if (!iframe.src || iframe.src === '' || iframe.src === 'about:blank') return;
				editorReady = true;
				if (loading) loading.style.display = 'none';
				iframe.style.display = 'block';
				// Fade in after a brief delay to let the editor paint.
				requestAnimationFrame(function() {
					iframe.style.opacity = '1';
				});
			});
		}

		// Format button clicks — switch format on current draft or open new one.
		formatBtns.forEach(function(btn) {
			btn.addEventListener('click', function(e) {
				e.preventDefault();
				var newFormat = btn.dataset.format;
				if (newFormat === selectedFormat) return;

				selectedFormat = newFormat;
				formatBtns.forEach(function(b) {
					b.classList.remove('frs-composer__fmt--active');
				});
				btn.classList.add('frs-composer__fmt--active');

				// If modal is open, update the format and swap the blocks via postMessage.
				if (overlay && overlay.classList.contains('frs-composer__overlay--open') && currentPostId && iframe && iframe.contentWindow) {
					var patterns = config.formatPatterns || {};
					var content = patterns[newFormat] || patterns['standard'] || '';

					// Tell the iframe editor to switch format + replace blocks.
					iframe.contentWindow.postMessage({
						type: 'frs-composer-set-format',
						format: newFormat === 'standard' ? '' : newFormat,
						content: content,
					}, window.location.origin);

					// Also update via REST so the DB stays in sync.
					fetch('/wp-json/wp/v2/posts/' + currentPostId, {
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': config.nonce,
						},
						body: JSON.stringify({ format: newFormat === 'standard' ? '' : newFormat }),
					}).catch(function() {});
				} else {
					openComposer(selectedFormat);
				}
			});
		});

		// Prompt click.
		if (trigger) {
			trigger.addEventListener('click', function() {
				openComposer(selectedFormat);
			});
		}

		// Close button.
		if (closeBtn) {
			closeBtn.addEventListener('click', closeComposer);
		}

		// Click overlay backdrop to close.
		if (overlay) {
			overlay.addEventListener('click', function(e) {
				if (e.target === overlay) closeComposer();
			});
		}

		/**
		 * Create an auto-draft via REST and load the iframe editor.
		 * Used by both openComposer() and format-switch handler.
		 */
		function createDraft(format) {
			loading.style.display = '';
			iframe.style.display = 'none';
			editorReady = false;

			fetch(config.restUrl + 'posts/create-draft', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.nonce,
				},
				body: JSON.stringify({ format: format }),
			})
			.then(function(res) {
				if (!res.ok) {
					return res.json().then(function(err) {
						throw new Error(err.message || err.code || ('HTTP ' + res.status));
					});
				}
				return res.json();
			})
			.then(function(data) {
				if (data.post_id && data.editor_url) {
					currentPostId = data.post_id;
					iframe.src = data.editor_url;
				} else {
					console.error('Composer: unexpected response', data);
					alert('Failed to create draft: unexpected response.');
					closeComposer();
				}
			})
			.catch(function(err) {
				console.error('Composer: create-draft failed', err);
				alert('Failed to create draft: ' + (err.message || 'unknown error'));
				closeComposer();
			});
		}

		/**
		 * Open the expanded composer, create an auto-draft, and load the iframe editor.
		 */
		function openComposer(format) {
			if (overlay && overlay.classList.contains('frs-composer__overlay--open')) return; // Already open.

			// Show modal overlay.
			if (overlay) overlay.classList.add('frs-composer__overlay--open');
			document.body.style.overflow = 'hidden';

			createDraft(format);
		}

		/**
		 * Close the composer and reset state.
		 */
		function closeComposer() {
			if (overlay) overlay.classList.remove('frs-composer__overlay--open');
			document.body.style.overflow = '';
			iframe.src = '';
			iframe.style.opacity = '0';
			iframe.style.display = 'none';
			loading.style.display = '';
			currentPostId = null;
			editorReady = false;
			tags = [];
			renderTags();
			if (publishBtn) {
				publishBtn.disabled = false;
				publishBtn.innerHTML = 'Post now <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>';
			}
		}

		// Height messages from the iframe bridge.
		window.addEventListener('message', function(event) {
			if (event.data && event.data.type === 'frs-composer-height' && iframe && event.data.height) {
				iframe.style.height = Math.max(200, event.data.height) + 'px';
			}
		});
		// The outer title field syncs on publish via REST API.

		// Tag input: Enter key adds tag.
		if (tagInput) {
			tagInput.addEventListener('keydown', function(e) {
				if (e.key === 'Enter') {
					e.preventDefault();
					var tag = tagInput.value.trim().replace(/^#/, '');
					if (tag && tags.indexOf(tag) === -1) {
						tags.push(tag);
						renderTags();
					}
					tagInput.value = '';
				}
			});
		}

		function renderTags() {
			if (!tagList) return;
			tagList.innerHTML = tags.map(function(tag) {
				return '<span class="frs-composer__tag">#' + escapeHtml(tag) +
					' <button type="button" data-remove-tag="' + escapeHtml(tag) + '">&times;</button></span>';
			}).join('');
		}

		// Tag removal via delegation.
		if (tagList) {
			tagList.addEventListener('click', function(e) {
				var removeBtn = e.target.closest('[data-remove-tag]');
				if (removeBtn) {
					tags = tags.filter(function(t) { return t !== removeBtn.dataset.removeTag; });
					renderTags();
				}
			});
		}

		// Publish button — set tags, then tell iframe editor to save+publish.
		if (publishBtn) {
			publishBtn.addEventListener('click', function() {
				if (!currentPostId || !editorReady || !iframe || !iframe.contentWindow) return;

				publishBtn.disabled = true;
				publishBtn.innerHTML = 'Publishing...';

				// Set tags first, then tell the iframe to publish.
				var tagPromise = tags.length > 0
					? resolveAndSetTags(currentPostId, tags, config.nonce)
					: Promise.resolve();

				tagPromise.then(function() {
					// Tell the iframe editor to save title + content + set status to publish.
					iframe.contentWindow.postMessage({
						type: 'frs-composer-publish',
					}, window.location.origin);
				}).catch(function(err) {
					console.error('Tag resolution failed:', err);
					publishBtn.disabled = false;
					publishBtn.innerHTML = 'Post now <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>';
				});
			});
		}

		// Listen for publish confirmation from the iframe bridge.
		window.addEventListener('message', function(event) {
			if (!event.data || !event.data.type) return;
			if (event.data.type === 'frs-composer-published') {
				closeComposer();
				prependPostToFeed({
					postId: event.data.postId,
					title: event.data.title || 'Untitled',
					url: event.data.url || '#',
					format: event.data.format || 'standard',
				});
			}
			if (event.data.type === 'frs-composer-error') {
				if (publishBtn) {
					publishBtn.disabled = false;
					publishBtn.innerHTML = 'Post now <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>';
				}
				alert('Failed to publish: ' + (event.data.message || 'unknown error'));
			}
		});

	}

	/**
	 * Resolve tag names to IDs and assign to a post.
	 */
	function resolveAndSetTags(postId, tagNames, nonce) {
		// First, create/find all tags.
		var promises = tagNames.map(function(name) {
			return fetch('/wp-json/wp/v2/tags', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
				},
				body: JSON.stringify({ name: name }),
			})
			.then(function(res) { return res.json(); })
			.then(function(tag) { return tag.id; })
			.catch(function() {
				// Tag might already exist — try to find it.
				return fetch('/wp-json/wp/v2/tags?search=' + encodeURIComponent(name) + '&per_page=1', {
					headers: { 'X-WP-Nonce': nonce },
				})
				.then(function(res) { return res.json(); })
				.then(function(results) {
					return results.length > 0 ? results[0].id : null;
				});
			});
		});

		return Promise.all(promises).then(function(tagIds) {
			var validIds = tagIds.filter(function(id) { return id; });
			if (validIds.length === 0) return;

			return fetch('/wp-json/wp/v2/posts/' + postId, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
				},
				body: JSON.stringify({ tags: validIds }),
			});
		});
	}

	/**
	 * Prepend a newly published post to the activity feed.
	 */
	function prependPostToFeed(data) {
		var feed = document.getElementById('frs-activity-feed');
		if (!feed) return;

		// Remove "No posts yet" message if present.
		var empty = feed.querySelector('.frs-profile__empty');
		if (empty) empty.remove();

		var title = data.title || 'Untitled';
		var url = data.url || '#';
		var format = data.format || 'standard';

		var html = '<div class="frs-profile__feed-item frs-profile__feed-item--post" data-post-id="' + data.postId + '">' +
			'<div class="frs-profile__feed-icon frs-profile__feed-icon--' + escapeHtml(format) + '">' +
				'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">' +
					'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>' +
					'<polyline points="14 2 14 8 20 8"/>' +
				'</svg>' +
			'</div>' +
			'<div class="frs-profile__feed-body">' +
				'<div class="frs-profile__feed-meta">' +
					'<span class="frs-profile__feed-badge">Post</span>' +
					'<span class="frs-profile__feed-time">just now</span>' +
				'</div>' +
				'<a href="' + escapeHtml(url) + '" class="frs-profile__feed-title">' + escapeHtml(title) + '</a>' +
			'</div>' +
		'</div>';

		feed.insertAdjacentHTML('afterbegin', html);
	}

	/**
	 * Escape HTML special characters.
	 */
	function escapeHtml(str) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

	// Run on DOM ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initProfileEditor);
	} else {
		initProfileEditor();
	}
})();
