<?php
/**
 * Client request details recorded with an acceptance.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Service;

/**
 * Validated IP address and user agent of the accepting client.
 *
 * Only REMOTE_ADDR is trusted; forwarded headers can be spoofed unless the site
 * sits behind a known proxy, which is a deployment decision (see README).
 */
final class ClientInfo {

	public const MAX_USER_AGENT_LENGTH = 255;

	public readonly string $ip_address;
	public readonly string $user_agent;

	/**
	 * Constructor.
	 *
	 * @param string $ip_address IP address; dropped when invalid.
	 * @param string $user_agent User agent; control characters removed, truncated to 255 characters.
	 */
	public function __construct( string $ip_address = '', string $user_agent = '' ) {
		$this->ip_address = false === filter_var( $ip_address, FILTER_VALIDATE_IP ) ? '' : $ip_address;

		$user_agent       = (string) preg_replace( '/[\x00-\x1F\x7F]+/u', '', mb_scrub( $user_agent, 'UTF-8' ) );
		$this->user_agent = mb_substr( trim( $user_agent ), 0, self::MAX_USER_AGENT_LENGTH, 'UTF-8' );
	}

	/**
	 * Reads the client details from a $_SERVER-like array.
	 *
	 * @param array<string, mixed> $server Server variables.
	 */
	public static function from_server( array $server ): self {
		$ip         = $server['REMOTE_ADDR'] ?? '';
		$user_agent = $server['HTTP_USER_AGENT'] ?? '';

		return new self( is_string( $ip ) ? $ip : '', is_string( $user_agent ) ? $user_agent : '' );
	}
}
