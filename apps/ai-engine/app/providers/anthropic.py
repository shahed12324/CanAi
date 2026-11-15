"""Anthropic Messages API."""

from __future__ import annotations

import json
from collections.abc import AsyncIterator

from app.providers.base import (
    Adapter,
    ChatMessage,
    ChatRequest,
    ChatResult,
    ProviderError,
    StreamEvent,
    check_model,
)


class AnthropicAdapter(Adapter):
    name = "anthropic"
    default_base_url = "https://api.anthropic.com"

    def headers(self) -> dict[str, str]:
        return {
            "x-api-key": self._key,
            "anthropic-version": "2023-06-01",
            "Content-Type": "application/json",
        }

    @staticmethod
    def _content(m: ChatMessage) -> object:
        if not m.images:
            return m.content
        parts: list[dict[str, object]] = [
            {"type": "image", "source": {"type": "base64", "media_type": i.mime, "data": i.data}}
            for i in m.images
        ]
        parts.append({"type": "text", "text": m.content})
        return parts

    def _body(self, req: ChatRequest, stream: bool) -> dict[str, object]:
        system = "\n\n".join(m.content for m in req.messages if m.role == "system")
        body: dict[str, object] = {
            "model": check_model(req.model),
            "max_tokens": req.max_tokens or 1024,
            "messages": [
                {"role": m.role, "content": self._content(m)}
                for m in req.messages
                if m.role != "system"
            ],
        }
        if system:
            body["system"] = system
        if req.temperature is not None:
            body["temperature"] = min(req.temperature, 1.0)
        if stream:
            body["stream"] = True
        return body

    async def complete(self, req: ChatRequest) -> ChatResult:
        data = self._json(await self._request("POST", "/v1/messages", self._body(req, False)))
        try:
            text = "".join(b.get("text", "") for b in data["content"] if b.get("type") == "text")
            usage = data.get("usage") or {}
            return ChatResult(
                text=text,
                input_tokens=usage.get("input_tokens"),
                output_tokens=usage.get("output_tokens"),
                finish_reason=data.get("stop_reason"),
                model=str(data.get("model", req.model)),
            )
        except (KeyError, TypeError, AttributeError):
            raise ProviderError("bad_response", "unexpected provider response") from None

    async def stream(self, req: ChatRequest) -> AsyncIterator[StreamEvent]:
        async for line in self._stream_lines("/v1/messages", self._body(req, True)):
            if not line.startswith("data:"):
                continue
            try:
                d = json.loads(line[5:].strip())
            except ValueError:
                continue
            kind = d.get("type")
            if kind == "content_block_delta":
                yield StreamEvent(text=(d.get("delta") or {}).get("text") or "")
            elif kind == "message_start":
                u = (d.get("message") or {}).get("usage") or {}
                yield StreamEvent(input_tokens=u.get("input_tokens"))
            elif kind == "message_delta":
                u = d.get("usage") or {}
                yield StreamEvent(
                    output_tokens=u.get("output_tokens"),
                    finish_reason=(d.get("delta") or {}).get("stop_reason"),
                )
            elif kind == "error":
                raise ProviderError("upstream_error", "provider stream error")

    async def check(self) -> None:
        await self._request("GET", "/v1/models")
