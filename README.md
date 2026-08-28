# Bonsai ActiveCampaign

Connects a WordPress site to an ActiveCampaign account so ActiveCampaign forms
can be rendered and submitted natively — no ActiveCampaign JavaScript widget, no
`proc.php` iframe.

## What it does

1. **Settings > ActiveCampaign** — enter the API URL and API key from
   ActiveCampaign (*Settings > Developer > API Access*). Buttons to **test the
   connection** and **sync forms now**.
2. **Sync** — a WP-Cron job (default every 15 minutes) pulls every form
   definition from the ActiveCampaign API into a local table
   (`{prefix}bac_forms`). Missing forms are only deactivated after a fully
   successful fetch, so a partial API response can never wipe live forms.
3. **Render** — `bac_render_form( $id )` outputs the form (fields, labels,
   button text, thank-you copy all come from ActiveCampaign).
4. **Submit** — the form posts to `admin-ajax.php`. The plugin creates/updates
   the contact via `POST /api/3/contact/sync`, then adds them to the form's
   list via `POST /api/3/contactLists`. On success the form is replaced by the
   ActiveCampaign thank-you message.

## Theme API

All functions are guarded — a theme should call them behind
`function_exists()` so it degrades gracefully when the plugin is inactive.

| Function | Purpose |
|---|---|
| `bac_get_form( $id )` | Cached form definition as an array, or `null`. |
| `bac_get_forms()` | List of active forms (`ac_form_id`, `name`, `synced_at`). |
| `bac_render_form( $id, $args = [] )` | Echo a ready-to-submit form. `$args`: `class`, `submit_label`, `list_id` (fallback list). |
| `bac_ac_field_name( $field )` | Map an AC field definition to its form `name`. |
| `bac_get_form_list_id( $form )` | Resolve the target list ID from a form's action data. |

### Hook

```php
do_action( 'bac_form_submitted', $form_id, $contact_id, $submission );
```

## Markup / styling

Rendered forms use the same BEM classes as the NEC SWS theme's
`active_campaign_module` (`.active-campaign-module__input`,
`.active-campaign-module__submit`, …) so existing module CSS styles them. The
plugin ships only minimal fallback CSS (`assets/css/bac-form.css`) for the
error message and loading state.

## Requirements

- WordPress 6.0+, PHP 7.4+
- An ActiveCampaign account with API access
- jQuery (bundled with WordPress) for the front-end submit script

## Notes

- The API key is stored in the `bac_settings` option. The settings page is
  restricted to `manage_options`.
- Uninstalling the plugin drops the table and deletes all options.