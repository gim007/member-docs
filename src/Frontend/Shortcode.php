<?php
/**
 * [member_document] shortcode.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Frontend;

use MemberDocs\Repository\AcceptanceRepository;
use MemberDocs\Rest\DownloadController;
use MemberDocs\Rest\Permission;
use MemberDocs\Service\DocumentRenderer;
use MemberDocs\Service\TemplateService;
use MemberDocs\View;

/**
 * Shows a document with an "I accept" button, or the member's existing acceptance.
 *
 * Usage: [member_document slug="membership-terms"]
 */
final class Shortcode {

	public const TAG    = 'member_document';
	public const SCRIPT = 'member-docs-accept';

	/**
	 * Constructor.
	 *
	 * @param TemplateService      $templates   Template service.
	 * @param AcceptanceRepository $acceptances Acceptance repository.
	 * @param DocumentRenderer     $documents   Document renderer.
	 */
	public function __construct(
		private readonly TemplateService $templates,
		private readonly AcceptanceRepository $acceptances,
		private readonly DocumentRenderer $documents,
	) {}

	/**
	 * Registers the shortcode and the script (enqueued only when the shortcode renders).
	 */
	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_script' ) );
	}

	/**
	 * Registers the accept script without enqueueing it.
	 */
	public function register_script(): void {
		wp_register_script(
			self::SCRIPT,
			plugins_url( 'assets/js/accept.js', MEMBER_DOCS_FILE ),
			array(),
			MEMBER_DOCS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_localize_script(
			self::SCRIPT,
			'memberDocsAccept',
			array(
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'  => array(
					'saving'   => __( 'Saving your acceptance…', 'member-docs' ),
					/* translators: %s: acceptance date. */
					'accepted' => __( 'You accepted this document on %s.', 'member-docs' ),
					'download' => __( 'Download PDF', 'member-docs' ),
					'updated'  => __( 'This document has been updated. Reloading the latest version…', 'member-docs' ),
					'error'    => __( 'Your acceptance could not be saved. Please try again.', 'member-docs' ),
				),
			)
		);
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public function render( $atts ): string {
		$atts    = shortcode_atts( array( 'slug' => '' ), (array) $atts, self::TAG );
		$current = $this->templates->find_current( sanitize_title( $atts['slug'] ) );

		if ( null === $current ) {
			return '';
		}

		[ $template, $version ] = $current;

		if ( ! is_user_logged_in() ) {
			return View::render(
				'frontend/login',
				array(
					'title'     => $version->title,
					'login_url' => wp_login_url( (string) get_permalink() ),
				)
			);
		}

		$user       = wp_get_current_user();
		$acceptance = $this->acceptances->find_for( $version->id, $user->ID );

		if ( null !== $acceptance ) {
			return View::render(
				'frontend/accepted',
				array(
					'title'        => $version->title,
					'version'      => $version->version,
					'accepted_at'  => View::datetime( $acceptance->accepted_at ),
					'download_url' => null === $acceptance->pdf_path ? null : DownloadController::url( $acceptance->id ),
				)
			);
		}

		if ( ! wp_script_is( self::SCRIPT, 'registered' ) ) {
			$this->register_script();
		}
		wp_enqueue_script( self::SCRIPT );

		return View::render(
			'frontend/document',
			array(
				'title'      => $version->title,
				'version'    => $version->version,
				'version_id' => $version->id,
				'body'       => $this->documents->body( $version, $user ),
				'endpoint'   => rest_url( Permission::NAMESPACE . '/templates/' . $template->slug . '/accept' ),
			)
		);
	}
}
