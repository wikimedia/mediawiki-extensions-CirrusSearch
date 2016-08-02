<?php

namespace CirrusSearch;

/**
 * CirrusSearch - List of Similarity profiles
 * Configure the Similarity function used for scoring
 * see https://www.elastic.co/guide/en/elasticsearch/reference/current/index-modules-similarity.html
 *
 * A reindex is required when changes are made to the similarity configuration.
 * NOTE the parameters that do not affect indexed values can be tuned manually
 * in the index settings but it is possible only on a closed index.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */

$wgCirrusSearchSimilarityProfiles = array(
	// default profile, uses the classic TF/IDF from Lucene
	'default' => array(),
	// BM25 with default values for k and a for all fields
	'bm25_with_defaults' => array(
		'similarity' => array(
			'bm25' => array(
				'type' => 'BM25'
			)
		),
		'fields' => array(
			'__default__' => 'bm25',
		)
	),
	// Example with "randomly" tuned values
	// (do not use)
	'bm25_tuned' => array(
		'similarity' => array(
			'title' => array(
				'type' => 'BM25',
				'k1' => 1.23,
				'b' => 0.75,
			),
			'opening' => array(
				'type' => 'BM25',
				'k1' => 1.22,
				'b' => 0.75,
			),
			'arrays' => array(
				'type' => 'BM25',
				'k1' => 1.1,
				'b' => 0.3,
			),
			'text' => array(
				'type' => 'BM25',
				'k1' => 1.3,
				'b' => 0.80,
			),
		),
		'fields' => array(
			'__default__' => 'text',
			// Field specific config
			'opening_text' => 'opening',
			'category' => 'arrays',
			'title' => 'title',
			'heading' => 'arrays',
			'suggest' => 'title',
		),
	),
);

class SimilarityProfiles {
	public static function getSimilarity( SearchConfig $config, $field, $analyzer = null ) {
		$similarity = $config->get( 'CirrusSearchSimilarityProfile' );
		$fieldSimilarity = 'default';
		if ( isset( $similarity['fields'] ) ) {
			if( isset( $similarity['fields'][$field] ) ) {
				$fieldSimilarity = $similarity['fields'][$field];
			} else if ( $similarity['fields']['__default__'] ) {
				$fieldSimilarity = $similarity['fields']['__default__'];
			}

			if ( $analyzer != null && isset( $similarity['fields']["$field.$analyzer"] ) ) {
				$fieldSimilarity = $similarity['fields']["$field.$analyzer"];
			}
		}
		return $fieldSimilarity;
	}
}
