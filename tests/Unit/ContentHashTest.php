<?php
/**
 * Content hash tests.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Tests\Unit;

use MemberDocs\Domain\ContentHash;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MemberDocs\Domain\ContentHash
 */
final class ContentHashTest extends TestCase {

	public function test_hash_is_sha256(): void {
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', ContentHash::of( 'Terms', '<p>Body</p>' ) );
	}

	public function test_line_endings_do_not_change_the_hash(): void {
		$this->assertSame(
			ContentHash::of( 'Terms', "<p>One</p>\n<p>Two</p>" ),
			ContentHash::of( 'Terms', "<p>One</p>\r\n<p>Two</p>" )
		);
		$this->assertSame(
			ContentHash::of( 'Terms', "<p>One</p>\n<p>Two</p>" ),
			ContentHash::of( 'Terms', "<p>One</p>\r<p>Two</p>" )
		);
	}

	public function test_whitespace_runs_and_surrounding_whitespace_do_not_change_the_hash(): void {
		$this->assertSame(
			ContentHash::of( 'Terms', '<p>One two</p>' ),
			ContentHash::of( "  Terms\t", "\n\n<p>One \t  two</p>  \n" )
		);
	}

	public function test_changed_body_changes_the_hash(): void {
		$this->assertNotSame( ContentHash::of( 'Terms', '<p>One</p>' ), ContentHash::of( 'Terms', '<p>Two</p>' ) );
	}

	public function test_changed_title_changes_the_hash(): void {
		$this->assertNotSame( ContentHash::of( 'Terms', '<p>One</p>' ), ContentHash::of( 'Terms v2', '<p>One</p>' ) );
	}

	public function test_title_and_body_cannot_be_shifted_into_each_other(): void {
		$this->assertNotSame( ContentHash::of( 'A B', 'C' ), ContentHash::of( 'A', 'B C' ) );
	}
}
