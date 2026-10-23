"""Reject local configuration, credentials and database files in the Git index."""
from pathlib import PurePosixPath
import subprocess
import sys

paths = subprocess.check_output(["git", "ls-files", "-z"], text=True).split("\0")
blocked = []
for name in filter(None, paths):
    path = PurePosixPath(name)
    base = path.name
    if ((base == ".env" or base.startswith(".env.")) and base != ".env.example"
            or base == "auth.json"
            or path.suffix in {".pem", ".key", ".sqlite", ".sqlite3", ".sql"}
            or "node_modules" in path.parts or "vendor" in path.parts):
        blocked.append(name)
if blocked:
    print("Remove prohibited tracked files:", *blocked, sep="\n")
    sys.exit(1)
print("Tracked-file policy passed.")
