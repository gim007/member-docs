<?php
/**
 * Shortcode output for visitors who are not logged in.
 *
 * @package MemberDocs
 *
 * @var array{title: string, login_url: string} $args
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="member-docs member-docs--login">
	<p>
		<?php
		printf(
			/* translators: 1: opening link tag, 2: closing link tag, 3: document title. */
			esc_html__( 'Please %1$slog in%2$s to read and accept "%3$s".', 'member-docs' ),
			'<a href="' . esc_url( $args['login_url'] ) . '">',
			'</a>',
			esc_html( $args['title'] )
		);
		?>
	</p>
</div>
