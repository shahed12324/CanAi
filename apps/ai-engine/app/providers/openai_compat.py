"""OpenAI chat-completions wire format (OpenAI, Groq, OpenRouter, Ollama /v1, compatible)."""

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


class OpenAICompatAdapter(Adapter):
    name = "openai_compatible"
    default_base_url: str | None = None

    def headers(self) -> dict[str, str]:
        h = {"Content-Type": "application/json"}
        if self._key:
            h["Authorization"] = f"Bearer {self._key}"
        return h

    @staticmethod
    def _message(m: ChatMessage) -> dict[str, object]:
        if not m.images:
            return {"role": m.role, "content": m.content}
        parts: list[dict[str, object]] = [
            {"type": "image_url", "image_url": {"url": f"data:{i.mime};base64,{i.data}"}}
            for i in m.images
        ]
        parts.append({"type": "text", "text": m.content})
        return {"role": m.role, "content": parts}

    def _body(self, req: ChatRequest, stream: bool) -> dict[str, object]:
        body: dict[str, object] = {
            "model": check_model(req.model),
            "messages": [self._message(m) for m in req.messages],
        }
        if req.max_tokens is not None:
            body["max_tokens"] = req.max_tokens
        if req.temperature is not None:
            body["temperature"] = req.temperature
        if stream:
            body["stream"] = True
            body["stream_options"] = {"include_usage": True}
        return body

    async def complete(self, req: ChatRequest) -> ChatResult:
        data = self._json(await self._request("POST", "/chat/completions", self._body(req, False)))
        try:
            choice = data["choices"][0]
            usage = data.get("usage") or {}
            return ChatResult(
                text=choice["message"].get("content") or "",
                input_tokens=usage.get("prompt_tokens"),
                output_tokens=usage.get("completion_tokens"),
                finish_reason=choice.get("finish_reason"),
                model=str(data.get("model", req.model)),
            )
        except (KeyError, IndexError, TypeError, AttributeError):
            raise ProviderError("bad_response", "unexpected provider response") from None

    async def stream(self, req: ChatRequest) -> AsyncIterator[StreamEvent]:
        async for line in self._stream_lines("/chat/completions", self._body(req, True)):
            if not line.startswith("data:"):
                continue
            payload = line[5:].strip()
            if payload == "[DONE]":
                return
            try:
                d = json.loads(payload)
            except ValueError:
                continue
            usage = d.get("usage") or {}
            choices = d.get("choices") or []
            choice = choices[0] if choices else {}
            yield StreamEvent(
                text=(choice.get("delta") or {}).get("content") or "",
                input_tokens=usage.get("prompt_tokens"),
                output_tokens=usage.get("completion_tokens"),
                finish_reason=choice.get("finish_reason"),
            )

    async def check(self) -> None:
        await self._request("GET", "/models")


class OpenAIAdapter(OpenAICompatAdapter):
    name = "openai"
    default_base_url = "https://api.openai.com/v1"


class GroqAdapter(OpenAICompatAdapter):
    name = "groq"
    default_base_url = "https://api.groq.com/openai/v1"


class OpenRouterAdapter(OpenAICompatAdapter):
    name = "openrouter"
    default_base_url = "https://openrouter.ai/api/v1"


class OllamaAdapter(OpenAICompatAdapter):
    name = "ollama"
    default_base_url = "http://localhost:11434/v1"
