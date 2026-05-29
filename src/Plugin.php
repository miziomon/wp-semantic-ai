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
		// Link rapidi nell'elenco plugin.
		add_filter( 'plugin_action_links_' . plugin_basename( SAI_PLUGIN_FILE ), [ $this, 'add_action_links' ] );
		add_filter( 'plugin_row_meta', [ $this, 'add_row_meta' ], 10, 2 );
		// Test AJAX connessione AI dalla pagina impostazioni.
		add_action( 'wp_ajax_sai_test_ai_connection', [ $this, 'handle_test_ai_connection' ] );
		// Log analisi.
		add_action( 'wp_ajax_sai_reset_instruction', [ $this, 'handle_reset_instruction' ] );
		add_action( 'wp_ajax_sai_clear_log', [ $this, 'handle_clear_log' ] );
		add_action( 'wp_ajax_sai_get_log_result', [ $this, 'handle_get_log_result' ] );
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

	/** Registra le route REST tramite SuggestController e PrepareController. */
	public function register_rest_routes(): void {
		$candidate_provider = new \Mavida\SemanticInternalLinks\Content\CandidateProvider(
			new \Mavida\SemanticInternalLinks\Content\KeywordExtractor()
		);

		$suggest_controller = new \Mavida\SemanticInternalLinks\Rest\SuggestController(
			new \Mavida\SemanticInternalLinks\Ai\LinkSuggester(
				new \Mavida\SemanticInternalLinks\Ai\PromptBuilder(),
				new \Mavida\SemanticInternalLinks\Ai\SuggestionCache(),
				new \Mavida\SemanticInternalLinks\Ai\ResponseValidator()
			),
			$candidate_provider
		);

		$prepare_controller = new \Mavida\SemanticInternalLinks\Rest\PrepareController(
			$candidate_provider
		);

		$suggest_controller->register();
		$prepare_controller->register();
	}

	/** Registra la pagina delle impostazioni tramite SettingsPage. */
	public function register_settings_page(): void {
		$settings_page = new \Mavida\SemanticInternalLinks\Settings\SettingsPage();
		$settings_page->register();
	}

	/**
	 * Aggiunge il link "Impostazioni" nella colonna azioni dell'elenco plugin.
	 *
	 * @param string[] $links Link di azione correnti.
	 * @return string[] Link aggiornati.
	 */
	public function add_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=' . \Mavida\SemanticInternalLinks\Settings\SettingsPage::MENU_SLUG ) ),
			esc_html__( 'Impostazioni', 'semantic-ai' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Aggiunge il link "GitHub" nella riga meta dell'elenco plugin.
	 *
	 * @param string[] $links  Link meta correnti.
	 * @param string   $plugin Path del plugin corrente.
	 * @return string[] Link aggiornati.
	 */
	public function add_row_meta( array $links, string $plugin ): array {
		if ( plugin_basename( SAI_PLUGIN_FILE ) !== $plugin ) {
			return $links;
		}
		$links[] = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( 'https://github.com/miziomon/wp-semantic-ai' ),
			esc_html__( 'GitHub', 'semantic-ai' )
		);
		return $links;
	}

	/** Ripristina la system instruction personalizzata eliminando l'opzione salvata. */
	public function handle_reset_instruction(): void {
		check_ajax_referer( 'sai_reset_instruction', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Non autorizzato.', 'semantic-ai' ) );
		}

		delete_option( 'sai_custom_system_instruction' );
		wp_send_json_success();
	}

	/** Svuota il log delle analisi. */
	public function handle_clear_log(): void {
		check_ajax_referer( 'sai_clear_log', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Non autorizzato.', 'semantic-ai' ) );
		}

		$log = new \Mavida\SemanticInternalLinks\Ai\AnalysisLog();
		$log->clear();
		wp_send_json_success();
	}

	/** Restituisce il risultato completo di una voce del log. */
	public function handle_get_log_result(): void {
		check_ajax_referer( 'sai_get_log_result', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Non autorizzato.', 'semantic-ai' ) );
		}

		$id     = sanitize_text_field( wp_unslash( (string) ( $_POST['id'] ?? '' ) ) );
		$log    = new \Mavida\SemanticInternalLinks\Ai\AnalysisLog();
		$result = $log->get_result( $id );

		if ( null === $result ) {
			wp_send_json_error( __( 'Risultato non trovato.', 'semantic-ai' ) );
		}

		wp_send_json_success( $result );
	}

	/** Gestisce il test AJAX della connessione al provider AI configurato. */
	public function handle_test_ai_connection(): void {
		check_ajax_referer( 'sai_test_ai', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Non autorizzato.', 'semantic-ai' ) );
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			wp_send_json_error( __( 'WP AI Client non disponibile. Richiede WordPress 7.0+.', 'semantic-ai' ) );
		}

		$builder = wp_ai_client_prompt( 'Reply with the single word OK and nothing else.' );

		if ( is_wp_error( $builder ) ) {
			wp_send_json_error( $builder->get_error_message() );
		}

		if ( ! $builder->is_supported_for_text_generation() ) {
			wp_send_json_error( __( 'Nessun provider AI configurato. Vai in Impostazioni → Connettori AI.', 'semantic-ai' ) );
		}

		$raw_prefs = self::get_option( 'model_preferences' );
		/* @var string[] $model_prefs */
		$model_prefs = ( is_array( $raw_prefs ) && count( $raw_prefs ) > 0 )
			? array_values( array_map( 'strval', $raw_prefs ) )
			: [ 'claude-sonnet-4-6', 'gemini-3.5-flash', 'gpt-4.1' ];

		$builder->using_model_preference( ...$model_prefs );

		$result = $builder->generate_text();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success(
			[
				'message'  => __( 'Connessione AI funzionante.', 'semantic-ai' ),
				'response' => sanitize_text_field( (string) $result ),
			]
		);
	}

	/**
	 * Helper per leggere un'opzione del plugin con il suo default.
	 *
	 * @param string $key Chiave dell'opzione (senza prefisso).
	 * @return mixed
	 */
	public static function get_option( string $key ): mixed {
		$defaults = [
			'max_candidates'            => 50,
			'max_links'                 => 8,
			'max_emphasis'              => 10,
			'chunk_threshold_chars'     => 20000,
			'target_post_types'         => [ 'post', 'page' ],
			'cache_ttl'                 => DAY_IN_SECONDS,
			'model_preferences'         => [ 'claude-sonnet-4-6', 'gemini-3.5-flash', 'gpt-4.1' ],
			'update_check_interval'     => 4,
			'ai_request_timeout'        => 120,
			'custom_system_instruction' => '',
		];

		$value = get_option( 'sai_' . $key, $defaults[ $key ] ?? null );

		return $value;
	}
}
