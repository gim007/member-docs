<?php
/**
 * PDF renderer contract.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Pdf;

/**
 * Converts an HTML document into PDF bytes.
 *
 * An interface because the PDF engine is the piece most likely to be swapped
 * (e.g. for a hosted rendering service) and tests replace it with a fake.
 */
interface PdfRenderer {

	/**
	 * Renders HTML to PDF.
	 *
	 * @param string $html Complete HTML document.
	 * @return string PDF file contents.
	 */
	public function render( string $html ): string;
}
