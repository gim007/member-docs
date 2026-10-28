<?php
/**
 * Template REST routes.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Rest;

use MemberDocs\Domain\AcceptanceSource;
use MemberDocs\Repository\AcceptanceRepository;
use MemberDocs\Service\AcceptanceService;
use MemberDocs\Service\ClientInfo;
use MemberDocs\Service\DocumentRenderer;
use MemberDocs\Service\StaleVersionException;
use MemberDocs\Service\TemplateService;
use Exception;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * GET  /templates/{slug}/current  Current version for the logged-in member.
 * POST /templates/{slug}/accept   Accept the current version.
 */
final class TemplatesController {

	/**
	 * Constructor.
	 *
	 * @param TemplateService      $templates   Template service.
	 * @param AcceptanceService    $service     Acceptance service.
	 * @param AcceptanceRepository $acceptances Acceptance repository.
	 * @param DocumentRenderer     $documents   Document renderer.
	 * @param AcceptanceFormatter  $formatter   Acceptance formatter.
	 */
	public function __construct(
		private readonly TemplateService $templates,
		private readonly AcceptanceService $service,
		private readonly AcceptanceRepository $acceptances,
		private readonly DocumentRenderer $documents,
		private readonly AcceptanceFormatter $formatter,
	) {}

	/**
	 * Hooks route registration.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		$slug = array(
			'description' => __( 'Template slug.', 'member-docs' ),
			'type'        => 'string',
			'pattern'     => '^[a-z0-9-]+$',
			'required'    => true,
		);

		register_rest_route(
			Permission::NAMESPACE,
			'/templates/(?P<slug>[a-z0-9-]+)/current',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_current' ),
				'permission_callback' => array( Permission::class, 'logged_in' ),
				'args'                => array( 'slug' => $slug ),
			)
		);

		register_rest_route(
			Permission::NAMESPACE,
			'/templates/(?P<slug>[a-z0-9-]+)/accept',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'accept' ),
				'permission_callback' => array( Permission::class, 'logged_in' ),
				'args'                => array(
					'slug'       => $slug,
					'version_id' => array(
						'description' => __( 'ID of the version the member read. Must be the current version.', 'member-docs' ),
						'type'        => 'integer',
						'minimum'     => 1,
						'required'    => true,
					),
				),
			)
		);
	}

	/**
	 * Returns the current version, rendered for the member, and their acceptance if any.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array{slug: string}> $request
	 */
	public function get_current( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$current = $this->templates->find_current( $request['slug'] );
		if ( null === $current ) {
			return self::not_found();
		}

		[ $template, $version ] = $current;
		$user                   = wp_get_current_user();
		$acceptance             = $this->acceptances->find_for( $version->id, $user->ID );

		return new WP_REST_Response(
			array(
				'template'   => array(
					'slug'  => $template->slug,
					'title' => $template->title,
				),
				'version'    => array(
					'id'           => $version->id,
					'number'       => $version->version,
					'title'        => $version->title,
					'content'      => $this->documents->body( $version, $user, $acceptance?->accepted_at ),
					'content_hash' => $version->content_hash,
					'published_at' => $version->published_at->format( DATE_ATOM ),
				),
				'acceptance' => null === $acceptance ? null : $this->formatter->format( $acceptance ),
			)
		);
	}

	/**
	 * Accepts the current version for the logged-in member.
	 *
	 * Responds 201 for a new acceptance, 200 when it already existed and 409 when
	 * the submitted version is no longer current (e.g. a stale browser tab).
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array{slug: string, version_id: int}> $request
	 */
	public function accept( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$current = $this->templates->find_current( $request['slug'] );
		if ( null === $current ) {
			return self::not_found();
		}

		$version = $current[1];
		if ( $version->id !== (int) $request['version_id'] ) {
			return self::stale( $version->id );
		}

		$user     = wp_get_current_user();
		$existing = $this->acceptances->find_for( $version->id, $user->ID );
		if ( null !== $existing ) {
			return new WP_REST_Response( $this->formatter->format( $existing ), 200 );
		}

		try {
			$acceptance = $this->service->accept( $version, $user, AcceptanceSource::Web, ClientInfo::from_server( $_SERVER ) );
		} catch ( StaleVersionException ) {
			return self::stale( $version->id );
		} catch ( Exception ) {
			return new WP_Error( 'member_docs_accept_failed', __( 'Your acceptance could not be recorded. Please try again.', 'member-docs' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( $this->formatter->format( $acceptance ), 201 );
	}

	/**
	 * 404 for unknown or inactive templates.
	 */
	private static function not_found(): WP_Error {
		return new WP_Error( 'member_docs_not_found', __( 'Document not found.', 'member-docs' ), array( 'status' => 404 ) );
	}

	/**
	 * 409 for outdated versions.
	 *
	 * @param int $current_version_id ID of the version that is current now.
	 */
	private static function stale( int $current_version_id ): WP_Error {
		return new WP_Error(
			'member_docs_stale_version',
			__( 'This document has been updated. Please review the latest version.', 'member-docs' ),
			array(
				'status'             => 409,
				'current_version_id' => $current_version_id,
			)
		);
	}
}
