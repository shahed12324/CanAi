"""Real-provider smoke test, no Laravel needed.

    export PROVIDER_API_KEY=...            # read from the environment, never from argv/history
    python -m scripts.provider_smoke --adapter openai --model <model-key>
    python -m scripts.provider_smoke --adapter ollama --model llama3 --allow-private

Runs the connection check, then a 1-token completion and a short stream. Prints only
status, latency and token counts — never the key or raw upstream bodies.
"""

import argparse
import asyncio
import os
import sys
import time

from pydantic import SecretStr

from app.providers.base import ChatMessage, ChatRequest, ProviderError, ProviderSpec
from app.providers.registry import build_adapter


async def run(a: argparse.Namespace) -> int:
    key = os.environ.get("PROVIDER_API_KEY")
    if not key and a.adapter != "ollama":
        print("Set PROVIDER_API_KEY in the environment.", file=sys.stderr)
        return 2
    spec = ProviderSpec(
        adapter=a.adapter,
        base_url=a.base_url,
        api_key=SecretStr(key) if key else None,
        allow_private=a.allow_private,
        timeout_s=a.timeout,
    )
    req = ChatRequest(
        model=a.model, messages=[ChatMessage(role="user", content="Reply with: ok")], max_tokens=16
    )
    try:
        adapter = build_adapter(spec)
    except ProviderError as e:
        print(f"FAIL config   {e.code}: {e.message}")
        return 2
    failed = False

    for name in ("check", "complete", "stream"):
        t0 = time.perf_counter()
        try:
            if name == "check":
                await adapter.check()
                detail = ""
            elif name == "complete":
                r = await adapter.complete(req)
                detail = (
                    f"tokens in/out = {r.input_tokens}/{r.output_tokens}, chars = {len(r.text)}"
                )
            else:
                n = 0
                async for ev in adapter.stream(req):
                    n += len(ev.text)
                detail = f"streamed chars = {n}"
            print(f"PASS {name:8} {int((time.perf_counter() - t0) * 1000):>5} ms  {detail}")
        except ProviderError as e:
            failed = True
            print(f"FAIL {name:8} {e.code}: {e.message}")
    return 1 if failed else 0


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("--adapter", required=True)
    p.add_argument("--model", required=True)
    p.add_argument("--base-url")
    p.add_argument("--allow-private", action="store_true")
    p.add_argument("--timeout", type=float, default=30)
    sys.exit(asyncio.run(run(p.parse_args())))


if __name__ == "__main__":
    main()
