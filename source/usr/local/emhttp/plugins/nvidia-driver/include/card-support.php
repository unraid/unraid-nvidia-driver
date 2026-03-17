<?php

/**
 * Extract NVIDIA driver version from mixed input formats.
 *
 * Supported examples:
 * - 580.126.18
 * - 390.157
 * - 340.108
 * - nvidia-470.256.02-6.12.17-Unraid-1.txz
 * - v590.48.01
 */
function nvidia_extract_version($value) {
  $text = trim((string)$value);
  if ($text === '') {
    return null;
  }

  if (preg_match('/\bv?(\d{3}\.\d{1,3}(?:\.\d{1,3})?)\b/i', $text, $match)) {
    return $match[1];
  }

  return null;
}

/**
 * Parse the numeric major branch (e.g. 580 from 580.126.18).
 * Returns null for non-version inputs.
 */
function nvidia_driver_major($driver_version) {
  $normalized = nvidia_extract_version($driver_version);
  if ($normalized === null) {
    return null;
  }

  if (preg_match('/^(\d+)\./', $normalized, $match)) {
    return (int)$match[1];
  }

  return null;
}

/**
 * Return the highest version in a branch from a list of full versions.
 * Example: nvidia_best_version_for_branch(['580.126.18','470.256.02'],470) => 470.256.02
 */
function nvidia_best_version_for_branch($available_versions, $branch_major) {
  if (!is_array($available_versions) || empty($available_versions)) {
    return null;
  }

  $branch_versions = array();
  foreach ($available_versions as $version) {
    $normalized = nvidia_extract_version($version);
    if ($normalized !== null && nvidia_driver_major($normalized) === (int)$branch_major) {
      $branch_versions[] = $normalized;
    }
  }

  if (empty($branch_versions)) {
    return null;
  }

  usort($branch_versions, 'version_compare');
  return end($branch_versions);
}

/**
 * Return the latest version from current (non-legacy) branches.
 * Legacy branches can be adjusted when policies change.
 */
function nvidia_latest_current_driver($available_versions, $legacy_majors = array(470, 390, 340)) {
  if (!is_array($available_versions) || empty($available_versions)) {
    return null;
  }

  $current_versions = array();
  foreach ($available_versions as $version) {
    $normalized = nvidia_extract_version($version);
    $major = nvidia_driver_major($normalized);
    if ($major === null) {
      continue;
    }

    if (!in_array($major, $legacy_majors, true)) {
      $current_versions[] = $normalized;
    }
  }

  if (empty($current_versions)) {
    return null;
  }

  usort($current_versions, 'version_compare');
  return end($current_versions);
}

/**
 * Detect the minimal required NVIDIA branch for a card based on known families.
 * This is heuristic (name-based), intended for UI warning/suggestion logic.
 *
 * Returns one of:
 * - "current" (modern drivers, e.g. 5xx/58x)
 * - "470"
 * - "390"
 * - "340"
 * - "unknown"
 */
function nvidia_required_branch_for_card($card_name) {
  $name = strtolower(trim((string)$card_name));
  if ($name === '') {
    return 'unknown';
  }

  // Kepler-era cards generally require legacy 470.xx today.
  $branch_470_patterns = array(
    '/\bkepler\b/',
    '/\bgk\d{3}\b/',
    '/\bgtx\s*6[5-9]0\b/',
    '/\bgtx\s*7\d{2}\b/',
    '/\bgt\s*7\d{2}\b/',
    '/\bquadro\s*k\d+/','/\btesla\s*k\d+/'
  );

  // Fermi-era cards generally require legacy 390.xx.
  $branch_390_patterns = array(
    '/\bfermi\b/',
    '/\bgf\d{3}\b/',
    '/\bgtx\s*[45]\d{2}\b/',
    '/\bgt\s*[45]\d{2}\b/',
    '/\bquadro\s*[2456]000\b/',
    '/\btesla\s*(c2050|c2070|m20[57]0)\b/'
  );

  // Tesla/G80-G200 era cards generally require legacy 340.xx.
  $branch_340_patterns = array(
    '/\btesla\s*(c870|s870|d870|c1060|s1070|m1060)\b/',
    '/\bg8\d{2}\b/',
    '/\bg9\d{2}\b/',
    '/\bgt2\d{2}\b/',
    '/\bgeforce\s*[89]\d{3}\b/',
    '/\bgeforce\s*[123]\d{2}\b/',
    '/\bquadro\s*fx\b/',
    '/\bion\b/'
  );

  foreach ($branch_340_patterns as $pattern) {
    if (preg_match($pattern, $name)) {
      return '340';
    }
  }

  foreach ($branch_390_patterns as $pattern) {
    if (preg_match($pattern, $name)) {
      return '390';
    }
  }

  foreach ($branch_470_patterns as $pattern) {
    if (preg_match($pattern, $name)) {
      return '470';
    }
  }

  // Default to current branch for modern cards (Maxwell+ and newer).
  return 'current';
}

