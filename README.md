# Chisel

Run allowlisted Artisan commands from the Craft CP — forms built from each command's signature, permission per command, full audit log.

> **Status:** scaffold — nothing user-facing yet. See [PLAN.md](PLAN.md).

## Requirements

- Craft CMS 6.0.0-beta.1 or later (native Craft 6 plugin, no Yii 2 adapter)
- PHP 8.5
- Laravel integration: Artisan. Runs allowlisted Artisan commands through the console kernel.

## Editions and pricing

single edition — Free (provisional).

## Development

```bash
composer install
composer test        # Pest
composer phpstan     # Larastan level 6
composer check-cs    # Pint
```

Tested in the Craft 6 harness at `~/Sites/plugin-testing-craft6` (`bin/plugin-wire craft-chisel --install`).
