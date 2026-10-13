#!/usr/bin/env bash
set -euo pipefail

if [[ $# != 2 ]]; then
    printf 'Usage: %s ENGINE_CHECKOUT CORE_CHECKOUT\n' "$0" >&2
    exit 1
fi

repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
engine_root="$(cd -- "$1" && pwd)"
core_root="$(cd -- "$2" && pwd)"
engine_revision=1eb8e34d0cf5049698f6591427356f53eae79c88
core_revision=14cff31b99206d544db4b337f2fce356c631bfcf
vendor_root="$repo_root/components/gateways/nonmerchant/xmrpay/vendor"
destination="$vendor_root/xmrpay"

test "$(git -C "$engine_root" rev-parse HEAD)" = "$engine_revision"
test "$(git -C "$core_root" rev-parse HEAD)" = "$core_revision"
git -C "$engine_root" diff --quiet HEAD -- src third-party licenses autoload.php LICENSE NOTICE docs
git -C "$core_root" diff --quiet HEAD -- src LICENSE
test -z "$(git -C "$engine_root" ls-files --others -- src third-party licenses autoload.php LICENSE NOTICE docs)"
test -z "$(git -C "$core_root" ls-files --others -- src LICENSE)"
mkdir -p "$vendor_root"
staging_root="$(mktemp -d "$vendor_root/.xmrpay-build.XXXXXX")"
trap 'rm -rf -- "$staging_root"' EXIT
mkdir -p "$staging_root/engine" "$staging_root/core/src"
cp -a "$engine_root/src" "$engine_root/third-party" "$engine_root/licenses" "$staging_root/engine/"
cp "$engine_root/autoload.php" "$engine_root/LICENSE" "$engine_root/NOTICE" "$staging_root/engine/"
test ! -d "$staging_root/engine/src/Carrot"
for component in Gateway InvoicePolicy OrderStore OutputOrderStore Settler; do
    cp "$core_root/src/$component.php" "$staging_root/core/src/"
done
cp "$core_root/LICENSE" "$staging_root/core/"
printf 'engine %s\ncore %s\n\n' "$engine_revision" "$core_revision" > "$staging_root/SOURCES.txt"
cat >> "$staging_root/SOURCES.txt" <<'EOF'
Classic runtime subset: excludes ArrayOrderStore and research documents.
Source files and required upstream notices are copied unchanged.
EOF
(
    cd "$staging_root"
    find engine core -type f -print0 | sort -z | xargs -0 sha256sum > MANIFEST.sha256
)
rm -rf -- "$destination"
mv -- "$staging_root" "$destination"
