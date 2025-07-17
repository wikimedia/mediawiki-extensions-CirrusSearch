<?php

namespace CirrusSearch\MetaStore;

use Elastica\Index;
use InvalidArgumentException;
use MediaWiki\WikiMap\WikiMap;

class MetaSaneitizeJobStore implements MetaStore {
	public const METASTORE_TYPE = "sanitize";

	/** @var Index */
	private $index;

	public function __construct( Index $index ) {
		$this->index = $index;
	}

	/**
	 * @param string $jobName
	 * @return string the job id
	 */
	public static function docId( $jobName ) {
		return implode( '-', [
			self::METASTORE_TYPE,
			WikiMap::getCurrentWikiId(),
			$jobName
		] );
	}

	/**
	 * @param string $jobName
	 * @param int $idOffset The starting page id of the job
	 * @param string|null $cluster target cluster for this job (null for all writable clusters)
	 * @return \Elastica\Document
	 */
	public function create( $jobName, $idOffset, $cluster = null ) {
		$doc = new \Elastica\Document(
			self::docId( $jobName ),
			[
				'type' => self::METASTORE_TYPE,
				'wiki' => WikiMap::getCurrentWikiId(),
				'sanitize_job_loop_id' => 0,
				'sanitize_job_wiki' => WikiMap::getCurrentWikiId(), // Deprecated, use common wiki field
				'sanitize_job_created' => time(),
				'sanitize_job_updated' => time(),
				'sanitize_job_last_loop' => null,
				'sanitize_job_cluster' => $cluster,
				'sanitize_job_id_offset' => $idOffset,
				'sanitize_job_ids_sent' => 0,
				'sanitize_job_ids_sent_total' => 0,
				'sanitize_job_jobs_sent' => 0,
				'sanitize_job_jobs_sent_total' => 0
			],
			'_doc'
		);
		$this->index->addDocuments( [ $doc ] );
		return $doc;
	}

	/**
	 * @param string $jobName
	 * @return \Elastica\Document|null
	 */
	public function get( $jobName ) {
		try {
			return $this->index->getDocument( self::docId( $jobName ) );
		} catch ( \Elastica\Exception\NotFoundException ) {
			return null;
		}
	}

	/**
	 * TODO: Might be more comfortable with something that
	 * wraps the document and guarantees something sane
	 * is provided here.
	 */
	public function update( \Elastica\Document $jobInfo ) {
		if ( $jobInfo->get( 'type' ) != self::METASTORE_TYPE ) {
			throw new InvalidArgumentException( "Wrong document type" );
		}
		$jobInfo->set( 'sanitize_job_updated', time() );
		$params = $jobInfo->getParams();
		// Clear versioning info provided by elastica, we don't want
		// to version these docs (they once were).
		unset( $params['version'] );
		$jobInfo->setParams( $params );

		$this->index->addDocuments( [ $jobInfo ] );
	}

	/**
	 * @param string $jobName
	 */
	public function delete( $jobName ) {
		$this->index->deleteById( self::docId( $jobName ) );
	}

	/**
	 * @return array
	 */
	public function buildIndexProperties() {
		return [];
	}
}
