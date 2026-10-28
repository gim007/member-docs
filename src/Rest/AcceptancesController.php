<?php
/**
 * Acceptance REST routes.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Rest;

use DateTimeImmutable;
use MemberDocs\Capabilities;
use MemberDocs\Domain\Acceptance;
use MemberDocs\Repository\AcceptanceFilter;
use MemberDocs\Repository\AcceptanceRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * GET /me/acceptances  The logged-in member's acceptances.
 * GET /acceptances     The full acceptance log (md_view_acceptances).
 */
final class AcceptancesController {

	/**
	 * Constructor.
	 *
	 * @param AcceptanceRepository $acceptances Acceptance repository.
	 * @param AcceptanceFormatter  $formatter   Acceptance formatter.
	 */
	public function __construct(
		private readonly AcceptanceRepository $acceptances,
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
		register_rest_route(
			Permission::NAMESPACE,
			'/me/acceptances',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_mine' ),
				'permission_callback' => array( Permission::class, 'logged_in' ),
			)
		);

		$id = array(
			'type'    => 'integer',
			'minimum' => 1,
		);

		register_rest_route(
			Permission::NAMESPACE,
			'/acceptances',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_log' ),
				'permission_callback' => static fn() => Permission::can( Capabilities::VIEW_ACCEPTANCES ),
				'args'                => array(
					'template_id' => $id + array( 'description' => __( 'Limit to one template.', 'member-docs' ) ),
					'version_id'  => $id + array( 'description' => __( 'Limit to one version.', 'member-docs' ) ),
					'user_id'     => $id + array( 'description' => __( 'Limit to one user.', 'member-docs' ) ),
					'after'       => array(
						'description' => __( 'Accepted at or after this date (ISO 8601).', 'member-docs' ),
						'type'        => 'string',
						'format'      => 'date-time',
					),
					'before'      => array(
						'description' => __( 'Accepted at or before this date (ISO 8601).', 'member-docs' ),
						'type'        => 'string',
						'format'      => 'date-time',
					),
					'page'        => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page'    => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 20,
					),
				),
			)
		);
	}

	/**
	 * Lists the current member's acceptances.
	 */
	public function get_mine(): WP_REST_Response {
		$acceptances = $this->acceptances->for_user( get_current_user_id() );

		return new WP_REST_Response( array_map( fn( Acceptance $acceptance ) => $this->formatter->format( $acceptance ), $acceptances ) );
	}

	/**
	 * Lists the acceptance log with filters and pagination.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array{template_id?: int, version_id?: int, user_id?: int, after?: string, before?: string, page: int, per_page: int}> $request
	 */
	public function get_log( WP_REST_Request $request ): WP_REST_Response {
		$filter = new AcceptanceFilter(
			isset( $request['template_id'] ) ? (int) $request['template_id'] : null,
			isset( $request['version_id'] ) ? (int) $request['version_id'] : null,
			isset( $request['user_id'] ) ? (int) $request['user_id'] : null,
			isset( $request['after'] ) ? new DateTimeImmutable( $request['after'] ) : null,
			isset( $request['before'] ) ? new DateTimeImmutable( $request['before'] ) : null
		);

		$per_page = (int) $request['per_page'];
		$total    = $this->acceptances->count( $filter );
		$items    = $this->acceptances->search( $filter, $per_page, ( (int) $request['page'] - 1 ) * $per_page );

		$response = new WP_REST_Response( array_map( fn( Acceptance $acceptance ) => $this->formatter->format( $acceptance, true ), $items ) );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / $per_page ) );

		return $response;
	}
}
