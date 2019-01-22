<?php

namespace CirrusSearch\Assignment;

interface ClusterAssignment {
	/**
	 * @return string Name of the cluster group to search against
	 */
	public function getSearchCluster();

	/**
	 * @return string[] List of the cluster groups to send writes to
	 */
	public function getWritableClusters(): array;

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
