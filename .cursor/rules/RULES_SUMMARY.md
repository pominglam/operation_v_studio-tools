# Cursor Rules Summary

This document provides a categorized summary of all Cursor rules with justifications and application strategies.

## Formatting Rules

**File**: `formatting.mdc`  
**Strategy**: `always`

### PHP Formatting (Laravel Pint)

- **Tool**: Laravel Pint with PSR-12 standard
- **Justification**: Ensures consistent PHP code style aligned with Laravel conventions, improves readability and maintainability
- **Application**: Run on save (optional), required in CI

### JavaScript/TypeScript/Vue Formatting (Prettier)

- **Tool**: Prettier with Vue and Tailwind plugins
- **Justification**: Consistent code formatting across JS/TS/Vue files, Tailwind class ordering improves maintainability
- **Application**: Run on save, required in CI

### EditorConfig

- **File**: `.editorconfig` required
- **Settings**: UTF-8, LF line endings, 4 spaces (PHP), 2 spaces (JS/TS/Vue)
- **Justification**: Cross-editor consistency, prevents line ending issues
- **Application**: Always enforced

---

## Type Safety Rules

**File**: `typesafety.mdc`  
**Strategy**: `always`

### PHP Static Analysis

- **Tool**: PHPStan level 8 or Psalm strict mode
- **Justification**: Catches type errors before runtime, improves code quality and maintainability
- **Application**: Always for new code, baselines allowed for legacy

### PHP Type Declarations

- **Require**: Return types, parameter types, typed properties
- **Justification**: Leverages PHP 8.4 features, improves IDE support, prevents bugs
- **Application**: Always for new code

### TypeScript Strictness

- **Enable**: All strict mode flags (strict, noImplicitAny, strictNullChecks, etc.)
- **Justification**: Catches errors at compile time, improves code reliability
- **Application**: Always enforced

### DTO Type Safety

- **Require**: Typed DTOs, prefer immutable, validate with types
- **Justification**: Type-safe API boundaries, prevents data corruption
- **Application**: Always for API boundaries

---

## Framework-Specific Rules

### Laravel 12.x (`controllers.mdc`, `services.mdc`, `dal.mdc`, `console.mdc`, `architecture.mdc`)

**Strategy**: `targeted`

#### Controllers

- **Max function length**: 40 lines
- **Max file lines**: 300
- **Forbid**: Business logic, direct DB calls
- **Require**: Form requests, Resource classes, return types
- **Justification**: Maintains thin controller pattern, improves testability

#### Services

- **Max function length**: 60 lines
- **Max file lines**: 400
- **Require**: Interfaces for external calls, DTOs, typed exceptions
- **Forbid**: HTTP layer logic
- **Justification**: Clean separation of concerns, testable business logic

#### Data Access Layer

- **Require**: Repository pattern, transactions for multi-write
- **Use**: Eloquent scopes, typed relationships
- **Migrations**: Reversible, indexed foreign keys
- **Justification**: Encapsulates persistence, improves maintainability

#### Console Commands

#### Architecture

- **Principles**: Thin controllers, cohesive services, focused repositories
- **Dependency**: Controller ΓåÆ Service ΓåÆ Repository ΓåÆ DB
- **Organization**: Group by domain/feature where practical
- **Justification**: Keeps system simple, testable, and maintainable
- **Max function length**: 80 lines
- **Require**: Service invocation, kebab-case signatures, progress bars
- **Forbid**: Business logic
- **Justification**: Thin command layer, better UX for long operations

### Vue 3.4.x (`frontend.mdc`)

**Strategy**: `targeted`

#### Component Structure

- **Require**: `<script setup>`, Composition API, PascalCase naming
- **Order**: Script, template, style
- **Justification**: Leverages Vue 3 features, improves code organization

#### TypeScript Integration

- **Require**: Strict mode, explicit types, no `any` escape hatches
- **Justification**: Type-safe Vue components, catches errors early

#### Pinia Stores

- **Use**: Setup syntax, TypeScript, avoid prop drilling
- **Justification**: Type-safe state management, better developer experience

#### Tailwind CSS 4.0.x

- **Use**: CSS-first configuration (`@theme`), utility classes
- **Order**: Layout, spacing, sizing, typography, colors, effects
- **Justification**: Modern Tailwind approach, maintainable styles

#### Vite 6.x

- **Features**: Code splitting, tree shaking, `import.meta.env`
- **Justification**: Optimal build performance, smaller bundles

### Design System Rules (`design_system_rules.mdc`)

**Strategy**: `targeted`

