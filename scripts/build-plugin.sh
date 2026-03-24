#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC_DIR="$ROOT_DIR/src"
BUILD_DIR="$ROOT_DIR/.build"
STAGE_DIR="$BUILD_DIR/WPpknewsletter"
EXT_DIR="$ROOT_DIR/extensions"

VERSION="$(sed -n "s/^define('WPPKNEWSLETTER_VERSION', '\\([^']*\\)').*/\\1/p" "$SRC_DIR/wppknewsletter.php")"

if [[ -z "$VERSION" ]]; then
  echo "Impossible de lire la version depuis src/wppknewsletter.php" >&2
  exit 1
fi

rm -rf "$BUILD_DIR"
mkdir -p "$STAGE_DIR" "$EXT_DIR"

cp -R "$SRC_DIR"/. "$STAGE_DIR"/

rm -f "$EXT_DIR/WPpknewsletter-$VERSION.zip"
cd "$BUILD_DIR"
COPYFILE_DISABLE=1 zip -X -r "$EXT_DIR/WPpknewsletter-$VERSION.zip" WPpknewsletter >/dev/null

echo "$EXT_DIR/WPpknewsletter-$VERSION.zip"
