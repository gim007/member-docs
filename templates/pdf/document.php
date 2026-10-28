<?php
/**
 * PDF layout of an accepted document.
 *
 * @package MemberDocs
 *
 * @var array{title: string, version: int, body: string, member: string, email: string, accepted_at: string, content_hash: string, site: string} $args
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<title><?php echo esc_html( $args['title'] ); ?></title>
	<style>
		body { font-family: "DejaVu Sans", sans-serif; font-size: 10.5pt; line-height: 1.5; color: #1d2327; }
		h1 { font-size: 18pt; margin: 0 0 4pt; }
		.subtitle { color: #50575e; margin: 0 0 18pt; }
		.record { margin-top: 24pt; border-top: 1px solid #c3c4c7; padding-top: 8pt; font-size: 9pt; width: 100%; }
		.record th { text-align: left; width: 30%; color: #50575e; font-weight: normal; }
		.hash { font-family: "DejaVu Sans Mono", monospace; font-size: 8pt; }
	</style>
</head>
<body>
	<h1><?php echo esc_html( $args['title'] ); ?></h1>
	<p class="subtitle">
		<?php
		/* translators: 1: version number, 2: site name. */
		echo esc_html( sprintf( __( 'Version %1$d · %2$s', 'member-docs' ), $args['version'], $args['site'] ) );
		?>
	</p>

	<?php echo wp_kses_post( $args['body'] ); ?>

	<table class="record">
		<tr>
			<th><?php esc_html_e( 'Accepted by', 'member-docs' ); ?></th>
			<td><?php echo esc_html( $args['member'] . ' <' . $args['email'] . '>' ); ?></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Accepted at', 'member-docs' ); ?></th>
			<td><?php echo esc_html( $args['accepted_at'] ); ?></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Content hash (SHA-256)', 'member-docs' ); ?></th>
			<td class="hash"><?php echo esc_html( $args['content_hash'] ); ?></td>
		</tr>
	</table>
</body>
</html>
