<?php
/**
 * Admin screen tests.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Tests\Integration;

use DateTimeImmutable;
use MemberDocs\Admin\AcceptanceLogPage;
use MemberDocs\Admin\CsvExporter;
use MemberDocs\Admin\Notices;
use MemberDocs\Admin\TemplatesPage;
use MemberDocs\Domain\AcceptanceSource;
use MemberDocs\Domain\TemplateStatus;
use MemberDocs\Repository\AcceptanceFilter;
use MemberDocs\Service\ClientInfo;
use WPDieException;

/**
 * @covers \MemberDocs\Admin\TemplatesPage
 * @covers \MemberDocs\Admin\AcceptanceLogPage
 * @covers \MemberDocs\Admin\CsvExporter
 * @covers \MemberDocs\Admin\Notices
 * @covers \MemberDocs\Admin\TemplatesListTable
 * @covers \MemberDocs\Admin\AcceptanceLogListTable
 */
final class AdminTest extends TestCase {

	private Notices $notices;
	private TemplatesPage $page;

	public function set_up(): void {
		parent::set_up();

		$plugin        = $this->plugin();
		$this->notices = new Notices();
		$this->page    = new TemplatesPage( $plugin->template_service, $plugin->templates, $plugin->versions, $plugin->acceptances, $this->notices );

		wp_set_current_user( $this->member( 'administrator' )->ID );
	}

	/**
	 * Form input as handle_save() would build it.
	 *
	 * @param int    $template_id Template ID.
	 * @param string $body        Body.
	 * @param string $slug        Slug.
	 * @return array{template_id: int, title: string, slug: string, status: TemplateStatus, body: string}
	 */
	private function input( int $template_id, string $body, string $slug = 'terms' ): array {
		return array(
			'template_id' => $template_id,
			'title'       => 'Terms',
			'slug'        => $slug,
			'status'      => TemplateStatus::Active,
			'body'        => $body,
		);
	}

	private function last_notice(): string {
		$pending = $this->notices->pending();

		return (string) end( $pending )['message'];
	}

	public function test_saving_reports_published_version_numbers(): void {
		$this->page->save( $this->input( 0, '<p>One</p>' ), get_current_user_id() );
		$this->assertSame( 'Version 1 published.', $this->last_notice() );

		$template = $this->plugin()->templates->find_by_slug( 'terms' );
		$this->page->save( $this->input( (int) $template?->id, '<p>One</p>' ), get_current_user_id() );
		$this->assertStringContainsString( 'no new version was published', $this->last_notice() );

		$redirect = $this->page->save( $this->input( (int) $template?->id, '<p>Two</p>' ), get_current_user_id() );
		$this->assertSame( 'Version 2 published.', $this->last_notice() );
		$this->assertSame(
			TemplatesPage::url(
				array(
					'action' => 'edit',
					'id'     => (int) $template?->id,
				)
			),
			$redirect
		);
	}

	public function test_invalid_input_shows_an_error_and_keeps_the_entered_text(): void {
		$this->create_template( 'terms' );

		$redirect = $this->page->save( $this->input( 0, '<p>Draft text</p>' ), get_current_user_id() );

		$this->assertSame( 'error', $this->notices->pending()[0]['type'] );
		$this->assertStringContainsString( 'page=' . TemplatesPage::NEW_SLUG, $redirect );

		set_current_screen( 'member-documents_page_' . TemplatesPage::NEW_SLUG );
		ob_start();
		$this->page->render_new();
		$this->assertStringContainsString( 'Draft text', (string) ob_get_clean() );
	}

	public function test_save_handler_requires_capability(): void {
		wp_set_current_user( $this->member()->ID );

		$this->expectException( WPDieException::class );
		$this->page->handle_save();
	}

	public function test_save_handler_requires_nonce(): void {
		$_POST = array( 'title' => 'Terms' );

		$this->expectException( WPDieException::class );
		$this->page->handle_save();
	}

	public function test_templates_list_shows_current_version_and_acceptance_count(): void {
		$created = $this->create_template( 'terms', '<p>One</p>' );
		$second  = $this->plugin()->template_service->update( $created->template->id, 'Terms', 'terms', TemplateStatus::Active, '<p>Two</p>', 1 );
		$this->accept( $second->published, $this->member() );

		set_current_screen( 'toplevel_page_' . TemplatesPage::SLUG );
		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Terms', $html );
		$this->assertMatchesRegularExpression( '#data-colname="Current version">2<#', $html );
		$this->assertMatchesRegularExpression( '#data-colname="Acceptances">1<#', $html );
	}

