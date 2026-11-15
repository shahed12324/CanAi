"""Google Gemini generateContent API. The key travels in a header, never in the URL."""

from __future__ import annotations

import json
import re
from collections.abc import AsyncIterator

from app.providers.base import Adapter, ChatRequest, ChatResult, ProviderError, StreamEvent

_GEMINI_MODEL = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._\-]{0,99}$")


class GeminiAdapter(Adapter):
    name = "gemini"
    default_base_url = "https://generativelanguage.googleapis.com"

    def headers(self) -> dict[str, str]:
        return {"x-goog-api-key": self._key, "Content-Type": "application/json"}

    @staticmethod
    def _model(model: str) -> str:
        if not _GEMINI_MODEL.match(model):
            raise ProviderError("bad_request", "invalid model key")
        return model

    def _body(self, req: ChatRequest) -> dict[str, object]:
        system = "\n\n".join(m.content for m in req.messages if m.role == "system")
        body: dict[str, object] = {
            "contents": [
                {
                    "role": "model" if m.role == "assistant" else "user",
                    "parts": [
                        *(
                            {"inline_data": {"mime_type": i.mime, "data": i.data}}
                            for i in (m.images or [])
                        ),
                        {"text": m.content},
                    ],
                }
                for m in req.messages
                if m.role != "system"
            ]
        }
        if system:
            body["systemInstruction"] = {"parts": [{"text": system}]}
        cfg: dict[str, object] = {}
        if req.max_tokens is not None:
            cfg["maxOutputTokens"] = req.max_tokens
        if req.temperature is not None:
            cfg["temperature"] = req.temperature
        if cfg:
            body["generationConfig"] = cfg
        return body

    @staticmethod
    def _text(d: dict[str, object]) -> tuple[str, str | None]:
        cands = d.get("candidates") or []
        if not isinstance(cands, list) or not cands:
            return "", None
        c = cands[0]
        parts = (c.get("content") or {}).get("parts") or []
        return "".join(p.get("text", "") for p in parts), c.get("finishReason")

    async def complete(self, req: ChatRequest) -> ChatResult:
        path = f"/v1beta/models/{self._model(req.model)}:generateContent"
        data = self._json(await self._request("POST", path, self._body(req)))
        try:
            text, finish = self._text(data)
            u = data.get("usageMetadata") or {}
            return ChatResult(
                text=text,
                input_tokens=u.get("promptTokenCount"),
                output_tokens=u.get("candidatesTokenCount"),
                finish_reason=finish,
                model=req.model,
            )
        except (KeyError, TypeError, AttributeError):
            raise ProviderError("bad_response", "unexpected provider response") from None

    async def stream(self, req: ChatRequest) -> AsyncIterator[StreamEvent]:
        path = f"/v1beta/models/{self._model(req.model)}:streamGenerateContent?alt=sse"
        async for line in self._stream_lines(path, self._body(req)):
            if not line.startswith("data:"):
                continue
            try:
                d = json.loads(line[5:].strip())
            except ValueError:
                continue
            text, finish = self._text(d)
            u = d.get("usageMetadata") or {}
            yield StreamEvent(
                text=text,
                input_tokens=u.get("promptTokenCount"),
                output_tokens=u.get("candidatesTokenCount"),
                finish_reason=finish,
            )

    async def check(self) -> None:
        await self._request("GET", "/v1beta/models")
