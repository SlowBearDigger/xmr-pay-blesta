#!/usr/bin/env bash
set -euo pipefail

if [[ $# != 2 ]]; then
    printf 'Usage: %s ENGINE_CHECKOUT CORE_CHECKOUT\n' "$0" >&2
    exit 1
fi

repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
engine_root="$(cd -- "$1" && pwd)"
core_root="$(cd -- "$2" && pwd)"
engine_revision=4d081522e0176531d4a61c2eda5bf8cd83b99b88
core_revision=d82e513a8796bb65a867116b3e716eb6c188086b
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
rm "$staging_root/engine/src/Carrot/AccountSecrets.php"
for component in Gateway InvoicePolicy OrderStore OutputOrderStore Settler; do
    cp "$core_root/src/$component.php" "$staging_root/core/src/"
done
cp "$core_root/LICENSE" "$staging_root/core/"
sed -i 's@docs/CRYPTO-PROVENANCE.md@NOTICE@' "$staging_root/engine/src/Carrot/X25519.php"
sed -i 's@\[the provenance review\](../../docs/CRYPTO-PROVENANCE.md)@[the package notice](../../NOTICE)@' \
    "$staging_root/engine/third-party/monero/ATTRIBUTION.md"
sed -i 's@See docs/CRYPTO-PROVENANCE.md for exact pins, evidence, limits and next decisions.@Packaged source revisions are recorded in ../SOURCES.txt.@' "$staging_root/engine/NOTICE"
printf '%s\n' 'THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.' \
    >> "$staging_root/engine/licenses/BSD-3-MONERO.txt"
printf 'engine %s\ncore %s\n\n' "$engine_revision" "$core_revision" > "$staging_root/SOURCES.txt"
cat >> "$staging_root/SOURCES.txt" <<'EOF'
Runtime subset: excludes AccountSecrets, ArrayOrderStore and research documents.
Executable PHP is unchanged. Notice links point to the packaged NOTICE.
The truncated Monero BSD disclaimer is completed from enote_utils.cpp at
b13ab0cabadeb301b2ce5282b077945e9ab3a7ba in seraphis-migration/monero.
EOF
(
    cd "$staging_root"
    find engine core -type f -print0 | sort -z | xargs -0 sha256sum > MANIFEST.sha256
)
rm -rf -- "$destination"
mv -- "$staging_root" "$destination"
