"""Polite, bounded, SSRF-safe page fetching + robots.txt."""

from __future__ import annotations

import asyncio
import time
from dataclasses import dataclass
from urllib.parse import urljoin, urlsplit
from urllib.robotparser import RobotFileParser

import httpx

from app.leads.ssrf import GuardedTransport, UnsafeUrl, parse_url

USER_AGENT = "JarvisOneBot/1.0 (+lead research; respects robots.txt)"
MAX_REDIRECTS = 3


class FetchError(Exception):
    def __init__(self, code: str) -> None:
        super().__init__(code)
        self.code = code


@dataclass
class Raw:
    status: int
    headers: httpx.Headers
    url: str
    body: bytes


@dataclass
class Page:
    url: str
    status: int
    html: str
    content_type: str


class SafeFetcher:
    def __init__(
        self,
        *,
        allow_private: bool = False,
        transport: httpx.AsyncBaseTransport | None = None,
        timeout: float = 10.0,
        max_bytes: int = 1_500_000,
        min_delay: float = 1.0,
        max_crawl_delay: float = 10.0,
    ) -> None:
        self.allow_private = allow_private
        self._client = httpx.AsyncClient(
            transport=transport or GuardedTransport(allow_private),
            timeout=httpx.Timeout(timeout, connect=5.0),
            follow_redirects=False,
            headers={
                "User-Agent": USER_AGENT,
                "Accept": "text/html,application/xhtml+xml;q=0.9,*/*;q=0.1",
            },
            trust_env=False,
        )
        self._max_bytes = max_bytes
        self._min_delay = min_delay
        self._max_crawl_delay = max_crawl_delay
        self._next_at: dict[str, float] = {}
        self._lock = asyncio.Lock()
        self._robots: dict[str, RobotFileParser | None] = {}  # None = "everything disallowed"
        self._delays: dict[str, float] = {}

    async def aclose(self) -> None:
        await self._client.aclose()

    async def _wait_turn(self, host: str) -> None:
        async with self._lock:
            delay = max(self._min_delay, min(self._delays.get(host, 0.0), self._max_crawl_delay))
            now = time.monotonic()
            at = max(now, self._next_at.get(host, 0.0))
            self._next_at[host] = at + delay
        if at > now:
            await asyncio.sleep(at - now)

    async def _get_raw(self, url: str, accept_any: bool = False) -> Raw:
        current = url
        for _ in range(MAX_REDIRECTS + 1):
            try:
                p = parse_url(current, allow_private=self.allow_private)
            except UnsafeUrl as e:
                raise FetchError("unsafe_url:" + e.code) from None
            await self._wait_turn(p.netloc.lower())
            try:
                async with self._client.stream("GET", current) as r:
                    if r.is_redirect and "location" in r.headers:
                        current = urljoin(current, r.headers["location"])
                        continue
                    ctype = r.headers.get("content-type", "").split(";")[0].strip().lower()
                    if (
                        not accept_any
                        and r.status_code == 200
                        and ctype not in ("text/html", "application/xhtml+xml", "")
                    ):
                        raise FetchError("not_html")
                    buf = bytearray()
                    async for chunk in r.aiter_bytes():
                        buf += chunk
                        if len(buf) > self._max_bytes:
                            del buf[self._max_bytes :]
                            break
                    return Raw(r.status_code, r.headers, current, bytes(buf))
            except FetchError:
                raise
            except UnsafeUrl as e:
                raise FetchError("unsafe_url:" + e.code) from None
            except httpx.TimeoutException:
                raise FetchError("timeout") from None
            except httpx.HTTPError as e:
                cause = e.__cause__ or e
                if isinstance(cause, UnsafeUrl):
                    raise FetchError("unsafe_url:" + cause.code) from None
                raise FetchError("unreachable") from None
        raise FetchError("too_many_redirects")

    async def get(self, url: str) -> Page:
        raw = await self._get_raw(url)
        enc = httpx.Response(200, headers=raw.headers).encoding or "utf-8"
        return Page(
            raw.url,
            raw.status,
            raw.body.decode(enc, errors="replace"),
            raw.headers.get("content-type", ""),
        )

    async def get_any(self, url: str) -> Page:
        """Like get(), but any content type (sitemap.xml, robots.txt). Same SSRF guard, redirect limit and size cap."""
        raw = await self._get_raw(url, accept_any=True)
        enc = httpx.Response(200, headers=raw.headers).encoding or "utf-8"
        return Page(
            raw.url,
            raw.status,
            raw.body.decode(enc, errors="replace"),
            raw.headers.get("content-type", ""),
        )

    async def allowed(self, url: str) -> bool:
        p = parse_url(url, allow_private=self.allow_private)
        origin = f"{p.scheme}://{p.netloc}"
        if origin not in self._robots:
            self._robots[origin] = await self._load_robots(origin)
        rp = self._robots[origin]
        return rp is not None and rp.can_fetch(USER_AGENT, url)

    async def _load_robots(self, origin: str) -> RobotFileParser | None:
        try:
            raw = await self._get_raw(origin + "/robots.txt", accept_any=True)
        except FetchError:
            return (
                self._open_robots()
            )  # robots.txt unreachable: the page fetch itself will fail if the site is down
        if 500 <= raw.status < 600:
            return None  # server error: be conservative, crawl nothing
        rp = RobotFileParser()
        if raw.status >= 400:
            rp.parse([])  # 4xx: no robots file = everything allowed
        else:
            rp.parse(raw.body.decode("utf-8", errors="replace").splitlines())
        delay = rp.crawl_delay(USER_AGENT)
        if delay:
            self._delays[urlsplit(origin).netloc.lower()] = float(delay)
        return rp

    @staticmethod
    def _open_robots() -> RobotFileParser:
        rp = RobotFileParser()
        rp.parse([])
        return rp
