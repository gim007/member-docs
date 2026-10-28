<?php
/**
 * Stale version error.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Service;

use RuntimeException;

/**
 * Thrown when a version that is no longer current (or belongs to an inactive template) is accepted.
 */
final class StaleVersionException extends RuntimeException {
}
