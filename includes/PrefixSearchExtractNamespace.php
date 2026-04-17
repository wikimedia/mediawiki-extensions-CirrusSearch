<?php

namespace CirrusSearch;

use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Language\Language;
use MediaWiki\MainConfigNames;
use MediaWiki\Search\Hook\PrefixSearchExtractNamespaceHook;

class PrefixSearchExtractNamespace implements PrefixSearchExtractNamespaceHook {
	private SearchConfig $config;
	private Language $language;

	/**
	 * @param Config $mainConfig
	 * @param ConfigFactory $configFactory
	 * @param Language $language
	 * @return PrefixSearchExtractNamespaceHook
	 */
	public static function create( Config $mainConfig, ConfigFactory $configFactory, Language $language ) {
		if ( $mainConfig->get( MainConfigNames::SearchType ) !== 'CirrusSearch' ) {
			return new class() implements PrefixSearchExtractNamespaceHook {
				/**
				 * @inheritDoc
				 */
				public function onPrefixSearchExtractNamespace( &$namespaces, &$search ) {
					return false;
				}
			};
		}
		/** @phan-suppress-next-line PhanTypeMismatchArgumentSuperType CirrusSearch returns SearchConfig */
		return new self( $configFactory->makeConfig( 'CirrusSearch' ), $language );
	}

	public function __construct( SearchConfig $config, Language $language ) {
		$this->config = $config;
		$this->language = $language;
	}

	/**
	 * @inheritDoc
	 */
	public function onPrefixSearchExtractNamespace( &$namespaces, &$search ) {
		$method = $this->config->get( 'CirrusSearchNamespaceResolutionMethod' );
		$colon = strpos( $search, ':' );
		if ( $colon === false ) {
			return false;
		}
		$namespaceName = substr( $search, 0, $colon );
		$ns = Util::identifyNamespace( $namespaceName, $method, $this->language );
		if ( $ns !== false ) {
			$namespaces = [ $ns ];
			$search = substr( $search, $colon + 1 );
		}

		return false;
	}
}
