#!/bin/bash

function update(){
KERNEL_V="$(uname -r)"
PACKAGE="nvidia"
CURENTTIME=$(date +%s)
CHK_TIMEOUT=300
FETCH_VERSIONS() {
  DRIVERS="$(wget -qO- https://api.github.com/repos/unraid/unraid-nvidia-driver/releases/tags/${KERNEL_V} | jq -r '.assets[].name' | grep -E -v '\.md5$' | sort -V)"
  echo -n "$(grep ${PACKAGE} <<< "$DRIVERS" | awk -F "-" '{print $2}' | sort -V | uniq)" > /tmp/nvidia_driver
  echo -n "$(grep nvos <<< "$DRIVERS" | awk -F "-" '{print $2}' | sort -V | uniq)" > /tmp/nvos_driver
  if [ ! -s /tmp/nvidia_driver ]; then
    echo -n "$(modinfo nvidia | grep "version:" | awk '{print $2}' | head -1)" > /tmp/nvidia_driver
  fi
}
if [ -f /tmp/nvidia_driver ]; then
  FILETIME=$(stat /tmp/nvidia_driver -c %Y)
  DIFF=$(expr $CURENTTIME - $FILETIME)
  if [ $DIFF -gt $CHK_TIMEOUT ]; then
    FETCH_VERSIONS
  fi
else
  FETCH_VERSIONS
fi
# FIX: Read available versions from cache file instead of $DRIVERS variable.
# $DRIVERS is only populated inside FETCH_VERSIONS() which may not run
# if the cache is still fresh. Reading from the file ensures the 580
# legacy driver check works regardless of whether the cache was refreshed.
if ! grep -q "580" /tmp/nvidia_driver 2>/dev/null; then
  LEGACY_DRIVER="$(grep "580" /tmp/nvos_driver 2>/dev/null | head -1)"
  if [ -n "${LEGACY_DRIVER}" ]; then
    sed -i "1s/^/${LEGACY_DRIVER}\n/" /tmp/nvidia_driver
  fi
fi
if [ -f /tmp/nvidia_branches ]; then
  FILETIME=$(stat /tmp/nvidia_branches -c %Y)
  DIFF=$(expr $CURENTTIME - $FILETIME)
  if [ $DIFF -gt $CHK_TIMEOUT ]; then
    echo -n "$(wget -q -N -O /tmp/nvidia_branches https://raw.githubusercontent.com/unraid/unraid-nvidia-driver/master/versions.json)"
    if [ ! -s /tmp/nvidia_branches ]; then
      rm -rf /tmp/nvidia_branches
    fi
  fi
else
  echo -n "$(wget -q -N -O /tmp/nvidia_branches https://raw.githubusercontent.com/unraid/unraid-nvidia-driver/master/versions.json)"
fi
}

function update_version(){
# SEC: Validate input to prevent command injection via sed.
# exec.sh is called with $@ (line 119), so any string passed from the web UI
# ends up as ${1} here. Without validation, a crafted version string like
# "1.0; rm -rf /" would execute arbitrary commands through sed.
if [[ ! "${1}" =~ ^[a-zA-Z0-9._]+$ ]]; then
  echo "ERROR: Invalid version string"
  exit 1
fi
sed -i "/driver_version=/c\driver_version=${1}" "/boot/config/plugins/nvidia-driver/settings.cfg"
if [[ "${1}" != "latest" && "${1}" != "latest_prb" && "${1}" != "latest_nfb" && "${1}" != "latest_beta" ]]; then
  sed -i "/update_check=/c\update_check=false" "/boot/config/plugins/nvidia-driver/settings.cfg"
  echo -n "$(crontab -l | grep -v '/usr/local/emhttp/plugins/nvidia-driver/include/update-check.sh &>/dev/null 2>&1'  | crontab -)"
fi
/usr/local/emhttp/plugins/nvidia-driver/include/download.sh
}

function get_latest_version(){
KERNEL_V="$(uname -r)"
echo -n "$(cat /tmp/nvidia_driver | tail -1)"
}

function get_prb(){
echo -n "$(comm -12 <(cat /tmp/nvidia_driver | awk -F '.' '{printf "%d.%03d.%d\n", $1,$2,$3}' | awk -F '.' '{printf "%d.%03d.%02d\n", $1,$2,$3}') <(echo "$(cat /tmp/nvidia_branches | jq -r '.branches.production[]' | sort -V | awk -F '.' '{printf "%d.%03d.%d\n", $1,$2,$3}' | awk -F '.' '{printf "%d.%03d.%02d\n", $1,$2,$3}')") | tail -1 | awk -F '.' '{printf "%d.%02d.%02d\n", $1,$2,$3}' | awk '{sub(/\.0+$/,"")}1')"
}

