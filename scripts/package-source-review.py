#!/usr/bin/env python3
"""Package reviewable source without environment state, uploads, or dependencies."""

import argparse
import hashlib
import json
from pathlib import Path
import zipfile


ROOT = Path(__file__).resolve().parents[1]
DIRECTORIES = (
    "app", "config", "database", "resources", "routes", "src", "tests", "scripts",
    "docs", "public", "deployment",
)
ROOT_FILES = (
    ".editorconfig", ".env.example", ".gitattributes", ".gitignore", ".htaccess",
    ".prettierignore", ".prettierrc.json", "README.md", "LICENSE", "artisan",
    "composer.json", "composer.lock", "package.json", "package-lock.json",
    "components.json", "pint.json", "phpunit.xml", "index.html", "tsconfig.json",
    "tsconfig.app.json", "tsconfig.node.json", "vite.config.ts", "vite.static.config.ts",
    "bootstrap/app.php", "bootstrap/providers.php", "bootstrap/cache/.gitignore",
)
EXCLUDED_PREFIXES = (
    "public/build/", "public/uploads/", "public/storage/", "public/hot",
    "docs/audits/", "deployment/reports/",
)
EXCLUDED_SUFFIXES = (".sqlite", ".sqlite-wal", ".sqlite-shm", ".db", ".log", ".pid", ".sock", ".pem", ".key", ".tsbuildinfo")


def review_files():
    paths = {ROOT / name for name in ROOT_FILES if (ROOT / name).is_file()}
    for directory in DIRECTORIES:
        paths.update(path for path in (ROOT / directory).rglob("*") if path.is_file())
    for path in sorted(paths):
        name = path.relative_to(ROOT).as_posix()
        if path.is_symlink() or name.startswith(EXCLUDED_PREFIXES):
            continue
        if path.name == ".DS_Store" or path.name.endswith(EXCLUDED_SUFFIXES):
            continue
        if ".env" in path.name and name != ".env.example":
            continue
        if any(part in {"__pycache__", "node_modules", "vendor", ".git", ".local"} for part in path.parts):
            continue
        yield path, name


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", required=True, type=Path)
    options = parser.parse_args()
    destination = options.output.resolve()
    if destination.exists():
        parser.error("Output already exists; choose a new filename.")
    destination.parent.mkdir(parents=True, exist_ok=True)
    files = list(review_files())
    manifest = []
    with zipfile.ZipFile(destination, "w", zipfile.ZIP_DEFLATED) as archive:
        for path, name in files:
            data = path.read_bytes()
            manifest.append({"path": name, "bytes": len(data), "sha256": hashlib.sha256(data).hexdigest()})
            archive.writestr("NUERS/" + name, data)
        for directory in ("storage/app/private", "storage/framework/cache/data", "storage/framework/sessions", "storage/framework/views", "storage/logs", "bootstrap/cache"):
            archive.writestr("NUERS/" + directory + "/", b"")
        archive.writestr("NUERS/SOURCE_MANIFEST.json", json.dumps(manifest, indent=2) + "\n")
    with zipfile.ZipFile(destination) as archive:
        if archive.testzip() is not None:
            raise RuntimeError("Archive CRC verification failed.")
        for entry in manifest:
            actual = hashlib.sha256(archive.read("NUERS/" + entry["path"])).hexdigest()
            if actual != entry["sha256"]:
                raise RuntimeError("Archive content verification failed: " + entry["path"])
    print(json.dumps({"archive": str(destination), "files": len(files), "bytes": destination.stat().st_size, "sha256": hashlib.sha256(destination.read_bytes()).hexdigest()}, indent=2))


if __name__ == "__main__":
    main()
