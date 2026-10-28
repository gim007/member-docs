<?php
/**
 * Custom capabilities.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs;

/**
 * Capability names used by the plugin. Granted to administrators on activation.
 */
final class Capabilities {

	public const MANAGE_TEMPLATES = 'md_manage_templates';
	public const VIEW_ACCEPTANCES = 'md_view_acceptances';

	public const ALL = array( self::MANAGE_TEMPLATES, self::VIEW_ACCEPTANCES );
}
