#!/usr/bin/env bash
#
# Refresh the bundled picowebp library under includes/picowebp.
#
# The package is the source of truth: the plugin ships a copy so it keeps
# working on hosts with no Composer, and this script is how that copy follows
# the package. It reads either the installed Composer package or the local
# codec/ checkout used while developing picowebp.
#
# Usage:
#   bash scripts/sync-picowebp.sh [path-to-picowebp]

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repository_root="$(cd "${script_dir}/.." && pwd)"
target="${repository_root}/includes/picowebp"
source_dir="${1:-}"

if [[ -z "${source_dir}" ]]; then
	if [[ -d "${repository_root}/vendor/nikocodes/picowebp/src" ]]; then
		source_dir="${repository_root}/vendor/nikocodes/picowebp"
	elif [[ -d "${repository_root}/codec/picowebp/src" ]]; then
		source_dir="${repository_root}/codec/picowebp"
	else
		printf 'No picowebp sources found. Install the optional nikocodes/picowebp package on PHP 8.1+, or pass its source path as the first argument.\n' >&2
		exit 1
	fi
fi

for required in src LICENSE; do
	if [[ ! -e "${source_dir}/${required}" ]]; then
		printf 'The picowebp source is missing %s: %s\n' "${required}" "${source_dir}" >&2
		exit 1
	fi
done

# Never let a stray dotfile reach the release package: WordPress.org rejects
# hidden files, and the build script fails the release when it finds one.
if [[ -n "$(find "${source_dir}/src" -name '.*' -print -quit)" ]]; then
	printf 'The picowebp source tree contains a hidden file; refusing to bundle it.\n' >&2
	exit 1
fi

if [[ ! -f "${target}/README.md" ]]; then
	printf 'The bundled provenance notes are missing from %s; refusing to overwrite.\n' "${target}" >&2
	exit 1
fi

staged="$(mktemp -d "${TMPDIR:-/tmp}/picowebp-sync.XXXXXX")"
cleanup() {
	rm -rf "${staged}"
}
trap cleanup EXIT

cp -R "${source_dir}/src" "${staged}/src"
cp "${source_dir}/LICENSE" "${staged}/LICENSE"

rm -rf "${target}/src"
cp -R "${staged}/src" "${target}/src"
cp "${staged}/LICENSE" "${target}/LICENSE"

version=""
if [[ -f "${source_dir}/CHANGELOG.md" ]]; then
	version="$(sed -nE 's/^## \[?([0-9]+\.[0-9]+\.[0-9]+)\]?.*$/\1/p' "${source_dir}/CHANGELOG.md" | head -n1)"
fi

printf 'Bundled picowebp from %s%s\n' "${source_dir}" "${version:+ (version ${version})}"
printf 'Remember to update the upstream version note in includes/picowebp/README.md.\n'
