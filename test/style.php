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
 * @link    https://github.com/jeromev/Luna
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
//
// The published vocabulary and the worked examples belong to the same invariant. A namespace
// move that updates the model, the stylesheets and the mapping but leaves ontology/ontology.ttl
// describing the old IRI passes every other gate in the repository: the app renders, the suites
// go green, and the vocabulary quietly documents a namespace nothing writes. The render
// namespace is held to the same terms — it is declared in the same fifteen files, by hand, and
// drifts the same way.
$model = (string) file_get_contents('luna/luna.classes/luna.model.class.php');
preg_match("/const LUNA_NS = '([^']+)'/", $model, $m);
$ns = $m[1] ?? '';
preg_match("/const LUNA_RENDER_NS = '([^']+)'/", $model, $rm);
$renderNs = $rm[1] ?? '';
ok($ns !== '', 'lunaModel::LUNA_NS is readable'.($ns !== '' ? " ({$ns})" : ''));
ok($renderNs !== '', 'lunaModel::LUNA_RENDER_NS is readable'.($renderNs !== '' ? " ({$renderNs})" : ''));

$xsl = glob('luna/luna.xsl/luna.html.xsl/*.xsl');

if ($ns !== '') {
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

	// The vocabulary has to describe the namespace the app actually writes, in both places it
	// names it: the prefix binding and the subject of the owl:Ontology declaration.
	$vocabSrc = is_file('ontology/ontology.ttl') ? (string) file_get_contents('ontology/ontology.ttl') : '';
	$vocabPrefix = preg_match('/@prefix\s+luna:\s*<([^>]*)>/', $vocabSrc, $vm) === 1 ? $vm[1] : '';
	if ($vocabPrefix !== $ns) { note("ontology/ontology.ttl: @prefix luna: <{$vocabPrefix}>"); }
	ok($vocabSrc !== '' && $vocabPrefix === $ns, 'ontology/ontology.ttl binds @prefix luna: to LUNA_NS');
	ok(
		$vocabSrc !== '' && preg_match('/<'.preg_quote($ns, '/').'>\s+a\s+owl:Ontology/', $vocabSrc) === 1,
		'ontology/ontology.ttl declares <LUNA_NS> a owl:Ontology'
	);

	// Every query a reader copies out of examples/queries.sparql has to run against the deployed
	// store, which means its PREFIX has to be the deployed namespace.
	$qSrc = is_file('examples/queries.sparql') ? (string) file_get_contents('examples/queries.sparql') : '';
	preg_match_all('/PREFIX\s+luna:\s*<([^>]*)>/', $qSrc, $qm);
	$qBad = [];
	foreach ($qm[1] as $u) {
		if ($u !== $ns) { $qBad[] = $u; }
	}
	foreach ($qBad as $u) { note("examples/queries.sparql: PREFIX luna: <{$u}>"); }
	ok(
		$qm[1] !== [] && $qBad === [],
		sprintf('all %d luna: PREFIXes in examples/queries.sparql equal LUNA_NS', count($qm[1]))
	);
}

if ($renderNs !== '') {
	$badUi = [];
	foreach ($xsl as $f) {
		$src = (string) file_get_contents($f);
		if (!preg_match('/xmlns:ui="([^"]*)"/', $src, $um)) { $badUi[] = "$f: no xmlns:ui"; continue; }
		if ($um[1] !== $renderNs) { $badUi[] = sprintf('%s: declares %s', $f, $um[1]); }
	}
	foreach ($badUi as $b) { note($b); }
	ok($badUi === [] && $xsl !== [], sprintf('all %d stylesheets declare xmlns:ui = LUNA_RENDER_NS', count($xsl)));
}

echo "--- house rule 12: a destructive control confirms before it acts ---\n";
// A delete button is two halves, written by hand in six stylesheets and agreed nowhere:
// class="submit warning" is the paint, and data-confirm is the behaviour js/luna.js binds to
// window.confirm(). A seventh delete button that copies the class and forgets the attribute is
// invisible — same markup, same styling, same submit — and it simply deletes without asking.
// Nothing in test/ can see it either: every suite posts with curl, which never runs page
// JavaScript. So the pairing is asserted here, statically, over the XSLT that emits it.
//
// Both directions, deliberately. A confirm on a control the stylesheet does not paint as
// destructive is the same marriage breaking from the other side: the dialogue says the action
// is grave and nothing on the page agrees. Either half moving is a decision, and it should
// cost an edit to this rule rather than pass unnoticed.
$XHTML = 'http://www.w3.org/1999/xhtml';
$XSLT = 'http://www.w3.org/1999/XSL/Transform';

/**
 * The value an XSLT-authored element will emit for one attribute, written either literally or
 * built by an <xsl:attribute name="…"> child. Both forms occur here — the class is literal, and
 * data-confirm is an xsl:attribute because its text is an i18n vocabulary lookup — so reading
 * only the literal form would find every destructive button and none of the confirmations.
 * Returns null when the element emits the attribute by neither route.
 */
