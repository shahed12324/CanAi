"""SSRF guard for the crawler.

Two layers, because checking a URL once is not enough (DNS can change between the check and the connection):
  1. parse_url(): only http/https, no credentials, no IP-literal hosts, a small set of ports.
  2. GuardedBackend: the *connection* itself resolves the host and refuses any non-public address, then connects to the
     address it validated. TLS still verifies the certificate against the original hostname.
Redirects are followed by the fetcher one hop at a time, so every hop goes through both layers again.
"""

from __future__ import annotations

import asyncio
import ipaddress
import socket
from collections.abc import Iterable
from urllib.parse import SplitResult, urlsplit

import httpcore
import httpx

ALLOWED_PORTS = frozenset({80, 443, 8080, 8443})
MAX_URL_LENGTH = 2048


class UnsafeUrl(Exception):
    def __init__(self, code: str) -> None:
        super().__init__(code)
        self.code = code


def ip_is_public(ip: str) -> bool:
    try:
        addr = ipaddress.ip_address(ip.split("%", 1)[0])
    except ValueError:
        return False
    if isinstance(addr, ipaddress.IPv6Address) and addr.ipv4_mapped is not None:
        addr = addr.ipv4_mapped
    if isinstance(addr, ipaddress.IPv6Address) and addr.sixtofour is not None:
        return ip_is_public(str(addr.sixtofour))
    return addr.is_global and not addr.is_multicast


def parse_url(url: str, *, allow_private: bool = False) -> SplitResult:
    if (
        not isinstance(url, str)
        or not url
        or len(url) > MAX_URL_LENGTH
        or any(c in url for c in "\r\n\t\0 ")
    ):
        raise UnsafeUrl("bad_url")
    try:
        p = urlsplit(url)
        port = p.port
    except ValueError:
        raise UnsafeUrl("bad_url") from None
    if p.scheme not in ("http", "https"):
        raise UnsafeUrl("bad_scheme")
    if p.username is not None or p.password is not None or "@" in p.netloc:
        raise UnsafeUrl("credentials_in_url")
    host = p.hostname
    if not host:
        raise UnsafeUrl("bad_host")
    if not allow_private:
        try:
            ipaddress.ip_address(host.strip("[]"))
        except ValueError:
            pass
        else:
            raise UnsafeUrl("ip_literal")
        labels = host.split(".")
        # a real site has a dot and an alphabetic TLD ("0x7f.1", "2130706433" and friends are numeric-IP tricks)
        if len(labels) < 2 or not (labels[-1].isalpha() or labels[-1].startswith("xn--")):
            raise UnsafeUrl("bad_host")
        if host.endswith((".local", ".internal", ".localhost", ".lan", ".home", ".corp")):
            raise UnsafeUrl("internal_host")
        if port is not None and port not in ALLOWED_PORTS:
            raise UnsafeUrl("bad_port")
    return p


async def resolve_public(host: str, port: int, *, allow_private: bool = False) -> list[str]:
    loop = asyncio.get_running_loop()
    try:
        infos = await loop.getaddrinfo(host, port, type=socket.SOCK_STREAM)
    except socket.gaierror:
        raise UnsafeUrl("dns_failed") from None
    ips = list(dict.fromkeys(str(i[4][0]) for i in infos))
    if not ips:
        raise UnsafeUrl("dns_failed")
    if not allow_private and not all(ip_is_public(ip) for ip in ips):
        raise UnsafeUrl(
            "private_address"
        )  # even ONE private answer rejects the host (rebinding mixes answers)
    return ips


class GuardedBackend(httpcore.AnyIOBackend):
    def __init__(self, allow_private: bool = False) -> None:
        super().__init__()
        self._allow_private = allow_private

    async def connect_tcp(  # type: ignore[override]
        self,
        host: str,
        port: int,
        timeout: float | None = None,
        local_address: str | None = None,
        socket_options: Iterable[tuple[int, int, int] | tuple[int, int, bytes]] | None = None,
    ) -> httpcore.AsyncNetworkStream:
        ips = await resolve_public(host, port, allow_private=self._allow_private)
        last: Exception | None = None
        for ip in ips:
            try:
                return await super().connect_tcp(ip, port, timeout, local_address, socket_options)
            except Exception as e:  # noqa: BLE001  - try the next validated address
                last = e
        raise last or httpcore.ConnectError("no address")


class GuardedTransport(httpx.AsyncHTTPTransport):
    """httpx transport whose connections go through GuardedBackend. Never uses proxies from the environment."""

    def __init__(self, allow_private: bool = False) -> None:
        super().__init__(http1=True, http2=False)
        self._pool = httpcore.AsyncConnectionPool(
            ssl_context=httpx.create_ssl_context(),
            max_connections=10,
            max_keepalive_connections=5,
            keepalive_expiry=10.0,
            http1=True,
            http2=False,
            network_backend=GuardedBackend(allow_private),
        )
