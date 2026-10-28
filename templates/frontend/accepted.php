<?php
/**
 * Shortcode output when the member already accepted the current version.
 *
 * @package MemberDocs
 *
 * @var array{title: string, version: int, accepted_at: string, download_url: string|null} $args
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="member-docs member-docs--accepted">
	<p>
		<?php
		/* translators: 1: document title, 2: version number, 3: acceptance date. */
		echo esc_html( sprintf( __( 'You accepted "%1$s" (version %2$d) on %3$s.', 'member-docs' ), $args['title'], $args['version'], $args['accepted_at'] ) );
		?>
	</p>
	<?php if ( null !== $args['download_url'] ) : ?>
		<p><a href="<?php echo esc_url( $args['download_url'] ); ?>"><?php esc_html_e( 'Download PDF', 'member-docs' ); ?></a></p>
	<?php endif; ?>
</div>
