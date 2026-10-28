<?php
/**
 * Legacy CSV row parser tests.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MemberDocs\Cli\LegacyRowParser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MemberDocs\Cli\LegacyRowParser
 * @covers \MemberDocs\Cli\LegacyRow
 */
final class LegacyRowParserTest extends TestCase {

	private const HEADER = array( 'email', 'template_slug', 'version', 'accepted_at', 'ip' );

	private function parser(): LegacyRowParser {
		return new LegacyRowParser( self::HEADER, new DateTimeImmutable( '2026-01-01 00:00:00', new DateTimeZone( 'UTC' ) ) );
	}

	public function test_parses_a_valid_row(): void {
		$row = $this->parser()->parse( array( ' jane@example.com ', 'Membership-Terms', '2', '2024-03-01 10:15:00', '198.51.100.7' ), 5 );

		$this->assertSame( 5, $row->line );
		$this->assertSame( 'jane@example.com', $row->email );
		$this->assertSame( 'membership-terms', $row->template_slug );
		$this->assertSame( 2, $row->version );
		$this->assertSame( '2024-03-01 10:15:00', $row->accepted_at->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( 'UTC', $row->accepted_at->getTimezone()->getName() );
		$this->assertSame( '198.51.100.7', $row->ip );
	}

	public function test_columns_may_be_in_any_order_and_header_may_have_bom(): void {
		$parser = new LegacyRowParser( array( 'ip', 'accepted_at', 'version', 'template_slug', "\xEF\xBB\xBFEmail" ) );

		$row = $parser->parse( array( '', '2024-03-01', '1', 'terms', 'jane@example.com' ), 2 );

		$this->assertSame( 'jane@example.com', $row->email );
		$this->assertSame( '', $row->ip );
	}

	public function test_dates_with_offsets_are_converted_to_utc(): void {
		$row = $this->parser()->parse( array( 'jane@example.com', 'terms', '1', '2024-03-01T10:00:00+02:00', '' ), 2 );

		$this->assertSame( '2024-03-01 08:00:00', $row->accepted_at->format( 'Y-m-d H:i:s' ) );
	}

	public function test_missing_column_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Missing column "ip"' );

		new LegacyRowParser( array( 'email', 'template_slug', 'version', 'accepted_at' ) );
	}

	/**
	 * @dataProvider invalid_rows
	 *
	 * @param list<string> $values  Row values.
	 * @param string       $message Expected reason.
	 */
	public function test_invalid_rows_are_rejected_with_a_reason( array $values, string $message ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $message );

		$this->parser()->parse( $values, 3 );
	}

	/**
	 * @return array<string, array{list<string>, string}>
	 */
	public function invalid_rows(): array {
		return array(
			'bad email'         => array( array( 'nope', 'terms', '1', '2024-01-01', '' ), 'Invalid email' ),
			'bad slug'          => array( array( 'a@example.com', 'terms & co', '1', '2024-01-01', '' ), 'Invalid template slug' ),
			'version not int'   => array( array( 'a@example.com', 'terms', 'one', '2024-01-01', '' ), 'Invalid version' ),
			'version zero'      => array( array( 'a@example.com', 'terms', '0', '2024-01-01', '' ), 'Invalid version' ),
			'unparseable date'  => array( array( 'a@example.com', 'terms', '1', 'last tuesday', '' ), 'Invalid date' ),
			'impossible date'   => array( array( 'a@example.com', 'terms', '1', '2023-02-30 10:00:00', '' ), 'Invalid date' ),
			'out of range date' => array( array( 'a@example.com', 'terms', '1', '2023-13-45', '' ), 'Invalid date' ),
			'future date'       => array( array( 'a@example.com', 'terms', '1', '2099-01-01', '' ), 'in the future' ),
			'bad ip'            => array( array( 'a@example.com', 'terms', '1', '2024-01-01', '300.1.1.1' ), 'Invalid IP address' ),
			'short row'         => array( array( 'a@example.com' ), 'Invalid template slug' ),
		);
	}

	public function test_duplicate_key_ignores_email_case(): void {
		$parser = $this->parser();

		$this->assertSame(
			$parser->parse( array( 'Jane@Example.com', 'terms', '1', '2024-01-01', '' ), 2 )->key(),
			$parser->parse( array( 'jane@example.com', 'terms', '1', '2024-02-01', '' ), 3 )->key()
		);
	}
}
