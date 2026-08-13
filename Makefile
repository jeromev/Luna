# LunarSystem build helpers

SASS ?= sass
SCSS_SRC := scss/luna.scss
CSS_OUT  := css/luna.css
# Shared flags: UTF-8 without @charset, silence vendored-lib deprecations,
# resolve the vendored baseline grid via a load path, embed the SCSS sources in
# the source map so dev tools resolve compiled rules back to _*.scss:line.
SASS_FLAGS := --no-charset --quiet-deps --load-path=scss/vendor --embed-sources

.PHONY: css css-watch css-min test test-authz test-lockout render-capture render-check resync-triplestore

css: ## Compile scss/ -> css/luna.css (+ css/luna.css.map for dev tools)
	$(SASS) $(SCSS_SRC):$(CSS_OUT) $(SASS_FLAGS) --style=expanded

css-watch: ## Dev loop: recompile on every save (with source maps)
	$(SASS) --watch $(SCSS_SRC):$(CSS_OUT) $(SASS_FLAGS) --style=expanded

css-min: ## Production build: minified, no source map
	$(SASS) $(SCSS_SRC):$(CSS_OUT) --no-source-map --no-charset --quiet-deps --load-path=scss/vendor --style=compressed

test: ## Smoke + security-regression suite (run `docker compose up -d` first)
	BASE=$${BASE:-http://localhost:8080} bash test/regression.sh
	BASE=$${BASE:-http://localhost:8080} bash test/delegated_admin.sh
	BASE=$${BASE:-http://localhost:8080} bash test/admin_lockout.sh

test-authz: ## Delegated-admin privilege-escalation test (per-target authz; mutates DB, self-cleans)
	BASE=$${BASE:-http://localhost:8080} bash test/delegated_admin.sh

render-capture: ## Re-snapshot every page type into the COMMITTED baseline (run when a diff is inspected and accepted)
	BASE=$${BASE:-http://localhost:8080} bash test/render_diff.sh capture

render-check: ## Re-render and assert byte-identical to the committed baseline (proves a change is output-neutral; also runs in CI)
	BASE=$${BASE:-http://localhost:8080} bash test/render_diff.sh check

test-lockout: ## Admin-lockout guardrail test (self-demotion / last-admin / protected nodes; mutates DB, self-cleans)
	BASE=$${BASE:-http://localhost:8080} bash test/admin_lockout.sh

resync-triplestore: ## Rebuild Oxigraph from MySQL: clear + re-project every node (run `docker compose up -d` first)
	docker-compose exec -T app php bin/resync-triplestore.php
