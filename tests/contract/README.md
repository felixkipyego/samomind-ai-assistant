# Contract check against Samomind

The plugin and Samomind talk in both directions. Samomind's request models refuse
unknown fields, so a field in the wrong place is a refused message. This folder
holds one real example of every message and a script that checks each one against
Samomind's own models and signing code. Run it before every release.

- `examples/plugin-to-samomind/`: what the plugin sends and what the shop answers,
  recorded from the real code by `tests/integration/ContractExamplesTest.php`.
- `examples/samomind-to-plugin/`: what Samomind sends the shop, built from
  Samomind's own request models and signed with Samomind's own signing code by
  `make_requests.py`. `tests/integration/ContractRequestsTest.php` feeds each one to
  the plugin's handlers and checks that the plugin reproduces the signature and
  accepts the request.
- `validate.py`: checks every example (and the fixed lists: reasons, tracking,
  mail dispatch, API version, limits) and prints one line per message, PASS or FAIL.

All keys and order data are fake.

Version 2 (plugin 0.4.1, order history and product cards) is covered in both
directions: the email scope on `start` and `verify`, `list`, the total and item
images on `lookup`, `capabilities.orders.history`, and the product fields
`image_url`, `price_display` and `regular_price_display` in the sync batch. The
contract text is SamoMind `docs/design/F-3-order-status.md` section 15; the 0.4.1
check ran against SamoMind commit 22913b9 (the account-scope redeem, `RedeemRequestV2`, included).

## Run it

You need Samomind's backend code (read only) and pydantic. The backend image has both.
Export Samomind's `main` first, for example:

    git -C "$SAMOMIND" fetch origin
    mkdir -p /tmp/samomind-main
    git -C "$SAMOMIND" archive origin/main docs backend | tar -x -C /tmp/samomind-main

1. Record the plugin's side (wp-env, from the plugin root; the HPOS setting does not matter):

       npx @wordpress/env@10 run tests-cli --env-cwd=wp-content/plugins/samomind-ai-assistant \
         env SAMOMIND_HPOS=0 SAMOMIND_CONTRACT_WRITE=1 \
         php vendor/bin/phpunit -c phpunit-integration.xml.dist --filter ContractExamplesTest

2. Build Samomind's side (only needed when Samomind's models change):

       docker run --rm --network none --entrypoint python \
         -v /tmp/samomind-main/backend:/work/backend:ro -v "$PWD/tests/contract:/contract" \
         -w /work/backend -e PYTHONPATH=/work/backend -e PYTHONDONTWRITEBYTECODE=1 \
         widgetplatform-backend:dev /contract/make_requests.py /contract/examples/samomind-to-plugin

3. Validate:

       docker run --rm --network none --entrypoint python \
         -v /tmp/samomind-main/backend:/work/backend:ro -v "$PWD/tests/contract:/contract:ro" \
         -v "$PWD:/plugin:ro" -w /work/backend -e PYTHONPATH=/work/backend \
         -e PYTHONDONTWRITEBYTECODE=1 widgetplatform-backend:dev \
         /contract/validate.py --examples /contract/examples --plugin /plugin

   Without the backend image, use a throwaway venv in /tmp (never a global install),
   install what the backend imports, and put `/tmp/samomind-main/backend` on `PYTHONPATH`.

4. The PHP side of step 2's files runs with the normal integration suite
   (`composer integration`): `ContractRequestsTest` reads them.

Exit code 1 means a message does not fit. To add an example, add a call to
`ContractExamplesTest` (an answer or a request the plugin sends) or an entry to
`EXAMPLES` in `make_requests.py` (a request Samomind sends). When the files change
in a way that is not meant, the git diff shows it.
