.PHONY: test-docker

test-docker:
	docker compose run --rm playwright-tests python3 testing/scripts/playwright_test.py
