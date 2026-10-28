<?php
/**
 * Client info tests.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Tests\Unit;

use MemberDocs\Service\ClientInfo;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MemberDocs\Service\ClientInfo
 */
final class ClientInfoTest extends TestCase {

	public function test_reads_server_variables(): void {
		$client = ClientInfo::from_server(
			array(
				'REMOTE_ADDR'     => '2001:db8::1',
				'HTTP_USER_AGENT' => 'Browser/1.0',
			)
		);

		$this->assertSame( '2001:db8::1', $client->ip_address );
		$this->assertSame( 'Browser/1.0', $client->user_agent );
	}

	public function test_drops_invalid_ip_addresses(): void {
		$this->assertSame( '', ( new ClientInfo( '999.1.1.1' ) )->ip_address );
		$this->assertSame( '', ClientInfo::from_server( array() )->ip_address );
	}

	public function test_truncates_user_agent_to_255_characters(): void {
		$client = new ClientInfo( '127.0.0.1', str_repeat( 'ä', 300 ) );

		$this->assertSame( 255, mb_strlen( $client->user_agent ) );
	}

	public function test_strips_control_characters_and_invalid_utf8(): void {
		$client = new ClientInfo( '127.0.0.1', "Bad\r\nHeader\x00\xff" );

		$this->assertSame( 'BadHeader?', $client->user_agent );
	}
}
