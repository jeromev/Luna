<?php

/**
 * The house rules — the invariants that are this project's own.
 *
 * php-cs-fixer holds the layout and PHPStan holds the types; neither knows anything about
 * fold markers, about the ontology namespace that must agree across fifteen stylesheets, or
 * about a documentation convention. Those live here. See docs/coding-style.md.
 *
 * Pure PHP: no database, no running stack, no dependencies. Run with `make lint-house`.
 *
 * PHP 8.1+ (tested on 8.3)
 *
 * @author  Jérôme Vogel
 * @license http://www.gnu.org/copyleft/gpl.html  GPL
 * @link    https://github.com/jeromev/LunarSystem
 */

$root = dirname(__DIR__);
chdir($root);

$fails = 0;
$checks = 0;
function ok(bool $cond, string $label): void {
	global $fails, $checks;
	$checks++;
	if ($cond) {
		printf("  \033[32mPASS\033[0m %s\n", $label);
	} else {
		printf("  \033[31mFAIL\033[0m %s\n", $label);
		$fails++;
	}
}
function note(string $s): void { printf("  %s\n", $s); }

/** Every first-party PHP file, which is exactly what the Makefile's PHP_SRC covers. */
function luna_sources(): array {
	$out = ['index.php', 'luna/luna.php'];
	foreach (['luna/luna.classes', 'luna/luna.mods', 'bin', 'test'] as $d) {
		foreach (glob($d.'/*.php') as $f) { $out[] = $f; }
	}
	sort($out);
	return $out;
}

echo "--- house rule 1: fold markers are an accurate index ---\n";
// The markers are how this codebase has been navigated since 2006. A marker that names a
// method other than the one it wraps is worse than no marker, and an unbalanced file nests
// everything after it inside the wrong fold.
$foldProblems = [];
foreach (luna_sources() as $f) {
	$lines = file($f, FILE_IGNORE_NEW_LINES);
	$opens = [];
	$closes = 0;
	foreach ($lines as $i => $l) {
		if (preg_match('@^\s*//\s+\{\{\{\s*(.*)$@', $l, $m)) { $opens[] = [$i + 1, trim($m[1])]; }
		if (preg_match('@^\s*//\s+\}\}\}@', $l)) { $closes++; }
	}
	if (count($opens) !== $closes) {
		$foldProblems[] = sprintf('%s: %d open / %d close', $f, count($opens), $closes);
	}
	foreach ($opens as [$ln, $label]) {
		if (!preg_match('/^(\w+)\(\)/', $label, $m)) { continue; }   // free-text labels are fine
		// Scan to the fold's own close (or the next open) rather than a fixed number of lines:
		// a long docblock between marker and declaration must not put the declaration out of
		// reach, and a marker must never reach into the following fold.
		$next = null;
		for ($j = $ln; $j < count($lines); $j++) {
			if (preg_match('@^\s*//\s+(\}\}\}|\{\{\{)@', $lines[$j])) { break; }
			if (preg_match('/function\s+(\w+)\s*\(/', $lines[$j], $fm)) { $next = $fm[1]; break; }
		}
		if ($next !== null && $next !== $m[1]) {
			$foldProblems[] = sprintf('%s:%d: label "%s" wraps %s()', $f, $ln, $label, $next);
		}
	}
}

