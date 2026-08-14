<!-- Thanks for contributing! Keep changes small and focused — see CONTRIBUTING.md. -->

## What & why

Briefly: what does this change, and why does it fit a minimal local teaching artifact?

## Checklist

- [ ] PHP lints (`make lint-php`)
- [ ] House style holds (`make fmt-check` and `make lint-house`) — see [docs/coding-style.md](../docs/coding-style.md)
- [ ] Static analysis is clean (`make analyse`)
- [ ] Smoke-tested on Docker (site renders; `?output=jsonld` is valid)
- [ ] Render is unchanged, or the baseline was re-captured deliberately (`make render-check`)
- [ ] Docs updated for anything I changed
- [ ] Version bumped (`luna/luna.php`, the READMEs) + a `CHANGELOG.md` entry
- [ ] No secrets committed; published ports stay bound to `127.0.0.1`
- [ ] No new bundled dependencies (dev tooling lives in `tools/`, never `composer.json`)
