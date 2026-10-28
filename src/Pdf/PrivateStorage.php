<?php
/**
 * Private file storage for generated PDFs.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Pdf;

use DateTimeImmutable;
use RuntimeException;

/**
 * Stores files below a web-protected directory with random, non-guessable names.
 *
 * Paths handed out by this class are relative to the storage root so the database
 * never contains server-specific absolute paths.
 */
final class PrivateStorage {

	public const DIRECTORY = 'member-docs-private';

	/**
	 * Absolute path of the storage root, without trailing slash.
	 *
	 * @var string
	 */
	private string $base_dir;

	/**
	 * Constructor.
	 *
	 * @param string $base_dir Absolute path of the storage root.
	 */
	public function __construct( string $base_dir ) {
		$this->base_dir = untrailingslashit( $base_dir );
	}

	/**
	 * Storage located in the uploads directory.
	 */
	public static function in_uploads(): self {
		return new self( wp_upload_dir( null, false )['basedir'] . '/' . self::DIRECTORY );
	}

	/**
	 * Absolute path of the storage root.
	 */
	public function base_dir(): string {
		return $this->base_dir;
	}

	/**
	 * Creates the root directory and the files that block direct web access.
	 *
	 * Apache honours .htaccess, IIS honours web.config, and index.php prevents
	 * directory listings elsewhere. Nginx needs a server rule (see README).
	 *
	 * @throws RuntimeException When the directory cannot be created.
	 */
	public function ensure_protected(): void {
		if ( ! wp_mkdir_p( $this->base_dir ) ) {
			throw new RuntimeException( esc_html__( 'Could not create the private storage directory.', 'member-docs' ) );
		}

		$files = array(
			'.htaccess'  => "# Member Documents: files are served through an authenticated REST endpoint only.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
		);

		foreach ( $files as $name => $contents ) {
			$path = $this->base_dir . '/' . $name;
			if ( ! file_exists( $path ) ) {
				file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Runs outside the Filesystem API context (activation, REST, CLI).
			}
		}
	}

	/**
	 * Writes a file into a year/month sub-directory under a random name.
	 *
	 * @param string            $contents  File contents.
	 * @param DateTimeImmutable $timestamp Determines the year/month sub-directory.
	 * @param string            $extension File extension without dot.
	 * @return string Path relative to the storage root.
	 * @throws RuntimeException When the file cannot be written.
	 */
	public function store( string $contents, DateTimeImmutable $timestamp, string $extension = 'pdf' ): string {
		$this->ensure_protected();

		$directory = $timestamp->format( 'Y/m' );
		if ( ! wp_mkdir_p( $this->base_dir . '/' . $directory ) ) {
			throw new RuntimeException( esc_html__( 'Could not create the private storage directory.', 'member-docs' ) );
		}

		$relative = $directory . '/' . bin2hex( random_bytes( 16 ) ) . '.' . $extension;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- See ensure_protected().
		if ( false === file_put_contents( $this->base_dir . '/' . $relative, $contents, LOCK_EX ) ) {
			throw new RuntimeException( esc_html__( 'Could not write the file to private storage.', 'member-docs' ) );
		}

		return $relative;
	}

	/**
	 * Resolves a stored relative path to an absolute one.
	 *
	 * @param string $relative Path as returned by store().
	 * @throws RuntimeException When the path tries to escape the storage root.
	 */
	public function path( string $relative ): string {
		if ( ! preg_match( '#^\d{4}/\d{2}/[a-f0-9]{32}\.[a-z0-9]+$#', $relative ) ) {
			throw new RuntimeException( esc_html__( 'Invalid private storage path.', 'member-docs' ) );
		}

		return $this->base_dir . '/' . $relative;
	}

	/**
	 * Whether a stored file exists.
	 *
	 * @param string $relative Path as returned by store().
	 */
	public function exists( string $relative ): bool {
		return is_file( $this->path( $relative ) );
	}

	/**
	 * SHA-256 of a stored file, or null if it does not exist.
	 *
	 * @param string $relative Path as returned by store().
	 */
	public function hash( string $relative ): ?string {
		$hash = $this->exists( $relative ) ? hash_file( 'sha256', $this->path( $relative ) ) : false;

		return false === $hash ? null : $hash;
	}

	/**
	 * Deletes a stored file if it exists.
	 *
	 * @param string $relative Path as returned by store().
	 */
	public function delete( string $relative ): void {
		if ( $this->exists( $relative ) ) {
			wp_delete_file( $this->path( $relative ) );
		}
	}

	/**
	 * Removes the whole storage directory. Used by uninstall only.
	 */
	public function delete_all(): void {
		if ( ! is_dir( $this->base_dir ) ) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->base_dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			/** @var \SplFileInfo $item */
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Uninstall cleanup without the Filesystem API.
			} else {
				wp_delete_file( $item->getPathname() );
			}
		}

		rmdir( $this->base_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- See above.
	}
}
