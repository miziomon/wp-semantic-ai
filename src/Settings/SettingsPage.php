<?php
/**
 * Pagina delle impostazioni del plugin tramite WordPress Settings API con sistema di TAB.
 *
 * @package Mavida\SemanticInternalLinks\Settings
 */

declare( strict_types=1 );

namespace Mavida\SemanticInternalLinks\Settings;

use Mavida\SemanticInternalLinks\Ai\AnalysisLog;
use Mavida\SemanticInternalLinks\Plugin;
use Mavida\SemanticInternalLinks\Updater;

/**
 * Registra e renderizza la pagina "Impostazioni → Semantic AI" con 5 TAB.
 *
 * TAB disponibili:
 * - analysis : parametri di analisi + timeout AI
 * - models   : preferenze modelli + diagnostica
 * - prompt   : system instruction personalizzata
 * - updates  : aggiornamenti automatici
 * - log      : log delle analisi eseguite
 */
class SettingsPage {

	/** Slug della pagina di impostazioni. */
	public const MENU_SLUG = 'semantic-ai';

	/** Gruppo di opzioni per register_setting. */
	public const OPTION_GROUP = 'sai_options';

	// Slug interni delle sezioni (usati come "page" in add_settings_section/field).
	private const TAB_ANALYSIS = 'sai_tab_analysis';
	private const TAB_MODELS   = 'sai_tab_models';
	private const TAB_PROMPT   = 'sai_tab_prompt';
	private const TAB_UPDATES  = 'sai_tab_updates';

	// Nomi sezioni all'interno dei tab.
	private const SECTION_ANALYSIS    = 'sai_section_analysis';
	private const SECTION_MODELS      = 'sai_section_models';
	private const SECTION_DIAGNOSTICS = 'sai_section_diagnostics';
	private const SECTION_UPDATES     = 'sai_section_updates';

	/**
	 * Catalogo dei modelli AI disponibili per la selezione.
	 *
	 * @var array<int, array{id: string, label: string, provider: string}>
	 */
	private const MODEL_CATALOG = [
		[
			'id'       => 'claude-opus-4-8',
			'label'    => 'Claude Opus 4.8',
			'provider' => 'Anthropic',
		],
		[
			'id'       => 'claude-sonnet-4-6',
			'label'    => 'Claude Sonnet 4.6',
			'provider' => 'Anthropic',
		],
		[
			'id'       => 'claude-haiku-4-5',
			'label'    => 'Claude Haiku 4.5',
			'provider' => 'Anthropic',
		],
		[
			'id'       => 'gemini-3.5-flash',
			'label'    => 'Gemini 3.5 Flash',
			'provider' => 'Google',
		],
		[
			'id'       => 'gemini-3.1-pro-preview',
			'label'    => 'Gemini 3.1 Pro Preview',
			'provider' => 'Google',
		],
		[
			'id'       => 'gemini-2.5-pro',
			'label'    => 'Gemini 2.5 Pro',
			'provider' => 'Google',
		],
		[
			'id'       => 'gemini-2.5-flash',
			'label'    => 'Gemini 2.5 Flash',
			'provider' => 'Google',
		],
		[
			'id'       => 'gpt-4.1',
			'label'    => 'GPT-4.1',
			'provider' => 'OpenAI',
		],
		[
			'id'       => 'gpt-4.1-mini',
			'label'    => 'GPT-4.1 mini',
			'provider' => 'OpenAI',
		],
		[
			'id'       => 'gpt-4o',
			'label'    => 'GPT-4o',
			'provider' => 'OpenAI',
		],
	];

	/** Preferenze di default se l'opzione non è ancora stata salvata. */
	private const DEFAULT_MODEL_PREFERENCES = [ 'claude-sonnet-4-6', 'gemini-3.5-flash', 'gpt-4.1' ];

	/** Tab validi con le relative etichette. */
	private const TABS = [
		'analysis' => 'Analisi',
		'models'   => 'Modelli AI',
		'prompt'   => 'Prompt',
		'updates'  => 'Aggiornamenti',
		'log'      => 'Log analisi',
	];

