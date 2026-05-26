<?php
/**
 * Bootstrap per PHPStan: definisce le costanti del plugin e quelle WordPress
 * non presenti negli stubs, così l'analisi statica può trovare i simboli.
 *
 * Non viene caricato a runtime — solo durante l'analisi PHPStan.
 *
 * @package Mavida\SemanticInternalLinks
 */

declare( strict_types=1 );

// Costanti del plugin (definite in semantic-internal-links.php a runtime).
define( 'SIL_VERSION', '0.1.0' );
define( 'SIL_PLUGIN_FILE', __DIR__ . '/semantic-internal-links.php' );
// Usa solo slash forward per evitare problemi di separatori su Windows con PHPStan.
define( 'SIL_PLUGIN_DIR', str_replace( '\\', '/', __DIR__ ) . '/' );
define( 'SIL_PLUGIN_URL', 'http://localhost/' );
