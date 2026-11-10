#!/usr/bin/env bash
# Build a plugin ZIP locally, the same way the release workflow does.
#   .github/scripts/build_local.sh goo1-privacypilot [outdir]
# Needs: python3, git, zip; php for signing (only if PRIVACYPILOT_SIGNING_KEY is set).
set -euo pipefail

SLUG="${1:?plugin slug}"
OUT="${2:-build-local}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

read_cfg() {
  python3 -c "import json,sys;c=json.load(open('.github/plugins.json'))['$SLUG'];l=c.get('library') or {};print({'dir':c['dir'],'puc':c['puc'],'strip':' '.join(c.get('strip',[])),'lib_src':l.get('src',''),'lib_out':l.get('out',''),'lib_bundle':l.get('bundle_as',''),'prebuild':c.get('prebuild','')}['$1'])"
}
DIR="$(read_cfg dir)"; PUC="$(read_cfg puc)"; STRIP="$(read_cfg strip)"
LIB_SRC="$(read_cfg lib_src)"; LIB_BUNDLE="$(read_cfg lib_bundle)"; PREBUILD="$(read_cfg prebuild)"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"
if [ -n "$PREBUILD" ]; then bash -c "$PREBUILD" >/dev/null; fi
cp -a "$DIR" "$WORK/$SLUG"
for f in $STRIP; do rm -rf "$WORK/$SLUG/$f"; done

git clone -q --depth 1 --branch "$PUC" https://github.com/YahnisElsts/plugin-update-checker.git "$WORK/puc" 2>/dev/null
rm -rf "$WORK/puc/.git"
mkdir -p "$WORK/$SLUG/plugin-update-checker"
cp -a "$WORK/puc/." "$WORK/$SLUG/plugin-update-checker/"

if [ -n "$LIB_SRC" ]; then
  python3 .github/scripts/build_library.py --src "$LIB_SRC" --out "$WORK/library.json"
  mkdir -p "$(dirname "$WORK/$SLUG/$LIB_BUNDLE")"
  cp "$WORK/library.json" "$WORK/$SLUG/$LIB_BUNDLE"
  cp "$WORK/library.json" "$OUT/library.json"
  if [ -n "${PRIVACYPILOT_SIGNING_KEY:-}" ]; then
    php .github/scripts/sign_library.php sign "$OUT/library.json"
  fi
fi

rm -f "$OUT/$SLUG.zip"
( cd "$WORK" && zip -qr "$OUT/$SLUG.zip" "$SLUG" )
echo "Built $OUT/$SLUG.zip"
