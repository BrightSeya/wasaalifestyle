"""Measure sequential response times for representative Wasaa HTTP pages.

Uses only the Python standard library. The account is used to create an HTTP
session; its credentials are prompted for and never written to the output.
"""

from __future__ import annotations

import argparse
import csv
import getpass
import math
import statistics
import time
from datetime import datetime
from html.parser import HTMLParser
from http.cookiejar import CookieJar
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode, urljoin, urlsplit
from urllib.request import HTTPCookieProcessor, Request, build_opener


GUEST_ROUTES = ("/", "/login")
AUTHENTICATED_ROUTES = (
    "/dashboard",
    "/notifications",
    "/activities",
    "/schedules",
    "/schedules/week",
    "/recommendations",
    "/settings",
)
TARGET_MS = 2000.0
OUTPUT_DIRECTORY = Path(__file__).resolve().parents[1] / "storage" / "app" / "performance"


class CsrfTokenParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.token: str | None = None

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        if tag.lower() != "input":
            return

        values = dict(attrs)
        if values.get("name") == "_token":
            self.token = values.get("value")


def request_page(opener, base_url: str, path: str, timeout: float) -> tuple[int, str, float, int, bytes]:
    request = Request(
        urljoin(base_url, path.lstrip("/")),
        headers={"Accept": "text/html", "User-Agent": "WasaaPerformanceCheck/1.0"},
    )
    started = time.perf_counter()
    try:
        with opener.open(request, timeout=timeout) as response:
            body = response.read()
            return (
                response.status,
                response.geturl(),
                (time.perf_counter() - started) * 1000,
                len(body),
                body,
            )
    except (HTTPError, URLError, TimeoutError) as error:
        raise RuntimeError(f"GET {path} failed: {error}") from error


def login(opener, base_url: str, timeout: float) -> None:
    status, final_url, _, _, body = request_page(opener, base_url, "/login", timeout)
    if status != 200 or urlsplit(final_url).path.rstrip("/") != "/login":
        raise RuntimeError(f"Could not open the login page (HTTP {status}, {final_url}).")

    parser = CsrfTokenParser()
    parser.feed(body.decode("utf-8", errors="replace"))
    if not parser.token:
        raise RuntimeError("Could not find the login form CSRF token.")

    email = input("Verified test-account email: ").strip()
    password = getpass.getpass("Test-account password (not saved): ")
    data = urlencode({"_token": parser.token, "email": email, "password": password}).encode()
    request = Request(
        urljoin(base_url, "login"),
        data=data,
        headers={
            "Accept": "text/html",
            "Content-Type": "application/x-www-form-urlencoded",
            "Referer": urljoin(base_url, "login"),
            "User-Agent": "WasaaPerformanceCheck/1.0",
        },
    )
    try:
        with opener.open(request, timeout=timeout) as response:
            response.read()
            final_path = urlsplit(response.geturl()).path.rstrip("/")
    except (HTTPError, URLError, TimeoutError) as error:
        raise RuntimeError(f"Login request failed: {error}") from error

    password = ""
    if final_path in ("/login", "/email/verify"):
        raise RuntimeError("Login did not reach the dashboard. Check the test account and email verification.")


def percentile95(values: list[float]) -> float:
    ordered = sorted(values)
    return ordered[max(0, math.ceil(0.95 * len(ordered)) - 1)]


def measure_route(opener, base_url: str, route: str, warmups: int, samples: int, timeout: float):
    measurements = []
    expected_path = route.rstrip("/") or "/"

    for iteration in range(1, warmups + samples + 1):
        status, final_url, elapsed_ms, response_bytes, _ = request_page(opener, base_url, route, timeout)
        final_path = urlsplit(final_url).path.rstrip("/") or "/"
        if status != 200 or final_path != expected_path:
            raise RuntimeError(
                f"GET {route} returned HTTP {status} at {final_url}; "
                "check login, email verification, and route availability."
            )

        if iteration > warmups:
            measurements.append(
                {
                    "route": route,
                    "iteration": iteration - warmups,
                    "status": status,
                    "elapsed_ms": round(elapsed_ms, 3),
                    "response_bytes": response_bytes,
                }
            )

    return measurements


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-url", default="http://127.0.0.1:8000", help="Laravel base URL")
    parser.add_argument("--samples", type=int, default=30, help="Measured requests per page (default: 30)")
    parser.add_argument("--warmups", type=int, default=3, help="Unrecorded warm-up requests per page (default: 3)")
    parser.add_argument("--timeout", type=float, default=20.0, help="Request timeout in seconds (default: 20)")
    parser.add_argument("--output", type=Path, help="CSV output path; defaults to Laravel storage/app/performance/")
    args = parser.parse_args()

    if args.samples < 1 or args.warmups < 0 or args.timeout <= 0:
        parser.error("samples must be positive; warmups must be non-negative; timeout must be positive")

    base_url = args.base_url.rstrip("/") + "/"
    timestamp = datetime.now().astimezone().strftime("%Y%m%d-%H%M%S")
    output = args.output or OUTPUT_DIRECTORY / f"http-benchmark-{timestamp}.csv"
    guest_opener = build_opener(HTTPCookieProcessor(CookieJar()))
    authenticated_opener = build_opener(HTTPCookieProcessor(CookieJar()))

    try:
        rows = []
        for route in GUEST_ROUTES:
            rows.extend(measure_route(guest_opener, base_url, route, args.warmups, args.samples, args.timeout))

        login(authenticated_opener, base_url, args.timeout)
        for route in AUTHENTICATED_ROUTES:
            rows.extend(measure_route(authenticated_opener, base_url, route, args.warmups, args.samples, args.timeout))
    except RuntimeError as error:
        parser.exit(2, f"Benchmark stopped: {error}\n")

    output.parent.mkdir(parents=True, exist_ok=True)
    with output.open("w", newline="", encoding="utf-8") as destination:
        writer = csv.DictWriter(destination, fieldnames=["route", "iteration", "status", "elapsed_ms", "response_bytes"])
        writer.writeheader()
        writer.writerows(rows)

    print(f"Base URL: {base_url}")
    print(f"Samples per route: {args.samples}; warm-ups per route: {args.warmups}; concurrency: 1")
    print(f"Target: every measured normal page response under {TARGET_MS:.0f} ms")
    print("\nRoute                         Median ms    P95 ms    Max ms    Under target")
    print("-" * 78)

    all_under_target = True
    for route in (*GUEST_ROUTES, *AUTHENTICATED_ROUTES):
        values = [row["elapsed_ms"] for row in rows if row["route"] == route]
        maximum = max(values)
        under_target = maximum < TARGET_MS
        all_under_target = all_under_target and under_target
        print(
            f"{route:<29} {statistics.median(values):>9.1f} "
            f"{percentile95(values):>9.1f} {maximum:>9.1f}    "
            f"{'yes' if under_target else 'NO'}"
        )

    print(f"\nRaw samples: {output}")
    print(f"Observed target result: {'met' if all_under_target else 'not met'}")
    print("This sequential local run is not a production or concurrent-load guarantee.")
    return 0 if all_under_target else 1


if __name__ == "__main__":
    raise SystemExit(main())
