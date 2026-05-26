# Semantic Internal Links

Plugin WordPress che analizza semanticamente un articolo nell'editor Gutenberg e propone, tramite **WP AI Client** nativo (WordPress 7.0), una topologia di link interni e un'enfasi semantica (grassetto/corsivo) su keyword e frasi chiave.

I suggerimenti vengono mostrati in una modale come anteprima: l'utente seleziona quelli da applicare e il plugin inserisce **solo formati** (`core/link`, `core/bold`, `core/italic`) su testo già presente nei blocchi — mai testo generato dall'AI.

## Requisiti

- WordPress 7.0+
- PHP 8.1+
- Un provider AI configurato in **Impostazioni → Connettori** (es. AI Provider for Anthropic)

## Sviluppo locale

```bash
# 1. Dipendenze PHP
composer install

# 2. Dipendenze JS
npm install

# 3. Build asset editor
npm run build

# 4. Avvia ambiente wp-env (WordPress 7.0 + PHP 8.1)
npx wp-env start

# 5. (Opzionale) Modalità watch per sviluppo
npm run start
```

Dopo `wp-env start`, configura la chiave API in **Impostazioni → Connettori**.

## Comandi QA

```bash
# PHPCS (WordPress Coding Standards)
composer phpcs

# PHPCS auto-fix
composer phpcbf

# PHPStan analisi statica (livello 8)
composer phpstan
```

## Struttura

```
src/                  # Classi PHP (PSR-4 Mavida\SemanticInternalLinks\)
src/editor/           # Sorgenti JavaScript/JSX (Gutenberg)
assets/scss/          # Stili SCSS in metodologia BEM
build/                # Asset compilati (generato da npm run build)
```

## Impostazioni

Disponibili in **Impostazioni → Semantic Internal Links**:

| Parametro | Default | Descrizione |
|---|---|---|
| Max candidati | 50 | Post/page da inviare all'AI come destinazioni possibili |
| Max link | 8 | Numero massimo di suggerimenti link per analisi |
| Max enfasi | 10 | Numero massimo di suggerimenti grassetto/corsivo |
| Soglia chunking | 20.000 char | Articoli più lunghi vengono analizzati a chunk |
| Post type attivi | post, page | Tipi di contenuto da includere come candidati |
| TTL cache | 86.400 sec | Durata cache risposte AI (24 ore) |
