<?php
/**
 * Aggiornamenti automatici del plugin tramite GitHub Releases.
 *
 * Interroga l'API GitHub per l'ultimo release e inietta le informazioni
 * nel meccanismo nativo di aggiornamento di WordPress.
 *
 * @package Mavida\SemanticInternalLinks
 */

declare( strict_types=1 );

namespace Mavida\SemanticInternalLinks;

/**
 * Gestisce il controllo e l'installazione degli aggiornamenti da GitHub.
 *
 * Flusso:
 * 1. pre_set_site_transient_update_plugins → confronta versione GitHub vs installata.
 * 2. plugins_api → popola il popup "Visualizza dettagli versione".
 * 3. upgrader_process_complete → invalida cache dopo aggiornamento completato.
 * 4. wp_clean_plugins_cache + delete_site_transient_update_plugins → invalida
 *    cache quando WP forza un controllo (copertura di tutti i percorsi WP).
 * 5. upgrader_source_selection → rinomina la directory estratta dallo zip GitHub
 *    ({owner}-{repo}-{hash}) nel nome corretto del plugin ({slug}).
 */
class Updater {

	/** Repository GitHub nel formato "owner/repo". */
	private const GITHUB_REPO = 'miziomon/wp-semantic-ai';

	/** Chiave del transient di cache per la risposta GitHub API. */
	private const CACHE_KEY = 'sai_github_release';

	/**
	 * Basename del plugin (es. wp-semantic-ai/semantic-ai.php).
	 *
	 * @var string
	 */
	private string $basename;

	/**
	 * Slug della directory del plugin (es. wp-semantic-ai).
	 *
	 * @var string
	 */
	private string $slug;

	/** Costruttore. */
	public function __construct() {
		$this->basename = plugin_basename( SAI_PLUGIN_FILE );
		$this->slug     = dirname( $this->basename );
	}

