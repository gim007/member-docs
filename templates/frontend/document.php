<?php
/**
 * Shortcode output asking the member to accept the current version.
 *
 * @package MemberDocs
 *
 * @var array{title: string, version: int, version_id: int, body: string, endpoint: string} $args
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="member-docs member-docs--pending">
	<h2 class="member-docs__title"><?php echo esc_html( $args['title'] ); ?></h2>
	<p class="member-docs__meta">
		<?php
		/* translators: %d: version number. */
		echo esc_html( sprintf( __( 'Version %d', 'member-docs' ), $args['version'] ) );
		?>
	</p>

	<div class="member-docs__body">
		<?php echo wp_kses_post( $args['body'] ); ?>
	</div>

	<form class="member-docs__accept" data-endpoint="<?php echo esc_url( $args['endpoint'] ); ?>" data-version-id="<?php echo esc_attr( (string) $args['version_id'] ); ?>">
		<button type="submit" class="wp-element-button"><?php esc_html_e( 'I accept', 'member-docs' ); ?></button>
		<noscript><p><?php esc_html_e( 'JavaScript is required to accept this document.', 'member-docs' ); ?></p></noscript>
	</form>
	<p class="member-docs__status" role="status" aria-live="polite"></p>
</div>
