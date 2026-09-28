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
EXCLUDE_FILES = re.compile(r"(^\.env|\.env$|credentials\.|secrets?\.json$|\.pem$|\.key$|\.log$|\.DS_Store$|Thumbs\.db$|\.gitkeep$)", re.I)
SECRET_RE = re.compile(rb"(sk-[A-Za-z0-9]{20,}|AKIA[0-9A-Z]{16}|-----BEGIN [A-Z ]*PRIVATE KEY-----|xox[baprs]-[A-Za-z0-9-]{10,})")
CLASS_RE = re.compile(rb"\b(PCT_[A-Za-z0-9_]+)(?=::)|\bnew\s+(PCT_[A-Za-z0-9_]+)")


def class_file(name):
    return "personalized-city-tour/includes/class-" + name.decode().lower().replace("_", "-") + ".php"


def main():
    if os.path.exists(OUT):
        os.remove(OUT)
    count = 0
    referenced = set()
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
                if name.endswith(".php"):
                    referenced.update(a or b for a, b in CLASS_RE.findall(data))
                z.write(abs_path, rel)
                count += 1
    with zipfile.ZipFile(OUT) as z:
        names = z.namelist()
        assert "personalized-city-tour/personalized-city-tour.php" in names, "main plugin file missing"
        assert all("\\" not in n for n in names), "backslash in entry name"
        assert all(n.startswith("personalized-city-tour/") for n in names), "entry outside plugin dir"
        assert not any("/tests/" in n or "/.out/" in n or "/.git/" in n for n in names)
        # Every PCT_* class the code refers to must ship, or the autoloader fails at runtime.
        missing = sorted(c.decode() for c in referenced if class_file(c) not in names)
        assert not missing, "classes referenced but not packaged: " + ", ".join(missing)
    print("wrote %s (%d files, %d bytes)" % (OUT, count, os.path.getsize(OUT)))

if __name__ == "__main__":
    main()