function get_nfb(){
echo -n "$(comm -12 <(cat /tmp/nvidia_driver | awk -F '.' '{printf "%d.%03d.%d\n", $1,$2,$3}' | awk -F '.' '{printf "%d.%03d.%02d\n", $1,$2,$3}') <(echo "$(cat /tmp/nvidia_branches | jq -r '.branches.newfeature[]' | sort -V | awk -F '.' '{printf "%d.%03d.%d\n", $1,$2,$3}' | awk -F '.' '{printf "%d.%03d.%02d\n", $1,$2,$3}')") | tail -1 | awk -F '.' '{printf "%d.%02d.%02d\n", $1,$2,$3}' | awk '{sub(/\.0+$/,"")}1')"
}

function get_beta(){
echo -n "$(comm -12 <(cat /tmp/nvidia_driver | awk -F '.' '{printf "%d.%03d.%d\n", $1,$2,$3}' | awk -F '.' '{printf "%d.%03d.%02d\n", $1,$2,$3}') <(echo "$(cat /tmp/nvidia_branches | jq -r '.branches.beta.current' | sort -V | awk -F '.' '{printf "%d.%03d.%d\n", $1,$2,$3}' | awk -F '.' '{printf "%d.%03d.%02d\n", $1,$2,$3}')") | tail -1 | awk -F '.' '{printf "%d.%02d.%02d\n", $1,$2,$3}' | awk '{sub(/\.0+$/,"")}1')"
}

function get_nos(){
echo -n "$(cat /tmp/nvos_driver | sort -V | tail -1)"
}

function get_gpu_arch(){
echo -n "$(nvidia-smi --query-gpu=compute_cap --format=csv,noheader 2>/dev/null | head -1)"
}

function get_cuda_version(){
echo -n "$(nvidia-smi 2>/dev/null | grep 'CUDA Version' | grep -oE '[0-9]+\.[0-9]+' | tail -1)"
}

function get_selected_version(){
echo -n "$(cat /boot/config/plugins/nvidia-driver/settings.cfg | grep "driver_version" | cut -d '=' -f2)"
}

function get_installed_version(){
echo -n "$(modinfo nvidia | grep -w "version:" | awk '{print $2}')"
}

function get_license(){
LICENSE="$(modinfo nvidia 2>/dev/null | grep "license" | awk '{print $2}')"
if [ -z "${LICENSE}" ]; then
  echo -n "NONE"
elif [ "${LICENSE}" == "NVIDIA" ]; then
  echo -n "PROPRIETARY"
else
  echo -n "OPENSOURCE"
fi
}

function update_check(){
echo -n "$(cat /boot/config/plugins/nvidia-driver/settings.cfg | grep "update_check" | head -1 | cut -d '=' -f2)"
}

function change_update_check(){
# SEC: Whitelist boolean values to prevent command injection.
# Only "true" or "false" are valid — anything else is rejected.
if [[ "${1}" != "true" && "${1}" != "false" ]]; then
  echo "ERROR: Invalid value for update_check"
  exit 1
fi
sed -i "/update_check=/c\update_check=${1}" "/boot/config/plugins/nvidia-driver/settings.cfg"
if [ "${1}" == "true" ]; then
  if [ ! "$(crontab -l | grep "/usr/local/emhttp/plugins/nvidia-driver/include/update-check.sh")" ]; then
    echo -n "$((crontab -l ; echo ""$((0 + $RANDOM % 59))" "$(shuf -i 8-9 -n 1)" * * * /usr/local/emhttp/plugins/nvidia-driver/include/update-check.sh &>/dev/null 2>&1") | crontab -)"
  fi
elif [ "${1}" == "false" ]; then
  echo -n "$(crontab -l | grep -v '/usr/local/emhttp/plugins/nvidia-driver/include/update-check.sh &>/dev/null 2>&1'  | crontab -)"
fi

}

# SEC: Restrict callable functions to prevent arbitrary code execution.
# The web UI calls this script via shell_exec("... exec.sh function_name args").
# Without a whitelist, any bash function (or command) could be invoked.
ALLOWED_FUNCTIONS="update update_version get_latest_version get_prb get_nfb get_beta get_nos get_gpu_arch get_cuda_version get_selected_version get_installed_version get_license update_check change_update_check"
if [[ " ${ALLOWED_FUNCTIONS} " == *" ${1} "* ]]; then
  "$@"
else
  echo "ERROR: Unknown function '${1}'"
  exit 1
fi
