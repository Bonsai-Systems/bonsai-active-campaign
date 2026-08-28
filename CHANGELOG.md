# Changelog

All notable changes to this project are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased] - 2026-08-28

### Added
- GitHub-based automatic updates via the Yahnis Elsts Plugin Update Checker
  (`vendor/`, Composer-managed). Checks `Bonsai-Systems/bonsai-active-campaign`
  `main` branch and updates from release assets — same setup as Bonsai Code
  Injector.

### Changed
- `BAC_Api` now normalises the API URL, so the connection works whether the
  bare account URL (`https://acct.api-us1.com`) or the full `/api/3` URL is
  entered in settings.
- README: added an "Using it in an ACF module" section (field group, Flexible
  Content module template, `function_exists` / form-ID guards, optional
  `acf/load_field` form picker).

## [1.0.0] - 2026-08-28

### Added
- Initial release. Replaces the original standalone "ActiveCampaign Form
  Repository" PHP app (separate MySQL database + cron + token-guarded JSON
  endpoint) with a self-contained WordPress plugin.
- **Settings > ActiveCampaign** page (Settings API): API URL, API key, sync
  frequency, "Test connection" and "Sync forms now" buttons, last-sync
  status, and a list of synced forms with their IDs.
- Custom table `{prefix}bac_forms` caching ActiveCampaign form definitions,
  kept in step by a WP-Cron sync (default every 15 minutes) with the
  original's hash-to-skip-unchanged and safe-deactivation behaviour.
- ActiveCampaign API v3 client (`BAC_Api`) over `wp_remote_*`, wrapped and
  logged, with pagination for form listing.
- Native form rendering (`bac_render_form()`) and server-side submission via
  `contact/sync` + `contactLists` — no iframe, no `proc.php`.
- Theme API: `bac_get_form()`, `bac_get_forms()`, `bac_render_form()`,
  `bac_ac_field_name()`, `bac_get_form_list_id()`.
- `bac_form_submitted` action hook.
