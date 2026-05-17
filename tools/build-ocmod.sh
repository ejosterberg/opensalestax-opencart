#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later
#
# Build the OpenCart-installable .ocmod.zip artifact for a tagged release.
#
# Usage:
#   tools/build-ocmod.sh                    # uses version from composer.json or git
#   tools/build-ocmod.sh v0.1.0-alpha.1     # explicit version override
#
# Output: dist/opensalestax-opencart-<VERSION>.ocmod.zip
#
# The zip contains the OpenCart 4.x extension layout:
#   install.json
#   upload/admin/...
#   upload/catalog/...
#   upload/system/library/opensalestax/{bootstrap.php, *Adapter.php, vendor/}
#
# `vendor/` is the production-only composer tree (no dev dependencies, no
# phpunit / phpstan / php-cs-fixer). The bundled SDK + Guzzle + PSR
# interfaces add ~1.5 MB; the SDK alone is what the runtime extension
# actually uses.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"

VERSION="${1:-}"
if [ -z "$VERSION" ]; then
    if command -v jq >/dev/null 2>&1; then
        VERSION="$(jq -r '.extra.version // empty' composer.json 2>/dev/null || true)"
    fi
fi
if [ -z "$VERSION" ]; then
    # Fallback to the most recent git tag, otherwise to the alpha placeholder.
    VERSION="$(git describe --tags --abbrev=0 2>/dev/null || echo "v0.1.0-alpha.1")"
fi

# Normalize: strip leading "v" for the file name suffix readers prefer.
VERSION_NUM="${VERSION#v}"

DIST_DIR="$REPO_ROOT/dist"
STAGING_DIR="$REPO_ROOT/build/ocmod-staging"
ARTIFACT="$DIST_DIR/opensalestax-opencart-v${VERSION_NUM}.ocmod.zip"

echo "Building $ARTIFACT (version=$VERSION_NUM)"

rm -rf "$STAGING_DIR"
mkdir -p "$STAGING_DIR"
mkdir -p "$DIST_DIR"

# Stage extension/* into the zip root.
cp -R "$REPO_ROOT/extension/." "$STAGING_DIR/"

# Build a production-only vendor tree alongside the staging.
VENDOR_BUILD_DIR="$REPO_ROOT/build/vendor-build"
rm -rf "$VENDOR_BUILD_DIR"
mkdir -p "$VENDOR_BUILD_DIR"
cp "$REPO_ROOT/composer.json" "$VENDOR_BUILD_DIR/composer.json"
[ -f "$REPO_ROOT/composer.lock" ] && cp "$REPO_ROOT/composer.lock" "$VENDOR_BUILD_DIR/composer.lock"

PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"

# Pick a sensible composer entry on Windows + XAMPP if `composer` is missing.
if ! command -v "$COMPOSER_BIN" >/dev/null 2>&1; then
    if [ -f "/c/Users/ejosterberg/.local/bin/composer.phar" ]; then
        COMPOSER_BIN="/c/xampp/8.2.4/php/php.exe /c/Users/ejosterberg/.local/bin/composer.phar"
    fi
fi

(
    cd "$VENDOR_BUILD_DIR"
    # shellcheck disable=SC2086
    $COMPOSER_BIN install --no-dev --optimize-autoloader --no-progress --no-interaction
)

# Inject only the SDK + its transitive runtime dependencies. We need
# OpenSalesTax + Guzzle + PSR/HTTP + their autoload glue.
mkdir -p "$STAGING_DIR/upload/system/library/opensalestax/vendor"
cp -R "$VENDOR_BUILD_DIR/vendor/." "$STAGING_DIR/upload/system/library/opensalestax/vendor/"

# Copy our own src/ into the bundled vendor as a separate package, since
# the bundled autoloader is built from the build-time composer.json and won't
# know about a relative path back to our `src/`. We put the namespace-mapped
# tree alongside the vendor and add a small autoload-bridge file.
mkdir -p "$STAGING_DIR/upload/system/library/opensalestax/src"
cp -R "$REPO_ROOT/src/." "$STAGING_DIR/upload/system/library/opensalestax/src/"

# Bridge: tell the bundled autoloader where to find our own namespace.
cat > "$STAGING_DIR/upload/system/library/opensalestax/autoload-bridge.php" <<'PHP'
<?php
// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later
// Registers our connector's PSR-4 namespace with the bundled autoloader.
spl_autoload_register(static function (string $class): void {
    $prefix = 'OpenSalesTax\\OpenCart\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
PHP

# Patch bootstrap.php to also require the autoload bridge (in case build is
# run on a system where the staging copy diverges; idempotent: the registered
# loader skips classes outside our namespace).
sed -i 's|require_once __DIR__ . '"'"'/vendor/autoload.php'"'"';|require_once __DIR__ . "/vendor/autoload.php";\nrequire_once __DIR__ . "/autoload-bridge.php";|' "$STAGING_DIR/upload/system/library/opensalestax/bootstrap.php" || true

# Rewrite install.json's version field to the canonical version we built for.
if command -v jq >/dev/null 2>&1; then
    jq --arg v "$VERSION_NUM" '.version = $v' "$STAGING_DIR/install.json" > "$STAGING_DIR/install.json.tmp"
    mv "$STAGING_DIR/install.json.tmp" "$STAGING_DIR/install.json"
fi

# Strip OS-specific cruft.
find "$STAGING_DIR" -name ".DS_Store" -delete 2>/dev/null || true
find "$STAGING_DIR" -name "Thumbs.db" -delete 2>/dev/null || true

# Produce the zip. OpenCart expects install.json at the root, not nested.
rm -f "$ARTIFACT"
if command -v zip >/dev/null 2>&1; then
    (
        cd "$STAGING_DIR"
        zip -qr "$ARTIFACT" .
    )
else
    # Fallback: PHP's ZipArchive â€” portable across Windows / macOS / Linux.
    ZIP_PHP="$REPO_ROOT/build/zip-helper.php"
    cat > "$ZIP_PHP" <<'PHP_ZIP'
<?php
$src = $argv[1];
$dst = $argv[2];
$zip = new ZipArchive();
if ($zip->open($dst, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Failed to open $dst for writing\n");
    exit(3);
}
$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($iter as $file) {
    $absPath = $file->getRealPath();
    if ($absPath === false) {
        continue;
    }
    $relPath = ltrim(substr($absPath, strlen($src)), DIRECTORY_SEPARATOR . '/');
    $relPath = str_replace(DIRECTORY_SEPARATOR, '/', $relPath);
    if (!$zip->addFile($absPath, $relPath)) {
        fwrite(STDERR, "Failed to add $relPath to zip\n");
        exit(4);
    }
}
$zip->close();
PHP_ZIP
    "$PHP_BIN" "$ZIP_PHP" "$STAGING_DIR" "$ARTIFACT"
fi

echo "OK: $ARTIFACT"
ls -lh "$ARTIFACT"
