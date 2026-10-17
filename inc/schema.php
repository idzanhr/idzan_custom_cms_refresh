<?php
declare(strict_types=1);

/**
 * Content types managed by the CMS.
 *
 * The admin list/edit screens are generated from these definitions, so adding a new
 * section later (e.g. "Skills", "Testimonials", "Music releases") means:
 *   1. add a table in install/schema.sql (and run the CREATE TABLE on your server),
 *   2. add an entry here,
 *   3. add a template for it in inc/render.php if it should appear on the public site.
 *
 * Field types: text, textarea, richtext, slug, url, date, datetime, checkbox, select,
 *              number, image, term (one term), terms (several terms).
 */
const STATUS_OPTIONS = ['published' => 'Published', 'draft' => 'Draft'];
/** Content types listed in sitemap.xml, which keep a "last edited" date (modified_at). */
const SITEMAP_TABLES = ['posts', 'projects', 'experience', 'pages', 'affiliates'];

const TAXONOMIES = [
    'category' => ['label' => 'Blog categories', 'singular' => 'Category'],
    'service'  => ['label' => 'Services',        'singular' => 'Service'],
    'tool'     => ['label' => 'Tools',           'singular' => 'Tool'],
];

const CONTACT_ICON_OPTIONS = ['' => 'Automatic (from the address)', 'protonmail' => 'Email', 'phone' => 'Phone', 'link' => 'Generic link',
    'facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'x' => 'X / Twitter', 'threads' => 'Threads',
    'youtube' => 'YouTube', 'github' => 'GitHub', 'bluesky' => 'Bluesky', 'mastodon' => 'Mastodon', 'tiktok' => 'TikTok',
    'soundcloud' => 'SoundCloud', 'whatsapp' => 'WhatsApp', 'telegram' => 'Telegram'];

const THEME_DEFAULTS = ['dark' => 'Dark first (brand look), visitors can switch to light', 'system' => 'Follow the visitor\'s device setting'];

const ROBOTS_DEFAULT = <<<'TXT'
# robots.txt for idzan.hr: who may read this site.
# Only the bots named below are welcome; every other bot is asked to stay away (last group).
# This is a polite request: good bots follow it, the server blocks the worst ones anyway.
# The Sitemap line is added automatically. Never list the admin address here.
# Keep each group's lines together: a comment-only or empty line ends a group for some readers.

# Search engines and AI assistants
User-agent: Googlebot            # Google Search
User-agent: Google-Extended      # Google Gemini
User-agent: bingbot              # Bing and Microsoft Copilot
User-agent: DuckDuckBot          # DuckDuckGo search
User-agent: DuckAssistBot        # DuckDuckGo AI answers (DuckAssist / Duck.ai); not used for AI training
User-agent: Applebot             # Apple Spotlight / Siri search
User-agent: Applebot-Extended    # Apple Intelligence
User-agent: ClaudeBot            # Anthropic Claude (training)
User-agent: Claude-SearchBot     # Anthropic Claude (search)
User-agent: Claude-User          # Anthropic Claude (page a user asked for)
User-agent: GPTBot               # OpenAI ChatGPT (training)
User-agent: OAI-SearchBot        # OpenAI ChatGPT (search)
User-agent: ChatGPT-User         # OpenAI ChatGPT (page a user asked for)
User-agent: PerplexityBot        # Perplexity (search)
User-agent: Perplexity-User      # Perplexity (page a user asked for)
User-agent: archive.org_bot      # Internet Archive / Wayback Machine (archived copies of pages)
User-agent: ia_archiver          # Internet Archive, older name
Allow: /

# Link previews when a page is shared (the share buttons on posts)
User-agent: facebookexternalhit  # Facebook, Instagram, Threads, Messenger
User-agent: facebookcatalog      # Facebook / Instagram
User-agent: Twitterbot           # X
User-agent: LinkedInBot          # LinkedIn
User-agent: WhatsApp             # WhatsApp
User-agent: TelegramBot          # Telegram
User-agent: Mastodon             # Mastodon (also checks the rel="me" link)
User-agent: Discordbot           # Discord
User-agent: Slackbot             # Slack
Allow: /

