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

## Using it in an ACF module

The plugin doesn't ship a block or shortcode — themes call `bac_render_form()`
directly. The usual pattern is a Flexible Content layout ("ActiveCampaign Form")
with a single field for the form ID.

### 1. ACF field group

Add these sub-fields to the Flexible Content layout (Bonsai module pattern —
one folder per layout: `module.php`, `_module.scss`):

| Field | Name | Type | Notes |
|---|---|---|---|
| AC Form ID | `ac_form_id` | Number (or Text) | The numeric ID from ActiveCampaign. It's also shown in **Settings > ActiveCampaign** next to each synced form. |
| Heading | `heading` | Text | Optional — module heading above the form. |
| Intro | `intro` | Wysiwyg | Optional. |
| Fallback list ID | `fallback_list_id` | Number | Optional — only needed if a form's definition has no list attached. |

If you'd rather editors pick from a list than paste an ID, make `ac_form_id` a
**Select** and populate it with an `acf/load_field` filter:

```php
/**
 * Populate the ActiveCampaign form picker from synced forms.
 */
add_filter( 'acf/load_field/name=ac_form_id', 'mytheme_load_ac_form_choices' );
function mytheme_load_ac_form_choices( $field ) {
	$field['choices'] = array( '' => __( '— Select a form —', 'mytheme' ) );

	if ( function_exists( 'bac_get_forms' ) ) {
		foreach ( bac_get_forms() as $form ) {
			$field['choices'][ (int) $form['ac_form_id'] ] = sprintf( '%s (#%d)', $form['name'], $form['ac_form_id'] );
		}
	}

	return $field;
}
```

### 2. Module template (`module.php`)

```php
<?php
/**
 * Flexible Content layout: ActiveCampaign Form.
 */

defined( 'ABSPATH' ) || exit;

$ac_form_id      = (int) get_sub_field( 'ac_form_id' );
$heading         = get_sub_field( 'heading' );
$intro           = get_sub_field( 'intro' );
$fallback_list   = (int) get_sub_field( 'fallback_list_id' );

// Nothing to render without an ID, or if the plugin is inactive.
if ( ! $ac_form_id || ! function_exists( 'bac_render_form' ) ) {
	return;
}

// Optional: skip the whole module if the form hasn't synced yet.
if ( ! bac_get_form( $ac_form_id ) ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( 'ActiveCampaign module: form ' . $ac_form_id . ' not synced yet.' );
	}
	return;
}
?>
<section class="active-campaign-module">
	<div class="active-campaign-module__inner">

		<?php if ( $heading ) : ?>
			<h2 class="active-campaign-module__title"><?php echo esc_html( $heading ); ?></h2>
		<?php endif; ?>

		<?php if ( $intro ) : ?>
			<div class="active-campaign-module__intro content-block">
				<?php echo wp_kses_post( $intro ); ?>
			</div>
		<?php endif; ?>

		<?php
		bac_render_form(
			$ac_form_id,
			array(
				'class'   => 'active-campaign-module__box--flex',
				'list_id' => $fallback_list, // ignored if the form already has a list
			)
		);
		?>

	</div>
</section>
```

`bac_render_form()` echoes the markup, enqueues its own script/style, and handles
the AJAX submit + thank-you swap. The theme only supplies the wrapper, heading
and intro.

### 3. Guard checklist

- `function_exists( 'bac_render_form' )` — degrades gracefully if the plugin is
  deactivated.
- `if ( ! $ac_form_id )` — don't render an empty module when the field is blank.
- `bac_get_form( $id )` check — optional, but avoids a silent empty module when a
  form ID is set but hasn't synced yet (run **Sync forms now** in settings).
- Field name for the ID must match whatever you pass to `bac_render_form()` —
  `ac_form_id` throughout above.

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

## Roadmap / ideas

Not built yet — a running list of improvements, roughly in priority order.
Contributions welcome; delete an item when it ships and add a CHANGELOG entry.

### Editor experience

- **Ship a form picker instead of a raw ID field.** Right now a theme has to
  wire up its own `acf/load_field` filter (see *Using it in an ACF module*).
  Options, cheapest first:
  - A helper `bac_acf_form_choices()` returning `array( id => 'Name (#id)' )`
    so a theme's filter is a one-liner.
  - A drop-in filter the plugin registers itself for a conventional field name
    (e.g. any ACF field named `ac_form_id` or `bac_form_id` becomes a select),
    toggleable from settings.
  - A proper custom ACF field type (`activecampaign_form`) — nicest UX, most
    work, needs ACF Pro present.
- **Gutenberg block + shortcode** (`[bac_form id="12"]`) so non-Bonsai sites and
  the block editor can drop a form in without theme code.
- **Preview in the admin** — render a synced form on its row in
  *Settings > ActiveCampaign* so editors can see what they're picking.

### Sync / reliability

- **Manual "resync single form"** action on each row, rather than all-or-nothing.
- **Admin notice when a sync fails** N times in a row (currently only in the log
  under `WP_DEBUG`).
- **Webhook endpoint** so ActiveCampaign pushes form changes instantly instead
  of waiting up to 15 minutes for cron.
- **Store the raw API payload** alongside the parsed form, to make debugging
  field-mapping issues possible without a re-fetch.

### Forms / front-end

Native rendering means AC's own JS widget never loads, so anything it used to do
for the form is now ours to reproduce:

- **Honour all of the form's configured actions.** The submit handler currently
  does `contact/sync` + a single `contactLists` subscribe. AC forms can also
  define tags, multiple lists, custom-field writes and deal actions in their
  `action_data` — parse and apply those.
- **Honour the form's redirect action.** If the AC form is set to redirect on
  submit rather than show a thank-you message, follow that instead of the
  inline swap. Fall back to inline when none is set.
- **Honeypot + time-trap spam protection** on the AJAX submit. AC's widget has
  its own spam/reCAPTCHA handling; native submit has none. No external service,
  no CAPTCHA.
- **Cookiebot / consent awareness** — optionally hold submission until consent,
  in line with the Bonsai tracking rules.
- **Client-side validation messages** styled to match the module, instead of
  the browser default bubble.
- **`bac_form_submitted` → analytics** — documented recipe for pushing a
  consent-gated GTM `dataLayer` event on success.

### Housekeeping

- `languages/` + a `.pot` file (the text domain is already loaded).
- PHPUnit coverage for `BAC_Api` (URL normalisation, error extraction) and the
  list-ID resolver in `bac_get_form_list_id()` — it has several fallback
  branches and no tests.
- Settings link straight to the ActiveCampaign API Access page.