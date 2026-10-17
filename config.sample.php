<?php
/**
 * Copy this file to config.php and fill in your values.
 * On nginx with static mode, config.php sits outside the web root and is never served.
 */
return [
    'db' => [
        'host'    => '127.0.0.1',   // CloudPanel: 127.0.0.1
        'port'    => 3306,
        'name'    => 'DB_NAME',
        'user'    => 'DB_USER',
        'pass'    => 'DB_PASS',   // your own generated password; put a \ before any ' or \ in it
        'charset' => 'utf8mb4',
    ],

    // Full public address, used for canonical URLs, RSS feed and sitemap.
    'site_url' => 'https://your-domain-here.com',

    // Path the site lives under: '' at the domain root, or e.g. '/new'.
    'base_path' => '',

    // Secret admin address. Pick something hard to guess and use the same value in the
    // nginx config, e.g. '/studio-7q4mzk'. (Default '/admin'.)
    'admin_path' => '/something-secret-not-admin',

    // Static mode (recommended): every change in the CMS rebuilds plain HTML into public_dir,
    // and nginx serves only that folder. Uploads and brand fonts are stored there too.
    'static'     => true,
    'public_dir' => __DIR__ . '/public',

    // Translation (Admin → Settings → Languages & translation). Fill in the key of the service(s) you use;
    // keys stay in this file only. Empty = that service is not offered.
    //   Google Translate basic: Google Cloud console → APIs → Cloud Translation API → Credentials → API key
    //   Gemini (free tier):     https://aistudio.google.com/apikey
    //   Claude:                 https://platform.claude.com → API keys
    //   OpenAI (ChatGPT):       https://platform.openai.com/api-keys (pay as you go; set a monthly budget there)
    'translate' => [
        'google_api_key' => '',
        'gemini_api_key' => '',
        'gemini_model'   => 'gemini-3.8-flash',
        'claude_api_key' => '',
        'claude_model'   => 'claude-haiku-5-5',
        'openai_api_key' => '',
        'openai_model'   => 'gpt-6-luna',     // cheapest current OpenAI model; good for translation
    ],

    // Stay signed in on a device for this many days after a 2FA login ("Keep me signed in"). Default is 30 days. For setting up 2FA with QR code use Athenticator app like Google Authenticator, Proton Auth, Microsoft Authenticator or any other that you use. Highly recommended.
    'remember_days' => 10,

    // If use Varnish cache on your panel, otherwise skip
    // Varnish cache clearing after every change.
    // CloudPanel: the site's Varnish settings file (SITEUSER = the site's user in CloudPanel).
    'varnish' => ['cloudpanel' => '/home/SITEUSER/.varnish-cache/settings.json'],
    // Plain Varnish without CloudPanel: 'varnish' => ['url' => 'http://127.0.0.1:6081', 'host' => 'your-domain-here.com'],

    // BunnyCDN: purge the pull zone too after every change (leave out or empty to skip).
    // api_key: Bunny dashboard → Account settings → API key. pull_zone_id: the number in the pull zone's address.
    'bunny' => ['api_key' => '', 'pull_zone_id' => 0],

    // Max upload size per image, in megabytes (keep nginx client_max_body_size above this).
    'upload_max_mb' => 10,

    // Longest edge for uploaded images; larger images are scaled down (needs PHP GD). 0 = keep original.
    'image_max_px' => 2400,

    //Timezone configuration, default is Europe/Zagreb. You can find your timezone here: https://www.php.net/manual/en/timezones.php
    'timezone' => 'Europe/Zagreb',
];
