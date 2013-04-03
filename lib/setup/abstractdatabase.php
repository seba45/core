<?php

namespace OC\Setup;

abstract class AbstractDatabase {
	protected $trans;
	protected $dbuser;
	protected $dbpassword;
	protected $dbname;
	protected $dbhost;
	protected $tableprefix;

	public function __construct($trans, $config) {
		$this->trans = $trans;
	}

	public function validateBasic($config) {
		$errors = array();
		if(empty($config['dbuser'])) {
			$errors[] = $this->trans->t("%s enter the database username.", array($this->dbprettyname));
		}
		if(empty($config['dbname'])) {
			$errors[] = $this->trans->t("%s enter the database name.", array($this->dbprettyname));
		}
		if(substr_count($config['dbname'], '.') >= 1) {
			$errors[] = $this->trans->t("%s you may not use dots in the database name", array($this->dbprettyname));
		}
		return $errors;
	}

	public function validate($config) {
		$errors = $this->validateBasic($config);
		if(empty($config['dbhost'])) {
			$errors[] = $this->trans->t("%s set the database host.", array($this->dbprettyname));
		}
		return $errors;
	}

	public function initialize($config) {
		$dbuser = $config['dbuser'];
		$dbpass = $config['dbpass'];
		$dbname = $config['dbname'];
		$dbhost = isset($config['dbhost']) ? $config['dbhost'] : ''; // dbhost contents is checked earlier
		$dbtableprefix = isset($config['dbtableprefix']) ? $config['dbtableprefix'] : 'oc_';

		\OC_Config::setValue('dbname', $dbname);
		\OC_Config::setValue('dbhost', $dbhost);
		\OC_Config::setValue('dbtableprefix', $dbtableprefix);

		$this->dbuser = $dbuser;
		$this->dbpassword = $dbpass;
		$this->dbname = $dbname;
		$this->dbhost = $dbhost;
		$this->tableprefix = $tableprefix;
	}
}