// The converse, which the rule also claims: every method is wrapped. Without this, a method
// with no markers at all is structurally invisible to the checks above — it contributes to
// neither the counts nor the labels — so the rule would be enforced only for methods that
// already comply.
foreach (luna_sources() as $f) {
	$lines = file($f, FILE_IGNORE_NEW_LINES);
	$depth = 0;
	$inClass = false;
	foreach ($lines as $i => $l) {
		if (preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+\w+/', $l)) { $inClass = true; }
		if (preg_match('@^\s*//\s+\{\{\{@', $l)) { $depth++; continue; }
		if (preg_match('@^\s*//\s+\}\}\}@', $l)) { $depth--; continue; }
		// Class members only: functions declared outside a class are script helpers
		// (luna.php's luna_env() and luna_is_https() live inside a function_exists guard).
		if (!$inClass) { continue; }
		if (!preg_match('/^\t(?:(?:final|abstract|public|private|protected|static)\s+)*function\s+(\w+)\s*\(/', $l, $fm)) { continue; }
		// Being inside an OPEN fold is the invariant, not having a marker immediately above:
		// several related one-liners may legitimately share one grouping fold, as the password
		// helpers in lunaTools do.
		if ($depth < 1) {
			$foldProblems[] = sprintf('%s:%d: %s() is not inside a fold', $f, $i + 1, $fm[1]);
		}
	}
}

foreach ($foldProblems as $p) { note($p); }
ok($foldProblems === [], 'every method is wrapped, the markers balance, and each names its method');

echo "--- house rule 11: the ontology namespace agrees everywhere ---\n";
// ontology/README.md records that this is maintained BY HAND across the model, fifteen
// stylesheets and the R2RML mapping, and is not auto-derived. That makes it the most
// fragile cross-file invariant in the repository, and it was enforced by nothing.
$model = (string) file_get_contents('luna/luna.classes/luna.model.class.php');
preg_match("/const LUNA_NS = '([^']+)'/", $model, $m);
$ns = $m[1] ?? '';
ok($ns !== '', 'lunaModel::LUNA_NS is readable'.($ns !== '' ? " ({$ns})" : ''));

if ($ns !== '') {
	$xsl = glob('luna/luna.xsl/luna.html.xsl/*.xsl');
	$bad = [];
	foreach ($xsl as $f) {
		$src = (string) file_get_contents($f);
		if (!preg_match('/xmlns:luna="([^"]*)"/', $src, $xm)) { $bad[] = "$f: no xmlns:luna"; continue; }
		if ($xm[1] !== $ns) { $bad[] = sprintf('%s: declares %s', $f, $xm[1]); }
	}
	foreach ($bad as $b) { note($b); }
	ok($bad === [] && $xsl !== [], sprintf('all %d stylesheets declare xmlns:luna = LUNA_NS', count($xsl)));

	$ttl = 'semantic/ontop/mapping.ttl';
	$hasTtl = is_file($ttl) && str_contains((string) file_get_contents($ttl), '<'.$ns.'>');
	ok($hasTtl, 'semantic/ontop/mapping.ttl binds luna: to LUNA_NS');
}

echo "--- house rule 8: prose cites symbols, never line numbers ---\n";
// Line numbers in prose rot silently: 19 of the 20 that used to be in docs/ pointed at the
// wrong construct, one at the wrong file entirely.
$anchors = [];
foreach (glob('docs/*.md') as $doc) {
	foreach (file($doc, FILE_IGNORE_NEW_LINES) as $i => $l) {
		if (preg_match_all('/[A-Za-z_.\/]+\.php[:#]L?\d+/', $l, $am)) {
			foreach ($am[0] as $a) { $anchors[] = sprintf('%s:%d: %s', $doc, $i + 1, $a); }
		}
	}
}
foreach ($anchors as $a) { note($a); }
ok($anchors === [], 'no file:line anchors in docs/*.md');

echo "--- house rule 9: the ratchets do not go up ---\n";
// Budgets the codebase currently fails are not rules. These are the counts as they stand;
// they may fall, never rise. docs/coding-style.md names the scheduled path down.
$counts = [
	'functions_without_return_type' => 0,
	'lines_over_120_columns' => 0,
];
$bigFiles = [];
foreach (luna_sources() as $f) {
	$src = (string) file_get_contents($f);
	foreach (token_get_all($src) as $t) { /* tokenise once for parse validity */ }
	preg_match_all('/function\s+\w+\s*\([^{;]*\)(\s*:\s*[\\\\\w|?]+)?/', $src, $fm, PREG_SET_ORDER);
	foreach ($fm as $decl) {
		if (!isset($decl[1]) || trim($decl[1]) === '') { $counts['functions_without_return_type']++; }
	}
	foreach (file($f, FILE_IGNORE_NEW_LINES) as $l) {
		// A tab counts as its display width, which is what a reader actually sees.
		if (mb_strlen(str_replace("\t", '    ', $l)) > 120) { $counts['lines_over_120_columns']++; }
	}
	$n = count(file($f));
	if ($n > 1000) { $bigFiles[$f] = $n; }
}
ksort($bigFiles);
$current = $counts + ['files_over_1000_lines' => $bigFiles];

$ratchetFile = 'test/style.counts';
if (!is_file($ratchetFile)) {
	file_put_contents($ratchetFile, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
	note("created {$ratchetFile} — commit it; from now on these numbers may fall, never rise");
	ok(true, 'ratchet baseline written');
} else {
	$prev = json_decode((string) file_get_contents($ratchetFile), true);
	$regressed = [];
	foreach (['functions_without_return_type', 'lines_over_120_columns'] as $k) {
		note(sprintf('%-32s %d (was %d)', $k, $current[$k], $prev[$k] ?? -1));
		if (isset($prev[$k]) && $current[$k] > $prev[$k]) {
			$regressed[] = sprintf('%s rose from %d to %d', $k, $prev[$k], $current[$k]);
		}
	}
	foreach ($bigFiles as $f => $n) {
		$was = $prev['files_over_1000_lines'][$f] ?? null;
		note(sprintf('%-52s %d lines%s', $f, $n, $was === null ? ' (new)' : sprintf(' (was %d)', $was)));
		if ($was !== null && $n > $was) { $regressed[] = sprintf('%s grew from %d to %d lines', $f, $was, $n); }
		if ($was === null) { $regressed[] = sprintf('%s is a new file over 1000 lines', $f); }
	}
	foreach ($regressed as $r) { note("\033[31m{$r}\033[0m"); }

	// A rise is sometimes right — a bug fix that needs two more lines, a new file. It should
	// still be a decision rather than a drift, so it takes an explicit accept and shows up as
	// a diff to the committed counts:  LUNA_RATCHET_ACCEPT=1 make lint-house
	$accept = getenv('LUNA_RATCHET_ACCEPT') === '1';
	if ($regressed !== [] && $accept) {
		note('LUNA_RATCHET_ACCEPT=1 — recording the new counts; commit test/style.counts with the change that caused them');
	}
	ok($regressed === [] || $accept, 'no ratchet went up');

	// Only ever write on an explicit accept. A plain `make lint-house` is a check, and a check
	// that silently rewrites a tracked file is a surprise — it would leave a dirty tree in CI
	// and quietly bank an improvement nobody committed.
	$fell = $regressed === [] && $current !== $prev;
	if ($accept) {
		file_put_contents($ratchetFile, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
	} elseif ($fell) {
		note('counts fell — bank them with LUNA_RATCHET_ACCEPT=1 make lint-house, and commit test/style.counts with the change that earned it');
	}
}

printf("\n%s\n", $fails === 0
	? sprintf("\033[32mHOUSE RULES HOLD\033[0m (%d checks)", $checks)
	: sprintf("\033[31m%d OF %d CHECK(S) FAILED\033[0m", $fails, $checks));
exit($fails === 0 ? 0 : 1);
