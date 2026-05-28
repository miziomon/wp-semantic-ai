# Build Brief per Claude Code — Plugin WordPress 7.0 "Semantic AI"

> Questo file è un **prompt operativo** da incollare in Claude Code. Contiene contesto,
> vincoli, architettura e fasi di lavoro. Lavora **a fasi**, con commit atomici, e a fine
> di ogni fase esegui `composer phpcs`, `composer phpstan` e `npm run build` finché non
> passano puliti prima di proseguire.

---

## 0. Ruolo e modalità di lavoro

Agisci come **senior WordPress plugin engineer**. Il committente è uno sviluppatore PHP
senior (20+ anni), quindi:

- Spiega le decisioni architetturali **passo passo** e commenta il codice in modo che
  resti leggibile a distanza di mesi.
- Applica sempre le **best practice** WordPress (sicurezza, i18n, escaping, capability,
  nonce) e PHP moderne (tipi, `declare(strict_types=1)`, immutabilità dove sensata).
- Prima di scrivere codice WordPress, **consulta le Agent Skills** installate in Fase 1 e
  segui le procedure documentate invece di andare a memoria.
- Se incontri un'ambiguità che cambia l'architettura, **fermati e chiedi** prima di
  proseguire. Per scelte minori, procedi e annota l'assunzione nel commit.

---

## 1. Obiettivo del plugin

Creare un plugin che, alla pressione di un pulsante nella **sidebar (spalla) dell'editor
Gutenberg**, analizzi semanticamente l'articolo in editing e proponga in una **modale** una
**topologia di link interni** per la SEO, oltre a una valorizzazione semantica del testo
(grassetto/corsivo su keyword e frasi chiave).

I suggerimenti vengono mostrati come **anteprima**: l'utente seleziona quali applicare e
solo quelli selezionati vengono inseriti **dentro al flusso del testo** (mai in un widget a
fine pagina), modificando il contenuto dei blocchi.

### Invariante di progetto fondamentale (sicurezza + qualità)

> Il plugin **non inserisce mai testo generato dall'AI** dentro al post. Applica
> **soltanto formati** (`core/link`, `core/bold`, `core/italic`) a porzioni di testo **già
> presenti** nei blocchi. Gli URL dei link provengono **esclusivamente** dalla lista di
> contenuti candidati fornita dal plugin (risolti via `targetId`), non da testo libero del
> modello.

Questo elimina i rischi di prompt-injection/HTML injection e garantisce che i link
puntino sempre a contenuti realmente esistenti sul sito.

---

## 2. Vincoli tecnici (obbligatori)

- **PHP 8.1** minimo, con **autoload PSR-4** via Composer.
- **Coding Standards**: PHPCS con il ruleset **WordPress (WPCS)**.
- **Analisi statica**: **PHPStan** (livello alto, vedi §9) con estensione WordPress.
- **CSS**: **SCSS** con metodologia **BEM**.
- **Ambiente di sviluppo locale**: **`wp-env`** (`@wordpress/env`).
- **CHANGELOG.md** in formato **Keep a Changelog** + Semantic Versioning.
- **Agent Skills**: installa e usa quelle del repo ufficiale (vedi Fase 1).
- **Git**: inizializza il repo e configura il remote. **Nessun file di licenza** (`LICENSE`)
  e nessuna riga `License:` nell'header del plugin.

### Scelte già definite con il committente

| Decisione | Scelta |
| --- | --- |
| Strategia link interni | **Ibrida**: il plugin fornisce i candidati, l'AI fa il matching |
| Applicazione modifiche | **Anteprima in modale → applico solo i suggerimenti selezionati** |
| Ambito contenuti destinazione | **Articoli + Pagine** (`post`, `page`), configurabile in seguito |
| Nome / text domain | `Semantic AI` / `semantic-ai` |
| Namespace PHP (PSR-4 → `src/`) | `Mavida\SemanticInternalLinks` |
| Provider AI di test | **AI Provider for Anthropic**, preferenza modello `claude-sonnet-4-6` (logica resta provider-agnostica) |

---

## 3. Stack WordPress 7.0 — API da usare (verificate)

WordPress 7.0 "Armstrong" include il **WP AI Client** nativo. Usa **esclusivamente** queste
API lato server (NON usare l'API JavaScript client-side dell'AI Client: è sconsigliata nei
plugin distribuiti perché richiede capability da admin e consente prompt arbitrari).

