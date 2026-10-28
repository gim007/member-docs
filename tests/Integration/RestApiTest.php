<?php
/**
 * REST API tests.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Tests\Integration;

use MemberDocs\Domain\TemplateStatus;
use MemberDocs\Rest\DownloadController;
use MemberDocs\Rest\FileResponse;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @covers \MemberDocs\Rest\TemplatesController
 * @covers \MemberDocs\Rest\AcceptancesController
 * @covers \MemberDocs\Rest\DownloadController
 * @covers \MemberDocs\Rest\AcceptanceFormatter
 * @covers \MemberDocs\Rest\Permission
 */
final class RestApiTest extends TestCase {

	private const NS = '/member-docs/v1';

	/**
	 * Sends a request through the REST server.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route below the namespace.
	 * @param array<string, mixed> $params Parameters.
	 */
	private function request( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, self::NS . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}

	public function test_every_route_has_a_real_permission_callback(): void {
		$routes = array_filter(
			rest_get_server()->get_routes(),
			static fn( string $route ): bool => str_starts_with( $route, self::NS . '/' ),
			ARRAY_FILTER_USE_KEY
		);

		$this->assertCount( 5, $routes );
		foreach ( $routes as $route => $endpoints ) {
			foreach ( $endpoints as $endpoint ) {
				$this->assertArrayHasKey( 'permission_callback', $endpoint, $route );
				$this->assertNotSame( '__return_true', $endpoint['permission_callback'], $route );
			}
		}
	}

	public function test_current_version_requires_login(): void {
		$this->create_template();

		$this->assertSame( 401, $this->request( 'GET', '/templates/membership-terms/current' )->get_status() );
	}

	public function test_current_version_is_rendered_for_the_member(): void {
		$version = $this->create_template()->published;
		wp_set_current_user( $this->member()->ID );

		$response = $this->request( 'GET', '/templates/membership-terms/current' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $version->id, $data['version']['id'] );
		$this->assertSame( '<p>Hello Jane Member.</p>', $data['version']['content'] );
		$this->assertNull( $data['acceptance'] );
		$this->assertArrayNotHasKey( 'body', $data['version'], 'Raw template source is not exposed.' );
	}

