<?php /** @noinspection SqlResolve */
/**
 * Plugin Name:    Woocommerce Arcavis Shop
 * Plugin URI:        http://www.arcavis.ch
 * Description:    Enable Arcavis shop in woocommerce.
 * Version:        1.1.5
 * Author:            Sequens IT
 * Author URI:        http://www.arcavis.ch
 * Developer:        SD
 * Developer URI:    http://www.arcavis.ch
 * Contributors:
 * Text Domain:    wc-arcavis-shop
 * Domain Path:        /languages
 *
 * Copyright:
 * License:        GNU General Public License v3.0
 * License URI:    http://www.gnu.org/licenses/gpl-3.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
/*if ( !is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
  return;
}*/
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	return;
}

class WC_Arcavis_Shop {

	/**
	 * @var WC_Arcavis_Shop - the single instance of the class
	 * @since 1.0.3
	 */

	protected static $_instance;

	public $settings_obj;
	public $sync;
	public $transaction_response;

	/* Texts */
	public $text_discount = 'Rabatt';
	public $text_voucher = 'Gutschein';
	public $text_settings = 'Arcavis Einstellungen';
	public $text_entervoucher = 'Gutschein';
	public $text_entervoucherplaceholder = 'Gutschein-Nummer';
	public $text_syncstarted = 'Daten werden synchronisiert. Dies wird mehrere Minuten dauern...';
	public $text_dontreload = 'Bitte Seite nicht neu laden';
	public $text_settingssaved = 'Einstellungen gespeichert';
	public $text_settingspage_credentials = 'Einstellungen für die Verbindung mit Arcavis';
	public $text_settingspage_savebutton = 'Speichern und Synchronisation starten';
	public $text_settingspage_reloadbutton = 'Alles löschen und neu synchronisieren';
	public $text_settingspage_url = 'Arcavis Installations-URL';
	public $text_settingspage_urlplaceholder = 'https://test.arcavis.ch';
	public $text_settingspage_username = 'Benutzername';
	public $text_settingspage_password = 'Kennwort';
	public $text_settingspage_interval = 'Synchronisationsintervall';
	public $text_settingspage_syncwarning = 'Achtung! Alle Artikel und Bestellungen werden gelöscht. Fortfahren?';

public function __construct() {
		$this->define_constant();
		$this->load_required_files();
		add_action( 'init', array( $this, 'init' ) );
		$plugin = plugin_basename( __FILE__ );
		add_filter( "plugin_action_links_$plugin", array( $this, 'arcavis_add_settings_link' ) );
		add_filter( 'cron_schedules', array( $this, 'arcavis_add_cron_recurrence_interval' ) );
		add_filter( 'woocommerce_order_button_html',array($this, 'check_arcavis_transaction'));
		register_activation_hook( __FILE__, array( $this, 'activation_tasks' ) );
		add_action( 'wp_ajax_arcavis_start_initial_sync', array( $this, 'arcavis_start_initial_sync' ) );
		add_action( 'wp_ajax_nopriv_arcavis_start_initial_sync', array( $this, 'arcavis_start_initial_sync' ) );
		add_action( 'arcavis_schedule_api_hook', array( $this, 'arcavis_start_update_sync' ) );
		add_action( 'plugins_loaded', 'add_arcavis_gateway' );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate_tasks' ) );

		// Locale English
		if ( strpos( get_locale(), 'en' ) == 0 ) {
			$this->text_discount = 'Discount';
			$this->text_voucher  = 'Voucher';
		}
	}

		private function define_constant() {
		define( 'WC_AS_PATH', plugin_dir_path( __FILE__ ) );
		define( 'WC_AS_INC', WC_AS_PATH . 'includes/' );
		define( 'WC_AS_INC_ADMIN', WC_AS_PATH . 'includes/admin/' );


	}// end of the construct

	private function load_required_files() {
		$this->load_files( WC_AS_INC_ADMIN . 'shop-setting.php' );
		$this->load_files( WC_AS_INC_ADMIN . 'create-products.php' );
		$this->load_files( WC_AS_INC_ADMIN . 'payment-gateway.php' );
		$this->load_files( WC_AS_INC . 'arcavis-transaction.php' );

	}

	public function load_files( $path, $type = 'require' ) {
		foreach ( glob( $path ) as $files ) {
			if ( $type == 'require' ) {
				require_once( $files );
			} else if ( $type == 'include' ) {
				include_once( $files );
			}

		}
	}

	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	## This function will run at the time of plugin activation set up cron, custom database tables..

	public function init() {
		$this->init_class();
		//print_r(wp_get_schedules());
	}

	## Function will call at deactivation of plugin.

	private function init_class() {
		$this->settings_obj = new WooCommerce_Arcavis_Shop_Admin_Settings;
		$this->sync         = new WooCommerce_Arcavis_Create_Products_Settings;
		$this->transaction  = new WooCommerce_Arcavis_Transaction;
	}

	## This function Schedule a cron for give time interval from setting in admin.

