<?php

namespace CirrusSearch;

use CirrusSearch\Profile\SearchProfileService;
use CirrusSearch\SecondTry\SecondTryRunner;
use CirrusSearch\SecondTry\SecondTryRunnerFactory;
use CirrusSearch\SecondTry\SecondTrySearchFactory;
use MediaWiki\Language\Language;

/**
 * In memory index of namespaces using SecondTryRunner for normalizing strings at index & search time.
 */
class NamespaceMatcher {
	public const SERVICE = self::class;
	private SecondTryRunner $indexRunner;
	private SecondTryRunner $searchRunner;
	private Language $language;
	private ?array $indexedNamespaces = null;

	public static function create(
		Language $language,
		SecondTrySearchFactory $secondTrySearchFactory,
		SearchConfig $config
	): NamespaceMatcher {
		$profile = $config->getProfileService()->loadProfile( SearchProfileService::NAMESPACE_MATCHER );
		return self::buildFromProfile( $profile, $language, $secondTrySearchFactory, $config );
	}

	public static function buildFromProfile(
		array $profile,
		Language $language,
		SecondTrySearchFactory $secondTrySearchFactory,
		SearchConfig $config
	): NamespaceMatcher {
		$factory = new SecondTryRunnerFactory( $secondTrySearchFactory, $config );
		$indexProfile = $profile['index_second_try_profile'] ?? null;
		$searchProfile = $profile['search_second_try_profile'] ?? null;
		if ( !is_string( $indexProfile ) ) {
			throw new \RuntimeException( "Expected index_second_try_profile entry to exist and be a string" );
		}
		if ( !is_string( $searchProfile ) ) {
			throw new \RuntimeException( "Expected search_second_try_profile entry to exist and be a string" );
		}
		$indexProfileData = $config->getProfileService()->loadProfileByName( SearchProfileService::SECOND_TRY, $indexProfile );
		$indexRunner = $factory->buildFromProfile( $indexProfileData );
		$searchRunner = $indexRunner;
		if ( $indexProfile !== $searchProfile ) {
			$searchRunner = $factory->buildFromProfile(
				$config->getProfileService()->loadProfileByName( SearchProfileService::SECOND_TRY, $searchProfile )
			);
		}
		return new self( $indexRunner, $searchRunner, $language );
	}

	private function __construct( SecondTryRunner $indexRunner, SecondTryRunner $searchRunner, Language $language ) {
		$this->indexRunner = $indexRunner;
		$this->searchRunner = $searchRunner;
		$this->language = $language;
	}

	/**
	 * Identify a namespace from a string representation.
	 * May try to apply varying normalization techniques to increase recall compared to a naive
	 * lowercase match.
	 * @param string $namespace
	 * @return int|null the namespace id if found null otherwize
	 */
	public function identifyNamespace( string $namespace ): ?int {
		$indexedNs = $this->getIndexedNamespaces();
		// attempt the raw lc form first
		$foundNs = $indexedNs[$this->language->lc( $namespace )] ?? null;
		if ( $foundNs !== null ) {
			return $foundNs;
		}
		$candidateNamespaces = $this->searchRunner->candidates( $namespace );
		foreach ( $candidateNamespaces as $methodCandidates ) {
			foreach ( $methodCandidates as $methodCandidate ) {
				$foundNs = $indexedNs[$methodCandidate] ?? null;
				if ( $foundNs !== null ) {
					break;
				}
			}
		}

		return $foundNs;
	}

	/**
	 * @return array<string, int>
	 */
	private function getIndexedNamespaces(): array {
		if ( $this->indexedNamespaces === null ) {
			$indexedNs = [];
			foreach ( $this->language->getNamespaceIds() as $candidate => $nsId ) {
				$normalizedCandidates = $this->indexRunner->candidates( $candidate );
				$indexedNs[$candidate] = $nsId;
				foreach ( $normalizedCandidates as $methodCandidates ) {
					foreach ( $methodCandidates as $methodCandidate ) {
						if ( !array_key_exists( $methodCandidate, $indexedNs ) ) {
							$indexedNs[$methodCandidate] = $nsId;
						}
					}
				}
			}
			$this->indexedNamespaces = $indexedNs;
		}

		return $this->indexedNamespaces;
	}
}
