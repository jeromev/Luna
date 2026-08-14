<?php

/**
 * preflight — check a host can run the Luna publishing surface before you deploy.
 *
 * Verifies the PHP version and the extensions the app needs (notably `xsl`, which the
 * XSLT HTML renderer depends on and which some shared hosts omit), the committed vendor
 * tree, and a writable cache dir. Run it on the target host (e.g. DreamHost shared, over
 * SSH) before pointing a domain at it:
 *
 *   php bin/preflight.php
 *
 * Exit code 0 = ready, 1 = something required is missing. See docs/going-public.md.
 *
 * @package Luna
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "preflight.php is CLI-only.\n"); exit(2); }

$ok = true;
function check($label, $pass, &$ok, $hint = '') {
	$ok = $ok && $pass;
	printf("  [%s] %s%s\n", $pass ? ' OK ' : 'FAIL', $label, ($pass || $hint === '') ? '' : "  — $hint");
}

echo "Luna preflight\n=====================\n";

// PHP version — 8.3 is the tested stack; 8.1+ should work.
check('PHP >= 8.1 (have '.PHP_VERSION.')', version_compare(PHP_VERSION, '8.1.0', '>='), $ok, 'select PHP 8.1+ in the host panel');

// Required extensions.
$required = [
	'xsl'       => 'XSLT HTML rendering (XSLTProcessor) — pages will not render without it',
	'pdo_mysql' => 'MySQL access',
	'mbstring'  => 'UTF-8 string handling',
	'gettext'   => 'i18n catalogs',
	'json'      => 'JSON-LD output',
	'libxml'    => 'RDF/XML + sitemap serialisation',
];
foreach ($required as $ext => $why) { check("ext: $ext", extension_loaded($ext), $ok, $why); }

// Composer vendor tree (committed for clone-and-run).
check('vendor/autoload.php present', is_readable(__DIR__.'/../vendor/autoload.php'), $ok, 'restore the committed vendor/ tree or run `composer install`');

// A writable cache dir for at least the default domain.
$cache = __DIR__.'/../luna/luna.domains/luna.default/cache';
check('default domain cache/ writable', is_dir($cache) && is_writable($cache), $ok, "make the domain's cache/ dir writable by the web server");

echo "\n".($ok ? "READY — the publishing surface can run on this host.\n" : "NOT READY — fix the FAIL lines above.\n");
exit($ok ? 0 : 1);