	/** Registra la voce di menu e la pagina impostazioni. */
	public function register(): void {
		add_options_page(
			__( 'Semantic AI', 'semantic-ai' ),
			__( 'Semantic AI', 'semantic-ai' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);

		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_footer-settings_page_' . self::MENU_SLUG, [ $this, 'render_admin_scripts' ] );
	}

	/** Registra le opzioni, le sezioni e i campi tramite Settings API. */
	public function register_settings(): void {
		$this->register_all_settings();

		// TAB Analisi.
		add_settings_section(
			self::SECTION_ANALYSIS,
			__( 'Parametri di analisi', 'semantic-ai' ),
			[ $this, 'render_section_analysis_desc' ],
			self::TAB_ANALYSIS
		);

		// TAB Modelli AI (2 sezioni unite).
		add_settings_section(
			self::SECTION_MODELS,
			__( 'Preferenze modelli AI', 'semantic-ai' ),
			[ $this, 'render_section_models_desc' ],
			self::TAB_MODELS
		);

		add_settings_section(
			self::SECTION_DIAGNOSTICS,
			__( 'Diagnostica', 'semantic-ai' ),
			[ $this, 'render_section_diagnostics_desc' ],
			self::TAB_MODELS
		);

		// TAB Aggiornamenti.
		add_settings_section(
			self::SECTION_UPDATES,
			__( 'Aggiornamenti automatici', 'semantic-ai' ),
			[ $this, 'render_section_updates_desc' ],
			self::TAB_UPDATES
		);

		$this->add_all_fields();
	}

	/** Renderizza la pagina di impostazioni con il sistema di TAB. */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$raw_tab    = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'analysis'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tab = array_key_exists( $raw_tab, self::TABS ) ? $raw_tab : 'analysis';
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<nav class="nav-tab-wrapper" style="margin-bottom:0;">
				<?php foreach ( self::TABS as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::MENU_SLUG . '&tab=' . $slug ) ); ?>"
						class="nav-tab<?php echo $active_tab === $slug ? ' nav-tab-active' : ''; ?>">
						<?php echo esc_html__( $label, 'semantic-ai' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php if ( 'log' !== $active_tab ) : ?>
			<form method="post" action="options.php" style="margin-top:20px;">
				<?php
				settings_fields( self::OPTION_GROUP );

				$tab_page_map = [
					'analysis' => self::TAB_ANALYSIS,
					'models'   => self::TAB_MODELS,
					'prompt'   => self::TAB_PROMPT,
					'updates'  => self::TAB_UPDATES,
				];

				$page_slug = $tab_page_map[ $active_tab ];
				do_settings_sections( $page_slug );

				if ( 'prompt' === $active_tab ) {
					$this->render_prompt_tab_extra();
				}

				submit_button( __( 'Salva impostazioni', 'semantic-ai' ) );
				?>
			</form>
			<?php else : ?>
				<div style="margin-top:20px;">
					<?php $this->render_log_tab(); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Descrizioni di sezione
	// ──────────────────────────────────────────────────────────────────────────

	/** Renderizza la descrizione della sezione Analisi. */
	public function render_section_analysis_desc(): void {
		echo '<p>' . esc_html__( 'Configura il comportamento del plugin per la generazione di suggerimenti AI.', 'semantic-ai' ) . '</p>';
	}

	/** Renderizza la descrizione della sezione Modelli AI. */
	public function render_section_models_desc(): void {
		echo '<p>' . esc_html__( 'Il WP AI Client usa il primo modello nell\'elenco supportato dal provider configurato. Sposta i modelli con ▲/▼ per cambiare la priorità.', 'semantic-ai' ) . '</p>';
	}

	/** Renderizza la descrizione della sezione Diagnostica. */
	public function render_section_diagnostics_desc(): void {
		echo '<p>' . esc_html__( 'Verifica che il provider AI configurato in WordPress risponda correttamente.', 'semantic-ai' ) . '</p>';
	}

	/** Renderizza la descrizione della sezione Aggiornamenti. */
	public function render_section_updates_desc(): void {
		echo '<p>' . esc_html__( 'Configura con quale frequenza il plugin interroga GitHub per nuovi aggiornamenti.', 'semantic-ai' ) . '</p>';
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Registrazione opzioni e campi
	// ──────────────────────────────────────────────────────────────────────────

	/** Registra tutte le opzioni del plugin con i relativi sanitize callback. */
	private function register_all_settings(): void {
		$options = [
			'sai_max_candidates'            => [ $this, 'sanitize_positive_int' ],
			'sai_max_links'                 => [ $this, 'sanitize_positive_int' ],
			'sai_max_emphasis'              => [ $this, 'sanitize_positive_int' ],
			'sai_chunk_threshold_chars'     => [ $this, 'sanitize_positive_int' ],
			'sai_target_post_types'         => [ $this, 'sanitize_post_types' ],
			'sai_cache_ttl'                 => [ $this, 'sanitize_positive_int' ],
			'sai_ai_request_timeout'        => [ $this, 'sanitize_positive_int' ],
			'sai_model_preferences'         => [ $this, 'sanitize_model_preferences' ],
			'sai_update_check_interval'     => [ $this, 'sanitize_positive_int' ],
			'sai_custom_system_instruction' => 'sanitize_textarea_field',
		];

		foreach ( $options as $option_name => $sanitize_callback ) {
			register_setting(
				self::OPTION_GROUP,
				$option_name,
				[ 'sanitize_callback' => $sanitize_callback ]
			);
		}
	}

	/** Aggiunge tutti i campi della pagina impostazioni. */
	private function add_all_fields(): void {
		// ── TAB Analisi ──────────────────────────────────────────────────────
		$analysis_fields = [
			[
				'id'       => 'sai_max_candidates',
				'label'    => __( 'Max candidati', 'semantic-ai' ),
				'callback' => 'render_number_field',
				'args'     => [
					'option'      => 'sai_max_candidates',
					'default'     => 50,
					'min'         => 5,
					'max'         => 200,
					'description' => __( 'Post/page inviati come candidati all\'AI (5–200).', 'semantic-ai' ),
				],
			],
			[
				'id'       => 'sai_max_links',
				'label'    => __( 'Max link suggeriti', 'semantic-ai' ),
				'callback' => 'render_number_field',
				'args'     => [
					'option'      => 'sai_max_links',
					'default'     => 8,
					'min'         => 1,
					'max'         => 30,
					'description' => __( 'Numero massimo di suggerimenti link per analisi (1–30).', 'semantic-ai' ),
				],
			],
			[
				'id'       => 'sai_max_emphasis',
				'label'    => __( 'Max enfasi suggerite', 'semantic-ai' ),
				'callback' => 'render_number_field',
				'args'     => [
					'option'      => 'sai_max_emphasis',
					'default'     => 10,
					'min'         => 0,
					'max'         => 30,
					'description' => __( 'Numero massimo di grassetto/corsivo suggeriti (0–30).', 'semantic-ai' ),
				],
			],
			[
				'id'       => 'sai_chunk_threshold_chars',
				'label'    => __( 'Soglia chunking (caratteri)', 'semantic-ai' ),
				'callback' => 'render_number_field',
				'args'     => [
					'option'      => 'sai_chunk_threshold_chars',
					'default'     => 20000,
					'min'         => 5000,
					'max'         => 100000,
					'description' => __( 'Articoli più lunghi vengono analizzati a blocchi (5.000–100.000).', 'semantic-ai' ),
				],
			],
			[
				'id'       => 'sai_target_post_types',
				'label'    => __( 'Tipi di post candidati', 'semantic-ai' ),
				'callback' => 'render_post_types_field',
				'args'     => [
					'option'      => 'sai_target_post_types',
					'default'     => [ 'post', 'page' ],
					'description' => __( 'Tipi di contenuto da includere come destinazioni dei link.', 'semantic-ai' ),
				],
			],
			[
				'id'       => 'sai_cache_ttl',
				'label'    => __( 'TTL cache AI (secondi)', 'semantic-ai' ),
				'callback' => 'render_number_field',
				'args'     => [
					'option'      => 'sai_cache_ttl',
					'default'     => 86400,
					'min'         => 300,
					'max'         => 604800,
					'description' => __( 'Durata della cache delle risposte AI in secondi (min 300, max 604.800 = 7 giorni).', 'semantic-ai' ),
				],
			],
			[
				'id'       => 'sai_ai_request_timeout',
				'label'    => __( 'Timeout AI (secondi)', 'semantic-ai' ),
				'callback' => 'render_number_field',
				'args'     => [
					'option'      => 'sai_ai_request_timeout',
					'default'     => 120,
					'min'         => 30,
					'max'         => 300,
					'description' => __( 'Timeout massimo per la chiamata HTTP al provider AI (30–300 secondi). Aumentare se si verificano errori di timeout su articoli lunghi.', 'semantic-ai' ),
				],
			],
		];

		foreach ( $analysis_fields as $field ) {
			add_settings_field(
				$field['id'],
				$field['label'],
				[ $this, $field['callback'] ],
				self::TAB_ANALYSIS,
				self::SECTION_ANALYSIS,
				$field['args']
			);
		}

		// ── TAB Modelli AI ───────────────────────────────────────────────────
		add_settings_field(
			'sai_model_preferences',
			__( 'Ordine di preferenza', 'semantic-ai' ),
			[ $this, 'render_model_preferences_field' ],
			self::TAB_MODELS,
			self::SECTION_MODELS
		);

		add_settings_field(
			'sai_test_connection',
			__( 'Connessione AI', 'semantic-ai' ),
			[ $this, 'render_test_connection_field' ],
			self::TAB_MODELS,
			self::SECTION_DIAGNOSTICS
		);

		// ── TAB Aggiornamenti ────────────────────────────────────────────────
		add_settings_field(
			'sai_update_check_interval',
			__( 'Intervallo verifica (ore)', 'semantic-ai' ),
			[ $this, 'render_update_interval_field' ],
			self::TAB_UPDATES,
			self::SECTION_UPDATES
		);

		add_settings_field(
			'sai_force_update_check',
			__( 'Forza verifica ora', 'semantic-ai' ),
			[ $this, 'render_force_check_field' ],
			self::TAB_UPDATES,
			self::SECTION_UPDATES
		);
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Renderer campi
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Renderizza un campo numerico.
	 *
	 * @param array<string, mixed> $args Argomenti del campo.
	 */
	public function render_number_field( array $args ): void {
		$option      = (string) ( $args['option'] ?? '' );
		$default     = (int) ( $args['default'] ?? 0 );
		$min         = (int) ( $args['min'] ?? 0 );
		$max         = (int) ( $args['max'] ?? 99999 );
		$description = (string) ( $args['description'] ?? '' );
		$value       = (int) get_option( $option, $default );

		printf(
			'<input type="number" name="%s" id="%s" value="%s" min="%s" max="%s" class="small-text" />',
			esc_attr( $option ),
			esc_attr( $option ),
			esc_attr( (string) $value ),
			esc_attr( (string) $min ),
			esc_attr( (string) $max )
		);

		if ( '' !== $description ) {
			printf( '<p class="description">%s</p>', esc_html( $description ) );
		}
	}

	/**
	 * Renderizza le checkbox dei tipi di post.
	 *
	 * @param array<string, mixed> $args Argomenti del campo.
	 */
	public function render_post_types_field( array $args ): void {
		$option      = (string) ( $args['option'] ?? '' );
		$default     = (array) ( $args['default'] ?? [ 'post', 'page' ] );
		$description = (string) ( $args['description'] ?? '' );

		$raw_saved    = get_option( $option, $default );
		$saved_types  = is_array( $raw_saved ) ? $raw_saved : $default;
		$public_types = get_post_types( [ 'public' => true ], 'objects' );

		foreach ( $public_types as $post_type ) {
			$checked = in_array( $post_type->name, $saved_types, true );
			printf(
				'<label style="display:block;margin-bottom:4px"><input type="checkbox" name="%s[]" value="%s"%s /> %s</label>',
				esc_attr( $option ),
				esc_attr( $post_type->name ),
				checked( $checked, true, false ),
				esc_html( $post_type->label )
			);
		}

		if ( '' !== $description ) {
			printf( '<p class="description">%s</p>', esc_html( $description ) );
		}
	}

	/** Renderizza il campo di ordinamento dei modelli AI. */
	public function render_model_preferences_field(): void {
		$raw_saved   = Plugin::get_option( 'model_preferences' );
		$saved_prefs = is_array( $raw_saved ) ? array_values( $raw_saved ) : self::DEFAULT_MODEL_PREFERENCES;

		$catalog_by_id = [];
		foreach ( self::MODEL_CATALOG as $model ) {
			$catalog_by_id[ $model['id'] ] = $model;
		}

		$active_models = [];
		foreach ( $saved_prefs as $model_id ) {
			$id = (string) $model_id;
			if ( isset( $catalog_by_id[ $id ] ) ) {
				$active_models[] = $catalog_by_id[ $id ];
			}
		}

		if ( empty( $active_models ) ) {
			foreach ( self::DEFAULT_MODEL_PREFERENCES as $model_id ) {
				$active_models[] = $catalog_by_id[ $model_id ];
			}
		}

		$active_ids   = array_column( $active_models, 'id' );
		$hidden_value = (string) wp_json_encode( $active_ids );

		echo '<div id="sai-model-prefs-wrap">';
		echo '<ul id="sai-model-list" style="margin:0;padding:0;list-style:none;max-width:560px;">';

		foreach ( $active_models as $i => $model ) {
			printf(
				'<li class="sai-model-item" data-model-id="%s" data-provider="%s" style="display:flex;align-items:center;gap:8px;padding:6px 10px;background:#fff;border:1px solid #ddd;margin-bottom:4px;border-radius:3px;">'
				. '<span class="sai-pos" style="color:#888;min-width:24px;font-weight:600">%d.</span>'
				. '<strong>%s</strong>'
				. '<span style="color:#888;font-size:12px">(%s)</span>'
				. '<code style="color:#555;font-size:11px;background:#f6f6f6;padding:1px 4px;border-radius:2px">%s</code>'
				. '<span style="flex:1"></span>'
				. '<button type="button" class="button button-small sai-move-up" title="%s">&#9650;</button>'
				. '<button type="button" class="button button-small sai-move-down" title="%s" style="margin-left:2px">&#9660;</button>'
				. '<button type="button" class="button button-small sai-remove" title="%s" style="margin-left:6px;color:#a00">&#10005;</button>'
				. '</li>',
				esc_attr( $model['id'] ),
				esc_attr( $model['provider'] ),
				absint( $i ) + 1,
				esc_html( $model['label'] ),
				esc_html( $model['provider'] ),
				esc_attr( $model['id'] ),
				esc_attr__( 'Sposta su', 'semantic-ai' ),
				esc_attr__( 'Sposta giù', 'semantic-ai' ),
				esc_attr__( 'Rimuovi', 'semantic-ai' )
			);
		}

		echo '</ul>';
		echo '<p style="margin-top:12px;display:flex;align-items:center;gap:8px;">';
		echo '<select id="sai-model-add-select">';
		echo '<option value="">' . esc_html__( '— scegli modello —', 'semantic-ai' ) . '</option>';

		$providers = [];
		foreach ( self::MODEL_CATALOG as $model ) {
			$providers[ $model['provider'] ][] = $model;
		}

		foreach ( $providers as $provider_name => $provider_models ) {
			printf( '<optgroup label="%s">', esc_attr( $provider_name ) );
			foreach ( $provider_models as $model ) {
				printf(
					'<option value="%s" data-label="%s" data-provider="%s">%s</option>',
					esc_attr( $model['id'] ),
					esc_attr( $model['label'] ),
					esc_attr( $model['provider'] ),
					esc_html( $model['label'] . ' (' . $model['id'] . ')' )
				);
			}
			echo '</optgroup>';
		}

		echo '</select>';
		printf(
			'<button type="button" class="button" id="sai-add-model-btn">%s</button>',
			esc_html__( '+ Aggiungi', 'semantic-ai' )
		);
		echo '</p>';

		printf(
			'<input type="hidden" name="sai_model_preferences" id="sai-model-prefs-hidden" value="%s">',
			esc_attr( $hidden_value )
		);

		echo '<p class="description">' . esc_html__(
			'Il WP AI Client prova i modelli in quest\'ordine e usa il primo supportato dal provider configurato.',
			'semantic-ai'
		) . '</p>';

		echo '</div>';
	}

	/** Renderizza il pulsante di test della connessione AI. */
	public function render_test_connection_field(): void {
		$nonce = wp_create_nonce( 'sai_test_ai' );

		printf(
			'<button type="button" id="sai-test-btn" class="button" data-nonce="%s">%s</button>'
			. '<span id="sai-test-result" style="margin-left:10px;display:none;"></span>',
			esc_attr( $nonce ),
			esc_html__( 'Testa connessione AI', 'semantic-ai' )
		);

		echo '<p class="description">' . esc_html__(
			'Invia una richiesta minimale al provider AI configurato e mostra l\'esito in tempo reale.',
			'semantic-ai'
		) . '</p>';
	}

	/** Renderizza il campo dell\'intervallo di verifica aggiornamenti. */
	public function render_update_interval_field(): void {
		$value = (int) Plugin::get_option( 'update_check_interval' );
		$value = max( 1, min( 24, $value ) );

		printf(
			'<input type="number" name="sai_update_check_interval" id="sai_update_check_interval" value="%s" min="1" max="24" class="small-text" /> %s',
			esc_attr( (string) $value ),
			esc_html__( 'ore', 'semantic-ai' )
		);

		echo '<p class="description">' . esc_html__(
			'Ogni quante ore il plugin interroga GitHub per nuovi aggiornamenti (1–24). Modificare questa impostazione svuota la cache corrente.',
			'semantic-ai'
		) . '</p>';
	}

	/** Renderizza il pulsante di verifica forzata degli aggiornamenti. */
	public function render_force_check_field(): void {
		$nonce_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=sai_force_update_check' ),
			'sai_force_update_check'
		);

		$cached = Updater::get_cached_release();
		$gh_ver = is_array( $cached ) ? ltrim( (string) ( $cached['tag_name'] ?? '?' ), 'v' ) : null;

		$status = is_null( $gh_ver )
			? __( 'Cache non presente — verrà interrogata GitHub alla prossima verifica.', 'semantic-ai' )
			: sprintf(
				/* translators: 1: versione installata, 2: versione in cache da GitHub */
				__( 'Installata: %1$s · GitHub (in cache): %2$s', 'semantic-ai' ),
				SAI_VERSION,
				$gh_ver
			);

		printf(
			'<a href="%s" class="button">%s</a><span class="description" style="margin-left:10px;">%s</span>',
			esc_url( $nonce_url ),
			esc_html__( 'Forza verifica aggiornamenti', 'semantic-ai' ),
			esc_html( $status )
		);

		echo '<p class="description" style="margin-top:6px;">' . esc_html__(
			'Svuota la cache GitHub e apre la pagina Aggiornamenti di WordPress con verifica forzata.',
			'semantic-ai'
		) . '</p>';
	}

	/** Renderizza i campi extra del TAB Prompt (fuori dalle sezioni Settings API). */
	private function render_prompt_tab_extra(): void {
		$value = (string) get_option( 'sai_custom_system_instruction', '' );
		$nonce = wp_create_nonce( 'sai_reset_instruction' );
		?>
		<h2><?php esc_html_e( 'System instruction personalizzata', 'semantic-ai' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="sai_custom_system_instruction">
						<?php esc_html_e( 'Istruzione di sistema', 'semantic-ai' ); ?>
					</label>
				</th>
				<td>
					<textarea name="sai_custom_system_instruction"
								id="sai_custom_system_instruction"
								rows="14"
								cols="80"
								class="large-text"
								style="font-family:monospace;font-size:12px;"><?php echo esc_textarea( $value ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'Lascia vuoto per usare l\'instruction predefinita del plugin. Placeholder supportati: {language}, {max_links}, {max_emphasis}.', 'semantic-ai' ); ?>
					</p>
					<p>
						<button type="button"
								id="sai-reset-instruction-btn"
								class="button"
								data-nonce="<?php echo esc_attr( $nonce ); ?>">
							<?php esc_html_e( 'Ripristina predefinita', 'semantic-ai' ); ?>
						</button>
						<span id="sai-reset-result" style="margin-left:8px;display:none;color:#0a7a0a;"></span>
					</p>
					<details style="margin-top:12px;">
						<summary style="cursor:pointer;color:#2271b1;">
							<?php esc_html_e( 'Mostra instruction predefinita', 'semantic-ai' ); ?>
						</summary>
						<pre style="background:#f6f6f6;padding:12px;margin-top:8px;font-size:11px;overflow:auto;max-height:300px;border:1px solid #ddd;"><?php echo esc_html( $this->get_default_instruction_text() ); ?></pre>
					</details>
				</td>
			</tr>
		</table>
		<?php
	}

	/** Renderizza il TAB Log analisi. */
	private function render_log_tab(): void {
		$log          = new AnalysisLog();
		$entries      = $log->get_entries();
		$clear_nonce  = wp_create_nonce( 'sai_clear_log' );
		$result_nonce = wp_create_nonce( 'sai_get_log_result' );
		?>
		<h2><?php esc_html_e( 'Log analisi', 'semantic-ai' ); ?></h2>
		<p><?php esc_html_e( 'Storico delle analisi AI eseguite (un record per articolo — il più recente).', 'semantic-ai' ); ?></p>
		<p style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
			<input type="search"
					id="sai-log-search"
					placeholder="<?php esc_attr_e( 'Filtra per titolo post…', 'semantic-ai' ); ?>"
					style="width:280px;" />
			<button type="button" id="sai-clear-log-btn" class="button"
					data-nonce="<?php echo esc_attr( $clear_nonce ); ?>">
				<?php esc_html_e( 'Svuota log', 'semantic-ai' ); ?>
			</button>
		</p>

		<?php if ( empty( $entries ) ) : ?>
			<p><?php esc_html_e( 'Nessuna analisi registrata. Esegui un\'analisi da Gutenberg per vedere i risultati qui.', 'semantic-ai' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" id="sai-log-table" style="margin-top:12px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Post', 'semantic-ai' ); ?></th>
						<th><?php esc_html_e( 'Data', 'semantic-ai' ); ?></th>
						<th style="text-align:center;" title="<?php esc_attr_e( 'Clicca per vedere i candidati usati', 'semantic-ai' ); ?>">
							<?php esc_html_e( 'Candidati', 'semantic-ai' ); ?> ↗
						</th>
						<th style="text-align:center;"><?php esc_html_e( 'Link', 'semantic-ai' ); ?></th>
						<th style="text-align:center;"><?php esc_html_e( 'Enfasi', 'semantic-ai' ); ?></th>
						<th style="text-align:center;"><?php esc_html_e( 'Cache', 'semantic-ai' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php
				foreach ( $entries as $entry ) :
					/* @var array<string, mixed> $entry */
					$entry_id      = (string) ( $entry['id'] ?? '' );
					$post_title    = (string) ( $entry['post_title'] ?? '' );
					$post_id_entry = (int) ( $entry['post_id'] ?? 0 );
					$timestamp     = (string) ( $entry['timestamp'] ?? '' );
					$links_count   = (int) ( $entry['links_count'] ?? 0 );
					$emph_count    = (int) ( $entry['emphasis_count'] ?? 0 );
					$cand_count    = (int) ( $entry['candidate_count'] ?? 0 );
					$from_cache    = (bool) ( $entry['from_cache'] ?? false );
					$raw_edit_link = $post_id_entry > 0 ? get_edit_post_link( $post_id_entry ) : null;
					$edit_link     = is_string( $raw_edit_link ) ? $raw_edit_link : '';
					?>
					<tr data-title="<?php echo esc_attr( mb_strtolower( $post_title, 'UTF-8' ) ); ?>">
						<td>
							<?php if ( '' !== $edit_link ) : ?>
								<a href="<?php echo esc_url( $edit_link ); ?>"><?php echo esc_html( $post_title ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $post_title ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $timestamp ); ?></td>
						<td style="text-align:center;">
							<?php if ( $cand_count > 0 ) : ?>
								<button type="button"
										class="button-link sai-view-candidates"
										style="color:#2271b1;text-decoration:underline;cursor:pointer;"
										data-id="<?php echo esc_attr( $entry_id ); ?>"
										data-title="<?php echo esc_attr( $post_title ); ?>"
										data-nonce="<?php echo esc_attr( $result_nonce ); ?>">
									<?php echo absint( $cand_count ); ?>
								</button>
							<?php else : ?>
								0
							<?php endif; ?>
						</td>
						<td style="text-align:center;"><?php echo absint( $links_count ); ?></td>
						<td style="text-align:center;"><?php echo absint( $emph_count ); ?></td>
						<td style="text-align:center;">
							<?php echo $from_cache ? esc_html__( 'Sì', 'semantic-ai' ) : esc_html__( 'No', 'semantic-ai' ); ?>
						</td>
						<td>
							<button type="button"
									class="button button-small sai-view-result"
									data-id="<?php echo esc_attr( $entry_id ); ?>"
									data-nonce="<?php echo esc_attr( $result_nonce ); ?>">
								<?php esc_html_e( 'Visualizza', 'semantic-ai' ); ?>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<!-- Modal inline per il risultato dell'analisi -->
		<div id="sai-log-modal"
			style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:99999;">
			<div style="background:#fff;max-width:720px;margin:60px auto;padding:24px;max-height:80vh;overflow-y:auto;border-radius:4px;box-shadow:0 4px 24px rgba(0,0,0,.3);">
				<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
					<h2 style="margin:0;"><?php esc_html_e( 'Dettaglio analisi', 'semantic-ai' ); ?></h2>
					<button type="button" id="sai-log-modal-close" class="button">
						<?php esc_html_e( 'Chiudi', 'semantic-ai' ); ?>
					</button>
				</div>
				<div id="sai-log-result-content"></div>
			</div>
		</div>

		<!-- Modal inline per i candidati -->
		<div id="sai-candidates-modal"
			style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:99999;">
			<div style="background:#fff;max-width:680px;margin:60px auto;padding:24px;max-height:80vh;overflow-y:auto;border-radius:4px;box-shadow:0 4px 24px rgba(0,0,0,.3);">
				<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
					<h2 id="sai-candidates-modal-title" style="margin:0;"><?php esc_html_e( 'Candidati', 'semantic-ai' ); ?></h2>
					<button type="button" id="sai-candidates-modal-close" class="button">
						<?php esc_html_e( 'Chiudi', 'semantic-ai' ); ?>
					</button>
				</div>
				<div id="sai-candidates-content"></div>
			</div>
		</div>
		<?php
	}

	/** Restituisce il testo dell'instruction predefinita per il pannello "Mostra predefinita". */
	private function get_default_instruction_text(): string {
		$max_links    = (int) Plugin::get_option( 'max_links' );
		$max_emphasis = (int) Plugin::get_option( 'max_emphasis' );

		return "Sei un esperto SEO specializzato in internal linking e leggibilità dei contenuti web.\n"
			. "Analizzi l'articolo fornito e suggerisci link interni e enfasi semantica per migliorare la SEO on-page.\n\n"
			. "LINGUA: Tutti i suggerimenti devono essere nella stessa lingua dell'articolo.\n\n"
			. "REGOLE PER I LINK INTERNI:\n"
			. "- Puoi linkare SOLO ai targetId presenti nella lista candidati fornita.\n"
			. "- Le ancore devono essere TESTO GIÀ PRESENTE nel blocco indicato (verbatim).\n"
			. "- Le ancore devono essere descrittive e ricche di keyword.\n"
			. "- Massimo 1 link ogni 100-150 parole nell'articolo.\n"
			. "- Non linkare più volte allo stesso targetId.\n"
			. "- Proponi al massimo {$max_links} link in totale.\n\n"
			. "REGOLE PER L'ENFASI:\n"
			. "- Usa grassetto (bold) o corsivo (italic) solo su keyword o frasi davvero portanti.\n"
			. "- Non enfatizzare frasi già all'interno di un link.\n"
			. "- Massimo {$max_emphasis} elementi in totale.\n\n"
			. 'OUTPUT: Restituisci SOLO JSON conforme allo schema, senza testo extra.';
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Script JS admin (iniettati nell'admin footer della pagina)
	// ──────────────────────────────────────────────────────────────────────────

	/** Inietta gli script JS necessari per tutti i widget interattivi della pagina. */
	public function render_admin_scripts(): void {
		?>
		<script>
		(function () {

		// ── Ordinamento modelli ───────────────────────────────────────────────
		var list   = document.getElementById('sai-model-list');
		var hidden = document.getElementById('sai-model-prefs-hidden');
		var sel    = document.getElementById('sai-model-add-select');
		var addBtn = document.getElementById('sai-add-model-btn');

		if (list && hidden && sel && addBtn) {
			function updatePositions() {
				list.querySelectorAll('.sai-model-item').forEach(function (item, i) {
					item.querySelector('.sai-pos').textContent = (i + 1) + '.';
				});
			}
			function syncModels() {
				var ids = [];
				list.querySelectorAll('.sai-model-item').forEach(function (item) {
					ids.push(item.dataset.modelId);
				});
				hidden.value = JSON.stringify(ids);
			}
			list.addEventListener('click', function (e) {
				var btn  = e.target.closest('button');
				if (!btn) { return; }
				var item = btn.closest('.sai-model-item');
				if (!item) { return; }
				if (btn.classList.contains('sai-move-up')) {
					var prev = item.previousElementSibling;
					if (prev) { list.insertBefore(item, prev); }
				} else if (btn.classList.contains('sai-move-down')) {
					var next = item.nextElementSibling;
					if (next) { list.insertBefore(next, item); }
				} else if (btn.classList.contains('sai-remove')) {
					item.remove();
				}
				updatePositions(); syncModels();
			});
			addBtn.addEventListener('click', function () {
				var opt      = sel.options[sel.selectedIndex];
				var modelId  = opt.value;
				if (!modelId) { return; }
				if (list.querySelector('[data-model-id="' + modelId + '"]')) { return; }
				var label    = opt.dataset.label    || opt.text;
				var provider = opt.dataset.provider || '';
				var count    = list.querySelectorAll('.sai-model-item').length;
				var li = document.createElement('li');
				li.className       = 'sai-model-item';
				li.dataset.modelId = modelId;
				li.style.cssText   = 'display:flex;align-items:center;gap:8px;padding:6px 10px;background:#fff;border:1px solid #ddd;margin-bottom:4px;border-radius:3px;';
				var pos = document.createElement('span'); pos.className = 'sai-pos'; pos.style.cssText = 'color:#888;min-width:24px;font-weight:600'; pos.textContent = (count+1)+'.';
				var strong = document.createElement('strong'); strong.textContent = label;
				var prov = document.createElement('span'); prov.style.cssText='color:#888;font-size:12px'; prov.textContent = provider ? '('+provider+')' : '';
				var code = document.createElement('code'); code.style.cssText='color:#555;font-size:11px;background:#f6f6f6;padding:1px 4px;border-radius:2px'; code.textContent = modelId;
				var spacer = document.createElement('span'); spacer.style.flex='1';
				var bUp = document.createElement('button'); bUp.type='button'; bUp.className='button button-small sai-move-up'; bUp.title='Sposta su'; bUp.innerHTML='&#9650;';
				var bDn = document.createElement('button'); bDn.type='button'; bDn.className='button button-small sai-move-down'; bDn.style.marginLeft='2px'; bDn.title='Sposta giù'; bDn.innerHTML='&#9660;';
				var bRm = document.createElement('button'); bRm.type='button'; bRm.className='button button-small sai-remove'; bRm.style.cssText='margin-left:6px;color:#a00'; bRm.title='Rimuovi'; bRm.innerHTML='&#10005;';
				li.appendChild(pos); li.appendChild(strong); li.appendChild(prov); li.appendChild(code); li.appendChild(spacer); li.appendChild(bUp); li.appendChild(bDn); li.appendChild(bRm);
				list.appendChild(li);
				updatePositions(); syncModels();
			});
			syncModels();
		}

		// ── Test connessione AI ───────────────────────────────────────────────
		var testBtn    = document.getElementById('sai-test-btn');
		var testResult = document.getElementById('sai-test-result');
		if (testBtn && testResult) {
			testBtn.addEventListener('click', function () {
				testBtn.disabled = true;
				testBtn.textContent = '<?php echo esc_js( __( 'Test in corso…', 'semantic-ai' ) ); ?>';
				testResult.style.display = 'none';
				var fd = new FormData();
				fd.append('action', 'sai_test_ai_connection');
				fd.append('nonce',  testBtn.dataset.nonce);
				fetch(ajaxurl, { method:'POST', body:fd, credentials:'same-origin' })
					.then(function(r){ return r.json(); })
					.then(function(data){
						testResult.style.display = 'inline';
						if (data.success) {
							testResult.style.color = '#0a7a0a'; testResult.style.fontWeight = 'bold';
							testResult.textContent = '✓ ' + (data.data.message || 'OK');
						} else {
							testResult.style.color = '#a00'; testResult.style.fontWeight = 'normal';
							testResult.textContent = '✗ ' + (typeof data.data === 'string' ? data.data : 'Errore sconosciuto');
						}
					})
					.catch(function(e){
						testResult.style.display = 'inline'; testResult.style.color = '#a00'; testResult.style.fontWeight = 'normal';
						testResult.textContent = '✗ Errore di rete: ' + e.message;
					})
					.finally(function(){
						testBtn.disabled = false;
						testBtn.textContent = '<?php echo esc_js( __( 'Testa connessione AI', 'semantic-ai' ) ); ?>';
					});
			});
		}

		// ── Ripristina instruction predefinita ────────────────────────────────
		var resetBtn    = document.getElementById('sai-reset-instruction-btn');
		var resetResult = document.getElementById('sai-reset-result');
		if (resetBtn) {
			resetBtn.addEventListener('click', function () {
				var fd = new FormData();
				fd.append('action', 'sai_reset_instruction');
				fd.append('nonce',  resetBtn.dataset.nonce);
				fetch(ajaxurl, { method:'POST', body:fd, credentials:'same-origin' })
					.then(function(r){ return r.json(); })
					.then(function(data){
						if (data.success) {
							var ta = document.getElementById('sai_custom_system_instruction');
							if (ta) { ta.value = ''; }
							if (resetResult) {
								resetResult.textContent = '<?php echo esc_js( __( '✓ Instruction ripristinata', 'semantic-ai' ) ); ?>';
								resetResult.style.display = 'inline';
								setTimeout(function(){ resetResult.style.display = 'none'; }, 3000);
							}
						}
					});
			});
		}

		// ── Log analisi ───────────────────────────────────────────────────────
		var clearLogBtn = document.getElementById('sai-clear-log-btn');
		if (clearLogBtn) {
			clearLogBtn.addEventListener('click', function () {
				if (!confirm('<?php echo esc_js( __( 'Svuotare il log analisi? L\'operazione non è reversibile.', 'semantic-ai' ) ); ?>')) { return; }
				var fd = new FormData();
				fd.append('action', 'sai_clear_log');
				fd.append('nonce',  clearLogBtn.dataset.nonce);
				fetch(ajaxurl, { method:'POST', body:fd, credentials:'same-origin' })
					.then(function(r){ return r.json(); })
					.then(function(data){ if (data.success) { location.reload(); } });
			});
		}

		var logModal       = document.getElementById('sai-log-modal');
		var logModalClose  = document.getElementById('sai-log-modal-close');
		var logResultBox   = document.getElementById('sai-log-result-content');

		document.querySelectorAll('.sai-view-result').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var fd = new FormData();
				fd.append('action', 'sai_get_log_result');
				fd.append('nonce',  btn.dataset.nonce);
				fd.append('id',     btn.dataset.id);
				fetch(ajaxurl, { method:'POST', body:fd, credentials:'same-origin' })
					.then(function(r){ return r.json(); })
					.then(function(data){
						if (data.success && logModal && logResultBox) {
							logResultBox.innerHTML = '';
							var result = data.data;
							// Render link suggestions
							if (result.links && result.links.length > 0) {
								var h3l = document.createElement('h3'); h3l.textContent = '<?php echo esc_js( __( 'Link suggeriti', 'semantic-ai' ) ); ?> (' + result.links.length + ')';
								logResultBox.appendChild(h3l);
								result.links.forEach(function(link, i) {
									var p = document.createElement('p');
									p.style.cssText = 'background:#f6f6f6;padding:8px;border-radius:3px;margin-bottom:6px;font-size:13px;';
									p.innerHTML = '<strong>' + (i+1) + '. «' + (link.anchorText||'') + '»</strong>'
										+ ' → <a href="' + (link.url||'#') + '" target="_blank">' + (link.title||link.url||'') + '</a>'
										+ (link.rationale ? '<br><em style="color:#666;">' + link.rationale + '</em>' : '');
									logResultBox.appendChild(p);
								});
							}
							// Render emphasis suggestions
							if (result.emphasis && result.emphasis.length > 0) {
								var h3e = document.createElement('h3'); h3e.textContent = '<?php echo esc_js( __( 'Enfasi suggerite', 'semantic-ai' ) ); ?> (' + result.emphasis.length + ')';
								logResultBox.appendChild(h3e);
								result.emphasis.forEach(function(emph, i) {
									var p = document.createElement('p');
									p.style.cssText = 'background:#f6f6f6;padding:8px;border-radius:3px;margin-bottom:6px;font-size:13px;';
									p.innerHTML = '<strong>' + (i+1) + '. «' + (emph.phrase||'') + '»</strong>'
										+ ' (' + (emph.format||'') + ')'
										+ (emph.rationale ? '<br><em style="color:#666;">' + emph.rationale + '</em>' : '');
									logResultBox.appendChild(p);
								});
							}
							if ((!result.links || result.links.length === 0) && (!result.emphasis || result.emphasis.length === 0)) {
								logResultBox.textContent = '<?php echo esc_js( __( 'Nessun suggerimento trovato in questa analisi.', 'semantic-ai' ) ); ?>';
							}
							logModal.style.display = 'block';
						}
					});
			});
		});

		if (logModalClose && logModal) {
			logModalClose.addEventListener('click', function(){ logModal.style.display = 'none'; });
			logModal.addEventListener('click', function(e){ if (e.target === logModal) { logModal.style.display = 'none'; } });
		}

		// ── Candidati modal ───────────────────────────────────────────────────
		var candidatesModal      = document.getElementById('sai-candidates-modal');
		var candidatesModalClose = document.getElementById('sai-candidates-modal-close');
		var candidatesContent    = document.getElementById('sai-candidates-content');
		var candidatesTitle      = document.getElementById('sai-candidates-modal-title');

		document.querySelectorAll('.sai-view-candidates').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var fd = new FormData();
				fd.append('action', 'sai_get_log_result');
				fd.append('nonce',  btn.dataset.nonce);
				fd.append('id',     btn.dataset.id);
				fetch(ajaxurl, { method:'POST', body:fd, credentials:'same-origin' })
					.then(function(r){ return r.json(); })
					.then(function(data){
						if (data.success && candidatesModal && candidatesContent) {
							candidatesContent.innerHTML = '';
							if (candidatesTitle) {
								candidatesTitle.textContent = '<?php echo esc_js( __( 'Candidati per', 'semantic-ai' ) ); ?> «' + (btn.dataset.title || '') + '»';
							}
							var candidates = data.data._candidates || [];
							if (candidates.length === 0) {
								candidatesContent.textContent = '<?php echo esc_js( __( 'Nessun candidato salvato per questa analisi.', 'semantic-ai' ) ); ?>';
							} else {
								var ul = document.createElement('ul');
								ul.style.cssText = 'list-style:none;margin:0;padding:0;';
								candidates.forEach(function(c) {
									var li = document.createElement('li');
									li.style.cssText = 'padding:8px 0;border-bottom:1px solid #f0f0f0;';
									var a = document.createElement('a');
									a.href = c.url || '#';
									a.target = '_blank';
									a.rel = 'noopener noreferrer';
									a.textContent = c.title || c.url || '';
									a.style.fontWeight = '600';
									li.appendChild(a);
									if (c.excerpt) {
										var small = document.createElement('p');
										small.style.cssText = 'margin:2px 0 0;color:#666;font-size:12px;';
										small.textContent = c.excerpt.length > 120 ? c.excerpt.substring(0,120) + '…' : c.excerpt;
										li.appendChild(small);
									}
									ul.appendChild(li);
								});
								candidatesContent.appendChild(ul);
							}
							candidatesModal.style.display = 'block';
						}
					});
			});
		});

		if (candidatesModalClose && candidatesModal) {
			candidatesModalClose.addEventListener('click', function(){ candidatesModal.style.display = 'none'; });
			candidatesModal.addEventListener('click', function(e){ if (e.target === candidatesModal) { candidatesModal.style.display = 'none'; } });
		}

		// ── Ricerca nel log ───────────────────────────────────────────────────
		var logSearch = document.getElementById('sai-log-search');
		var logTable  = document.getElementById('sai-log-table');

		if (logSearch && logTable) {
			logSearch.addEventListener('input', function () {
				var query = logSearch.value.toLowerCase();
				logTable.querySelectorAll('tbody tr').forEach(function (row) {
					var title = (row.dataset.title || '').toLowerCase();
					row.style.display = title.includes(query) ? '' : 'none';
				});
			});
		}

		})();
		</script>
		<?php
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Sanitize callbacks
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Sanitizza un intero positivo.
	 *
	 * @param mixed $value Valore grezzo dall'input.
	 * @return int Valore sanitizzato (minimo 1).
	 */
	public function sanitize_positive_int( mixed $value ): int {
		$int = absint( $value );
		return max( 1, $int );
	}

