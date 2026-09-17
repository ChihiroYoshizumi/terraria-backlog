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

# 対応バージョンの正本は docs/design.md §2.3。ここを変更する場合は
# docs/design.md §2.3 と各 README の記載も併せて更新する。
TSHOCK_VERSION="6.1.0"
TERRARIA_VERSION="1.4.5.6"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEFAULT_DEST_DIR="${REPO_ROOT}/.tshock-server"
DEST_DIR="${DEFAULT_DEST_DIR}"
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

# linux / osx の配布 zip は中に単一の .tar を含む（例: TShock-Beta-<os>-Release.tar）が、
# win-x64 の配布 zip はサーバーのファイルを直接含む。両方の構造を扱う。
TAR_FILE="$(find "${WORK_DIR}" -maxdepth 1 -name '*.tar' | head -n 1)"
if [[ -n "${TAR_FILE}" ]]; then
  tar -xf "${TAR_FILE}" -C "${DEST_DIR}"
else
  shopt -s dotglob nullglob
  extracted=("${WORK_DIR}"/*)
  if [[ ${#extracted[@]} -eq 0 ]]; then
    echo "Release zip appears to be empty: ${ZIP_PATH}" >&2
    exit 1
  fi
  if [[ ${#extracted[@]} -eq 1 && -d "${extracted[0]}" ]]; then
    # 単一のトップレベルディレクトリに包まれている場合は、その中身を DEST_DIR 直下へ移す。
    inner=("${extracted[0]}"/*)
    if [[ ${#inner[@]} -eq 0 ]]; then
      echo "Extracted directory is empty: ${extracted[0]}" >&2
      exit 1
    fi
    mv "${inner[@]}" "${DEST_DIR}/"
  else
    mv "${extracted[@]}" "${DEST_DIR}/"
  fi
  shopt -u dotglob nullglob
fi

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
# 既定以外の場所へ展開した場合、deploy-adapter.sh には --tshock-dir を渡す必要がある。
DEPLOY_CMD="scripts/deploy-adapter.sh"
if [[ "${DEST_DIR}" != "${DEFAULT_DEST_DIR}" ]]; then
  DEPLOY_CMD="scripts/deploy-adapter.sh --tshock-dir \"${DEST_DIR}\""
fi

echo "Next steps:"
echo "  1. Build/deploy the Adapter plugin:   ${DEPLOY_CMD}"
echo "  2. Start the server (manual, see adapter/README.md / root README.md):"
echo "       cd ${DEST_DIR} && ./TShock.Server -world <worldfile> -autocreate 2"
echo ""
echo "このスクリプトはダウンロード・展開のみを行う。TShock 本体は git 管理しない (.gitignore を参照)。"
