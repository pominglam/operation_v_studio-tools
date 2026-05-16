# Cursor Rules Overview

This folder defines layered rule configurations used by Cursor to guide edits and reviews.

- **Ownership**: set via `owned_by` for each file.
- **Scope**: controlled by `globs` per rule file.
- **Precedence**: more specific rule files (targeting narrower globs) take precedence over broader ones. Where overlap exists:
    1. `testing.mdc` and `tests.mdc` govern test organization, DB alignment, and coverage.
    2. Layer files (`controllers.mdc`, `services.mdc`, `dal.mdc`, `console.mdc`) govern their respective boundaries.
    3. `infra.mdc` governs CI/Docker/infra.
    4. `frontend.mdc`, `typesafety.mdc`, `formatting.mdc`, `versioning.mdc` govern technology-specific concerns.
    5. `project.mdc` is the global baseline and should avoid duplicating layer/test rules.

## Rule Files by Category

### Core Architecture

1. **controllers.mdc** ΓÇö API controllers only; keep thin; no business logic. (`targeted`)
2. **services.mdc** ΓÇö business orchestration; no HTTP; use interfaces for externals. (`targeted`)
3. **dal.mdc** ΓÇö persistence and repositories; transactions for multi-write. (`targeted`)
4. **console.mdc** ΓÇö commands delegate to services; no business logic. (`targeted`)
5. **architecture.mdc** ΓÇö simple high-level principles; layer boundaries and dependency direction. (`always`)

### Testing

5. **testing.mdc** ΓÇö Comprehensive testing rules for Pest (PHP), Vitest (JS/TS), Playwright/Cypress (E2E). (`targeted`)
6. **tests.mdc** ΓÇö Legacy test rules; see `testing.mdc` for comprehensive guidelines. (`targeted`)

### Technology-Specific

7. **frontend.mdc** ΓÇö Vue 3.4.x, TypeScript 5.7.x, Tailwind CSS 4.0.x, Vite 6.x, Pinia 2.1.x. (`targeted`)
8. **design_system_rules.mdc** ΓÇö Design system implementation, tokens, Figma workflow. (`targeted`)
9. **typesafety.mdc** ΓÇö PHPStan/Psalm for PHP, TypeScript strict mode. (`always`)
10. **formatting.mdc** ΓÇö Laravel Pint (PHP), Prettier (JS/TS/Vue), Tailwind class ordering. (`always`)
11. **versioning.mdc** ΓÇö Dependency version pinning for environment parity. (`always`)

### Infrastructure & DevOps

12. **infra.mdc** ΓÇö DevOps, Docker/Sail, CI/CD, GitHub Actions. (`always`)
13. **project.mdc** ΓÇö global governance; SDK preference; env/DB consistency; docs/ADRs. (`always`)

### Documentation catalogue

14. **features-documentation-catalog.mdc** — Keep `docs/features/` (and aligned backend catalog) updated when UI/API/auth/job behavior changes; same granularity as existing screen docs. (`always`)

## Application Strategies

Rules are classified by when they should be applied:

- **`always`**: Applied automatically to all relevant files (global governance)
    - Formatting, type safety, versioning, infrastructure, project-wide rules
- **`targeted`**: Applied only when editing matching files (high signal, low noise)
    - Layer-specific rules (controllers, services, DAL, console)
    - Frontend rules (Vue/TypeScript files and configs)
    - Test files and test tooling configs (Pest, Vitest, Playwright, Cypress)
- **`intelligent`**: Context-aware application (not yet implemented)
    - Enhanced rules for test files
    - Migration file reversibility
- **`manual`**: Require explicit invocation
    - Major refactors, dependency updates
    - Documentation reviews

## Stack Alignment

- **Backend**: PHP 8.4.x, Laravel 12.x, MySQL 8.4.x
- **Frontend**: Vue 3.4.x, TypeScript 5.7.x, Vite 6.x, Tailwind CSS 4.0.x, Pinia 2.1.x, Vue Router 4.4.x
- **Testing**: Pest 3.x (PHP), Vitest 2.x + @vue/test-utils 2.x (JS), Playwright 1.x or Cypress 13.x (E2E)
- **DevOps**: Docker/Sail, GitHub Actions, Node.js 20.x LTS
- **Versioning**: Pinned minor versions for environment parity

## File Format

All `.mdc` rule files use Cursor's official format:

- **YAML frontmatter**: Contains metadata (description, globs, alwaysApply)
- **Markdown content**: Human-readable rules and guidelines

Example:

```markdown
---
description: Rule description
globs:
    - '**/*.vue'
alwaysApply: false
---

# Rule Title

Markdown content with rules...
```

## Change Management

- Avoid duplication; centralize test policies in `testing.mdc`.
- Prefer SDKs over direct HTTP per `project.mdc`.
- Update requirements docs when product decisions change.
- Git hygiene (per `project.mdc`): require `.gitattributes` for EOL normalization, avoid executable bits on text files, recommend `core.filemode=false` on cross-platform setups.

## Why most rules are not `alwaysApply`

- Relevance: Targeted rules only fire where they make sense (e.g., controller rules on controllers/routes/requests).
- Signal-to-noise: Prevents unrelated guidance when editing other layers or technologies.
- Performance: Cursor evaluates fewer rules on each edit.

This repo expands globs to ensure coverage while keeping rules targeted:

- Controllers also match route and form request files
- Services also match service providers and DTOs
- DAL also matches seeders and factories
- Console also matches the console kernel
- Frontend also matches tsconfig/tailwind/postcss configs
- Testing also matches test runner configs (Vitest/Playwright/Cypress)

## Evaluation

See `EVALUATION.md` for detailed analysis of the rule set and recommendations.
