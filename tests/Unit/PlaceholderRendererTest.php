<?php
/**
 * Placeholder renderer tests.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Tests\Unit;

use MemberDocs\Service\PlaceholderRenderer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MemberDocs\Service\PlaceholderRenderer
 */
final class PlaceholderRendererTest extends TestCase {

	public function test_substitutes_known_placeholders(): void {
		$output = ( new PlaceholderRenderer() )->render(
			'<p>{{member.display_name}} accepts {{document.title}} v{{document.version}}.</p>',
			array(
				'member.display_name' => 'Jane',
				'document.title'      => 'Terms',
				'document.version'    => 3,
			)
		);

		$this->assertSame( '<p>Jane accepts Terms v3.</p>', $output );
	}

	public function test_allows_whitespace_inside_braces(): void {
		$this->assertSame( 'Hi Jane', ( new PlaceholderRenderer() )->render( 'Hi {{ member.display_name }}', array( 'member.display_name' => 'Jane' ) ) );
	}

	public function test_escapes_substituted_values(): void {
		$output = ( new PlaceholderRenderer() )->render(
			'<p>{{member.display_name}}</p><a title="{{member.email}}">x</a>',
			array(
				'member.display_name' => '<script>alert("x")</script>',
				'member.email'        => '" onmouseover="steal()',
			)
		);

		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertStringNotContainsString( '" onmouseover', $output );
		$this->assertStringContainsString( '&lt;script&gt;', $output );
	}

	public function test_does_not_render_placeholders_inside_values(): void {
		$output = ( new PlaceholderRenderer() )->render(
			'{{member.display_name}}',
			array(
				'member.display_name' => '{{member.email}}',
				'member.email'        => 'secret@example.com',
			)
		);

		$this->assertSame( '{{member.email}}', $output );
	}

	public function test_leaves_unknown_placeholders_unchanged_and_logs_each_once(): void {
		$messages = array();
		$renderer = new PlaceholderRenderer(
			static function ( string $message ) use ( &$messages ): void {
				$messages[] = $message;
			}
		);

		$first  = $renderer->render( '{{member.shoe_size}} {{member.shoe_size}} {{other}}', array() );
		$second = $renderer->render( '{{member.shoe_size}}', array() );

		$this->assertSame( '{{member.shoe_size}} {{member.shoe_size}} {{other}}', $first );
		$this->assertSame( '{{member.shoe_size}}', $second );
		$this->assertCount( 2, $messages );
		$this->assertStringContainsString( 'member.shoe_size', $messages[0] );
		$this->assertStringContainsString( 'other', $messages[1] );
	}

	public function test_ignores_text_that_is_not_a_placeholder(): void {
		$text = '{{ }} {single} {{has space}} {{trailing.}}';

		$this->assertSame( $text, ( new PlaceholderRenderer() )->render( $text, array() ) );
	}
}