/**
 * Normalize device ID values.
 *
 * Accepted examples:
 * - "1fb2"
 * - "0x1FB2"
 * - "10de:1fb2" (vendor:device)
 * - "0000:01:00.0 10de:1fb2" (lspci -n style fragment)
 *
 * Returns a 4-hex PCI device ID, or null if input does not contain one.
 */
function nvidia_normalize_device_id($device_id) {
  $raw = strtolower(trim((string)$device_id));
  if ($raw === '') {
    return null;
  }

  // Prefer explicit vendor:device format if present.
  if (preg_match('/\b(?:0x)?([0-9a-f]{4})\s*:\s*(?:0x)?([0-9a-f]{4})\b/', $raw, $match)) {
    $vendor = $match[1];
    $device = $match[2];

    // If the vendor is NVIDIA (10de), the second field is the device ID we need.
    if ($vendor === '10de') {
      return $device;
    }

    // For non-NVIDIA vendor:device tuples, still return the device part.
    return $device;
  }

  $id = preg_replace('/^0x/', '', $raw);
  $id = preg_replace('/[^0-9a-f]/', '', $id);

  if ($id === '') {
    return null;
  }

  // Handle packed device+vendor style (e.g. 1fb210de from nvidia-smi).
  if (preg_match('/^[0-9a-f]{8}$/', $id) && substr($id, -4) === '10de') {
    return substr($id, 0, 4);
  }

  // 10de alone is NVIDIA's vendor ID, not a GPU device ID.
  if ($id === '10de') {
    return null;
  }

  return str_pad(substr($id, -4), 4, '0', STR_PAD_LEFT);
}

/**
 * Built-in branch overrides for known device IDs.
 * Intentionally empty: do not force device IDs into branches when NVIDIA
 * supportedchips data does not list them.
 */
function nvidia_builtin_device_branch_map() {
  return array();
}

/**
 * Resolve required branch for a GPU device ID using a supplied map.
 *
 * Expected map format:
 * array(
 *   '10fa' => '470',
 *   '1db6' => 'current',
 *   '06c0' => '340'
 * )
 */
function nvidia_required_branch_for_device_id($device_id, $device_branch_map = array()) {
  $normalized = nvidia_normalize_device_id($device_id);
  if ($normalized === null) {
    return 'unknown';
  }

  $resolved_map = array_merge(nvidia_builtin_device_branch_map(), is_array($device_branch_map) ? $device_branch_map : array());

  if (!array_key_exists($normalized, $resolved_map)) {
    return 'unknown';
  }

  $branch = strtolower(trim((string)$resolved_map[$normalized]));
  if ($branch === '470' || $branch === '390' || $branch === '340' || $branch === 'current') {
    return $branch;
  }

  return 'unknown';
}

/**
 * Return valid driver versions for a GPU device ID.
 *
 * For legacy IDs, returns the versions in the matching legacy branch.
 * For current IDs, returns all non-legacy versions.
 */
function nvidia_valid_drivers_for_device_id($device_id, $available_versions, $device_branch_map = array(), $current_branch_major = 580) {
  $required_branch = nvidia_required_branch_for_device_id($device_id, $device_branch_map);
  $normalized = nvidia_normalize_device_id($device_id);

  $valid_versions = array();
  if (!is_array($available_versions)) {
    $available_versions = array();
  }

  if ($required_branch === '470' || $required_branch === '390' || $required_branch === '340') {
    $target_major = (int)$required_branch;
    foreach ($available_versions as $version) {
      $normalized_version = nvidia_extract_version($version);
      if ($normalized_version !== null && nvidia_driver_major($normalized_version) === $target_major) {
        $valid_versions[] = $normalized_version;
      }
    }
  } elseif ($required_branch === 'current') {
    foreach ($available_versions as $version) {
      $normalized_version = nvidia_extract_version($version);
      $major = nvidia_driver_major($normalized_version);
      if ($major !== null && $major !== 470 && $major !== 390 && $major !== 340) {
        $valid_versions[] = $normalized_version;
      }
    }

    // If no explicit current list exists, use the configured current major branch fallback.
    if (empty($valid_versions)) {
      foreach ($available_versions as $version) {
        $normalized_version = nvidia_extract_version($version);
        if ($normalized_version !== null && nvidia_driver_major($normalized_version) === (int)$current_branch_major) {
          $valid_versions[] = $normalized_version;
        }
      }
    }
  }

  $valid_versions = array_values(array_unique($valid_versions));
  usort($valid_versions, 'version_compare');

  return array(
    'device_id' => $normalized,
    'required_branch' => $required_branch,
    'valid_drivers' => $valid_versions,
    'best_driver' => empty($valid_versions) ? null : end($valid_versions)
  );
}

