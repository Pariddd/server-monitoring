<?php

return [
  'ingest' => [
    'max_metrics_per_request' => 5000,
    'max_future_skew_seconds' => 60,      // tolak data dengan stempel waktu > 60 dtk di masa depan
    'max_age_seconds' => 86400,           // tolak data lebih tua dari 24 jam
  ],

  // Satu entri per measurement Telegraf.
  // tag_key: tag yang dipakai; tag_pattern: hanya instance yang cocok yang disimpan; store_tag: simpan nilai tag atau ''.
  'telegraf' => [
    'win_cpu' => [
      'field' => 'Percent_Processor_Time',
      'metric' => 'cpu_usage',
      'tag_key' => 'instance',
      'tag_pattern' => '/^_Total$/',
      'store_tag' => false,
    ],
    'mem' => [
      'field' => 'used_percent',
      'metric' => 'mem_used_pct',
      'tag_key' => null,
      'tag_pattern' => null,
      'store_tag' => false,
    ],
    'win_disk' => [
      'field' => 'Percent_Free_Space',
      'metric' => 'disk_free_pct',
      // hanya volume berhuruf (C:, D:). Partisi tanpa huruf seperti HarddiskVolume1 diabaikan
      // agar partisi recovery tidak memicu alert disk palsu.
      'tag_key' => 'instance',
      'tag_pattern' => '/^[A-Z]:$/i',
      'store_tag' => true,
    ],
    'win_services' => [
      'field' => 'state',
      'metric' => 'service_state',
      'tag_key' => 'service_name',
      'tag_pattern' => null,
      'store_tag' => true,
    ],
  ],
];
