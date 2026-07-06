<?php
/**
 * Profiles List Table
 *
 * Displays a list of profiles with actions to create user accounts.
 *
 * @package FRSUsers
 * @subpackage Admin
 * @since 1.0.0
 */

namespace FRSUsers\Admin;

use FRSUsers\Models\Profile;
use FRSUsers\Core\PhoneFormatter;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class ProfilesList
 *
 * Extends WP_List_Table to display profiles with user account creation.
 *
 * @package FRSUsers\Admin
 */
class ProfilesList extends \WP_List_Table {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'profile',
				'plural'   => 'profiles',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Get columns for the list table
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'            => '<input type="checkbox" />',
			'headshot'      => __( 'Photo', 'frs-users' ),
			'name'          => __( 'Name', 'frs-users' ),
			'email'         => __( 'Email', 'frs-users' ),
			'phone_number'  => __( 'Phone', 'frs-users' ),
			'nmls'          => __( 'NMLS', 'frs-users' ),
			'service_areas' => __( 'Service Areas', 'frs-users' ),
			'profile_types' => __( 'Type', 'frs-users' ),
			'status'        => __( 'Status', 'frs-users' ),
		);
	}

	/**
	 * Get sortable columns
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'name'       => array( 'first_name', true ),
			'email'      => array( 'email', false ),
			'created_at' => array( 'created_at', false ),
		);
	}

	/**
	 * Get bulk actions
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array(
			'bulk_create_users' => __( 'Create User Accounts', 'frs-users' ),
			'bulk_archive'      => __( 'Archive', 'frs-users' ),
			'bulk_unarchive'    => __( 'Unarchive', 'frs-users' ),
			'bulk_merge'        => __( 'Merge Profiles', 'frs-users' ),
		);
	}

	/**
	 * Render checkbox column
	 *
	 * @param object $item Profile item.
	 * @return string
	 */
	protected function column_cb( $item ) {
		// Show checkbox for all profiles (needed for merge and archive)
		// WordPress WP_List_Table expects singular form: 'profile[]'
		return sprintf( '<input type="checkbox" name="profile[]" value="%d" />', $item->id );
	}

	/**
	 * Render headshot column
	 *
	 * @param object $item Profile item.
	 * @return string
	 */
	protected function column_headshot( $item ) {
		if ( $item->headshot_id ) {
			$image_url = wp_get_attachment_image_url( $item->headshot_id, 'thumbnail' );
			if ( $image_url ) {
				return '<div style="width: 50px; height: 50px; border-radius: 50%; overflow: hidden;"><img src="' . esc_url( $image_url ) . '" style="width: 100%; height: 100%; object-fit: cover; display: block;" alt="' . esc_attr( $item->first_name . ' ' . $item->last_name ) . '"></div>';
			}
		}
		return '<div style="width: 50px; height: 50px; background: #ddd; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 18px; color: #666;">' . strtoupper( substr( $item->first_name, 0, 1 ) ) . '</div>';
	}

	/**
	 * Render name column
	 *
	 * @param object $item Profile item.
	 * @return string
	 */
	protected function column_name( $item ) {
		$name = $item->first_name . ' ' . $item->last_name;

		$view_url = add_query_arg(
			array(
				'page' => 'frs-profile-view',
				'id'   => $item->id,
			),
			admin_url( 'admin.php' )
		);

		$edit_url = add_query_arg(
			array(
				'page' => 'frs-profile-edit',
				'id'   => $item->id,
			),
			admin_url( 'admin.php' )
		);

		$delete_url = add_query_arg(
			array(
				'action'     => 'frs_delete_profile',
				'profile_id' => $item->id,
				'_wpnonce'   => wp_create_nonce( 'delete_profile_' . $item->id ),
			),
			admin_url( 'admin-post.php' )
		);

		$actions = array(
			'view'   => sprintf( '<a href="%s">%s</a>', $view_url, __( 'View', 'frs-users' ) ),
			'edit'   => sprintf( '<a href="%s">%s</a>', $edit_url, __( 'Edit', 'frs-users' ) ),
		);

		// Add FluentCRM link if available
		if ( function_exists( 'FluentCrm' ) && ! empty( $item->email ) ) {
			global $wpdb;
			$contact = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}fc_subscribers WHERE email = %s LIMIT 1",
					$item->email
				)
			);

			if ( $contact ) {
				$fluentcrm_url = admin_url( 'admin.php?page=fluentcrm-admin#/subscribers/' . $contact->id );
				$actions['fluentcrm'] = sprintf(
					'<a href="%s" target="_blank" style="color: #2271b1;">%s</a>',
					$fluentcrm_url,
					__( 'FluentCRM', 'frs-users' )
				);
			}
		}

		// Add archive/unarchive action
		if ( $item->is_active ) {
			$archive_url = add_query_arg(
				array(
					'action'     => 'frs_archive_profile',
					'profile_id' => $item->id,
					'_wpnonce'   => wp_create_nonce( 'archive_profile_' . $item->id ),
				),
				admin_url( 'admin-post.php' )
			);
			$actions['archive'] = sprintf( '<a href="%s">%s</a>', $archive_url, __( 'Archive', 'frs-users' ) );
		} else {
			$unarchive_url = add_query_arg(
				array(
					'action'     => 'frs_unarchive_profile',
					'profile_id' => $item->id,
					'_wpnonce'   => wp_create_nonce( 'unarchive_profile_' . $item->id ),
				),
				admin_url( 'admin-post.php' )
			);
			$actions['unarchive'] = sprintf( '<a href="%s">%s</a>', $unarchive_url, __( 'Unarchive', 'frs-users' ) );
		}

		// Add "Merge with..." action
		$merge_with_url = add_query_arg(
			array(
				'page'       => 'frs-profile-merge-select',
				'profile_id' => $item->id,
			),
			admin_url( 'admin.php' )
		);
		$actions['merge'] = sprintf( '<a href="%s">%s</a>', $merge_with_url, __( 'Merge with...', 'frs-users' ) );

		$actions['delete'] = sprintf( '<a href="%s" class="delete-profile">%s</a>', $delete_url, __( 'Delete', 'frs-users' ) );

		return sprintf( '<strong><a href="%s">%s</a></strong>%s', $view_url, esc_html( $name ), $this->row_actions( $actions ) );
	}

	/**
	 * Render phone number column
	 *
	 * @param object $item Profile item.
	 * @return string
	 */
	protected function column_phone_number( $item ) {
		if ( ! empty( $item->phone_number ) ) {
			$tel = preg_replace( '/[^\d+]/', '', $item->phone_number );
			return sprintf( '<a href="tel:%s">%s</a>', esc_attr( $tel ), esc_html( PhoneFormatter::us( $item->phone_number ) ) );
		}
		return '—';
	}

	/**
	 * Render NMLS column
	 *
	 * @param object $item Profile item.
	 * @return string
	 */
	protected function column_nmls( $item ) {
		if ( ! empty( $item->nmls ) ) {
			return '<code>' . esc_html( $item->nmls ) . '</code>';
		}
		if ( ! empty( $item->nmls_number ) ) {
			return '<code>' . esc_html( $item->nmls_number ) . '</code>';
		}
		return '—';
	}

	/**
	 * Render service areas column
	 *
	 * @param object $item Profile item.
	 * @return string
	 */
	protected function column_service_areas( $item ) {
		if ( empty( $item->service_areas ) ) {
			return '<span style="color: #999; font-style: italic;">—</span>';
		}

		$areas = is_array( $item->service_areas ) ? $item->service_areas : json_decode( $item->service_areas, true );

		if ( empty( $areas ) || ! is_array( $areas ) ) {
			return '<span style="color: #999; font-style: italic;">—</span>';
		}

		// Show first 2 areas, add "+" badge if more
		$display_areas = array_slice( $areas, 0, 2 );
		$remaining = count( $areas ) - 2;

		$output = '';
		foreach ( $display_areas as $area ) {
			$output .= '<span style="display: inline-block; background: #f0f0f1; border: 1px solid #c3c4c7; padding: 2px 8px; border-radius: 3px; font-size: 11px; margin-right: 4px; margin-bottom: 4px;">' . esc_html( $area ) . '</span>';
		}

		if ( $remaining > 0 ) {
			$output .= '<span style="display: inline-block; background: #2271b1; color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px; margin-right: 4px; margin-bottom: 4px;">+' . $remaining . '</span>';
		}

		return $output;
	}

	/**
	 * Render email column
	 *
	 * @param object $item Profile item.
	 * @return string
	 */
	protected function column_email( $item ) {
		return sprintf( '<a href="mailto:%s">%s</a>', esc_attr( $item->email ), esc_html( $item->email ) );
	}

	/**
	 * Render profile types column
	 *
	 * @param object $item Profile item.
	 * @return string
	 */
	protected function column_profile_types( $item ) {
		if ( empty( $item->select_person_type ) ) {
			return '<span style="color: #999; font-style: italic;">' . __( 'Not Set', 'frs-users' ) . '</span>';
		}

		return '<span class="profile-type-badge">' . esc_html( ucwords( str_replace( '_', ' ', $item->select_person_type ) ) ) . '</span>';
	}

	/**
	 * Render status column
	 *
	 * @param object $item Profile item.
	 * @return string
	 */
	protected function column_status( $item ) {
		// Check if archived
		if ( ! $item->is_active ) {
			return '<span class="archived-badge" style="background: #999; color: white; padding: 3px 8px; border-radius: 3px; font-size: 11px;">' . __( 'Archived', 'frs-users' ) . '</span>';
		}

		if ( $item->is_guest() ) {
			return '<span class="profile-only-badge" style="background: #f0ad4e; color: white; padding: 3px 8px; border-radius: 3px; font-size: 11px;">' . __( 'Profile Only', 'frs-users' ) . '</span>';
		}

		$user = get_user_by( 'id', $item->user_id );
		if ( $user ) {
			return '<span class="profile-plus-badge" style="background: #5cb85c; color: white; padding: 3px 8px; border-radius: 3px; font-size: 11px;">' . sprintf( __( 'Profile+ (@%s)', 'frs-users' ), $user->user_login ) . '</span>';
		}

		return '—';
	}

	/**
	 * Render created_at column
	 *
	 * @param object $item Profile item.
	 * @return string
	 */
	protected function column_created_at( $item ) {
		return date_i18n( get_option( 'date_format' ), strtotime( $item->created_at ) );
	}

	/**
	 * Render actions column
	 *
	 * @param object $item Profile item.
	 * @return string
	 */
	protected function column_actions( $item ) {
		if ( ! $item->is_guest() ) {
			return '—';
		}

		$nonce = wp_create_nonce( 'create_user_' . $item->id );

		return sprintf(
			'<button type="button" class="button button-primary create-user-btn" data-profile-id="%d" data-nonce="%s">%s</button>',
			$item->id,
			$nonce,
			__( 'Create User Account', 'frs-users' )
		);
	}

	/**
	 * Prepare items for display
	 *
	 * @return void
	 */
	public function prepare_items() {
		$columns  = $this->get_columns();
		$hidden   = array();
		$sortable = $this->get_sortable_columns();

		$this->_column_headers = array( $columns, $hidden, $sortable );

		// Handle bulk actions
		$this->process_bulk_action();

		// Get filter parameters
		$filter_type     = isset( $_GET['filter_type'] ) ? sanitize_text_field( $_GET['filter_type'] ) : '';
		$guests_only     = isset( $_GET['guests_only'] ) ? (bool) $_GET['guests_only'] : false;
		$show_archived   = isset( $_GET['show_archived'] ) ? (bool) $_GET['show_archived'] : false;
		$search          = isset( $_REQUEST['s'] ) ? sanitize_text_field( $_REQUEST['s'] ) : '';

		// Pagination
		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		// Fetch all profiles using WordPress-native query
		$all_profiles = Profile::get_all();

		// Convert to arrays for filtering
		$results = array_map( function( $p ) {
			return $p->toArray();
		}, $all_profiles );

		// Filter by active/archived status
		if ( $show_archived ) {
			$results = array_filter( $results, function( $p ) {
				return empty( $p['is_active'] );
			} );
		} else {
			$results = array_filter( $results, function( $p ) {
				return ! empty( $p['is_active'] );
			} );
		}

		// Filter by guest profiles (no user_id)
		if ( $guests_only ) {
			$results = array_filter( $results, function( $p ) {
				return empty( $p['user_id'] );
			} );
		}

		// Filter by type
		if ( $filter_type ) {
			$results = array_filter( $results, function( $p ) use ( $filter_type ) {
				return ( $p['select_person_type'] ?? '' ) === $filter_type;
			} );
		}

		// Search functionality
		if ( ! empty( $search ) ) {
			$search_lower = strtolower( $search );
			$results = array_filter( $results, function( $p ) use ( $search_lower ) {
				$haystack = strtolower(
					( $p['first_name'] ?? '' ) . ' ' .
					( $p['last_name'] ?? '' ) . ' ' .
					( $p['email'] ?? '' ) . ' ' .
					( $p['phone_number'] ?? '' ) . ' ' .
					( $p['nmls'] ?? '' ) . ' ' .
					( $p['nmls_number'] ?? '' )
				);
				return strpos( $haystack, $search_lower ) !== false;
			} );
		}

		$results     = array_values( $results );
		$total_items = count( $results );

		// Paginate
		$items = array_slice( $results, $offset, $per_page );

		$this->items = $items;

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * Process bulk actions
	 *
	 * @return void
	 */
	public function process_bulk_action() {
		$action = $this->current_action();

		// Debug: Log all POST and GET data
		error_log( 'ProfilesList::process_bulk_action() - Action: ' . $action );
		error_log( 'POST data: ' . print_r( $_POST, true ) );
		error_log( 'GET data: ' . print_r( $_GET, true ) );

		if ( 'bulk_archive' === $action ) {
			// Check nonce
			check_admin_referer( 'bulk-' . $this->_args['plural'] );

			if ( ! isset( $_POST['profile'] ) || ! is_array( $_POST['profile'] ) ) {
				return;
			}

			$profile_ids = array_map( 'absint', $_POST['profile'] );
			$archived_count = 0;

			foreach ( $profile_ids as $profile_id ) {
				$profile = Profile::find( $profile_id );
				if ( $profile ) {
					$profile->is_active = 0;
					$profile->save();
					$archived_count++;
				}
			}

			add_action( 'admin_notices', function() use ( $archived_count ) {
				?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						printf(
							__( 'Successfully archived %d profiles.', 'frs-users' ),
							$archived_count
						);
						?>
					</p>
				</div>
				<?php
			} );
		} elseif ( 'bulk_unarchive' === $action ) {
			// Check nonce
			check_admin_referer( 'bulk-' . $this->_args['plural'] );

			if ( ! isset( $_POST['profile'] ) || ! is_array( $_POST['profile'] ) ) {
				return;
			}

			$profile_ids = array_map( 'absint', $_POST['profile'] );
			$unarchived_count = 0;

			foreach ( $profile_ids as $profile_id ) {
				$profile = Profile::find( $profile_id );
				if ( $profile ) {
					$profile->is_active = 1;
					$profile->save();
					$unarchived_count++;
				}
			}

			add_action( 'admin_notices', function() use ( $unarchived_count ) {
				?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						printf(
							__( 'Successfully unarchived %d profiles.', 'frs-users' ),
							$unarchived_count
						);
						?>
					</p>
				</div>
				<?php
			} );
		} elseif ( 'bulk_merge' === $action ) {
			// Debug log
			error_log( 'ProfilesList: bulk_merge action triggered' );

			// Check nonce
			check_admin_referer( 'bulk-' . $this->_args['plural'] );

			if ( ! isset( $_POST['profile'] ) || ! is_array( $_POST['profile'] ) ) {
				error_log( 'ProfilesList: No profile[] in POST. POST keys: ' . implode( ', ', array_keys( $_POST ) ) );
				return;
			}

			$profile_ids = array_map( 'absint', $_POST['profile'] );
			error_log( 'ProfilesList: Profile IDs to merge: ' . implode( ',', $profile_ids ) );

			if ( count( $profile_ids ) < 2 ) {
				error_log( 'ProfilesList: Less than 2 profiles selected' );
				add_action( 'admin_notices', function() {
					?>
					<div class="notice notice-error is-dismissible">
						<p><?php _e( 'Please select at least 2 profiles to merge.', 'frs-users' ); ?></p>
					</div>
					<?php
				} );
				return;
			}

			// Redirect to merge screen
			$redirect_url = add_query_arg(
				array(
					'page'        => 'frs-profile-merge',
					'profile_ids' => implode( ',', $profile_ids ),
				),
				admin_url( 'admin.php' )
			);

			error_log( 'ProfilesList: Redirecting to merge page: ' . $redirect_url );

			wp_safe_redirect( $redirect_url );
			exit;
		} elseif ( 'bulk_create_users' === $action ) {
			// Check nonce
			check_admin_referer( 'bulk-' . $this->_args['plural'] );

			if ( ! isset( $_POST['profile'] ) || ! is_array( $_POST['profile'] ) ) {
				return;
			}

			$profile_ids = array_map( 'absint', $_POST['profile'] );
			$success_count = 0;
			$failed_count = 0;

			foreach ( $profile_ids as $profile_id ) {
				$profile = Profile::find( $profile_id );

				if ( ! $profile || ! $profile->is_guest() ) {
					$failed_count++;
					continue;
				}

				// Validate required fields
				if ( empty( $profile->first_name ) || empty( $profile->last_name ) || empty( $profile->email ) ) {
					$failed_count++;
					continue;
				}

				// Generate username
				$username = sanitize_user( strtolower( $profile->first_name . '.' . $profile->last_name ) );
				$username = str_replace( ' ', '', $username );

				if ( username_exists( $username ) ) {
					$username = $username . wp_rand( 1, 999 );
				}

				// Create user
				$user_data = array(
					'user_login' => $username,
					'user_email' => $profile->email,
					'first_name' => $profile->first_name,
					'last_name'  => $profile->last_name,
					'role'       => 'subscriber',
				);

				$user_id = wp_insert_user( $user_data );

				if ( is_wp_error( $user_id ) ) {
					$failed_count++;
					continue;
				}

				// Add profile type as role
				if ( ! empty( $profile->select_person_type ) ) {
					$user = new \WP_User( $user_id );
					$user->add_role( $profile->select_person_type );
				}

				// Link profile
				$profile->link_user( $user_id );

				// Send email
				wp_send_new_user_notifications( $user_id, 'user' );

				$success_count++;
			}

			// Show admin notice
			add_action( 'admin_notices', function() use ( $success_count, $failed_count ) {
				?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						printf(
							__( 'Successfully created %d user accounts. %d failed.', 'frs-users' ),
							$success_count,
							$failed_count
						);
						?>
					</p>
				</div>
				<?php
			} );
		}
	}

	/**
	 * Display filters
	 *
	 * @param string $which Top or bottom.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		?>
		<div class="alignleft actions">
			<select name="filter_type">
				<option value=""><?php _e( 'All Profile Types', 'frs-users' ); ?></option>
				<option value="loan_originator" <?php selected( isset( $_GET['filter_type'] ) && $_GET['filter_type'] === 'loan_originator' ); ?>>
					<?php _e( 'Loan Originators', 'frs-users' ); ?>
				</option>
				<option value="broker_associate" <?php selected( isset( $_GET['filter_type'] ) && $_GET['filter_type'] === 'broker_associate' ); ?>>
					<?php _e( 'Broker Associates', 'frs-users' ); ?>
				</option>
				<option value="sales_associate" <?php selected( isset( $_GET['filter_type'] ) && $_GET['filter_type'] === 'sales_associate' ); ?>>
					<?php _e( 'Sales Associates', 'frs-users' ); ?>
				</option>
				<option value="escrow_officer" <?php selected( isset( $_GET['filter_type'] ) && $_GET['filter_type'] === 'escrow_officer' ); ?>>
					<?php _e( 'Escrow Officers', 'frs-users' ); ?>
				</option>
				<option value="property_manager" <?php selected( isset( $_GET['filter_type'] ) && $_GET['filter_type'] === 'property_manager' ); ?>>
					<?php _e( 'Property Managers', 'frs-users' ); ?>
				</option>
				<option value="partner" <?php selected( isset( $_GET['filter_type'] ) && $_GET['filter_type'] === 'partner' ); ?>>
					<?php _e( 'Partners', 'frs-users' ); ?>
				</option>
				<option value="leadership" <?php selected( isset( $_GET['filter_type'] ) && $_GET['filter_type'] === 'leadership' ); ?>>
					<?php _e( 'Leadership', 'frs-users' ); ?>
				</option>
				<option value="staff" <?php selected( isset( $_GET['filter_type'] ) && $_GET['filter_type'] === 'staff' ); ?>>
					<?php _e( 'Staff', 'frs-users' ); ?>
				</option>
			</select>

			<label style="margin-left: 10px;">
				<input type="checkbox" name="guests_only" value="1" <?php checked( isset( $_GET['guests_only'] ) && $_GET['guests_only'] ); ?> />
				<?php _e( 'Guest Profiles Only', 'frs-users' ); ?>
			</label>

			<label style="margin-left: 10px;">
				<input type="checkbox" name="show_archived" value="1" <?php checked( isset( $_GET['show_archived'] ) && $_GET['show_archived'] ); ?> />
				<?php _e( 'Show Archived', 'frs-users' ); ?>
			</label>

			<?php submit_button( __( 'Filter', 'frs-users' ), 'button', 'filter_action', false ); ?>
		</div>
		<?php
	}
}
