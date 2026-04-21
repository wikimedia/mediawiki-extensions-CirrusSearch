<?php

namespace CirrusSearch;

use MediaWiki\Config\Config;
use MediaWiki\MainConfigNames;
use MediaWiki\Search\Hook\PrefixSearchExtractNamespaceHook;

class PrefixSearchExtractNamespace implements PrefixSearchExtractNamespaceHook {
	private NamespaceMatcher $namespaceMatcher;

	/**
	 * @param Config $mainConfig
	 * @param NamespaceMatcher $namespaceMatcher
	 * @return PrefixSearchExtractNamespaceHook
	 */
	public static function create(
		Config $mainConfig,
		NamespaceMatcher $namespaceMatcher,
	) {
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
		return new self( $namespaceMatcher );
	}

	public function __construct( NamespaceMatcher $namespaceMatcher ) {
		$this->namespaceMatcher = $namespaceMatcher;
	}

	/**
	 * @inheritDoc
	 */
	public function onPrefixSearchExtractNamespace( &$namespaces, &$search ) {
		$colon = strpos( $search, ':' );
		if ( $colon === false ) {
			return false;
		}
		$namespaceName = substr( $search, 0, $colon );
		$ns = $this->namespaceMatcher->identifyNamespace( $namespaceName );
		if ( $ns !== null ) {
			$namespaces = [ $ns ];
			$search = substr( $search, $colon + 1 );
		}

		return false;
	}
}
