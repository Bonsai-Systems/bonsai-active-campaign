<?php
/**
 * Bonsai admin UI: shared header and stylesheet for this plugin's screens.
 *
 * Markup and CSS follow the Bonsai admin design system
 * (assets/bonsai-admin-ui.css, canonical copy in bonsai-seo-geo-checker).
 * Self-contained: nothing here depends on another Bonsai plugin.
 *
 * @package Bonsai_ActiveCampaign
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Bonsai header and loads the design-system stylesheet.
 */
class BAC_Admin_UI {

	const REPO    = 'https://github.com/Bonsai-Systems/bonsai-active-campaign';
	const WEBSITE = 'https://bonsaidigitalcollective.co.uk/';
	const HANDLE  = 'bac-bonsai-admin-ui';

	/**
	 * Enqueue the design-system stylesheet. Call from admin_enqueue_scripts
	 * on this plugin's screens only.
	 */
	public static function enqueue() {
		wp_enqueue_style( self::HANDLE, BAC_URL . 'assets/bonsai-admin-ui.css', array(), BAC_VERSION );
	}

	/**
	 * Print the page header, followed by the marker core uses to place
	 * admin notices, so notices sit below the header rather than above it.
	 *
	 * @param string $title Page title (plain text).
	 * @param string $lead  Short description. May contain links, code and emphasis.
	 * @param array  $links Extra links shown before the standard ones: each array( 'label' => '', 'url' => '' ).
	 */
	public static function header( $title, $lead = '', $links = array() ) {
		$links = array_merge(
			$links,
			array(
				array(
					'label'    => __( 'GitHub', 'bonsai-active-campaign' ),
					'url'      => self::REPO,
					'external' => true,
				),
				array(
					'label'    => __( 'Changelog', 'bonsai-active-campaign' ),
					'url'      => self::REPO . '/releases',
					'external' => true,
				),
				array(
					'label'    => __( 'The Bonsai Digital Collective', 'bonsai-active-campaign' ),
					'url'      => self::WEBSITE,
					'external' => true,
				),
			)
		);
		?>
		<header class="bonsai-ui-header">
			<div class="bonsai-ui-header__main">
				<img class="bonsai-ui-header__logo" src="<?php echo esc_url( BAC_URL . 'assets/bonsai-avatar.jpg' ); ?>" width="412" height="108" alt="<?php esc_attr_e( 'The Bonsai Digital Collective', 'bonsai-active-campaign' ); ?>">
				<h1 class="bonsai-ui-header__title"><?php echo esc_html( $title ); ?></h1>
				<?php if ( '' !== $lead ) : ?>
					<p class="bonsai-ui-header__lead"><?php echo wp_kses( $lead, self::lead_tags() ); ?></p>
				<?php endif; ?>
				<ul class="bonsai-ui-header__links">
					<?php foreach ( $links as $link ) : ?>
						<li>
							<?php if ( ! empty( $link['external'] ) ) : ?>
								<a href="<?php echo esc_url( $link['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $link['label'] ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'bonsai-active-campaign' ); ?></span></a>
							<?php else : ?>
								<a href="<?php echo esc_url( $link['url'] ); ?>"><?php echo esc_html( $link['label'] ); ?></a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<div class="bonsai-ui-header__meta">
				<span class="bonsai-ui-version">v<?php echo esc_html( BAC_VERSION ); ?></span>
			</div>
		</header>
		<hr class="wp-header-end">
		<?php
	}

	/**
	 * Tags allowed in the header lead.
	 *
	 * @return array
	 */
	private static function lead_tags() {
		return array(
			'a'      => array( 'href' => true ),
			'code'   => array(),
			'strong' => array(),
			'em'     => array(),
		);
	}
}
