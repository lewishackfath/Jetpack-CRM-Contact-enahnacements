#!/usr/bin/env python3
"""Build an installable plugin ZIP without development/test fixtures."""
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile

root = Path(__file__).resolve().parents[1]
files = [root / name for name in ("jetpack-crm-courses.php", "README.md", "readme.txt", "LICENSE")]
files += sorted((root / "includes").glob("*.php"))
files += sorted((root / "assets").glob("*"))
out = root / "dist" / "jetpack-crm-courses-1.0.0.zip"
out.parent.mkdir(exist_ok=True)
with ZipFile(out, "w", ZIP_DEFLATED) as archive:
    for file in files:
        archive.write(file, "jetpack-crm-courses/" + str(file.relative_to(root)))
with ZipFile(out) as archive:
    assert archive.testzip() is None
print(f"Built {out} ({out.stat().st_size:,} bytes)")
