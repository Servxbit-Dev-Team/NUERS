from pathlib import Path
import hashlib
import json
import zipfile

root = Path.cwd()
out = root / "output/merchant-loading-20261020/release"
base = json.loads((root / "output/storage-operations-audit/before-hashes.json").read_text())
base.update(json.loads((root / "output/storage-operations-audit/release/manifest.json").read_text())["files"])
sources = json.loads((out.parent / "before-hashes.json").read_text())
base.update(sources)
files = {p: root / p for p in sources}
for folder in ["public/build", "dist/assets"]:
    for file in (root / folder).rglob("*"):
        if file.is_file():
            path = str(file.relative_to(root))
            if hashlib.sha256(file.read_bytes()).hexdigest() != base.get(path):
                files[path] = file
files["dist/index.html"] = root / "dist/index.html"
files["index.html"] = root / "dist/index.html"
for path, file in list(files.items()):
    if path.startswith("dist/assets/"):
        files[path.removeprefix("dist/")] = file
manifest = {
    "release": "2026-10-20.merchant-loading",
    "files": {}, "baseline": {}, "new_files": [],
    "tests": "70 tests, 705 assertions; read timeout/cancellation checks; TypeScript and all production builds",
}
for path, file in files.items():
    manifest["files"][path] = hashlib.sha256(file.read_bytes()).hexdigest()
    prior = base.get(path) or base.get("dist/" + path)
    if prior:
        manifest["baseline"][path] = prior
    else:
        manifest["new_files"].append(path)
(out / "manifest.json").write_text(json.dumps(manifest, indent=2))
package = out / "nuers-merchant-loading-20261020.zip"
with zipfile.ZipFile(package, "w", zipfile.ZIP_DEFLATED) as archive:
    archive.writestr("audit-release-manifest.json", json.dumps(manifest))
    for path, file in files.items():
        archive.write(file, path)
sha = hashlib.sha256(package.read_bytes()).hexdigest()
(out / "package-sha256.txt").write_text(sha + "\n")
installer = (root / "output/admin-security-audit/release/nuers-admin-security-audit-install.php").read_text()
installer = installer.replace("nuers-admin-security-audit", "nuers-merchant-loading")
installer = installer.replace("Admin security audit deployment", "Merchant dashboard loading fix")
installer = installer.replace("213a78771d2015c623cc316ac3655ab185f01c6f7524a9d1b2e69fd39d6105f7", sha)
# Capture before/after records inside the brief maintenance period, after existing read requests have ended.
installer = installer.replace("$before=$snapshot();file_put_contents($backup.'/preservation-hashes.json',json_encode($before));\n", "")
installer = installer.replace("try{\n // Promote", "try{\n $before=$snapshot();file_put_contents($backup.'/preservation-hashes.json',json_encode($before));\n // Promote")
(out / "nuers-merchant-loading-install.php").write_text(installer)
print(json.dumps({"files": len(files), "bytes": package.stat().st_size, "sha256": sha}))
