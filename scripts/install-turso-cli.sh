#!/usr/bin/env bash
set -euo pipefail

# Keep the executable in the Serverless package, not in a CI runner or image.
# A changed release artifact fails closed before deployment.
version='v1.0.34'
archive='turso-cli_Linux_x86_64.tar.gz'
url="https://github.com/tursodatabase/turso-cli/releases/download/${version}/${archive}"
# Published by the v1.0.34 GitHub release's checksums.txt asset.
sha256='9eb67f3efece578a6407d1fa535a75e8d7b5cde4f6af3ac1dd79929e99768dc9'

mkdir -p bin
curl --fail --location --silent --show-error "$url" --output "/tmp/$archive"
printf '%s  %s\n' "$sha256" "/tmp/$archive" | sha256sum --check --status
tar --extract --gzip --file "/tmp/$archive" --directory bin turso
chmod 0755 bin/turso

sqlite_version='3490100'
sqlite_archive="sqlite-autoconf-${sqlite_version}.tar.gz"
sqlite_url="https://www.sqlite.org/2025/${sqlite_archive}"
sqlite_sha256='106642d8ccb36c5f7323b64e4152e9b719f7c0215acf5bfeac3d5e7f97b59254'
sqlite_source="/tmp/sqlite-autoconf-${sqlite_version}"

rm -rf "$sqlite_source"
curl --fail --location --silent --show-error "$sqlite_url" --output "/tmp/$sqlite_archive"
printf '%s  %s\n' "$sqlite_sha256" "/tmp/$sqlite_archive" | sha256sum --check --status
tar --extract --gzip --file "/tmp/$sqlite_archive" --directory /tmp
(
    cd "$sqlite_source"
    ./configure --disable-readline --disable-shared --enable-static CFLAGS='-Os' LDFLAGS='-static'
    make -j"$(nproc)" sqlite3
)
install -m 0755 "$sqlite_source/sqlite3" bin/sqlite3
