<?php
/**
 * Luna house style — PSR-12 with seven named deviations.
 *
 * The deviations and the reasoning for each are in docs/coding-style.md. This file is the
 * machine that holds them; that document is the one that explains them. Keep them in step.
 *
 * INDENTATION IS A TAB. php-cs-fixer does NOT read .editorconfig, so the tab decision lives
 * in two places — ->setIndent("\t") below, and .editorconfig's [*.php] block.
 *
 * @author  Jérôme Vogel
 * @license http://www.gnu.org/copyleft/gpl.html  GPL
 * @link    https://github.com/jeromev/Luna
 */

// Finder::in() takes DIRECTORIES; single files go in append(). Listing directories rather
// than files means a new first-party file is covered the day it is created.
//
// Nothing vendored is reachable from here, and that is by construction rather than by an
// exclude() call: luna/luna.lib (ARC2) is a SIBLING of luna/luna.classes and luna/luna.mods,
// vendor/ is at the root, and tools/vendor/ is avoided by appending the two first-party
// tools/ scripts individually instead of scanning tools/. exclude() would not have worked —
// it resolves relative to the in() roots.
$finder = PhpCsFixer\Finder::create()
	->in([
		__DIR__.'/bin',
		__DIR__.'/luna/luna.classes',
		__DIR__.'/luna/luna.mods',
		__DIR__.'/test',
	])
	->append([
		__DIR__.'/index.php',
		__DIR__.'/luna/luna.php',
		__DIR__.'/tools/phpstan-constants.php',
		__DIR__.'/tools/token-diff.php',
	])
	->name('*.php');

return (new PhpCsFixer\Config())
	->setIndent("\t")          // Deviation 1 — PSR-12 §2.4 asks for four spaces
	->setLineEnding("\n")
	->setRiskyAllowed(false)   // nothing that can change behaviour runs unattended
	->setFinder($finder)
	->setRules([
		'@PSR12' => true,

		// Deviations 2 and 3 — braces stay on the same line (classes and functions included),
		// and one-line bodies stay on one line.
		//
		// These two rules are what PSR-12 uses to enforce both, and they are entangled:
		// braces_position has no "allow single line control structure" option, so any setting
		// of it expands `if (x) { y; }` across three lines. Switching both off keeps the tree
		// as it is — which is already unanimous on same-line braces — and statement_indentation
		// (still on) continues to fix genuinely wrong indentation.
		//
		// Cost of not deviating, measured: enabling all three is about +2,000 lines, a fifth of
		// the tree — and braces_position alone accounts for nearly all of it, because it is the
		// single rule that both moves the brace and expands the one-line body. The ~700 guard
		// clauses are inside that figure, not on top of it. It would also falsify the sample
		// block in docs/modules.md that every new module is copied from.
		'braces_position' => false,
		'class_definition' => false,

		// Same deviation, third rule. With one-line bodies kept, this rule does not expand
		// `if (x) { a(); b(); }` — it splits it in half, leaving the first statement on the
		// `if` line and the second on its own, closing brace trailing:
		//
		//     if (PHP_SAPI !== 'cli') { fwrite(STDERR, "…");
		//         exit(1); }
		//
		// which is worse than either whole form. It did that to 46 guard clauses. Off.
		'no_multiple_statements_per_line' => false,

		// Empty bodies still collapse: `function __clone() {}` needs no two lines.
		'single_line_empty_body' => true,

		// Deviation 4 — concatenation is tight, everything else is spaced. 1,812 tight
		// concatenations and none spaced; this is Symfony's published exception too.
		'concat_space' => ['spacing' => 'none'],

		// --- token tier ------------------------------------------------------------------
		// These change real tokens, so `make token-diff` cannot vouch for them; they are
		// proved by the render baseline and the write-path suites instead. All are PSR-12
		// and all are inherited from @PSR12 — listed here only to record that they were
		// applied deliberately and separately from the whitespace tier.
		//   no_closing_tag        drops the trailing close tag (verified: no file emits output after it)
		//   elseif                `else if` -> `elseif`
		//   constant_case         `NULL`/`FALSE` -> lowercase
		//   new_with_parentheses  `new $c` -> `new $c()`
		//   modifier_keywords     normalises visibility/static order

		// Short array syntax. NOT PSR-12 — PSR-12 is silent on array syntax — and not one of
		// the seven deviations; it is a deliberate modernisation, applied in its own commit so
		// the choice stays visible in the history rather than arriving inside a whitespace pass.
		'array_syntax' => ['syntax' => 'short'],

		// @access is deprecated (phpDocumentor 2) and PHP's own visibility keyword already says it.
		// Enforced rather than swept: two survived a hand-written sweep by hiding on a docblock's
		// opening line, which is exactly the kind of thing a rule catches and a regex does not.
		'phpdoc_no_access' => true,

		// Docblock hygiene, now that the docblocks say true things and are worth aligning.
		'phpdoc_scalar' => true,
		'phpdoc_indent' => true,
		'phpdoc_trim' => true,
		'no_empty_phpdoc' => true,
		'no_superfluous_phpdoc_tags' => false, // would strip tags we still rely on pre-types
	]);
