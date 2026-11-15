"""Strict input shape. Everything is validated here so the HTML/DOCX builders can stay simple."""

from __future__ import annotations

import base64
import binascii
import re
from typing import Literal

from pydantic import BaseModel, Field, field_validator

HEX = re.compile(r"\A#[0-9a-fA-F]{6}\Z")
MAX_LOGO_BYTES = 600_000
PNG = b"\x89PNG\r\n\x1a\n"
JPEG = b"\xff\xd8\xff"


class Brand(BaseModel):
    name: str = Field(default="", max_length=80)
    primary: str = "#1F3A5F"
    accent: str = "#E07A1F"
    logo_b64: str | None = None
    email: str = Field(default="", max_length=120)
    phone: str = Field(default="", max_length=40)
    website: str = Field(default="", max_length=200)
    address: str = Field(default="", max_length=240)

    @field_validator("primary", "accent")
    @classmethod
    def _color(cls, v: str) -> str:
        if not HEX.match(v):
            raise ValueError("color must be #RRGGBB")
        return v.lower()

    @field_validator("logo_b64")
    @classmethod
    def _logo(cls, v: str | None) -> str | None:
        if not v:
            return None
        try:
            raw = base64.b64decode(v, validate=True)
        except (binascii.Error, ValueError) as e:
            raise ValueError("logo is not base64") from e
        if len(raw) > MAX_LOGO_BYTES:
            raise ValueError("logo too large")
        if not (raw.startswith(PNG) or raw.startswith(JPEG)):
            raise ValueError("logo must be PNG or JPEG")
        return v

    @property
    def logo_bytes(self) -> bytes | None:
        return base64.b64decode(self.logo_b64) if self.logo_b64 else None

    @property
    def logo_mime(self) -> str | None:
        raw = self.logo_bytes
        if raw is None:
            return None
        return "image/png" if raw.startswith(PNG) else "image/jpeg"


class Section(BaseModel):
    heading: str = Field(default="", max_length=160)
    body: str = Field(default="", max_length=8000)


class PriceRow(BaseModel):
    name: str = Field(max_length=240)
    qty: str = Field(default="", max_length=16)
    unit_price: str = Field(default="", max_length=32)
    total: str = Field(default="", max_length=32)


class TotalLine(BaseModel):
    label: str = Field(max_length=80)
    value: str = Field(max_length=40)
    strong: bool = False


class Pricing(BaseModel):
    rows: list[PriceRow] = Field(default_factory=list, max_length=60)
    totals: list[TotalLine] = Field(default_factory=list, max_length=6)
    note: str = Field(default="", max_length=1000)


class PortfolioEntry(BaseModel):
    title: str = Field(max_length=160)
    url: str = Field(default="", max_length=300)
    description: str = Field(default="", max_length=600)


DEFAULT_LABELS = {
    "prepared_for": "Prepared for",
    "date": "Date",
    "item": "Item",
    "qty": "Qty",
    "unit_price": "Unit price",
    "amount": "Amount",
    "pricing": "Pricing",
    "portfolio": "Selected work",
    "contact": "Contact",
    "page": "Page",
}


class ProposalDoc(BaseModel):
    language: Literal["bn", "en"] = "en"
    title: str = Field(min_length=1, max_length=200)
    client_name: str = Field(default="", max_length=200)
    date_text: str = Field(default="", max_length=40)
    sections: list[Section] = Field(default_factory=list, max_length=30)
    pricing: Pricing | None = None
    portfolio: list[PortfolioEntry] = Field(default_factory=list, max_length=12)
    brand: Brand = Field(default_factory=Brand)
    labels: dict[str, str] = Field(default_factory=dict)

    @field_validator("labels")
    @classmethod
    def _labels(cls, v: dict[str, str]) -> dict[str, str]:
        return {k: str(x)[:60] for k, x in v.items() if k in DEFAULT_LABELS}

    def label(self, key: str) -> str:
        return self.labels.get(key) or DEFAULT_LABELS[key]
