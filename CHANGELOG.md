# Changelog

## 1.x (unreleased)

- The plans moved into `policy.json` (its `plans` member, omnisense/contracts' schema); `plans.json`
  and the `PLANS` constant are gone; the provider reads its policy with `UsagePolicy::forPlan()`.
  Behaviour unchanged.
- First release: Firecrawl as an omnicrawl provider, its usage policy declared as data.
