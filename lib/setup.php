<?php

class DatabaseSetupException extends Exception
{
	private $hint;

	public function __construct($message, $hint, $code = 0, Exception $previous = null) {
		$this->hint = $hint;
		parent::__construct($message, $code, $previous);
	}

	public function __toString() {
		return __CLASS__ . ": [{$this->code}]: {$this->message} ({$this->hint})\n";
	}

	public function getHint() {
		return $this->hint;
	}
}

class OC_Setup {
	static $db_setup_classes = array(
		'mysql' => '\OC\Setup\MySQL',
		'pgsql' => '\OC\Setup\PostgreSQL',
		'oci'   => '\OC\Setup\OCI',
		'mssql' => '\OC\Setup\MSSQL',
		'sqlite' => '\OC\Setup\Sqlite',
		'sqlite3' => '\OC\Setup\Sqlite',
	);

	public static function getTrans(){
		return OC_L10N::get('lib');
	}

	public static function install($options) {
		$l = self::getTrans();

		$error = array();
		$dbtype = $options['dbtype'];

		if(empty($options['adminlogin'])) {
			$error[] = $l->t('Set an admin username.');
		}
		if(empty($options['adminpass'])) {
			$error[] = $l->t('Set an admin password.');
		}
		if(empty($options['directory'])) {
			$error[] = $l->t('Specify a data folder.');
		}

		if (!isset(self::$db_setup_classes[$dbtype])) {
			$dbtype = 'sqlite';
		}

		$class = self::$db_setup_classes[$dbtype];
		$db_setup = new $class(self::getTrans());
		$error = array_merge($error, $db_setup->validate($options));

		if(count($error) != 0) {
			return $error;
		}

		//no errors, good
		$username = htmlspecialchars_decode($options['adminlogin']);
		$password = htmlspecialchars_decode($options['adminpass']);
		$datadir = htmlspecialchars_decode($options['directory']);

		if (OC_Util::runningOnWindows()) {
			$datadir = rtrim(realpath($datadir), '\\');
		}

		//use sqlite3 when available, otherise sqlite2 will be used.
		if($dbtype=='sqlite' and class_exists('SQLite3')) {
			$dbtype='sqlite3';
		}

		//generate a random salt that is used to salt the local user passwords
		$salt = OC_Util::generate_random_bytes(30);
		OC_Config::setValue('passwordsalt', $salt);

		//write the config file
		OC_Config::setValue('datadirectory', $datadir);
		OC_Config::setValue('dbtype', $dbtype);
		OC_Config::setValue('version', implode('.', OC_Util::getVersion()));
		try {
			$db_setup->initialize($options);
			$db_setup->setupDatabase($username);
		} catch (DatabaseSetupException $e) {
			$error[] = array(
				'error' => $e->getMessage(),
				'hint' => $e->getHint()
			);
			return($error);
		} catch (Exception $e) {
			$error[] = array(
				'error' => $e->getMessage(),
				'hint' => ''
			);
			return($error);
		}

		//create the user and group
		try {
			OC_User::createUser($username, $password);
		}
		catch(Exception $exception) {
			$error[] = $exception->getMessage();
		}

		if(count($error) == 0) {
			OC_Appconfig::setValue('core', 'installedat', microtime(true));
			OC_Appconfig::setValue('core', 'lastupdatedat', microtime(true));
			OC_AppConfig::setValue('core', 'remote_core.css', '/core/minimizer.php');
			OC_AppConfig::setValue('core', 'remote_core.js', '/core/minimizer.php');

			OC_Group::createGroup('admin');
			OC_Group::addToGroup($username, 'admin');
			OC_User::login($username, $password);

			//guess what this does
			OC_Installer::installShippedApps();

			//create htaccess files for apache hosts
			if (isset($_SERVER['SERVER_SOFTWARE']) && strstr($_SERVER['SERVER_SOFTWARE'], 'Apache')) {
				self::createHtaccess();
			}

			//and we are done
			OC_Config::setValue('installed', true);
		}

		return $error;
	}

	/**
	 * create .htaccess files for apache hosts
	 */
	private static function createHtaccess() {
		$content = "<IfModule mod_fcgid.c>\n";
		$content.= "<IfModule mod_setenvif.c>\n";
		$content.= "<IfModule mod_headers.c>\n";
		$content.= "SetEnvIfNoCase ^Authorization$ \"(.+)\" XAUTHORIZATION=$1\n";
		$content.= "RequestHeader set XAuthorization %{XAUTHORIZATION}e env=XAUTHORIZATION\n";
		$content.= "</IfModule>\n";
		$content.= "</IfModule>\n";
		$content.= "</IfModule>\n";
		$content.= "ErrorDocument 403 ".OC::$WEBROOT."/core/templates/403.php\n";//custom 403 error page
		$content.= "ErrorDocument 404 ".OC::$WEBROOT."/core/templates/404.php\n";//custom 404 error page
		$content.= "<IfModule mod_php5.c>\n";
		$content.= "php_value upload_max_filesize 512M\n";//upload limit
		$content.= "php_value post_max_size 512M\n";
		$content.= "php_value memory_limit 512M\n";
		$content.= "<IfModule env_module>\n";
		$content.= "  SetEnv htaccessWorking true\n";
		$content.= "</IfModule>\n";
		$content.= "</IfModule>\n";
		$content.= "<IfModule mod_rewrite.c>\n";
		$content.= "RewriteEngine on\n";
		$content.= "RewriteRule .* - [env=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n";
		$content.= "RewriteRule ^.well-known/host-meta /public.php?service=host-meta [QSA,L]\n";
		$content.= "RewriteRule ^.well-known/carddav /remote.php/carddav/ [R]\n";
		$content.= "RewriteRule ^.well-known/caldav /remote.php/caldav/ [R]\n";
		$content.= "RewriteRule ^apps/([^/]*)/(.*\.(css|php))$ index.php?app=$1&getfile=$2 [QSA,L]\n";
		$content.= "RewriteRule ^remote/(.*) remote.php [QSA,L]\n";
		$content.= "</IfModule>\n";
		$content.= "<IfModule mod_mime.c>\n";
		$content.= "AddType image/svg+xml svg svgz\n";
		$content.= "AddEncoding gzip svgz\n";
		$content.= "</IfModule>\n";
		$content.= "Options -Indexes\n";
		@file_put_contents(OC::$SERVERROOT.'/.htaccess', $content); //supress errors in case we don't have permissions for it

		self::protectDataDirectory();
	}

	public static function protectDataDirectory() {
		$content = "deny from all\n";
		$content.= "IndexIgnore *";
		file_put_contents(OC_Config::getValue('datadirectory', OC::$SERVERROOT.'/data').'/.htaccess', $content);
		file_put_contents(OC_Config::getValue('datadirectory', OC::$SERVERROOT.'/data').'/index.html', '');
	}

	/**
	 * @brief Post installation checks
	 */
	public static function postSetupCheck($params) {
		// setup was successful -> webdav testing now
		$l = self::getTrans();
		if (OC_Util::isWebDAVWorking()) {
			header("Location: ".OC::$WEBROOT.'/');
		} else {

			$error = $l->t('Your web server is not yet properly setup to allow files synchronization because the WebDAV interface seems to be broken.');
			$hint = $l->t('Please double check the <a href=\'%s\'>installation guides</a>.',
				'http://doc.owncloud.org/server/5.0/admin_manual/installation.html');

			$tmpl = new OC_Template('', 'error', 'guest');
			$tmpl->assign('errors', array(1 => array('error' => $error, 'hint' => $hint)));
			$tmpl->printPage();
			exit();
		}
	}
}
