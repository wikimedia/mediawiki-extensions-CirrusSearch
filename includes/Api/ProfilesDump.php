<?php

namespace CirrusSearch\Api;

use CirrusSearch\Profile\SearchProfileOverride;
use CirrusSearch\Profile\SearchProfileService;
use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Dumps CirrusSearch profiles for easy viewing.
 *
 * @license GPL-2.0-or-later
 */
class ProfilesDump extends ApiBase {
	use ApiTrait;

	private SearchProfileService $service;

	public function __construct( ApiMain $mainModule, string $moduleName, ?SearchProfileService $service = null ) {
		parent::__construct( $mainModule, $moduleName );
		$this->service = $service ?: $this->getSearchConfig()->getProfileService();
	}

	public function execute() {
		$service = $this->service;
		$verbose = $this->getParameter( 'verbose' );
		$contexts = [];
		foreach ( $service->listProfileTypes() as $type ) {
			foreach ( $service->listProfileRepositories( $type ) as $repository ) {
				$this->getResult()->addValue( [ 'profiles', $repository->repositoryType(), 'repositories' ], $repository->repositoryName(),
					$verbose ? $repository->listExposedProfiles() : array_keys( $repository->listExposedProfiles() ) );
			}
			foreach ( $service->listProfileContexts( $type ) as $context => $default ) {
				$this->getResult()->addValue( [ 'profiles', $type, 'contexts', $context ], 'code_default', $default );
				$this->getResult()->addValue( [ 'profiles', $type, 'contexts', $context ], 'actual_default',
					$service->getProfileName( $type, $context, [] ) );
				$overriders = $service->listProfileOverrides( $type, $context );
				usort( $overriders, static function ( SearchProfileOverride $a, SearchProfileOverride $b ) {
					return $a->priority() <=> $b->priority();
				} );
				$overriders = array_map( static function ( SearchProfileOverride $o ) {
					return $o->explain();
				}, $overriders );
				$this->getResult()->addValue( [ 'profiles', $type, 'contexts', $context ], 'overriders', $overriders );
				if ( !isset( $contexts[$context] ) ) {
					$contexts[$context] = [];
				}
				$contexts[$context][] = $type;
			}
		}

		foreach ( $contexts as $context => $types ) {
			foreach ( $types as $type ) {
				$this->getResult()->addValue( [ 'contexts', $context ], $type, $service->getProfileName( $type, $context, [] ) );
			}
		}
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'verbose' => [
				ParamValidator::PARAM_DEFAULT => false,
			],
		];
	}

	/**
	 * Mark as internal. This isn't meant to be used by normal api users
	 * @return bool
	 */
	public function isInternal() {
		return true;
	}

	/**
	 * @see ApiBase::getExamplesMessages
	 * @return array
	 */
	protected function getExamplesMessages() {
		return [
			'action=cirrus-profiles-dump' =>
				'apihelp-cirrus-profiles-dump-example'
		];
	}

}
