#!/usr/bin/env python3
"""Build a WordPress-installable ZIP containing runtime files only."""
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile

root = Path(__file__).resolve().parents[1]
output = root / 'dist' / 'letterlift-1.0.0.zip'
output.parent.mkdir(exist_ok=True)
files = [root / name for name in ('letterlift.php', 'uninstall.php', 'readme.txt', 'README.md', 'LICENSE')]
for directory in ('includes', 'assets', 'docs'):
    files.extend(sorted((root / directory).glob('*')))
with ZipFile(output, 'w', ZIP_DEFLATED) as archive:
    for path in files:
        if path.is_file():
            archive.write(path, 'letterlift/' + str(path.relative_to(root)))
print(output)
