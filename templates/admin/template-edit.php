<?php
/**
 * Template create/edit screen.
 *
 * @package MemberDocs
 *
 * @var array{
 *     template: \MemberDocs\Domain\Template|null,
 *     latest: \MemberDocs\Domain\TemplateVersion|null,
 *     values: array{title: string, slug: string, status: string, body: string},
 *     statuses: list<\MemberDocs\Domain\TemplateStatus>,
 *     placeholders: list<string>,
 *     action: string
 * } $args
 */

use MemberDocs\Admin\TemplatesPage;
use MemberDocs\View;

defined( 'ABSPATH' ) || exit;

$member_docs_template = $args['template'];
$member_docs_latest   = $args['latest'];
$member_docs_values   = $args['values'];
?>
<div class="wrap">
	<h1>
		<?php
		echo null === $member_docs_template
			? esc_html__( 'Add Document', 'member-docs' )
			: esc_html__( 'Edit Document', 'member-docs' );
		?>
	</h1>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( $args['action'] ); ?>">
		<input type="hidden" name="template_id" value="<?php echo esc_attr( (string) ( $member_docs_template->id ?? 0 ) ); ?>">
		<?php wp_nonce_field( $args['action'] ); ?>

		<div id="poststuff">
			<div id="post-body" class="metabox-holder columns-2">
				<div id="post-body-content">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="member-docs-title"><?php esc_html_e( 'Title', 'member-docs' ); ?></label></th>
							<td><input type="text" id="member-docs-title" name="title" class="large-text" required value="<?php echo esc_attr( $member_docs_values['title'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="member-docs-slug"><?php esc_html_e( 'Slug', 'member-docs' ); ?></label></th>
							<td>
								<input type="text" id="member-docs-slug" name="slug" class="regular-text" pattern="[a-z0-9\-]*" value="<?php echo esc_attr( $member_docs_values['slug'] ); ?>" aria-describedby="member-docs-slug-help">
								<p class="description" id="member-docs-slug-help"><?php esc_html_e( 'Used in the shortcode. Leave empty to derive it from the title.', 'member-docs' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="member-docs-status"><?php esc_html_e( 'Status', 'member-docs' ); ?></label></th>
							<td>
								<select id="member-docs-status" name="status">
									<?php foreach ( $args['statuses'] as $member_docs_status ) : ?>
										<option value="<?php echo esc_attr( $member_docs_status->value ); ?>" <?php selected( $member_docs_values['status'], $member_docs_status->value ); ?>><?php echo esc_html( $member_docs_status->label() ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					</table>

					<h2><label for="member_docs_body"><?php esc_html_e( 'Document text', 'member-docs' ); ?></label></h2>
					<?php
					wp_editor(
						$member_docs_values['body'],
						'member_docs_body',
						array(
							'textarea_name' => 'body',
							'textarea_rows' => 20,
							'media_buttons' => false,
						)
					);
					?>
				</div>

				<div id="postbox-container-1" class="postbox-container">
					<div class="postbox">
						<h2 class="hndle"><?php esc_html_e( 'Publish', 'member-docs' ); ?></h2>
						<div class="inside">
							<?php if ( null !== $member_docs_latest ) : ?>
								<p>
									<?php
									/* translators: 1: version number, 2: publication date. */
									echo esc_html( sprintf( __( 'Latest version: %1$d, published %2$s.', 'member-docs' ), $member_docs_latest->version, View::datetime( $member_docs_latest->published_at ) ) );
									?>
								</p>
								<p>
									<a href="
									<?php
									echo esc_url(
										TemplatesPage::url(
											array(
												'action' => 'history',
												'id'     => (int) $member_docs_template?->id,
											)
										)
									);
									?>
												"><?php esc_html_e( 'Version history', 'member-docs' ); ?></a>
								</p>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Saving changed title or text publishes a new version. Published versions are never modified.', 'member-docs' ); ?></p>
							<?php submit_button( __( 'Save', 'member-docs' ), 'primary large', 'submit', false ); ?>
						</div>
					</div>

					<div class="postbox">
						<h2 class="hndle"><?php esc_html_e( 'Placeholders', 'member-docs' ); ?></h2>
						<div class="inside">
							<ul>
								<?php foreach ( $args['placeholders'] as $member_docs_placeholder ) : ?>
									<li><code>{{<?php echo esc_html( $member_docs_placeholder ); ?>}}</code></li>
								<?php endforeach; ?>
							</ul>
							<?php if ( null !== $member_docs_template ) : ?>
								<p><?php esc_html_e( 'Shortcode:', 'member-docs' ); ?><br><code>[member_document slug="<?php echo esc_html( $member_docs_template->slug ); ?>"]</code></p>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>
		</div>
	</form>
</div>
