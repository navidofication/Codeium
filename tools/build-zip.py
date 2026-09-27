#!/usr/bin/env python3
"""Build the installable plugin ZIP with forward-slash entry names (WordPress needs
personalized-city-tour/personalized-city-tour.php at the top level).

    python tools/build-zip.py [output.zip]
"""
import os
import re
import sys
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PLUGIN = os.path.join(ROOT, "personalized-city-tour")
OUT = sys.argv[1] if len(sys.argv) > 1 else os.path.join(ROOT, "personalized-city-tour.zip")

EXCLUDE_DIRS = {".git", "node_modules", "tests", ".out", "__pycache__"}
EXCLUDE_FILES = re.compile(r"(^\.env|\.env$|credentials|secret|\.pem$|\.key$|\.log$|\.DS_Store$|Thumbs\.db$|\.gitkeep$)", re.I)
SECRET_RE = re.compile(rb"(sk-[A-Za-z0-9]{20,}|AKIA[0-9A-Z]{16}|-----BEGIN [A-Z ]*PRIVATE KEY-----|xox[baprs]-[A-Za-z0-9-]{10,})")

def main():
    if os.path.exists(OUT):
        os.remove(OUT)
    count = 0
    with zipfile.ZipFile(OUT, "w", zipfile.ZIP_DEFLATED) as z:
        for dirpath, dirnames, filenames in os.walk(PLUGIN):
            dirnames[:] = sorted(d for d in dirnames if d not in EXCLUDE_DIRS)
            for name in sorted(filenames):
                if EXCLUDE_FILES.search(name):
                    continue
                abs_path = os.path.join(dirpath, name)
                rel = os.path.relpath(abs_path, os.path.dirname(PLUGIN)).replace(os.sep, "/")
                with open(abs_path, "rb") as fh:
                    data = fh.read()
                if name.endswith((".php", ".js", ".json", ".txt", ".md", ".css")) and SECRET_RE.search(data):
                    sys.exit("secret-like content in " + rel)
                z.write(abs_path, rel)
                count += 1
    with zipfile.ZipFile(OUT) as z:
        names = z.namelist()
        assert "personalized-city-tour/personalized-city-tour.php" in names, "main plugin file missing"
        assert all("\\" not in n for n in names), "backslash in entry name"
        assert all(n.startswith("personalized-city-tour/") for n in names), "entry outside plugin dir"
        assert not any("/tests/" in n or "/.out/" in n or "/.git/" in n for n in names)
    print("wrote %s (%d files, %d bytes)" % (OUT, count, os.path.getsize(OUT)))

if __name__ == "__main__":
    main()
