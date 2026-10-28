<?php
/**
 * Checkout document and acceptance checkbox.
 *
 * @package MemberDocs
 *
 * @var array{version: \MemberDocs\Domain\TemplateVersion, body: string, field: string, hidden: string, checked: bool} $args
 */

defined( 'ABSPATH' ) || exit;

$member_docs_version = $args['version'];
?>
<div class="member-docs-checkout">
	<details class="member-docs-checkout__document">
		<summary>
			<?php
			/* translators: 1: document title, 2: version number. */
			echo esc_html( sprintf( __( 'Read the %1$s (version %2$d)', 'member-docs' ), $member_docs_version->title, $member_docs_version->version ) );
			?>
		</summary>
		<div class="member-docs-checkout__body">
			<?php echo wp_kses_post( $args['body'] ); ?>
		</div>
	</details>

	<p class="form-row validate-required">
		<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
			<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="<?php echo esc_attr( $args['field'] ); ?>" id="<?php echo esc_attr( $args['field'] ); ?>" value="1" <?php checked( $args['checked'] ); ?>>
			<span>
				<?php
				/* translators: %s: document title. */
				echo esc_html( sprintf( __( 'I have read and accept the %s', 'member-docs' ), $member_docs_version->title ) );
				?>
			</span>&nbsp;<abbr class="required" title="<?php esc_attr_e( 'required', 'member-docs' ); ?>">*</abbr>
		</label>
		<input type="hidden" name="<?php echo esc_attr( $args['hidden'] ); ?>" value="<?php echo esc_attr( (string) $member_docs_version->id ); ?>">
	</p>
</div>
