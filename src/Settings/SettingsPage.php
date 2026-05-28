<?php
/**
 * Pagina delle impostazioni del plugin tramite WordPress Settings API.
 *
 * @package Mavida\SemanticInternalLinks\Settings
 */

declare( strict_types=1 );

namespace Mavida\SemanticInternalLinks\Settings;

use Mavida\SemanticInternalLinks\Plugin;
use Mavida\SemanticInternalLinks\Updater;

/**
 * Registra e renderizza la pagina "Impostazioni → Semantic AI".
 *
 * Parametri configurabili:
 * - max_candidates: numero massimo di post/page inviati come candidati all'AI.
 * - max_links: numero massimo di suggerimenti link per analisi.
 * - max_emphasis: numero massimo di suggerimenti grassetto/corsivo.
 * - chunk_threshold_chars: soglia caratteri oltre la quale chunking dell'articolo.
 * - target_post_types: tipi di post da includere come candidati.
 * - cache_ttl: TTL in secondi della cache transient delle risposte AI.
 * - model_preferences: ordine di preferenza dei modelli AI (array di model ID).
 */
class SettingsPage {

	/** Slug della pagina di impostazioni. */
	public const MENU_SLUG = 'semantic-ai';

	/** Gruppo di opzioni per register_setting. */
	public const OPTION_GROUP = 'sai_options';

	/** Nome della sezione diagnostica (prima sezione visualizzata). */
	private const SECTION_DIAGNOSTICS = 'sai_section_diagnostics';

	/** Nome della sezione principale. */
	private const SECTION_MAIN = 'sai_section_main';

	/** Nome della sezione preferenze modelli. */
	private const SECTION_MODELS = 'sai_section_models';

	/** Nome della sezione aggiornamenti automatici. */
	private const SECTION_UPDATES = 'sai_section_updates';

	/**
	 * Catalogo dei modelli AI disponibili per la selezione.
	 *
	 * @var array<int, array{id: string, label: string, provider: string}>
	 */
	private const MODEL_CATALOG = [
		[ 'id' => 'claude-opus-4-8',        'label' => 'Claude Opus 4.8',        'provider' => 'Anthropic' ],
		[ 'id' => 'claude-sonnet-4-6',       'label' => 'Claude Sonnet 4.6',      'provider' => 'Anthropic' ],
		[ 'id' => 'claude-haiku-4-5',        'label' => 'Claude Haiku 4.5',       'provider' => 'Anthropic' ],
		[ 'id' => 'gemini-3.5-flash',        'label' => 'Gemini 3.5 Flash',       'provider' => 'Google'    ],
		[ 'id' => 'gemini-3.1-pro-preview',  'label' => 'Gemini 3.1 Pro Preview', 'provider' => 'Google'    ],
		[ 'id' => 'gemini-2.5-pro',          'label' => 'Gemini 2.5 Pro',         'provider' => 'Google'    ],
		[ 'id' => 'gemini-2.5-flash',        'label' => 'Gemini 2.5 Flash',       'provider' => 'Google'    ],
		[ 'id' => 'gpt-4.1',                 'label' => 'GPT-4.1',                'provider' => 'OpenAI'    ],
		[ 'id' => 'gpt-4.1-mini',            'label' => 'GPT-4.1 mini',           'provider' => 'OpenAI'    ],
		[ 'id' => 'gpt-4o',                  'label' => 'GPT-4o',                 'provider' => 'OpenAI'    ],
	];

