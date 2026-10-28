<?php
/**
 * Shortcode tests.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Tests\Integration;

use MemberDocs\Domain\TemplateStatus;
use MemberDocs\Frontend\Shortcode;

/**
 * @covers \MemberDocs\Frontend\Shortcode
 */
final class ShortcodeTest extends TestCase {

	public function tear_down(): void {
		wp_dequeue_script( Shortcode::SCRIPT );
		parent::tear_down();
	}

	public function test_visitors_see_a_login_prompt(): void {
		$this->create_template();

		$html = do_shortcode( '[member_document slug="membership-terms"]' );

		$this->assertStringContainsString( 'log in', $html );
		$this->assertStringContainsString( wp_login_url(), $html );
		$this->assertStringNotContainsString( 'Hello', $html );
		$this->assertFalse( wp_script_is( Shortcode::SCRIPT, 'enqueued' ) );
	}

	public function test_members_see_the_document_and_accept_button(): void {
		$version = $this->create_template()->published;
		wp_set_current_user( $this->member()->ID );

		$html = do_shortcode( '[member_document slug="membership-terms"]' );

		$this->assertStringContainsString( '<p>Hello Jane Member.</p>', $html );
		$this->assertStringContainsString( 'data-version-id="' . $version->id . '"', $html );
		$this->assertStringContainsString( 'member-docs/v1/templates/membership-terms/accept', urldecode( $html ) );
		$this->assertStringContainsString( 'aria-live="polite"', $html );
		$this->assertTrue( wp_script_is( Shortcode::SCRIPT, 'enqueued' ) );
	}

	public function test_members_who_accepted_see_date_and_download_link(): void {
		$version    = $this->create_template()->published;
		$acceptance = $this->accept( $version, $this->member() );
		wp_set_current_user( $acceptance->user_id );

		$html = do_shortcode( '[member_document slug="membership-terms"]' );

		$this->assertStringContainsString( 'You accepted', $html );
		$this->assertStringContainsString( 'Download PDF', $html );
		$this->assertStringContainsString( "acceptances/{$acceptance->id}/pdf", urldecode( $html ) );
		$this->assertStringNotContainsString( 'I accept', $html );
		$this->assertFalse( wp_script_is( Shortcode::SCRIPT, 'enqueued' ) );
	}

	public function test_a_new_version_asks_for_acceptance_again(): void {
		$created = $this->create_template( 'terms', '<p>One</p>' );
		$member  = $this->member();
		$this->accept( $created->published, $member );
		$this->plugin()->template_service->update( $created->template->id, 'Terms', 'terms', TemplateStatus::Active, '<p>Two</p>', 1 );
		wp_set_current_user( $member->ID );

		$html = do_shortcode( '[member_document slug="terms"]' );

		$this->assertStringContainsString( '<p>Two</p>', $html );
		$this->assertStringContainsString( 'I accept', $html );
	}

	public function test_unknown_or_inactive_documents_render_nothing(): void {
		$this->create_template( 'draft', '<p>Draft</p>', TemplateStatus::Draft );
		wp_set_current_user( $this->member()->ID );

		$this->assertSame( '', do_shortcode( '[member_document slug="draft"]' ) );
		$this->assertSame( '', do_shortcode( '[member_document slug="missing"]' ) );
		$this->assertSame( '', do_shortcode( '[member_document]' ) );
	}

	public function test_script_is_registered_but_not_enqueued_on_other_pages(): void {
		do_action( 'wp_enqueue_scripts' );

		$this->assertTrue( wp_script_is( Shortcode::SCRIPT, 'registered' ) );
		$this->assertFalse( wp_script_is( Shortcode::SCRIPT, 'enqueued' ) );
	}
}
