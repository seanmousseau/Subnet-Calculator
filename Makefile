.PHONY: test-docker

# v3.1.0 #324 — generate a fresh per-run drain token and inject it into BOTH
# the webapp and playwright-tests containers via docker-compose env. The
# token gates admin/_test-drain.php (404 when unset, 403 on mismatch). It
# never lands on disk, never enters git, and is regenerated every invocation.
test-docker:
	@PHPUNIT_TEST_DRAIN_TOKEN="$$(php -r 'echo bin2hex(random_bytes(16));')" ; \
	export PHPUNIT_TEST_DRAIN_TOKEN ; \
	docker compose build webapp playwright-tests ; \
	docker compose run --rm \
	    -e PHPUNIT_TEST_DRAIN_TOKEN \
	    playwright-tests python3 testing/scripts/playwright_test.py