```php
// Entry point: restituisce WP_AI_Client_Prompt_Builder (snake_case, ritorna WP_Error).
$builder = wp_ai_client_prompt( $prompt_text );

// Configurazione (builder fluente):
$builder
    ->using_system_instruction( $system_instruction )
    ->using_temperature( 0.2 )
    ->using_max_tokens( 4000 )
    ->using_model_preference( 'claude-sonnet-4-6', 'gemini-3.1-pro-preview', 'gpt-5.4' )
    ->as_json_response( $json_schema ); // forza output JSON conforme allo schema

// Feature detection: SEMPRE prima di mostrare la UI / eseguire il prompt.
// Non fa chiamate di rete, è deterministico e gratuito.
if ( ! $builder->is_supported_for_text_generation() ) {
    // Nessun provider configurato o capability non supportata → degrada con grazia.
}

// Generazione:
$json = $builder->generate_text();           // string | WP_Error
// oppure, per metadati (token usage, provider/modello):
$result = $builder->generate_text_result();  // GenerativeAiResult | WP_Error
// GenerativeAiResult è serializzabile e può essere passato a rest_ensure_response().

if ( is_wp_error( $json ) ) {
    return $json; // gestisci l'errore secondo le convenzioni WP
}
```

Note operative:

- Le **credenziali sono gestite dalla Connectors API** (Impostazioni → Connettori). Il
  plugin **non gestisce chiavi API**.
- `as_json_response( $schema )` fa restituire al modello una stringa JSON conforme: dopo la
  generazione fai `json_decode( $json, true )` e **valida** la struttura prima di usarla.
- È disponibile il filtro `wp_ai_client_prevent_prompt` per bloccare prompt: tienilo
  presente nei test (quando un prompt è bloccato, `is_supported_*()` torna `false` e i
  metodi `generate_*()` tornano `WP_Error`).

---

## 4. Architettura (data-flow)

```
[Sidebar Gutenberg]
   │  click "Analizza link interni"
   ▼
[JS] raccoglie i blocchi testuali (plain text + clientId + index) del post
   │  POST  /wp-json/semantic-ai/v1/suggest   (nonce X-WP-Nonce, postId)
   ▼
[REST controller PHP]  permission_callback: current_user_can('edit_post', $post_id)
   │  1) CandidateProvider → WP_Query post+page (esclude il post corrente, pre-filtro per
   │     rilevanza) → lista { id, title, url, excerpt, terms }
   │  2) LinkSuggester → wp_ai_client_prompt() con system instruction + schema JSON
   │     payload: testo per-blocco (plain text) + lista candidati
   │  3) valida/normalizza la risposta JSON; risolve targetId → URL/titolo dai candidati
   ▼
[JSON di risposta] { links: [...], emphasis: [...] }  (vedi §6)
   ▼
[JS Modal] mostra i suggerimenti con checkbox + motivazione + anteprima dell'ancora
   │  utente seleziona → "Applica selezionati"
   ▼
[JS apply] per ogni suggerimento selezionato:
   - trova il blocco via clientId, l'occorrenza n-esima del testo ancora, calcola offset
   - @wordpress/rich-text: create({html}) → applyFormat(core/link|core/bold|core/italic)
     → toHTMLString() → wp.data dispatch('core/block-editor').updateBlockAttributes()
   - salta range già formattati / ancore non trovate (log + notice non bloccante)
```

Punti chiave:

- Il **matching testo↔blocco** usa un `blockIndex` (indice progressivo dei blocchi
  testuali inviati) + `anchorText` + `occurrence` (n-esima occorrenza). Niente fuzzy
  matching: l'ancora deve esistere verbatim nel blocco, altrimenti il suggerimento viene
  scartato lato client con avviso.
- Blocchi da scansionare: `core/paragraph`, `core/heading`, `core/list`/`core/list-item`,
  `core/quote`. **Escludi** `core/code`, `core/preformatted`, `core/html`, embed e media.
