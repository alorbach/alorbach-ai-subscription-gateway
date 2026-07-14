<?php
/**
 * Legacy Local Codex provider compatibility alias.
 *
 * @package Alorbach\AIGateway\Providers
 */

namespace Alorbach\AIGateway\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @deprecated Use AI_Bridge_Provider.
 */
class Codex_Local_Provider extends AI_Bridge_Provider {
	public function get_type() {
		return 'codex_local';
	}
}