/**
 * Check a single NVIDIA driver version and return detailed diagnostics.
 */
function nvidia_driver_device_id_check_from_nvidia($driver_version, $device_id, $fetch_timeout = 8) {
  static $supportedchips_cache = array();

  $normalized_id = strtoupper((string)nvidia_normalize_device_id($device_id));
  $normalized_version = (string)nvidia_extract_version($driver_version);

  if ($normalized_id === '' || $normalized_version === '') {
    return array(
      'driver_version' => $normalized_version === '' ? null : $normalized_version,
      'device_id' => $normalized_id === '' ? null : strtolower($normalized_id),
      'url' => null,
      'fetch_ok' => false,
      'supported' => false,
      'error' => 'invalid-input'
    );
  }

  $url = 'https://download.nvidia.com/XFree86/Linux-x86_64/' . rawurlencode($normalized_version) . '/README/supportedchips.html';

  $context = stream_context_create(array(
    'http' => array(
      'method' => 'GET',
      'timeout' => (int)$fetch_timeout,
      'header' => "User-Agent: unraid-nvidia-driver/1.0\r\n"
    )
  ));

  if (array_key_exists($normalized_version, $supportedchips_cache)) {
    $html = $supportedchips_cache[$normalized_version];
  } else {
    $html = @file_get_contents($url, false, $context);
    $supportedchips_cache[$normalized_version] = ($html === false || $html === '') ? false : $html;
  }

  if ($html === false || $html === '') {
    return array(
      'driver_version' => $normalized_version,
      'device_id' => strtolower($normalized_id),
      'url' => $url,
      'fetch_ok' => false,
      'supported' => false,
      'error' => 'fetch-failed'
    );
  }

  // Ignore legacy appendix sections on newer pages to avoid false positives
  // (e.g. cards listed only under older legacy branches).
  $primary_html = preg_split('/Below\s+are\s+the\s+legacy\s+GPUs/i', $html, 2);
  $search_html = is_array($primary_html) ? $primary_html[0] : $html;

  // Match only the first PCI field in the table cell (device ID), not subsystem IDs.
  // Works for both formats used by NVIDIA pages:
  // - 1FB2 1028 1489
  // - 0x1FB2 0x1028 0x1489
  $primary_id_pattern = '/<td>\s*(?:0x)?' . preg_quote($normalized_id, '/') . '(?:\s+(?:0x)?[0-9a-f]{4}){0,2}\s*<\/td>/i';
  $is_supported = (bool)preg_match($primary_id_pattern, $search_html);

  return array(
    'driver_version' => $normalized_version,
    'device_id' => strtolower($normalized_id),
    'url' => $url,
    'fetch_ok' => true,
    'supported' => $is_supported,
    'error' => null
  );
}

/**
 * Check a single NVIDIA driver version directly against NVIDIA supportedchips data.
 */
function nvidia_driver_supports_device_id_from_nvidia($driver_version, $device_id, $fetch_timeout = 8) {
  $check = nvidia_driver_device_id_check_from_nvidia($driver_version, $device_id, $fetch_timeout);
  return !empty($check['supported']);
}

/**
 * Query NVIDIA directly to get valid drivers for a PCI device ID.
 */
