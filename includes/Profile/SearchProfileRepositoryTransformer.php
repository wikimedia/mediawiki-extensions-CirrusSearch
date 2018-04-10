<?php

namespace CirrusSearch\Profile;

use Wikimedia\Assert\Assert;

/**
 * Transforms profile content based on replacement variable using a syntax
 * to lookup the entry to modify.
 *
 * Examples:
 *  [ 'elem1.elem2' => 'value' ]
 * will replace the following profile
 *  [ 'elem1' =>
 *      [
 * 			'elem2' => 'placeholder'
 *  	]
 *  ]
 * with :
 *  [ 'elem1' =>
 *      [
 * 			'elem2' => 'value'
 *  	]
 *  ]
 *
 * The syntax supports lookaheads on next array value:
 *  [ 'elem1.*[type=bar].element' => [ 'custom' => 'data' ] ]
 * will replace the following profile
 *  [ 'elem1' =>
 *      [
 * 			[
 * 				'type' => 'foo'
 * 				'element' => []
 * 			],
 * 			[
 * 				'type' => 'bar'
 * 				'element' => []
 * 			],
 *  	]
 *  ]
 * with :
 *  [ 'elem1' =>
 *      [
 * 			[
 * 				'type' => 'foo'
 * 				'element' => []
 * 			],
 * 			[
 * 				'type' => 'bar'
 * 				'element' => [ 'custom' => 'data' ]
 * 			],
 *  	]
 *  ]
 *
 * A single substition can occur, the first match wins.
 * The full path must always be matched.
 */
class SearchProfileRepositoryTransformer implements SearchProfileRepository {

	const PATTERN = '/\G(?:(?<!^)[.]|^)(?<keypath>[^.\[\]]+)(?:\[(?<lookaheadkey>[^.\[\]]+)=(?<lookaheadvalue>[^.\[\]]+)\])?/';

	/**
	 * @var SearchProfileRepository
	 */
	private $repository;

	/**
	 * @var mixed[]
	 */
	private $replacements;

	/**
	 * Lazily initializaed after calling getCompiledReplacements
	 * @var array|null list of replacements compiled after appyling preg_match_all
	 */
	private $compiledReplacements;

	/**
	 * SearchProfileRepositoryTransformer constructor.
	 * @param SearchProfileRepository $repository
	 * @param mixed[] $replacements array of replacements indexed with a string in the syntax supported
	 * by this class
	 * @see SearchProfileRepositoryTransformer
	 */
	public function __construct( SearchProfileRepository $repository, array $replacements ) {
		$this->repository = $repository;
		$this->replacements = $replacements;
	}

	/**
	 * The repository type
	 * @return string
	 */
	public function repositoryType() {
		return $this->repository->repositoryType();
	}

	/**
	 * The repository name
	 * @return string
	 */
	public function repositoryName() {
		return $this->repository->repositoryName();
	}

	/**
	 * Load a profile named $name
	 * @param string $name
	 * @return array|null the profile data or null if not found
	 */
	public function getProfile( $name ) {
		return $this->transform( $this->repository->getProfile( $name ) );
	}

	/**
	 * Check if a profile named $name exists in this repository
	 * @param string $name
	 * @return bool
	 */
	public function hasProfile( $name ) {
		return $this->repository->hasProfile( $name );
	}

	/**
	 * Get the list of profiles that we want to expose to the user.
	 *
	 * @return array[] list of profiles indexed by name
	 */
	public function listExposedProfiles() {
		return array_map( [ $this, 'transform' ], $this->repository->listExposedProfiles() );
	}

	/**
	 * Transform the profile
	 * @param array|null $profile
	 * @return array|null
	 */
	private function transform( array $profile = null ) {
		if ( $profile === null ) {
			return null;
		}
		foreach ( $this->getCompiledReplacements() as $replacement ) {
			$profile = $this->replace( $profile, $replacement );
		}
		return $profile;
	}

	/**
	 * @param array $profile
	 * @param array $replacement
	 * @return array transformed profile
	 */
	private function replace( array $profile, array $replacement ) {
		$cur = &$profile;
		foreach ( $replacement['matches'] as $match ) {
			if ( !is_array( $cur ) ) {
				return $profile;
			}
			$keypath = $match['keypath'][0];
			$keys = [ $keypath ];
			if ( $keypath === '*' && isset( $match['lookaheadvalue'] ) && $match['lookaheadvalue'][0] !== '' ) {
				$keys = array_keys( $cur );
			}
			$found = false;
			foreach ( $keys as $key ) {
				if ( !array_key_exists( $key, $cur ) ) {
					continue;
				}
				$maybeNext = $cur[$key];
				if ( is_array( $maybeNext ) && isset( $match['lookaheadvalue'] )
					 && $match['lookaheadvalue'][0] !== ''
				) {
					$lookaheadKey = $match['lookaheadkey'][0];
					$lookaheadValue = $match['lookaheadvalue'][0];
					if ( isset( $maybeNext[$lookaheadKey] ) ) {
						$lookahead = $maybeNext[$lookaheadKey];
						// Use == instead of === so that a pattern expression such as [boost=2]
						// can match a profile declaring [ 'boost' => 2.0 ]
						if ( !is_array( $lookahead ) && $lookahead == $lookaheadValue ) {
							$cur = &$cur[$key];
							$found = true;
							break;
						}
					}
				} else {
					$cur = &$cur[$key];
					$found = true;
					break;
				}
			}
			if ( !$found ) {
				return $profile;
			}
		}
		$cur = $replacement['value'];
		return $profile;
	}

	/**
	 * Compile the replacement strings if needed
	 * @return array
	 */
	private function getCompiledReplacements() {
		if ( $this->compiledReplacements === null ) {
			$this->compiledReplacements = [];
			foreach ( $this->replacements as $repl => $value ) {
				if ( !is_string( $repl ) ) {
					throw new SearchProfileException( "Replacement pattern must be string but is a " . gettype( $repl ) );
				}
				$matches = [];
				$ret = preg_match_all( self::PATTERN, $repl, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
				Assert::postcondition( $ret !== false, 'preg_match_all should not fail' );
				if ( count( $matches ) > 0 ) {
					$lastMatch = end( $matches )[0];
					// Make sure that we matched the whole input if not it means we perhaps matched
					// the beginning but some components at the end
					$valid = ( strlen( $lastMatch[0] ) + $lastMatch[1] ) === strlen( $repl );
					reset( $matches );
				} else {
					$valid = false;
				}
				if ( !$valid ) {
					throw new SearchProfileException( "Invalid replacement pattern provided: [$repl]." );
				}
				$this->compiledReplacements[] = [
					'value' => $value,
					'matches' => $matches
				];
			}
		}
		return $this->compiledReplacements;
	}
}