$emitted = static function (DOMElement $el, string $name) use ($XSLT): ?string {
	if ($el->hasAttribute($name)) { return $el->getAttribute($name); }
	foreach ($el->childNodes as $child) {
		if ($child instanceof DOMElement && $child->namespaceURI === $XSLT
			&& $child->localName === 'attribute' && $child->getAttribute('name') === $name) {
			return $child->textContent;
		}
	}
	return null;
};

$confirmProblems = [];
$destructive = 0;
foreach (glob('luna/luna.xsl/luna.html.xsl/*.xsl') as $f) {
	$doc = new DOMDocument();
	if (!@$doc->load($f)) { $confirmProblems[] = "{$f}: does not parse as XML"; continue; }
	foreach ($doc->getElementsByTagName('*') as $el) {
		if (!$el instanceof DOMElement || $el->namespaceURI !== $XHTML) { continue; }
		// `warning` paints a control (scss/_page.scss) and also a message box and a table row;
		// only a control can be clicked, so only a control is asked to confirm.
		$class = (string) $emitted($el, 'class');
		$isControl = in_array($el->localName, ['input', 'button', 'a'], true);
		$isDestructive = $isControl && in_array('warning', preg_split('/\s+/', trim($class)) ?: [], true);
		$confirms = $emitted($el, 'data-confirm') !== null;
		if ($isDestructive) { $destructive++; }
		// The rule is the equivalence, so one comparison states it and covers both directions.
		if ($isDestructive !== $confirms) {
			$why = $isDestructive ? 'is destructive and does not confirm' : 'confirms but is not painted destructive';
			$confirmProblems[] = sprintf('%s: <%s class="%s"> %s', $f, $el->localName, $class, $why);
		}
	}
}
// A rule that finds nothing passes for the wrong reason. Renaming the class in the stylesheets
// and the SCSS together would leave every check above satisfied over an empty set, which is
// exactly the state this rule exists to make impossible.
if ($destructive === 0) {
	$confirmProblems[] = 'no destructive control found in any stylesheet — has the `warning` class been renamed?';
}
foreach ($confirmProblems as $p) { note($p); }
ok($confirmProblems === [], sprintf('all %d destructive controls confirm, and only those do', $destructive));

echo "--- house rule 13: the compiled catalogue matches its source ---\n";
// gettext ships every message twice. luna.po is the source a translator edits; luna.mo is the
// binary the runtime actually loads, and it is the only one it loads — lunaTools::set_lang binds
// the domain and every lookup goes through gettext itself, which never reads the .po. Nothing in
// this repository regenerates one from the other, so an edit to a .po that is never recompiled
// changes nothing a visitor sees, and says so nowhere.
//
// That is not hypothetical. 0.8.34-alpha cleaned a dead domain out of both .po headers and left
// it sitting in both .mo, where it went on shipping to every visitor for thirty-three releases.
//
// It survived because the .mo is BINARY, and the acceptance check that would have caught it was
// a text search: `git grep -I` skips binary files silently — as does BSD grep without -a — so it
// reported the tree clean while the artifact still carried the string. A search that cannot read
// the file it is searching returns "clean" and "absent" as the same answer.
//
// mtime is deliberately NOT the test. git does not record it, so on a fresh clone or in CI every
// file carries checkout time and an "is the .mo newer than its .po" check passes vacuously in
// the one place it most needs to hold. This compares content.
//
// It compares the header too, which is the point: the 0.8.34 drift lived ENTIRELY in the header.
// Every msgid/msgstr pair was identical in both directions, so a check over pairs alone would
// have passed on the stale file and found nothing.

/** Every msgid => msgstr pair a .mo actually carries, read straight out of the binary. */
$readMo = static function (string $f): ?array {
	$d = (string) file_get_contents($f);
	if (strlen($d) < 20) { return null; }
	// The format tags its own byte order with the magic number; both orders occur in the wild.
	$magic = (int) unpack('V', substr($d, 0, 4))[1];
	if ($magic === 0x950412de) { $u = 'V'; } elseif ($magic === 0xde120495) { $u = 'N'; } else { return null; }
	$long = static fn (int $at): int => (int) unpack($u, substr($d, $at, 4))[1];
	$n = $long(8);
	$ids = $long(12);
	$strs = $long(16);
	$out = [];
	for ($i = 0; $i < $n; $i++) {
		$out[substr($d, $long($ids + $i * 8 + 4), $long($ids + $i * 8))]
			= substr($d, $long($strs + $i * 8 + 4), $long($strs + $i * 8));
	}
	return $out;
};

/**
 * The pairs a .po claims — i.e. what msgfmt would put in the .mo, for the singular, contextless
 * forms these catalogues use. `msgid_plural` and `msgctxt` are NOT understood: msgfmt keys those
 * as "id\0plural" and "ctx\x04id", which this would read as a key the .po never claimed and
 * report as staleness. Neither form appears in any catalogue here; if one is ever added, extend
 * this before trusting the result — do not relax the comparison to make the failure go away.
 */
