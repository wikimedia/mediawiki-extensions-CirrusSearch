<?php
class ConfigBuilder {
	protected $where;

	public function __construct($where) {
		$this->where = $where;
	}

	protected function indent( $source ) {
		return preg_replace( '/^/m', "\t", $source );
	}

	protected function copyRawConfigFile( $path ) {
		$source = __DIR__ . '/copiedRaw/' . $path;
		copy( $source, $this->getDestinationAndEnsureParent( $path ) );
	}

	protected function writeConfigFile( $path, $contents ) {
		file_put_contents( $this->getDestinationAndEnsureParent( $path ), $contents );
	}

	private function getDestinationAndEnsureParent( $path ) {
		$dest = $this->where . '/' . $path;
		wfMkdirParents( dirname( $dest ), 0755 );
		return $dest;
	}
}
