#!/usr/bin/env bash
# Builds build/affiwave-woocommerce.zip — the installable plugin (no dev tools, tests or vendor).
# The asset name stays the same in every GitHub release, so the link
# https://github.com/websystemspl/affiwave-woocommerce/releases/latest/download/affiwave-woocommerce.zip
# always points to the newest version.
set -euo pipefail
cd "$(dirname "$0")/.."
rm -rf build && mkdir -p build/affiwave-woocommerce
cp -r affiwave-woocommerce.php src languages readme.txt LICENSE build/affiwave-woocommerce/
(cd build && zip -qr affiwave-woocommerce.zip affiwave-woocommerce)
echo "build/affiwave-woocommerce.zip ($(grep -m1 'Version:' affiwave-woocommerce.php | awk '{print $NF}'))"
