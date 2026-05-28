# Changelog

Tutte le modifiche rilevanti a questo progetto sono documentate in questo file.

Il formato segue [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
e il progetto adotta [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.7] - 2026-05-28

### Fixed

- **Aggiornamenti automatici non rilevati**: tre bug distinti risolti.
  1. *Nessuna GitHub Release* (causa principale): il plugin interrogava `/releases/latest` che richiedeva Release create esplicitamente su GitHub, non semplici tag `git push`. Create le release v0.2.2–v0.2.6 mancanti.
  2. *Cache non svuotata al force-check*: `update-core.php?force-check=1` chiama `delete_site_transient('update_plugins')`, non `wp_clean_plugins_cache()`. Aggiunto hook su `delete_site_transient_update_plugins` per coprire tutti i percorsi di verifica forzata.
  3. *Directory zip GitHub rinominata male*: GitHub produce zip con root `{owner}-{repo}-{hash}/`; WordPress si aspetta `wp-semantic-ai/`. Aggiunto filtro `upgrader_source_selection` che rinomina la directory estratta prima dell'installazione.

## [0.2.6] - 2026-05-28

### Changed

- Spostata la sezione **Diagnostica** (test connessione AI) dopo "Preferenze modelli AI" e prima di "Aggiornamenti automatici".
- Fix link GitHub nell'elenco plugin: rimosso frammento `#doc` dall'URL.
- Fix pulsante "Testa connessione AI": rimossi `using_temperature(0.0)` e `using_max_tokens(10)` che causavano errore "Missing candidates[0].content" su Gemini 3.5 Flash.

## [0.2.5] - 2026-05-28

### Added

- Link **Impostazioni** nell'elenco plugin (colonna azioni) per accesso rapido alla pagina di configurazione.
- Link **GitHub** nella riga meta dell'elenco plugin con ancora `#doc` (`https://github.com/miziomon/wp-semantic-ai#doc`).
- Sezione **Diagnostica** in cima alla pagina Impostazioni con pulsante "Testa connessione AI": invia una richiesta minimale al provider configurato (usando l'ordine di preferenza modelli impostato) e mostra l'esito in tempo reale senza ricaricare la pagina.

## [0.2.4] - 2026-05-28

### Added

- Pulsante "Forza verifica aggiornamenti" nella pagina Impostazioni → Semantic AI: cancella il transient GitHub e reindirizza a `update-core.php?force-check=1`, così WP e il plugin interrogano entrambi fonti fresche.
- Integrazione con "Verifica di nuovo" di WordPress: hook su `wp_clean_plugins_cache` che invalida automaticamente la cache GitHub ogni volta che WP forza un controllo aggiornamenti.
- Campo "Intervallo verifica (ore)" nelle impostazioni: personalizza ogni quante ore il plugin interroga GitHub (1–24, default 4h). Modificare il valore svuota automaticamente la cache corrente.
- Stato cache mostrato accanto al pulsante (versione installata vs. versione GitHub in cache).

### Changed

- Intervallo di verifica aggiornamenti ridotto da 12h a 4h (default).
- La durata della cache è ora dinamica: legge `Plugin::get_option('update_check_interval')` invece di una costante hardcoded.

## [0.2.3] - 2026-05-28

### Added

- Selezione e ordinamento modelli AI nella pagina Impostazioni → Semantic AI: nuova sezione "Preferenze modelli AI" con lista interattiva (pulsanti ▲/▼ per priorità, ✕ per rimuovere, menu a tendina per aggiungere). Catalogo di 10 modelli confermati: Claude Opus 4.8, Sonnet 4.6, Haiku 4.5 (Anthropic); Gemini 3.5 Flash, 3.1 Pro Preview, 2.5 Pro, 2.5 Flash (Google); GPT-4.1, GPT-4.1 mini, GPT-4o (OpenAI). Il WP AI Client usa il primo modello nell'elenco supportato dal provider configurato.

### Changed

- Modello di fallback Gemini aggiornato da `gemini-3.1-pro-preview` a `gemini-3.5-flash` (stable, top performer).
- Modello di fallback OpenAI aggiornato da `gpt-5.4` a `gpt-4.1`.
- `LinkSuggester::call_ai()` ora legge l'ordine dei modelli da `Plugin::get_option('model_preferences')` anziché dalla costante hardcoded.

## [0.2.2] - 2026-05-28

### Added

- Aggiornamenti automatici tramite GitHub Releases: `Updater.php` aggiunge il plugin al meccanismo nativo di WordPress senza librerie di terze parti. Controlla l'API `api.github.com/repos/miziomon/wp-semantic-ai/releases/latest`, confronta la versione e, se disponibile, mostra la notifica di aggiornamento direttamente nella dashboard WP. La risposta API viene messa in cache con un transient da 12 ore.

## [0.2.1] - 2026-05-28

### Fixed

- Aggiunto `build/index.js` al repository: il bundle JS mancava dallo ZIP GitHub, impedendo il caricamento della sidebar nell'editor.
- Corretta la dipendenze dell'asset PHP (`build/index.asset.php`): le dipendenze WordPress (`wp-plugins`, `wp-components`, ecc.) ora vengono dichiarate correttamente.
- Correcto percorso CSS in `Plugin.php`: `build/index.css` → `build/editor.scss.css`.

## [0.2.0] - 2026-05-28

### Changed

- Plugin rinominato da "Semantic Internal Links" a **"Semantic AI"**: nome, slug, text domain (`semantic-ai`), prefissi opzioni DB (`sai_`), costanti PHP (`SAI_`), classi CSS (`.sai-`), namespace REST (`/semantic-ai/v1/`).
- File principale rinominato da `semantic-internal-links.php` a `semantic-ai.php`.

### Fixed

- Distribuzione ZIP da GitHub ora funziona senza `composer install`: l'autoloader Composer è stato sostituito con un `spl_autoload_register` nativo. `vendor/` è necessario solo per gli strumenti di sviluppo (phpcs, phpstan).

## [0.1.0] - 2026-05-27

### Added

- Sidebar Gutenberg "Semantic AI" con pulsante di analisi e rilevamento disponibilità provider AI.
- Endpoint REST `POST /semantic-ai/v1/suggest` con autenticazione via nonce WordPress.
- Pre-filtro candidati basato su tassonomie condivise (category, post_tag) con fallback fulltext su `post` e `page`.
- Integrazione con WP AI Client nativo (`wp_ai_client_prompt`) con JSON schema strutturato (§6 spec).
- Modale di anteprima con applicazione selettiva: due sezioni (link interni / enfasi semantica), controlli "Seleziona tutto / Deseleziona tutto", spinner di caricamento e stati di errore espliciti.
- Applicazione dei suggerimenti via `@wordpress/rich-text` (`applyFormat`, `toHTMLString`, `updateBlockAttributes`): ricerca per occorrenza n-esima, skip di range già formattati, snackbar di riepilogo.
- Cache transient 24h delle risposte AI con chiave hash (content + candidateIds + schemaVersion + locale); invalidazione automatica su `save_post`.
- Chunking automatico degli articoli lunghi: divisione dei blocchi in chunk sotto la soglia configurata, N chiamate AI separate e merge con deduplicazione.
- Pagina Impostazioni (Settings API) sotto *Impostazioni → Semantic AI* con sei parametri configurabili.
- Stili SCSS in metodologia BEM (`.sai-modal`, `.sai-sidebar`, elementi e modificatori).
- i18n completo con text domain `semantic-ai` su tutte le stringhe UI.
- Filtri di estensione: `SAI_candidates`, `SAI_system_instruction`, `SAI_suggestion_validate_link`.
- Tooling: `@wordpress/scripts`, `@wordpress/env`, PHPCS (WPCS), PHPStan livello 8 con stubs WP 7.0.

[Unreleased]: https://github.com/miziomon/wp-semantic-ai/compare/v0.2.7...HEAD
[0.2.7]: https://github.com/miziomon/wp-semantic-ai/compare/v0.2.6...v0.2.7
[0.2.6]: https://github.com/miziomon/wp-semantic-ai/compare/v0.2.5...v0.2.6
[0.2.5]: https://github.com/miziomon/wp-semantic-ai/compare/v0.2.4...v0.2.5
[0.2.4]: https://github.com/miziomon/wp-semantic-ai/compare/v0.2.3...v0.2.4
[0.2.3]: https://github.com/miziomon/wp-semantic-ai/compare/v0.2.2...v0.2.3
[0.2.2]: https://github.com/miziomon/wp-semantic-ai/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/miziomon/wp-semantic-ai/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/miziomon/wp-semantic-ai/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/miziomon/wp-semantic-ai/releases/tag/v0.1.0