	public function activation_tasks() {


		if ( ! wp_next_scheduled( 'arcavis_schedule_api_hook' ) ) {
			wp_schedule_event( time(), 'arcavis_minutes', 'arcavis_schedule_api_hook' );
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table_name = $wpdb->prefix . 'lastSyncTicks';

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			apiName varchar(255) NOT NULL,
			lastSync bigint(20) NOT NULL,
			updated timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL,
			UNIQUE KEY id (id)
		) $charset_collate;";


		dbDelta( $sql );
		$checkSyncApi = $wpdb->get_results( "SELECT * FROM " . $table_name . " WHERE apiName = 'articles' OR apiName = 'vouchers' OR apiName = 'articlestocks'", OBJECT );
		if ( empty( $checkSyncApi ) ) {
			$wpdb->insert( $table_name, array( 'apiName' => 'articles', 'lastSync' => '', 'updated' => '' ) );
			$wpdb->insert( $table_name, array( 'apiName' => 'vouchers', 'lastSync' => '', 'updated' => '' ) );
			$wpdb->insert( $table_name, array( 'apiName' => 'articlestocks', 'lastSync' => '', 'updated' => '' ) );
		}


		$table_name2 = $wpdb->prefix . 'lastSyncPage';

		$sql2 = "CREATE TABLE $table_name2 (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			lastPage int(11) NOT NULL,
			UNIQUE KEY id (id)
		) $charset_collate;";


		dbDelta( $sql2 );
		$checkSyncPage = $wpdb->get_results( "SELECT * FROM " . $table_name2, OBJECT );
		if ( empty( $checkSyncPage ) ) {
			$wpdb->insert( $table_name2, array( 'lastPage' => '1' ) );
		}

		$table_name3 = $wpdb->prefix . 'applied_vouchers';

		$sql3 = "CREATE TABLE $table_name3 (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			session_id varchar(255) NOT NULL,
			voucher_code varchar(255) NOT NULL,
			discount_amount varchar(255) NOT NULL,
			discount_type varchar(255) NOT NULL,
			transaction_response longtext NOT NULL,
			UNIQUE KEY id (id)
		) $charset_collate;";


		dbDelta( $sql3 );


		// Logger
		$table_name4 = $wpdb->prefix . 'arcavis_logs';

		$sql4 = "CREATE TABLE $table_name4 (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			date datetime NOT NULL,
			level varchar(10) NOT NULL,
			message longtext NULL,
			UNIQUE KEY id (id)
		) $charset_collate;";

		dbDelta( $sql4 );
	}

	public function deactivate_tasks() {
		wp_clear_scheduled_hook( 'arcavis_schedule_api_hook' );
	}

	public function arcavis_add_cron_recurrence_interval( $schedules ) {
		$arcavis_settings = get_option( 'arcavis_settings' );
		$arcavis_options  = unserialize( $arcavis_settings );

		if ( isset( $arcavis_options['arcavis_link'] ) ) {
			$schedules['arcavis_minutes'] = array(
				'interval' => $arcavis_options['arcavis_sync_interval'] * 60,
				'display'  => 'Arcavis Minutes',
			);
		}

		$schedules['arcavis_initial_sync'] = array(
			'interval' => 3 * 60,
			'display'  => 'Arcavis Initial Sync',
		);

		return $schedules;
	}

	public function arcavis_start_initial_sync() {
		global $wc_arcavis_shop;
		$wc_arcavis_shop->logDebug( '**arcavis_start_initial_sync' );
		$this->sync->create_products_init();

	}

	public function arcavis_start_update_sync() {
		global $wc_arcavis_shop;
		$this->init_class();
		$wc_arcavis_shop->logDebug( '**arcavis_start_update_sync' );
		$this->sync->update_products();
	}

	public function arcavis_add_settings_link( $links ) {
		$settings_link = '<a href="options-general.php?page=arcavis_settings">' . __( 'Settings' ) . '</a>';
		array_push( $links, $settings_link );

		return $links;
	}
    public function check_arcavis_transaction($orderButton){
        $proceed = WC()->session->get('arcavis_ok');
        return $proceed ? $orderButton : '';
    }
	public function logDebug( $msg ) {
		$this->logMessage( 'DEBUG', $msg );
	}

	private function logMessage( $level, $msg ) {
		global $wpdb;
		if ( WP_DEBUG === true || $level != 'DEBUG' ) {
			$table_name = $wpdb->prefix . 'arcavis_logs';
			$date       = date( 'Y-m-d H:i:s' );
			$wpdb->insert( $table_name, array( 'date' => $date, 'level' => $level, 'message' => $msg ) );
		}
	}

	public function logError( $msg ) {
		$this->logMessage( 'ERROR', $msg );
	}

	public function is_request( $type ) {
		switch ( $type ) {
			case 'admin' :
				return is_admin();
			case 'ajax' :
				return defined( 'DOING_AJAX' );
			case 'cron' :
				return defined( 'DOING_CRON' );
			case 'frontend' :
				return ( ! is_admin() || defined( 'DOING_AJAX' ) ) && ! defined( 'DOING_CRON' );
			default:
				return false;
		}
	}

	public function get_last_sync( $api ) {
		global $wpdb;
		$lastSync = '';
		$result   = $wpdb->get_row( "SELECT * FROM " . $wpdb->prefix . "lastSyncTicks WHERE apiName='" . $api . "'", OBJECT );
		if ( ! empty( $result ) ) {
			if ( $result->lastSync == 0 ) {
				$lastSync = '';
			} else {
				$lastSync = $result->lastSync;
			}

		}

		return $lastSync;
	}

	public function get_last_page() {
		global $wpdb;
		$lastSync = '';
		$result   = $wpdb->get_row( "SELECT * FROM " . $wpdb->prefix . "lastSyncPage ORDER BY id DESC LIMIT 0,1", OBJECT );
		if ( ! empty( $result ) ) {

			$lastSync = $result->lastPage;

		}

		return $lastSync;
	}

}// end of class

function WC_Arcavis_Shop() {

	return WC_Arcavis_Shop::instance();

}

// Launch the whole plugin
$GLOBALS['wc_arcavis_shop'] = WC_Arcavis_Shop();