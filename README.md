# Gitmeh API

Uses PHP 8.5 and Laravel 13.

## Tests

```bash
./vendor/bin/pint --test
```

## Static analysis

```bash
./vendor/bin/phpstan analyse
```

## API test

Use this command to test the API.

```bash
curl -skS --request POST \
  --header 'Content-Type: text/plain; charset=UTF-8' \
  --data-binary $'diff --git a/README.md b/README.md\n--- a/README.md\n+++
  b/README.md\n@@ -4,6 +4,11 @@\n ## Intro\n \n Short description.\n+\n+###
  GITMEH_PROBE_OK\n+\n+Document the /gitmeh smoke test: POST a unified diff
  as plain text.\n+\n ## License\n' \
  'https://ai.hellyer.kiwi/gitmeh'
```

## Unit tests

```bash
php artisan test tests/Unit
```

## Feature tests

```bash
php artisan test tests/Feature
```
