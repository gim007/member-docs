<?php
/**
 * WooCommerce classic checkout integration.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Integration\WooCommerce;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Exception;
use MemberDocs\Domain\AcceptanceSource;
use MemberDocs\Domain\TemplateStatus;
use MemberDocs\Domain\TemplateVersion;
use MemberDocs\Repository\AcceptanceRepository;
use MemberDocs\Repository\TemplateRepository;
use MemberDocs\Service\AcceptanceService;
use MemberDocs\Service\ClientInfo;
use MemberDocs\Service\DocumentRenderer;
use MemberDocs\Service\StaleVersionException;
use MemberDocs\Service\TemplateService;
use MemberDocs\View;
use WC_Order;
use WP_User;

/**
 * Requires customers to accept a document at checkout and records the acceptance with the order.
 *
 * Classic (shortcode) checkout only; the block checkout would need a Store API
 * extension and a checkout block integration.
 */
final class CheckoutIntegration {

	public const OPTION        = 'member_docs_checkout_template';
	public const FIELD         = 'member_docs_accept';
	public const VERSION_FIELD = 'member_docs_version_id';
	public const ORDER_META    = '_member_docs_version_id';

	/**
	 * Constructor.
	 *
	 * @param TemplateService      $service     Template service.
	 * @param TemplateRepository   $templates   Template repository.
	 * @param AcceptanceRepository $acceptances Acceptance repository.
	 * @param AcceptanceService    $accept      Acceptance service.
	 * @param DocumentRenderer     $documents   Document renderer.
	 */
	public function __construct(
		private readonly TemplateService $service,
		private readonly TemplateRepository $templates,
		private readonly AcceptanceRepository $acceptances,
		private readonly AcceptanceService $accept,
		private readonly DocumentRenderer $documents,
	) {}

	/**
	 * Declares compatibility with High-Performance Order Storage. Runs on before_woocommerce_init.
	 */
	public static function declare_compatibility(): void {
		if ( class_exists( FeaturesUtil::class ) ) {
			FeaturesUtil::declare_compatibility( 'custom_order_tables', MEMBER_DOCS_FILE, true );
		}
	}

	/**
	 * Hooks into WooCommerce.
	 */
	public function register(): void {
		add_filter( 'woocommerce_get_settings_advanced', array( $this, 'add_setting' ), 10, 2 );
		add_action( 'woocommerce_checkout_after_customer_details', array( $this, 'render_checkbox' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate' ) );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'record' ), 10, 3 );
	}

	/**
	 * Adds the document selector next to the terms page setting (Settings → Advanced → Page setup).
	 *
	 * @param array<int, array<string, mixed>> $settings Settings of the section.
	 * @param string                           $section  Current section, '' for page setup.
	 * @return array<int, array<string, mixed>>
	 */
	public function add_setting( array $settings, string $section ): array {
		if ( '' !== $section ) {
			return $settings;
		}

		$options = array( '' => __( 'None', 'member-docs' ) );
		foreach ( $this->templates->all( TemplateStatus::Active ) as $template ) {
			$options[ $template->slug ] = $template->title;
		}

		$setting = array(
			'title'    => __( 'Member document', 'member-docs' ),
			'desc'     => __( 'Customers must accept the current version of this document at checkout. Classic checkout only.', 'member-docs' ),
			'id'       => self::OPTION,
			'type'     => 'select',
			'class'    => 'wc-enhanced-select',
			'default'  => '',
			'options'  => $options,
			'desc_tip' => true,
			'autoload' => false,
		);

		$position = array_search( 'woocommerce_terms_page_id', array_column( $settings, 'id' ), true );
		array_splice( $settings, false === $position ? count( $settings ) : $position + 1, 0, array( $setting ) );

		return $settings;
	}

	/**
	 * The version the current customer still has to accept, or null when nothing is required.
	 */
	public function required_version(): ?TemplateVersion {
		$current = $this->service->find_current( (string) get_option( self::OPTION, '' ) );
		if ( null === $current ) {
			return null;
		}

		$version = $current[1];
		$user_id = get_current_user_id();
		if ( 0 !== $user_id && null !== $this->acceptances->find_for( $version->id, $user_id ) ) {
			return null;
		}

		return $version;
	}

	/**
	 * Prints the document and the required checkbox.
	 */
	public function render_checkbox(): void {
		$version = $this->required_version();
		if ( null === $version ) {
			return;
		}

		View::display(
			'woocommerce/checkbox',
			array(
				'version' => $version,
				'body'    => $this->documents->body( $version, wp_get_current_user() ),
				'field'   => self::FIELD,
				'hidden'  => self::VERSION_FIELD,
				'checked' => ! empty( $_POST[ self::FIELD ] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only restores the checkbox state.
			)
		);
	}

	/**
	 * Rejects the checkout when the document was not accepted or has changed meanwhile.
	 */
	public function validate(): void {
		$version = $this->required_version();
		if ( null === $version ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce before this hook.
		$accepted   = ! empty( $_POST[ self::FIELD ] );
		$version_id = isset( $_POST[ self::VERSION_FIELD ] ) ? absint( $_POST[ self::VERSION_FIELD ] ) : 0;
		// phpcs:enable

		if ( ! $accepted ) {
			/* translators: %s: document title. */
			wc_add_notice( esc_html( sprintf( __( 'Please read and accept the %s to place your order.', 'member-docs' ), $version->title ) ), 'error' );
		} elseif ( $version_id !== $version->id ) {
			/* translators: %s: document title. */
			wc_add_notice( esc_html( sprintf( __( 'The %s has been updated. Please review the latest version and accept it again.', 'member-docs' ), $version->title ) ), 'error' );
		}
	}

	/**
	 * Records the acceptance for the order's customer and stores the version on the order.
	 *
	 * Guests have no member account, so for them only the order meta is stored.
	 *
	 * @param int                  $order_id    Order ID.
	 * @param array<string, mixed> $posted_data Checkout data.
	 * @param WC_Order             $order       Order.
	 */
	public function record( int $order_id, array $posted_data, WC_Order $order ): void {
		$current = $this->service->find_current( (string) get_option( self::OPTION, '' ) );
		if ( null === $current ) {
			return;
		}

		$version = $current[1];
		$user    = get_userdata( $order->get_customer_id() );
		/* translators: 1: document title, 2: version number. */
		$note = sprintf( __( 'Customer accepted %1$s (version %2$d) at checkout.', 'member-docs' ), $version->title, $version->version );

		if ( $user instanceof WP_User ) {
			try {
				$this->accept->accept(
					$version,
					$user,
					AcceptanceSource::Checkout,
					new ClientInfo( $order->get_customer_ip_address(), $order->get_customer_user_agent() ),
					$order->get_id()
				);
			} catch ( StaleVersionException ) {
				return;
			} catch ( Exception $error ) {
				// The order already exists; never break checkout over the audit record. Leave a trail instead.
				/* translators: %s: error message. */
				$note .= ' ' . sprintf( __( 'The acceptance could not be added to the member log: %s', 'member-docs' ), $error->getMessage() );
			}
		}

		$order->update_meta_data( self::ORDER_META, (string) $version->id );
		$order->add_order_note( $note );
		$order->save();
	}
}
