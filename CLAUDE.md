# CLAUDE.md

Technical reference for Claude Code when working with FRS User Profiles.

## Overview

WordPress plugin for managing professional profiles (loan officers, real estate agents, staff, leadership) across multiple sites with synchronized data.

**Human-readable documentation:** See `docs/README.md`

## Build Commands

```bash
# PHP
composer install
composer lint          # PHPCS
composer lint:fix      # PHPCBF
composer test          # PHPUnit

# Admin React (assets/admin/)
cd assets/admin && npm install && npm run build

# Gutenberg blocks (assets/blocks/)
cd assets/blocks && npm install && npm run build
```

## Architecture

### Data Storage

WordPress-native mode (default): `wp_users` + `wp_usermeta` with `frs_` prefix.

### Key Classes

| Class | Location | Purpose |
|-------|----------|---------|
| `Roles` | `includes/Core/Roles.php` | Centralized role/context config |
| `Profile` | `includes/Models/Profile.php` | Profile data model |
| `ProfileSync` | `includes/Core/ProfileSync.php` | Webhook synchronization |
| `ProfileApi` | `includes/Core/ProfileApi.php` | REST API handler |
| `CLI` | `includes/Core/CLI.php` | WP-CLI commands |
| `Template` | `includes/Core/Template.php` | URL routing |
| `FluentCRMSync` | `includes/Integrations/FluentCRMSync.php` | CRM integration |

### Directory Structure

```
includes/
├── Core/           # Core services (Roles, ProfileSync, CLI, Template)
├── Models/         # Data models (Profile, UserProfile)
├── Admin/          # Admin pages (DataViews-based)
├── Controllers/    # Blocks, shortcodes
├── Routes/         # REST API (Api.php)
├── Integrations/   # FluentCRM, Arrive
├── Abilities/      # WordPress Abilities API
└── DataKit/        # DataViews integration

assets/
├── admin/          # React admin interface
└── blocks/         # Gutenberg blocks

templates/profile/  # Frontend templates
database/Migrations/ # Schema migrations
```

## Two Role Systems

### WordPress Roles (Capabilities)

Define permissions and URL prefixes. Stored in `wp_usermeta` as `wp_capabilities`.

| Role | URL Prefix |
|------|------------|
| `loan_officer` | `/lo/` |
| `re_agent` | `/agent/` |
| `escrow_officer` | `/escrow/` |
| `property_manager` | `/pm/` |
| `dual_license` | `/lo/` |
| `staff` | `/staff/` |
| `leadership` | `/leader/` |
| `assistant` | `/staff/` |
| `partner` | (none) |

### Company Roles (Directory Categorization)

Define where profiles appear. Stored as multi-value `frs_company_role` meta.

| Company Role | Maps to WP Role |
|--------------|-----------------|
| `loan_originator` | `loan_officer` |
| `broker_associate` | `re_agent` |
| `sales_associate` | `re_agent` |
| `escrow_officer` | `escrow_officer` |
| `property_manager` | `property_manager` |
| `partner` | `partner` |
| `leadership` | `leadership` |
| `staff` | `staff` |

## Site Contexts

Set via `FRS_SITE_CONTEXT` constant in wp-config.php.

| Context | Company Roles | Editing |
|---------|---------------|---------|
| `development` | All 8 | Yes |
| `hub` | All 8 | Yes |
| `21stcenturylending` | loan_originator, leadership | No |
| `c21masters` | broker_associate, sales_associate, leadership | No |

**Precedence:** Constant → Filter (`frs_site_context`) → Option → Default (`development`)

## REST API

Base: `/wp-json/frs-users/v1/`

| Endpoint | Method | Auth |
|----------|--------|------|
| `/profiles` | GET | Public |
| `/profiles` | POST | Editor |
| `/profiles/{id}` | GET/PUT/DELETE | Public/Editor |
| `/profiles/slug/{slug}` | GET | Public |
| `/profiles/user/{id\|me}` | GET | Public |
| `/service-areas` | GET | Public |
| `/vcard/{id}` | GET | Public |
| `/meeting-request` | POST | Public |
| `/webhook/profile-updated` | POST | HMAC signed |

## WP-CLI Commands

```bash
# Context
wp frs-users site-context

# Sync
wp frs-users setup-sync --hub-url=<url>
wp frs-users setup-sync --generate-secret
wp frs-users sync-from-hub [--type=<type>] [--dry-run]

# Profiles
wp frs-users list-profiles [--type=<type>]
wp frs-users list-guests
wp frs-users create-user <profile_id>
wp frs-users generate-slugs
wp frs-users generate-qr-codes [--force]
wp frs-users generate-vcards [--type=<type>]

# Migrations
wp frs-users migrate-fields [--dry-run]
wp frs-users cleanup-fields [--dry-run]
```

## User Meta Keys

All prefixed with `frs_`:

**Core:** `phone_number`, `mobile_number`, `job_title`, `biography`, `headshot_id`

**Licensing:** `nmls`, `nmls_number`, `license_number`, `dre_license`

**Location:** `office`, `city_state`, `region`, `service_areas` (JSON)

**Social:** `facebook_url`, `instagram_url`, `linkedin_url`, `twitter_url`, `youtube_url`, `tiktok_url`

**Profile:** `profile_slug`, `profile_headline`, `profile_theme`, `is_active`, `qr_code_data`

**JSON arrays:** `specialties`, `specialties_lo`, `languages`, `custom_links`, `service_areas`

**Sync:** `company_role` (multi-value), `select_person_type`, `frs_agent_id`, `synced_to_fluentcrm_at`

## Hooks

