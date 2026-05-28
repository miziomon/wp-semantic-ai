# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

### PHP
```bash
composer install          # installa strumenti dev (phpcs, phpstan) — NON serve in produzione
composer phpcs            # linting (WordPress Coding Standards)
composer phpcbf           # auto-fix PHPCS violations
composer phpstan          # analisi statica livello 8
```

> **Nota:** il plugin usa un autoloader PSR-4 nativo (`spl_autoload_register` in `semantic-ai.php`). `vendor/` non è necessario in produzione — lo zip di GitHub funziona senza `composer install`.

### JavaScript / CSS
```bash
# IMPORTANTE: usare Node.js v20, non v26 (v26 non compila fs-ext-extra-prebuilt)
# Sessione PowerShell: $env:PATH = "C:\tools\node20\node-v20.19.2-win-x64;$env:PATH"

npm install               # installa dipendenze JS
npm run build             # build produzione (build/index.js + build/editor.scss.css)
npm run start             # watch mode durante sviluppo
npm run lint:js           # ESLint sui file JS/JSX
npm run lint:css          # stylelint su SCSS
```

### Ambiente locale (wp-env + Docker)
```bash
# Sempre con Node v20 nel PATH
node_modules\.bin\wp-env start    # avvia WP 7.0 + PHP 8.1 su http://localhost:8888
node_modules\.bin\wp-env stop     # ferma i container
node_modules\.bin\wp-env run cli wp <comando>   # esegue WP-CLI nel container
```

wp-env admin: `http://localhost:8888/wp-admin` — utente `admin` / password `password`.

Dopo il primo avvio, installare manualmente il provider AI:
```bash
node_modules\.bin\wp-env run cli wp plugin install ai-provider-for-anthropic --activate
```

## Architettura

### Flusso principale

```
[Sidebar Gutenberg] → clic "Analizza"
    → src/editor/lib/blocks.js   (raccoglie blocchi testuali dall'editor)
    → src/editor/lib/api.js      (POST /semantic-ai/v1/suggest via apiFetch)
        → src/Rest/SuggestController.php   (authn: edit_post + nonce REST)
            → src/Content/CandidateProvider.php  (WP_Query: tax + fulltext)
            → src/Ai/LinkSuggester.php           (wp_ai_client_prompt, cache, chunking)
                → src/Ai/PromptBuilder.php        (system instruction + JSON schema §6)
                → src/Ai/SuggestionCache.php      (transient 24h, chiave = sha1)
                → src/Ai/ResponseValidator.php    (valida JSON, risolve targetId → url)
    → src/editor/components/SuggestionModal.jsx  (modale con selezione)
    → src/editor/lib/apply.js    (applyFormat rich-text + updateBlockAttributes)
```

### PHP (`src/`)

- **`Plugin.php`** — singleton, registra tutti gli hook WP; DI chain manuale in `register_rest_routes()`; `Plugin::get_option($key)` restituisce le impostazioni con default centralizzati.
- **`Rest/SuggestController`** — endpoint `POST /semantic-ai/v1/suggest`; `permission_callback` = `current_user_can('edit_post', $post_id)`.
- **`Ai/LinkSuggester`** — orchestratore: controlla disponibilità provider, gestisce cache, attiva chunking se `strlen(content) > chunk_threshold_chars`, chiama `call_ai()` N volte e unisce i risultati con deduplicazione.
- **`Ai/PromptBuilder`** — costruisce il system instruction e il payload JSON; `get_json_schema()` è il contratto con il modello AI (non modificarlo senza aggiornare `ResponseValidator`).
- **`Ai/ResponseValidator`** — unica guardia di sicurezza tra output AI e dati scritti nel post: scarta suggerimenti con `targetId` non presente nei candidati originali (gli URL vengono **sempre** risolti dalla lista interna, mai dal testo libero del modello).
- **`Content/CandidateProvider`** — due query `WP_Query`: prima per tassonomie condivise, poi fulltext (`s=`) se i risultati sono sotto soglia; emette filtro `sai_candidates`.
- **`Settings/SettingsPage`** — Settings API sotto *Impostazioni → Semantic AI*; le opzioni si leggono sempre tramite `Plugin::get_option()`.

### JavaScript (`src/editor/`)

- **`index.js`** — `registerPlugin` entry point.
- **`components/Sidebar.jsx`** — legge `window.silData.providerAvailable`; chiama `getTextBlocks()` → `fetchSuggestions()` al clic; passa `blockMap` (blockIndex → clientId) alla modale.
- **`lib/apply.js`** — `applyAllSuggestions(selected, blockMap)`: trova la n-esima occorrenza di `anchorText`/`phrase` nel plain-text del blocco, salta se il range è già formattato, applica `core/link` / `core/bold` / `core/italic` via `@wordpress/rich-text`, chiude con snackbar.

### Invariante di sicurezza

Il plugin non inserisce mai testo generato dall'AI. Applica solo formati (`core/link`, `core/bold`, `core/italic`) a testo già presente nei blocchi. Gli URL dei link provengono esclusivamente dalla lista candidati (`targetId` → `url`), mai dal testo libero del modello.

## Convenzioni

### PHP
- PSR-4: namespace `Mavida\SemanticInternalLinks\`, file PascalCase (es. `LinkSuggester.php`).
- `declare(strict_types=1)` in ogni file PHP.
- PHPCS scansiona solo `src/` e `semantic-ai.php`; esclude `src/editor/` e `assets/` (gestiti da wp-scripts).
- Le annotazioni `/* @var Type $x */` per PHPStan vengono segnalate come falso positivo da `Squiz.PHP.CommentedOutCode` — la sniff è disabilitata nel ruleset.
- Non usare l'operatore Elvis `?:` (PHPCS lo segnala): usare ternario esplicito.

### PHPStan
- `phpstan-bootstrap.php` definisce le costanti `SAI_*` e lo stub di `WP_AI_Client_Prompt_Builder` / `wp_ai_client_prompt()` perché `php-stubs/wordpress-stubs` è ancora alla v6.9.x e non include le API WP 7.0.
- Su Windows, i path in `phpstan-bootstrap.php` usano `str_replace('\\', '/', __DIR__)` per evitare problemi con i separatori.

### JavaScript
- `wp-scripts` gestisce build, lint e minificazione.
- Entry point espliciti: `src/editor/index.js` e `assets/scss/editor.scss`.
- `phpcbf` non deve essere eseguito su file `.js`/`.jsx` — li danneggia (rompe `?.` e `??`).

## Impostazioni plugin

Lette via `Plugin::get_option($key)`. Chiavi disponibili (prefisso DB: `SAI_`):

| Chiave | Default | Descrizione |
|---|---|---|
| `max_candidates` | 50 | Post/page inviati all'AI come candidati |
| `max_links` | 8 | Max suggerimenti link per analisi |
| `max_emphasis` | 10 | Max suggerimenti grassetto/corsivo |
| `chunk_threshold_chars` | 20000 | Soglia per attivare il chunking |
| `target_post_types` | `['post','page']` | Post type inclusi come candidati |
| `cache_ttl` | 86400 | TTL cache AI in secondi |
