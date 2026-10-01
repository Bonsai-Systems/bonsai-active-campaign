# Changelog

All notable changes to this project are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
- [includes/class-bac-form-post.php] Successful submissions on forms set to **redirect to URL** were treated as failures and also went through the API fallback. For these forms `proc.php` answers with `window.top.location.href = "…"` instead of `_show_thank_you(...)`. A redirect now counts as success, as long as there's no `_show_error`.

### Added
- [includes/class-bac-submit.php, assets/js/bac-form.js] Visitors are sent to the form's "redirect to URL" after submitting, as on an AC landing page. The inline thanks message shows first while the next page loads. The URL is limited to http/https. New `bac_form_redirect` filter to change it, or return `''` to stay on the page.

## [1.2.1] - 2026-10-01

### Fixed
- [includes/class-bac-form-post.php] Form submissions always fell back to the API, so "Submits a form" automations still didn't run. The plugin always POSTed to `proc.php`, but ActiveCampaign's embed only POSTs for forms with `formSupportsPost = true`. For every other form it loads `proc.php?{fields}&jsonp=true` with a GET, and a POST got back a 200 the plugin didn't recognise. The plugin now reads `formSupportsPost` from the embed and submits the same way. GET by default, with the query string built like the embed's (`field[N][]` for checkboxes, RFC 3986 encoding). POST forms send `Accept: application/json`, and the `{"js": …}` response is unwrapped before checking for `_show_thank_you`. Embed values cached by 1.2.0 have no `formSupportsPost` value and fall back to GET.

### Added
- [includes/class-bac-form-post.php] When a `proc.php` response isn't recognised, the debug log (with `WP_DEBUG`) now records the response content type, the final URL if a redirect was followed, and the first 500 characters of the body with tags stripped. Email addresses are masked.

## [1.2.0] - 2026-10-01

### Fixed
- [includes/class-bac-form-post.php (new), includes/class-bac-submit.php, includes/class-bac-api.php] Submissions didn't start ActiveCampaign automations with a **"Submits a form"** trigger, and the form's own actions (tags, extra lists, double opt-in) were never applied. The plugin only created the contact and subscribed them to a list via API v3, which ActiveCampaign doesn't record as a form submission. Forms now submit server-side to the account's `proc.php`, the same endpoint AC landing pages and embeds use. The per-form hidden values (`u`, `or`) are read from the public `/f/embed.php?id={id}` script and cached for 12 hours. The cache is cleared on a failed post so edited forms pick up new values. The action URL must be `https://{account}.activehosted.com/proc.php` or it's rejected. Payload matches AC's embed: `firstname`/`lastname`, `field[N]`, and checkboxes as `field[N][]` with the `~|` marker.

### Added
- API v3 fallback: if the `proc.php` post fails, the previous `contact/sync` + `contactLists` flow runs so the lead isn't lost, and the failure is logged (with `WP_DEBUG`).
- `bac_use_form_post` filter (default `true`) to turn the form post off; `bac_form_host` filter to override the derived `acct.activehosted.com` host.
- `BAC_Api::find_contact_id_by_email()`. `bac_form_submitted` still receives the contact ID after a `proc.php` submission.

## [1.1.0] - 2026-09-30

### Changed
- [includes/class-bac-admin-ui.php, assets/] Settings screen restyled with the Bonsai admin design system: logo header with version and GitHub/changelog links; account settings, connection & sync, and synced forms each in their own card. Last sync now shows as a success/failed badge with a status list. Stylesheet loads on this screen only. No option or field changes.

### Fixed
- [includes/class-bac-settings.php] Field labels weren't tied to their inputs; added `label_for` and IDs.
- [includes/class-bac-settings.php] Test/sync result notices showed on whichever admin screen the next page load happened to be, and to any admin. They're now stored per user and only shown on the ActiveCampaign settings screen.
- [includes/class-bac-settings.php] Sanitise callback now guards against non-array input.
- Removed the inline `style` attribute from the forms table.


## [1.0.1] - 2026-09-24

### Fixed
- [composer.json, vendor/] Fatal error (`Cannot declare class ComposerAutoloaderInit057850a63dccc1b5ea3cf2a346d50db8, because the name is already in use`) when active alongside Bonsai Code Injector. `vendor/` had been copied from that plugin, and Composer reuses the suffix already in `vendor/autoload.php`, so both shipped the same autoloader class. Set a fixed `config.autoloader-suffix` (`BonsaiActiveCampaign`) and regenerated the autoloader: class is now `ComposerAutoloaderInitBonsaiActiveCampaign`.

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
- README: added a "Roadmap / ideas" section (form picker/block/shortcode,
  per-form resync, webhook sync, honouring the AC form's own actions/redirect,
  spam protection, consent gating, i18n, test coverage).

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
