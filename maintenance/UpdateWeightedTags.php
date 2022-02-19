<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\CirrusSearch;
use Generator;
use MalformedTitleException;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\ProperPageIdentity;
use SplFileObject;
use Title;

/**
 * Update the weighted_tags field for a page for a specific tag.
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

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
require_once __DIR__ . '/../includes/Maintenance/Maintenance.php';

class UpdateWeightedTags extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( "Update the weighted_tags field for a page for a specific tag." );
		$this->addOption( 'page', 'Page title', false, true );
		$this->addOption( 'page-list', 'Path to file with a list of page titles, one per line.', false, true );
		$this->addOption( 'pageid-list', 'Path to file with a list of page IDs, one per line.', false, true );
		$this->addOption( 'tagType', "Tag type. A string such as 'recommendation.link'.", true, true );
		$this->addOption( 'tagName', "Tag name. Some tag types don't use this.", false, true, false, true );
		$this->addOption( 'weight', "Weight (0-1000). Some tag types don't use this. When used, must occur the same number of"
			. " times as --tagName and will be matched by position.", false, true, false, true );
		$this->addOption( 'reset', 'Reset a tag type (remove all tags belonging to it). Cannot be mixed with --tagName and --weight.' );
		$this->addOption( 'verbose', 'Verbose output.' );
		$this->setBatchSize( 50 );
	}

	public function execute() {
		$this->validateParams();
		foreach ( $this->getPageIdentities() as $pageIdentity ) {
			$tagPrefix = $this->getOption( 'tagType' );
			$cirrusSearch = new CirrusSearch();
			if ( $this->hasOption( 'reset' ) ) {
				$cirrusSearch->resetWeightedTags( $pageIdentity, $tagPrefix );
			} else {
				$tagNames = $this->getOption( 'tagName' );
				$tagWeights = $this->getOption( 'weight' );
				if ( $tagWeights !== null ) {
					$tagWeights = array_map( 'intval', $tagWeights );
					$tagWeights = array_combine( $tagNames, $tagWeights );
				}
				$cirrusSearch->updateWeightedTags( $pageIdentity, $tagPrefix, $tagNames, $tagWeights );
			}
		}
	}

	private function validateParams() {
		$pageOptionCount = (int)$this->hasOption( 'page' ) + (int)$this->hasOption( 'page-list' )
			+ (int)$this->hasOption( 'pageid-list' );
		if ( $pageOptionCount !== 1 ) {
			$this->fatalError( "Exactly one of --page, --page-list and --pageid-list must be used" );
		} elseif ( $this->hasOption( 'page-list' ) && !is_readable( $this->getOption( 'page-list' ) ) ) {
			$this->fatalError( 'Cannot read page list from ' . $this->getOption( 'page-list' ) );
		} elseif ( $this->hasOption( 'pageid-list' ) && !is_readable( $this->getOption( 'pageid-list' ) ) ) {
			$this->fatalError( 'Cannot read page ID list from ' . $this->getOption( 'page-list' ) );
		}

		if ( strpos( $this->getOption( 'tagType' ), '/' ) !== false ) {
			$this->fatalError( 'The tag type cannot contain a / character' );
		}

		if ( $this->hasOption( 'reset' ) ) {
			if ( $this->hasOption( 'tagName' ) || $this->hasOption( 'weight' ) ) {
				$this->fatalError( '--reset cannot be used with --tagName or --weight' );
			}
		} else {
			$tagNames = $this->getOption( 'tagName' );
			$tagWeights = $this->getOption( 'weight' );

			if ( $tagNames === null ) {
				if ( $tagWeights !== null ) {
					$this->fatalError( '--weight should be used together with --tagName' );
				}
			} else {
				if ( $tagWeights && count( $tagNames ) !== count( $tagWeights ) ) {
					$this->fatalError( 'When --weight is used, it must occur the same number of times as --tagName' );
				}
				foreach ( $tagNames as $tagName ) {
					if ( strpos( $tagName, '|' ) !== false ) {
						$this->fatalError( "Wrong tag name '$tagName': cannot contain | character" );
					}
				}
				foreach ( $tagWeights ?? [] as $tagWeight ) {
					if ( !ctype_digit( $tagWeight ) || ( $tagWeight < 1 ) || ( $tagWeight > 1000 ) ) {
						$this->fatalError( "Wrong tag weight '$tagWeight': must be an integer between 1 and 1000" );
					}
				}
			}
		}
	}

	/**
	 * @return Generator<ProperPageIdentity>
	 */
	private function getPageIdentities() {
		if ( $this->hasOption( 'page' ) ) {
			$pageName = $this->getOption( 'page' );
			$title = Title::newFromText( $pageName );
			if ( !$title ) {
				$this->fatalError( "Invalid title $pageName" );
			} elseif ( !$title->canExist() ) {
				$this->fatalError( "$pageName is not a proper page" );
			} elseif ( !$title->exists() ) {
				$this->fatalError( "$pageName does not exist" );
			}
			if ( $title->hasFragment() ) {
				$title->setFragment( '' );
			}
			yield $title->toPageIdentity();
		} else {
			$useIds = $this->hasOption( 'pageid-list' );
			if ( $useIds ) {
				$file = new SplFileObject( $this->getOption( 'pageid-list' ) );
			} else {
				$file = new SplFileObject( $this->getOption( 'page-list' ) );
			}
			foreach ( $this->readLineBatch( $file, $useIds ) as $pageIdentities ) {
				yield from $pageIdentities;
			}
		}
	}

	/**
	 * Read lines from the given file and return up to $batchSize page identities.
	 * @param SplFileObject $file
	 * @param bool $useIds Is the file a list of page IDs or titles?
	 * @return Generator<ProperPageIdentity[]>
	 */
	private function readLineBatch( SplFileObject $file, bool $useIds ) {
		$titleParser = MediaWikiServices::getInstance()->getTitleParser();
		$pageStore = MediaWikiServices::getInstance()->getPageStore();
		$batchSize = $this->getBatchSize();
		$identifiers = [];
		$logNext = true;
		while ( !$file->eof() || $identifiers ) {
			if ( count( $identifiers ) >= $batchSize || $file->eof() ) {
				if ( $useIds ) {
					yield $pageStore->newSelectQueryBuilder()->wherePageIds( $identifiers )
						->fetchPageRecordArray();
				} else {
					$linkBatch = MediaWikiServices::getInstance()->getLinkBatchFactory()
						->newLinkBatch( $identifiers );
					$linkBatch->execute();
					yield $linkBatch->getPageIdentities();
				}
				$identifiers = [];
				$logNext = true;
			}
			if ( $file->eof() ) {
				break;
			}
			$line = trim( $file->fgets() );
			// be forgiving with trailing empty lines
			if ( $line === '' ) {
				continue;
			}
			if ( $useIds ) {
				if ( !preg_match( '/^[1-9]\d*$/', $line ) ) {
					$this->error( "Invalid page ID: $line\n" );
					continue;
				} else {
					$identifiers[] = (int)$line;
				}
			} else {
				try {
					$identifiers[] = $titleParser->parseTitle( $line );
				} catch ( MalformedTitleException $e ) {
					$this->error( "Invalid page title: $line\n" );
					continue;
				}
			}
			if ( $logNext && $this->hasOption( 'verbose' ) ) {
				$this->output( 'Processing batch starting with ' . $line . PHP_EOL );
				$logNext = false;
			}
		}
	}
}

$maintClass = UpdateWeightedTags::class;
require_once RUN_MAINTENANCE_IF_MAIN;
