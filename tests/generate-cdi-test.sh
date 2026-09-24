#!/bin/bash

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEST_ROOT="$(mktemp -d)"
INSTALL_SCRIPT="${TEST_ROOT}/install.sh"
FUNCTION_SCRIPT="${TEST_ROOT}/generate-cdi.sh"

cleanup() {
  rm -rf "${TEST_ROOT}"
}

trap cleanup EXIT

python3 - "${REPO_ROOT}/nvidia-driver.plg" "${INSTALL_SCRIPT}" <<'PY'
import sys
import xml.etree.ElementTree as ET

root = ET.parse(sys.argv[1]).getroot()
for file_element in root.findall("FILE"):
    inline = file_element.find("INLINE")
    if inline is not None and inline.text and "generate_cdi()" in inline.text:
        with open(sys.argv[2], "w", encoding="utf-8") as output:
            output.write(inline.text)
        break
else:
    raise SystemExit("generate_cdi function not found")
PY

sed -n '/^generate_cdi() {/,/^}$/p' "${INSTALL_SCRIPT}" >"${FUNCTION_SCRIPT}"
# shellcheck source=/dev/null
source "${FUNCTION_SCRIPT}"

write_fixture() {
  local output="$1"

  jq -n \
    --arg compat_path "${MOCK_EXISTING_COMPAT_PATH:-}" \
    --arg compat_alias "${MOCK_EXISTING_COMPAT_ALIAS:-}" \
    --arg compat_folder "${MOCK_EXISTING_COMPAT_FOLDER:-}" '
    def mount($path): {
      hostPath: $path,
      containerPath: $path,
      options: ["ro"]
    };
    {
    cdiVersion: "0.5.0",
    kind: "nvidia.com/gpu",
    devices: [{name: "GPU-test", containerEdits: {}}],
    containerEdits: {
      mounts: [mount("/usr/lib64/existing.so")] +
        ([$compat_path, $compat_alias] | map(select(length > 0) | mount(.))),
      hooks: [
        {
          hookName: "createContainer",
          path: "/usr/bin/nvidia-cdi-hook",
          args: [
            "nvidia-cdi-hook",
            "create-symlinks",
            "--link",
            "../libnvidia-allocator.so.1::/usr/lib64/gbm/nvidia-drm_gbm.so"
          ]
        },
        {
          hookName: "createContainer",
          path: "/usr/bin/nvidia-cdi-hook",
          args: ([
            "nvidia-cdi-hook",
            "update-ldcache",
            "--folder",
            "/usr/lib64"
          ] + if $compat_folder == "" then [] else ["--folder", $compat_folder] end)
        }
      ]
    }
  }' >"${output}"
}

nvidia-ctk() {
  local argument output=""

  if [ "${MOCK_GENERATION_STATUS:-0}" -ne 0 ]; then
    return "${MOCK_GENERATION_STATUS}"
  fi

  for argument in "$@" ; do
    case "${argument}" in
      --output=*) output="${argument#--output=}" ;;
    esac
  done

  if [ "${MOCK_GENERATED_SPEC:-valid}" == "valid" ]; then
    write_fixture "${output}"
  else
    printf '%s\n' "${MOCK_GENERATED_SPEC}" >"${output}"
  fi
}

nvidia-container-cli() {
  if [ "${MOCK_DISCOVERY_STATUS:-0}" -ne 0 ]; then
    return "${MOCK_DISCOVERY_STATUS}"
  fi

  printf '%s' "${MOCK_LIBRARY_OUTPUT:-}"
}

assert_eq() {
  local expected="$1" actual="$2" message="$3"

  if [ "${expected}" != "${actual}" ]; then
    echo "FAIL: ${message}: expected '${expected}', got '${actual}'" >&2
    exit 1
  fi
}

run_positive_case() {
  local case_root="${TEST_ROOT}/positive"
  local lib32_dir="${case_root}/usr/lib"
  local lib64_dir="${case_root}/usr/lib64"
  local lib32="${lib32_dir}/libGLX_nvidia.so.595.84"
  local alias="${lib32_dir}/libGLX_nvidia.so.0"
  local broken_alias="${lib32_dir}/libnvidia-broken.so.0"
  local lib64="${lib64_dir}/libGLX_nvidia.so.595.84"
  local spec="${case_root}/nvidia.yaml"

  mkdir -p "${lib32_dir}" "${lib64_dir}"
  printf '\177ELF\001fixture' >"${lib32}"
  printf '\177ELF\002fixture' >"${lib64}"
  ln -s "$(basename "${lib32}")" "${alias}"
  ln -s "libnvidia-missing.so" "${broken_alias}"

  export NVIDIA_CDI_PATH="${spec}"
  MOCK_LIBRARY_OUTPUT="${lib64}"$'\n'"${lib32}"$'\n'
  MOCK_DISCOVERY_STATUS=0
  MOCK_GENERATED_SPEC=valid
  generate_cdi

  assert_eq "nvidia.com/gpu" "$(jq -r '.kind' "${spec}")" "vendor kind"
  assert_eq "true" "$(jq -r --arg path "${lib32}" 'any(.containerEdits.mounts[]; .hostPath == $path and .containerPath == $path)' "${spec}")" "ELF32 mount"
  assert_eq "true" "$(jq -r --arg path "${alias}" 'any(.containerEdits.mounts[]; .hostPath == $path and .containerPath == $path)' "${spec}")" "ELF32 alias mount"
  assert_eq "false" "$(jq -r --arg path "${broken_alias}" 'any(.containerEdits.mounts[]; .hostPath == $path)' "${spec}")" "broken alias exclusion"
  assert_eq "false" "$(jq -r --arg path "${lib64}" 'any(.containerEdits.mounts[]; .hostPath == $path)' "${spec}")" "ELF64 exclusion"
  assert_eq "true" "$(jq -r --arg folder "${lib32_dir}" 'any(.containerEdits.hooks[].args[]; . == $folder)' "${spec}")" "compat32 ldconfig folder"
  assert_eq "true" "$(jq -r 'any(.containerEdits.hooks[].args[]; . == "/usr/lib64/libnvidia-allocator.so.1::/usr/lib/x86_64-linux-gnu/gbm/nvidia-drm_gbm.so")' "${spec}")" "GBM compatibility link"
}

