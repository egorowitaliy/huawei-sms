#!/usr/bin/python3

from __future__ import annotations

import os
import sys


def main(argv: list[str]) -> int:
    if len(argv) < 2:
        print(
            "command-runner: interpreter and script are required",
            file=sys.stderr,
        )
        return 126

    try:
        os.setsid()
    except OSError as exc:
        print(f"command-runner: setsid failed: {exc}", file=sys.stderr)
        return 126

    interpreter = argv[0]
    command = argv

    try:
        os.execve(interpreter, command, os.environ.copy())
    except OSError as exc:
        print(f"command-runner: exec failed: {exc}", file=sys.stderr)
        return 127


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