$readPo = static function (string $f): array {
	$unescape = static fn (string $s): string
		=> strtr($s, ['\\n' => "\n", '\\t' => "\t", '\\r' => "\r", '\\"' => '"', '\\\\' => '\\']);
	$entries = [];
	$cur = ['id' => null, 'str' => null, 'fuzzy' => false];
	$field = null;
	foreach (file($f, FILE_IGNORE_NEW_LINES) as $line) {
		$t = trim($line);
		if ($t === '') {
			if ($cur['id'] !== null) { $entries[] = $cur; }
			$cur = ['id' => null, 'str' => null, 'fuzzy' => false];
			$field = null;
			continue;
		}
		// Obsolete entries are commented out with #~ and msgfmt drops them, so they are not
		// part of the claim. This must be tested before the general comment case.
		if (str_starts_with($t, '#~')) { $field = null; continue; }
		// Only the flag line `#,` carries fuzzy, and it carries a comma-separated LIST, so the
		// flag has to be matched as a whole item — `#, c-format, fuzzy` is fuzzy and `#, no-fuzzy`
		// is not. Reading the word out of any comment was the earlier mistake: a translator note
		// such as "# not sure, this is fuzzy" silently dropped a perfectly good entry from the
		// expectation, and the rule then reported the .mo as carrying a message the .po had lost.
		// A gate that fails on correct input is worse than no gate, because the way to make it
		// pass is to weaken it.
		if (str_starts_with($t, '#,')) {
			$flags = array_map('trim', explode(',', substr($t, 2)));
			if (in_array('fuzzy', $flags, true)) { $cur['fuzzy'] = true; }
			continue;
		}
		if (str_starts_with($t, '#')) { continue; }
		if (preg_match('/^msgid\s+"(.*)"$/', $t, $m)) { $cur['id'] = $unescape($m[1]); $field = 'id'; continue; }
		if (preg_match('/^msgstr\s+"(.*)"$/', $t, $m)) { $cur['str'] = $unescape($m[1]); $field = 'str'; continue; }
		// A long message is split across continuation lines; they belong to whichever of the
		// two fields opened last.
		if ($field !== null && preg_match('/^"(.*)"$/', $t, $m)) { $cur[$field] .= $unescape($m[1]); }
	}
	if ($cur['id'] !== null) { $entries[] = $cur; }
	$out = [];
	foreach ($entries as $e) {
		// msgfmt omits fuzzy and untranslated entries, so neither belongs in the expectation.
		// The header (msgid "") is not untranslated — its msgstr IS the header block.
		if ($e['fuzzy'] || (string) $e['str'] === '') { continue; }
		$out[(string) $e['id']] = (string) $e['str'];
	}
	return $out;
};

// msgfmt canonicalises the charset spelling on its way into the binary (utf-8 becomes UTF-8),
// so comparing the header verbatim would fail on a catalogue that is perfectly fresh. Fold that
// one value and nothing else: the rest of the header is compared exactly.
$foldCharset = static fn (string $s): string => (string) preg_replace_callback(
	'/charset=([\w-]+)/i',
	static fn (array $m): string => 'charset='.strtoupper($m[1]),
	$s
);

$catalogueProblems = [];
$catalogues = 0;
foreach (glob('luna/luna.domains/*/locale/*/LC_MESSAGES/*.po') as $po) {
	$label = implode('/', array_slice(explode('/', $po), -3));
	$mo = substr($po, 0, -3).'.mo';
	if (!is_file($mo)) { $catalogueProblems[] = "{$label}: no compiled .mo beside it"; continue; }
	$have = $readMo($mo);
	if ($have === null) { $catalogueProblems[] = "{$label}: the .mo is not a gettext catalogue"; continue; }
	$want = $readPo($po);
	$catalogues++;
	if (isset($want[''])) { $want[''] = $foldCharset($want['']); }
	if (isset($have[''])) { $have[''] = $foldCharset($have['']); }
	$name = static fn (string $id): string => $id === '' ? 'the header' : sprintf('"%s"', $id);
	foreach ($want as $id => $str) {
		if (!array_key_exists($id, $have)) {
			$catalogueProblems[] = sprintf('%s: .mo is missing %s', $label, $name($id));
		} elseif ($have[$id] !== $str) {
			$catalogueProblems[] = sprintf('%s: .mo disagrees with the .po on %s', $label, $name($id));
		}
	}
	foreach ($have as $id => $str) {
		if (!array_key_exists($id, $want)) {
			$catalogueProblems[] = sprintf('%s: .mo still carries %s, dropped from the .po', $label, $name($id));
		}
	}
}
// A rule that finds nothing passes for the wrong reason: moving the catalogues would leave this
// check satisfied over an empty set, which is the state it exists to make impossible.
if ($catalogues === 0) {
	$catalogueProblems[] = 'no .po/.mo pair found under luna/luna.domains/*/locale/ — has the layout moved?';
}
foreach (array_slice($catalogueProblems, 0, 12) as $p) { note($p); }
if (count($catalogueProblems) > 12) {
	note(sprintf('… and %d more — recompile with msgfmt', count($catalogueProblems) - 12));
}
ok($catalogueProblems === [], sprintf('all %d compiled catalogues carry exactly their source', $catalogues));

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
