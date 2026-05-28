<?php
/**
 * Classe principale del plugin: registra tutti gli hook WordPress.
 *
 * @package Mavida\SemanticInternalLinks
 */

declare( strict_types=1 );

namespace Mavida\SemanticInternalLinks;

/**
 * Plugin entry-point. Usa il pattern singleton leggero: una sola istanza
 * viene creata da semantic-ai.php tramite instance()->boot().
 */
final class Plugin {

	/**
	 * Istanza singleton del plugin.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/** Impedisce istanziazione diretta. */
	private function __construct() {}

	/** Restituisce l'istanza singleton. */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registra tutti gli hook WordPress.
	 * Chiamato una sola volta dal file principale del plugin.
	 */
	public function boot(): void {
		add_action( 'init', [ $this, 'load_text_domain' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_action( 'admin_menu', [ $this, 'register_settings_page' ] );
		// Invalida la cache AI quando il post viene salvato.
		add_action( 'save_post', [ $this, 'invalidate_cache_on_save' ] );
		// Aggiornamenti automatici tramite GitHub Releases.
		( new \Mavida\SemanticInternalLinks\Updater() )->register();
	}

	/**
	 * Invalida la cache dei suggerimenti AI per il post appena salvato.
	 *
	 * @param int $post_id ID del post salvato.
	 */
	public function invalidate_cache_on_save( int $post_id ): void {
		// Salta le revisioni automatiche.
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$cache = new \Mavida\SemanticInternalLinks\Ai\SuggestionCache();
		$cache->invalidate_for_post( $post_id );
	}

	/** Carica le traduzioni del plugin. */
	public function load_text_domain(): void {
		load_plugin_textdomain(
			'semantic-ai',
			false,
			dirname( plugin_basename( SAI_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Accoda lo script dell'editor Gutenberg.
	 * Legge deps e version da build/index.asset.php generato da @wordpress/scripts.
	 */
	public function enqueue_editor_assets(): void {
		$asset_file = SAI_PLUGIN_DIR . 'build/index.asset.php';

		// Il file asset viene generato da "npm run build": non disponibile in sviluppo
		// finché non si esegue almeno una build.
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		/* @var array{dependencies: string[], version: string} $asset */
		$asset = require $asset_file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable

		wp_enqueue_script(
			'semantic-ai-editor',
			SAI_PLUGIN_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			false
		);

		wp_set_script_translations(
			'semantic-ai-editor',
			'semantic-ai',
			SAI_PLUGIN_DIR . 'languages'
		);

		// Passa al JS i dati di bootstrap (flag provider, nonce REST).
		wp_localize_script(
			'semantic-ai-editor',
			'silData',
			[
				'restUrl' => esc_url_raw( rest_url( 'semantic-ai/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			]
		);

		wp_enqueue_style(
			'semantic-ai-editor',
			SAI_PLUGIN_URL . 'build/editor.scss.css',
			[],
			$asset['version']
		);
	}

	/** Registra le route REST tramite SuggestController. */
	public function register_rest_routes(): void {
		$controller = new \Mavida\SemanticInternalLinks\Rest\SuggestController(
			new \Mavida\SemanticInternalLinks\Ai\LinkSuggester(
				new \Mavida\SemanticInternalLinks\Ai\PromptBuilder(),
				new \Mavida\SemanticInternalLinks\Ai\SuggestionCache(),
				new \Mavida\SemanticInternalLinks\Ai\ResponseValidator()
			),
			new \Mavida\SemanticInternalLinks\Content\CandidateProvider(
				new \Mavida\SemanticInternalLinks\Content\KeywordExtractor()
			)
		);

		$controller->register();
	}

	/** Registra la pagina delle impostazioni tramite SettingsPage. */
	public function register_settings_page(): void {
		$settings_page = new \Mavida\SemanticInternalLinks\Settings\SettingsPage();
		$settings_page->register();
	}

	/**
	 * Helper per leggere un'opzione del plugin con il suo default.
	 *
	 * @param string $key Chiave dell'opzione (senza prefisso).
	 * @return mixed
	 */
	public static function get_option( string $key ): mixed {
		$defaults = [
			'max_candidates'        => 50,
			'max_links'             => 8,
			'max_emphasis'          => 10,
			'chunk_threshold_chars' => 20000,
			'target_post_types'     => [ 'post', 'page' ],
			'cache_ttl'             => DAY_IN_SECONDS,
			'model_preferences'     => [ 'claude-sonnet-4-6', 'gemini-3.5-flash', 'gpt-4.1' ],
		];

		$value = get_option( 'sai_' . $key, $defaults[ $key ] ?? null );

		return $value;
	}
}
