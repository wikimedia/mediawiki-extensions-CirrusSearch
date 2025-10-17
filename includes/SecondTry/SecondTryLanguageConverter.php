<?php

namespace CirrusSearch\SecondTry;

use MediaWiki\Language\ILanguageConverter;

class SecondTryLanguageConverter implements SecondTrySearch {
	private ILanguageConverter $converter;
	private int $topK;

	public static function build( ILanguageConverter $converter, array $config ): SecondTryLanguageConverter {
		return new self( $converter, $config['top_k'] ?? 3 );
	}

	/**
	 * @param ILanguageConverter $converter the converter to use
	 * @param int $topK the max number of variants to keep
	 */
	public function __construct( ILanguageConverter $converter, int $topK = 3 ) {
		$this->converter = $converter;
		$this->topK = $topK;
	}

	/**
	 * @inheritDoc
	 */
	public function candidates( string $searchQuery ): array {
		$candidates = $this->converter->autoConvertToAllVariants( $searchQuery );
		if ( count( $candidates ) > $this->topK ) {
			   // We should not allow too many variants
			   $candidates = array_slice( $candidates, 0, $this->topK );
		}
		return $candidates;
	}
}
