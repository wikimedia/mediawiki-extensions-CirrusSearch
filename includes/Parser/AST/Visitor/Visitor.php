<?php

namespace CirrusSearch\Parser\AST\Visitor;

use CirrusSearch\Parser\AST\BooleanClause;
use CirrusSearch\Parser\AST\EmptyQueryNode;
use CirrusSearch\Parser\AST\FuzzyNode;
use CirrusSearch\Parser\AST\KeywordFeatureNode;
use CirrusSearch\Parser\AST\NamespaceHeaderNode;
use CirrusSearch\Parser\AST\NegatedNode;
use CirrusSearch\Parser\AST\ParsedBooleanNode;
use CirrusSearch\Parser\AST\PhrasePrefixNode;
use CirrusSearch\Parser\AST\PhraseQueryNode;
use CirrusSearch\Parser\AST\PrefixNode;
use CirrusSearch\Parser\AST\WildcardNode;
use CirrusSearch\Parser\AST\WordsQueryNode;

/**
 * AST Visitor
 */
interface Visitor {

	/**
	 * @param ParsedBooleanNode $node
	 * @return void
	 */
	public function visitParsedBooleanNode( ParsedBooleanNode $node );

	/**
	 * @param BooleanClause $clause
	 * @return void
	 */
	public function visitBooleanClause( BooleanClause $clause );

	/**
	 * @param WordsQueryNode $node
	 * @return void
	 */
	public function visitWordsQueryNode( WordsQueryNode $node );

	/**
	 * @param PhraseQueryNode $node
	 * @return void
	 */
	public function visitPhraseQueryNode( PhraseQueryNode $node );

	/**
	 * @param PhrasePrefixNode $node
	 * @return void
	 */
	public function visitPhrasePrefixNode( PhrasePrefixNode $node );

	/**
	 * @param NegatedNode $node
	 * @return void
	 */
	public function visitNegatedNode( NegatedNode $node );

	/**
	 * @param FuzzyNode $node
	 * @return void
	 */
	public function visitFuzzyNode( FuzzyNode $node );

	/**
	 * @param PrefixNode $node
	 * @return void
	 */
	public function visitPrefixNode( PrefixNode $node );

	/**
	 * @param WildcardNode $node
	 * @return void
	 */
	public function visitWildcardNode( WildcardNode $node );

	/**
	 * @param EmptyQueryNode $node
	 * @return void
	 */
	public function visitEmptyQueryNode( EmptyQueryNode $node );

	/**
	 * @param KeywordFeatureNode $node
	 * @return void
	 */
	public function visitKeywordFeatureNode( KeywordFeatureNode $node );

	/**
	 * @param NamespaceHeaderNode $node
	 * @return void
	 */
	public function visitNamespaceHeader( NamespaceHeaderNode $node );
}