	public function test_log_filter_resolves_users_and_whole_days(): void {
		$user = $this->member();
		update_option( 'timezone_string', 'Europe/Berlin' );

		$filter = AcceptanceLogPage::filter(
			array(
				'template_id' => '3',
				'version_id'  => '',
				'user'        => $user->user_email,
				'from'        => '2026-01-10',
				'to'          => '2026-01-11',
			)
		);

		$this->assertSame( 3, $filter->template_id );
		$this->assertNull( $filter->version_id );
		$this->assertSame( $user->ID, $filter->user_id );
		$this->assertSame( '2026-01-09 23:00:00', $filter->from?->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( '2026-01-11 22:59:59', $filter->to?->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) );

		$this->assertSame( $user->ID, AcceptanceLogPage::filter( array( 'user' => $user->user_login ) )->user_id );
		$this->assertSame( 0, AcceptanceLogPage::filter( array( 'user' => 'nobody@example.com' ) )->user_id );
		$this->assertNull( AcceptanceLogPage::filter( array( 'from' => 'not-a-date' ) )->from );
	}

	public function test_csv_export_contains_rows_and_neutralises_formulas(): void {
		$version = $this->create_template()->published;
		$user    = $this->member();
		$this->plugin()->acceptance_service->accept( $version, $user, AcceptanceSource::Web, new ClientInfo( '203.0.113.9', '=HYPERLINK("http://evil.test")' ) );
		$plugin   = $this->plugin();
		$exporter = new CsvExporter( $plugin->acceptances, $plugin->templates, $plugin->versions );
		$stream   = fopen( 'php://memory', 'w+' );

		$rows = $exporter->write( $stream, new AcceptanceFilter() );
		rewind( $stream );
		$lines = array_map(
			static fn( string $line ): array => str_getcsv( $line, ',', '"', '' ),
			explode( "\n", trim( (string) stream_get_contents( $stream ) ) )
		);

		$this->assertSame( 1, $rows );
		$this->assertCount( 2, $lines );
		$this->assertSame( 'id', $lines[0][0] );
		$this->assertSame( $user->user_email, $lines[1][5] );
		$this->assertSame( 'membership-terms', $lines[1][2] );
		$this->assertSame( '\'=HYPERLINK("http://evil.test")', $lines[1][10] );
		$this->assertSame( 'yes', $lines[1][12] );
	}

	public function test_export_requires_capability(): void {
		wp_set_current_user( $this->member()->ID );
		$plugin = $this->plugin();
		$page   = new AcceptanceLogPage( $plugin->acceptances, $plugin->templates, $plugin->versions, new CsvExporter( $plugin->acceptances, $plugin->templates, $plugin->versions ) );

		$this->expectException( WPDieException::class );
		$page->handle_export();
	}

	public function test_acceptance_log_renders_filtered_rows(): void {
		$version = $this->create_template()->published;
		$this->accept( $version, $this->member() );
		$plugin = $this->plugin();
		$page   = new AcceptanceLogPage( $plugin->acceptances, $plugin->templates, $plugin->versions, new CsvExporter( $plugin->acceptances, $plugin->templates, $plugin->versions ) );

		set_current_screen( 'member-documents_page_' . AcceptanceLogPage::SLUG );
		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Jane Member', $html );
		$this->assertStringContainsString( 'Membership Terms (v1)', $html );
		$this->assertStringContainsString( 'action=' . AcceptanceLogPage::EXPORT_ACTION, $html );
	}

	public function test_notices_are_shown_once(): void {
		$this->notices->add( 'Saved.' );

		ob_start();
		$this->notices->render();
		$first = (string) ob_get_clean();
		ob_start();
		$this->notices->render();
		$second = (string) ob_get_clean();

		$this->assertStringContainsString( 'Saved.', $first );
		$this->assertSame( '', $second );
	}

	public function test_version_history_lists_all_versions(): void {
		$created = $this->create_template( 'terms', '<p>One</p>' );
		$this->plugin()->template_service->update( $created->template->id, 'Terms', 'terms', TemplateStatus::Active, '<p>Two</p>', 1 );
		$_GET = array(
			'action' => 'history',
			'id'     => (string) $created->template->id,
		);

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		$this->assertSame( 2, substr_count( $html, 'action=version' ) );
		$this->assertStringContainsString( '(current)', $html );
	}
}
