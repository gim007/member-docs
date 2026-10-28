<?php
/**
 * Acceptance log screen.
 *
 * @package MemberDocs
 *
 * @var array{
 *     table: \MemberDocs\Admin\AcceptanceLogListTable,
 *     query: array<string, string>,
 *     templates: list<\MemberDocs\Domain\Template>,
 *     export_url: string,
 *     page: string
 * } $args
 */

defined( 'ABSPATH' ) || exit;

$member_docs_query = $args['query'];
?>
<div class="wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Acceptance Log', 'member-docs' ); ?></h1>
	<a href="<?php echo esc_url( $args['export_url'] ); ?>" class="page-title-action"><?php esc_html_e( 'Export CSV', 'member-docs' ); ?></a>
	<hr class="wp-header-end">

	<form method="get" class="member-docs-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( $args['page'] ); ?>">
		<p class="search-box" style="float: none; display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; margin-bottom: 1em;">
			<label>
				<?php esc_html_e( 'Document', 'member-docs' ); ?><br>
				<select name="template_id">
					<option value=""><?php esc_html_e( 'All documents', 'member-docs' ); ?></option>
					<?php foreach ( $args['templates'] as $member_docs_template ) : ?>
						<option value="<?php echo esc_attr( (string) $member_docs_template->id ); ?>" <?php selected( $member_docs_query['template_id'], (string) $member_docs_template->id ); ?>><?php echo esc_html( $member_docs_template->title ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<?php esc_html_e( 'Version ID', 'member-docs' ); ?><br>
				<input type="number" min="1" name="version_id" class="small-text" value="<?php echo esc_attr( $member_docs_query['version_id'] ); ?>">
			</label>
			<label>
				<?php esc_html_e( 'User (ID, email or login)', 'member-docs' ); ?><br>
				<input type="text" name="user" value="<?php echo esc_attr( $member_docs_query['user'] ); ?>">
			</label>
			<label>
				<?php esc_html_e( 'From', 'member-docs' ); ?><br>
				<input type="date" name="from" value="<?php echo esc_attr( $member_docs_query['from'] ); ?>">
			</label>
			<label>
				<?php esc_html_e( 'To', 'member-docs' ); ?><br>
				<input type="date" name="to" value="<?php echo esc_attr( $member_docs_query['to'] ); ?>">
			</label>
			<?php submit_button( __( 'Filter', 'member-docs' ), '', '', false ); ?>
		</p>
	</form>

	<?php $args['table']->display(); ?>
</div>
