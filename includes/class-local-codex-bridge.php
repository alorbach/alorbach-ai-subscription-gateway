<?php
/**
 * Legacy Local Codex compatibility alias.
 *
 * @package Alorbach\AIGateway
 */

namespace Alorbach\AIGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @deprecated Use AI_Bridge.
 */
class Local_Codex_Bridge extends AI_Bridge {}
