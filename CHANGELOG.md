# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable version. It requires `omnisense/contracts` `^1.0`, `omnisense/omnicrawl` `^1.0` with no stability flag, so `composer require omnicrawl/firecrawl` installs it in a project with Composer's default minimum-stability; the 1.x series follows semantic versioning. A tag `vX.Y.Z` is released by `.github/workflows/release.yml` once the package's CI passes on it.

- The plans moved into `policy.json` (its `plans` member, omnisense/contracts' schema); `plans.json`
  and the `PLANS` constant are gone; the provider reads its policy with `UsagePolicy::forPlan()`.
  Behaviour unchanged.
- First release: Firecrawl as an omnicrawl provider, its usage policy declared as data.
