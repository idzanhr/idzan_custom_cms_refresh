<?php
// Local development: copy to config.php in the CMS folder (never upload it). SQLite instead of MySQL.
return [
    'db' => ['dsn' => 'sqlite:' . __DIR__ . '/storage/dev.sqlite'],
    'base_path' => '',
    'site_url' => 'http://127.0.0.1:8080',
    'admin_path' => '/studio-dev',
    'static' => true,
    'public_dir' => __DIR__ . '/public',
    'varnish' => null,
    'remember_days' => 10,
    'upload_max_mb' => 50,
    'image_max_px' => 2400,
    'timezone' => 'Europe/Zagreb',
    // 'translate' => ['gemini_api_key' => '', 'gemini_model' => 'gemini-3.5-flash-lite', 'openai_api_key' => ''],
];
