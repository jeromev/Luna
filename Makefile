# Luna build helpers

# Default PHP to the containerised php:8.3-cli the project standardizes on (there is usually no
# local php CLI on the dev host). Override with `make PHP=php …` if you have PHP installed.
PHP  ?= docker run --rm -v "$(CURDIR)":/app -w /app php:8.3-cli php
SASS ?= sass
SCSS_SRC := scss/luna.scss
CSS_OUT  := css/luna.css
# Shared flags: UTF-8 without @charset, silence vendored-lib deprecations,
# resolve the vendored baseline grid via a load path, embed the SCSS sources in
# the source map so dev tools resolve compiled rules back to _*.scss:line.
SASS_FLAGS := --no-charset --quiet-deps --load-path=scss/vendor --embed-sources

# First-party PHP: everything we ship or run, minus the vendored trees. luna/luna.lib/arc (ARC2)
# and vendor/ (Composer) are third-party and are never linted, formatted or analysed here.
PHP_SRC := index.php luna/luna.php luna/luna.classes luna/luna.mods bin test

# Developer tooling lives in tools/ under its OWN composer manifest — never the root one. The
# committed vendor/ tree is the deployment, and deps-audit.yml audits the root lock file without
# --no-dev, so a dev dependency there would enlarge the shipped artifact's advisory surface.
# tools/composer.{json,lock} are committed (versions pinned); tools/vendor/ is gitignored and
# restored by `make tools`. Every target below fails with a pointer if it is missing.
TOOLS_DIR := tools
# memory_limit=-1: PHPStan must PARSE the vendored trees (ARC2 + Composer) to resolve symbols
# even though it does not analyse them, and that exceeds the php:8.3-cli default of 128M.
CSFIX := docker run --rm -v "$(CURDIR)":/app -w /app php:8.3-cli php -d memory_limit=-1 $(TOOLS_DIR)/vendor/bin/php-cs-fixer
PHPSTAN := docker run --rm -v "$(CURDIR)":/app -w /app php:8.3-cli php -d memory_limit=-1 $(TOOLS_DIR)/vendor/bin/phpstan

