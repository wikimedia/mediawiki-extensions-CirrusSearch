<?php

namespace CirrusSearch;

use Html;
use HTMLFormField;

/**
 * Completion Suggester preferences UI.
 * Select the profile used by search autocompletion.
 */
class HTMLCompletionProfileSettings extends HTMLFormField {
	/** @var string[] profiles available */
	private $profiles;

	/** @var string[] Order in which we propose comp suggest profiles */
	private $compProfilesPreferedOrder = [
		'fuzzy',
		'fuzzy-subphrases',
		'strict',
		'normal',
		'normal-subphrases',
	];

	public function __construct( $params ) {
		parent::__construct( $params );

		$this->profiles = [];
		foreach( $params['profiles'] as $prof ) {
			$this->profiles[] = $prof['name'];
		}
	}

	/**
	 * @param string $value
	 * @return string
	 */
	function getInputHTML( $value ) {
		$html = Html::openElement( 'div' );
		$html .= Html::element( 'legend',
			[],
			wfMessage( 'cirrussearch-pref-completion-profile-help' )
		);

		$html .= Html::element( 'strong',
			[],
			wfMessage( 'cirrussearch-pref-completion-section-desc' )->text()
		);
		$html .= Html::rawElement( 'legend',
			[],
			wfMessage( 'cirrussearch-pref-completion-section-legend' )->parse()
		);
		foreach( $this->compProfilesPreferedOrder as $prof ) {
			if ( in_array( $prof, $this->profiles ) ) {
				$html .= $this->addCompSuggestOption( $prof, $value );
			}
		}

		$html .= Html::element( 'strong',
			[],
			wfMessage( 'cirrussearch-pref-completion-legacy-section-desc' )->text()
		);
		$html .= Html::rawElement( 'legend',
			[],
			wfMessage( 'cirrussearch-pref-completion-legacy-section-legend' )->parse()
		);
		$html .= $this->addCompSuggestOption( 'classic', $value );
		$html .= Html::closeElement( 'div' );
		return $html;
	}

	/**
	 * @param string $prof profile name
	 * @param string $value selected profile name
	 * @return string html
	 */
	private function addCompSuggestOption( $prof, $value ) {
		$html = Html::openElement( 'div' );
		$html .= Html::openElement( 'div', [ 'style' => 'vertical-align:top; display:inline-block;' ] );
		$radioId = $this->mID . "-$prof";
		$radioAttrs = [
			'id' => $radioId,
		];
		if ( $prof === $value ) {
			$radioAttrs['checked'] = 'checked';
		}
		$html .= Html::input( $this->mID, $prof, 'radio', $radioAttrs );
		$html .= Html::closeElement( 'div' );
		$html .= Html::openElement( 'div', [ 'style' => 'display:inline-block' ] );
		$html .= Html::element( 'label',
			['for' => $radioId, 'style' => 'font-weight: bold'],
			wfMessage( "cirrussearch-completion-profile-$prof-pref-name" )->text()
		);
		$html .= Html::element( 'div',
			[],
			wfMessage( "cirrussearch-completion-profile-$prof-pref-desc" )->text()
		);
		$html .= Html::closeElement( 'div' );
		$html .= Html::closeElement( 'div' );
		return $html;
	}
}
