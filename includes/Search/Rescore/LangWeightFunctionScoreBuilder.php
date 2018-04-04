<?php

namespace CirrusSearch\Search\Rescore;

use CirrusSearch\SearchConfig;
use Elastica\Query\FunctionScore;

/**
 * Boosts documents in user language and in wiki language if different
 * Uses getUserLanguage in SearchConfig and LanguageCode for language values
 * and CirrusSearchLanguageWeight['user'|'wiki'] for respective weights.
 */
class LangWeightFunctionScoreBuilder extends FunctionScoreBuilder {
	/**
	 * @var string user language
	 */
	private $userLang;

	/**
	 * @var float user language weight
	 */
	private $userWeight;

	/**
	 * @var string wiki language
	 */
	private $wikiLang;

	/**
	 * @var float wiki language weight
	 */
	private $wikiWeight;

	/**
	 * @param SearchConfig $config
	 * @param float $weight
	 */
	public function __construct( SearchConfig $config, $weight ) {
		parent::__construct( $config, $weight );
		$this->userLang = $config->getUserLanguage();
		$this->userWeight =
			$config->getElement( 'CirrusSearchLanguageWeight', 'user' );
		$this->wikiLang = $config->get( 'LanguageCode' );
		$this->wikiWeight =
			$config->getElement( 'CirrusSearchLanguageWeight', 'wiki' );
	}

	public function append( FunctionScore $functionScore ) {
		// Boost pages in a user's language
		if ( $this->userWeight ) {
			$functionScore->addWeightFunction( $this->userWeight * $this->weight,
				new \Elastica\Query\Term( [ 'language' => $this->userLang ] ) );
		}

		// And a wiki's language, if it's different
		if ( $this->wikiWeight && $this->userLang != $this->wikiLang ) {
			$functionScore->addWeightFunction( $this->wikiWeight * $this->weight,
				new \Elastica\Query\Term( [ 'language' => $this->wikiLang ] ) );
		}
	}
}
