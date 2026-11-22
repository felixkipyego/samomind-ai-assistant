"""Check the plugin's examples against Samomind's own models and signing code, and print one
line per message: direction, message, model, PASS or FAIL with the reason. Exit code 1 if any
line fails.

Run it where Samomind's backend code and pydantic are installed (the backend image, or a
throwaway venv), with Samomind's backend on the path:

    docker run --rm -v "$SAMOMIND/backend:/work/backend:ro" -v "$PWD:/contract:ro" \
        -v "$PLUGIN:/plugin:ro" -w /work/backend widgetplatform-backend:dev \
        python /contract/validate.py --examples /contract/examples --plugin /plugin

No network, no database, no real key. See README.md.
"""
import argparse
import json
import pathlib
import re
import sys
import typing

from pydantic import ValidationError

from app.features.woo_order_status import signing, texts
from app.features.woo_order_status import schemas as orders
from app.ingest import push
from app.ingest import push_schemas as ingest
from app.install.schemas import PluginConnectRequest

SHOP_MODELS = {
    "PingResponse": orders.PingResponse,
    "StartResponse": orders.StartResponse,
    "VerifyResponse": orders.VerifyResponse,
    "RedeemResponse": orders.RedeemResponse,
    "LookupResponse": orders.LookupResponse,
    "InvalidSignatureBody": orders.InvalidSignatureBody,
    "AccountProof": orders.AccountProof,
}
REQUEST_MODELS = {
    "PingRequest": orders.PingRequest,
    "StartRequest": orders.StartRequest,
    "VerifyRequest": orders.VerifyRequest,
    "RedeemRequest": orders.RedeemRequest,
    "LookupRequest": orders.LookupRequest,
    "EndRequest": orders.EndRequest,
}
INGEST_MODELS = {
    "/api/v1/plugin/connect": ("PluginConnectRequest", PluginConnectRequest),
    "/api/v1/ingest/connect": ("PushConnectRequest", ingest.PushConnectRequest),
    "/api/v1/ingest/checkin": ("PushCheckinRequest", ingest.PushCheckinRequest),
    "/api/v1/ingest/documents": ("PushDocumentsRequest", ingest.PushDocumentsRequest),
    "/api/v1/ingest/documents/delete": ("PushDeleteRequest", ingest.PushDeleteRequest),
    "/api/v1/ingest/sync": ("PushSyncRequest", ingest.PushSyncRequest),
    "/api/v1/ingest/reconcile": ("PushReconcileRequest", ingest.PushReconcileRequest),
    "/api/v1/ingest/disconnect": ("PushDisconnectRequest", ingest.PushDisconnectRequest),
}

rows = []


def record(direction, message, model, problems):
    rows.append((direction, message, model, "PASS" if not problems else "FAIL", "; ".join(problems)))


def reason_of(error: ValidationError) -> str:
    first = error.errors()[0]
    where = ".".join(str(p) for p in first["loc"])
    return f"{where or 'body'}: {first['msg']} ({first['type']})"


def check_shop_answer(example):
    problems = []
    name, model_name = example["message"], example["model"]
    raw = example["body_raw"].encode()
    status = example["status"]

    if model_name:
        try:
            SHOP_MODELS[model_name].model_validate_json(raw)
        except ValidationError as error:
            problems.append(reason_of(error))
    elif status in (403, 429):
        try:
            code = json.loads(raw).get("code")
        except ValueError:
            code = None
        if code not in ("not_enabled", "rate_limited"):
            problems.append(f"403/429 body code is {code!r}, Samomind knows not_enabled and rate_limited")
    elif status == 400:
        # Samomind makes no use of a 400 body: it is a "bad_response" to its client. A valid
        # request never gets one, so this only shows the plugin's reply to a malformed call.
        pass

    if model_name == "InvalidSignatureBody":
        if example["signature"] is not None:
            problems.append("a failed-signature answer must not be signed")
    elif model_name != "AccountProof" and status != 400:
        ok = signing.response_is_valid(
            example["secret"], example["nonce"], status, raw, example["signature"]
        )
        if not ok:
            problems.append("response signature does not verify (nonce, status, body bytes)")

    if model_name == "StartResponse" and status != 202:
        problems.append(f"start answered {status}, Samomind accepts 200 or 202 and the design says 202")
    record("shop to Samomind", name, model_name or f"(status {status} body)", problems)