# Every other bot: AI training and scraping (TikTok/Bytespider, Common Crawl, Meta AI, Amazon...),
# SEO tools, and other search engines (Yandex, Baidu, ...)
User-agent: *
Disallow: /
TXT;
/** md5 of earlier built-in defaults: a saved copy of one of those is treated as "use the current default". */
const ROBOTS_OLD_DEFAULTS = ['f66131c80fb3cdea2494fe882f3e8c53', '3bd2cc1dea03d7e361860c539ab725e7', 'c01969cf7221a0e871cae7997e9d1d38'];

const WORK_TYPES = ['onsite' => 'On-site', 'hybrid' => 'Hybrid', 'remote' => 'Remote'];

const AFFILIATE_KINDS = ['affiliate' => 'Affiliate', 'referral' => 'Referral', 'sponsored' => 'Sponsored'];

const PROMO_OPTIONS = ['no' => 'NO CODE', 'yes' => 'Has a promo code'];

function entities(): array
{
    return [
        'posts' => [
            'label' => 'Blog posts', 'singular' => 'Blog post', 'icon' => '✎',
            'order' => 'published_at DESC, id DESC',
            'list'  => ['title' => 'Title', 'category_id' => 'Category', 'status' => 'Status', 'published_at' => 'Published'],
            'fields' => [
                'title'          => ['type' => 'text', 'label' => 'Title', 'required' => true],
                'slug'           => ['type' => 'slug', 'label' => 'URL slug', 'from' => 'title', 'help' => 'Post address: /your-slug/ (same as on WordPress). Leave empty to generate from the title.'],
                'category_id'    => ['type' => 'term', 'taxonomy' => 'category', 'label' => 'Main category', 'help' => 'Shown on the blog cards.'],
                'more_categories' => ['type' => 'terms', 'taxonomy' => 'category', 'label' => 'Also in categories', 'help' => 'Optional. The post is listed under these categories too, and the post page shows all of them.'],
                'featured_image' => ['type' => 'image', 'label' => 'Featured image', 'alt' => 'featured_alt'],
                'content'        => ['type' => 'richtext', 'label' => 'Content', 'help' => 'Use the image button to place pictures inside the text.'],
                'excerpt'        => ['type' => 'textarea', 'label' => 'Excerpt', 'rows' => 3, 'help' => 'Optional. Shown on the blog list; generated from the content when empty.'],
                'status'         => ['type' => 'select', 'label' => 'Status', 'options' => STATUS_OPTIONS, 'default' => 'draft', 'side' => true],
                'published_at'   => ['type' => 'datetime', 'label' => 'Publish date', 'default' => 'now', 'side' => true],
                'seo_title'       => ['type' => 'text', 'label' => 'Search title', 'side' => true, 'counter' => SEO_TITLE_MAX, 'panel' => 'Search engines (SEO)',
                                      'help' => 'The blue link in Google and Bing. Empty = the title. About 60 characters.'],
                'seo_description' => ['type' => 'textarea', 'label' => 'Search description', 'rows' => 4, 'side' => true, 'counter' => SEO_DESC_MAX,
                                      'help' => 'The text under the link in search results and in share previews. Empty = the excerpt or first lines. 70–160 characters.'],
            ],
        ],

        'projects' => [
            'label' => 'Projects', 'singular' => 'Project', 'icon' => '◧',
            'order' => 'started_on DESC, id DESC',
            'list'  => ['title' => 'Title', 'client' => 'Client', 'started_on' => 'Started', 'status' => 'Status'],
            'fields' => [
                'title'          => ['type' => 'text', 'label' => 'Project title', 'required' => true],
                'slug'           => ['type' => 'slug', 'label' => 'URL slug', 'from' => 'title', 'help' => 'Address: /project/your-slug/'],
                'client'         => ['type' => 'text', 'label' => 'Client (for whom)', 'help' => 'e.g. Savio, obrt za poduku i uslužne djelatnosti'],
                'started_on'     => ['type' => 'date', 'label' => 'Started'],
                'url'            => ['type' => 'url', 'label' => 'Project URL', 'help' => 'Opens in a new tab on the site.'],
                'services'       => ['type' => 'terms', 'taxonomy' => 'service', 'label' => 'Service provided'],
                'tools'          => ['type' => 'terms', 'taxonomy' => 'tool', 'label' => 'Tools used'],
                'featured_image' => ['type' => 'image', 'label' => 'Featured image', 'alt' => 'featured_alt'],
                'content'        => ['type' => 'richtext', 'label' => 'Project description'],
                'status'         => ['type' => 'select', 'label' => 'Status', 'options' => STATUS_OPTIONS, 'default' => 'published', 'side' => true],
                'seo_title'       => ['type' => 'text', 'label' => 'Search title', 'side' => true, 'counter' => SEO_TITLE_MAX, 'panel' => 'Search engines (SEO)',
                                      'help' => 'The blue link in Google and Bing. Empty = the title. About 60 characters.'],
                'seo_description' => ['type' => 'textarea', 'label' => 'Search description', 'rows' => 4, 'side' => true, 'counter' => SEO_DESC_MAX,
                                      'help' => 'The text under the link in search results and in share previews. Empty = the excerpt or first lines. 70–160 characters.'],
            ],
        ],

        'experience' => [
            'label' => 'Work experience', 'singular' => 'Work experience', 'icon' => '▤',
            'order' => 'ongoing DESC, COALESCE(end_date, start_date) DESC, start_date DESC, id DESC',
            'list'  => ['company' => 'Company', 'role' => 'Role', 'start_date' => 'Start', 'end_date' => 'End', 'work_type' => 'Type'],
            'title_field' => 'role',
            'fields' => [
                'company'     => ['type' => 'text', 'label' => 'Company name', 'required' => true],
                'role'        => ['type' => 'text', 'label' => 'Role in company', 'required' => true],
                'slug'        => ['type' => 'slug', 'label' => 'URL slug', 'from' => 'role', 'help' => 'Address: /work-experience/your-slug/'],
                'start_date'  => ['type' => 'date', 'label' => 'Start date', 'required' => true],
                'ongoing'     => ['type' => 'checkbox', 'label' => 'Ongoing (I still work here)'],
                'end_date'    => ['type' => 'date', 'label' => 'End date', 'disabled_by' => 'ongoing'],
                'work_type'   => ['type' => 'select', 'label' => 'Type of work', 'options' => WORK_TYPES, 'default' => 'remote'],
                'description' => ['type' => 'richtext', 'label' => 'Description of work'],
                'status'      => ['type' => 'select', 'label' => 'Status', 'options' => STATUS_OPTIONS, 'default' => 'published', 'side' => true],
            ],
        ],

        'education' => [
            'label' => 'Education', 'singular' => 'Education entry', 'icon' => '◎',
            'order' => 'sort_order ASC, start_date DESC, id ASC',
            'list'  => ['institution' => 'Institution', 'program' => 'Program', 'start_date' => 'Start', 'end_date' => 'End'],
            'title_field' => 'institution',
            'fields' => [
                'institution' => ['type' => 'text', 'label' => 'Institution', 'required' => true],
                'program'     => ['type' => 'text', 'label' => 'Program / degree'],
                'location'    => ['type' => 'text', 'label' => 'Location'],
                'start_date'  => ['type' => 'date', 'label' => 'Start date'],
                'ongoing'     => ['type' => 'checkbox', 'label' => 'Ongoing'],
                'end_date'    => ['type' => 'date', 'label' => 'End date', 'disabled_by' => 'ongoing'],
                'description' => ['type' => 'textarea', 'label' => 'Notes', 'rows' => 4],
                'sort_order'  => ['type' => 'number', 'label' => 'Order', 'default' => 0, 'help' => 'Lower numbers show first.', 'side' => true],
                'status'      => ['type' => 'select', 'label' => 'Status', 'options' => STATUS_OPTIONS, 'default' => 'published', 'side' => true],
            ],
        ],

        'certifications' => [
            'label' => 'Certifications', 'singular' => 'Certification', 'icon' => '✓',
            'order' => 'issued_on DESC, id DESC',
            'list'  => ['name' => 'Name', 'issuer' => 'Issued by', 'issued_on' => 'Issued on'],
            'title_field' => 'name',
            'fields' => [
                'name'           => ['type' => 'text', 'label' => 'Certificate name', 'required' => true],
                'issuer'         => ['type' => 'text', 'label' => 'Issued by'],
                'issued_on'      => ['type' => 'date', 'label' => 'Issued on'],
                'credential_url' => ['type' => 'url', 'label' => 'Credential URL', 'help' => 'Opens in a new tab.'],
                'image'          => ['type' => 'image', 'label' => 'Badge / certificate image', 'alt' => 'image_alt'],
                'status'         => ['type' => 'select', 'label' => 'Status', 'options' => STATUS_OPTIONS, 'default' => 'published', 'side' => true],
            ],
        ],

        'affiliates' => [
            'label' => 'Affiliates & sponsors', 'singular' => 'Affiliate / sponsorship', 'icon' => '%',
            'order' => 'published_at DESC, id DESC',
            'list'  => ['title' => 'Title', 'company_name' => 'Company', 'kind' => 'Type', 'has_code' => 'Promo code', 'status' => 'Status'],
            'fields' => [
                'title'          => ['type' => 'text', 'label' => 'Title', 'required' => true],
                'slug'           => ['type' => 'slug', 'label' => 'URL slug', 'from' => 'title', 'help' => 'Address: /affiliate/your-slug/'],
                'company_name'   => ['type' => 'text', 'label' => 'Company', 'help' => 'e.g. Hetzner Online GmbH'],
                'company_url'    => ['type' => 'url', 'label' => 'Company website', 'help' => 'Official site, linked from the company name.'],
                'link_url'       => ['type' => 'url', 'label' => 'Affiliate / sponsorship link', 'help' => 'Your tracked link (e.g. https://dje.ba/hetzner-online). Shown as “Visit Site”, opens in a new tab.'],
                'kind'           => ['type' => 'select', 'label' => 'Type', 'options' => AFFILIATE_KINDS, 'default' => 'affiliate'],
                'has_code'       => ['type' => 'select', 'label' => 'Promo code', 'options' => PROMO_OPTIONS, 'default' => 'no'],
                'promo_code'     => ['type' => 'text', 'label' => 'Code', 'enabled_when' => ['has_code', 'yes'], 'raw' => true,
                                     'help' => 'Saved exactly as typed (capitals and symbols kept).'],
                'featured_image' => ['type' => 'image', 'label' => 'Featured image', 'alt' => 'featured_alt'],
                'content'        => ['type' => 'richtext', 'label' => 'Description / review'],
                'status'         => ['type' => 'select', 'label' => 'Status', 'options' => STATUS_OPTIONS, 'default' => 'published', 'side' => true],
                'published_at'   => ['type' => 'datetime', 'label' => 'Date', 'default' => 'now', 'side' => true, 'help' => 'Newest first on the list.'],
                'seo_title'       => ['type' => 'text', 'label' => 'Search title', 'side' => true, 'counter' => SEO_TITLE_MAX, 'panel' => 'Search engines (SEO)',
                                      'help' => 'The blue link in Google and Bing. Empty = the title. About 60 characters.'],
                'seo_description' => ['type' => 'textarea', 'label' => 'Search description', 'rows' => 4, 'side' => true, 'counter' => SEO_DESC_MAX,
                                      'help' => 'The text under the link in search results and in share previews. Empty = the excerpt or first lines. 70–160 characters.'],
            ],
        ],

        'contacts' => [
            'label' => 'Contact links', 'singular' => 'Contact link', 'icon' => '☏',
            'order' => 'sort_order ASC, id ASC',
            'list'  => ['label' => 'Type', 'value' => 'Shown text', 'sort_order' => 'Order', 'status' => 'Status'],
            'title_field' => 'label',
            'fields' => [
                'label'      => ['type' => 'text', 'label' => 'Type', 'required' => true, 'help' => 'The heading on the card, e.g. "Facebook Page", "Email", "GitHub account".'],
                'value'      => ['type' => 'text', 'label' => 'Shown text', 'help' => 'e.g. your name or the email address. Empty = the address itself.'],
                'link'       => ['type' => 'link', 'label' => 'Address', 'required' => true, 'help' => 'https://… for profiles, an email address, or a phone number starting with +.'],
                'icon'       => ['type' => 'select', 'label' => 'Icon', 'options' => CONTACT_ICON_OPTIONS, 'default' => '', 'help' => 'Automatic picks it from the address. Your own icons come from Admin → Icons.'],
                'sort_order' => ['type' => 'number', 'label' => 'Order', 'default' => 100, 'side' => true, 'help' => 'Lower numbers come first (10, 20, 30… leaves room to slot new ones in).'],
                'status'     => ['type' => 'select', 'label' => 'Status', 'options' => STATUS_OPTIONS, 'default' => 'published', 'side' => true],
            ],
        ],

        'pages' => [
            'label' => 'Pages', 'singular' => 'Page', 'icon' => '▢',
            'order' => 'footer_order ASC, title ASC',
            'list'  => ['title' => 'Title', 'slug' => 'Address', 'in_footer' => 'In footer', 'status' => 'Status'],
            'fields' => [
                'title'        => ['type' => 'text', 'label' => 'Title', 'required' => true],
                'slug'         => ['type' => 'slug', 'label' => 'URL slug', 'from' => 'title', 'help' => 'Address: /your-slug/. The page with slug "contact" is the Contact link in the menu and shows your Contact links grid (write [contact-links] in the text where it should go); "affiliates-and-sponsorships" shows its text above the affiliate list.'],
                'content'      => ['type' => 'richtext', 'label' => 'Content'],
                'status'       => ['type' => 'select', 'label' => 'Status', 'options' => STATUS_OPTIONS, 'default' => 'published', 'side' => true],
                'in_footer'    => ['type' => 'checkbox', 'label' => 'Link in footer', 'side' => true],
                'footer_order' => ['type' => 'number', 'label' => 'Footer order', 'default' => 0, 'side' => true],
                'seo_title'       => ['type' => 'text', 'label' => 'Search title', 'side' => true, 'counter' => SEO_TITLE_MAX, 'panel' => 'Search engines (SEO)',
                                      'help' => 'The blue link in Google and Bing. Empty = the title. About 60 characters.'],
                'seo_description' => ['type' => 'textarea', 'label' => 'Search description', 'rows' => 4, 'side' => true, 'counter' => SEO_DESC_MAX,
                                      'help' => 'The text under the link in search results and in share previews. Empty = the excerpt or first lines. 70–160 characters.'],
            ],
        ],
    ];
}

