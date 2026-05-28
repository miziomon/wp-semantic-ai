<?php
/**
 * Plugin Name:       Semantic AI
 * Plugin URI:        https://mavida.com
 * Description:       AI-powered semantic internal link suggestions for the Gutenberg editor.
 * Version:           0.2.7
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Maurizio — MAVIDA
 * Author URI:        https://mavida.com
 * Text Domain:       semantic-ai
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
define( 'SAI_VERSION', '0.2.7' );
define( 'SAI_PLUGIN_FILE', __FILE__ );
define( 'SAI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SAI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Autoloader PSR-4 nativo — non richiede vendor/ in produzione.
spl_autoload_register(
	function ( string $classname ): void {
		$prefix   = 'Mavida\\SemanticInternalLinks\\';
		$base_dir = SAI_PLUGIN_DIR . 'src/';

		if ( strncmp( $prefix, $classname, strlen( $prefix ) ) !== 0 ) {
			return;
		}

		$relative = substr( $classname, strlen( $prefix ) );
		$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

// Bootstrap del plugin.
\Mavida\SemanticInternalLinks\Plugin::instance()->boot();