	public function test_unknown_and_inactive_templates_are_not_found(): void {
		$this->create_template( 'draft-doc', '<p>Draft</p>', TemplateStatus::Draft );
		wp_set_current_user( $this->member()->ID );

		$this->assertSame( 404, $this->request( 'GET', '/templates/nope/current' )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '/templates/draft-doc/current' )->get_status() );
	}

	public function test_accept_creates_then_returns_the_existing_acceptance(): void {
		$version = $this->create_template()->published;
		wp_set_current_user( $this->member()->ID );

		$first  = $this->request( 'POST', '/templates/membership-terms/accept', array( 'version_id' => $version->id ) );
		$second = $this->request( 'POST', '/templates/membership-terms/accept', array( 'version_id' => $version->id ) );

		$this->assertSame( 201, $first->get_status() );
		$this->assertSame( 200, $second->get_status() );
		$this->assertSame( $first->get_data()['id'], $second->get_data()['id'] );
		$this->assertStringContainsString( 'member-docs/v1/acceptances/' . $first->get_data()['id'] . '/pdf', urldecode( $first->get_data()['download_url'] ) );
		$this->assertArrayNotHasKey( 'ip_address', $first->get_data(), 'Audit fields are not exposed to members.' );
	}

	public function test_accepting_an_outdated_version_returns_409(): void {
		$created = $this->create_template( 'terms', '<p>One</p>' );
		$second  = $this->plugin()->template_service->update( $created->template->id, 'Terms', 'terms', TemplateStatus::Active, '<p>Two</p>', 1 );
		wp_set_current_user( $this->member()->ID );

		$response = $this->request( 'POST', '/templates/terms/accept', array( 'version_id' => $created->published->id ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'member_docs_stale_version', $response->as_error()->get_error_code() );
		$this->assertSame( $second->published?->id, $response->as_error()->get_error_data()['current_version_id'] );
	}

	public function test_accept_validates_input_and_requires_login(): void {
		$version = $this->create_template()->published;

		$this->assertSame( 401, $this->request( 'POST', '/templates/membership-terms/accept', array( 'version_id' => $version->id ) )->get_status() );

		wp_set_current_user( $this->member()->ID );
		$this->assertSame( 400, $this->request( 'POST', '/templates/membership-terms/accept' )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/templates/membership-terms/accept', array( 'version_id' => 'abc' ) )->get_status() );
	}

	public function test_me_acceptances_lists_only_own_records(): void {
		$version = $this->create_template()->published;
		$mine    = $this->accept( $version, $this->member() );
		$this->accept( $version, $this->member() );
		wp_set_current_user( $mine->user_id );

		$data = $this->request( 'GET', '/me/acceptances' )->get_data();

		$this->assertSame( array( $mine->id ), array_column( $data, 'id' ) );
	}

	public function test_subscriber_cannot_read_the_acceptance_log(): void {
		wp_set_current_user( $this->member()->ID );

		$this->assertSame( 403, $this->request( 'GET', '/acceptances' )->get_status() );
	}

	public function test_auditor_can_filter_the_acceptance_log(): void {
		$terms   = $this->create_template()->published;
		$privacy = $this->create_template( 'privacy', '<p>Privacy</p>' )->published;
		$member  = $this->member();
		$this->accept( $terms, $member );
		$expected = $this->accept( $privacy, $member );
		$this->accept( $privacy, $this->member() );
		wp_set_current_user( $this->member( 'administrator' )->ID );

		$response = $this->request(
			'GET',
			'/acceptances',
			array(
				'template_id' => $privacy->template_id,
				'user_id'     => $member->ID,
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $expected->id ), array_column( $response->get_data(), 'id' ) );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '203.0.113.7', $response->get_data()[0]['ip_address'] );
	}

	public function test_owner_can_download_own_pdf(): void {
		$acceptance = $this->accept( $this->create_template()->published, $this->member() );
		wp_set_current_user( $acceptance->user_id );

		$response = $this->request( 'GET', "/acceptances/{$acceptance->id}/pdf" );

		$this->assertInstanceOf( FileResponse::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'application/pdf', $response->get_headers()['Content-Type'] );
		$this->assertStringStartsWith( 'attachment; filename="', $response->get_headers()['Content-Disposition'] );
	}

	public function test_other_members_cannot_download_the_pdf(): void {
		$acceptance = $this->accept( $this->create_template()->published, $this->member() );

		$this->assertSame( 401, $this->request( 'GET', "/acceptances/{$acceptance->id}/pdf" )->get_status() );

		wp_set_current_user( $this->member()->ID );
		$this->assertSame( 403, $this->request( 'GET', "/acceptances/{$acceptance->id}/pdf" )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/acceptances/999999/pdf' )->get_status() );
	}

	public function test_auditor_can_download_any_pdf(): void {
		$acceptance = $this->accept( $this->create_template()->published, $this->member() );
		wp_set_current_user( $this->member( 'administrator' )->ID );

		$this->assertSame( 200, $this->request( 'GET', "/acceptances/{$acceptance->id}/pdf" )->get_status() );
	}

	public function test_missing_pdf_file_returns_404(): void {
		$acceptance = $this->accept( $this->create_template()->published, $this->member() );
		$this->plugin()->storage->delete( (string) $acceptance->pdf_path );
		wp_set_current_user( $acceptance->user_id );

		$this->assertSame( 404, $this->request( 'GET', "/acceptances/{$acceptance->id}/pdf" )->get_status() );
	}

	public function test_file_responses_are_streamed_instead_of_json_encoded(): void {
		$acceptance = $this->accept( $this->create_template()->published, $this->member() );
		wp_set_current_user( $acceptance->user_id );
		$response = $this->request( 'GET', "/acceptances/{$acceptance->id}/pdf" );

		ob_start();
		$served = DownloadController::serve_file( false, $response );
		$output = (string) ob_get_clean();

		$this->assertTrue( $served );
		$this->assertSame( file_get_contents( $this->plugin()->storage->path( (string) $acceptance->pdf_path ) ), $output );
		$this->assertFalse( DownloadController::serve_file( false, new WP_REST_Response( array() ) ) );
	}
}
