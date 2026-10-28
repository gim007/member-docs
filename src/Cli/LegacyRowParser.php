<?php
/**
 * Legacy CSV row validation.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Cli;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Validates raw CSV rows of a legacy acceptance export. Pure PHP, no WordPress.
 *
 * Expected columns (any order, header row required): email, template_slug, version, accepted_at, ip.
 * Dates without an explicit offset are interpreted as UTC.
 */
final class LegacyRowParser {

	public const COLUMNS = array( 'email', 'template_slug', 'version', 'accepted_at', 'ip' );

	private const DATE_FORMATS = array( 'Y-m-d H:i:s', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d' );

	/**
	 * Column positions keyed by column name.
	 *
	 * @var array<string, int>
	 */
	private array $positions;

	/**
	 * Upper bound for acceptance dates.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $now;

	/**
	 * Constructor.
	 *
	 * @param list<string|null>      $header Header row.
	 * @param DateTimeImmutable|null $now    Current time; acceptances after it are rejected.
	 * @throws InvalidArgumentException When required columns are missing.
	 */
	public function __construct( array $header, ?DateTimeImmutable $now = null ) {
		$header          = array_map( static fn( ?string $name ): string => strtolower( trim( (string) $name, " \t\n\r\0\x0B\xEF\xBB\xBF" ) ), $header );
		$this->positions = array();

		foreach ( self::COLUMNS as $column ) {
			$position = array_search( $column, $header, true );
			if ( false === $position ) {
				throw new InvalidArgumentException( sprintf( 'Missing column "%s". Expected: %s.', $column, implode( ', ', self::COLUMNS ) ) );
			}
			$this->positions[ $column ] = (int) $position;
		}

		$this->now = $now ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Validates one row.
	 *
	 * @param list<string|null> $values Raw values.
	 * @param int               $line   Line number for reporting.
	 * @throws InvalidArgumentException With a human readable reason when the row is invalid.
	 */
	public function parse( array $values, int $line ): LegacyRow {
		$value = fn( string $column ): string => trim( (string) ( $values[ $this->positions[ $column ] ] ?? '' ) );

		$email = $value( 'email' );
		if ( false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			throw new InvalidArgumentException( sprintf( 'Invalid email "%s".', $email ) );
		}

		$slug = strtolower( $value( 'template_slug' ) );
		if ( 1 !== preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
			throw new InvalidArgumentException( sprintf( 'Invalid template slug "%s".', $slug ) );
		}

		$version = $value( 'version' );
		if ( ! ctype_digit( $version ) || (int) $version < 1 ) {
			throw new InvalidArgumentException( sprintf( 'Invalid version "%s"; expected a positive whole number.', $version ) );
		}

		$accepted_at = $this->parse_date( $value( 'accepted_at' ) );

		$ip = $value( 'ip' );
		if ( '' !== $ip && false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			throw new InvalidArgumentException( sprintf( 'Invalid IP address "%s".', $ip ) );
		}

		return new LegacyRow( $line, $email, $slug, (int) $version, $accepted_at, $ip );
	}

	/**
	 * Parses a date in one of the supported formats.
	 *
	 * @param string $value Raw date.
	 * @throws InvalidArgumentException When the date is invalid or in the future.
	 */
	private function parse_date( string $value ): DateTimeImmutable {
		$utc = new DateTimeZone( 'UTC' );

		foreach ( self::DATE_FORMATS as $format ) {
			$date   = DateTimeImmutable::createFromFormat( '!' . $format, $value, $utc );
			$errors = DateTimeImmutable::getLastErrors();

			// Rejects overflowing dates such as 2024-02-30 that PHP would silently roll over.
			if ( false !== $date && ( false === $errors || 0 === $errors['warning_count'] + $errors['error_count'] ) ) {
				$date = $date->setTimezone( $utc );
				if ( $date > $this->now ) {
					throw new InvalidArgumentException( sprintf( 'Acceptance date "%s" is in the future.', $value ) );
				}
				return $date;
			}
		}

		throw new InvalidArgumentException( sprintf( 'Invalid date "%s"; expected YYYY-MM-DD HH:MM:SS.', $value ) );
	}
}
