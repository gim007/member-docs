<?php
/**
 * PDF download route.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Rest;

use MemberDocs\Capabilities;
use MemberDocs\Pdf\PrivateStorage;
use MemberDocs\Repository\AcceptanceRepository;
use MemberDocs\Repository\VersionRepository;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Server;

/**
 * GET /acceptances/{id}/pdf  Streams the acceptance PDF to its owner or to auditors.
 */
final class DownloadController {

	/**
	 * Constructor.
	 *
	 * @param AcceptanceRepository $acceptances Acceptance repository.
	 * @param VersionRepository    $versions    Version repository.
	 * @param PrivateStorage       $storage     Private file storage.
	 */
	public function __construct(
		private readonly AcceptanceRepository $acceptances,
		private readonly VersionRepository $versions,
		private readonly PrivateStorage $storage,
	) {}

	/**
	 * Download URL for an acceptance. Includes a REST nonce so a plain link works with cookie auth.
	 *
	 * @param int $acceptance_id Acceptance ID.
	 */
	public static function url( int $acceptance_id ): string {
		return add_query_arg(
			'_wpnonce',
			wp_create_nonce( 'wp_rest' ),
			rest_url( Permission::NAMESPACE . '/acceptances/' . $acceptance_id . '/pdf' )
		);
	}

	/**
	 * Hooks route registration and file streaming.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( self::class, 'serve_file' ), 10, 2 );
	}

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			Permission::NAMESPACE,
			'/acceptances/(?P<id>\d+)/pdf',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'download' ),
				'permission_callback' => array( $this, 'can_download' ),
				'args'                => array(
					'id' => array(
						'description' => __( 'Acceptance ID.', 'member-docs' ),
						'type'        => 'integer',
						'minimum'     => 1,
						'required'    => true,
					),
				),
			)
		);
	}

	/**
	 * Allows the owner of the acceptance and users who may view the acceptance log.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array{id: int}> $request
	 * @return true|WP_Error
	 */
	public function can_download( WP_REST_Request $request ): bool|WP_Error {
		$logged_in = Permission::logged_in();
		if ( true !== $logged_in ) {
			return $logged_in;
		}

		$acceptance = $this->acceptances->find( (int) $request['id'] );
		if ( null !== $acceptance && ( get_current_user_id() === $acceptance->user_id || current_user_can( Capabilities::VIEW_ACCEPTANCES ) ) ) {
			return true;
		}

		// Same answer for "missing" and "not yours", so IDs of other members' records are not confirmed.
		return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to download this document.', 'member-docs' ), array( 'status' => 403 ) );
	}

	/**
	 * Responds with the PDF file.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array{id: int}> $request
	 */
	public function download( WP_REST_Request $request ): FileResponse|WP_Error {
		$acceptance = $this->acceptances->find( (int) $request['id'] );

		if ( null === $acceptance || null === $acceptance->pdf_path || ! $this->storage->exists( $acceptance->pdf_path ) ) {
			return new WP_Error( 'member_docs_pdf_missing', __( 'No PDF is available for this acceptance.', 'member-docs' ), array( 'status' => 404 ) );
		}

		$version  = $this->versions->find( $acceptance->version_id );
		$filename = sanitize_file_name( sprintf( '%s-v%d-%d.pdf', $version->title ?? 'document', $version->version ?? 0, $acceptance->id ) );

		return new FileResponse( $this->storage->path( $acceptance->pdf_path ), $filename, 'application/pdf' );
	}

	/**
	 * Streams file responses instead of JSON-encoding them. Headers were already sent by the REST server.
	 *
	 * @param bool             $served Whether the request has been served.
	 * @param WP_HTTP_Response $result Response.
	 */
	public static function serve_file( bool $served, WP_HTTP_Response $result ): bool {
		if ( $served || ! $result instanceof FileResponse ) {
			return $served;
		}

		readfile( $result->path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams the file without loading it into memory.

		return true;
	}
}
