# Cursor Rules Quick Reference

## Rule Files at a Glance

| File                      | Purpose                           | Strategy   | Applies To                  |
| ------------------------- | --------------------------------- | ---------- | --------------------------- |
| `project.mdc`             | Global governance, best practices | `always`   | All files                   |
| `formatting.mdc`          | Code formatting standards         | `always`   | All files                   |
| `typesafety.mdc`          | Type safety enforcement           | `always`   | PHP, TS files               |
| `versioning.mdc`          | Dependency version pinning        | `always`   | composer.json, package.json |
| `infra.mdc`               | DevOps, CI/CD, Docker             | `always`   | Infra files                 |
| `frontend.mdc`            | Vue, TypeScript, Tailwind         | `targeted` | _.vue, _.ts, JS files       |
| `design_system_rules.mdc` | Design system & Figma integration | `targeted` | Vue, CSS, UI files          |
| `testing.mdc`             | Pest, Vitest, E2E testing         | `targeted` | Test files                  |
| `tests.mdc`               | Legacy PHP test rules             | `targeted` | tests/\*\*                  |
| `controllers.mdc`         | API controllers                   | `targeted` | Controllers                 |
| `services.mdc`            | Business logic layer              | `targeted` | Services                    |
| `dal.mdc`                 | Data access layer                 | `targeted` | DAL, Models, Migrations     |
| `console.mdc`             | Console commands                  | `targeted` | Commands                    |
| `architecture.mdc`        | Simple architecture principles    | `always`   | app/\*\*                    |

## Stack Versions (Must Match)

- **PHP**: 8.4.x
- **Laravel**: 12.x
- **MySQL**: 8.4.x
- **Node.js**: 20.x LTS
- **Vue**: 3.4.x
- **Vue Router**: 4.4.x
- **Pinia**: 2.1.x
- **TypeScript**: 5.7.x
- **Vite**: 6.x
- **Tailwind**: 4.0.x
- **Pest**: 3.x
- **Vitest**: 2.x
- **@vue/test-utils**: 2.x
- **Playwright**: 1.x (preferred) or **Cypress**: 13.x

## Key Rules Summary

### PHP / Laravel

- Γ£à Use Laravel Pint (PSR-12)
- Γ£à PHPStan level 8+
- Γ£à Strict types preferred
- Γ£à Type declarations required
- Γ£à Controllers: max 40 lines per method, no business logic
- Γ£à Services: max 60 lines, use DTOs
- Γ£à DAL: Repository pattern, transactions for multi-write
- Γ£à Architecture: Controller ΓåÆ Service ΓåÆ Repository; group by domain; avoid cycles

### Vue / TypeScript

- Γ£à `<script setup>` syntax
- Γ£à TypeScript strict mode
- Γ£à Composition API preferred
- Γ£à Tailwind CSS 4.0 with `@theme`
- Γ£à Pinia stores with TypeScript

### Testing

- Γ£à 100% coverage target
- Γ£à MySQL 8.4.x for tests
- Γ£à Mock external calls
- Γ£à TDD approach

### DevOps

- Γ£à Pin all versions
- Γ£à Docker multi-stage builds
- Γ£à GitHub Actions with pinned versions
- Γ£à CI: Lint ΓåÆ Test ΓåÆ Build

## Common Patterns

### Vue Component Structure

```vue
<script setup lang="ts">
// Script first
</script>

<template>
    <!-- Template second -->
</template>

<style scoped>
/* Style last */
</style>
```

### Laravel Controller

```php
<?php

declare(strict_types=1);

class MyController extends Controller
{
    public function __invoke(MyRequest $request): JsonResource
    {
        $result = app(MyService::class)->handle($request->validated());
        return MyResource::make($result);
    }
}
```

### Laravel Service

```php
<?php

declare(strict_types=1);

class MyService
{
    public function handle(array $data): MyDTO
    {
        // Use DTOs, return types
    }
}
```

## Need More Details?

- **Evaluation**: See `EVALUATION.md` for analysis
- **Summary**: See `RULES_SUMMARY.md` for detailed rules
- **Overview**: See `README.md` for file organization