| Hook | Type | Description |
|------|------|-------------|
| `frs_profile_saved` | Action | After profile save. Args: `$profile_id`, `$profile_data` |
| `frs_users_loaded` | Action | After plugin init |
| `frs_users_api_routes` | Action | Add custom REST routes |
| `frs_site_context` | Filter | Override site context |

## Webhook Sync

**Hub → Marketing Sites**

1. Profile saved on hub
2. `frs_profile_saved` fires
3. POST to registered endpoints with HMAC-SHA256 signature
4. Marketing site verifies signature
5. Filters by site context company roles
6. Creates/updates user if role matches

**Payload:**
```json
{
  "event": "profile_updated",
  "timestamp": 1704931200,
  "profile": { ... }
}
```

## Gutenberg Blocks

| Block | Description |
|-------|-------------|
| `frs/lo-directory` | Searchable directory with service area filtering |

Uses WordPress Interactivity API (`view.js`).

## Integrations

- **FluentCRM:** Real-time sync on user create/update/role change
- **Simple Local Avatars:** Avatar system for headshots
- **Arrive:** Auto-populate loan application URLs
- **MultisiteProvisioning:** On `user_register`, adds user to blog 1 (`subscriber`) and blog 2 (`loan_officer`). See below for why.

## Multisite User Provisioning

**File:** `includes/Integrations/MultisiteProvisioning.php`

### Why it exists

Post-SSO, WPO365 lands users on the `redirect_url` (main site, `https://myhub21.com/`). The fluent snippet `6-login-redirect.php` would redirect them to `/lending/`, but `ms_site_check()` runs **before** the snippet's `init` hook and kills the request with *"your admin needs to add you to the site"* when the user has no main-site membership.

`MultisiteProvisioning` hooks `user_register` (priority 20, after WPO365) and ensures every new user is a member of both:

| Blog | Path | Default role |
|------|------|--------------|
| 1 | `/` | `subscriber` |
| 2 | `/lending/` | `loan_officer` |

Main-site membership lets `ms_site_check` pass, so the fluent snippet can then redirect to `/lending`, where `loan_officer` membership lets the onboarding wizard fire.

### Future — company directory

The main site is planned to become a BuddyPress-backed company directory. `subscriber` is the placeholder role; revisit once BuddyPress is installed and a directory role exists.

## DO NOT MODIFY (myhub21.com)

These files on myhub21.com control login redirects and must NOT be changed without explicit user approval:

1. **Fluent Snippet**: `/app/data/public/wp-content/fluent-snippet-storage/6-login-redirect.php`
   - Contains `login_redirect` filter that returns `home_url('/')`
   - Contains redirect from main site to `/lending/`

2. **WPO365 Config**: `/app/data/public/wpo365-config.php`
   - `goto_after_signon_url` must be empty (let fluent snippet handle redirect)
   - `redirect_url` = `https://myhub21.com/`
   - See "Azure AD Two-App Architecture" section below for app settings

3. **FRS M365 Widgets**: The widgets use `https://graph.microsoft.com/User.Read` as the scope for all `/me` endpoint calls. See inline documentation in `frs-m365-widgets.php` and `widgets.js`.

## Azure AD Two-App Architecture (myhub21.com)

**DO NOT CHANGE** these settings without understanding the full architecture:

### Two Apps Required

| Setting | App Name | App ID | Purpose |
|---------|----------|--------|---------|
| `application_id` | hub21 | `09c85172-9ea1-4f34-a6f8-f19afabb4e0b` | SSO login, M365 widgets (delegated tokens) |
| `app_only_application_id` | hub21-app-only | `3c74e9b9-1dbc-4a67-9d99-28a2eaf3b039` | User Sync, background tasks (app-only tokens) |

### Critical Setting

```php
"use_app_only_token" => false,  // MUST be false!
```

**Why false?** The M365 widgets call `/me` endpoint which REQUIRES delegated tokens (user context). App-only tokens have no user context, so `/me` fails.

### Why Two Apps?

| Scenario | Token Type Needed |
|----------|-------------------|
| M365 widgets (`/me` endpoint) | Delegated (requires user) |
| User Sync (background job) | App-only (no user logged in) |

With `use_app_only_token=false`, WPO365:
- Uses delegated tokens by default (widgets work)
- Still uses `app_only_application_id` when app-only is explicitly needed (User Sync works)

### App-Only App Permissions (hub21-app-only)

User.Read.All, Mail.Read, Calendars.Read, GroupMember.Read.All, GroupMember.ReadWrite.All, Group.Read.All, Files.Read.All, Contacts.Read, Contacts.ReadWrite

### If Widgets Break

Error: `/me request is only valid with delegated authentication flow`

Fix: Ensure `use_app_only_token` = `false` in `/app/data/public/wpo365-config.php`

## Deployment (Deployer for Git webhook)

Auto-deploys to production (`myhub21.com` on Cloudron) via the **Deployer for Git (Pro)** plugin, which watches `github.com/fullrealtyservices/frs-wp-users` on the **`production`** branch.

```bash
git push origin main                 # dev
git push origin main:production      # deploy (a server hook requires main be pushed first)
```

Pushing `production` fires a GitHub webhook to `/wp-json/dfg/v1/package_update?secret=…&type=plugin&package=frs-wp-users`, which pulls the production branch and installs it live — no manual file copy.

Notes:
- Push auth: use the gh CLI credential helper (`gh auth setup-git`); the macOS `osxkeychain` helper can fail with `Device not configured`.
- The deployer's cache flush does **not** reset PHP opcache, so a *modified* file's change may not appear until the app is restarted (`cloudron restart --app <id>`) + `wp cache flush`. New files load fine.
