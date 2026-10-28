<?php
declare(strict_types=1);

/**
 * The old WordPress site showed some page text in coloured boxes (Elementor widgets the export
 * doesn't carry). This puts that text back into the CMS boxes. Runs once as a database update and
 * after every import; text that's already in a box, or worded differently, is left alone.
 */
function legacy_boxes(string $slug, string $html): string
{
    if (str_contains($html, 'class="box ')) return $html;
    if ($slug === 'contact') {
        // A run-together heading from the old page: "Reporting Policy for Violations All suspected…"
        $html = preg_replace('~<p>\s*Reporting Policy for Violations\s+(All suspected)~u', '<p><strong>Reporting policy for violations:</strong> $1', $html);
        $html = preg_replace('~(<h4>\s*I strive to respond.*?</h4>\s*(?:<p>.*?</p>\s*)*?)(?=<h4>\s*However|\z)~su', '<div class="box box-info">$1</div>' . "\n", (string)$html, 1);
        $html = preg_replace('~(<h4>\s*However, I do not entertain.*?</h4>.*)$~su', '<div class="box box-warning">$1</div>', (string)$html, 1);
    }
    if ($slug === 'affiliates-and-sponsorships') {
        $html = preg_replace('~(<h2>\s*Important Notice\s*</h2>.*?helpful for you\.?\s*</p>)~su', '<div class="box box-notice">$1</div>', $html, 1);
    }
    return (string)$html;
}

/** Applies legacy_boxes() to the pages in the database. Returns how many pages changed. */
function legacy_boxes_apply(): int
{
    $n = 0;
    foreach (all("SELECT id, slug, content FROM pages WHERE slug IN ('contact', 'affiliates-and-sponsorships')") as $p) {
        $new = legacy_boxes((string)$p['slug'], (string)$p['content']);
        if ($new !== $p['content']) { q('UPDATE pages SET content = ?, updated_at = ? WHERE id = ?', [$new, now(), $p['id']]); $n++; }
    }
    return $n;
}
