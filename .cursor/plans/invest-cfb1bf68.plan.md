<!-- cfb1bf68-7a9a-41d4-a08b-34a88d5ddd3b 490ff864-0e8c-4c5b-96b1-a04a5a63a845 -->
# Update CI/CD Docker Caching Strategy

## Goals

1. **Staging**: Keep fast feedback with Docker build caching enabled, but ensure we can easily force clean builds when needed.
2. **Preprod**: Prioritize fidelity to production; avoid risky caching that could hide build problems.
3. **Production**: Maximize safety and reproducibility; avoid caching that could result in stale or inconsistent images.

## High-Level Approach

1. **Define environment-specific caching policy**

- **Staging** (`deploy-staging.yml`): Enable Docker build caching using `type=gha` (or `type=registry` in the future), but keep a simple way to temporarily disable cache for troubleshooting.
- **Preprod** (`deploy-preprod.yml`): Disable Docker build cache to ensure each build is clean and mirrors production behavior.
- **Production** (`deploy-prod.yml`): Also disable Docker build cache to guarantee clean, reproducible release images.

2. **Update staging workflow** (`.github/workflows/deploy-staging.yml`)

- Re-enable the `cache-from` / `cache-to` configuration for the `docker/build-push-action` step.
- Add inline comments documenting how and when to temporarily comment out caching for a forced clean build.

3. **Update preprod workflow** (`.github/workflows/deploy-preprod.yml`)

- Remove or comment out `cache-from` and `cache-to` from the Docker build step so that every preprod build is a fresh build.
- Ensure tags remain based on `${{ github.sha }}` so each image is uniquely tied to a commit.

4. **Update production workflow** (`.github/workflows/deploy-prod.yml`)

- Confirm there is no Docker build caching configured (keep building from scratch for each `v*` tag).
- Add comments explaining that production intentionally avoids caching for safety.

5. **Document the strategy**

- Add a short section to the CI/CD documentation (e.g., `docs/CI-CD.md` or `README.md`) describing:
 - The caching policy per environment.
 - How to trigger a clean build in staging when investigating issues.
 - Rationale: speed vs safety trade-offs.

## Implementation Todos

1. **update-staging-workflow**: Re-enable and document Docker build caching in `.github/workflows/deploy-staging.yml`.
2. **update-preprod-workflow**: Remove/disable Docker caching in `.github/workflows/deploy-preprod.yml` so builds are always clean.
3. **verify-prod-workflow**: Double-check that `.github/workflows/deploy-prod.yml` uses no Docker build caching and add comments explaining why.
4. **update-ci-docs**: Update CI/CD documentation to capture the new caching policy and how to perform clean builds when needed.

### To-dos

- [ ] Confirm the exact Git commit SHA / build version running on local vs staging to ensure they truly match, not just branch name.
- [ ] Compare rendered HTML and JS/CSS asset entrypoints between local and staging admin login pages to see how the UI is being bootstrapped in each environment.
- [ ] Inspect backend routes/views and frontend entrypoints for environment-conditional logic or feature flags that affect which admin login UI is rendered.
- [ ] Review CI/CD pipeline and container/image configuration for staging to ensure it deploys the correct branch/image and doesn’t hardwire the new UI.
- [ ] Based on the findings, identify whether the difference is due to environment flags, CI/CD configuration, or build artifacts and decide how local and staging should be aligned.
- [ ] Review CI/CD pipeline cache settings, specifically Docker layer caching and GHA caches for npm/build artifacts.
- [ ] Modify .github/workflows/deploy-staging.yml to comment out Docker build cache configuration (cache-from/cache-to).
- [ ] Commit and push the change to trigger the new CI configuration (or allow manual trigger).
- [ ] Investigate why 'NunoMaduro\Collision\Adapters\Laravel\CollisionServiceProvider' is missing in the container environment.
- [ ] Review composer.json and composer.lock to check if Collision is in 'require' or 'require-dev' and where it is being used.
- [ ] Check config/app.php or service provider registration to see if Collision is conditionally loaded.
- [ ] Check deploy-preprod.yml and deploy-prod.yml for caching configurations similar to staging.
- [ ] Check if preprod/prod environments install dev dependencies or run in a mode that might conflict with the now-removed bootstrap cache files (though removing them is generally good).
- [ ] Create a plan to update caching strategy in all workflow files (staging, preprod, prod).
- [ ] Modify deploy-staging.yml to restore caching but with a more robust key (e.g. including composer.lock hash) or use 'registry' cache type.
- [ ] Modify deploy-preprod.yml and deploy-prod.yml to implement the chosen caching strategy (e.g. registry cache or no cache for safer releases).
- [ ] Confirm the exact Git commit SHA / build version running on local vs staging to ensure they truly match, not just branch name.
- [ ] Compare rendered HTML and JS/CSS asset entrypoints between local and staging admin login pages to see how the UI is being bootstrapped in each environment.
- [ ] Inspect backend routes/views and frontend entrypoints for environment-conditional logic or feature flags that affect which admin login UI is rendered.
- [ ] Review CI/CD pipeline and container/image configuration for staging to ensure it deploys the correct branch/image and doesn’t hardwire the new UI.
- [ ] Based on the findings, identify whether the difference is due to environment flags, CI/CD configuration, or build artifacts and decide how local and staging should be aligned.