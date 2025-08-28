<?php

namespace CirrusSearch\Maintenance;

class CompletionSuggesterIndexerConfig {
	private string $indexBaseName;
	private bool $altIndex;
	private int $altIndexId;
	private int $shardCount;
	private string $replicaCount;
	private int $maxShardPerNode;
	private bool $recycle;
	private int $indexChunkSize;
	private string $masterTimeout;
	private int $replicationTimeout;
	private int $indexRetryAttempts;
	private ?string $allocationIncludeTag;
	private ?string $allocationExcludeTag;
	private bool $optimizeIndex;
	private bool $force;

	/**
	 * @param string $indexBaseName the base name of the index
	 * @param bool $altIndex true if it's an "alternative" index
	 * @param int $altIndexId the id of the alternative index
	 * @param int $shardCount the number of shards
	 * @param string $replicaCount the number of replicas (auto_expand_replicas format)
	 * @param int $maxShardPerNode max number of shards per node
	 * @param bool $recycle true to recycle the index
	 * @param int $indexChunkSize max number of docs to buffer before writing to the backend
	 * @param string $masterTimeout the master timeouts for operations involving a cluster state change
	 * @param int $replicationTimeout timeout to wait for replication to happen (wait for green)
	 * @param int $indexRetryAttempts number of retries to attempt per bulk requests
	 * @param string|null $allocationIncludeTag optional allocation tag to force allocation on nodes marked with this tag
	 * @param string|null $allocationExcludeTag optional allocation tag to force allocation on nodes not marked with this tag
	 * @param bool $optimizeIndex true to optimize the index
	 * @param bool $force force the promotion of the index even if some checks suggest that the index is broken
	 */
	public function __construct( string $indexBaseName, bool $altIndex, int $altIndexId,
		int $shardCount, string $replicaCount, int $maxShardPerNode,
		bool $recycle, int $indexChunkSize, string $masterTimeout, int $replicationTimeout, int $indexRetryAttempts,
		?string $allocationIncludeTag, ?string $allocationExcludeTag, bool $optimizeIndex,
		bool $force
	) {
		$this->indexBaseName = $indexBaseName;
		$this->altIndex = $altIndex;
		$this->altIndexId = $altIndexId;
		$this->shardCount = $shardCount;
		$this->replicaCount = $replicaCount;
		$this->maxShardPerNode = $maxShardPerNode;
		$this->recycle = $recycle;
		$this->indexChunkSize = $indexChunkSize;
		$this->masterTimeout = $masterTimeout;
		$this->replicationTimeout = $replicationTimeout;
		$this->indexRetryAttempts = $indexRetryAttempts;
		$this->allocationIncludeTag = $allocationIncludeTag;
		$this->allocationExcludeTag = $allocationExcludeTag;
		$this->optimizeIndex = $optimizeIndex;
		$this->force = $force;
	}

	public function getAllocationExcludeTag(): ?string {
		return $this->allocationExcludeTag;
	}

	public function getIndexBaseName(): string {
		return $this->indexBaseName;
	}

	public function isAltIndex(): bool {
		return $this->altIndex;
	}

	public function getAltIndexId(): int {
		return $this->altIndexId;
	}

	public function isRecycle(): bool {
		return $this->recycle;
	}

	public function getIndexChunkSize(): int {
		return $this->indexChunkSize;
	}

	public function getMasterTimeout(): string {
		return $this->masterTimeout;
	}

	public function getIndexRetryAttempts(): int {
		return $this->indexRetryAttempts;
	}

	public function getAllocationIncludeTag(): ?string {
		return $this->allocationIncludeTag;
	}

	public function isOptimizeIndex(): bool {
		return $this->optimizeIndex;
	}

	public function isForce(): bool {
		return $this->force;
	}

	public function getShardCount(): int {
		return $this->shardCount;
	}

	public function getReplicaCount(): string {
		return $this->replicaCount;
	}

	public function getMaxShardPerNode(): int {
		return $this->maxShardPerNode;
	}

	public function getReplicationTimeout(): int {
		return $this->replicationTimeout;
	}
}
