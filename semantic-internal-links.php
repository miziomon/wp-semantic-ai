<?php
/**
 * Plugin Name:       Semantic Internal Links
 * Plugin URI:        https://mavida.com
 * Description:       AI-powered semantic internal link suggestions for the Gutenberg editor.
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Maurizio — MAVIDA
 * Author URI:        https://mavida.com
 * Text Domain:       semantic-internal-links
 * Domain Path:       /languages
 *
 * @package Mavida\SemanticInternalLinks
 */

declare( strict_types=1 );

// Blocca accesso diretto.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Costanti di plugin.
define( 'SIL_VERSION', '0.1.0' );
define( 'SIL_PLUGIN_FILE', __FILE__ );
define( 'SIL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SIL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Autoload Composer (PSR-4).
require_once SIL_PLUGIN_DIR . 'vendor/autoload.php';

// Bootstrap del plugin.
\Mavida\SemanticInternalLinks\Plugin::instance()->boot();
