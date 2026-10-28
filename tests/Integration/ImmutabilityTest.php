<?php
/**
 * Immutability guarantees of versions and acceptances.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Tests\Integration;

use Error;
use MemberDocs\Domain\Acceptance;
use MemberDocs\Domain\TemplateVersion;
use MemberDocs\Repository\AcceptanceRepository;
use MemberDocs\Repository\VersionRepository;
use ReflectionClass;
use ReflectionMethod;

/**
 * @coversNothing
 */
final class ImmutabilityTest extends TestCase {

	/**
	 * @dataProvider append_only_repositories
	 *
	 * @param class-string $repository Repository class.
	 */
	public function test_append_only_repositories_have_no_mutation_methods( string $repository ): void {
		$methods = array_map(
			static fn( ReflectionMethod $method ): string => $method->getName(),
			( new ReflectionClass( $repository ) )->getMethods( ReflectionMethod::IS_PUBLIC )
		);

		$mutations = preg_grep( '/^(update|delete|remove|save|replace|upsert|set|truncate|modify|edit)/i', $methods );

		$this->assertSame( array(), array_values( (array) $mutations ) );
		$this->assertContains( 'insert', $methods );
	}

	/**
	 * @return array<string, array{class-string}>
	 */
	public function append_only_repositories(): array {
		return array(
			'versions'    => array( VersionRepository::class ),
			'acceptances' => array( AcceptanceRepository::class ),
		);
	}

	/**
	 * @dataProvider value_objects
	 *
	 * @param class-string $value_object Value object class.
	 */
	public function test_value_object_properties_are_readonly( string $value_object ): void {
		foreach ( ( new ReflectionClass( $value_object ) )->getProperties() as $property ) {
			$this->assertTrue( $property->isReadOnly(), "{$value_object}::\${$property->getName()} must be readonly." );
		}
	}

	/**
	 * @return array<string, array{class-string}>
	 */
	public function value_objects(): array {
		return array(
			'version'    => array( TemplateVersion::class ),
			'acceptance' => array( Acceptance::class ),
		);
	}

	public function test_published_version_cannot_be_changed_in_memory(): void {
		$version = $this->create_template()->published;

		$this->expectException( Error::class );
		$version->body = '<p>Tampered</p>'; // @phpstan-ignore-line Deliberate write to a readonly property.
	}
}
