<?php

namespace CirrusSearch\Assignment;

class ConstantAssignment implements ClusterAssignment {
	/** @var string[]|array[] Elastica connection configuration */
	private $servers;

	/**
	 * @param string[]|array[] $servers Elastica connection configuration
	 */
	public function __construct( array $servers ) {
		$this->servers = $servers;
	}

	public function uniqueId( $cluster ) {
		return 'default';
	}

	/**
	 * @param string|null $cluster
	 * @return string[]|array[]
	 */
	public function getServerList( $cluster = null ): array {
		return $this->servers;
	}

	public function getSearchCluster() {
		return 'default';
	}

	public function getWritableClusters( string $updateGroup ): array {
		return [ 'default' ];
	}

	public function getAllKnownClusters(): array {
		return [ 'default' ];
	}

	public function hasCluster( string $clusterName ): bool {
		return true;
	}

	public function canWriteToCluster( $clusterName, $updateGroup ) {
		return true;
	}

	public function getCrossClusterName() {
		return null;
	}
}
