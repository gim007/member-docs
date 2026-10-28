<?php
/**
 * Templates list screen.
 *
 * @package MemberDocs
 *
 * @var array{table: \MemberDocs\Admin\TemplatesListTable} $args
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Member Documents', 'member-docs' ); ?></h1>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . MemberDocs\Admin\TemplatesPage::NEW_SLUG ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'member-docs' ); ?></a>
	<hr class="wp-header-end">

	<p><?php esc_html_e( 'Each save that changes a document publishes a new, immutable version. Members are asked to accept the latest version of active documents.', 'member-docs' ); ?></p>

	<?php $args['table']->display(); ?>
</div>
