<?php
/**
 * Plugin Name: Easy digital downloads ePayco
 * Description: Plugin de pago ePayco para Easy Digital Downloads
 * Version: 1.0.0
 * Author: Saul Morales Pacheco
 * Author URI: https://saulmoralespa.com
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if(!defined('EASY_DIGITAL_DOWNLOADS_VERSION')){
    define('EASY_DIGITAL_DOWNLOADS_VERSION', '1.0.0');
}

add_action('plugins_loaded','easy_digital_downloads_init');

function easy_digital_downloads_init(){
    if(!easy_digital_downloads_requirements()) return;
    easy_digital_downloads()->run_epayco();
}

function easy_digital_downloads_notices( $notice ) {
    ?>
    <div class="error notice is-dismissible">
        <p><?php echo $notice; ?></p>
    </div>
    <?php
}

function easy_digital_downloads_requirements(){

    if (!class_exists('Easy_Digital_Downloads')){
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            add_action(
                'admin_notices',
                function() {
                    easy_digital_downloads_notices('Easy digital downloads ePayco: Requiere que se encuentre instalado y activo Easy Digital Downloads');
                }
            );
        }
        return false;
    }

    return true;
}

function easy_digital_downloads(){
    static $plugin;
    if (!isset($plugin)){
        require_once ('includes/class-easy-digital-downloads-epayco-plugin.php');
        $plugin = new Easy_Digital_Downloads_Epayco_Plugin(__FILE__, EASY_DIGITAL_DOWNLOADS_VERSION);
    }
    return $plugin;
}