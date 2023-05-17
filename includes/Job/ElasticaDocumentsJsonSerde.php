<?php

namespace CirrusSearch\Job;

use Elastica\Document;

/**
 * Updates to be sent to elasticsearch need to be represented as a Document
 * object, but we can't directly serialize those into the job queue which only
 * supports json.
 *
 * Implements a simple serialize / deserialize routine that round trips
 * documents to plain json types and back.
 */
class ElasticaDocumentsJsonSerde {
	/**
	 * @param Document[] $docs
	 * @return array[] Document represented with json compatible types
	 */
	public function serialize( array $docs ) {
		$res = [];
		foreach ( $docs as $doc ) {
			$res[] = $this->serializeOne( $doc );
		}
		return $res;
	}

	public function serializeOne( Document $doc ) {
		return [
			'data' => $doc->getData(),
			'params' => $doc->getParams(),
			'upsert' => $doc->getDocAsUpsert(),
		];
	}

	/**
	 * @param array[] $serialized Data returned by self::serialize
	 * @return Document[]
	 */
	public function deserialize( array $serialized ) {
		$res = [];
		foreach ( $serialized as $x ) {
			$res[] = $this->deserializeOne( $x );
		}
		return $res;
	}

	public function deserializeOne( array $serialized, ?Document $doc = null ): Document {
		// TODO: Because json_encode/decode is involved the round trip
		// is imperfect. Almost everything here is an array regardless
		// of what it was before serialization.  That shouldn't matter
		// for documents, but elastica does occasionally use `(object)[]`
		// instead of an empty array to force `{}` in the json output
		// and that has been lost here.
		// document _source
		if ( $doc === null ) {
			$doc = Document::create( $serialized['data'] );
		} else {
			$doc->setData( $serialized['data'] );
		}
		// id, version, etc.
		$doc->setParams( $serialized['params'] );
		$doc->setDocAsUpsert( $serialized['upsert'] );
		return $doc;
	}
}
