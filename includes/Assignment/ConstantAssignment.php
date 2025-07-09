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

	/** @inheritDoc */
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

	/** @inheritDoc */
	public function getSearchCluster() {
		return 'default';
	}

	public function getManagedClusters(): array {
		return [ 'default' ];
	}

	/** @inheritDoc */
	public function getWritableClusters( string $updateGroup ): array {
		return [ 'default' ];
	}

	/** @inheritDoc */
	public function getAllKnownClusters(): array {
		return [ 'default' ];
	}

	/** @inheritDoc */
	public function hasCluster( string $clusterName ): bool {
		return true;
	}

	/**
	 * @param string $clusterName
	 * @return bool True when the named cluster is in the set of managable clusters.
	 */
	public function canManageCluster( $clusterName ): bool {
		return true;
	}

	/** @inheritDoc */
	public function canWriteToCluster( $clusterName, $updateGroup ) {
		return true;
	}

	/** @inheritDoc */
	public function getCrossClusterName() {
		return null;
	}
}
