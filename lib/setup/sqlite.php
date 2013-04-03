<?php

namespace OC\Setup;

class Sqlite extends AbstractDatabase {
	public function initialize($config) {
	}

	public function setupDatabase($username) {
		$datadir = \OC_Config::getValue('datadirectory');

		//delete the old sqlite database first, might cause infinte loops otherwise
		if(file_exists("$datadir/owncloud.db")) {
			unlink("$datadir/owncloud.db");
		}
		//in case of sqlite, we can always fill the database
		\OC_DB::createDbFromStructure('db_structure.xml');
	}
}
