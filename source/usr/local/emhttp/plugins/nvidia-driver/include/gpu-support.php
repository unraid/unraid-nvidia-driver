<?php
require_once '/usr/local/emhttp/plugins/nvidia-driver/include/card-support.php';

header('Content-Type: application/json; charset=utf-8');

function nvidia_normalize_architecture_mapping($data, $default) {
  if (!is_array($data)) {
    return $default;
  }

  if (!isset($data['chipPrefixToArchitecture']) || !is_array($data['chipPrefixToArchitecture'])) {
    $data['chipPrefixToArchitecture'] = $default['chipPrefixToArchitecture'];
  }

  if (!isset($data['kernelModuleSupport']) || !is_array($data['kernelModuleSupport'])) {
    $data['kernelModuleSupport'] = $default['kernelModuleSupport'];
  }

  return $data;
}

function nvidia_load_architecture_mapping($file_path, $remote_url = null, $remote_cache_ttl = 21600) {
  $default = array(
    'chipPrefixToArchitecture' => array(
      'GK' => 'Kepler',
      'GM' => 'Maxwell',
      'GP' => 'Pascal',
      'GV' => 'Volta',
      'TU' => 'Turing',
      'GA' => 'Ampere',
      'AD' => 'Ada',
      'GH' => 'Hopper',
      'GB' => 'Blackwell'
    ),
    'kernelModuleSupport' => array(
      'proprietary-only' => array('Kepler', 'Maxwell', 'Pascal', 'Volta'),
      'both' => array('Turing', 'Ampere', 'Ada', 'Hopper'),
      'open-only' => array('Blackwell')
    )
  );

  // Try remote mapping first with a lightweight cache.
  if (!empty($remote_url)) {
    $cache_file = '/tmp/nvidia-driver-architecture-mapping.json';
    $cache_time_file = '/tmp/nvidia-driver-architecture-mapping.ts';
    $use_cached = false;

    if (is_file($cache_file) && is_file($cache_time_file)) {
      $last_refresh = (int)@file_get_contents($cache_time_file);
      if ($last_refresh > 0 && (time() - $last_refresh) < (int)$remote_cache_ttl) {
        $use_cached = true;
      }
    }

    if ($use_cached) {
      $cached_json = @file_get_contents($cache_file);
      $cached_data = json_decode((string)$cached_json, true);
      $normalized_cached = nvidia_normalize_architecture_mapping($cached_data, $default);
      if (is_array($normalized_cached) && !empty($normalized_cached)) {
        return $normalized_cached;
      }
    }

    $context = stream_context_create(array(
      'http' => array(
        'method' => 'GET',
        'timeout' => 5,
        'header' => "User-Agent: unraid-nvidia-driver/1.0\r\n"
      )
    ));

    $remote_json = @file_get_contents($remote_url, false, $context);
    if ($remote_json !== false && trim($remote_json) !== '') {
      $remote_data = json_decode($remote_json, true);
      $normalized_remote = nvidia_normalize_architecture_mapping($remote_data, $default);
      if (is_array($normalized_remote) && !empty($normalized_remote)) {
        @file_put_contents($cache_file, json_encode($normalized_remote));
        @file_put_contents($cache_time_file, (string)time());
        return $normalized_remote;
      }
    }
  }

  $json = @file_get_contents($file_path);
  if ($json === false || trim($json) === '') {
    return $default;
  }

  $data = json_decode($json, true);
  return nvidia_normalize_architecture_mapping($data, $default);
}

function nvidia_architecture_from_chip_codename($chip_codename, $chip_prefix_to_architecture) {
  $chip = strtoupper(trim((string)$chip_codename));
  if ($chip === '') {
    return null;
  }

  if (!is_array($chip_prefix_to_architecture)) {
    return null;
  }

  foreach ($chip_prefix_to_architecture as $prefix => $architecture) {
    $prefix_upper = strtoupper(trim((string)$prefix));
    if ($prefix_upper === '') {
      continue;
    }

    if (strpos($chip, $prefix_upper) === 0) {
      return (string)$architecture;
    }
  }

  return null;
}

