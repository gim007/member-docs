<?php
/**
 * Service wiring.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs;

use MemberDocs\Admin\AcceptanceLogPage;
use MemberDocs\Admin\CsvExporter;
use MemberDocs\Admin\Menu;
use MemberDocs\Admin\Notices;
use MemberDocs\Admin\TemplatesPage;
use MemberDocs\Cli\Commands;
use MemberDocs\Cli\Migrator;
use MemberDocs\Cli\Seeder;
use MemberDocs\Cli\Verifier;
use MemberDocs\Frontend\Shortcode;
use MemberDocs\Integration\WooCommerce\CheckoutIntegration;
use MemberDocs\Mail\AcceptanceMailer;
use MemberDocs\Pdf\DompdfRenderer;
use MemberDocs\Pdf\PrivateStorage;
use MemberDocs\Repository\AcceptanceRepository;
use MemberDocs\Repository\TemplateRepository;
use MemberDocs\Repository\VersionRepository;
use MemberDocs\Rest\AcceptanceFormatter;
use MemberDocs\Rest\AcceptancesController;
use MemberDocs\Rest\DownloadController;
use MemberDocs\Rest\TemplatesController;
use MemberDocs\Service\AcceptanceService;
use MemberDocs\Service\DocumentRenderer;
use MemberDocs\Service\PlaceholderRenderer;
use MemberDocs\Service\TemplateService;

/**
 * Creates the plugin services and registers their hooks.
 *
 * Wiring is manual on purpose: this file is the single place that shows how
 * everything fits together.
 */
final class Plugin {

	public readonly TemplateRepository $templates;
	public readonly VersionRepository $versions;
	public readonly AcceptanceRepository $acceptances;
	public readonly PrivateStorage $storage;
	public readonly DocumentRenderer $documents;
	public readonly TemplateService $template_service;
	public readonly AcceptanceService $acceptance_service;

	/**
	 * Builds the object graph. No hooks are registered here.
	 */
	public function __construct() {
		global $wpdb;

		$this->templates        = new TemplateRepository( $wpdb );
		$this->versions         = new VersionRepository( $wpdb );
		$this->acceptances      = new AcceptanceRepository( $wpdb );
		$this->storage          = PrivateStorage::in_uploads();
		$this->documents        = new DocumentRenderer( new PlaceholderRenderer( self::debug_logger() ) );
		$this->template_service = new TemplateService( $this->templates, $this->versions );

		$this->acceptance_service = new AcceptanceService(
			$this->templates,
			$this->versions,
			$this->acceptances,
			$this->documents,
			new DompdfRenderer(),
			$this->storage
		);
	}

	/**
	 * Registers all hooks. Called on plugins_loaded.
	 */
	public function register_hooks(): void {
		Installer::maybe_upgrade();

		( new AcceptanceMailer( $this->versions, $this->storage ) )->register();

		$formatter = new AcceptanceFormatter( $this->templates, $this->versions );
		( new TemplatesController( $this->template_service, $this->acceptance_service, $this->acceptances, $this->documents, $formatter ) )->register();
		( new AcceptancesController( $this->acceptances, $formatter ) )->register();
		( new DownloadController( $this->acceptances, $this->versions, $this->storage ) )->register();

		( new Shortcode( $this->template_service, $this->acceptances, $this->documents ) )->register();

		if ( is_admin() ) {
			$this->admin_menu()->register();
		}

		add_action( 'before_woocommerce_init', array( CheckoutIntegration::class, 'declare_compatibility' ) );
		if ( class_exists( 'WooCommerce' ) ) {
			$this->checkout_integration()->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'member-docs', $this->cli_commands() );
		}
	}

	/**
	 * Admin screens.
	 */
	public function admin_menu(): Menu {
		$notices = new Notices();

		return new Menu(
			new TemplatesPage( $this->template_service, $this->templates, $this->versions, $this->acceptances, $notices ),
			new AcceptanceLogPage( $this->acceptances, $this->templates, $this->versions, new CsvExporter( $this->acceptances, $this->templates, $this->versions ) ),
			$notices
		);
	}

	/**
	 * WooCommerce checkout integration.
	 */
	public function checkout_integration(): CheckoutIntegration {
		return new CheckoutIntegration( $this->template_service, $this->templates, $this->acceptances, $this->acceptance_service, $this->documents );
	}

	/**
	 * WP-CLI commands.
	 */
	public function cli_commands(): Commands {
		return new Commands(
			$this->migrator(),
			new Verifier( $this->acceptances, $this->versions, $this->storage ),
			new Seeder( $this->template_service, $this->templates, $this->versions, $this->acceptance_service )
		);
	}

	/**
	 * Legacy acceptance importer.
	 */
	public function migrator(): Migrator {
		return new Migrator( $this->templates, $this->versions, $this->acceptances, $this->acceptance_service );
	}

	/**
	 * Logger that writes to the PHP error log while WP_DEBUG is enabled.
	 *
	 * @return \Closure(string): void
	 */
	private static function debug_logger(): \Closure {
		return static function ( string $message ): void {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug-only logging.
			}
		};
	}
}
