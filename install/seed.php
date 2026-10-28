<?php
declare(strict_types=1);

/**
 * Starter content: placeholder text so every page type has something to show right after installing.
 * Replace it in the admin, or run Admin → Import from WordPress (posts with the same slug are replaced).
 * Everything here is an example: names, companies and addresses are made up.
 */
function seed_content(): void
{
    $t = now();
    $contact = 'hello@domain.com';

    $settings = [
        'site_title' => 'Your Name',
        'tagline' => 'Web Design, Development and Support',
        'meta_description' => 'Portfolio and blog of Your Name: web design, development and support.',
        'about_html' => '<p>Write a few paragraphs about yourself here: what you do, how long you have been doing it and what you enjoy most about it.</p>'
            . '<p>Mention the kind of work you take on, the tools you know well and anything that makes you different. Keep it personal: visitors want to know who they would be working with.</p>',
        'footer_note' => '<p>Hand-built PHP CMS. Static HTML, no tracking.</p>',
    ];
    foreach ($settings as $k => $v) save_setting($k, $v);

    // Work experience: one ongoing, one finished.
    $exp = [
        ['Self Employed', 'Freelance Web Designer', 'freelance-web-designer', '2015-01-01', null, 1, 'remote'],
        ['Example Company Ltd.', 'Web and Social Media Manager', 'web-and-social-media-manager', '2018-03-01', '2022-06-30', 0, 'onsite'],
    ];
    foreach ($exp as [$company, $role, $slug, $start, $end, $ongoing, $type]) {
        q('INSERT INTO experience (company, role, slug, start_date, end_date, ongoing, work_type, description, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$company, $role, $slug, $start, $end, $ongoing, $type, '', 'published', $t, $t]);
    }

    $edu = [['Example University of Applied Sciences', 1], ['Example High School', 2]];
    foreach ($edu as [$inst, $o]) {
        q('INSERT INTO education (institution, program, location, ongoing, description, sort_order, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)',
            [$inst, '', '', 0, '', $o, 'published', $t, $t]);
    }

    q('INSERT INTO certifications (name, issuer, issued_on, credential_url, image, image_alt, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)',
        ['Example Certificate', 'Example Academy', '2025-01-01', '', '', 'Example Certificate issued by Example Academy', 'published', $t, $t]);

    q('INSERT INTO projects (title, slug, client, started_on, url, featured_image, featured_alt, content, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [
        '“Example Client” Web Site', 'example-client-web-site', 'Example Client Ltd.', '2025-05-01', 'https://example.com/', '', '',
        '<p>Describe the project: what the client needed, what you built and which tools you used. Add a screenshot as the featured image.</p>',
        'published', $t, $t,
    ]);
    $id = (int)db()->lastInsertId();
    set_entity_terms('projects', $id, 'service', [ensure_term_seed('service', 'Web Design'), ensure_term_seed('service', 'Web Development')]);
    set_entity_terms('projects', $id, 'tool', array_map(fn($n) => ensure_term_seed('tool', $n), ['CSS', 'HTML']));

    $pages = [
        ['Contact', 'contact', '<p>Have a project in mind or just want to say hello? Write to <a href="mailto:' . $contact . '">' . $contact . '</a>.</p>', 0, 0],
        ['Imprint', 'imprint', '<p>Add your imprint details here.</p>', 1, 1],
        ['Disclaimer', 'disclaimer', '<p>Add your disclaimer here.</p>', 1, 2],
        ['Accessibility statement', 'accessibility-statement', '<p>This website aims to conform to WCAG 2.1 level AA. It can be used with a keyboard and screen readers, respects your system’s reduced-motion setting, and offers text size, contrast, link-underline, readable-font and stop-animation options through the “Aa” button in the bottom-right corner.</p><p>If something doesn’t work for you, please let me know: <a href="mailto:' . $contact . '">' . $contact . '</a>.</p>', 1, 3],
        ['Cookie Policy (EU)', 'cookie-policy-eu', '<p>This cookie policy explains what this website stores on your device and why. It applies to visitors from the European Economic Area and follows the GDPR and the ePrivacy Directive.</p>'
            . '<h2>What is stored</h2><table><thead><tr><th>Name</th><th>Purpose</th><th>Category</th><th>Kept for</th></tr></thead><tbody>'
            . '<tr><td><code>cookie_consent</code></td><td>Remembers your choice in the cookie banner</td><td>Functional</td><td>365 days</td></tr>'
            . '<tr><td><code>site_lang</code></td><td>Remembers the language you picked in the language menu</td><td>Functional</td><td>365 days</td></tr>'
            . '<tr><td><code>site_theme</code></td><td>Remembers light or dark mode, if you switch it</td><td>Functional</td><td>365 days</td></tr>'
            . '<tr><td><code>a11y-options</code> (local storage)</td><td>Remembers your accessibility settings — only if you allow Preferences</td><td>Preferences</td><td>Until you clear it</td></tr>'
            . '</tbody></table>'
            . '<p>No statistics, analytics, advertising or social-media tracking cookies are used, and no third-party scripts or fonts are loaded. Embedded YouTube videos use the privacy-enhanced youtube-nocookie.com player.</p>'
            . '<h2>Your choice</h2><p>Use <strong>Manage consent</strong> at the bottom of any page to change or withdraw your consent at any time. You can also delete stored data in your browser settings.</p>'
            . '<h2>Contact</h2><p>Questions about this policy: <a href="mailto:' . $contact . '">' . $contact . '</a>.</p>', 1, 4],
        ['Privacy Statement (EU)', 'privacy-statement-eu', '<p>This privacy statement explains how personal data is handled on this website, in line with the EU General Data Protection Regulation (GDPR).</p>'
            . '<h2>Controller</h2><p>Your Name, City, Country — <a href="mailto:' . $contact . '">' . $contact . '</a></p>'
            . '<h2>What is collected</h2><p>This website has no contact form, comments, accounts, analytics or advertising. The web server keeps standard technical logs (IP address, time, requested page, browser) for security and troubleshooting; these are kept for a short period and not shared or used for profiling.</p>'
            . '<h2>Cookies</h2><p>See the <a href="/cookie-policy-eu/">Cookie Policy (EU)</a>.</p>'
            . '<h2>Email</h2><p>If you write to me by email, your message is used only to reply and is not shared.</p>'
            . '<h2>Your rights</h2><p>You can ask for access to, correction or deletion of your data, restrict or object to processing, and lodge a complaint with your national data protection authority.</p>', 1, 6],
        ['Affiliates and Sponsorships', 'affiliates-and-sponsorships', '<p>Some links on this website are affiliate links or point to sponsored products and services. Sponsored content is always clearly marked.</p><p>Only tools and services I have used myself are recommended here.</p>', 1, 5],
    ];
    foreach ($pages as [$title, $slug, $html, $foot, $order]) {
        q('INSERT INTO pages (title, slug, content, status, in_footer, footer_order, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)',
            [$title, $slug, $html, 'published', $foot, $order, $t, $t]);
    }

    q('INSERT INTO affiliates (title, slug, company_name, company_url, link_url, kind, has_code, promo_code, featured_image, featured_alt, content, status, published_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        ['Example Hosting – VPS and Web Hosting', 'example-hosting-vps-and-web-hosting', 'Example Hosting Ltd.', 'https://example.com/', 'https://example.com/?ref=your-id', 'affiliate', 'no', '', '', '',
         '<p>Say why you recommend this service and how long you have used it.</p>', 'published', $t, $t, $t]);

    q('INSERT INTO posts (title, slug, category_id, featured_image, featured_alt, excerpt, content, status, published_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [
        'Hello World', 'hello-world', ensure_term_seed('category', 'Updates'), '', '',
        'The first post on the new site. Edit or delete it in the admin.',
        '<p>The first post on the new site. Edit or delete it in Admin → Blog posts, or import your old posts with Admin → Import from WordPress.</p>',
        'published', $t, $t, $t,
    ]);

    foreach (['Web Design', 'Web Development', 'Maintenance', 'Hosting', 'SEO'] as $s) ensure_term_seed('service', $s);
}

function ensure_term_seed(string $tax, string $name): int
{
    $slug = slugify($name);
    $r = one('SELECT id FROM terms WHERE taxonomy = ? AND slug = ?', [$tax, $slug]);
    if ($r) return (int)$r['id'];
    q('INSERT INTO terms (taxonomy, name, slug) VALUES (?, ?, ?)', [$tax, $name, $slug]);
    return (int)db()->lastInsertId();
}