- Per il **budget token**: invia solo **plain text** dei blocchi (non l'HTML serializzato);
  se l'articolo supera una soglia di caratteri configurabile, gestisci il troncamento o il
  chunking e documenta la scelta.

---

## 5. User flow nella modale

1. Pulsante in sidebar: se `is_supported_for_text_generation()` è `false`, mostra una
   `Notice` ("Configura un provider AI in Impostazioni → Connettori") e disabilita il
   pulsante.
2. Al click: stato di loading (`Spinner`) mentre gira la richiesta REST.
3. Modale con due sezioni:
   - **Link interni**: lista di card → ancora proposta, titolo del contenuto di
     destinazione, motivazione (`rationale`), checkbox.
   - **Enfasi semantica**: frase/keyword, tipo (grassetto/corsivo), motivazione, checkbox.
4. Controlli "Seleziona tutto" / "Deseleziona tutto" per sezione.
5. "Applica selezionati": applica i formati, chiude la modale, mostra un riepilogo
   (`Snackbar`/`Notice`) con quanti suggerimenti applicati e quanti saltati.
6. Gestione errori: messaggi chiari per provider non configurato, errore di rete,
   risposta non valida, nessun suggerimento trovato.

---

## 6. Contratto dati AI (JSON Schema per `as_json_response`)

Definisci e passa **questo** schema (adatta i tipi alle convenzioni del WP AI Client):

```json
{
  "type": "object",
  "properties": {
    "links": {
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "blockIndex":  { "type": "integer" },
          "anchorText":  { "type": "string" },
          "occurrence":  { "type": "integer", "minimum": 1 },
          "targetId":    { "type": "integer" },
          "rationale":   { "type": "string" }
        },
        "required": ["blockIndex", "anchorText", "occurrence", "targetId"]
      }
    },
    "emphasis": {
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "blockIndex":  { "type": "integer" },
          "phrase":      { "type": "string" },
          "occurrence":  { "type": "integer", "minimum": 1 },
          "format":      { "type": "string", "enum": ["bold", "italic"] },
          "rationale":   { "type": "string" }
        },
        "required": ["blockIndex", "phrase", "occurrence", "format"]
      }
    }
  },
  "required": ["links", "emphasis"]
}
```

**Validazione lato server**: scarta gli item con `targetId` non presente tra i candidati,
`blockIndex` fuori range, `anchorText`/`phrase` vuoti. Risolvi `targetId` → `{url, title}`
dai candidati (mai da testo libero del modello).

---

## 7. Prompt engineering (system instruction)

Scrivi la system instruction in modo che l'output rispetti questi criteri (la lingua dei
suggerimenti deve essere **quella dell'articolo**, tipicamente italiano):

- Ruolo: esperto SEO di **internal linking** e leggibilità.
- I link possono puntare **solo** ai `targetId` della lista candidati fornita.
- Le ancore devono essere **descrittive e ricche di keyword**, mai generiche ("clicca qui",
  "leggi di più"); devono essere testo **già presente** nel blocco indicato.
- **Niente over-linking**: massimo ~1 link ogni 100–150 parole, evita più link allo stesso
  `targetId`, evita di linkare la stessa ancora più volte.
- L'**enfasi** (grassetto/corsivo) va usata con parsimonia, solo su keyword o frasi
  davvero portanti; non enfatizzare frasi già dentro un link.
- Restituisci **solo** JSON conforme allo schema, senza testo extra.

Includi nel payload utente: per ogni blocco testuale `{ index, type, text }` e la lista
candidati `{ id, title, url, excerpt }`. Usa `using_temperature(0.2)` per output stabile.

---

## 8. Struttura del progetto (consigliata)

```
semantic-ai/
├── semantic-ai.php     # header plugin, guclausola PHP 8.1, autoload, bootstrap
├── composer.json                   # PSR-4 + dev deps (phpcs/wpcs/phpstan)
├── package.json                    # @wordpress/scripts, @wordpress/env, deps editor
├── .wp-env.json                    # WP 7.0, PHP 8.1, provider Anthropic
├── phpcs.xml.dist                  # ruleset WPCS, testVersion 8.1, text domain
├── phpstan.neon.dist               # livello + bootstrap WordPress
├── .gitignore                      # vendor/, node_modules/, build/
├── CHANGELOG.md                    # Keep a Changelog
├── README.md                       # uso/dev (NIENTE LICENSE)
├── src/                            # PSR-4 Mavida\SemanticInternalLinks\  (solo le classi PHP)
│   ├── Plugin.php                  # registrazione hook, asset, REST
│   ├── Rest/SuggestController.php  # endpoint /suggest, permessi, nonce, validazione
│   ├── Ai/LinkSuggester.php        # wp_ai_client_prompt() + schema + parsing/validazione
│   ├── Content/CandidateProvider.php   # WP_Query post+page, pre-filtro rilevanza
│   └── Content/BlockTextExtractor.php  # estrazione plain text per-blocco (lato server se serve)
├── src/editor/                     # sorgenti JS/JSX (entry per @wordpress/scripts)
│   ├── index.js                    # registerPlugin → PluginSidebar
│   ├── components/Sidebar.jsx
│   ├── components/SuggestionModal.jsx
│   ├── lib/api.js                  # apiFetch verso l'endpoint REST
│   └── lib/apply.js                # rich-text applyFormat + updateBlockAttributes
├── assets/scss/
│   └── editor.scss                 # stili BEM (.sai-modal, .sai-modal__row, ...)
└── build/                          # generato da wp-scripts (gitignored)
```

> Nota: PSR-4 carica solo le **classi PHP** referenziate, quindi la coesistenza con i
> sorgenti JS non crea conflitti. Configura l'entry di `@wordpress/scripts` su
> `src/editor/index.js` (file `package.json`, script di build, eventuale `--webpack-src-dir`).

### Dipendenze JS (editor)

`@wordpress/plugins`, `@wordpress/editor` (o `@wordpress/edit-post` per `PluginSidebar`),
`@wordpress/components`, `@wordpress/data`, `@wordpress/block-editor`,
`@wordpress/rich-text`, `@wordpress/api-fetch`, `@wordpress/i18n`, `@wordpress/element`.
Registra gli script con il file `*.asset.php` generato (deps + version) e accoda solo in
`enqueue_block_editor_assets`.

---

## 9. Qualità del codice e tooling

**Composer (`composer.json`)**

- `require`: `"php": ">=8.1"`.
- `require-dev`: `wp-coding-standards/wpcs`, `dealerdirect/phpcodesniffer-composer-installer`,
  `phpstan/phpstan`, `szepeviktor/phpstan-wordpress`.
- `autoload`: PSR-4 `"Mavida\\SemanticInternalLinks\\": "src/"`.
- `license`: imposta `"proprietary"` (NESSUN file `LICENSE`).
- Script: `"phpcs"`, `"phpcbf"`, `"phpstan"`.

**PHPCS (`phpcs.xml.dist`)**: ruleset `WordPress`, `config testVersion 8.1-`, definisci
`text_domain` = `semantic-ai` e il `prefix` per le funzioni globali; escludi
`vendor/`, `node_modules/`, `build/`.

**PHPStan (`phpstan.neon.dist`)**: includi `szepeviktor/phpstan-wordpress`, parti da
**livello 8** (alza a `max` se passa pulito), `paths: [src]`, e un `bootstrapFiles` per le
costanti/funzioni WP. Aggiungi una baseline solo se strettamente necessario, documentandolo.

**`@wordpress/scripts`**: usa `wp-scripts build` / `wp-scripts start`; gestisce JSX e SCSS.
SCSS in **BEM** (blocco `sai-modal`, elementi `sai-modal__row`, `sai-modal__rationale`,
modificatori `sai-modal__row--applied`).

**`.wp-env.json`**:

```json
{
  "core": "WordPress/WordPress#7.0",
  "phpVersion": "8.1",
  "plugins": [ "." ],
  "lifecycleScripts": {
    "afterStart": "wp-env run cli wp plugin install ai-provider-for-anthropic --activate"
  }
}
```

> Dopo l'avvio dovrò configurare la chiave del provider in **Impostazioni → Connettori**.
> Verifica che, senza provider configurato, il plugin degradi correttamente (UI disabilitata
> con notice), così come previsto in §5.

---

## 10. Sicurezza (checklist)

- Endpoint REST con `permission_callback` = `current_user_can( 'edit_post', $post_id )`.
- Verifica del **nonce** REST (`X-WP-Nonce`, automatico via `wp.apiFetch` con
  `wp_create_nonce('wp_rest')` accodato come dato dell'editor).
- **Sanitizzazione** di tutti gli input (`postId` come intero, testo dei blocchi).
- **Escaping** in output e uso di `wp_kses`/API sicure dove applicabile.
- Args dell'endpoint con `sanitize_callback` e `validate_callback` definiti nello schema.
- Nessun segreto nel repo; nessuna gestione diretta di chiavi API (delegata ai Connectors).
- Applica i formati **solo** a testo esistente; URL solo da `targetId` risolto sui candidati.

---

## 11. CHANGELOG, README, Git

- **`CHANGELOG.md`**: formato Keep a Changelog, sezione `## [Unreleased]` e prima release
  `## [0.1.0] - <data>` con sottosezione `### Added`.
- **`README.md`**: scopo, requisiti (WP 7.0+, PHP 8.1+, un provider AI configurato),
  installazione dev (`composer install`, `npm install`, `npm run build`, `npx wp-env start`),
  comandi di QA (`composer phpcs`, `composer phpstan`). **Niente sezione licenza, niente file
  LICENSE.**
- **Git**:
  1. `git init` (branch `main`), `.gitignore` con `vendor/ node_modules/ build/ .wp-env/`.
  2. Primo commit dopo la Fase 1 (scaffold), poi commit atomici per fase.
  3. Configura il remote: se è disponibile la GitHub CLI usa `gh repo create` (repo privato),
     altrimenti **chiedimi l'URL del remote** e fai `git remote add origin <URL>` + push.
  4. **Non** aggiungere `LICENSE`.

---

## 12. Fasi di lavoro (esegui in ordine, con commit per fase)

1. **Setup ambiente skills + repo**
   - Clona ed installa le Agent Skills WordPress per Claude Code dal repo ufficiale
     (Automattic/agent-skills è archiviato e migrato qui):
     ```bash
     git clone https://github.com/WordPress/agent-skills.git
     cd agent-skills
     node shared/scripts/skillpack-build.mjs --clean
     # globali (~/.claude/skills/) oppure nel progetto:
     node shared/scripts/skillpack-install.mjs --global \
       --skills=wp-plugin-development,wp-block-development,wp-rest-api,wp-abilities-api,wp-phpstan,wp-interactivity-api
     ```
   - `git init` del progetto plugin, `.gitignore`, scaffold cartelle.
2. **Tooling**: `composer.json`, `package.json`, `phpcs.xml.dist`, `phpstan.neon.dist`,
   `.wp-env.json`. Verifica che `composer install`, `npm install`, `npx wp-env start`
   funzionino e che PHPCS/PHPStan girino su uno scheletro vuoto.
3. **Bootstrap PHP**: header plugin (senza riga License), `declare(strict_types=1)`,
   autoload, classe `Plugin` con registrazione hook e accodamento asset editor.
4. **CandidateProvider**: `WP_Query` su `post`+`page`, esclusione post corrente, pre-filtro
   di rilevanza (es. per categorie/tag o ricerca su keyword estratte), output normalizzato.
5. **LinkSuggester + REST**: endpoint `/suggest`, schema JSON, system instruction, chiamata
   `wp_ai_client_prompt()`, parsing e validazione robusti, risoluzione `targetId`.
6. **UI editor**: sidebar + pulsante (con feature detection), modale con due sezioni e
   selezione, chiamata `apiFetch`.
7. **Apply**: logica `@wordpress/rich-text` (`create`/`applyFormat`/`toHTMLString`) +
   `updateBlockAttributes`, calcolo offset per occorrenza, skip dei range già formattati.
8. **Rifinitura**: SCSS BEM, i18n (`__()`, `_x()`, text domain, `wp i18n make-pot`),
   stati di errore/empty, riepilogo applicazioni.
9. **QA finale**: `composer phpcs` e `composer phpstan` puliti; test manuale su wp-env con
   articolo reale; verifica degrado senza provider; aggiorna `CHANGELOG.md`; commit + push.

---

## 13. Definition of Done

- [ ] `composer phpcs` e `composer phpstan` (livello ≥ 8) **senza errori**.
- [ ] `npm run build` produce gli asset; il plugin si attiva su wp-env (WP 7.0 / PHP 8.1).
- [ ] Senza provider configurato: UI disabilitata con notice, nessun errore PHP.
- [ ] Con provider configurato: la modale propone link (verso post/page esistenti) ed enfasi;
      applicando i selezionati il testo dei blocchi viene aggiornato in-place.
- [ ] I link puntano sempre a `targetId` reali; nessun testo AID inserito ex-novo nel post.
- [ ] `CHANGELOG.md` aggiornato, repo git con remote configurato, **nessun file LICENSE**.

---

### Conferme richieste prima di iniziare la Fase 4+ (se rilevante)

- Soglia caratteri oltre la quale fare chunking dell'articolo (default proposto: gestiscilo
  e proponimi un valore).
- Limite massimo di candidati da inviare all'AI (default proposto: 50, pre-filtrati per
  rilevanza).
- Numero massimo di suggerimenti di link/enfasi da proporre (default proposto: 8 link, 10
  enfasi). Procedi con i default e segnalali nel commit se non rispondo.
