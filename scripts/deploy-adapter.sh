#!/usr/bin/env bash
#
# adapter/ を Release ビルドし、TerrariaBacklog.Adapter.dll を
# ローカル TShock Dedicated Server の ServerPlugins/ に配置する。
#
# 前提: scripts/setup-tshock.sh を先に実行し、.tshock-server/ が存在すること。
#
# Usage:
#   scripts/deploy-adapter.sh [--tshock-dir <dir>]
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TSHOCK_DIR="${REPO_ROOT}/.tshock-server"
CONFIGURATION="Release"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --tshock-dir)
      TSHOCK_DIR="$2"
      shift 2
      ;;
    -h|--help)
      grep -E '^#( |$)' "${BASH_SOURCE[0]}" | sed -E 's/^# ?//'
      exit 0
      ;;
    *)
      echo "Unknown argument: $1" >&2
      exit 1
      ;;
  esac
done

if [[ ! -d "${TSHOCK_DIR}/ServerPlugins" ]]; then
  echo "TShock server not found at ${TSHOCK_DIR} (run scripts/setup-tshock.sh first)." >&2
  exit 1
fi

echo "Building adapter (${CONFIGURATION})..."
dotnet build "${REPO_ROOT}/adapter/TerrariaBacklog.Adapter.csproj" \
  -c "${CONFIGURATION}" \
  -p:TShockServerDir="${TSHOCK_DIR}"

BUILD_OUTPUT_DIR="${REPO_ROOT}/adapter/bin/${CONFIGURATION}/net9.0"
PLUGIN_DLL="${BUILD_OUTPUT_DIR}/TerrariaBacklog.Adapter.dll"

if [[ ! -f "${PLUGIN_DLL}" ]]; then
  echo "Build output not found at ${PLUGIN_DLL}" >&2
  exit 1
fi

cp "${PLUGIN_DLL}" "${TSHOCK_DIR}/ServerPlugins/"
if [[ -f "${BUILD_OUTPUT_DIR}/TerrariaBacklog.Adapter.pdb" ]]; then
  cp "${BUILD_OUTPUT_DIR}/TerrariaBacklog.Adapter.pdb" "${TSHOCK_DIR}/ServerPlugins/"
fi

echo "Deployed TerrariaBacklog.Adapter.dll to ${TSHOCK_DIR}/ServerPlugins/"
