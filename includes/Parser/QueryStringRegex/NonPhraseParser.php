<?php

namespace CirrusSearch\Parser\QueryStringRegex;

use CirrusSearch\Parser\AST\FuzzyNode;
use CirrusSearch\Parser\AST\NegatedNode;
use CirrusSearch\Parser\AST\ParsedNode;
use CirrusSearch\Parser\AST\PrefixNode;
use CirrusSearch\Parser\AST\WildcardNode;
use CirrusSearch\Parser\AST\WordsQueryNode;
use CirrusSearch\Search\Escaper;

/**
 * Parse non-phrase query parts.
 * Emit a ParsedQueryStringNode if lucene QueryString syntax is detected
 * A WordsQueryNode otherwise.
 */
class NonPhraseParser {

	/**
	 * Consume non quoted chars (negated phrase queries as well)
	 * allows:
	 * - all escaped sequences
	 * - !- only if they are not followed by " (accepts $ to consume !- at the end of the string)
	 * - stops at first ", ! or -
	 *
	 * Detects prefixed negation but ignores negation if not followed by a letter, a number or _
	 *   -word: properly negated
	 *   --word: eaten as "--word"
	 *
	 * few markups are added
	 */
	const NON_QUOTE = '/\G(?<negated>[-!](?=[\w]))?(?<word>(?:\\\\.|[!-](?!")|[^"!\pZ\pC-])+)/u';

	/**
	 * Detect simple prefix nodes
	 * only letters and number allowed
	 */
	const PREFIX_QUERY = '/^(?<prefix>\w+)[*]+$/u';

	/**
	 * Wildcards disallowed at the beginning
	 * we arbitrarily allow 3 wildcards to avoid catching random garbage
	 * and too costly queries.
	 */
	const DISALLOWED_LEADING_WILDCARD = '/^(?:\w+[?*]){1,3}\w*$/u';

	/**
	 * Wildcards allowed at the beginning
	 * but we still force the wildcards to be surrounded by letters
	 * we allow only 3 wildcards
	 */
	const ALLOWED_LEADING_WILDCARD = '/^(?:(?:[?*](?=\w)(?:\w+[?*]|\w+){1,2}\w*)|(?:(?:\w+[?*]){1,3}\w*))$/u';

	/**
	 * We force fuzzy words to have letters in them
	 * NOTE that we disallow * or ? here so we can't
	 * match fuzzy and wildcard at the same time
	 */
	const FUZZY_WORD = '/^(?<word>\w+)~(?<fuzzyness>[0-2])?$/u';

	/**
	 * @var Escaper $escaper
	 */
	private $escaper;

	/**
	 * @var string regex used to detect wildcards
	 */
	private $wildcardRegex;
	/**
	 * @param Escaper $escaper
	 */
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
	 * @return ParsedNode|null
	 */
	public function parse( $query, $start ) {
		if ( preg_match( self::NON_QUOTE, $query, $match, PREG_OFFSET_CAPTURE, $start ) === 1 ) {
			$wholeStart = $match[0][1];
			$wholeEnd = $wholeStart + strlen( $match[0][0] );
			$wordLen = strlen( $match['word'][0] );
			$wordStart = $match['word'][1];
			$wordEnd = $wordStart + $wordLen;
			$negationType = '';
			if ( isset( $match['negated'] ) && $match['negated'][1] >= 0 ) {
				$negationType = $match['negated'][0];
			}
			$word = substr( $query, $wordStart, $wordLen );
			$match = [];
			$node = null;
			if ( strpos( $word, '~' ) !== -1 && preg_match( self::FUZZY_WORD, $word, $match ) === 1 ) {
				$word = $match['word'];
				if ( isset( $match['fuzzyness'] ) && strlen( $match['fuzzyness'] ) > 0 ) {
					$fuzzyness = intval( $match['fuzzyness'] );
				} else {
					$fuzzyness = -1;
				}
				// No need to unescape here, we don't match any punctuation except_
				$node = new FuzzyNode( $wordStart, $wordEnd, $word, $fuzzyness );
			} elseif ( strpos( $word, '*' ) !== -1 || strpos( $word, '?' ) != -1 ) {
				if ( preg_match( self::PREFIX_QUERY, $word, $match ) === 1 ) {
					$node = new PrefixNode( $wordStart, $wordEnd, $match['prefix'] );
				} elseif ( preg_match( $this->wildcardRegex, $word ) === 1 ) {
					$node = new WildcardNode( $wordStart, $wordEnd, $word );
				}
			}

			if ( $node === null ) {
				$node = new WordsQueryNode( $wordStart, $wordEnd, $this->escaper->unescape( $word ) );
			}
			if ( $negationType !== '' ) {
				$node = new NegatedNode( $wholeStart, $wholeEnd, $node, $negationType );
			}
			return $node;
		}
		return null;
	}
}
