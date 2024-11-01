<?php /** @noinspection SqlResolve */

/*This File is for managing all settings of the Arcavis in the admin area.*/

class WooCommerce_Arcavis_Shop_Admin_Settings {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'arcavis_setting' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'arcavis_setting_scripts_admin' ) );

	}

	public function arcavis_setting_scripts_admin() {
		wp_enqueue_style( 'aracavis_style', plugin_dir_url( __FILE__ ) . '../css/arcavis.css' );
		wp_enqueue_script( 'aracavis_script', plugin_dir_url( __FILE__ ) . '../js/script.js' );
	}

	public function arcavis_setting() {
		global $wc_arcavis_shop;
		add_options_page( $wc_arcavis_shop->text_settings, $wc_arcavis_shop->text_settings, 'manage_options', 'arcavis_settings', array(
			$this,
			'arcavis_setting_page'
		) );
	}

	public function arcavis_setting_page() {
		global $wc_arcavis_shop;
		global $wpdb;
		$setting_option = $this->get_arcavis_settings();
		?>
        <script type="text/javascript">
            const website_url = '<?php echo site_url(); ?>';
        </script>

        <div id="arcavis_preloader">
            <div>
                <h3><?php echo $wc_arcavis_shop->text_syncstarted; ?></h3>
                <h4><?php echo $wc_arcavis_shop->text_dontreload; ?></h4>
                <img src="<?php echo site_url( 'wp-content/plugins/wc-arcavis-shop/includes/images/Spinner.gif' ); ?>" alt="loading...">
            </div>
        </div>
        <div class="arcavis_setting_page">
            <div class="row">
                <img src='https://www.arcavis.ch/wp-content/uploads/2018/06/Arcavis_bg-trans_Logo_1000px.png'
                     width="200" alt="Arcavis logo">
            </div>
            <div class="postbox-container">
                <div class="header"><?php echo $wc_arcavis_shop->text_settingspage_credentials; ?></div>
                <div class="content">
					<?php
					if ( isset( $_POST["arcavis_link"] ) ) {

						$arcavis_settings = array(
							'arcavis_link'          => rtrim( $_POST["arcavis_link"], '/' ),
							'arcavis_username'      => $_POST["arcavis_username"],
							'arcavis_password'      => $_POST["arcavis_password"],
							'arcavis_sync_interval' => $_POST["arcavis_sync_interval"]
						);
						update_option( 'arcavis_settings', serialize( $arcavis_settings ), true );

						?>
                        <div class="notice notice-success is-dismissible">
                            <p><?php echo $wc_arcavis_shop->text_settingssaved; ?></p>

							<?php
							if ( rtrim( $_POST["arcavis_link"], '/' ) != $setting_option['arcavis_link'] || $_POST["arcavis_username"] != $setting_option['arcavis_username'] ) {
								// Something changed, run complete sync
								?>
                                <script type="text/javascript">
                                    startSync();
                                </script>
								<?php

							} else {
								// Only trigger update manually
								$wc_arcavis_shop->arcavis_start_update_sync();
							}
							?>

                        </div>
						<?php
					}

					?>
					<?php $arcavis_settings = $this->get_arcavis_settings(); ?>
                    <form method="post" action="">

                        <table>

                            <tr>
                                <th scope="row"><label
                                            for="arcavis_link"><?php echo $wc_arcavis_shop->text_settingspage_url; ?></label>
                                </th>
                                <td><input type="url" id="arcavis_link" name="arcavis_link" required="required"
                                           placeholder='<?php echo $wc_arcavis_shop->text_settingspage_urlplaceholder; ?>'
                                           value="<?php echo isset( $arcavis_settings['arcavis_link'] ) ? $arcavis_settings['arcavis_link'] : ''; ?>"/>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><label
                                            for="arcavis_username"><?php echo $wc_arcavis_shop->text_settingspage_username; ?></label>
                                </th>
                                <td><input type="text" id="arcavis_username" name="arcavis_username" required="required"
                                           value="<?php echo isset( $arcavis_settings['arcavis_username'] ) ? $arcavis_settings['arcavis_username'] : ''; ?>"/>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><label
                                            for="arcavis_password"><?php echo $wc_arcavis_shop->text_settingspage_password; ?></label>
                                </th>
                                <td><input type="password" id="arcavis_password" name="arcavis_password"
                                           required="required"
                                           value="<?php echo isset( $arcavis_settings['arcavis_password'] ) ? $arcavis_settings['arcavis_password'] : ''; ?>"/>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><label
                                            for="arcavis_sync_interval"><?php echo $wc_arcavis_shop->text_settingspage_interval; ?></label>
                                </th>
                                <td><input type="number" id="arcavis_sync_interval" name="arcavis_sync_interval" min="5"
                                           max="60" required="required"
                                           value="<?php echo ( $arcavis_settings['arcavis_sync_interval'] == '' && isset( $arcavis_settings['arcavis_sync_interval'] ) ) ? 60 : $arcavis_settings['arcavis_sync_interval']; ?>"/>
                                    min
                                </td>
                            </tr>
                        </table>
                        <div class="row" style="margin-top:20px">
                            <input name="submit" id="submit" class="button button-primary"
                                   value="<?php echo $wc_arcavis_shop->text_settingspage_savebutton; ?>" type="submit">
                        </div>
                        <div class="row" style="margin-top:20px">
                            <form action="" method="post">
                                <input type="button" name=""
                                       value="<?php echo $wc_arcavis_shop->text_settingspage_reloadbutton; ?>"
                                       class="button arcavis_danger" id="start_sync">
                            </form>
                        </div>
						<?php //submit_button();
						?>
                    </form>
                </div>
            </div>
            <div class="postbox-container">
                <div class="header">Log</div>
                <div class="content">
                    <form action="" method="post">
						<?php
						$logs = $wpdb->get_results( "SELECT date, level, message FROM " . $wpdb->prefix . "arcavis_logs ORDER BY date DESC" );
						if ( ! empty( $logs ) ) {
							foreach ( $logs as $log ) {
								echo "<p>" . $log->date . " [" . $log->level . "] " . $log->message . "</p>";
							}
						}
						?>
                    </form>
                </div>
            </div>
        </div>
		<?php
	}

	public function get_arcavis_settings() {
		$arcavis_settings = get_option( 'arcavis_settings' );

		return unserialize( $arcavis_settings );
	}


}

?>
