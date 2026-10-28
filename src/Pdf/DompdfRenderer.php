<?php
/**
 * Dompdf based PDF renderer.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Renders PDFs with dompdf. Remote resources and embedded PHP are disabled.
 */
final class DompdfRenderer implements PdfRenderer {

	/**
	 * Renders HTML to PDF.
	 *
	 * @param string $html Complete HTML document.
	 * @return string PDF file contents.
	 */
	public function render( string $html ): string {
		$options = new Options();
		$options->setIsRemoteEnabled( false );
		$options->setIsPhpEnabled( false );
		$options->setIsJavascriptEnabled( false );
		$options->setDefaultFont( 'DejaVu Sans' );
		$options->setTempDir( get_temp_dir() );

		$dompdf = new Dompdf( $options );
		$dompdf->loadHtml( $html, 'UTF-8' );
		$dompdf->setPaper( 'A4' );
		$dompdf->render();

		return (string) $dompdf->output();
	}
}
