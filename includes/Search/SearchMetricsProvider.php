<?php

namespace CirrusSearch\Search;

interface SearchMetricsProvider {
	/**
	 * @return array
	 */
	public function getMetrics();
}
