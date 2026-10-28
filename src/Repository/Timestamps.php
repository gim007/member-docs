<?php
/**
 * Date conversion between PHP and MySQL.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Repository;

use DateTimeImmutable;
use DateTimeZone;

/**
 * All dates are stored as UTC DATETIME values.
 */
final class Timestamps {

	private const FORMAT = 'Y-m-d H:i:s';

	/**
	 * Current time in UTC, truncated to whole seconds like the database column.
	 */
	public static function now(): DateTimeImmutable {
		return self::from_db( gmdate( self::FORMAT ) );
	}

	/**
	 * Formats a date for a DATETIME column.
	 *
	 * @param DateTimeImmutable $date Date in any time zone.
	 */
	public static function to_db( DateTimeImmutable $date ): string {
		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( self::FORMAT );
	}

	/**
	 * Parses a DATETIME column value.
	 *
	 * @param string $value Value stored in UTC.
	 */
	public static function from_db( string $value ): DateTimeImmutable {
		return new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
	}
}
