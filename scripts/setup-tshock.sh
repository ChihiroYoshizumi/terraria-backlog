#!/usr/bin/env bash
#
# TShock Dedicated Server (対応バージョン固定) をローカルに取得・展開する。
#
# 用途:
#   1. adapter/ の C# ビルドが参照する TShockAPI.dll / TerrariaServer.dll / OTAPI.dll を用意する
#      (Task01 完了条件: Adapter Plugin が build できる)
#   2. ローカルで TShock Dedicated Server を起動し、Plugin ロード確認をするための配布物を用意する
#      (README の手動 Smoke Test 手順から利用する)
#
# このスクリプトは TShock 本体をダウンロードするだけで、
# サーバーの起動・World 作成・クライアント接続確認までは行わない。
#
# 対応バージョン (docs/design.md §2.3):
#   TShock:   6.1.0
#   Terraria: 1.4.5.6
#
# Usage:
#   scripts/setup-tshock.sh [--force] [--os <linux-x64|linux-arm64|linux-arm|osx-x64|win-x64>] [--dest <dir>]
#
set -euo pipefail

TSHOCK_VERSION="6.1.0"
TERRARIA_VERSION="1.4.5.6"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST_DIR="${REPO_ROOT}/.tshock-server"
CACHE_DIR="${REPO_ROOT}/.tshock-cache"
FORCE=0
OS_OVERRIDE=""

usage() {
  grep -E '^#( |$)' "${BASH_SOURCE[0]}" | sed -E 's/^# ?//'
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --force)
      FORCE=1
      shift
      ;;
    --os)
      OS_OVERRIDE="$2"
      shift 2
      ;;
    --dest)
      DEST_DIR="$2"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown argument: $1" >&2
      usage >&2
      exit 1
      ;;
  esac
done

detect_os_asset() {
  if [[ -n "${OS_OVERRIDE}" ]]; then
    echo "${OS_OVERRIDE}"
    return
  fi

  local uname_s uname_m
  uname_s="$(uname -s)"
  uname_m="$(uname -m)"

  case "${uname_s}" in
    Darwin)
      # TShock 6.1.0 は osx-x64 のみを配布する (osx-arm64 バイナリはない)。
      # Apple Silicon では Rosetta 2 経由で osx-x64 を実行する。
      echo "osx-x64"
      ;;
    Linux)
      case "${uname_m}" in
        x86_64) echo "linux-x64" ;;
        aarch64|arm64) echo "linux-arm64" ;;
        arm*) echo "linux-arm" ;;
        *)
          echo "Unsupported Linux arch: ${uname_m}" >&2
          exit 1
          ;;
      esac
      ;;
    MINGW*|MSYS*|CYGWIN*)
      echo "win-x64"
      ;;
    *)
      echo "Unsupported OS: ${uname_s}. Use --os to override." >&2
      exit 1
      ;;
  esac
}

OS_ASSET="$(detect_os_asset)"
ASSET_NAME="TShock-${TSHOCK_VERSION}-for-Terraria-${TERRARIA_VERSION}-${OS_ASSET}-Release.zip"
DOWNLOAD_URL="https://github.com/Pryaxis/TShock/releases/download/v${TSHOCK_VERSION}/${ASSET_NAME}"
ZIP_PATH="${CACHE_DIR}/${ASSET_NAME}"

if [[ -d "${DEST_DIR}" && "${FORCE}" -ne 1 ]]; then
  echo "TShock server already present at ${DEST_DIR} (use --force to re-download)."
  exit 0
fi

mkdir -p "${CACHE_DIR}"

if [[ ! -f "${ZIP_PATH}" || "${FORCE}" -eq 1 ]]; then
  echo "Downloading ${ASSET_NAME} ..."
  curl -fL --retry 3 -o "${ZIP_PATH}" "${DOWNLOAD_URL}"
else
  echo "Using cached archive ${ZIP_PATH}"
fi

rm -rf "${DEST_DIR}"
mkdir -p "${DEST_DIR}"

WORK_DIR="$(mktemp -d)"
trap 'rm -rf "${WORK_DIR}"' EXIT

echo "Extracting ${ASSET_NAME} ..."
unzip -q "${ZIP_PATH}" -d "${WORK_DIR}"

# 配布 zip は中に単一の .tar を含む（例: TShock-Beta-<os>-Release.tar）。
TAR_FILE="$(find "${WORK_DIR}" -maxdepth 1 -name '*.tar' | head -n 1)"
if [[ -z "${TAR_FILE}" ]]; then
  echo "Expected a .tar file inside the release zip but found none." >&2
  exit 1
fi

tar -xf "${TAR_FILE}" -C "${DEST_DIR}"

echo ""
echo "TShock ${TSHOCK_VERSION} (for Terraria ${TERRARIA_VERSION}, ${OS_ASSET}) installed to:"
echo "  ${DEST_DIR}"
echo ""
echo "Layout:"
echo "  ${DEST_DIR}/TShock.Server          - dedicated server executable"
echo "  ${DEST_DIR}/bin/TerrariaServer.dll - Adapter build reference"
echo "  ${DEST_DIR}/bin/OTAPI.dll          - Adapter build reference"
echo "  ${DEST_DIR}/ServerPlugins/         - drop TerrariaBacklog.Adapter.dll here to load it"
echo ""
echo "Next steps:"
echo "  1. Build/deploy the Adapter plugin:   scripts/deploy-adapter.sh"
echo "  2. Start the server (manual, see adapter/README.md / root README.md):"
echo "       cd ${DEST_DIR} && ./TShock.Server -world <worldfile> -autocreate 2"
echo ""
echo "このスクリプトはダウンロード・展開のみを行う。TShock 本体は git 管理しない (.gitignore を参照)。"
