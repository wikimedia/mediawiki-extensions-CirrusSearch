<?php

namespace CirrusSearch\Api;

use CirrusSearch\Updater;
use Mediawiki\MediaWikiServices;
use WikiPage;

/**
 * Dump stored CirrusSearch document for page.
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
class QueryBuildDocument extends \ApiQueryBase {
	use ApiTrait;

	public function __construct( \ApiQuery $query, $moduleName ) {
		parent::__construct( $query, $moduleName, 'cb' );
	}

	public function execute() {
		$conn = $this->getCirrusConnection();
		$result = $this->getResult();
		$engine = MediaWikiServices::getInstance()
			->getSearchEngineFactory()
			->create( 'cirrus' );

		if ( $engine instanceof \CirrusSearch ) {
			foreach ( $this->getPageSet()->getGoodTitles() as $pageId => $title ) {
				$page = new WikiPage( $title );
				$doc = Updater::buildDocument( $engine, $page, $conn, false, false, true );
				$result->addValue(
					[ 'query', 'pages', $pageId ],
					'cirrusbuilddoc', $doc->getData()
				);
			}
		} else {
			throw new \RuntimeException( 'Could not create cirrus engine' );
		}
	}

	public function getAllowedParams() {
		return [];
	}

	/**
	 * @see ApiBase::getExamplesMessages
	 * @return array
	 */
	protected function getExamplesMessages() {
		return [
			'action=query&prop=cirrusbuilddoc&titles=Main_Page' =>
				'apihelp-query+cirrusbuilddoc-example'
		];
	}

}
