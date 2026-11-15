from typing import Annotated

from fastapi import Depends, FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse

from app.routes_bills import router as bills_router
from app.routes_docs import router as docs_router
from app.routes_doctools import router as doctools_router
from app.routes_leads import router as leads_router
from app.routes_office import router as office_router
from app.routes_planner import router as planner_router
from app.routes_providers import router as providers_router
from app.routes_seo import router as seo_router
from app.routes_studio import router as studio_router
from app.routes_video import router as video_router
from app.routes_vision import router as vision_router
from app.routes_voice import router as voice_router
from app.security import AuthContext, require_signed_request

app = FastAPI(title="JARVIS AI Engine", docs_url=None, redoc_url=None, openapi_url=None)
app.include_router(providers_router)
app.include_router(leads_router)
app.include_router(docs_router)
app.include_router(bills_router)
app.include_router(vision_router)
app.include_router(office_router)
app.include_router(voice_router)
app.include_router(studio_router)
app.include_router(video_router)
app.include_router(seo_router)
app.include_router(doctools_router)
app.include_router(planner_router)


@app.exception_handler(RequestValidationError)
async def _invalid(_: Request, exc: RequestValidationError) -> JSONResponse:
    """FastAPI's default 422 echoes the offending input, which would leak api_key values."""
    fields = [".".join(str(p) for p in e["loc"] if p != "body") for e in exc.errors()]
    return JSONResponse({"detail": "invalid_request", "fields": fields}, status_code=422)


@app.get("/healthz")
async def healthz() -> dict[str, str]:
    """Unauthenticated liveness probe for Docker/orchestrator only. Reveals nothing."""
    return {"status": "ok"}


@app.get("/internal/v1/health")
async def internal_health(
    ctx: Annotated[AuthContext, Depends(require_signed_request)],
) -> dict[str, str]:
    """Authenticated round-trip used to prove Laravel -> Python signing works."""
    return {"status": "ok", "tenant_id": ctx.tenant_id}