function nvidia_kernel_module_support_mode($architecture, $kernel_support_mapping) {
  $arch = trim((string)$architecture);
  if ($arch === '') {
    return 'unknown';
  }

  if (!is_array($kernel_support_mapping)) {
    return 'unknown';
  }

  foreach ($kernel_support_mapping as $mode => $architectures) {
    if (!is_array($architectures)) {
      continue;
    }

    foreach ($architectures as $mapped_arch) {
      if (strcasecmp((string)$mapped_arch, $arch) === 0) {
        return (string)$mode;
      }
    }
  }

  return 'unknown';
}

$architecture_mapping = nvidia_load_architecture_mapping(
  '/usr/local/emhttp/plugins/nvidia-driver/architecture-mapping.json',
  'https://github.com/unraid/unraid-nvidia-driver/raw/master/architecture-mapping.json'
);
$chip_prefix_to_architecture = $architecture_mapping['chipPrefixToArchitecture'];
$kernel_support_mapping = $architecture_mapping['kernelModuleSupport'];

$latest_v = trim((string)shell_exec('/usr/local/emhttp/plugins/nvidia-driver/include/exec.sh get_latest_version'));
$latest_prb_v = trim((string)shell_exec('/usr/local/emhttp/plugins/nvidia-driver/include/exec.sh get_prb'));
$latest_nfb_v = trim((string)shell_exec('/usr/local/emhttp/plugins/nvidia-driver/include/exec.sh get_nfb'));
$latest_nos_v = trim((string)shell_exec('/usr/local/emhttp/plugins/nvidia-driver/include/exec.sh get_nos'));

$eachlines = @file('/tmp/nvidia_driver', FILE_IGNORE_NEW_LINES);
if (!is_array($eachlines)) {
  $eachlines = array();
}

$available_versions = array_values(array_filter(array_map('trim', $eachlines), 'strlen'));
$candidate_versions = array();

foreach (array($latest_v, $latest_prb_v, $latest_nfb_v, $latest_nos_v) as $v) {
  if ($v !== '') {
    $candidate_versions[] = $v;
  }
}

// Add one representative (highest) version per major branch available in plugin metadata.
$branch_buckets = array();
foreach ($available_versions as $version) {
  $normalized = nvidia_extract_version($version);
  $major = nvidia_driver_major($normalized);
  if ($normalized === null || $major === null) {
    continue;
  }

  if (!isset($branch_buckets[$major]) || version_compare($normalized, $branch_buckets[$major], '>')) {
    $branch_buckets[$major] = $normalized;
  }
}

foreach ($branch_buckets as $branch_head) {
  $candidate_versions[] = $branch_head;
}

$candidate_versions = array_values(array_unique($candidate_versions));
$open_recommended = nvidia_extract_version($latest_nos_v);
$production_recommended = nvidia_extract_version($latest_prb_v);

$lspci_metadata_by_device = array();
$gpu_entries = array();

$lspci_query = shell_exec('lspci -nn 2>/dev/null');
if (is_file('/tmp/lspci_output.txt')) {
  $cached_lspci = @file_get_contents('/tmp/lspci_output.txt');
  if ($cached_lspci !== false && trim($cached_lspci) !== '') {
    $lspci_query = $cached_lspci;
  }
}
if (!empty($lspci_query)) {
  $lspci_lines = preg_split('/\r\n|\r|\n/', trim($lspci_query));
  foreach ($lspci_lines as $line) {
    if (!preg_match('/(VGA compatible controller|3D controller|Display controller)/i', $line)) {
      continue;
    }

    if (stripos($line, 'NVIDIA') === false) {
      continue;
    }

    if (!preg_match('/\[10de:([0-9a-f]{4})\]/i', $line, $id_match)) {
      continue;
    }

    $slot = trim((string)strtok($line, ' '));
    $device_id = strtolower($id_match[1]);

    $chip_codename = null;
    if (preg_match('/NVIDIA\s+Corporation\s+([A-Z]{2}[0-9]{2,3}[A-Z]{0,4})\b/i', $line, $chip_match)) {
      $chip_codename = strtoupper($chip_match[1]);
    }

    $architecture = nvidia_architecture_from_chip_codename($chip_codename, $chip_prefix_to_architecture);
    $module_support = nvidia_kernel_module_support_mode($architecture, $kernel_support_mapping);

    $display_name = preg_replace('/^\S+\s+/', '', $line);
    $display_name = preg_replace('/\s*\[[0-9a-f]{4}:[0-9a-f]{4}\].*$/i', '', (string)$display_name);
    $display_name = trim((string)$display_name);

    $meta = array(
      'gpu_index' => $slot,
      'gpu_name' => $display_name,
      'gpu_input_device_id' => '10de:' . $device_id,
      'chip_codename' => $chip_codename,
      'architecture' => $architecture,
      'kernel_module_support' => $module_support,
      'detection_source' => 'lspci'
    );

    if (!isset($lspci_metadata_by_device[$device_id])) {
      $lspci_metadata_by_device[$device_id] = $meta;
    }

    $gpu_entries[] = $meta;
  }
}

