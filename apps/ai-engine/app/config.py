from functools import lru_cache

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    """Environment-driven settings. No secret has a usable default."""

    model_config = SettingsConfigDict(env_prefix="AI_ENGINE_", env_file=".env", extra="ignore")

    env: str = "local"
    # Shared with Laravel. Two separate secrets: one signs the request, one signs the JWT.
    hmac_secret: str = Field(min_length=32)
    jwt_secret: str = Field(min_length=32)
    jwt_issuer: str = "jarvis-web"
    jwt_audience: str = "jarvis-ai-engine"
    # Maximum clock skew / token lifetime in seconds (blueprint: 60s).
    max_age_seconds: int = 60
    redis_url: str | None = None
    # Lead crawler. allow_private is honoured ONLY when env is local/test; never in production.
    crawler_allow_private: bool = False
    crawler_min_delay: float = 1.0
    crawler_concurrency: int = 4


@lru_cache
def get_settings() -> Settings:
    return Settings()  # type: ignore[call-arg]
