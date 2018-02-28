<?php

namespace CirrusSearch\Query;

use CirrusSearch\Search\SearchContext;

interface KeywordFeature {
	/**
	 * List of keyword strings this implementation consumes
	 * @return string[]
	 */
	public function getKeywordPrefixes();

	/**
	 * Whether this keyword allows empty value.
	 * @return bool true to allow the keyword to appear in an empty form
	 */
	public function allowEmptyValue();

	/**
	 * Whether this keyword can have a value
	 * @return bool
	 */
	public function hasValue();

	/**
	 * Whether this keyword is greedy consuming the rest of the string.
	 * NOTE: do not use, greedy keywords will eventually be removed in the future
	 * @return bool
	 */
	public function greedy();

	/**
	 * Whether this keyword can appear only at the beginning of the query
	 * (excluding spaces)
	 * @return bool
	 */
	public function queryHeader();

	/**
	 * Determine the name of the feature being set in SearchContext::addSyntaxUsed
	 * Defaults to $key
	 *
	 * @param string $key
	 * @param string $valueDelimiter the delimiter used to wrap the value
	 * @return string
	 *  '"' when parsing keyword:"test"
	 *  '' when parsing keyword:test
	 */
	public function getFeatureName( $key, $valueDelimiter );

	/**
	 * List of value delimiters supported (must be an array of single byte char)
	 * @return string[][] list of delimiters options
	 */
	public function getValueDelimiters();

	/**
	 * Checks $term for usage of the feature, and applies necessary filters,
	 * rescores, etc. to the provided $context. The returned $term will be
	 * passed on to other keyword features, and eventually to an elasticsearch
	 * QueryString query.
	 *
	 * @param SearchContext $context
	 * @param string $term The input search query
	 * @return string The remaining search query after processing
	 */
	public function apply( SearchContext $context, $term );
}
