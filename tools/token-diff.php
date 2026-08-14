<?php

/**
 * Prove a change is token-identical — i.e. that it cannot have altered behaviour.
 *
 * Compares the PHP token stream of each file against a baseline copy, dropping whitespace
 * and comments. If the streams match, the change is comment-and-layout only: no test needs
 * to reach the code for that to be true, which makes this a stronger guarantee than any
 * suite for docblock and formatting commits. Behavioural changes are expected to report
 * DIFFERENT — that is information, not a failure.
 *
 * Driven by `make token-diff`, which exports the baseline with git first (there is no git
 * inside the PHP container).
 *
 * Usage:  php tools/token-diff.php <baseline-dir>
 *
 * @author  Jérôme Vogel
 * @license http://www.gnu.org/copyleft/gpl.html  GPL
 * @link    https://github.com/jeromev/LunarSystem
 */

$baseDir = rtrim($argv[1] ?? '', '/');
if ($baseDir === '' || !is_dir($baseDir)) {
	fwrite(STDERR, "usage: php tools/token-diff.php <baseline-dir>   (use `make token-diff`)\n");
	exit(2);
}

/** Executable tokens only: whitespace, comments and docblocks are dropped. */
function luna_tokens(string $src): array {
	$out = [];
	foreach (token_get_all($src) as $t) {
		if (is_array($t)) {
			if ($t[0] === T_WHITESPACE || $t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { continue; }
			$out[] = token_name($t[0]).':'.$t[1];
		} else {
			$out[] = $t;
		}
	}
	return $out;
}

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS));
$differing = 0;
$checked = 0;

foreach ($it as $old) {
	if ($old->getExtension() !== 'php') { continue; }
	$rel = substr($old->getPathname(), strlen($baseDir) + 1);
	if (!is_file($rel)) { printf("  %-52s DELETED in working tree\n", $rel); continue; }
	$checked++;
	$a = luna_tokens((string) file_get_contents($old->getPathname()));
	$b = luna_tokens((string) file_get_contents($rel));
	if ($a === $b) {
		printf("  %-52s identical\n", $rel);
		continue;
	}
	$differing++;
	printf("  %-52s DIFFERENT (%d -> %d tokens)\n", $rel, count($a), count($b));
	foreach ($a as $i => $t) {
		if (!isset($b[$i]) || $b[$i] !== $t) {
			printf("      first divergence at token %d: %s -> %s\n", $i, var_export($t, true), var_export($b[$i] ?? null, true));
			break;
		}
	}
}

printf("\ntoken-diff: %d file(s) compared, %d with altered tokens\n", $checked, $differing);
exit($differing === 0 ? 0 : 1);
