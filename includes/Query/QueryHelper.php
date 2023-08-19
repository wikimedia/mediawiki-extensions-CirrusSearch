<?php

namespace CirrusSearch\Query;

use MediaWiki\Category\Category;
use MediaWiki\Title\Title;

/**
 * helpers for building queries
 */
class QueryHelper {
	/**
	 * Builds a match query against $field for $title. $title is munged to make
	 * title matching better more intuitive for users.
	 *
	 * @param string $field field containing the title
	 * @param string $title title query text to match against
	 * @param bool $underscores If the field contains underscores instead of
	 *  spaces. Defaults to false.
	 * @return \Elastica\Query\MatchQuery For matching $title to $field
	 */
	public static function matchPage( $field, $title, $underscores = false ) {
		$t = Title::newFromText( $title );
		if ( $t ) {
			$title = $t->getPrefixedText();
		}
		if ( $underscores ) {
			$title = str_replace( ' ', '_', $title );
		}
		$match = new \Elastica\Query\MatchQuery();
		$match->setFieldQuery( $field, $title );

		return $match;
	}

	/**
	 * Builds a match query against $field for $name. $name is munged to make
	 * category matching better more intuitive for users.
	 *
	 * @param string $field field containing the title
	 * @param string $name title query text to match against
	 *  spaces. Defaults to false.
	 * @return \Elastica\Query\MatchQuery For matching $title to $field
	 */
	public static function matchCategory( $field, $name ): \Elastica\Query\MatchQuery {
		$c = Category::newFromName( $name );
		if ( $c ) {
			$name = $c->getName();
		}

		$name = str_replace( '_', ' ', $name );
		$match = new \Elastica\Query\MatchQuery();
		$match->setFieldQuery( $field, $name );

		return $match;
	}
}
