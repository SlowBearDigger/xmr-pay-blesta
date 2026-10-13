#!/usr/bin/env bash
set -euo pipefail

if [[ $# != 1 ]]; then
    printf 'Usage: %s OUTPUT_ZIP\n' "$0" >&2
    exit 1
fi
repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
output_directory="$(cd -- "$(dirname -- "$1")" && pwd)"
output="$output_directory/$(basename -- "$1")"
test ! -e "$output"
bundle_path=components/gateways/nonmerchant/xmrpay/vendor/xmrpay
(
    cd "$repo_root/$bundle_path"
    sha256sum --check --quiet MANIFEST.sha256
)
staging_root="$(mktemp -d)"
trap 'rm -rf -- "$staging_root"' EXIT
cd "$repo_root"
git ls-files -z -- components plugins README.md CHANGELOG.md LICENSE |
    tar --null -T - -cf - | tar -xf - -C "$staging_root"
mkdir -p "$staging_root/$bundle_path"
(
    cd "$repo_root/$bundle_path"
    {
        printf '%s\n' MANIFEST.sha256 SOURCES.txt
        cut -c 67- MANIFEST.sha256
    } | tar --verbatim-files-from -T - -cf -
) | tar -xf - -C "$staging_root/$bundle_path"
cd "$staging_root"
zip -q -r "$output" components plugins README.md CHANGELOG.md LICENSE