def check_proof(example):
    problems = []
    proof = json.loads(example["body_raw"])
    try:
        orders.AccountProof.model_validate(proof)
    except ValidationError as error:
        problems.append(reason_of(error))
    else:
        if not signing.proof_is_valid(example["secret"], proof["challenge"], proof["exp"], proof["sig"]):
            problems.append("sig is not HMAC-SHA256(secret, 'samomind-orders-v1-proof\\n1\\n<challenge>\\n<exp>')")
    record("shop to widget to Samomind", example["message"], "AccountProof", problems)


def check_plugin_request(example):
    problems = []
    path = example["path"]
    model_name, model = INGEST_MODELS[path]
    raw = example["body_raw"].encode()
    try:
        model.model_validate_json(raw)
    except ValidationError as error:
        problems.append(reason_of(error))

    if path != "/api/v1/plugin/connect":
        headers = {k: v for k, v in example["headers"].items()}
        expected = push.sign(
            example["secret"],
            headers.get(push.HEADER_TIMESTAMP, ""),
            headers.get(push.HEADER_KEY_ID, ""),
            headers.get(push.HEADER_IDEMPOTENCY_KEY, ""),
            "POST",
            path,
            raw,
        )
        if headers.get(push.HEADER_SIGNATURE) != expected:
            problems.append("signature does not verify with Samomind's own signing function")
        if headers.get(push.HEADER_API_VERSION) not in push.SUPPORTED_API_VERSIONS:
            problems.append(f"API version header {headers.get(push.HEADER_API_VERSION)!r} is not supported")
        if not re.fullmatch(r"[\x21-\x7e]{8,128}", headers.get(push.HEADER_IDEMPOTENCY_KEY, "")):
            problems.append("Idempotency-Key is not 8 to 128 visible ASCII characters")
        if not re.fullmatch(r"[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}", headers.get(push.HEADER_KEY_ID, "")):
            problems.append("key id is not a UUID")
    else:
        body = json.loads(raw)
        if not re.fullmatch(r"smc_[A-Z2-7]{32}", body.get("code", "")):
            problems.append("code is not smc_ and 32 base32 characters")
        if len(raw) > 4096:
            problems.append("body is over 4 KB")
    record("plugin to Samomind", example["message"], model_name, problems)


def check_samomind_request(example):
    problems = []
    model = REQUEST_MODELS[example["model"]]
    raw = example["body_raw"].encode()
    try:
        model.model_validate_json(raw)
    except ValidationError as error:
        problems.append(reason_of(error))
    expected = signing.sign_request(
        example["secret"], example["timestamp"], example["key_id"], example["nonce"], example["path"], raw
    )
    if example["headers"][signing.HEADER_SIGNATURE] != expected:
        problems.append("the example is not signed the way Samomind signs")
    record("Samomind to shop", example["message"], example["model"], problems)


def php_returns(source: str, function: str):
    """The string literals a PHP method returns, read from the source."""
    match = re.search(r"function\s+" + function + r"\s*\([^)]*\)\s*\{(.*?)\n\t\}", source, re.S)
    return sorted(set(re.findall(r"return\s+'([a-z_]+)'\s*;", match.group(1)))) if match else []


