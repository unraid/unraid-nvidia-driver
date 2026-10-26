#!/bin/bash

set -euo pipefail

container="${1:-}"
probe_user="${PROBE_USER:-retro}"
display="${DISPLAY:-:0}"
probe="${VULKAN_PROBE:-/home/retro/.steam/steam/steamapps/common/SteamLinuxRuntime/steam-runtime/i386/usr/bin/vkcube}"

if [ -z "${container}" ]; then
  container="$(docker ps --format '{{.Names}}' | grep -m1 '^WolfSteam_' || true)"
fi

if [ -z "${container}" ]; then
  echo "No running WolfSteam container found" >&2
  exit 2
fi

probe_type="$(docker exec "${container}" file "${probe}")"
if [[ "${probe_type}" != *"ELF 32-bit"* ]]; then
  echo "Vulkan probe is not ELF32: ${probe_type}" >&2
  exit 2
fi

set +e
output="$(
  timeout 20 docker exec -u "${probe_user}" -e DISPLAY="${display}" "${container}" \
    "${probe}" --c 1 --suppress_popups 2>&1
)"
status=$?
set -e

printf '%s\n' "${output}"

selected="$(printf '%s\n' "${output}" | sed -n 's/^Selected GPU [^:]*: \([^,]*\).*/\1/p' | head -1)"
if [ -z "${selected}" ]; then
  echo "The ELF32 Vulkan probe did not report a selected GPU (exit ${status})" >&2
  exit 1
fi

if [[ "${selected,,}" != *"nvidia"* ]]; then
  echo "Expected the ELF32 Vulkan probe to select NVIDIA, got: ${selected}" >&2
  exit 1
fi

echo "ELF32 Vulkan selected NVIDIA"
