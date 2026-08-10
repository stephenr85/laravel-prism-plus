> You are in **rushing/laravel-prism-plus** — wraps Prism and adds the LLM modalities it has no slot for (rerank, and later audio/video) as parallel first-class capabilities reusing Prism's own provider credentials.

Laravel package. Delegates every modality `prism-php/prism` already owns straight through
untouched, then layers on a small string-keyed registry-of-registries for the missing
capabilities. Built on `rushing/laravel-popcorn` (registry kernel) and `rushing/prism-cassette`
(record/replay fixtures), with typed `spatie/laravel-data` value objects throughout.

## Vendored family-package conventions

Any repo that vendors another family repo's code (composer `vendor/<vendor>/<pkg>/`, npm
`node_modules/<vendor>/<pkg>/`) checks that vendored repo's own `AGENTS.md` for conventions it
ships with itself before editing through into it.
