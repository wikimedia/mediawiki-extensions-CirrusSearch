<?php

namespace CirrusSearch\Parser\QueryStringRegex;

use CirrusSearch\Parser\AST\FuzzyNode;
use CirrusSearch\Parser\AST\NegatedNode;
use CirrusSearch\Parser\AST\ParsedNode;
use CirrusSearch\Parser\AST\PrefixNode;
use CirrusSearch\Parser\AST\WildcardNode;
use CirrusSearch\Parser\AST\WordsQueryNode;
use CirrusSearch\Search\Escaper;
use Wikimedia\Assert\Assert;

/**
 * Parse non-phrase query parts.
 * Emit a ParsedQueryStringNode if lucene QueryString syntax is detected
 * A WordsQueryNode otherwise.
 */
class NonPhraseParser {

	/**
	 * Detects prefixed negation but ignores negation if not followed by a letter, a number or _
	 *   -word: properly negated
	 *   --word: eaten as "--word"
	 *
	 * few markups are added
	 */
	private const NEGATION = '/\G[-!](?=[\w])/u';

	/**
	 * Consume non quoted chars (negated phrase queries as well)
	 * allows:
	 * - all escaped sequences
	 * - !- only if they are not followed by " (accepts $ to consume !- at the end of the string)
	 * - stops at first ", ! or -
	 */
	private const NON_QUOTE = '/\\\\.|[!-](?!")|(?<stop>["!\pZ\pC-])/u';

	/**
	 * Detect simple prefix nodes
	 * only letters and number allowed
	 */
	private const PREFIX_QUERY = '/^(?<prefix>\w+)[*]+$/u';

	/**
	 * Wildcards disallowed at the beginning
	 * we arbitrarily allow 3 wildcards to avoid catching random garbage
	 * and too costly queries.
	 */
	private const DISALLOWED_LEADING_WILDCARD = '/^(?:\w+[?*]){1,3}\w*$/u';

	/**
	 * Wildcards allowed at the beginning
	 * but we still force the wildcards to be surrounded by letters
	 * we allow only 3 wildcards
	 */
	private const ALLOWED_LEADING_WILDCARD = '/^(?:(?:[?*](?=\w)(?:\w+[?*]|\w+){1,2}\w*)|(?:(?:\w+[?*]){1,3}\w*))$/u';

	/**
	 * We force fuzzy words to have letters in them
	 * NOTE that we disallow * or ? here so we can't
	 * match fuzzy and wildcard at the same time
	 */
	private const FUZZY_WORD = '/^(?<word>\w+)~(?<fuzzyness>[0-2])?$/u';

	/**
	 * @var Escaper
	 */
	private $escaper;

	/**
	 * @var string regex used to detect wildcards
	 */
	private $wildcardRegex;

	public function __construct( Escaper $escaper ) {
		$this->escaper = $escaper;
		if ( $this->escaper->getAllowLeadingWildcard() ) {
			$this->wildcardRegex = self::ALLOWED_LEADING_WILDCARD;
		} else {
			$this->wildcardRegex = self::DISALLOWED_LEADING_WILDCARD;
		}
	}

	/**
	 * @param string $query
	 * @param int $start
	 * @param int $end
	 * @return ParsedNode|null
	 */
	public function parse( string $query, int $start, int $end ) {
		$match = [];
		Assert::precondition( $start < $end, '$start < $end' );
		Assert::precondition( $end <= strlen( $query ), '$end <= strlen( $query )' );
		$ret = preg_match( self::NEGATION, $query, $match, PREG_OFFSET_CAPTURE, $start );
		Assert::postcondition( $ret !== false, 'Regex failed: ' . preg_last_error() );
		$wholeStart = $start;
		$wordStart = $start;
		$negationType = '';
		if ( $ret ) {
			$wordStart = $start + strlen( $match[0][0] );
			$negationType = $match[0][0];
			$start = $match[0][1];
		}
		$wholeEnd = -1;

		while ( $start < $end ) {
			$ret = preg_match( self::NON_QUOTE, $query, $match, PREG_OFFSET_CAPTURE, $start );
			Assert::postcondition( $ret !== false, 'Regex failed: ' . preg_last_error() );
			if ( !$ret ) {
				$wholeEnd = $end;
				break;
			}
			if ( isset( $match['stop'] ) && $match['stop'][1] >= 0 ) {
				$wholeEnd = $match['stop'][1];
				break;
			}
			$start = $match[0][1] + strlen( $match[0][0] );
			$wholeEnd = $start;
		}

		if ( $wholeEnd == $wordStart ) {
			return null;
		}

		$wordLen = $wholeEnd - $wordStart;
		$word = substr( $query, $wordStart, $wordLen );
		$node = null;
		$match = [];
		if ( str_contains( $word, '~' ) && preg_match( self::FUZZY_WORD, $word, $match ) ) {
			$word = $match['word'];
			if ( isset( $match['fuzzyness'] ) && strlen( $match['fuzzyness'] ) > 0 ) {
				$fuzzyness = intval( $match['fuzzyness'] );
			} else {
				$fuzzyness = -1;
			}
			// No need to unescape here, we don't match any punctuation except_
			$node = new FuzzyNode( $wordStart, $wholeEnd, $word, $fuzzyness );
		} elseif ( str_contains( $word, '*' ) || str_contains( $word, '?' ) ) {
			if ( preg_match( self::PREFIX_QUERY, $word, $match ) ) {
				$node = new PrefixNode( $wordStart, $wholeEnd, $match['prefix'] );
			} elseif ( preg_match( $this->wildcardRegex, $word ) ) {
				$node = new WildcardNode( $wordStart, $wholeEnd, $word );
			}
		}

		$node ??= new WordsQueryNode( $wordStart, $wholeEnd, $this->escaper->unescape( $word ) );
		if ( $negationType !== '' ) {
			$node = new NegatedNode( $wholeStart, $wholeEnd, $node, $negationType );
		}
		return $node;
	}
}
