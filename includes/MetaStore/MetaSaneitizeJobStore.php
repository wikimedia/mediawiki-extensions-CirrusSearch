<?php

namespace CirrusSearch\MetaStore;

use Elastica\Index;
use WikiMap;

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
			// Try to fetch the JobInfo from one of the metastore
			// TODO: remove references to type (T308044)
			return $this->index->getType( '_doc' )->getDocument( self::docId( $jobName ) );
		} catch ( \Elastica\Exception\NotFoundException $e ) {
			return null;
		}
	}

	/**
	 * TODO: Might be more comfortable with something that
	 * wraps the document and guarantees something sane
	 * is provided here.
	 *
	 * @param \Elastica\Document $jobInfo
	 */
	public function update( \Elastica\Document $jobInfo ) {
		if ( $jobInfo->get( 'type' ) != self::METASTORE_TYPE ) {
			throw new \Exception( "Wrong document type" );
		}
		$version = time();
		$jobInfo->set( 'sanitize_job_updated', $version );
		// TODO: Use setVersion / setVersionType with Elastica 7.x
		$jobInfo->setParam( 'version', $version );
		$jobInfo->setParam( 'version_type', 'external' );
		$this->index->addDocuments( [ $jobInfo ] );
	}

	/**
	 * @param string $jobName
	 */
	public function delete( $jobName ) {
		// TODO: remove references to type (T308044)
		$this->index->getType( '_doc' )->deleteById( self::docId( $jobName ) );
	}

	/**
	 * @return array
	 */
	public function buildIndexProperties() {
		return [];
	}
}
