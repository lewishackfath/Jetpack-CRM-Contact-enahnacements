#!/usr/bin/env python3
"""Build the installable ZIP and GitHub update metadata, validating release versions."""
import argparse
import json
import re
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile

root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("--tag", help="Require a matching GitHub release tag, e.g. v1.0.1")
args = parser.parse_args()
plugin = (root / "jetpack-crm-courses.php").read_text()
readme = (root / "readme.txt").read_text()


def field(text, name):
    match = re.search(r"^\s*(?:\*\s*)?" + re.escape(name) + r":\s*(\S+)\s*$", text, re.M)
    if not match:
        parser.error(f"Missing {name} header")
    return match[1]


version = field(plugin, "Version")
if not re.fullmatch(r"(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)", version):
    parser.error("Use a stable version in major.minor.patch format")
constant = re.search(r"define\(\s*'JPCRM_COURSES_VERSION',\s*'([^']+)'\s*\)", plugin)
if not constant or constant[1] != version or field(readme, "Stable tag") != version:
    parser.error("Plugin header, JPCRM_COURSES_VERSION and readme Stable tag must match")
if args.tag is not None and args.tag not in (version, "v" + version):
    parser.error(f"Release tag {args.tag!r} does not match plugin version {version}")

metadata = {
    "version": version,
    "requires": field(plugin, "Requires at least"),
    "requires_php": field(plugin, "Requires PHP"),
    "tested": field(readme, "Tested up to"),
}
for name in ("requires", "requires_php", "tested"):
    if not re.fullmatch(r"[0-9]+\.[0-9]+(?:\.[0-9]+)?", metadata[name]):
        parser.error(f"Invalid compatibility version: {name}")
for name in ("Requires at least", "Requires PHP"):
    if field(plugin, name) != field(readme, name):
        parser.error(f"Plugin and readme {name} must match")

files = [root / name for name in ("jetpack-crm-courses.php", "README.md", "readme.txt", "LICENSE")]
files += sorted((root / "includes").glob("*.php"))
files += sorted((root / "assets").glob("*"))
out = root / "dist" / f"jetpack-crm-courses-{version}.zip"
out.parent.mkdir(exist_ok=True)
with ZipFile(out, "w", ZIP_DEFLATED) as archive:
    for file in files:
        archive.write(file, "jetpack-crm-courses/" + str(file.relative_to(root)))
with ZipFile(out) as archive:
    assert archive.testzip() is None
manifest = out.parent / "jetpack-crm-courses-update.json"
manifest.write_text(json.dumps(metadata, indent=2) + "\n")
print(f"Built {out} ({out.stat().st_size:,} bytes)")
print(f"Built {manifest}")
