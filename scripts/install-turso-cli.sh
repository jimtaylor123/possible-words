#!/usr/bin/env bash
set -euo pipefail

# Keep the executable in the Serverless package, not in a CI runner or image.
# A changed release artifact fails closed before deployment.
version='v1.0.14'
archive='turso-cli_Linux_x86_64.tar.gz'
url="https://github.com/tursodatabase/turso-cli/releases/download/${version}/${archive}"
sha256='8a5e9d6631e1e82aedd3f3c58bb93852529a140bd3e5b9855c44b5bf167b43b0'

mkdir -p bin
curl --fail --location --silent --show-error "$url" --output "/tmp/$archive"
printf '%s  %s\n' "$sha256" "/tmp/$archive" | sha256sum --check --status
tar --extract --gzip --file "/tmp/$archive" --directory bin turso
chmod 0755 bin/turso