run_no_compat_case() {
  local case_root="${TEST_ROOT}/no-compat"
  local spec="${case_root}/nvidia.yaml"

  mkdir -p "${case_root}"
  export NVIDIA_CDI_PATH="${spec}"
  MOCK_LIBRARY_OUTPUT=""
  MOCK_DISCOVERY_STATUS=0
  MOCK_GENERATED_SPEC=valid
  generate_cdi

  assert_eq "1" "$(jq -r '.containerEdits.mounts | length' "${spec}")" "native mount preservation"
  assert_eq "true" "$(jq -r 'any(.containerEdits.hooks[].args[]; . == "/usr/lib64/libnvidia-allocator.so.1::/usr/lib/x86_64-linux-gnu/gbm/nvidia-drm_gbm.so")' "${spec}")" "no-compat GBM compatibility link"
}

run_upstream_complete_case() {
  local case_root="${TEST_ROOT}/upstream-complete"
  local lib32_dir="${case_root}/usr/lib"
  local lib32="${lib32_dir}/libGLX_nvidia.so.595.84"
  local alias="${lib32_dir}/libGLX_nvidia.so.0"
  local spec="${case_root}/nvidia.yaml"

  mkdir -p "${lib32_dir}"
  printf '\177ELF\001fixture' >"${lib32}"
  ln -s "$(basename "${lib32}")" "${alias}"

  export NVIDIA_CDI_PATH="${spec}"
  MOCK_LIBRARY_OUTPUT="${lib32}"$'\n'
  MOCK_DISCOVERY_STATUS=0
  MOCK_GENERATION_STATUS=0
  MOCK_GENERATED_SPEC=valid
  MOCK_EXISTING_COMPAT_PATH="${lib32}"
  MOCK_EXISTING_COMPAT_ALIAS="${alias}"
  MOCK_EXISTING_COMPAT_FOLDER="${lib32_dir}"
  generate_cdi
  MOCK_EXISTING_COMPAT_PATH=""
  MOCK_EXISTING_COMPAT_ALIAS=""
  MOCK_EXISTING_COMPAT_FOLDER=""

  assert_eq "1" "$(jq -r --arg path "${lib32}" '[.containerEdits.mounts[] | select(.hostPath == $path)] | length' "${spec}")" "upstream ELF32 mount is not duplicated"
  assert_eq "1" "$(jq -r --arg path "${alias}" '[.containerEdits.mounts[] | select(.hostPath == $path)] | length' "${spec}")" "upstream ELF32 alias is not duplicated"
  assert_eq "1" "$(jq -r --arg folder "${lib32_dir}" '[.containerEdits.hooks[] | select(.args[0:2] == ["nvidia-cdi-hook", "update-ldcache"]) | .args[] | select(. == $folder)] | length' "${spec}")" "upstream compat32 folder is not duplicated"
}

run_preservation_case() {
  local name="$1" generated_spec="$2" discovery_status="$3"
  local generation_status="${4:-0}" library_output="${5:-}"
  local case_root="${TEST_ROOT}/${name}"
  local spec="${case_root}/nvidia.yaml"

  mkdir -p "${case_root}"
  printf '%s\n' 'stable-spec' >"${spec}"

  export NVIDIA_CDI_PATH="${spec}"
  MOCK_LIBRARY_OUTPUT="${library_output}"
  MOCK_DISCOVERY_STATUS="${discovery_status}"
  MOCK_GENERATION_STATUS="${generation_status}"
  MOCK_GENERATED_SPEC="${generated_spec}"

  if generate_cdi 2>/dev/null ; then
    echo "FAIL: ${name} unexpectedly succeeded" >&2
    exit 1
  fi

  assert_eq "stable-spec" "$(cat "${spec}")" "${name} preserves stable spec"
}

run_invalid_elf_case() {
  local case_root="${TEST_ROOT}/invalid-elf"
  local spec="${case_root}/nvidia.yaml"
  local library="${case_root}/libnvidia.so"

  mkdir -p "${case_root}"
  printf '%s\n' 'stable-spec' >"${spec}"
  printf '%s\n' 'not-an-elf-library' >"${library}"

  export NVIDIA_CDI_PATH="${spec}"
  MOCK_LIBRARY_OUTPUT="${library}"
  MOCK_DISCOVERY_STATUS=0
  MOCK_GENERATION_STATUS=0
  MOCK_GENERATED_SPEC=valid

  if generate_cdi 2>/dev/null ; then
    echo "FAIL: invalid-elf unexpectedly succeeded" >&2
    exit 1
  fi

  assert_eq "stable-spec" "$(cat "${spec}")" "invalid-elf preserves stable spec"
}

run_positive_case
run_no_compat_case
run_upstream_complete_case
run_preservation_case "discovery-failure" valid 17
run_preservation_case "generation-failure" valid 0 23
run_preservation_case "malformed-generation" '{not-json' 0
run_preservation_case "structural-generation" '[]' 0
run_preservation_case "missing-library" valid 0 0 "${TEST_ROOT}/missing/libnvidia.so"
run_invalid_elf_case

echo "generate_cdi tests passed"
