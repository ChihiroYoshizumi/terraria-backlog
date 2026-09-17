#!/usr/bin/env bash
#
# TShock Dedicated Server (対応バージョン固定) をローカルに取得・展開する。
#
# 用途:
#   1. adapter/ の C# ビルドが参照する TerrariaServer.exe / TShockAPI.dll を用意する
#      (Task01 完了条件: Adapter Plugin が build できる)
#   2. ローカルで TShock Dedicated Server を起動し、Plugin ロード確認をするための配布物を用意する
#      (README の手動 Smoke Test 手順から利用する)
#
# このスクリプトは TShock 本体をダウンロードするだけで、
# サーバーの起動・World 作成・クライアント接続確認までは行わない。
#
# 対応バージョン (docs/design.md §2.3):
#   TShock:   4.3.13
#   Terraria: 1.3.0.8
#
# TShock 4.3.13 の配布物は OS 非依存の単一 zip (tshock_4.3.13.zip) であり、
# .NET Framework 4.5 向けの assembly を含む。起動には Mono (macOS / Linux / WSL) が必要。
#
# Usage:
#   scripts/setup-tshock.sh [--force] [--dest <dir>]
#
set -euo pipefail

# 対応バージョンの正本は docs/design.md §2.3。ここを変更する場合は
# docs/design.md §2.3 と各 README の記載も併せて更新する。
TSHOCK_VERSION="4.3.13"
TERRARIA_VERSION="1.3.0.8"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEFAULT_DEST_DIR="${REPO_ROOT}/.tshock-server"
DEST_DIR="${DEFAULT_DEST_DIR}"
CACHE_DIR="${REPO_ROOT}/.tshock-cache"
FORCE=0

usage() {
  grep -E '^#( |$)' "${BASH_SOURCE[0]}" | sed -E 's/^# ?//'
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --force)
      FORCE=1
      shift
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

# TShock 4.3.13 は OS 別ビルドを配布しておらず、単一 zip のみ。
ASSET_NAME="tshock_${TSHOCK_VERSION}.zip"
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

echo "Extracting ${ASSET_NAME} ..."
# tshock_4.3.13.zip はトップレベルにサーバーのファイルを直接含む
# (TerrariaServer.exe / *.dll / ServerPlugins/*)。ラッパーディレクトリや .tar はない。
unzip -q "${ZIP_PATH}" -d "${DEST_DIR}"

# 想定した配布物の構造になっていることを確認してから成功扱いにする。
for required in "TerrariaServer.exe" "ServerPlugins/TShockAPI.dll"; do
  if [[ ! -f "${DEST_DIR}/${required}" ]]; then
    echo "Expected file missing after extraction: ${DEST_DIR}/${required}" >&2
    echo "Archive layout may have changed: ${ZIP_PATH}" >&2
    exit 1
  fi
done

echo ""
echo "TShock ${TSHOCK_VERSION} (for Terraria ${TERRARIA_VERSION}) installed to:"
echo "  ${DEST_DIR}"
echo ""
echo "Layout:"
echo "  ${DEST_DIR}/TerrariaServer.exe        - dedicated server (run with mono)"
echo "  ${DEST_DIR}/ServerPlugins/TShockAPI.dll - Adapter build reference"
echo "  ${DEST_DIR}/ServerPlugins/            - drop TerrariaBacklog.Adapter.dll here to load it"
echo ""
# 既定以外の場所へ展開した場合、deploy-adapter.sh には --tshock-dir を渡す必要がある。
DEPLOY_CMD="scripts/deploy-adapter.sh"
if [[ "${DEST_DIR}" != "${DEFAULT_DEST_DIR}" ]]; then
  DEPLOY_CMD="scripts/deploy-adapter.sh --tshock-dir \"${DEST_DIR}\""
fi

echo "Next steps:"
echo "  1. Build/deploy the Adapter plugin:   ${DEPLOY_CMD}"
echo "  2. Start the server (manual, requires Mono; see adapter/README.md / root README.md):"
echo "       cd ${DEST_DIR} && mono TerrariaServer.exe -world <worldfile> -autocreate 2"
echo ""
echo "このスクリプトはダウンロード・展開のみを行う。TShock 本体は git 管理しない (.gitignore を参照)。"
