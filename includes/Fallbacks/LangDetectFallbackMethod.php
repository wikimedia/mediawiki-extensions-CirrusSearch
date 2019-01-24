<?php

namespace CirrusSearch\Fallbacks;

use CirrusSearch\InterwikiResolver;
use CirrusSearch\LanguageDetector\Detector;
use CirrusSearch\LanguageDetector\LanguageDetectorFactory;
use CirrusSearch\Parser\BasicQueryClassifier;
use CirrusSearch\Search\ResultSet;
use CirrusSearch\Search\SearchMetricsProvider;
use CirrusSearch\Search\SearchQuery;
use CirrusSearch\Search\SearchQueryBuilder;
use CirrusSearch\SearchConfig;
use CirrusSearch\Searcher;
use MediaWiki\MediaWikiServices;
use Wikimedia\Assert\Assert;

class LangDetectFallbackMethod implements FallbackMethod, SearchMetricsProvider {
	use FallbackMethodTrait;

	/**
	 * @var SearchQuery
	 */
	private $query;

	/**
	 * @var SearcherFactory
	 */
	private $searcherFactory;

	/**
	 * @var array|null
	 */
	private $searchMetrics = [];

	/**
	 * @var Detector[]
	 */
	private $detectors;

	/**
	 * @var InterwikiResolver
	 */
	private $interwikiResolver;

	/**
	 * @var SearchConfig|null
	 */
	private $detectedLangWikiConfig;

	/**
	 * @var int
	 */
	private $threshold;

	/**
	 * LangDetectFallbackMethod constructor.
	 * @param SearchQuery $query
	 * @param SearcherFactory $searcherFactory
	 * @param Detector[] $detectors
	 * @param InterwikiResolver|null $interwikiResolver
	 */
	public function __construct(
		SearchQuery $query,
		SearcherFactory $searcherFactory,
		array $detectors,
		InterwikiResolver $interwikiResolver = null
	) {
		$this->query = $query;
		$this->searcherFactory = $searcherFactory;
		$this->detectors = $detectors;
		$this->interwikiResolver =
			$interwikiResolver ??
			MediaWikiServices::getInstance()->getService( InterwikiResolver::SERVICE );
		$this->threshold = $query->getSearchConfig()->get( 'CirrusSearchInterwikiThreshold' );
	}

	/**
	 * @param SearcherFactory $factory
	 * @param SearchQuery $query
	 * @return FallbackMethod
	 */
	public static function build( SearcherFactory $factory, SearchQuery $query ) {
		$langDetectFactory = new LanguageDetectorFactory( $query->getSearchConfig() );
		return new self( $query, $factory, $langDetectFactory->getDetectors() );
	}

	/**
	 * @param ResultSet $firstPassResults
	 * @return float
	 */
	public function successApproximation( ResultSet $firstPassResults ) {
		if ( !$this->query->isAllowRewrite() ) {
			return 0.0;
		}
		if ( !$this->query->getCrossSearchStrategy()->isCrossLanguageSearchSupported() ) {
			return 0.0;
		}
		if ( $this->resultsThreshold( $firstPassResults, $this->threshold ) ) {
			return 0.0;
		}
		if ( !$this->query->getParsedQuery()->isQueryOfClass( BasicQueryClassifier::SIMPLE_BAG_OF_WORDS ) ) {
			return 0.0;
		}
		foreach ( $this->detectors as $name => $detector ) {
			$lang = $detector->detect( $this->query->getParsedQuery()->getRawQuery() );
			if ( $lang === null ) {
				continue;
			}
			if ( $lang === $this->query->getSearchConfig()->get( 'LanguageCode' ) ) {
				// The query is in the wiki language so we
				// don't need to actually try another wiki.
				// Note that this may not be very accurate for
				// wikis that use deprecated language codes
				// but the interwiki resolver should not return
				// ourselves.
				continue;
			}
			$iwPrefixAndConfig = $this->interwikiResolver->getSameProjectConfigByLang( $lang );
			if ( !empty( $iwPrefixAndConfig ) ) {
				// it might be more accurate to attach these to the 'next'
				// log context? It would be inconsistent with the
				// langdetect => false condition which does not have a next
				// request though.
				Searcher::appendLastLogPayload( 'langdetect', $name );
				$prefix = key( $iwPrefixAndConfig );
				$config = $iwPrefixAndConfig[$prefix];
				$metric = [ $config->getWikiId(), $prefix ];
				$this->searchMetrics['wgCirrusSearchAltLanguage'] = $metric;
				$this->detectedLangWikiConfig = $config;
				return 0.5;
			}
		}
		Searcher::appendLastLogPayload( 'langdetect', 'failed' );
		return 0.0;
	}

	/**
	 * @param ResultSet $firstPassResults
	 * @param ResultSet $previousSet
	 * @return ResultSet
	 */
	public function rewrite( ResultSet $firstPassResults, ResultSet $previousSet ) {
		Assert::precondition( $this->detectedLangWikiConfig !== null,
			'nothing has been detected, this should not even be tried.' );

		if ( $this->resultsThreshold( $previousSet, $this->threshold ) ) {
			return $previousSet;
		}

		$crossLangQuery = SearchQueryBuilder::forCrossLanguageSearch( $this->detectedLangWikiConfig,
			$this->query )->build();
		$searcher = $this->searcherFactory->makeSearcher( $crossLangQuery );
		$status = $searcher->search( $crossLangQuery );
		if ( !$status->isOK() ) {
			return $previousSet;
		}
		$crossLangResults = $status->getValue();
		if ( !$crossLangResults instanceof ResultSet ) {
			// NOTE: Can/should this happen?
			return $previousSet;
		}
		$this->searchMetrics['wgCirrusSearchAltLanguageNumResults'] = $crossLangResults->numRows();
		if ( $crossLangResults->numRows() > 0 ) {
			$previousSet->addInterwikiResults( $crossLangResults,
				\SearchResultSet::INLINE_RESULTS, $this->detectedLangWikiConfig->getWikiId() );
		}
		return $previousSet;
	}

	public function getMetrics() {
		return $this->searchMetrics;
	}
}
