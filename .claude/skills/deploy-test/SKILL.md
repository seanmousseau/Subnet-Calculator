---
name: deploy-test
description: Deploy the Subnet Calculator to the dev server and run the full CDP browser test suite (125 assertions).
disable-model-invocation: true
---

Deploy and test the Subnet Calculator on the dev server:

1. Run the rsync deploy and copy the iframe fixture:
   ```
   rsync -a --delete Subnet-Calculator/ root@192.168.80.15:/opt/container_data/dev.seanmousseau.com/html/claude/subnet-calculator/
   scp testing/fixtures/iframe-test.html root@192.168.80.15:/opt/container_data/dev.seanmousseau.com/html/claude/subnet-calculator/
   ```
2. Run the Playwright browser test suite:
   ```
   bash -c 'set -a; source ~/.claude/dev-secrets.env; set +a; python3 testing/scripts/playwright_test.py'
   ```
3. Report results: total pass/fail count and details of any failed assertions.
