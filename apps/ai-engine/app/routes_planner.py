"""Internal planner endpoint (signed requests only): goal in -> validated draft plan out. Stateless; Laravel charges credits."""

from __future__ import annotations

from typing import Annotated

from fastapi import APIRouter, Depends
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field

from app.planner.plan import DEFAULT_KINDS, KINDS, MAX_GOAL, PlanInvalid, build_messages, extract_json, review, validate_plan
from app.providers.base import ChatMessage, ChatRequest, ProviderError, ProviderSpec, check_model
from app.routes_providers import _adapter, _err
from app.security import AuthContext, require_signed_request

router = APIRouter(prefix="/internal/v1")


class PlanRequest(BaseModel):
    provider: ProviderSpec
    model: str = Field(max_length=100)
    goal: str = Field(min_length=3, max_length=MAX_GOAL)
    roots: list[str] = Field(min_length=1, max_length=20)
    kinds: list[str] = Field(default=DEFAULT_KINDS, max_length=40)
    apps: list[str] = Field(default=[], max_length=50)           # programs the owner allowed (open_app / screen / mouse-keyboard kinds)
    hosts: list[str] = Field(default=[], max_length=50)          # websites the owner allowed (browser kinds)


@router.post("/plan", response_model=None)
async def make_plan(body: PlanRequest, _: Annotated[AuthContext, Depends(require_signed_request)]) -> JSONResponse:
    try:
        model = check_model(body.model)
    except ProviderError as e:
        return _err(e)
    roots = [r for r in body.roots if isinstance(r, str) and r.strip() and r.strip() not in ("/", "\\") and not (len(r.strip()) <= 3 and r.strip()[1:2] == ":")]
    if not roots:
        return JSONResponse({"status": "refused", "reason": "no_safe_folder"})
    allowed = set(body.kinds) & set(KINDS)
    adapter = _adapter(body.provider)
    feedback: str | None = None
    tokens_in = tokens_out = 0
    for attempt in range(2):
        msgs = [ChatMessage(**m) for m in build_messages(body.goal, roots, allowed, feedback, body.apps, body.hosts)]
        try:
            r = await adapter.complete(ChatRequest(model=model, messages=msgs, max_tokens=3000, temperature=0))
        except ProviderError as e:
            return _err(e)
        tokens_in += r.input_tokens or 0
        tokens_out += r.output_tokens or 0
        try:
            raw = extract_json(r.text)
            if isinstance(raw, dict) and raw.get("steps") == []:
                return JSONResponse({"status": "refused", "reason": "cannot_do_safely", "input_tokens": tokens_in, "output_tokens": tokens_out})
            plan = validate_plan(raw, roots, allowed, body.apps, body.hosts)
        except PlanInvalid as e:
            feedback = str(e)
            continue
        return JSONResponse({"status": "ok", "plan": plan, "review": review(plan), "input_tokens": tokens_in, "output_tokens": tokens_out})
    return JSONResponse({"status": "invalid", "reason": feedback, "input_tokens": tokens_in, "output_tokens": tokens_out})
