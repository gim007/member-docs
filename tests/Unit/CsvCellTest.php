<?php
/**
 * CSV injection escaping tests.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Tests\Unit;

use MemberDocs\Admin\CsvExporter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MemberDocs\Admin\CsvExporter::cell
 */
final class CsvCellTest extends TestCase {

	/**
	 * @dataProvider formulas
	 *
	 * @param string $value Dangerous value.
	 */
	public function test_formula_triggers_are_prefixed_with_a_quote( string $value ): void {
		$this->assertSame( "'" . $value, CsvExporter::cell( $value ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function formulas(): array {
		return array(
			'equals'          => array( '=HYPERLINK("http://evil.test","x")' ),
			'plus'            => array( '+1+1' ),
			'minus'           => array( '-2+3' ),
			'at'              => array( '@SUM(A1:A2)' ),
			'tab'             => array( "\t=1" ),
			'carriage return' => array( "\r=1" ),
		);
	}

	public function test_safe_values_are_unchanged(): void {
		$this->assertSame( 'Jane Doe', CsvExporter::cell( 'Jane Doe' ) );
		$this->assertSame( 'a=b', CsvExporter::cell( 'a=b' ) );
		$this->assertSame( '42', CsvExporter::cell( 42 ) );
		$this->assertSame( '', CsvExporter::cell( null ) );
	}
}
