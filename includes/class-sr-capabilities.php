<?php
/**
 * A dedicated capability + role so range staff can record results without
 * needing a full Administrator account.
 *
 * @package ShootingResults
 */

defined( 'ABSPATH' ) || exit;

class SR_Capabilities {

	const CAPABILITY = 'sr_manage_results';
	const ROLE       = 'sr_range_staff';

	public static function install() {
		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( self::CAPABILITY ) ) {
			$admin->add_cap( self::CAPABILITY );
		}

		if ( ! get_role( self::ROLE ) ) {
			add_role(
				self::ROLE,
				__( 'Range Staff', 'shooting-results' ),
				array(
					'read'            => true,
					self::CAPABILITY => true,
				)
			);
		}
	}

	public static function current_user_can_manage() {
		return current_user_can( self::CAPABILITY );
	}
}
