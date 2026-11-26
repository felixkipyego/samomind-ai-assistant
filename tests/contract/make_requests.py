"""Build one valid example of every request Samomind sends the shop, from Samomind's own
models, and sign it the way Samomind does (the same functions the server calls). The files
go to examples/samomind-to-plugin/ and are read by tests/integration/ContractRequestsTest.php.

Run it where Samomind's backend code and pydantic are installed, for example:

    docker run --rm -v "$SAMOMIND/backend:/work/backend:ro" -v "$PWD:/contract" \
        -w /work/backend widgetplatform-backend:dev python /contract/make_requests.py

Fake values only. The time is fixed, so the files do not change from run to run.
"""
import hashlib
import json
import pathlib
import sys
import uuid

from app.features.woo_order_status import signing, texts
from app.features.woo_order_status.client import _canonical_body
from app.features.woo_order_status.schemas import (
    EndRequest,
    ListRequest,
    LookupRequest,
    PingRequest,
    RedeemRequest,
    ShareChoices,
    ShopLimits,
    StartRequest,
    StartRequestV2,
    VerifyRequest,
    VerifyRequestV2,
)

SECRET = "smok_ContractFixtureSecret0000000000000000000"
KEY_ID = "3f2b6c1e-8d4a-4f0b-9a7e-2c5d1b6e9f10"
TIMESTAMP = "1731412345"
SESSION = hashlib.sha256(b"contract-session").hexdigest()[:32]
ORDER_NUMBER = "SM-1234"
EMAIL = "buyer@example.com"
CHALLENGE = "Zk9Qw2Lm8Vb5Nt0Zr7Yh4Kd"[:22]

# The F-0 defaults, as the design document lists them in section 13.
LIMITS = ShopLimits(
    code_ttl_minutes=10,
    code_tries=3,
    shop_emails_per_hour=20,
    grant_minutes=30,
    account_proof_seconds=60,
    resend_gap_seconds=60,
    sends_per_session_hour=3,
)


def uid(label: str) -> uuid.UUID:
    return uuid.UUID(hashlib.sha256(label.encode()).hexdigest()[:32])


EXAMPLES = {
    "ping": (texts.PATH_PING, PingRequest(request_id=uid("ping"), limits=LIMITS)),
    "start": (
        texts.PATH_START,
        StartRequest(
            request_id=uid("start"),
            session_ref=SESSION,
            order_number=ORDER_NUMBER,
            email=EMAIL,
            limits=LIMITS,
        ),
    ),
    "verify": (
        texts.PATH_VERIFY,
        VerifyRequest(
            request_id=uid("verify"),
            session_ref=SESSION,
            order_number=ORDER_NUMBER,
            code="123456",
            limits=LIMITS,
        ),
    ),
    # Version 2 (order history, plugin 0.4.1): the email scope and the list.
    "start-email": (
        texts.PATH_START,
        StartRequestV2(
            request_id=uid("start-email"),
            session_ref=SESSION,
            scope="email",
            email=EMAIL,
            limits=LIMITS,
        ),
    ),
    "verify-email": (
        texts.PATH_VERIFY,
        VerifyRequestV2(
            request_id=uid("verify-email"),
            session_ref=SESSION,
            scope="email",
            email=EMAIL,
            code="123456",
            limits=LIMITS,
        ),
    ),
    "list": (
        texts.PATH_LIST,
        ListRequest(request_id=uid("list"), session_ref=SESSION, limit=20),
    ),
    "redeem": (
        texts.PATH_REDEEM,
        RedeemRequest(
            request_id=uid("redeem"),
            session_ref=SESSION,
            challenge=CHALLENGE,
            order_number=ORDER_NUMBER,
            limits=LIMITS,
        ),
    ),
    "lookup-share-all": (
        texts.PATH_LOOKUP,
        LookupRequest(
            request_id=uid("lookup-all"),
            session_ref=SESSION,
            order_number=ORDER_NUMBER,
            share=ShareChoices(items=True, tracking=True),
        ),
    ),
    "lookup-share-none": (
        texts.PATH_LOOKUP,
        LookupRequest(
            request_id=uid("lookup-none"),
            session_ref=SESSION,
            order_number=ORDER_NUMBER,
            share=ShareChoices(items=False, tracking=False),
        ),
    ),
    "end": (texts.PATH_END, EndRequest(request_id=uid("end"), session_ref=SESSION)),
}


def main(out: pathlib.Path) -> None:
    out.mkdir(parents=True, exist_ok=True)
    for name, (path, model) in EXAMPLES.items():
        body = _canonical_body(model)
        nonce = hashlib.sha256(name.encode()).hexdigest()[:22]
        headers = signing.request_headers(SECRET, KEY_ID, TIMESTAMP, nonce, path, body)
        example = {
            "message": name,
            "direction": "Samomind to shop",
            "model": type(model).__name__,
            "path": path,
            "route": path.removeprefix("/wp-json"),
            "secret": SECRET,
            "key_id": KEY_ID,
            "timestamp": TIMESTAMP,
            "nonce": nonce,
            "headers": headers,
            "body_raw": body.decode(),
        }
        (out / f"{name}.json").write_text(
            json.dumps(example, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
        )
        print("wrote", name)


if __name__ == "__main__":
    main(pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else "/contract/examples/samomind-to-plugin"))
