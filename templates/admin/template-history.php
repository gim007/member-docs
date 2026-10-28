<?php
/**
 * Version history screen.
 *
 * @package MemberDocs
 *
 * @var array{
 *     template: \MemberDocs\Domain\Template,
 *     versions: list<\MemberDocs\Domain\TemplateVersion>,
 *     counts: array<int, int>,
 *     current: \MemberDocs\Domain\TemplateVersion|null
 * } $args
 */

use MemberDocs\Admin\AcceptanceLogPage;
use MemberDocs\Admin\TemplatesPage;
use MemberDocs\View;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1>
		<?php
		/* translators: %s: document title. */
		echo esc_html( sprintf( __( 'Version history: %s', 'member-docs' ), $args['template']->title ) );
		?>
	</h1>
	<p><a href="
	<?php
	echo esc_url(
		TemplatesPage::url(
			array(
				'action' => 'edit',
				'id'     => $args['template']->id,
			)
		)
	);
	?>
	">&larr; <?php esc_html_e( 'Back to the document', 'member-docs' ); ?></a></p>

	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Version', 'member-docs' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Title', 'member-docs' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Published', 'member-docs' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Published by', 'member-docs' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Content hash', 'member-docs' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Acceptances', 'member-docs' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $args['versions'] as $member_docs_version ) : ?>
				<?php $member_docs_publisher = get_userdata( $member_docs_version->published_by ); ?>
				<tr>
					<td>
						<a href="
						<?php
						echo esc_url(
							TemplatesPage::url(
								array(
									'action' => 'version',
									'id'     => $member_docs_version->id,
								)
							)
						);
						?>
									">
							<?php echo esc_html( (string) $member_docs_version->version ); ?>
						</a>
						<?php if ( $member_docs_version->id === $args['current']?->id ) : ?>
							<span class="description">(<?php esc_html_e( 'current', 'member-docs' ); ?>)</span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $member_docs_version->title ); ?></td>
					<td><?php echo esc_html( View::datetime( $member_docs_version->published_at ) ); ?></td>
					<td><?php echo esc_html( false === $member_docs_publisher ? __( 'System', 'member-docs' ) : $member_docs_publisher->display_name ); ?></td>
					<td><code title="<?php echo esc_attr( $member_docs_version->content_hash ); ?>"><?php echo esc_html( substr( $member_docs_version->content_hash, 0, 12 ) ); ?>&hellip;</code></td>
					<td>
						<a href="<?php echo esc_url( AcceptanceLogPage::url( array( 'version_id' => $member_docs_version->id ) ) ); ?>">
							<?php echo esc_html( number_format_i18n( $args['counts'][ $member_docs_version->id ] ?? 0 ) ); ?>
						</a>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
