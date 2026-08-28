#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repository_root="$(cd "${script_dir}/.." && pwd)"
plugin_slug="indexlane-safe-webp-queue"
wp_cli_bin="${WP_CLI_BIN:-wp}"
pot_path="${1:-}"
remove_pot=false

if [[ -z "${pot_path}" ]]; then
	pot_path="$(mktemp "${TMPDIR:-/tmp}/indexlane-ilswq-i18n.XXXXXX")"
	remove_pot=true
fi

cleanup() {
	if [[ "${remove_pot}" == true ]]; then
		rm -f "${pot_path}"
	fi
}
trap cleanup EXIT

php "${repository_root}/tests/i18n-audit.php"

"${wp_cli_bin}" i18n make-pot \
	"${repository_root}" \
	"${pot_path}" \
	--slug="${plugin_slug}" \
	--domain="${plugin_slug}" \
	--include="${plugin_slug}.php,includes/*.php,assets/admin.js" \
	--exclude="dist,tests,scripts,wporg-assets"

grep -Fq '"X-Domain: indexlane-safe-webp-queue\n"' "${pot_path}"
grep -Fq 'msgid "Conflict"' "${pot_path}"
grep -Fq 'msgid "Request failed."' "${pot_path}"
grep -Fq 'msgid "Attachment ID"' "${pot_path}"
grep -Fq 'msgid "Select %s"' "${pot_path}"

if grep -Eq '^#: (dist|tests|scripts|wporg-assets)/' "${pot_path}"; then
	printf 'POT extraction unexpectedly included non-release files.\n' >&2
	exit 1
fi

if command -v msgfmt >/dev/null 2>&1; then
	msgfmt --check-format -o /dev/null "${pot_path}"
fi

message_count="$(grep -c '^msgid ' "${pot_path}")"
printf 'Translation extraction passed (%d messages): %s\n' "$(( message_count - 1 ))" "${pot_path}"