// Primary detection path.
$gpu_query = shell_exec('nvidia-smi --query-gpu=index,name,pci.device_id --format=csv,noheader,nounits 2>/dev/null');
if (!empty($gpu_query)) {
  $nvidia_smi_entries = array();
  $gpu_lines = preg_split('/\r\n|\r|\n/', trim($gpu_query));
  foreach ($gpu_lines as $line) {
    if (trim($line) === '') {
      continue;
    }

    $parts = array_map('trim', explode(',', $line));
    if (count($parts) < 3) {
      continue;
    }

    $normalized_device_id = nvidia_normalize_device_id($parts[2]);
    $lspci_meta = ($normalized_device_id !== null && isset($lspci_metadata_by_device[$normalized_device_id]))
      ? $lspci_metadata_by_device[$normalized_device_id]
      : array();

    $nvidia_smi_entries[] = array(
      'gpu_index' => $parts[0],
      'gpu_name' => $parts[1],
      'gpu_input_device_id' => $parts[2],
      'chip_codename' => isset($lspci_meta['chip_codename']) ? $lspci_meta['chip_codename'] : null,
      'architecture' => isset($lspci_meta['architecture']) ? $lspci_meta['architecture'] : null,
      'kernel_module_support' => isset($lspci_meta['kernel_module_support']) ? $lspci_meta['kernel_module_support'] : 'unknown',
      'detection_source' => 'nvidia-smi'
    );
  }

  // Keep lspci fallback if nvidia-smi produced output but no valid GPU rows.
  if (!empty($nvidia_smi_entries)) {
    $gpu_entries = $nvidia_smi_entries;
  }
}

$gpu_support_rows = array();
if (!empty($gpu_entries)) {
  foreach ($gpu_entries as $entry) {
    $support = nvidia_valid_drivers_for_device_id_from_nvidia($entry['gpu_input_device_id'], $candidate_versions);
    $support['recommended_driver'] = $support['best_driver'];
    $support['recommendation_source'] = 'best-available';

    if (
      isset($entry['kernel_module_support']) &&
      $entry['kernel_module_support'] !== 'proprietary-only' &&
      !empty($open_recommended) &&
      !empty($support['valid_drivers']) &&
      in_array($open_recommended, $support['valid_drivers'], true)
    ) {
      $support['recommended_driver'] = $open_recommended;
      $support['recommendation_source'] = 'open-source';
    } elseif (
      !empty($production_recommended) &&
      !empty($support['valid_drivers']) &&
      in_array($production_recommended, $support['valid_drivers'], true)
    ) {
      $support['recommended_driver'] = $production_recommended;
      $support['recommendation_source'] = 'production-branch';
    }

    $support['gpu_index'] = $entry['gpu_index'];
    $support['gpu_name'] = $entry['gpu_name'];
    $support['gpu_input_device_id'] = $entry['gpu_input_device_id'];
    $support['chip_codename'] = isset($entry['chip_codename']) ? $entry['chip_codename'] : null;
    $support['architecture'] = isset($entry['architecture']) ? $entry['architecture'] : null;
    $support['kernel_module_support'] = isset($entry['kernel_module_support']) ? $entry['kernel_module_support'] : 'unknown';
    $support['detection_source'] = $entry['detection_source'];
    $gpu_support_rows[] = $support;
  }
}

echo json_encode(array(
  'ok' => true,
  'rows' => $gpu_support_rows
));
