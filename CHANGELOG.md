# Changelog

Tutte le modifiche rilevanti a questo progetto sono documentate in questo file.

Il formato segue [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
e il progetto adotta [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/miziomon/wp-semantic-ai/compare/v0.2.1...HEAD
[0.2.1]: https://github.com/miziomon/wp-semantic-ai/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/miziomon/wp-semantic-ai/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/miziomon/wp-semantic-ai/releases/tag/v0.1.0