/** Site-wide settings, grouped for the Settings screen. Same field types as above. */
function settings_fields(): array
{
    return [
        'Identity' => [
            'site_title'       => ['type' => 'text', 'label' => 'Site title / your name', 'default' => 'Marko Idžan'],
            'tagline'          => ['type' => 'text', 'label' => 'Tagline (homepage)', 'default' => 'Web Excellence: Design, Manage, Support'],
            'meta_description' => ['type' => 'textarea', 'label' => 'Default meta description', 'rows' => 2, 'default' => 'Marko Idžan Web Excellence: Design, Manage, Support'],
            'profile_image'    => ['type' => 'image', 'label' => 'Profile picture (homepage & About)', 'alt' => 'profile_alt'],
            'og_image'         => ['type' => 'image', 'label' => 'Social sharing image (Open Graph)'],
            'favicon'          => ['type' => 'image', 'label' => 'Favicon (your photo or logo, at least 512×512)', 'help' => 'Browser tab, bookmark and phone home-screen icons are all made from this one image (centre square).'],
            'favicon_shape'    => ['type' => 'select', 'label' => 'Favicon shape', 'options' => FAVICON_SHAPES, 'default' => 'circle'],
        ],
        'About me' => [
            'about_html' => ['type' => 'richtext', 'label' => 'About me text'],
        ],
        'Colors' => [
            'color_primary'   => ['type' => 'color', 'label' => 'Primary', 'default' => '#1F2022', 'help' => 'Page background, header and footer.'],
            'color_secondary' => ['type' => 'color', 'label' => 'Secondary', 'default' => '#2B2B2B', 'help' => 'Cards, bands and panels.'],
            'color_text'      => ['type' => 'color', 'label' => 'Text', 'default' => '#FDFBFB'],
            'color_accent'    => ['type' => 'color', 'label' => 'Accent', 'default' => '#FFB900', 'help' => 'Buttons, links, highlights.'],
            'theme_default'   => ['type' => 'select', 'label' => 'Light / dark mode', 'options' => THEME_DEFAULTS, 'default' => 'dark',
                                  'help' => 'Visitors can always switch with the sun/moon button in the header; their choice is remembered.'],
            'color_light_bg'        => ['type' => 'color', 'label' => 'Light mode: page background', 'default' => '#FDFBFB'],
            'color_light_secondary' => ['type' => 'color', 'label' => 'Light mode: cards and bands', 'default' => '#F1EFEE'],
            'color_light_text'      => ['type' => 'color', 'label' => 'Light mode: text', 'default' => '#1F2022'],
            'color_light_accent'    => ['type' => 'color', 'label' => 'Light mode: links and highlights', 'default' => '#8A5A00',
                                        'help' => 'A darker gold, because yellow text is hard to read on white. Buttons keep the Accent colour.'],
            'color_info_bg'   => ['type' => 'color', 'label' => 'Info box background', 'default' => '#D9EDF7', 'help' => 'Light box in page text (e.g. reply times on Contact). Its text is dark.'],
            'color_info_head' => ['type' => 'color', 'label' => 'Info box heading', 'default' => '#3771A7'],
            'color_notice'    => ['type' => 'color', 'label' => 'Notice box', 'default' => '#3771A7', 'help' => 'Blue box with white text (e.g. Important Notice on Affiliates).'],
            'color_warning'   => ['type' => 'color', 'label' => 'Warning box', 'default' => '#DA232C', 'help' => 'Red box with white text (e.g. unsolicited proposals on Contact).'],
        ],
        'Links & footer' => [
            'mastodon_url'    => ['type' => 'url', 'label' => 'Mastodon profile (invisible rel="me" verification only — not shown on the site)', 'default' => 'https://mastodon.social/@idzan_hr'],
            'twitter_handle'  => ['type' => 'text', 'label' => 'X/Twitter handle for share cards', 'default' => '@idzan_hr'],
            'footer_links'    => ['type' => 'textarea', 'label' => 'Footer links — one per line: Label | URL', 'rows' => 4,
                                  'default' => "Social Networks of Author | https://socials.idzan.hr\nMusical Works of Author | https://music.idzan.hr"],
            'copyright_from'  => ['type' => 'text', 'label' => 'Copyright start year', 'default' => '2010'],
            'footer_note'     => ['type' => 'richtext', 'label' => 'Footer note'],
        ],
        'Fonts' => [
            'font_body'    => ['type' => 'text', 'label' => 'Text font', 'default' => 'Roboto Self Hosted', 'help' => 'Family name from the @font-face rules below. Roboto 400 for text, 500 for buttons.'],
            'font_heading' => ['type' => 'text', 'label' => 'Heading font', 'default' => 'Roboto Self Hosted', 'help' => 'Headings use weight 700.'],
            'fonts_css'    => ['type' => 'textarea', 'label' => '@font-face rules (filled in by the WordPress import)', 'rows' => 6,
                               'help' => 'Font files live in /assets/fonts/. Keep these as they are unless you add a new brand font.'],
        ],
        'Search & bots' => [
            'robots_txt'  => ['type' => 'textarea', 'label' => 'robots.txt', 'rows' => 18, 'default' => ROBOTS_DEFAULT,
                              'help' => 'Published at /robots.txt with the Sitemap line added. Lines that mention the admin address are removed. Empty = the recommended default.'],
            'indexnow_enabled' => ['type' => 'checkbox', 'label' => 'Notify search engines about changes right away (IndexNow: Bing, Copilot, DuckDuckGo, Yandex, Seznam…)', 'default' => 0,
                                   'help' => 'After every save, only the addresses that changed are sent. Google doesn\'t use IndexNow; it reads the sitemap. The first time, every address is sent once.'],
            'llms_txt'    => ['type' => 'checkbox', 'label' => 'Publish /llms.txt (a plain list of your pages and posts for AI assistants)', 'default' => 1],
        ],
        'Cookie consent (GDPR)' => [
            'consent_enabled'   => ['type' => 'checkbox', 'label' => 'Show the cookie consent banner', 'default' => 1],
            'consent_title'     => ['type' => 'text', 'label' => 'Banner title', 'default' => 'Manage Consent'],
            'consent_text'      => ['type' => 'textarea', 'label' => 'Banner text', 'rows' => 4,
                                    'default' => 'This website only stores what it needs to work: your consent choice and, if you allow it, your accessibility settings. There are no tracking, analytics or advertising cookies, and nothing is shared with third parties. You can change your choice at any time with “Manage consent” at the bottom of every page.'],
            'consent_preferences_text' => ['type' => 'text', 'label' => 'Preferences — description', 'default' => 'Remembers your accessibility settings (text size, contrast…) on this device.'],
            'consent_statistics_text'  => ['type' => 'text', 'label' => 'Statistics — description', 'default' => 'Not used on this website.'],
            'consent_marketing_text'   => ['type' => 'text', 'label' => 'Marketing — description', 'default' => 'Not used on this website.'],
            'consent_policy_slug'  => ['type' => 'text', 'label' => 'Cookie policy page (slug)', 'default' => 'cookie-policy-eu'],
            'consent_privacy_slug' => ['type' => 'text', 'label' => 'Privacy statement page (slug)', 'default' => 'privacy-statement-eu'],
            'consent_days'      => ['type' => 'number', 'label' => 'Remember the choice for (days)', 'default' => 365],
        ],
        'Blog' => [
            'posts_per_page' => ['type' => 'number', 'label' => 'Blog posts per page', 'default' => 10],
        ],
    ];
}

/** Setting value with its default from settings_fields(). */
function s(string $key): string
{
    static $defaults = null;
    if ($defaults === null) {
        $defaults = [];
        foreach (settings_fields() as $group) foreach ($group as $k => $d) $defaults[$k] = (string)($d['default'] ?? '');
    }
    return tr_setting($key, setting($key, $defaults[$key] ?? ''));
}

function entity(string $key): ?array
{
    $all = entities();
    if (!isset($all[$key])) return null;
    $def = $all[$key];
    $def['key'] = $key;
    $def['title_field'] ??= 'title';
    return $def;
}
