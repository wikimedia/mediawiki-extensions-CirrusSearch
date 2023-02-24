<?php

/**
 * Romanian (ro) stop words with ș & ț (commas) instead of ş & ţ (cedillas)
 * for Elasticsearch analysis config.
 * Adapted from the list used by Lucene:
 * - https://github.com/apache/lucene/blob/main/lucene/analysis/common/src/resources/org/apache/lucene/analysis/ro/stopwords.txt
 * which was originally created by Jacques Savoy under the BSD license:
 * - http://members.unine.ch/jacques.savoy/clef/roumanianST.txt
 */

$romanianCommaStopwords = [
	'acești', 'aceștia', 'aș', 'așadar', 'ăștia', 'ați', 'aveți', 'câți', 'cîți', 'deși',
	'ești', 'fiți', 'îți', 'mulți', 'niște', 'noștri', 'și', 'sînteți', 'sunteți', 'ți',
	'ție', 'toți', 'totuși', 'voștri',
	];

return $romanianCommaStopwords;