	/**
	 * Sanitizza l'array dei post type selezionati.
	 *
	 * @param mixed $value Valore grezzo dall'input.
	 * @return string[] Array di slug di post type validi.
	 */
	public function sanitize_post_types( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [ 'post', 'page' ];
		}

		$valid = [];
		foreach ( $value as $type ) {
			$type = sanitize_key( (string) $type );
			if ( '' !== $type && post_type_exists( $type ) ) {
				$valid[] = $type;
			}
		}

		return ! empty( $valid ) ? $valid : [ 'post', 'page' ];
	}

	/**
	 * Sanitizza le preferenze modelli: decodifica il JSON e valida ogni ID contro il catalogo.
	 *
	 * @param mixed $value Stringa JSON ricevuta dal campo hidden.
	 * @return string[] Array ordinato di model ID validi.
	 */
	public function sanitize_model_preferences( mixed $value ): array {
		$catalog_ids = array_column( self::MODEL_CATALOG, 'id' );

		if ( ! is_string( $value ) ) {
			return self::DEFAULT_MODEL_PREFERENCES;
		}

		$decoded = json_decode( $value, true );
		if ( ! is_array( $decoded ) ) {
			return self::DEFAULT_MODEL_PREFERENCES;
		}

		$valid = [];
		foreach ( $decoded as $model_id ) {
			if ( is_string( $model_id ) && in_array( $model_id, $catalog_ids, true ) ) {
				$valid[] = $model_id;
			}
		}

		return ! empty( $valid ) ? $valid : self::DEFAULT_MODEL_PREFERENCES;
	}
}