- **Purpose**: Bridge between Figma designs and Vue implementation
- **Content**: Token mapping (CSS vars), component structure, asset management, MCP workflow
- **Justification**: Ensures high fidelity to design and consistent UI implementation
- **Application**: Applied to Vue, CSS, and component files

---

## Testing Rules

**Files**: `testing.mdc`, `tests.mdc`  
**Strategy**: `targeted`

### PHP Testing (Pest 3.x)

- **Organization**: Feature-first with layer subfolders
- **Coverage**: 100% target (exceptions justified)
- **Mocking**: External HTTP calls, no real network calls
- **DBMS**: MySQL 8.4.x (match production)
- **Justification**: Deterministic tests, TDD approach, production parity

### JavaScript/TypeScript Testing (Vitest 2.x)

- **Framework**: Vitest 2.x with @vue/test-utils 2.x
- **Mocking**: Axios, Router
- **Coverage**: Text, HTML, LCOV reporters
- **Justification**: Fast, Vue-native testing, comprehensive coverage

### E2E Testing (Playwright 1.x or Cypress 13.x)

- **Prefer**: Playwright 1.x
- **Pattern**: Page object pattern
- **Data**: Factories, no DB setup in tests
- **Justification**: Reliable E2E tests, maintainable test structure

---

## DevOps & Infrastructure Rules

**File**: `infra.mdc`  
**Strategy**: `always`

### Docker/Sail

- **Prefer**: Direct Composer/PHP for AI; Sail optional for humans
- **Require**: Multi-stage builds, no root user
- **Justification**: Consistent environments, security best practices

### GitHub Actions

- **Require**: Pinned action versions, dependency caching, parallel jobs
- **Workflow**: Lint ΓåÆ Test ΓåÆ Build stages
- **Justification**: Reproducible CI, faster builds, fail-fast approach

### Version Pinning

- **Docker**: Specific image tags
- **Composer**: Pinned minor versions
- **npm**: Pinned minor versions
- **Justification**: Environment parity, prevents unexpected issues

---

## Versioning Rules

**File**: `versioning.mdc`  
**Strategy**: `always`

### Required Versions

- **PHP**: 8.4.x
- **Laravel**: 12.x
- **MySQL**: 8.4.x
- **Node.js**: 20.x LTS
- **Vue**: 3.4.x
- **TypeScript**: 5.7.x
- **Vite**: 6.x
- **Tailwind**: 4.0.x
- **Pest**: 3.x
- **Vitest**: 2.x

### Validation

- **Check in CI**: Yes
- **Fail on mismatch**: Yes
- **Justification**: Environment parity across dev/test/CI/prod

---

## Project-Wide Rules

**File**: `project.mdc`  
**Strategy**: `always`

### Code Quality

- **Prefer**: Composer libraries over custom code
- **Prefer**: SDKs over direct HTTP
- **Require**: Multi-angle reviews, completion checks
- **Justification**: Maintainability, reliability, thoroughness

### Security

- **Never**: Log secrets, commit .env
- **Require**: Timeouts, retries, circuit breakers for external calls
- **Validate**: Env on boot
- **Justification**: Security best practices, reliability

### Database

- **DBMS**: MySQL 8.4.x across all environments
- **Timezone**: America/Toronto (database), user timezone (UI)
- **API**: ISO-8601 timestamps
- **Justification**: Consistency, correct timezone handling

### Documentation

- **Maintain**: Requirements docs, ADRs, changelog
- **Update**: On product decisions
- **Justification**: Knowledge preservation, onboarding

---

## Application Strategy Summary

| Strategy      | When Applied                        | Examples                                                      |
| ------------- | ----------------------------------- | ------------------------------------------------------------- |
| `always`      | Automatically to all relevant files | Formatting, type safety, versioning, infrastructure           |
| `targeted`    | To specific file types/paths        | Layer rules (controllers, services), frontend (Vue/TS), tests |
| `intelligent` | Context-aware (future)              | Enhanced test rules, migration reversibility                  |
| `manual`      | Explicit invocation required        | Major refactors, dependency updates, docs review              |

---

## Implementation Priority

1. **High Priority**: Formatting (`formatting.mdc`), Type Safety (`typesafety.mdc`), Versioning (`versioning.mdc`)
2. **Medium Priority**: Frontend rules (`frontend.mdc`), Testing consolidation (`testing.mdc`)
3. **Low Priority**: Enhanced layer rules (already good), Intelligent context awareness

---

## Success Metrics

- Γ£à All new code follows rules automatically
- Γ£à CI failures clearly identify rule violations
- Γ£à Frontend/backend parity in rule coverage
- Γ£à Reduced manual code review burden
- Γ£à Type safety issues caught before commit
- Γ£à Consistent formatting across codebase
- Γ£à Environment parity via version pinning
