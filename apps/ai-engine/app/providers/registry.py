from __future__ import annotations

import httpx

from app.providers.anthropic import AnthropicAdapter
from app.providers.base import Adapter, ProviderSpec
from app.providers.gemini import GeminiAdapter
from app.providers.openai_compat import (
    GroqAdapter,
    OllamaAdapter,
    OpenAIAdapter,
    OpenAICompatAdapter,
    OpenRouterAdapter,
)

ADAPTERS: dict[str, type[Adapter]] = {
    "openai": OpenAIAdapter,
    "anthropic": AnthropicAdapter,
    "gemini": GeminiAdapter,
    "openrouter": OpenRouterAdapter,
    "groq": GroqAdapter,
    "ollama": OllamaAdapter,
    "openai_compatible": OpenAICompatAdapter,
}


def build_adapter(spec: ProviderSpec, transport: httpx.AsyncBaseTransport | None = None) -> Adapter:
    return ADAPTERS[spec.adapter](spec, transport)
