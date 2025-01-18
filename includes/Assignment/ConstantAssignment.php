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

	/** @inheritDoc */
	public function canWriteToCluster( $clusterName, $updateGroup ) {
		return true;
	}

	/** @inheritDoc */
	public function getCrossClusterName() {
		return null;
	}
}
