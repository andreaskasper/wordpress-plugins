#!/usr/bin/env python3
"""
Update the Plugin-Update-Checker metadata JSON (distmeta/updater/goo1-mcp.json)
from the plugin's own header and readme.txt.

Single source of truth:
  * version / requires / requires_php  -> plugin main file header
  * tested up to                       -> readme.txt header
  * changelog / upgrade_notice         -> readme.txt "== Changelog ==" section

Everything else in the JSON (name, homepage, author, icons, banners,
description, installation, rating counters, ...) is preserved untouched.
"""

import argparse
import datetime
import html
import json
import re
import sys
from collections import OrderedDict


def read(path):
    with open(path, "r", encoding="utf-8") as fh:
        return fh.read()


def header_field(text, label):
    """Read a 'Label: value' field from a WP plugin/readme header block."""
    pattern = re.compile(
        r"^[ \t/*#]*" + re.escape(label) + r"\s*:\s*(.+?)\s*$",
        re.IGNORECASE | re.MULTILINE,
    )
    m = pattern.search(text)
    return m.group(1).strip() if m else None


def parse_changelog(readme_text):
    """Return list of (version, [lines]) from the == Changelog == section."""
    # Isolate the changelog section (until the next "== Heading ==" or EOF).
    m = re.search(
        r"==\s*Changelog\s*==\s*(.*?)(?:\n==\s|\Z)",
        readme_text,
        re.IGNORECASE | re.DOTALL,
    )
    if not m:
        return []
    body = m.group(1)

    entries = []
    current = None
    for raw in body.splitlines():
        line = raw.rstrip()
        head = re.match(r"^=\s*(.+?)\s*=\s*$", line)
        if head:
            current = (head.group(1).strip(), [])
            entries.append(current)
            continue
        if current is None:
            continue
        item = re.match(r"^\s*[\*\-]\s+(.*)$", line)
        if item:
            current[1].append(item.group(1).strip())
        elif line.strip():
            # continuation / free text line
            current[1].append(line.strip())
    return entries


def changelog_to_html(entries):
    parts = ["<h2>Changelog</h2>"]
    for version, lines in entries:
        parts.append("<h4>{}</h4>".format(html.escape(version)))
        if lines:
            lis = "".join("<li>{}</li>".format(html.escape(t)) for t in lines)
            parts.append("<ul>{}</ul>".format(lis))
    return "".join(parts)


def upgrade_notice_from(entries):
    if not entries:
        return None
    version, lines = entries[0]
    body = " ".join(lines).strip()
    if not body:
        return "Update auf Version {}.".format(version)
    notice = "Version {}: {}".format(version, body)
    return (notice[:497] + "...") if len(notice) > 500 else notice


def reorder(meta):
    """Cosmetic: keep new keys near logically related ones."""
    out = OrderedDict()
    for key in meta:
        out[key] = meta[key]
        if key == "name" and "slug" not in meta:
            out["slug"] = None  # placeholder, filled by caller
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--plugin-file", required=True)
    ap.add_argument("--readme", required=True)
    ap.add_argument("--meta", required=True)
    ap.add_argument("--owner", required=True)
    ap.add_argument("--repo", required=True)
    ap.add_argument("--tag", required=True)
    ap.add_argument("--slug", required=True)
    args = ap.parse_args()

    php = read(args.plugin_file)
    readme = read(args.readme)

    version = header_field(php, "Version")
    if not version:
        print("::error::No Version header found in plugin file", file=sys.stderr)
        sys.exit(1)

    requires = header_field(php, "Requires at least") or header_field(
        readme, "Requires at least"
    )
    requires_php = header_field(php, "Requires PHP") or header_field(
        readme, "Requires PHP"
    )
    tested = header_field(readme, "Tested up to")
    stable = header_field(readme, "Stable tag")

    # Consistency warnings (do not fail the build).
    const_match = re.search(r"GOO1_MCP_VERSION['\"]\s*,\s*['\"]([^'\"]+)", php)
    if const_match and const_match.group(1) != version:
        print(
            "::warning::GOO1_MCP_VERSION ({}) does not match header Version ({})".format(
                const_match.group(1), version
            )
        )
    if stable and stable != version:
        print(
            "::warning::readme Stable tag ({}) does not match header Version ({})".format(
                stable, version
            )
        )

    with open(args.meta, "r", encoding="utf-8") as fh:
        meta = json.load(fh, object_pairs_hook=OrderedDict)

    download_url = "https://github.com/{owner}/{repo}/releases/download/{tag}/{slug}.zip".format(
        owner=args.owner, repo=args.repo, tag=args.tag, slug=args.slug
    )

    meta["version"] = version
    meta["download_url"] = download_url
    if requires:
        meta["requires"] = requires
    if requires_php:
        meta["requires_php"] = requires_php
    if tested:
        meta["tested"] = tested
    meta["last_updated"] = datetime.datetime.now(datetime.timezone.utc).strftime(
        "%Y-%m-%d %H:%M:%S"
    )

    entries = parse_changelog(readme)
    if entries:
        meta.setdefault("sections", OrderedDict())
        meta["sections"]["changelog"] = changelog_to_html(entries)
        notice = upgrade_notice_from(entries)
        if notice:
            meta["upgrade_notice"] = notice

    # Place slug right after name; requires_php right after requires.
    ordered = OrderedDict()
    for key, val in meta.items():
        if key == "slug":
            continue
        if key == "requires_php":
            continue
        ordered[key] = val
        if key == "name" and "slug" in meta:
            ordered["slug"] = args.slug
        if key == "name" and "slug" not in meta:
            ordered["slug"] = args.slug
        if key == "requires" and "requires_php" in meta:
            ordered["requires_php"] = meta["requires_php"]
    meta = ordered

    with open(args.meta, "w", encoding="utf-8") as fh:
        json.dump(meta, fh, ensure_ascii=False, indent=4)
        fh.write("\n")

    print("version={}".format(version))
    print("Updated {} -> version {}, download_url {}".format(args.meta, version, download_url))

    # Expose version/tag to the GitHub Actions step if running in CI.
    import os
    gh_out = os.environ.get("GITHUB_OUTPUT")
    if gh_out:
        with open(gh_out, "a", encoding="utf-8") as fh:
            fh.write("version={}\n".format(version))
            fh.write("tag={}\n".format(args.tag))


if __name__ == "__main__":
    main()
