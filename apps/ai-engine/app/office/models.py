"""Strict shape of a document / spreadsheet / presentation.

Everything is validated and cleaned here so the builders stay simple. Text is plain text only:
a cell that starts with = + - @ is stored as text, never as a formula.
"""

from __future__ import annotations

import math
import re
from typing import Annotated, Literal

from pydantic import BaseModel, Field, field_validator

_CTRL = re.compile(r"[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]")
_BAD_SHEET = re.compile(r"[\[\]:*?/\\]")


def _clean(v: str) -> str:
    return _CTRL.sub("", v)


Text = Annotated[str, Field(max_length=5000)]
Short = Annotated[str, Field(max_length=300)]


class Block(BaseModel):
    t: Literal["h1", "h2", "h3", "p", "ul", "ol", "table"]
    text: Text = ""
    items: list[Short] = Field(default_factory=list, max_length=60)
    headers: list[Annotated[str, Field(max_length=200)]] = Field(
        default_factory=list, max_length=30
    )
    rows: list[list[Annotated[str, Field(max_length=500)]]] = Field(
        default_factory=list, max_length=500
    )

    @field_validator("text")
    @classmethod
    def _text(cls, v: str) -> str:
        return _clean(v)

    @field_validator("items")
    @classmethod
    def _items(cls, v: list[str]) -> list[str]:
        return [_clean(x) for x in v]

    @field_validator("headers")
    @classmethod
    def _headers(cls, v: list[str]) -> list[str]:
        return [_clean(x) for x in v]

    @field_validator("rows")
    @classmethod
    def _rows(cls, v: list[list[str]]) -> list[list[str]]:
        if any(len(r) > 30 for r in v):
            raise ValueError("too many columns")
        return [[_clean(c) for c in r] for r in v]


class DocSpec(BaseModel):
    kind: Literal["docx"] = "docx"
    title: Annotated[str, Field(min_length=1, max_length=200)]
    blocks: list[Block] = Field(min_length=1, max_length=300)

    @field_validator("title")
    @classmethod
    def _title(cls, v: str) -> str:
        v = _clean(v).strip()
        if not v:
            raise ValueError("title required")
        return v


Cell = str | int | float | None


class Sheet(BaseModel):
    name: Annotated[str, Field(max_length=60)] = "Sheet"
    headers: list[Annotated[str, Field(max_length=200)]] = Field(
        default_factory=list, max_length=50
    )
    rows: list[list[Cell]] = Field(default_factory=list, max_length=2000)

    @field_validator("name")
    @classmethod
    def _name(cls, v: str) -> str:
        v = _BAD_SHEET.sub(" ", _clean(v)).strip().strip("'")[:31].strip()
        return v or "Sheet"

    @field_validator("headers")
    @classmethod
    def _headers(cls, v: list[str]) -> list[str]:
        return [_clean(x) for x in v]

    @field_validator("rows")
    @classmethod
    def _rows(cls, v: list[list[Cell]]) -> list[list[Cell]]:
        out: list[list[Cell]] = []
        for r in v:
            if len(r) > 50:
                raise ValueError("too many columns")
            row: list[Cell] = []
            for c in r:
                if isinstance(c, float) and not math.isfinite(c):
                    c = None
                if isinstance(c, str):
                    if len(c) > 500:
                        raise ValueError("cell too long")
                    c = _clean(c)
                row.append(c)
            out.append(row)
        return out


class SheetSpec(BaseModel):
    kind: Literal["xlsx"] = "xlsx"
    title: Annotated[str, Field(min_length=1, max_length=200)]
    sheets: list[Sheet] = Field(min_length=1, max_length=10)

    @field_validator("title")
    @classmethod
    def _title(cls, v: str) -> str:
        v = _clean(v).strip()
        if not v:
            raise ValueError("title required")
        return v


class Slide(BaseModel):
    title: Annotated[str, Field(max_length=200)] = ""
    bullets: list[Short] = Field(default_factory=list, max_length=12)
    notes: Annotated[str, Field(max_length=2000)] = ""

    @field_validator("title", "notes")
    @classmethod
    def _t(cls, v: str) -> str:
        return _clean(v)

    @field_validator("bullets")
    @classmethod
    def _b(cls, v: list[str]) -> list[str]:
        return [_clean(x) for x in v]


class DeckSpec(BaseModel):
    kind: Literal["pptx"] = "pptx"
    title: Annotated[str, Field(min_length=1, max_length=200)]
    slides: list[Slide] = Field(min_length=1, max_length=40)

    @field_validator("title")
    @classmethod
    def _title(cls, v: str) -> str:
        v = _clean(v).strip()
        if not v:
            raise ValueError("title required")
        return v
