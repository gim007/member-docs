<?php
/**
 * Template versioning tests.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Tests\Integration;

use InvalidArgumentException;
use MemberDocs\Domain\TemplateStatus;

/**
 * @covers \MemberDocs\Service\TemplateService
 * @covers \MemberDocs\Repository\TemplateRepository
 * @covers \MemberDocs\Repository\VersionRepository
 */
final class TemplateServiceTest extends TestCase {

	public function test_create_publishes_version_one(): void {
		$result = $this->create_template();

		$this->assertNotNull( $result->published );
		$this->assertSame( 1, $result->published->version );
		$this->assertSame( $result->template->id, $result->published->template_id );
		$this->assertSame( 1, $result->published->published_by );
		$this->assertTrue( $result->published->is_intact() );
	}

	public function test_saving_unchanged_content_does_not_create_a_version(): void {
		$created = $this->create_template( 'terms', "<p>One</p>\n<p>Two</p>" );

		$result = $this->plugin()->template_service->update(
			$created->template->id,
			$created->template->title,
			'terms',
			TemplateStatus::Active,
			"<p>One</p>\r\n<p>Two</p>  ",
			1
		);

		$this->assertNull( $result->published );
		$this->assertCount( 1, $this->plugin()->versions->for_template( $created->template->id ) );
	}

	public function test_saving_changed_content_creates_the_next_version(): void {
		$created = $this->create_template( 'terms', '<p>One</p>' );
		$service = $this->plugin()->template_service;

		$second = $service->update( $created->template->id, 'Terms', 'terms', TemplateStatus::Active, '<p>Two</p>', 1 );
		$third  = $service->update( $created->template->id, 'Terms (revised)', 'terms', TemplateStatus::Active, '<p>Two</p>', 1 );

		$this->assertSame( 2, $second->published?->version );
		$this->assertSame( 3, $third->published?->version );
		$this->assertSame( array( 3, 2, 1 ), array_map( static fn( $v ) => $v->version, $this->plugin()->versions->for_template( $created->template->id ) ) );
	}

	public function test_publishing_leaves_previous_versions_untouched(): void {
		$created  = $this->create_template( 'terms', '<p>Original</p>' );
		$original = $created->published;

		$this->plugin()->template_service->update( $created->template->id, 'Changed', 'terms', TemplateStatus::Active, '<p>Changed</p>', 1 );

		$this->assertEquals( $original, $this->plugin()->versions->find( (int) $original?->id ) );
	}

	public function test_status_and_slug_changes_alone_do_not_create_a_version(): void {
		$created = $this->create_template( 'terms', '<p>One</p>' );

		$result = $this->plugin()->template_service->update( $created->template->id, $created->template->title, 'new-terms', TemplateStatus::Archived, '<p>One</p>', 1 );

		$this->assertNull( $result->published );
		$this->assertSame( 'new-terms', $result->template->slug );
		$this->assertSame( TemplateStatus::Archived, $result->template->status );
	}

	public function test_body_is_sanitized_before_publishing(): void {
		$result = $this->create_template( 'terms', '<p>Safe</p><script>alert(1)</script>' );

		$this->assertStringNotContainsString( '<script>', (string) $result->published?->body );
	}

	public function test_duplicate_slug_is_rejected(): void {
		$this->create_template( 'terms' );

		$this->expectException( InvalidArgumentException::class );
		$this->create_template( 'terms' );
	}

	public function test_slug_is_derived_from_title_when_empty(): void {
		$result = $this->plugin()->template_service->create( 'Privacy Policy', '', TemplateStatus::Draft, '<p>Text</p>', 1 );

		$this->assertSame( 'privacy-policy', $result->template->slug );
	}

	public function test_empty_body_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->create_template( 'terms', '   ' );
	}

	public function test_current_version_is_the_latest_version_of_an_active_template(): void {
		$created = $this->create_template( 'terms', '<p>One</p>' );
		$service = $this->plugin()->template_service;
		$second  = $service->update( $created->template->id, 'Terms', 'terms', TemplateStatus::Active, '<p>Two</p>', 1 );

		$this->assertEquals( $second->published, $service->current_version( $second->template ) );
		$this->assertSame( $second->published?->id, $service->find_current( 'terms' )[1]->id ?? null );
	}

	public function test_inactive_templates_have_no_current_version(): void {
		$draft = $this->create_template( 'draft-terms', '<p>One</p>', TemplateStatus::Draft );

		$this->assertNull( $this->plugin()->template_service->current_version( $draft->template ) );
		$this->assertNull( $this->plugin()->template_service->find_current( 'draft-terms' ) );
	}
}