	/** Preferenze di default se l'opzione non è ancora stata salvata. */
	private const DEFAULT_MODEL_PREFERENCES = [ 'claude-sonnet-4-6', 'gemini-3.5-flash', 'gpt-4.1' ];

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
		add_action( 'admin_footer-settings_page_' . self::MENU_SLUG, [ $this, 'render_model_prefs_script' ] );
	}

	/** Registra le opzioni, le sezioni e i campi tramite Settings API. */
	public function register_settings(): void {
		$this->register_all_settings();

		add_settings_section(
			self::SECTION_DIAGNOSTICS,
			__( 'Diagnostica', 'semantic-ai' ),
			[ $this, 'render_section_diagnostics_description' ],
			self::MENU_SLUG
		);

		add_settings_section(
			self::SECTION_MAIN,
			__( 'Parametri di analisi', 'semantic-ai' ),
			[ $this, 'render_section_description' ],
			self::MENU_SLUG
		);

		add_settings_section(
			self::SECTION_MODELS,
			__( 'Preferenze modelli AI', 'semantic-ai' ),
			[ $this, 'render_section_models_description' ],
			self::MENU_SLUG
		);

		add_settings_section(
			self::SECTION_UPDATES,
			__( 'Aggiornamenti automatici', 'semantic-ai' ),
			[ $this, 'render_section_updates_description' ],
			self::MENU_SLUG
		);

		$this->add_all_fields();
	}

	/** Renderizza la pagina di impostazioni. */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::MENU_SLUG );
				submit_button( __( 'Salva impostazioni', 'semantic-ai' ) );
				?>
			</form>
		</div>
		<?php
	}

	/** Renderizza la descrizione della sezione principale. */
	public function render_section_description(): void {
		echo '<p>' . esc_html__(
			'Configura il comportamento del plugin per la generazione di suggerimenti AI.',
			'semantic-ai'
		) . '</p>';
	}

	/** Renderizza la descrizione della sezione diagnostica. */
	public function render_section_diagnostics_description(): void {
		echo '<p>' . esc_html__(
			'Verifica che il provider AI configurato in WordPress risponda correttamente.',
			'semantic-ai'
		) . '</p>';
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
			'Invia una richiesta minimale al provider AI configurato (usa i modelli nell\'ordine di preferenza impostato sopra) e mostra l\'esito in tempo reale.',
			'semantic-ai'
		) . '</p>';
	}

	/** Renderizza la descrizione della sezione modelli AI. */
	public function render_section_models_description(): void {
		echo '<p>' . esc_html__(
			'Il WP AI Client usa il primo modello nell\'elenco supportato dal provider configurato. Sposta i modelli con ▲/▼ per cambiare la priorità.',
			'semantic-ai'
		) . '</p>';
	}

	/** Registra tutte le opzioni del plugin con i relativi sanitize callback. */
	private function register_all_settings(): void {
		$options = [
			'sai_max_candidates'        => [ $this, 'sanitize_positive_int' ],
			'sai_max_links'             => [ $this, 'sanitize_positive_int' ],
			'sai_max_emphasis'          => [ $this, 'sanitize_positive_int' ],
			'sai_chunk_threshold_chars' => [ $this, 'sanitize_positive_int' ],
			'sai_target_post_types'     => [ $this, 'sanitize_post_types' ],
			'sai_cache_ttl'             => [ $this, 'sanitize_positive_int' ],
			'sai_model_preferences'     => [ $this, 'sanitize_model_preferences' ],
			'sai_update_check_interval' => [ $this, 'sanitize_positive_int' ],
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
		add_settings_field(
			'sai_test_connection',
			__( 'Connessione AI', 'semantic-ai' ),
			[ $this, 'render_test_connection_field' ],
			self::MENU_SLUG,
			self::SECTION_DIAGNOSTICS
		);

		$main_fields = [
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
		];

		foreach ( $main_fields as $field ) {
			add_settings_field(
				$field['id'],
				$field['label'],
				[ $this, $field['callback'] ],
				self::MENU_SLUG,
				self::SECTION_MAIN,
				$field['args']
			);
		}

		add_settings_field(
			'sai_model_preferences',
			__( 'Ordine di preferenza', 'semantic-ai' ),
			[ $this, 'render_model_preferences_field' ],
			self::MENU_SLUG,
			self::SECTION_MODELS
		);

		add_settings_field(
			'sai_update_check_interval',
			__( 'Intervallo verifica (ore)', 'semantic-ai' ),
			[ $this, 'render_update_interval_field' ],
			self::MENU_SLUG,
			self::SECTION_UPDATES
		);

		add_settings_field(
			'sai_force_update_check',
			__( 'Forza verifica ora', 'semantic-ai' ),
			[ $this, 'render_force_check_field' ],
			self::MENU_SLUG,
			self::SECTION_UPDATES
		);
	}

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

	/** Renderizza la descrizione della sezione aggiornamenti automatici. */
	public function render_section_updates_description(): void {
		echo '<p>' . esc_html__(
			'Configura con quale frequenza il plugin interroga GitHub per nuovi aggiornamenti.',
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

		if ( is_null( $gh_ver ) ) {
			$status = __( 'Cache non presente — verrà interrogata GitHub alla prossima verifica.', 'semantic-ai' );
		} else {
			$status = sprintf(
				/* translators: 1: versione installata, 2: versione in cache da GitHub */
				__( 'Installata: %1$s · GitHub (in cache): %2$s', 'semantic-ai' ),
				SAI_VERSION,
				$gh_ver
			);
		}

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
				if ( isset( $catalog_by_id[ $model_id ] ) ) {
					$active_models[] = $catalog_by_id[ $model_id ];
				}
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
				$i + 1,
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
			'Il WP AI Client prova i modelli in quest\'ordine e usa il primo supportato dal provider configurato. Aggiungi o rimuovi modelli liberamente.',
			'semantic-ai'
		) . '</p>';

		echo '</div>';
	}

	/** Renderizza lo script JS per la gestione dell'ordinamento modelli. */
	public function render_model_prefs_script(): void {
		?>
		<script>
		(function () {
			var list   = document.getElementById('sai-model-list');
			var hidden = document.getElementById('sai-model-prefs-hidden');
			var sel    = document.getElementById('sai-model-add-select');
			var addBtn = document.getElementById('sai-add-model-btn');

			if (!list || !hidden || !sel || !addBtn) { return; }

			function updatePositions() {
				list.querySelectorAll('.sai-model-item').forEach(function (item, i) {
					item.querySelector('.sai-pos').textContent = (i + 1) + '.';
				});
			}

			function sync() {
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

				updatePositions();
				sync();
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
				li.className           = 'sai-model-item';
				li.dataset.modelId     = modelId;
				li.dataset.provider    = provider;
				li.style.cssText       = 'display:flex;align-items:center;gap:8px;padding:6px 10px;background:#fff;border:1px solid #ddd;margin-bottom:4px;border-radius:3px;';

				var pos  = document.createElement('span');
				pos.className   = 'sai-pos';
				pos.style.cssText = 'color:#888;min-width:24px;font-weight:600';
				pos.textContent = (count + 1) + '.';

				var strong = document.createElement('strong');
				strong.textContent = label;

				var prov = document.createElement('span');
				prov.style.cssText = 'color:#888;font-size:12px';
				prov.textContent   = provider ? '(' + provider + ')' : '';

				var code = document.createElement('code');
				code.style.cssText = 'color:#555;font-size:11px;background:#f6f6f6;padding:1px 4px;border-radius:2px';
				code.textContent   = modelId;

				var spacer = document.createElement('span');
				spacer.style.flex = '1';

				var btnUp   = document.createElement('button');
				btnUp.type  = 'button';
				btnUp.className = 'button button-small sai-move-up';
				btnUp.title = '↑ Sposta su';
				btnUp.innerHTML = '&#9650;';

				var btnDn   = document.createElement('button');
				btnDn.type  = 'button';
				btnDn.className = 'button button-small sai-move-down';
				btnDn.title = '↓ Sposta giù';
				btnDn.style.marginLeft = '2px';
				btnDn.innerHTML = '&#9660;';

				var btnRm   = document.createElement('button');
				btnRm.type  = 'button';
				btnRm.className = 'button button-small sai-remove';
				btnRm.title = 'Rimuovi';
				btnRm.style.cssText = 'margin-left:6px;color:#a00';
				btnRm.innerHTML = '&#10005;';

				li.appendChild(pos);
				li.appendChild(strong);
				li.appendChild(prov);
				li.appendChild(code);
				li.appendChild(spacer);
				li.appendChild(btnUp);
				li.appendChild(btnDn);
				li.appendChild(btnRm);

				list.appendChild(li);
				updatePositions();
				sync();
			});

			sync();

		// Test connessione AI
		var testBtn    = document.getElementById('sai-test-btn');
		var testResult = document.getElementById('sai-test-result');

		if (testBtn && testResult) {
			testBtn.addEventListener('click', function () {
				testBtn.disabled    = true;
				testBtn.textContent = '<?php echo esc_js( __( 'Test in corso…', 'semantic-ai' ) ); ?>';
				testResult.style.display = 'none';

				var formData = new FormData();
				formData.append('action', 'sai_test_ai_connection');
				formData.append('nonce',  testBtn.dataset.nonce);

				fetch(ajaxurl, { method: 'POST', body: formData, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (data) {
						testResult.style.display = 'inline';
						if (data.success) {
							testResult.style.color   = '#0a7a0a';
							testResult.style.fontWeight = 'bold';
							testResult.textContent   = '✓ ' + (data.data.message || 'OK');
						} else {
							testResult.style.color   = '#a00';
							testResult.style.fontWeight = 'normal';
							testResult.textContent   = '✗ ' + (typeof data.data === 'string' ? data.data : 'Errore sconosciuto');
						}
					})
					.catch(function (e) {
						testResult.style.display   = 'inline';
						testResult.style.color     = '#a00';
						testResult.style.fontWeight = 'normal';
						testResult.textContent     = '✗ Errore di rete: ' + e.message;
					})
					.finally(function () {
						testBtn.disabled    = false;
						testBtn.textContent = '<?php echo esc_js( __( 'Testa connessione AI', 'semantic-ai' ) ); ?>';
					});
			});
		}
		})();
		</script>
		<?php
	}

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
	 * Sanitizza le preferenze modelli: decodifica il JSON inviato dal form
	 * e valida ogni ID contro il catalogo noto.
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
