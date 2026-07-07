<?php
/**
 * UserProfile Helper Class
 *
 * Provides clean abstraction for accessing FRS profile data stored in wp_users + wp_usermeta.
 * This replaces direct access to the old wp_frs_profiles custom table.
 *
 * @package FRSUsers
 * @since 3.0.0
 */

namespace FRSUsers\Models;

class UserProfile {
    /**
     * WordPress user object
     *
     * @var \WP_User
     */
    private $user;

    /**
     * User ID
     *
     * @var int
     */
    private $user_id;

    /**
     * Constructor
     *
     * @param int|string|\WP_User $user User ID, email, or WP_User object
     * @throws \Exception If user not found
     */
    public function __construct($user) {
        if ($user instanceof \WP_User) {
            $this->user = $user;
        } elseif (is_numeric($user)) {
            $this->user = get_userdata($user);
        } elseif (is_string($user) && is_email($user)) {
            $this->user = get_user_by('email', $user);
        } elseif (is_string($user)) {
            // Try by slug
            $this->user = get_user_by('slug', $user);
        }

        if (!$this->user || !$this->user->exists()) {
            throw new \Exception('User not found');
        }

        $this->user_id = $this->user->ID;
    }

    /**
     * Get the underlying WP_User object
     *
     * @return \WP_User
     */
    public function get_user() {
        return $this->user;
    }

    /**
     * Get user ID
     *
     * @return int
     */
    public function get_id() {
        return $this->user_id;
    }

    // ===========================================
    // Core WordPress Fields
    // ===========================================

    public function get_email(): string {
        return $this->user->user_email ?: '';
    }

    public function get_secondary_email(): string {
        return get_user_meta($this->user_id, 'frs_secondary_email', true) ?: '';
    }

    public function get_display_name(): string {
        return $this->user->display_name ?: '';
    }

    public function get_user_nicename(): string {
        return $this->user->user_nicename ?: '';
    }

    public function get_user_login(): string {
        return $this->user->user_login ?: '';
    }

    public function get_first_name(): string {
        return get_user_meta($this->user_id, 'first_name', true) ?: '';
    }

    public function get_last_name(): string {
        return get_user_meta($this->user_id, 'last_name', true) ?: '';
    }

    public function get_full_name(): string {
        $first = $this->get_first_name();
        $last = $this->get_last_name();
        return trim("$first $last") ?: $this->get_display_name();
    }

    public function get_initials(): string {
        $first = $this->get_first_name();
        $last = $this->get_last_name();
        return strtoupper(substr($first, 0, 1) . substr($last, 0, 1));
    }

    // ===========================================
    // Contact Information
    // ===========================================

    public function get_phone_number(): string {
        return get_user_meta($this->user_id, 'frs_phone_number', true) ?: '';
    }

    public function get_mobile_number(): string {
        return get_user_meta($this->user_id, 'frs_mobile_number', true) ?: '';
    }

    public function get_office(): string {
        return get_user_meta($this->user_id, 'frs_office', true) ?: '';
    }

    // ===========================================
    // Profile Fields
    // ===========================================

    public function get_job_title(): string {
        return get_user_meta($this->user_id, 'frs_job_title', true) ?: 'Loan Officer';
    }

    public function get_biography(): string {
        return get_user_meta($this->user_id, 'frs_biography', true) ?: '';
    }

    public function get_date_of_birth(): string {
        return get_user_meta($this->user_id, 'frs_date_of_birth', true) ?: '';
    }

    // ===========================================
    // Professional Details
    // ===========================================

    public function get_nmls(): string {
        return get_user_meta($this->user_id, 'frs_nmls', true) ?: '';
    }

    public function get_nmls_number(): string {
        return get_user_meta($this->user_id, 'frs_nmls_number', true) ?: '';
    }

    public function get_license_number(): string {
        return get_user_meta($this->user_id, 'frs_license_number', true) ?: '';
    }

    public function get_dre_license(): string {
        return get_user_meta($this->user_id, 'frs_dre_license', true) ?: '';
    }

    public function get_brand(): string {
        return get_user_meta($this->user_id, 'frs_brand', true) ?: '';
    }

    public function get_status(): string {
        return get_user_meta($this->user_id, 'frs_status', true) ?: 'active';
    }

    // ===========================================
    // Location
    // ===========================================

    public function get_city_state(): string {
        return get_user_meta($this->user_id, 'frs_city_state', true) ?: '';
    }

    public function get_region(): string {
        return get_user_meta($this->user_id, 'frs_region', true) ?: '';
    }

    // ===========================================
    // Arrays (JSON fields)
    // ===========================================

