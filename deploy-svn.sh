#!/usr/bin/env bash
# Deploy Thai Nexus Logistics to WordPress.org SVN.
#
# Version policy: use 1.5.x patch releases only (1.5.11, 1.5.12, …).
# Do NOT tag 1.6, 1.7, or 2.0 unless the project owner explicitly approves.
# To override: ALLOW_MAJOR_VERSION=1 ./deploy-svn.sh "message" 1.6.0
#
# Usage:
#   ./deploy-svn.sh "Commit message"              # trunk only
#   ./deploy-svn.sh "Commit message" 1.5.11       # trunk + new tag
#   ./deploy-svn.sh "Commit message" 1.5.10 refresh # refresh existing tag from trunk

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
SVN_DIR="${SVN_DIR:-$HOME/svn-thai-nexus-logistics}"
SVN_URL="https://plugins.svn.wordpress.org/thai-nexus-logistics"
SVN_USER="${SVN_USER:-thainexus}"

MSG="${1:-}"
VERSION="${2:-}"
MODE="${3:-}"

if [[ -z "$MSG" ]]; then
  echo "Usage: $0 \"Commit message\" [version] [refresh]"
  exit 1
fi

validate_version() {
  local ver="$1"
  if [[ -z "$ver" ]]; then
    return 0
  fi
  if [[ ! "$ver" =~ ^1\.5\.[0-9]+$ ]]; then
    if [[ "${ALLOW_MAJOR_VERSION:-0}" != "1" ]]; then
      echo "Error: Version must stay on 1.5.x (e.g. 1.5.11). Got: $ver"
      echo "Set ALLOW_MAJOR_VERSION=1 only if the project owner approved 1.6+."
      exit 1
    fi
    echo "Warning: Deploying non-1.5.x version $ver (ALLOW_MAJOR_VERSION=1)."
  fi
}

validate_version "$VERSION"

if [[ ! -d "$SVN_DIR/.svn" ]]; then
  echo "Checking out SVN to $SVN_DIR ..."
  mkdir -p "$SVN_DIR"
  svn co "$SVN_URL" "$SVN_DIR" --username "$SVN_USER"
fi

echo "Updating SVN working copy..."
svn up -q "$SVN_DIR"

echo "Syncing plugin files to trunk (excluding PNG assets)..."
rsync -a --delete \
  --exclude='.git' \
  --exclude='.gitignore' \
  --exclude='admin/node_modules' \
  --exclude='deploy-svn.sh' \
  --exclude='*.png' \
  --exclude='assets/blueprints' \
  --exclude='vendor/dvdoug/boxpacker/tests' \
  "$PLUGIN_DIR/" "$SVN_DIR/trunk/"

if compgen -G "$PLUGIN_DIR"/*.png > /dev/null; then
  echo "Syncing marketing images to assets/..."
  cp "$PLUGIN_DIR"/*.png "$SVN_DIR/assets/" 2>/dev/null || true
  svn add --force "$SVN_DIR/assets/"*.png 2>/dev/null || true
  svn propset svn:mime-type image/png "$SVN_DIR/assets/"*.png 2>/dev/null || true
fi

cd "$SVN_DIR"
svn add --force trunk/* 2>/dev/null || true

if [[ -n "$VERSION" ]]; then
  if [[ "$MODE" == "refresh" ]]; then
    echo "Refreshing tag $VERSION from trunk..."
    svn rm -q "tags/$VERSION" 2>/dev/null || true
    svn cp -q trunk "tags/$VERSION"
  else
    echo "Creating tag $VERSION from trunk..."
    svn cp -q trunk "tags/$VERSION"
  fi
fi

echo ""
echo "Changes to commit:"
svn status -q
echo ""
read -r -p "Commit to WordPress.org SVN? [y/N] " CONFIRM
if [[ "$CONFIRM" =~ ^[Yy]$ ]]; then
  svn ci -m "$MSG" --username "$SVN_USER"
  echo "Done."
else
  echo "Aborted. Run manually: cd $SVN_DIR && svn ci -m \"$MSG\" --username $SVN_USER"
fi
