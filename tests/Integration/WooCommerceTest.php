<?php
/**
 * WooCommerce checkout integration tests. Skipped when WooCommerce is not installed.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Tests\Integration;

use Automattic\WooCommerce\Internal\Features\FeaturesController;
use MemberDocs\Domain\AcceptanceSource;
use MemberDocs\Domain\TemplateStatus;
use MemberDocs\Domain\TemplateVersion;
use MemberDocs\Integration\WooCommerce\CheckoutIntegration;
use WC_Order;

/**
 * @covers \MemberDocs\Integration\WooCommerce\CheckoutIntegration
 */
final class WooCommerceTest extends TestCase {

	private CheckoutIntegration $integration;
	private TemplateVersion $version;

	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not installed.' );
		}

		WC()->initialize_session();
		wc_clear_notices();

		$this->integration = $this->plugin()->checkout_integration();
		$this->version     = $this->create_template()->published;
		update_option( CheckoutIntegration::OPTION, 'membership-terms' );
	}

	public function tear_down(): void {
		$_POST = array();
		parent::tear_down();
	}

	private function checkbox_html(): string {
		ob_start();
		$this->integration->render_checkbox();

		return (string) ob_get_clean();
	}

	private function order_for( int $customer_id ): WC_Order {
		$order = wc_create_order( array( 'customer_id' => $customer_id ) );
		$order->set_customer_ip_address( '203.0.113.50' );
		$order->set_customer_user_agent( 'Checkout Browser' );
		$order->save();

		return $order;
	}

	public function test_declares_hpos_compatibility(): void {
		$compatible = wc_get_container()->get( FeaturesController::class )->get_compatible_plugins_for_feature( 'custom_order_tables' );

		$this->assertContains( plugin_basename( MEMBER_DOCS_FILE ), $compatible['compatible'] );
	}

	public function test_setting_is_added_after_the_terms_page(): void {
		$settings = apply_filters(
			'woocommerce_get_settings_advanced',
			array(
				array( 'id' => 'woocommerce_cart_page_id' ),
				array( 'id' => 'woocommerce_terms_page_id' ),
				array( 'id' => 'woocommerce_checkout_pay_endpoint' ),
			),
			''
		);
		$ids      = array_column( $settings, 'id' );

		$this->assertSame( array_search( 'woocommerce_terms_page_id', $ids, true ) + 1, array_search( CheckoutIntegration::OPTION, $ids, true ) );
		$this->assertArrayHasKey( 'membership-terms', $settings[2]['options'] );
	}

	public function test_checkbox_shows_the_current_version(): void {
		wp_set_current_user( $this->member()->ID );

		$html = $this->checkbox_html();

		$this->assertStringContainsString( 'name="' . CheckoutIntegration::FIELD . '"', $html );
		$this->assertStringContainsString( 'value="' . $this->version->id . '"', $html );
		$this->assertStringContainsString( 'Hello Jane Member.', $html );
	}

	public function test_checkbox_is_required(): void {
		wp_set_current_user( $this->member()->ID );

		$this->integration->validate();

		$this->assertSame( 1, wc_notice_count( 'error' ) );
		$this->assertStringContainsString( 'Please read and accept the Membership Terms', wc_get_notices( 'error' )[0]['notice'] );
	}

	public function test_checked_box_for_the_current_version_passes(): void {
		wp_set_current_user( $this->member()->ID );
		$_POST = array(
			CheckoutIntegration::FIELD         => '1',
			CheckoutIntegration::VERSION_FIELD => (string) $this->version->id,
		);

		$this->integration->validate();

		$this->assertSame( 0, wc_notice_count( 'error' ) );
	}

	public function test_checked_box_for_an_outdated_version_is_rejected(): void {
		$this->plugin()->template_service->update( $this->version->template_id, 'Membership Terms', 'membership-terms', TemplateStatus::Active, '<p>New terms</p>', 1 );
		$_POST = array(
			CheckoutIntegration::FIELD         => '1',
			CheckoutIntegration::VERSION_FIELD => (string) $this->version->id,
		);

		$this->integration->validate();

		$this->assertStringContainsString( 'has been updated', wc_get_notices( 'error' )[0]['notice'] );
	}

	public function test_order_records_a_checkout_acceptance_and_order_meta(): void {
		$customer = $this->member( 'customer' );
		$order    = $this->order_for( $customer->ID );

		$this->integration->record( $order->get_id(), array(), $order );

		$acceptance = $this->plugin()->acceptances->find_for( $this->version->id, $customer->ID );
		$this->assertNotNull( $acceptance );
		$this->assertSame( AcceptanceSource::Checkout, $acceptance->source );
		$this->assertSame( $order->get_id(), $acceptance->order_id );
		$this->assertSame( '203.0.113.50', $acceptance->ip_address );
		$this->assertSame( (string) $this->version->id, wc_get_order( $order->get_id() )->get_meta( CheckoutIntegration::ORDER_META ) );
	}

	public function test_a_failed_acceptance_never_breaks_the_order(): void {
		global $wpdb;

		$customer = $this->member( 'customer' );
		$order    = $this->order_for( $customer->ID );
		$table    = $wpdb->prefix . 'md_acceptances';
		add_filter(
			'query',
			static fn( string $query ): string => str_starts_with( $query, "INSERT INTO `{$table}`" ) ? 'INSERT INTO `md_missing_table` (id) VALUES (1)' : $query
		);
		$suppress = $wpdb->suppress_errors( true );

		$this->integration->record( $order->get_id(), array(), $order );
		$wpdb->suppress_errors( $suppress );

		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertStringContainsString( 'could not be added to the member log', $notes[0]->content );
		$this->assertSame( (string) $this->version->id, wc_get_order( $order->get_id() )->get_meta( CheckoutIntegration::ORDER_META ) );
	}

	public function test_guest_orders_store_the_version_on_the_order_only(): void {
		$order = $this->order_for( 0 );

		$this->integration->record( $order->get_id(), array(), $order );

		$this->assertSame( (string) $this->version->id, wc_get_order( $order->get_id() )->get_meta( CheckoutIntegration::ORDER_META ) );
		$this->assertSame( 0, $this->plugin()->acceptances->count( new \MemberDocs\Repository\AcceptanceFilter() ) );
	}

	public function test_customers_who_already_accepted_are_not_asked_again(): void {
		$customer = $this->member( 'customer' );
		$this->accept( $this->version, $customer );
		wp_set_current_user( $customer->ID );

		$this->assertSame( '', $this->checkbox_html() );
		$this->integration->validate();
		$this->assertSame( 0, wc_notice_count( 'error' ) );
	}

	public function test_nothing_is_required_without_a_configured_document(): void {
		update_option( CheckoutIntegration::OPTION, '' );
		wp_set_current_user( $this->member()->ID );

		$this->assertSame( '', $this->checkbox_html() );
		$this->integration->validate();
		$this->assertSame( 0, wc_notice_count( 'error' ) );
	}
}
