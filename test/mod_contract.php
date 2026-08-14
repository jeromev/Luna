<?php

/**
 * The module contract, checked rather than assumed.
 *
 * luna::load_mods() resolves a module's class name from the database `lid` column and calls
 * it by string — `call_user_func($lid.'::singleton')`, `method_exists($lid, 'submit_delete')`.
 * That makes the filename/classname correspondence a runtime invariant with no compile-time
 * signal, and it is the reason docs/coding-style.md keeps snake_case methods and
 * lowercase-first class names as a named deviation rather than a preference.
 *
 * The one place a contributor is invited to extend the system is the one place with no
 * machine-checkable contract: a typo like `submit_modfiy()` produces a form submission that
 * is silently ignored, because the dispatcher only ever asks method_exists().
 *
 * Pure PHP: no database, no running stack. Run with `make lint-house`.
 *
 * PHP 8.1+ (tested on 8.3)
 *
 * @author  Jérôme Vogel
 * @license http://www.gnu.org/copyleft/gpl.html  GPL
 * @link    https://github.com/jeromev/LunarSystem
 */

chdir(dirname(__DIR__));

$fails = 0;
$checks = 0;
function ok(bool $cond, string $label): void {
	global $fails, $checks;
	$checks++;
	printf($cond ? "  \033[32mPASS\033[0m %s\n" : "  \033[31mFAIL\033[0m %s\n", $label);
	if (!$cond) { $fails++; }
}

/** The hooks the dispatcher will call by name if they exist. A typo means silence. */
const KNOWN_HOOKS = ['load', 'submit', 'submit_add', 'submit_modify', 'submit_delete'];

$mods = glob('luna/luna.mods/*.php');
ok($mods !== [], sprintf('found %d module files', count($mods)));

foreach ($mods as $file) {
	$base = basename($file);                                  // luna.mod_admin_users.php
	$expected = substr($base, strlen('luna.'), -strlen('.php')); // mod_admin_users
	$src = (string) file_get_contents($file);

	preg_match_all('/^\s*(?:final\s+|abstract\s+)?class\s+(\w+)/m', $src, $cm);
	$classes = $cm[1];

	ok(count($classes) === 1, sprintf('%s declares exactly one class (%d)', $base, count($classes)));
	if (count($classes) !== 1) { continue; }

	// This is the invariant the database-driven dispatcher depends on.
	ok($classes[0] === $expected, sprintf('%s declares class %s (expected %s)', $base, $classes[0], $expected));

	// Capture the whole modifier run rather than one ordering: `public final`, `final public`
	// and `static public` are all legal, and a method the regex cannot see is a method none of
	// the checks below apply to.
	preg_match_all('/^\s*((?:(?:final|abstract|public|private|protected|static)\s+)*)function\s+(\w+)\s*\(/m', $src, $mm, PREG_SET_ORDER);
	$methods = [];
	$unqualified = [];
	foreach ($mm as $m) {
		$methods[] = $m[2];
		$mods = preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY);
		if (array_intersect($mods, ['public', 'private', 'protected']) === []) { $unqualified[] = $m[2]; }
	}

	ok(in_array('singleton', $methods, true), sprintf('%s has singleton()', $base));
	ok(in_array('load', $methods, true), sprintf('%s has load()', $base));
	ok($unqualified === [], sprintf(
		'%s: every method has an explicit visibility keyword%s',
		$base,
		$unqualified === [] ? '' : ' — missing on '.implode(', ', $unqualified).'()'
	));

	// A hook that is nearly-but-not-quite a known name is the failure this file exists for.
	foreach ($methods as $name) {
		if (!str_starts_with($name, 'submit') || in_array($name, KNOWN_HOOKS, true)) { continue; }
		$near = null;
		foreach (KNOWN_HOOKS as $hook) {
			if (levenshtein($name, $hook) <= 2) { $near = $hook; break; }
		}
		ok($near === null, sprintf('%s: %s() is not a near-miss of %s()', $base, $name, (string) $near));
	}
}

printf("\n%s\n", $fails === 0
	? sprintf("\033[32mMODULE CONTRACT HOLDS\033[0m (%d checks)", $checks)
	: sprintf("\033[31m%d OF %d CHECK(S) FAILED\033[0m", $fails, $checks));
exit($fails === 0 ? 0 : 1);
