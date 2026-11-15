"""HTML -> PDF with headless Chromium. No JavaScript, no network: the only things the page may load are data: URIs."""

from __future__ import annotations

from app.docs.html import build_html
from app.docs.models import ProposalDoc


class PdfUnavailable(RuntimeError):
    """Playwright or its Chromium is not installed in this image."""


def allowed_request(url: str) -> bool:
    return url.startswith("data:") or url == "about:blank"


FOOTER = (
    '<div style="width:100%;font-size:8px;color:#777;text-align:center;font-family:sans-serif">'
    '<span class="pageNumber"></span> / <span class="totalPages"></span></div>'
)


async def render_pdf(doc: ProposalDoc) -> bytes:
    try:
        from playwright.async_api import async_playwright
    except ImportError as e:
        raise PdfUnavailable("playwright not installed") from e
    html = build_html(doc)
    async with async_playwright() as pw:
        try:
            browser = await pw.chromium.launch(args=["--no-sandbox", "--disable-dev-shm-usage"])
        except Exception as e:  # noqa: BLE001 - browser binary missing / cannot start
            raise PdfUnavailable("chromium cannot start") from e
        try:
            ctx = await browser.new_context(java_script_enabled=False, service_workers="block")

            async def guard(route, request):  # type: ignore[no-untyped-def]
                if allowed_request(request.url):
                    await route.continue_()
                else:
                    await route.abort()

            await ctx.route("**/*", guard)
            page = await ctx.new_page()
            await page.set_content(html, wait_until="load")
            pdf_bytes: bytes = await page.pdf(
                format="A4",
                print_background=True,
                prefer_css_page_size=True,
                display_header_footer=True,
                header_template="<div></div>",
                footer_template=FOOTER,
                margin={"top": "18mm", "bottom": "20mm", "left": "16mm", "right": "16mm"},
            )
            return pdf_bytes
        finally:
            await browser.close()
