<?php
/**
 * Result of saving a template.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Service;

use MemberDocs\Domain\Template;
use MemberDocs\Domain\TemplateVersion;

/**
 * The saved template and the version published by the save, if any.
 */
final class SaveResult {

	/**
	 * Constructor.
	 *
	 * @param Template             $template  Saved template.
	 * @param TemplateVersion|null $published New version, or null when the content was unchanged.
	 */
	public function __construct(
		public readonly Template $template,
		public readonly ?TemplateVersion $published,
	) {}
}