	/** Registra i filtri e le action di WordPress. */
	public function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );
		add_action( 'upgrader_process_complete', [ $this, 'purge_cache' ], 10, 2 );

		// Invalida il transient GitHub in tutti i percorsi di force-check WP:
		// - wp_clean_plugins_cache → scatta da alcuni contesti admin
		// - delete_site_transient_update_plugins → scatta quando WP
		// elimina il transient update_plugins (es. update-core.php?force-check=1).
		add_action( 'wp_clean_plugins_cache', [ $this, 'purge_cache_on_force_check' ] );
		add_action( 'delete_site_transient_update_plugins', [ $this, 'purge_cache_on_force_check' ] );

		// Invalida la cache quando l'utente cambia l'intervallo di verifica.
		add_action( 'update_option_sai_update_check_interval', [ $this, 'purge_cache_on_force_check' ] );

		// Gestisce il pulsante "Forza verifica" dalla pagina impostazioni.
		add_action( 'admin_post_sai_force_update_check', [ $this, 'handle_force_update_check' ] );

		// Rinomina la directory estratta dallo zip GitHub nel nome corretto del plugin.
		add_filter( 'upgrader_source_selection', [ $this, 'fix_source_dir' ], 10, 4 );
	}

	/**
	 * Inietta i dati di aggiornamento nel transient nativo di WordPress.
	 *
	 * @param \stdClass $transient Transient corrente degli aggiornamenti.
	 * @return \stdClass Transient (eventualmente modificato).
	 */
	public function check_for_update( \stdClass $transient ): \stdClass {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();

		if ( null === $release ) {
			return $transient;
		}

		$remote_version = ltrim( $release['tag_name'], 'v' );

		if ( version_compare( $remote_version, SAI_VERSION, '>' ) ) {
			$transient->response[ $this->basename ] = (object) [
				'slug'         => $this->slug,
				'plugin'       => $this->basename,
				'new_version'  => $remote_version,
				'url'          => 'https://github.com/' . self::GITHUB_REPO,
				'package'      => $release['zipball_url'],
				'requires_php' => '8.1',
				'icons'        => [],
				'banners'      => [],
			];
		} else {
			$transient->no_update[ $this->basename ] = (object) [
				'slug'        => $this->slug,
				'plugin'      => $this->basename,
				'new_version' => $remote_version,
				'url'         => 'https://github.com/' . self::GITHUB_REPO,
				'package'     => '',
			];
		}

		return $transient;
	}

	/**
	 * Fornisce le informazioni del plugin per il popup "Visualizza dettagli versione".
	 *
	 * @param false|object|array<mixed> $result Risultato corrente del filtro.
	 * @param string                    $action Azione richiesta dall'API.
	 * @param object                    $args   Argomenti della richiesta.
	 * @return false|object|array<mixed>
	 */
	public function plugin_info( false|object|array $result, string $action, object $args ): false|object|array {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ( $args->slug ?? '' ) !== $this->slug ) {
			return $result;
		}

		$release = $this->get_latest_release();

		if ( null === $release ) {
			return $result;
		}

		$version = ltrim( $release['tag_name'], 'v' );

		return (object) [
			'name'          => 'Semantic AI',
			'slug'          => $this->slug,
			'version'       => $version,
			'author'        => 'Maurizio — MAVIDA',
			'homepage'      => 'https://github.com/' . self::GITHUB_REPO,
			'requires'      => '7.0',
			'requires_php'  => '8.1',
			'download_link' => $release['zipball_url'],
			'last_updated'  => $release['published_at'] ?? '',
			'sections'      => [
				'description' => 'AI-powered semantic internal link suggestions for the Gutenberg editor.',
				'changelog'   => nl2br( (string) ( $release['body'] ?? '' ) ),
			],
		];
	}

	/**
	 * Svuota la cache del release dopo un aggiornamento completato.
	 *
	 * @param \WP_Upgrader         $upgrader Istanza dell'upgrader.
	 * @param array<string, mixed> $options  Opzioni dell'operazione.
	 */
	public function purge_cache( \WP_Upgrader $upgrader, array $options ): void {
		if ( 'update' !== ( $options['action'] ?? '' ) || 'plugin' !== ( $options['type'] ?? '' ) ) {
			return;
		}

		$plugins = (array) ( $options['plugins'] ?? [] );

		if ( in_array( $this->basename, $plugins, true ) ) {
			delete_transient( self::CACHE_KEY );
		}
	}

	/**
	 * Svuota la cache GitHub in tutti i contesti di force-check WordPress:
	 * - wp_clean_plugins_cache
	 * - delete_site_transient_update_plugins (update-core.php?force-check=1)
	 * - update_option_sai_update_check_interval
	 */
	public function purge_cache_on_force_check(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Gestisce il pulsante "Forza verifica aggiornamenti" nella pagina impostazioni.
	 * Cancella il transient GitHub e reindirizza a update-core.php?force-check=1.
	 */
	public function handle_force_update_check(): void {
		check_admin_referer( 'sai_force_update_check' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Non autorizzato.', 'semantic-ai' ) );
		}

		delete_transient( self::CACHE_KEY );

		wp_safe_redirect( admin_url( 'update-core.php?force-check=1' ) );
		exit;
	}

	/**
	 * Rinomina la directory estratta dallo zip GitHub prima dell'installazione.
	 *
	 * GitHub produce zip con root "{owner}-{repo}-{hash}/" ma WordPress si aspetta
	 * che la directory corrisponda allo slug del plugin ("{slug}/").
	 *
	 * @param string               $source        Directory sorgente estratta (con trailing slash).
	 * @param string               $remote_source Directory temporanea di lavoro.
	 * @param \WP_Upgrader         $upgrader      Istanza upgrader corrente.
	 * @param array<string, mixed> $hook_extra    Metadati dell'operazione (plugin, action, type).
	 * @return string|\WP_Error    Directory corretta o WP_Error in caso di rinomina fallita.
	 */
	public function fix_source_dir(
		string $source,
		string $remote_source,
		\WP_Upgrader $upgrader,
		array $hook_extra
	): string|\WP_Error {
		if ( ( $hook_extra['plugin'] ?? '' ) !== $this->basename ) {
			return $source;
		}

		global $wp_filesystem;

		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			return $source;
		}

		$correct = trailingslashit( dirname( untrailingslashit( $source ) ) . '/' . $this->slug );

		if ( $source === $correct ) {
			return $source;
		}

		if ( $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $correct ) ) ) {
			return $correct;
		}

		return new \WP_Error(
			'sai_rename_failed',
			sprintf(
				/* translators: 1: directory origine, 2: directory destinazione */
				__( 'Impossibile rinominare %1$s in %2$s durante l\'aggiornamento.', 'semantic-ai' ),
				untrailingslashit( $source ),
				untrailingslashit( $correct )
			)
		);
	}

	/**
	 * Restituisce i dati del release GitHub attualmente in cache.
	 * Usato dalla pagina impostazioni per mostrare lo stato della cache.
	 *
	 * @return array<string, mixed>|null Dati del release in cache, o null se assenti.
	 */
	public static function get_cached_release(): ?array {
		$cached = get_transient( self::CACHE_KEY );
		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Recupera i dati dell'ultimo release GitHub.
	 * Usa un transient come cache per evitare chiamate API eccessive.
	 *
	 * @return array<string, mixed>|null Dati del release oppure null in caso di errore.
	 */
	private function get_latest_release(): ?array {
		$cached = get_transient( self::CACHE_KEY );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest',
			[
				'timeout' => 10,
				'headers' => [
					'Accept'     => 'application/vnd.github.v3+json',
					'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			return null;
		}

		set_transient( self::CACHE_KEY, $data, $this->get_cache_duration() );

		return $data;
	}

	/**
	 * Calcola la durata della cache in secondi leggendo l'opzione configurata.
	 *
	 * @return int Durata in secondi (tra 1h e 24h).
	 */
	private function get_cache_duration(): int {
		$hours = (int) Plugin::get_option( 'update_check_interval' );
		return max( 1, min( 24, $hours ) ) * HOUR_IN_SECONDS;
	}
}
