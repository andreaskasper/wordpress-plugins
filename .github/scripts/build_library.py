#!/usr/bin/env python3
"""
Build the PrivacyPilot clause library into one JSON file.

Source layout (src/goo1-privacypilot-library/):

    meta.json          name + schema version
    documents.json     document types (privacy, cookies, terms, ...)
    questions.json     questionnaire definition
    services.json      service catalogue (scanner + blocker share it)
    clauses/<doc>/<lang>/*.md
                       one clause per file, front matter between '---' lines:
                         id: privacy-contact        (unique per doc + lang)
                         order: 120                 (sort key inside the doc)
                         when: audience.eu          (condition, default "true")
                         version: 1                 (bump on every change)
                         change: editorial|material
                         effective: 2026-12-10      (optional, deadline)
                         jurisdiction: eu, uk       (informational)
                         reviewed_by: / reviewed_at: (optional, legal review)

Output: a single, deterministic JSON document. The library version is taken
from the last git commit that touched the source directory, so rebuilding the
same commit always yields the same file (and the same signature).
"""

import argparse
import datetime
import hashlib
import json
import os
import re
import subprocess
import sys

ALLOWED_CHANGE = {"editorial", "material"}
FRONT_KEYS = {
    "id", "order", "when", "version", "change", "effective",
    "jurisdiction", "reviewed_by", "reviewed_at",
}
EXPR_ALLOWED = re.compile(r"^[A-Za-z0-9_.\s()'!=<>,\-]*$")


def fail(msg):
    print("::error::" + msg, file=sys.stderr)
    sys.exit(1)


def load_json(path):
    with open(path, "r", encoding="utf-8") as fh:
        return json.load(fh)


def parse_front_matter(text, path):
    if not text.startswith("---"):
        fail("{}: missing front matter".format(path))
    parts = text.split("\n---", 1)
    if len(parts) != 2:
        fail("{}: unterminated front matter".format(path))
    head = parts[0][3:].strip("\n")
    body = parts[1]
    body = body[body.index("\n") + 1:] if "\n" in body else ""
    meta = {}
    for line in head.splitlines():
        if not line.strip() or line.strip().startswith("#"):
            continue
        if ":" not in line:
            fail("{}: bad front matter line: {}".format(path, line))
        key, value = line.split(":", 1)
        key = key.strip()
        if key not in FRONT_KEYS:
            fail("{}: unknown front matter key '{}'".format(path, key))
        meta[key] = value.strip()
    return meta, body.strip() + "\n"


def check_expr(expr, where):
    if not EXPR_ALLOWED.match(expr):
        fail("{}: condition contains unsupported characters: {}".format(where, expr))
    depth = 0
    in_str = False
    for ch in expr:
        if ch == "'":
            in_str = not in_str
        elif not in_str and ch == "(":
            depth += 1
        elif not in_str and ch == ")":
            depth -= 1
            if depth < 0:
                break
    if depth != 0 or in_str:
        fail("{}: unbalanced parentheses or quotes in condition: {}".format(where, expr))


def library_version(src):
    try:
        out = subprocess.check_output(
            ["git", "log", "-1", "--format=%ct", "--", src],
            stderr=subprocess.DEVNULL,
        ).decode().strip()
        if out:
            ts = int(out)
            return datetime.datetime.fromtimestamp(ts, datetime.timezone.utc).strftime("%Y.%m.%d.%H%M")
    except Exception:
        pass
    return datetime.datetime.now(datetime.timezone.utc).strftime("%Y.%m.%d.%H%M")


