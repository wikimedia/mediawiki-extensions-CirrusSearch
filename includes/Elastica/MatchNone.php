<?php

namespace CirrusSearch\Elastica;

/**
 * Backport of https://github.com/ruflin/Elastica/pull/1276
 *
 * @link https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-match-all-query.html
 */
class MatchNone extends \Elastica\Query\AbstractQuery {
    /**
     * Creates match none query.
     */
    public function __construct() {
        /** @suppress PhanTypeMismatchProperty (done like that in Elastica) */
        $this->_params = new \stdClass();
    }
}
