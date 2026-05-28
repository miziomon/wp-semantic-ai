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

// Costanti del plugin (definite in semantic-ai.php a runtime).
define( 'SAI_VERSION', '0.1.0' );
define( 'SAI_PLUGIN_FILE', __DIR__ . '/semantic-ai.php' );
// Usa solo slash forward per evitare problemi di separatori su Windows con PHPStan.
define( 'SAI_PLUGIN_DIR', str_replace( '\\', '/', __DIR__ ) . '/' );
define( 'SAI_PLUGIN_URL', 'http://localhost/' );

/**
 * Stub del WP AI Client (WordPress 7.0).
 * Il pacchetto php-stubs/wordpress-stubs è ancora alla v6.9.x e non include
 * le API del WP AI Client — le definiamo qui per l'analisi statica.
 *
 * Questa classe viene sostituita dall'implementazione reale a runtime.
 */
if ( ! class_exists( 'WP_AI_Client_Prompt_Builder' ) ) {
	class WP_AI_Client_Prompt_Builder {
		/** @return static */
		public function using_system_instruction( string $instruction ): static {
			return $this;
		}
		/** @return static */
		public function using_temperature( float $temperature ): static {
			return $this;
		}
		/** @return static */
		public function using_max_tokens( int $max_tokens ): static {
			return $this;
		}
		/** @return static */
		public function using_model_preference( string ...$models ): static {
			return $this;
		}
		/** @param array<string, mixed> $schema @return static */
		public function as_json_response( array $schema ): static {
			return $this;
		}
		public function is_supported_for_text_generation(): bool {
			return false;
		}
		/** @return string|\WP_Error */
		public function generate_text(): string|\WP_Error {
			return '';
		}
		/** @return \stdClass|\WP_Error */
		public function generate_text_result(): \stdClass|\WP_Error {
			return new \stdClass();
		}
	}
}

if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
	/**
	 * Entry-point del WP AI Client nativo (WP 7.0).
	 *
	 * @param string $prompt_text Testo del prompt utente.
	 * @return WP_AI_Client_Prompt_Builder|\WP_Error Builder fluente o WP_Error.
	 */
	function wp_ai_client_prompt( string $prompt_text ): WP_AI_Client_Prompt_Builder|\WP_Error {
		return new WP_AI_Client_Prompt_Builder();
	}
}
