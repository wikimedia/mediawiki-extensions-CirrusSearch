<?php

namespace CirrusSearch\Assignment;

interface ClusterAssignment {

	/**
	 * @param string $cluster The cluster to id.
	 * @return string An identifier that unique describes the
	 *  connection properties. Instances of the same implementation
	 *  will return same value for same configuration.
	 */
	public function uniqueId( $cluster );

	/**
	 * @return string Name of the cluster group to search against
	 */
	public function getSearchCluster();

	/**
	 * @param string $updateGroup UpdateGroup::* constant
	 * @return string[] List of the cluster groups to send writes to
	 */
	public function getWritableClusters( string $updateGroup ): array;

	/**
	 * @return string[] List all known cluster groups
	 */
	public function getAllKnownClusters(): array;

	/**
	 * @param string $clusterName
	 * @return bool True if the cluster is defined
	 */
	public function hasCluster( string $clusterName ): bool;

	/**
	 * @param string $clusterName
	 * @param string $updateGroup UpdateGroup::* constant
	 * @return bool True when the named cluster is writable for this kind of update
	 */
	public function canWriteToCluster( $clusterName, $updateGroup );

	/**
	 * @param string|null $cluster Name of cluster group to return connection
	 *  configuration for, or null for the default search cluster.
	 * @return string[]|array[] Either a list of hostnames, for default
	 *  connection configuration, or an array of arrays giving full
	 *  connection specifications.
	 */
	public function getServerList( $cluster = null ): array;

	/**
	 * @return string|null The name to use to refer to this wikis group in cross-cluster-search.
	 */
	public function getCrossClusterName();
}
