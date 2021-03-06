<?php
/**
 * Created by PhpStorm.
 * User: manu
 * Date: 04/02/2019
 * Time: 20:28
 */

/**
 * Class WP_LMaker_SAD
 */
class WP_LMaker_SAD extends WP_LMaker_Abstract_Addon {

	public function __construct() {
		parent::__construct();
		add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_sad_users' ), 45 );
	}

	public function enqueue_process_sad_users( $tables ) {
		if ( ! $this->is_plugin_active( 'halfdata-optin-downloads/halfdata-optin-downloads.php' ) ) {
			$tables['sad_users'] = false;
		}
		return $tables;
	}
}

new WP_LMaker_SAD();
