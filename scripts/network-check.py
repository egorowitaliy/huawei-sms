#!/usr/bin/python3

from __future__ import annotations

import ipaddress
import re
import shutil
import socket
import subprocess
import sys
import time
from dataclasses import dataclass


EXIT_OK = 0
EXIT_ARGUMENT = 2
EXIT_CHECK_FAILED = 10

PING_COUNT = 3
PING_WAIT_SECONDS = 1
CONNECT_TIMEOUT_SECONDS = 2.0


@dataclass(frozen=True)
class PingResult:
    ok: bool
    received: int
    average_ms: int | None
    error: str


def usage() -> str:
    return (
        "СЕТЕВАЯ ПРОВЕРКА\n"
        "Пинг <IPv4 или имя>\n"
        "Порт <IPv4 или имя> <1–65535>"
    )


def fail(message: str) -> int:
    print(usage())
    print(f"Ошибка: {message}")
    return EXIT_ARGUMENT


def validate_host(value: str) -> str:
    host = value.strip().lower()

    if not host or len(host) > 253:
        raise ValueError("некорректный адрес узла")

    try:
        address = ipaddress.ip_address(host)
    except ValueError:
        address = None

    if address is not None:
        if address.version != 4:
            raise ValueError("поддерживается только IPv4")
        return str(address)

    if re.fullmatch(r"[0-9.]+", host):
        raise ValueError("некорректный IPv4-адрес")

    if host.endswith("."):
        host = host[:-1]

    labels = host.split(".")

    if not labels:
        raise ValueError("некорректное имя узла")

    for label in labels:
        if (
            not label
            or len(label) > 63
            or re.fullmatch(r"[a-z0-9](?:[a-z0-9-]*[a-z0-9])?", label)
            is None
        ):
            raise ValueError("некорректное имя узла")

    return host


def ping_binary() -> str:
    for path in ("/usr/bin/ping", "/bin/ping"):
        if shutil.which(path):
            return path

    raise RuntimeError("утилита ping не найдена")


def run_ping(host: str) -> PingResult:
    command = [
        ping_binary(),
        "-n",
        "-q",
        "-c",
        str(PING_COUNT),
        "-W",
        str(PING_WAIT_SECONDS),
        host,
    ]

    try:
        completed = subprocess.run(
            command,
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            encoding="utf-8",
            errors="replace",
            timeout=(PING_COUNT * PING_WAIT_SECONDS) + 3,
            check=False,
        )
    except subprocess.TimeoutExpired:
        return PingResult(False, 0, None, "таймаут")
    except OSError as exc:
        return PingResult(False, 0, None, str(exc))

    output = completed.stdout
    received = 0
    average_ms: int | None = None

    packet_match = re.search(
        r"(\d+) packets transmitted,\s*(\d+) (?:packets )?received",
        output,
    )

    if packet_match:
        received = int(packet_match.group(2))

    timing_match = re.search(
        r"=\s*[\d.]+/([\d.]+)/[\d.]+/[\d.]+\s*ms",
        output,
    )

    if timing_match:
        average_ms = round(float(timing_match.group(1)))

    error = completed.stderr.strip()

    if not error and received == 0:
        error = "узел не отвечает"

    return PingResult(
        completed.returncode == 0 and received > 0,
        received,
        average_ms,
        error,
    )


def check_ping(host: str) -> int:
    result = run_ping(host)

    print(f"PING {host}")

    if result.ok:
        print("Статус: OK")
        print(f"Ответы: {result.received}/{PING_COUNT}")

        if result.average_ms is not None:
            print(f"Среднее: {result.average_ms} мс")

        return EXIT_OK

    print("Статус: NA")
    print(f"Ответы: {result.received}/{PING_COUNT}")

    if result.error:
        print(f"Ошибка: {result.error[:120]}")

    return EXIT_CHECK_FAILED


def check_port(host: str, port: int) -> int:
    started = time.monotonic()

    try:
        with socket.create_connection(
            (host, port),
            timeout=CONNECT_TIMEOUT_SECONDS,
        ):
            pass
    except ConnectionRefusedError:
        print(f"ПОРТ {host}:{port}")
        print("Статус: CLOSED")
        return EXIT_CHECK_FAILED
    except socket.timeout:
        print(f"ПОРТ {host}:{port}")
        print("Статус: NA")
        print("Ошибка: таймаут")
        return EXIT_CHECK_FAILED
    except socket.gaierror as exc:
        print(f"ПОРТ {host}:{port}")
        print("Статус: NA")
        print(f"Ошибка DNS: {exc}")
        return EXIT_CHECK_FAILED
    except OSError as exc:
        print(f"ПОРТ {host}:{port}")
        print("Статус: NA")
        print(f"Ошибка: {str(exc)[:120]}")
        return EXIT_CHECK_FAILED

    elapsed_ms = round((time.monotonic() - started) * 1000)

    print(f"ПОРТ {host}:{port}")
    print("Статус: OPEN")
    print(f"Время: {elapsed_ms} мс")
    return EXIT_OK


def main(argv: list[str]) -> int:
    if not argv:
        return fail("не указано действие")

    action = argv[0].strip().lower()

    if action == "ping":
        if len(argv) != 2:
            return fail("для ping нужен один адрес")

        try:
            host = validate_host(argv[1])
        except ValueError as exc:
            return fail(str(exc))

        return check_ping(host)

    if action == "port":
        if len(argv) != 3:
            return fail("для проверки порта нужны адрес и номер порта")

        try:
            host = validate_host(argv[1])
            port = int(argv[2], 10)
        except ValueError as exc:
            return fail(str(exc))

        if port < 1 or port > 65535:
            return fail("порт должен быть в диапазоне 1–65535")

        return check_port(host, port)

    return fail("допустимы только ping или port")


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
