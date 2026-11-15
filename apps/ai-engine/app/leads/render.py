"""Optional JavaScript rendering with Playwright (only for sites whose static HTML is nearly empty).

Browser traffic cannot use our connection-level guard, so every request the page makes is checked here: scheme, port and
a DNS lookup that must return only public addresses. This is best-effort for sub-resources; the document itself was
already fetched through the strict path first. Heavy resources are blocked."""

from __future__ import annotations

from app.leads.ssrf import UnsafeUrl, parse_url, resolve_public

BLOCKED_TYPES = {"image", "media", "font", "stylesheet", "websocket", "eventsource"}


class PlaywrightRenderer:
    def __init__(self, allow_private: bool = False, timeout_ms: int = 15000) -> None:
        self._allow_private = allow_private
        self._timeout = timeout_ms

    async def render(self, url: str) -> str | None:
        try:
            from playwright.async_api import async_playwright
        except ImportError:
            return None
        async with async_playwright() as pw:
            browser = await pw.chromium.launch(args=["--no-sandbox", "--disable-dev-shm-usage"])
            try:
                ctx = await browser.new_context(
                    java_script_enabled=True, user_agent="JarvisOneBot/1.0", service_workers="block"
                )

                async def guard(route, request):  # type: ignore[no-untyped-def]
                    if request.resource_type in BLOCKED_TYPES:
                        return await route.abort()
                    try:
                        p = parse_url(request.url, allow_private=self._allow_private)
                        await resolve_public(
                            p.hostname or "",
                            p.port or (443 if p.scheme == "https" else 80),
                            allow_private=self._allow_private,
                        )
                    except UnsafeUrl:
                        return await route.abort()
                    return await route.continue_()

                await ctx.route("**/*", guard)
                page = await ctx.new_page()
                await page.goto(url, wait_until="networkidle", timeout=self._timeout)
                content: str = await page.content()
                return content
            finally:
                await browser.close()
