<?php
/**
 * Demo data tests.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Tests\Integration;

use MemberDocs\Cli\Seeder;
use MemberDocs\Repository\AcceptanceFilter;

/**
 * @covers \MemberDocs\Cli\Seeder
 */
final class SeederTest extends TestCase {

	public function test_seeding_is_repeatable(): void {
		$plugin = $this->plugin();
		$seeder = new Seeder( $plugin->template_service, $plugin->templates, $plugin->versions, $plugin->acceptance_service );

		$first = $seeder->run();
		$seeder->run();

		$this->assertCount( 3, $plugin->templates->all() );
		$this->assertSame( 2, $plugin->versions->latest_for( (int) $plugin->templates->find_by_slug( 'membership-terms' )?->id )?->version );
		$this->assertSame( 12, $plugin->acceptances->count( new AcceptanceFilter() ) );
		$this->assertNotFalse( get_user_by( 'email', Seeder::member_email( 12 ) ) );
		$this->assertCount( 2, $first['pages'] );
		$this->assertStringContainsString( '[member_document slug="privacy-policy"]', (string) get_post( url_to_postid( $first['pages'][1] ) )?->post_content );
		$this->assertCount(
			2,
			get_posts(
				array(
					'post_type' => 'page',
					'meta_key'  => '_member_docs_seed_document',
				)
			)
		);
	}
}