.PHONY: css css-watch css-min test test-authz test-lockout test-multilingual test-naming migrate-texts migrate-texts-apply render-capture render-check resync-triplestore tools lint-php fmt fmt-check analyse lint-house check token-diff

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
	BASE=$${BASE:-http://localhost:8080} bash test/multilingual.sh
	BASE=$${BASE:-http://localhost:8080} bash test/naming_split.sh

test-authz: ## Delegated-admin privilege-escalation test (per-target authz; mutates DB, self-cleans)
	BASE=$${BASE:-http://localhost:8080} bash test/delegated_admin.sh

render-capture: ## Re-snapshot every page type into the COMMITTED baseline (run when a diff is inspected and accepted)
	BASE=$${BASE:-http://localhost:8080} bash test/render_diff.sh capture

render-check: ## Re-render and assert byte-identical to the committed baseline (proves a change is output-neutral; also runs in CI)
	BASE=$${BASE:-http://localhost:8080} bash test/render_diff.sh check

test-lockout: ## Admin-lockout guardrail test (self-demotion / last-admin / protected nodes; mutates DB, self-cleans)
	BASE=$${BASE:-http://localhost:8080} bash test/admin_lockout.sh

test-naming: ## Routing key vs display name: luna:lid in the store, schema:name for the label (decision #9)
	BASE=$${BASE:-http://localhost:8080} bash test/naming_split.sh

test-multilingual: ## Multilingual content: translations coexist, no clobber, language honoured, graph tagged (mutates DB, self-cleans)
	BASE=$${BASE:-http://localhost:8080} bash test/multilingual.sh

migrate-texts: ## Report what luna_texts needs to reach one-row-per-(node,language) — reads only
	docker-compose exec -T app php bin/migrate-texts.php

migrate-texts-apply: ## Same, but write: normalise blank languages, collapse duplicates, add the UNIQUE key
	docker-compose exec -T app php bin/migrate-texts.php --apply







resync-triplestore: ## Rebuild Oxigraph from MySQL: clear + re-project every node (run `docker compose up -d` first)
	docker-compose exec -T app php bin/resync-triplestore.php

# --- code quality -------------------------------------------------------------------------
# The house standard is PSR-12 with named deviations; docs/coding-style.md is the document,
# .php-cs-fixer.dist.php and phpstan.dist.neon are the machines that hold it.

tools: ## Install the pinned dev tooling into tools/vendor/ (gitignored; never enters the root composer.json)
	docker run --rm -v "$(CURDIR)/$(TOOLS_DIR)":/app -w /app composer:2 composer install --no-interaction

# 8.1 is the supported floor (bin/preflight.php, docs/going-public.md); 8.3 is the tested stack.
# Override to check the floor:  make lint-php PHP_LINT_IMAGE=php:8.1-cli
PHP_LINT_IMAGE ?= php:8.3-cli

lint-php: ## php -l across every first-party PHP file (vendored trees excluded)
	@# The quieting happens INSIDE the container so the target's status is the container's, not
	@# grep's. grep exits 0 only when it printed a line it did not expect — i.e. a diagnostic —
	@# so `&& exit 1` fires exactly then. A clean run leaves grep at 1 and falls through to 0.
	docker run --rm -v "$(CURDIR)":/app -w /app $(PHP_LINT_IMAGE) sh -c '\
	  find $(PHP_SRC) -name "*.php" -print0 | xargs -0 -n1 php -l \
	    | grep -v "^No syntax errors" && exit 1; exit 0'
	@echo "lint-php ($(PHP_LINT_IMAGE)): OK"

fmt: ## Apply the house style (writes)
	@[ -x $(TOOLS_DIR)/vendor/bin/php-cs-fixer ] || { echo "run 'make tools' first"; exit 1; }
	$(CSFIX) fix --config=.php-cs-fixer.dist.php

fmt-check: ## Assert the house style without writing (CI gate)
	@[ -x $(TOOLS_DIR)/vendor/bin/php-cs-fixer ] || { echo "run 'make tools' first"; exit 1; }
	$(CSFIX) fix --config=.php-cs-fixer.dist.php --dry-run --diff

analyse: ## PHPStan static analysis — no baseline file, ever (see docs/coding-style.md)
	@[ -f $(TOOLS_DIR)/vendor/bin/phpstan ] || { echo "run 'make tools' first"; exit 1; }
	$(PHPSTAN) analyse -c phpstan.dist.neon --no-progress

# LUNA_RATCHET_ACCEPT has to cross the container boundary or `make lint-house` cannot see it.
lint-house: ## The project's own invariants: fold markers, ontology namespace, mod contract, ratchets
	docker run --rm -e LUNA_RATCHET_ACCEPT -v "$(CURDIR)":/app -w /app php:8.3-cli php test/style.php
	$(PHP) test/mod_contract.php

check: lint-php fmt-check analyse lint-house ## Everything a PR must pass that needs no running stack

# REV=<rev> to compare against something other than HEAD, e.g. `make token-diff REV=HEAD~3`.
token-diff: ## Prove the working tree changed no executable tokens vs a revision (comment/layout-only commits)
	@rm -rf .token-baseline && mkdir -p .token-baseline
	@git diff --name-only $${REV:-HEAD} -- '*.php' | while read -r f; do \
	  mkdir -p ".token-baseline/$$(dirname "$$f")"; \
	  git show "$${REV:-HEAD}:$$f" > ".token-baseline/$$f" 2>/dev/null || rm -f ".token-baseline/$$f"; \
	done
	@$(PHP) tools/token-diff.php .token-baseline; rc=$$?; rm -rf .token-baseline; exit $$rc