    public function get_specialties_lo(): array {
        $json = get_user_meta($this->user_id, 'frs_specialties_lo', true);
        if (empty($json)) {
            return [];
        }
        $decoded = is_string($json) ? json_decode($json, true) : $json;
        return is_array($decoded) ? $decoded : [];
    }

    public function get_specialties(): array {
        $json = get_user_meta($this->user_id, 'frs_specialties', true);
        if (empty($json)) {
            return [];
        }
        $decoded = is_string($json) ? json_decode($json, true) : $json;
        return is_array($decoded) ? $decoded : [];
    }

    public function get_languages(): array {
        $json = get_user_meta($this->user_id, 'frs_languages', true);
        if (empty($json)) {
            return [];
        }
        $decoded = is_string($json) ? json_decode($json, true) : $json;
        return is_array($decoded) ? $decoded : [];
    }

    public function get_awards(): array {
        $json = get_user_meta($this->user_id, 'frs_awards', true);
        if (empty($json)) {
            return [];
        }
        $decoded = is_string($json) ? json_decode($json, true) : $json;
        return is_array($decoded) ? $decoded : [];
    }

    public function get_nar_designations(): array {
        $json = get_user_meta($this->user_id, 'frs_nar_designations', true);
        if (empty($json)) {
            return [];
        }
        $decoded = is_string($json) ? json_decode($json, true) : $json;
        return is_array($decoded) ? $decoded : [];
    }

    public function get_namb_certifications(): array {
        $json = get_user_meta($this->user_id, 'frs_namb_certifications', true);
        if (empty($json)) {
            return [];
        }
        $decoded = is_string($json) ? json_decode($json, true) : $json;
        return is_array($decoded) ? $decoded : [];
    }

    public function get_service_areas(): array {
        $json = get_user_meta($this->user_id, 'frs_service_areas', true);
        if (empty($json)) {
            return [];
        }
        $decoded = is_string($json) ? json_decode($json, true) : $json;
        return is_array($decoded) ? $decoded : [];
    }

    public function get_custom_links(): array {
        $json = get_user_meta($this->user_id, 'frs_custom_links', true);
        if (empty($json)) {
            return [];
        }
        $decoded = is_string($json) ? json_decode($json, true) : $json;
        return is_array($decoded) ? $decoded : [];
    }

    public function get_personal_branding_images(): array {
        $json = get_user_meta($this->user_id, 'frs_personal_branding_images', true);
        if (empty($json)) {
            return [];
        }
        $decoded = is_string($json) ? json_decode($json, true) : $json;
        return is_array($decoded) ? $decoded : [];
    }

    // ===========================================
    // Social Media
    // ===========================================

    public function get_facebook_url(): string {
        return get_user_meta($this->user_id, 'frs_facebook_url', true) ?: '';
    }

    public function get_instagram_url(): string {
        return get_user_meta($this->user_id, 'frs_instagram_url', true) ?: '';
    }

    public function get_linkedin_url(): string {
        return get_user_meta($this->user_id, 'frs_linkedin_url', true) ?: '';
    }

    public function get_twitter_url(): string {
        return get_user_meta($this->user_id, 'frs_twitter_url', true) ?: '';
    }

    public function get_youtube_url(): string {
        return get_user_meta($this->user_id, 'frs_youtube_url', true) ?: '';
    }

    public function get_tiktok_url(): string {
        return get_user_meta($this->user_id, 'frs_tiktok_url', true) ?: '';
    }

    public function get_website(): string {
        return $this->user->user_url ?: get_user_meta($this->user_id, 'frs_website', true) ?: '';
    }

    // ===========================================
    // Tools & Platforms
    // ===========================================

    public function get_arrive(): string {
        return get_user_meta($this->user_id, 'frs_arrive', true) ?: '';
    }

    public function get_arrive_url(): string {
        return $this->get_arrive(); // Alias
    }

    public function get_apply_url(): string {
        return $this->get_arrive(); // Alias
    }

    public function get_canva_folder_link(): string {
        return get_user_meta($this->user_id, 'frs_canva_folder_link', true) ?: '';
    }

    public function get_niche_bio_content(): string {
        return get_user_meta($this->user_id, 'frs_niche_bio_content', true) ?: '';
    }

    // ===========================================
    // Profile Settings
    // ===========================================

    public function get_profile_slug(): string {
        $custom = get_user_meta($this->user_id, 'frs_custom_slug', true);
        return $custom ?: $this->user->user_nicename;
    }

    public function get_profile_headline(): string {
        return get_user_meta($this->user_id, 'frs_profile_headline', true) ?: '';
    }

    public function get_profile_visibility(): array {
        $json = get_user_meta($this->user_id, 'frs_profile_visibility', true);
        if (empty($json)) {
            return [];
        }
        $decoded = is_string($json) ? json_decode($json, true) : $json;
        return is_array($decoded) ? $decoded : [];
    }

