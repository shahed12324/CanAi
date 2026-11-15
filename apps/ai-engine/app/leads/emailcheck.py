"""Email checks without sending anything: syntax (done at extraction) + does the domain accept mail (MX / implicit MX)."""

from __future__ import annotations

import asyncio
from typing import Protocol

import dns.asyncresolver
import dns.exception
import dns.resolver


class Resolver(Protocol):
    async def has_mx(self, domain: str) -> bool | None:
        """True = accepts mail, False = definitely not (NXDOMAIN / no MX and no A), None = could not tell."""


class DnsResolver:
    def __init__(self, timeout: float = 3.0) -> None:
        self._r = dns.asyncresolver.Resolver()
        self._r.lifetime = timeout
        self._r.timeout = timeout

    async def has_mx(self, domain: str) -> bool | None:
        try:
            ans = await self._r.resolve(domain, "MX")
            return any(
                str(r.exchange).rstrip(".") not in ("", ".") for r in ans
            )  # "null MX" (RFC 7505) = no mail
        except dns.resolver.NXDOMAIN:
            return False
        except dns.resolver.NoAnswer:
            try:
                await self._r.resolve(domain, "A")
                return True  # implicit MX
            except (dns.resolver.NoAnswer, dns.resolver.NXDOMAIN):
                return False
            except (dns.exception.DNSException, OSError):
                return None
        except (dns.exception.DNSException, OSError):
            return None


async def check_emails(
    emails: list[str], site_domain: str, resolver: Resolver, limit: int = 5
) -> list[dict[str, object]]:
    domains = list(dict.fromkeys(e.rsplit("@", 1)[1] for e in emails[:limit]))
    results = await asyncio.gather(*(resolver.has_mx(d) for d in domains))
    by_domain = dict(zip(domains, results, strict=True))
    out: list[dict[str, object]] = []
    for e in emails[:limit]:
        d = e.rsplit("@", 1)[1]
        r = by_domain[d]
        status = "mx_ok" if r is True else "no_mx" if r is False else "syntax_ok"
        sd = site_domain.removeprefix("www.")
        out.append(
            {
                "email": e,
                "status": status,
                "same_domain": d == sd or d.endswith("." + sd) or sd.endswith("." + d),
            }
        )
    return out