def git_sha(src):
    try:
        return subprocess.check_output(["git", "log", "-1", "--format=%h", "--", src], stderr=subprocess.DEVNULL).decode().strip()
    except Exception:
        return ""


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--src", required=True)
    ap.add_argument("--out", required=True)
    ap.add_argument("--version", default=None, help="override the derived library version")
    args = ap.parse_args()

    src = args.src.rstrip("/")
    meta = load_json(os.path.join(src, "meta.json"))
    documents = load_json(os.path.join(src, "documents.json"))
    questions = load_json(os.path.join(src, "questions.json"))
    services = load_json(os.path.join(src, "services.json"))
    ui_path = os.path.join(src, "ui.json")
    ui = load_json(ui_path) if os.path.exists(ui_path) else {}

    doc_ids = {d["id"] for d in documents}
    if len(doc_ids) != len(documents):
        fail("documents.json: duplicate document id")

    seen_q = set()
    for q in questions:
        for key in ("id", "type", "label", "section", "scope"):
            if key not in q:
                fail("questions.json: question without '{}': {}".format(key, q.get("id")))
        if q["id"] in seen_q:
            fail("questions.json: duplicate id {}".format(q["id"]))
        seen_q.add(q["id"])
        if q["scope"] not in ("entity", "site"):
            fail("questions.json: {} has invalid scope".format(q["id"]))
        if "when" in q:
            check_expr(q["when"], "question " + q["id"])

    seen_s = set()
    for s in services:
        for key in ("id", "name", "category", "provider"):
            if key not in s:
                fail("services.json: service without '{}': {}".format(key, s.get("id")))
        if s["id"] in seen_s:
            fail("services.json: duplicate id {}".format(s["id"]))
        seen_s.add(s["id"])
        for pattern in s.get("patterns", {}).get("script", []) + s.get("patterns", {}).get("iframe", []) + s.get("patterns", {}).get("inline", []):
            try:
                re.compile(pattern)
            except re.error as exc:
                fail("services.json: {} has an invalid pattern {}: {}".format(s["id"], pattern, exc))

    clauses = []
    seen_c = set()
    clause_root = os.path.join(src, "clauses")
    for doc in sorted(os.listdir(clause_root)):
        if doc not in doc_ids:
            fail("clauses/{}: unknown document".format(doc))
        for lang in sorted(os.listdir(os.path.join(clause_root, doc))):
            folder = os.path.join(clause_root, doc, lang)
            for name in sorted(os.listdir(folder)):
                if not name.endswith(".md"):
                    continue
                path = os.path.join(folder, name)
                with open(path, "r", encoding="utf-8") as fh:
                    front, body = parse_front_matter(fh.read(), path)
                cid = front.get("id")
                if not cid:
                    fail("{}: missing id".format(path))
                key = (doc, lang, cid)
                if key in seen_c:
                    fail("{}: duplicate clause id {}".format(path, cid))
                seen_c.add(key)
                change = front.get("change", "material")
                if change not in ALLOWED_CHANGE:
                    fail("{}: change must be editorial or material".format(path))
                when = front.get("when", "true") or "true"
                check_expr(when, path)
                effective = front.get("effective") or None
                if effective and not re.match(r"^\d{4}-\d{2}-\d{2}$", effective):
                    fail("{}: effective must be YYYY-MM-DD".format(path))
                clause = {
                    "id": cid,
                    "doc": doc,
                    "lang": lang,
                    "order": int(front.get("order", "500")),
                    "when": when,
                    "version": int(front.get("version", "1")),
                    "change": change,
                    "effective": effective,
                    "jurisdiction": [j.strip() for j in front.get("jurisdiction", "").split(",") if j.strip()],
                    "reviewed_by": front.get("reviewed_by") or None,
                    "reviewed_at": front.get("reviewed_at") or None,
                    "body": body,
                }
                clause["hash"] = hashlib.sha256(
                    (when + "\n" + body).encode("utf-8")
                ).hexdigest()[:16]
                clauses.append(clause)

    clauses.sort(key=lambda c: (c["doc"], c["lang"], c["order"], c["id"]))

    content = {
        "documents": documents,
        "questions": questions,
        "services": services,
        "ui": ui,
        "clauses": clauses,
    }
    canonical = json.dumps(content, sort_keys=True, ensure_ascii=False, separators=(",", ":"))
    library = {
        "name": meta.get("name", "PrivacyPilot clause library"),
        "schema": int(meta.get("schema", 1)),
        "version": args.version or library_version(src),
        "commit": git_sha(src),
        "content_hash": hashlib.sha256(canonical.encode("utf-8")).hexdigest(),
    }
    library.update(content)

    os.makedirs(os.path.dirname(os.path.abspath(args.out)), exist_ok=True)
    with open(args.out, "w", encoding="utf-8") as fh:
        json.dump(library, fh, ensure_ascii=False, indent=1, sort_keys=False)
        fh.write("\n")

    print("Built {}: version {}, {} documents, {} questions, {} services, {} clauses".format(
        args.out, library["version"], len(documents), len(questions), len(services), len(clauses)))


if __name__ == "__main__":
    main()
