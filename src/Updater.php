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
 * 3. upgrader_process_complete → invalida la cache dopo ogni aggiornamento.
 */
class Updater {

	/** Repository GitHub nel formato "owner/repo". */
	private const GITHUB_REPO = 'miziomon/wp-semantic-ai';

	/** Chiave del transient di cache per la risposta GitHub API. */
	private const CACHE_KEY = 'sai_github_release';

	/** TTL della cache in secondi (12 ore). */
	private const CACHE_DURATION = 12 * HOUR_IN_SECONDS;

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
	}

	/**
	 * Inietta i dati di aggiornamento nel transient nativo di WordPress.
	 *
	 * WordPress passa un \stdClass per questo filtro: usare stdClass come tipo
	 * permette a PHPStan di accettare le proprietà dinamiche (response, no_update).
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

		set_transient( self::CACHE_KEY, $data, self::CACHE_DURATION );

		return $data;
	}
}
