<?php
/**
 * WP-OmniAuth Translation Builder
 *
 * Scans PHP source files for translatable strings (__, _e, esc_html__, esc_attr__)
 * and generates a .pot template file.
 *
 * Usage:
 *   php tools/build-translations.php
 *
 * This script is also called by the GitHub Actions workflow to auto-update
 * the .pot file on every push.
 */

$plugin_dir = dirname(__DIR__);
$scan_dirs  = ['includes'];
$scan_files = ['wp-omni-auth.php'];
$text_domain = 'wp-omni-auth';
$output_pot  = $plugin_dir . '/languages/wp-omni-auth.pot';

// ── Collect all PHP files ──────────────────────────────────────────
$files = [];
foreach ($scan_files as $f) {
    $path = $plugin_dir . '/' . $f;
    if (file_exists($path)) {
        $files[] = $path;
    }
}
foreach ($scan_dirs as $dir) {
    $full = $plugin_dir . '/' . $dir;
    if (!is_dir($full)) continue;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($full, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}

// ── Extract translatable strings ───────────────────────────────────
// Pattern matches: __('string', 'text-domain') and similar calls
// Handles both single and double quotes, and escaped quotes inside strings
$functions = ['__', '_e', 'esc_html__', 'esc_attr__', 'esc_html_e', 'esc_attr_e'];
$func_pattern = implode('|', array_map(function ($f) {
    return preg_quote($f, '/');
}, $functions));
$domain_pattern = preg_quote($text_domain, '/');

// Match: func( 'string' , 'domain' ) or func( "string" , "domain" )
$pattern = '/(?:' . $func_pattern . ')\s*\(\s*'
    . '(?:'
    . "'((?:[^'\\\\]|\\\\.)*)'" // single-quoted first arg
    . '|'
    . '"((?:[^"\\\\]|\\\\.)*)"'            // double-quoted first arg
    . ')'
    . '\s*,\s*'
    . '(?:'
    . "'" . $domain_pattern . "'"
    . '|'
    . '"' . $domain_pattern . '"'
    . ')'
    . '/s';

/** @var array<string, array{file: string, line: int}[]> $strings */
$strings = [];

foreach ($files as $file) {
    $content = file_get_contents($file);
    if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($matches as $match) {
            // Group 1 = single-quoted, Group 2 = double-quoted
            $raw = !empty($match[1][0]) ? $match[1][0] : (isset($match[2][0]) ? $match[2][0] : '');
            if ($raw === '') continue;

            // Unescape: \' → ' and \" → "
            $text = str_replace(["\\'", '\\"', '\\\\'], ["'", '"', '\\'], $raw);

            $line = substr_count($content, "\n", 0, $match[0][1]) + 1;
            $rel  = str_replace('\\', '/', substr($file, strlen($plugin_dir) + 1));

            if (!isset($strings[$text])) {
                $strings[$text] = [];
            }
            $strings[$text][] = ['file' => $rel, 'line' => $line];
        }
    }
}

// ── Extract provider display names ──────────────────────────────
// Provider classes call parent::__construct('slug', 'Display Name', '<svg>');
// the display name is a literal 2nd argument and should be translatable.
$name_pattern = "/parent::__construct\\(\\s*'[^']*'\\s*,\\s*((?:'((?:[^'\\\\]|\\\\.)*)'|\"((?:[^\"\\\\]|\\\\.)*)\"))/s";
foreach ($files as $file) {
    $content = file_get_contents($file);
    if (preg_match_all($name_pattern, $content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $match) {
            $raw = !empty($match[2][0]) ? $match[2][0] : (isset($match[3][0]) ? $match[3][0] : '');
            if ($raw === '') continue;
            $text = str_replace(["\\'", '\\"', '\\\\'], ["'", '"', '\\'], $raw);
            $line = substr_count($content, "\n", 0, $match[0][1]) + 1;
            $rel  = str_replace('\\', '/', substr($file, strlen($plugin_dir) + 1));
            if (!isset($strings[$text])) {
                $strings[$text] = [];
            }
            $strings[$text][] = ['file' => $rel, 'line' => $line];
        }
    }
}

// Sort by source string for stable output
ksort($strings);

// ── Generate .pot file ─────────────────────────────────────────────
$date    = date('Y-m-d H:iO');
$year    = date('Y');
$content = <<<POT_HEADER
# Copyright (C) {$year} WP-OmniAuth
# This file is distributed under the same license as the WP-OmniAuth plugin.
#
msgid ""
msgstr ""
"Project-Id-Version: WP-OmniAuth 0.1.0\\n"
"Report-Msgid-Bugs-To: https://github.com/Asunano/wp-omni-auth\\n"
"POT-Creation-Date: {$date}\\n"
"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n"
"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n"
"Language-Team: LANGUAGE <LL@li.org>\\n"
"Language: \\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"Plural-Forms: nplurals=INTEGER; plural=EXPRESSION;\\n"
"X-Generator: WP-OmniAuth Translation Builder\\n"


POT_HEADER;

$count = 0;
foreach ($strings as $text => $refs) {
    // Reference comments
    foreach ($refs as $ref) {
        $content .= '#: ' . $ref['file'] . ':' . $ref['line'] . "\n";
    }

    // Escape for PO format: backslash, then double-quote, then newlines
    $escaped = addcslashes($text, '"\\');
    $escaped = str_replace(["\r\n", "\r", "\n"], ['\\n', '\\n', '\\n'], $escaped);

    $content .= 'msgid "' . $escaped . '"' . "\n";
    $content .= 'msgstr ""' . "\n\n";
    $count++;
}

// Ensure output directory exists
$dir = dirname($output_pot);
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

file_put_contents($output_pot, $content);
echo "Generated {$output_pot} with {$count} strings.\n";