function nvidia_valid_drivers_for_device_id_from_nvidia($device_id, $candidate_versions, $fetch_timeout = 8) {
  $normalized_id = nvidia_normalize_device_id($device_id);
  $valid_versions = array();
  $checked_versions = array();

  if ($normalized_id === null) {
    return array(
      'device_id' => null,
      'valid_drivers' => array(),
      'best_driver' => null,
      'source' => 'nvidia-supportedchips'
    );
  }

  if (!is_array($candidate_versions)) {
    $candidate_versions = array();
  }

  foreach ($candidate_versions as $version) {
    $version = nvidia_extract_version($version);
    if ($version === null) {
      continue;
    }

    $check = nvidia_driver_device_id_check_from_nvidia($version, $normalized_id, $fetch_timeout);
    $checked_versions[] = $check;

    if (!empty($check['supported'])) {
      $valid_versions[] = $version;
    }
  }

  $valid_versions = array_values(array_unique($valid_versions));
  usort($valid_versions, 'version_compare');

  $fetch_ok_count = 0;
  foreach ($checked_versions as $check) {
    if (!empty($check['fetch_ok'])) {
      $fetch_ok_count++;
    }
  }

  $no_match_reason = null;
  if (empty($valid_versions)) {
    $no_match_reason = ($fetch_ok_count === 0)
      ? 'no-supportedchips-pages-fetched'
      : 'device-id-not-listed-in-checked-versions';
  }

  return array(
    'device_id' => $normalized_id,
    'valid_drivers' => $valid_versions,
    'best_driver' => empty($valid_versions) ? null : end($valid_versions),
    'source' => 'nvidia-supportedchips',
    'checked_versions' => $checked_versions,
    'checked_count' => count($checked_versions),
    'fetch_ok_count' => $fetch_ok_count,
    'no_match_reason' => $no_match_reason
  );
}

/**
 * Determine if a selected driver supports a card and suggest the best available driver.
 *
 * @param string $card_name            User/system detected card name.
 * @param string $selected_driver      Selected driver version string (e.g. 580.126.18).
 * @param array  $available_versions   Available driver versions (e.g. ['580.126.18','470.256.02']).
 * @param int    $current_branch_major Modern branch major to treat as "current" (default: 580).
 *
 * @return array
 */
function nvidia_card_driver_support($card_name, $selected_driver, $available_versions = array(), $current_branch_major = 580) {
  $required_branch = nvidia_required_branch_for_card($card_name);
  $selected_major = nvidia_driver_major($selected_driver);

  $latest_current_driver = nvidia_latest_current_driver($available_versions);
  $latest_current_major = nvidia_driver_major((string)$latest_current_driver);

  $required_major = null;
  if ($required_branch === '470' || $required_branch === '390' || $required_branch === '340') {
    $required_major = (int)$required_branch;
  } elseif ($required_branch === 'current') {
    // Prefer detected current latest major from available versions.
    $required_major = ($latest_current_major !== null) ? $latest_current_major : (int)$current_branch_major;
  }

  $is_supported = true;

  if ($required_branch === '470' || $required_branch === '390' || $required_branch === '340') {
    // Legacy generations are branch-specific (470 vs 390 vs 340).
    $is_supported = ($selected_major !== null && $selected_major === $required_major);
  } elseif ($required_branch === 'current') {
    // Modern cards should use the current branch family, not legacy branches.
    $is_supported = ($selected_major !== null && $selected_major >= $required_major);
  }

  $recommended_driver = null;
  $warning = null;

  if ($required_branch === 'current') {
    $recommended_driver = $latest_current_driver;
    if ($recommended_driver === null && $selected_major !== null && $selected_major >= (int)$current_branch_major) {
      $recommended_driver = $selected_driver;
    }
  } elseif ($required_major !== null) {
    // Legacy generations need their matching legacy branch.
    $recommended_driver = nvidia_best_version_for_branch($available_versions, $required_major);
  }

  if (!$is_supported) {
    if ($recommended_driver !== null) {
      $warning = sprintf(
        'Selected driver %s does not support %s. Recommended driver: %s.',
        $selected_driver,
        $card_name,
        $recommended_driver
      );
    } else {
      $warning = sprintf(
        'Selected driver %s may not support %s, and no compatible legacy branch (470/390/340) is currently available.',
        $selected_driver,
        $card_name
      );
    }
  }

  return array(
    'card_name' => $card_name,
    'required_branch' => $required_branch,
    'required_major' => $required_major,
    'latest_current_driver' => $latest_current_driver,
    'latest_current_major' => $latest_current_major,
    'selected_driver' => $selected_driver,
    'selected_major' => $selected_major,
    'is_supported' => $is_supported,
    'recommended_driver' => $recommended_driver,
    'warning' => $warning
  );
}