    public function get_profile_theme(): string {
        return get_user_meta($this->user_id, 'frs_profile_theme', true) ?: 'default';
    }

    // ===========================================
    // Media & Assets
    // ===========================================

    public function get_headshot_id(): int {
        return \FRSUsers\Core\Avatar::get_id($this->user_id);
    }

    public function get_headshot_url(): string {
        return \FRSUsers\Core\Avatar::get_url($this->user_id) ?: '';
    }

    public function get_qr_code_data(): string {
        return get_user_meta($this->user_id, 'frs_qr_code_data', true) ?: '';
    }

    public function get_booking_url(): string {
        return get_user_meta($this->user_id, 'frs_booking_url', true) ?: '';
    }

    // ===========================================
    // Additional Fields
    // ===========================================

    public function get_loan_officer_profile(): int {
        return (int) get_user_meta($this->user_id, 'frs_loan_officer_profile', true);
    }

    public function get_loan_officer_user(): int {
        return (int) get_user_meta($this->user_id, 'frs_loan_officer_user', true);
    }

    // ===========================================
    // Metadata
    // ===========================================

    public function is_active(): bool {
        $is_active = get_user_meta($this->user_id, 'frs_is_active', true);
        return $is_active !== '0' && $is_active !== false;
    }

    public function get_created_at(): string {
        return $this->user->user_registered;
    }

    public function get_synced_to_fluentcrm_at(): string {
        return get_user_meta($this->user_id, 'frs_synced_to_fluentcrm_at', true) ?: '';
    }

    public function get_frs_agent_id(): string {
        return get_user_meta($this->user_id, 'frs_frs_agent_id', true) ?: '';
    }

    // ===========================================
    // Integration Fields
    // ===========================================

    public function get_followupboss_api_key(): string {
        return get_user_meta($this->user_id, 'frs_followupboss_api_key', true) ?: '';
    }

    public function get_followupboss_status(): array {
        $json = get_user_meta($this->user_id, 'frs_followupboss_status', true);
        if (empty($json)) {
            return [];
        }
        $decoded = is_string($json) ? json_decode($json, true) : $json;
        return is_array($decoded) ? $decoded : [];
    }

    public function get_notification_settings(): array {
        $json = get_user_meta($this->user_id, 'frs_notification_settings', true);
        if (empty($json)) {
            return [];
        }
        $decoded = is_string($json) ? json_decode($json, true) : $json;
        return is_array($decoded) ? $decoded : [];
    }

    public function get_privacy_settings(): array {
        $json = get_user_meta($this->user_id, 'frs_privacy_settings', true);
        if (empty($json)) {
            return [];
        }
        $decoded = is_string($json) ? json_decode($json, true) : $json;
        return is_array($decoded) ? $decoded : [];
    }

    // ===========================================
    // Role Helpers
    // ===========================================

    public function get_roles(): array {
        return $this->user->roles ?: [];
    }

    public function has_role(string $role): bool {
        return in_array($role, $this->get_roles(), true);
    }

    public function is_loan_officer(): bool {
        return $this->has_role('loan_officer');
    }

    public function is_realtor(): bool {
        return $this->has_role('partner');
    }

    public function is_staff(): bool {
        return $this->has_role('staff');
    }

    public function is_leadership(): bool {
        return $this->has_role('leadership');
    }

    public function is_assistant(): bool {
        return $this->has_role('assistant');
    }

    public function get_person_type(): string {
        $roles = $this->get_roles();
        $type_map = [
            'loan_officer' => 'loan_officer',
            'partner' => 'partner',
            'staff' => 'staff',
            'leadership' => 'leadership',
            'assistant' => 'assistant',
        ];

        foreach ($type_map as $role => $type) {
            if (in_array($role, $roles, true)) {
                return $type;
            }
        }

        return '';
    }

    // ===========================================
    // Static Factory Methods
    // ===========================================

