#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repository_root="$(cd "${script_dir}/.." && pwd)"
plugin_slug="indexlane-safe-webp-queue"
plugin_file="${repository_root}/${plugin_slug}.php"
output_dir="${repository_root}/dist"
version="$(sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*$/\1/p' "${plugin_file}")"

if [[ ! "${version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	printf 'Could not read a semantic version from %s.\n' "${plugin_file}" >&2
	exit 1
fi

if ! grep -Fqx ' * Plugin Name: IndexLane Safe WebP Queue' "${plugin_file}"; then
	printf 'The release plugin name is not final.\n' >&2
	exit 1
fi

if grep -Eq '^[[:space:]]*\*[[:space:]]*Update URI:' "${plugin_file}"; then
	printf 'The release plugin must not declare Update URI.\n' >&2
	exit 1
fi

if ! grep -Fqx "Stable tag: ${version}" "${repository_root}/readme.txt" ||
	! grep -Fqx 'Tested up to: 7.1' "${repository_root}/readme.txt" ||
	! grep -Fqx 'Contributors: wpfixpath' "${repository_root}/readme.txt"; then
	printf 'The WordPress.org readme metadata is not aligned with the release.\n' >&2
	exit 1
fi

release_files=(
	"CHANGELOG.md"
	"LICENSE"
	"indexlane-safe-webp-queue.php"
	"readme.txt"
	"uninstall.php"
)

release_directories=(
	"assets"
	"includes"
)

wordpress_org_assets=(
	"wporg-assets/banner-1544x500.png"
	"wporg-assets/banner-772x250.png"
	"wporg-assets/icon-128x128.png"
	"wporg-assets/icon-256x256.png"
	"screenshot-1.png"
	"screenshot-2.png"
	"screenshot-3.png"
	"screenshot-4.png"
)

for relative_path in "${release_files[@]}" "${release_directories[@]}" "${wordpress_org_assets[@]}"; do
	if [[ ! -e "${repository_root}/${relative_path}" ]]; then
		printf 'Required release input is missing: %s\n' "${relative_path}" >&2
		exit 1
	fi
done

temporary_root="$(mktemp -d "${TMPDIR:-/tmp}/indexlane-safe-webp-queue.XXXXXX")"
package_root="${temporary_root}/${plugin_slug}"
svn_root="${temporary_root}/svn"
archive_path="${output_dir}/${plugin_slug}-${version}.zip"
svn_output="${output_dir}/${plugin_slug}-${version}-svn"
svn_archive="${output_dir}/${plugin_slug}-${version}-svn.zip"
checksum_path="${output_dir}/${plugin_slug}-${version}-SHA256SUMS"

cleanup() {
	rm -rf "${temporary_root}"
}
trap cleanup EXIT

mkdir -p "${package_root}" "${svn_root}/assets" "${svn_root}/tags/${version}" "${svn_root}/trunk" "${output_dir}"

for relative_path in "${release_files[@]}"; do
	cp "${repository_root}/${relative_path}" "${package_root}/"
done

for relative_path in "${release_directories[@]}"; do
	cp -R "${repository_root}/${relative_path}" "${package_root}/"
done

if find "${package_root}" -name '.DS_Store' -o -name '__MACOSX' | grep -q .; then
	printf 'The release package contains macOS archive metadata.\n' >&2
	exit 1
fi

rm -f "${archive_path}" "${svn_archive}" "${checksum_path}"
(
	cd "${temporary_root}"
	zip -X -q -r "${archive_path}" "${plugin_slug}"
)

cp -R "${package_root}/." "${svn_root}/trunk/"
cp -R "${package_root}/." "${svn_root}/tags/${version}/"

for relative_path in "${wordpress_org_assets[@]}"; do
	cp "${repository_root}/${relative_path}" "${svn_root}/assets/$(basename "${relative_path}")"
done

if [[ -e "${svn_output}" ]]; then
	rm -rf "${svn_output}"
fi
cp -R "${svn_root}" "${svn_output}"

(
	cd "${svn_root}"
	zip -X -q -r "${svn_archive}" assets tags trunk
)

(
	cd "${output_dir}"
	shasum -a 256 "$(basename "${archive_path}")" "$(basename "${svn_archive}")" > "$(basename "${checksum_path}")"
)

diff -qr "${svn_root}/trunk" "${svn_root}/tags/${version}" >/dev/null

printf '%s\n' "${archive_path}" "${svn_output}" "${svn_archive}" "${checksum_path}"
