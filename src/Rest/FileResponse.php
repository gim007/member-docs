<?php
/**
 * File download response.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Rest;

use WP_REST_Response;

/**
 * REST response whose body is a file streamed by DownloadController::serve_file().
 */
final class FileResponse extends WP_REST_Response {

	/**
	 * Constructor.
	 *
	 * @param string $path     Absolute file path.
	 * @param string $filename Download file name.
	 * @param string $type     MIME type.
	 */
	public function __construct( public readonly string $path, string $filename, string $type ) {
		parent::__construct( null, 200 );

		$this->set_headers(
			array(
				'Content-Type'           => $type,
				'Content-Disposition'    => 'attachment; filename="' . $filename . '"',
				'Content-Length'         => (string) filesize( $path ),
				'Cache-Control'          => 'private, no-store',
				'X-Content-Type-Options' => 'nosniff',
			)
		);
	}
}
