<?php
/**
 * Pagina delle impostazioni del plugin tramite WordPress Settings API.
 *
 * @package Mavida\SemanticInternalLinks\Settings
 */

declare( strict_types=1 );

namespace Mavida\SemanticInternalLinks\Settings;

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
 */
class SettingsPage {

	/** Slug della pagina di impostazioni. */
	public const MENU_SLUG = 'semantic-ai';

	/** Gruppo di opzioni per register_setting. */
	public const OPTION_GROUP = 'sai_options';

	/** Nome della sezione principale. */
	private const SECTION_MAIN = 'sai_section_main';

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
	}

	/** Registra le opzioni, le sezioni e i campi tramite Settings API. */
	public function register_settings(): void {
		$this->register_all_settings();

		add_settings_section(
			self::SECTION_MAIN,
			__( 'Parametri di analisi', 'semantic-ai' ),
			[ $this, 'render_section_description' ],
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

	/** Registra tutte le opzioni del plugin con i relativi sanitize callback. */
	private function register_all_settings(): void {
		$options = [
			'sai_max_candidates'        => [ $this, 'sanitize_positive_int' ],
			'sai_max_links'             => [ $this, 'sanitize_positive_int' ],
			'sai_max_emphasis'          => [ $this, 'sanitize_positive_int' ],
			'sai_chunk_threshold_chars' => [ $this, 'sanitize_positive_int' ],
			'sai_target_post_types'     => [ $this, 'sanitize_post_types' ],
			'sai_cache_ttl'             => [ $this, 'sanitize_positive_int' ],
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
		$fields = [
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

		foreach ( $fields as $field ) {
			add_settings_field(
				$field['id'],
				$field['label'],
				[ $this, $field['callback'] ],
				self::MENU_SLUG,
				self::SECTION_MAIN,
				$field['args']
			);
		}
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
}