    /**
     * Find user by ID
     *
     * @param int $id User ID
     * @return self|null
     */
    public static function find($id): ?self {
        try {
            return new self($id);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Find user by email
     *
     * @param string $email Email address
     * @return self|null
     */
    public static function get_by_email(string $email): ?self {
        try {
            return new self($email);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Find user by slug
     *
     * @param string $slug User nicename/slug
     * @return self|null
     */
    public static function get_by_slug(string $slug): ?self {
        $user = get_user_by('slug', $slug);
        if (!$user) {
            // Try custom slug
            $users = get_users([
                'meta_key' => 'frs_custom_slug',
                'meta_value' => $slug,
                'number' => 1,
            ]);
            $user = $users ? $users[0] : null;
        }

        if (!$user) {
            return null;
        }

        try {
            return new self($user);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Find user by NMLS
     *
     * @param string $nmls NMLS number
     * @return self|null
     */
    public static function get_by_nmls(string $nmls): ?self {
        $users = get_users([
            'meta_key' => 'frs_nmls',
            'meta_value' => $nmls,
            'number' => 1,
        ]);

        if (empty($users)) {
            return null;
        }

        try {
            return new self($users[0]);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Find user by DRE license
     *
     * @param string $dre DRE license number
     * @return self|null
     */
    public static function get_by_dre(string $dre): ?self {
        $users = get_users([
            'meta_key' => 'frs_dre_license',
            'meta_value' => $dre,
            'number' => 1,
        ]);

        if (empty($users)) {
            return null;
        }

        try {
            return new self($users[0]);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get all FRS users
     *
     * @param array $args Query arguments
     * @return array Array of UserProfile instances
     */
    public static function get_all(array $args = []): array {
        $defaults = [
            'role__in' => [
                'loan_officer',
                're_agent',
                'escrow_officer',
                'property_manager',
                'dual_license',
                'partner',
                'staff',
                'leadership',
                'assistant',
            ],
            'number' => -1,
            'orderby' => 'display_name',
            'order' => 'ASC',
        ];

        $query_args = wp_parse_args($args, $defaults);
        $users = get_users($query_args);

        $profiles = [];
        foreach ($users as $user) {
            try {
                $profiles[] = new self($user);
            } catch (\Exception $e) {
                // Skip invalid users
                continue;
            }
        }

        return $profiles;
    }

    /**
     * Get users by role/type
     *
     * @param string $role Role name
     * @param array $args Additional query arguments
     * @return array Array of UserProfile instances
     */
    public static function get_by_type(string $role, array $args = []): array {
        $args['role'] = $role;
        return self::get_all($args);
    }

    // ===========================================
    // Profile Completion
    // ===========================================

    /**
     * Get auto-computed profile completion items
     *
     * Returns checklist items based on which profile fields are filled/empty.
     * Role-aware: conditional items (NMLS, DRE, Arrive) only appear for relevant roles.
     *
     * @return array Array of completion item arrays with type, key, title, is_completed.
     */
    public function get_profile_completion_items(): array {
        $roles = $this->get_roles();
        $is_lo = in_array( 'loan_officer', $roles, true ) || in_array( 'dual_license', $roles, true );
        $is_agent = in_array( 're_agent', $roles, true );

        $items = array();

        // Universal items (all roles)
        $items[] = array(
            'type'         => 'auto',
            'key'          => 'headshot',
            'title'        => 'Upload a professional headshot',
            'is_completed' => $this->get_headshot_url() !== '',
        );

        $items[] = array(
            'type'         => 'auto',
            'key'          => 'biography',
            'title'        => 'Write your professional biography',
            'is_completed' => $this->get_biography() !== '',
        );

        $items[] = array(
            'type'         => 'auto',
            'key'          => 'phone_number',
            'title'        => 'Add your phone number',
            'is_completed' => $this->get_phone_number() !== '',
        );

        $items[] = array(
            'type'         => 'auto',
            'key'          => 'service_areas',
            'title'        => 'Select your service areas',
            'is_completed' => ! empty( $this->get_service_areas() ),
        );

        $items[] = array(
            'type'         => 'auto',
            'key'          => 'specialties',
            'title'        => 'Choose your specialties',
            'is_completed' => ! empty( $this->get_specialties() ) || ! empty( $this->get_specialties_lo() ),
        );

        $items[] = array(
            'type'         => 'auto',
            'key'          => 'social_link',
            'title'        => 'Add at least one social media link',
            'is_completed' => $this->get_facebook_url() !== ''
                || $this->get_instagram_url() !== ''
                || $this->get_linkedin_url() !== ''
                || $this->get_twitter_url() !== ''
                || $this->get_youtube_url() !== ''
                || $this->get_tiktok_url() !== '',
        );

        // Loan officer / dual license items
        if ( $is_lo ) {
            $items[] = array(
                'type'         => 'auto',
                'key'          => 'nmls',
                'title'        => 'Add your NMLS number',
                'is_completed' => $this->get_nmls() !== '',
            );

            $items[] = array(
                'type'         => 'auto',
                'key'          => 'arrive_url',
                'title'        => 'Set your loan application URL',
                'is_completed' => $this->get_arrive_url() !== '',
            );
        }

        // Real estate agent items
        if ( $is_agent ) {
            $items[] = array(
                'type'         => 'auto',
                'key'          => 'dre_license',
                'title'        => 'Add your DRE license number',
                'is_completed' => $this->get_dre_license() !== '',
            );
        }

        return $items;
    }
}
