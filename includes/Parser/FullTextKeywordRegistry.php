<?php

namespace CirrusSearch\Parser;

use CirrusSearch\Query\BoostTemplatesFeature;
use CirrusSearch\Query\ContentModelFeature;
use CirrusSearch\Query\DeepcatFeature;
use CirrusSearch\Query\FileNumericFeature;
use CirrusSearch\Query\FileTypeFeature;
use CirrusSearch\Query\HasTemplateFeature;
use CirrusSearch\Query\InCategoryFeature;
use CirrusSearch\Query\InSourceFeature;
use CirrusSearch\Query\InTitleFeature;
use CirrusSearch\Query\KeywordFeature;
use CirrusSearch\Query\LanguageFeature;
use CirrusSearch\Query\LinksToFeature;
use CirrusSearch\Query\LocalFeature;
use CirrusSearch\Query\MoreLikeFeature;
use CirrusSearch\Query\PreferRecentFeature;
use CirrusSearch\Query\PrefixFeature;
use CirrusSearch\Query\SimpleKeywordFeature;
use CirrusSearch\Query\SubPageOfFeature;
use CirrusSearch\SearchConfig;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

/**
 * Registry of keywords suited for fulltext searches
 */
class FullTextKeywordRegistry implements KeywordRegistry {
	/**
	 * @var KeywordFeature[]
	 */
	private $features;

	public function __construct( SearchConfig $config ) {
		$this->features = [
			// Handle morelike keyword (greedy). This needs to be the
			// very first item until combining with other queries
			// is worked out.
			new MoreLikeFeature( $config ),
			// Handle title prefix notation (greedy)
			new PrefixFeature(),
			// Handle prefer-recent keyword
			new PreferRecentFeature( $config ),
			// Handle local keyword
			new LocalFeature(),
			// Handle boost-templates keyword
			new BoostTemplatesFeature(),
			// Handle hastemplate keyword
			new HasTemplateFeature(),
			// Handle linksto keyword
			new LinksToFeature(),
			// Handle incategory keyword
			new InCategoryFeature( $config ),
			// Handle non-regex insource keyword
			new InSourceFeature( $config ),
			// Handle intitle keyword
			new InTitleFeature( $config ),
			// inlanguage keyword
			new LanguageFeature(),
			// File types
			new FileTypeFeature(),
			// File numeric characteristics - size, resolution, etc.
			new FileNumericFeature(),
			// Content model feature
			new ContentModelFeature(),
			// subpageof keyword
			new SubPageOfFeature(),
			// deepcat feature
			new DeepcatFeature( $config,
				MediaWikiServices::getInstance()->getService( 'CirrusCategoriesClient' ) ),
		];

		$extraFeatures = [];
		\Hooks::run( 'CirrusSearchAddQueryFeatures', [ $config, &$extraFeatures ] );
		foreach ( $extraFeatures as $extra ) {
			if ( $extra instanceof SimpleKeywordFeature ) {
				$this->features[] = $extra;
			} else {
				LoggerFactory::getInstance( 'CirrusSearch' )
					->warning( 'Skipped invalid feature of class ' . get_class( $extra ) .
							   ' - should be instanceof SimpleKeywordFeature' );
			}
		}
	}

	/**
	 * @return KeywordFeature[]
	 */
	public function getKeywords() {
		return $this->features;
	}
}