def check_static(plugin: pathlib.Path):
    settings = (plugin / "core/includes/classes/class-samomind-orders-settings.php").read_text()
    lookup = (plugin / "core/includes/classes/class-samomind-orders-lookup.php").read_text()
    mail = (plugin / "core/includes/classes/class-samomind-orders-mail.php").read_text()
    auth = (plugin / "core/includes/classes/class-samomind-orders-auth.php").read_text()
    caps = ingest.PushOrdersCapabilities.model_fields

    reasons = php_returns(settings, "disabled_reason")
    problems = [f"reason {r!r} is not in REASON_SENTENCES" for r in reasons if r not in texts.REASON_SENTENCES]
    problems += [f"reason {r!r} is longer than 64" for r in reasons if len(r) > 64]
    if not reasons:
        problems.append("could not read the reasons from the plugin source")
    record("plugin to Samomind", "capabilities.orders.reason (all values)", "REASON_SENTENCES", problems)

    tracking = php_returns(lookup, "tracking_provider")
    allowed = set(typing.get_args(caps["tracking"].annotation))
    record(
        "plugin to Samomind",
        "capabilities.orders.tracking (all values)",
        "PushOrdersCapabilities.tracking",
        [f"{v!r} is not one of {sorted(allowed)}" for v in tracking if v not in allowed]
        or ([] if tracking else ["could not read the values from the plugin source"]),
    )

    dispatch = sorted(set(re.findall(r"'(shutdown|cron_fallback)'", mail)))
    inner = typing.get_args(caps["mail_dispatch"].annotation)
    allowed = {v for v in inner if isinstance(v, str)} or {
        v for part in inner for v in typing.get_args(part)
    }
    record(
        "plugin to Samomind",
        "capabilities.orders.mail_dispatch (all values)",
        "PushOrdersCapabilities.mail_dispatch",
        [] if dispatch and set(dispatch) <= allowed else [f"values {dispatch} are not all in {sorted(allowed)}"],
    )

    api = int(re.search(r"const\s+API_VERSION\s*=\s*(\d+)", settings).group(1))
    record(
        "plugin to Samomind",
        "capabilities.orders.api and ping api",
        "SUPPORTED_ORDERS_API",
        [] if api in texts.SUPPORTED_ORDERS_API else [f"{api} is not in {sorted(texts.SUPPORTED_ORDERS_API)}"],
    )

    plugin_limits = set(re.findall(r"'([a-z_]+)'\s*=>\s*array\(\s*\d+,\s*\d+,\s*\d+\s*\)", auth))
    server_limits = set(orders.ShopLimits.model_fields)
    problems = []
    if plugin_limits != server_limits:
        problems.append(f"plugin reads {sorted(plugin_limits)}, Samomind sends {sorted(server_limits)}")
    for name, default, low, high in re.findall(r"'([a-z_]+)'\s*=>\s*array\(\s*(\d+),\s*(\d+),\s*(\d+)\s*\)", auth):
        field = orders.ShopLimits.model_fields.get(name)
        if field is None:
            continue
        # The plugin's clamp range must lie inside what the model allows, or a value the shop
        # holds the limit to would be one Samomind could not have sent.
        ge = next((m.ge for m in field.metadata if hasattr(m, "ge")), None)
        le = next((m.le for m in field.metadata if hasattr(m, "le")), None)
        if ge is not None and int(low) < ge:
            problems.append(f"{name}: plugin floor {low} is below the model's {ge}")
        if le is not None and int(high) > le:
            problems.append(f"{name}: plugin ceiling {high} is above the model's {le}")
    record("Samomind to shop", "limits (names and ranges)", "ShopLimits", problems)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--examples", required=True)
    parser.add_argument("--plugin", required=True)
    args = parser.parse_args()
    root = pathlib.Path(args.examples)

    for file in sorted((root / "samomind-to-plugin").glob("*.json")):
        check_samomind_request(json.loads(file.read_text(encoding="utf-8")))
    for file in sorted((root / "plugin-to-samomind").glob("*.json")):
        example = json.loads(file.read_text(encoding="utf-8"))
        if example["message"].startswith("shop-account-proof"):
            check_proof(example)
        elif example["message"].startswith("shop-"):
            check_shop_answer(example)
        else:
            check_plugin_request(example)
    check_static(pathlib.Path(args.plugin))

    width = [max(len(r[i]) for r in rows) for i in range(3)]
    for direction, message, model, verdict, why in rows:
        print(f"{direction:<{width[0]}} | {message:<{width[1]}} | {model:<{width[2]}} | {verdict}" + (f" | {why}" if why else ""))
    failed = [r for r in rows if r[3] == "FAIL"]
    print(f"\n{len(rows) - len(failed)} PASS, {len(failed)} FAIL")
    sys.exit(1 if failed else 0)


if __name__ == "__main__":
    main()
