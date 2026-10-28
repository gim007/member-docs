<?php
/**
 * Read-only version screen.
 *
 * @package MemberDocs
 *
 * @var array{template: \MemberDocs\Domain\Template, version: \MemberDocs\Domain\TemplateVersion, intact: bool} $args
 */

use MemberDocs\Admin\TemplatesPage;
use MemberDocs\View;

defined( 'ABSPATH' ) || exit;

$member_docs_version = $args['version'];
?>
<div class="wrap">
	<h1>
		<?php
		/* translators: 1: document title, 2: version number. */
		echo esc_html( sprintf( __( '%1$s — version %2$d', 'member-docs' ), $member_docs_version->title, $member_docs_version->version ) );
		?>
	</h1>
	<p><a href="
	<?php
	echo esc_url(
		TemplatesPage::url(
			array(
				'action' => 'history',
				'id'     => $args['template']->id,
			)
		)
	);
	?>
	">&larr; <?php esc_html_e( 'Version history', 'member-docs' ); ?></a></p>

	<?php if ( ! $args['intact'] ) : ?>
		<div class="notice notice-error inline"><p><?php esc_html_e( 'The stored content no longer matches its hash. This version may have been modified directly in the database.', 'member-docs' ); ?></p></div>
	<?php endif; ?>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Published', 'member-docs' ); ?></th>
			<td><?php echo esc_html( View::datetime( $member_docs_version->published_at ) ); ?></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Content hash (SHA-256)', 'member-docs' ); ?></th>
			<td><code><?php echo esc_html( $member_docs_version->content_hash ); ?></code></td>
		</tr>
	</table>

	<div class="card" style="max-width: 50rem;">
		<?php echo wp_kses_post( $member_docs_version->body ); ?>
	</div>
</div>
