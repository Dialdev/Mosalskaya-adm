<?php 
/**
 *	MODX Document Parser
 *	Function: This class contains the main document parsing functions
 *
 */
if (!defined('E_DEPRECATED')) define('E_DEPRECATED', 8192);
if (!defined('E_USER_DEPRECATED')) define('E_USER_DEPRECATED', 16384);

class DocumentParser {
    var $db; // db object
    var $event, $Event; // event object
    var $pluginEvent;
    var $config= null;
    var $rs;
    var $result;
    var $sql;
    var $table_prefix;
    var $debug;
    var $documentIdentifier;
    var $documentMethod;
    var $documentGenerated;
    var $documentContent;
    var $tstart;
    var $mstart;
    var $minParserPasses;
    var $maxParserPasses;
    var $documentObject;
    var $templateObject;
    var $snippetObjects;
    var $stopOnNotice;
    var $executedQueries;
    var $queryTime;
    var $currentSnippet;
    var $documentName;
    var $aliases;
    var $visitor;
    var $entrypage;
    var $documentListing;
    var $dumpSnippets;
    var $snippetsCode;
    var $snippetsCount=array();
    var $snippetsTime=array();
    var $chunkCache;
    var $snippetCache;
    var $contentTypes;
    var $dumpSQL;
    var $queryCode;
    var $virtualDir;
    var $placeholders;
    var $sjscripts;
    var $jscripts;
    var $loadedjscripts;
    var $documentMap;
    var $forwards= 3;
    var $error_reporting;
    var $dumpPlugins;
    var $pluginsCode;
    var $pluginsTime=array();
    var $aliasListing;
    private $version=array();

    /**
     * Document constructor
     *
     * @return DocumentParser
     */
    function DocumentParser() {
        global $database_server;
        if(substr(PHP_OS,0,3) === 'WIN' && $database_server==='localhost') $database_server = '127.0.0.1';
        $this->loadExtension('DBAPI') or die('Could not load DBAPI class.'); // load DBAPI class
        $this->dbConfig= & $this->db->config; // alias for backward compatibility
        $this->jscripts= array ();
        $this->sjscripts= array ();
        $this->loadedjscripts= array ();
        // events
        $this->event= new SystemEvent();
        $this->Event= & $this->event; //alias for backward compatibility
        $this->pluginEvent= array ();
        // set track_errors ini variable
        @ ini_set("track_errors", "1"); // enable error tracking in $php_errormsg
        $this->error_reporting = 1;
    }

    function __call($method_name,$arguments) {
        include_once(MODX_MANAGER_PATH . 'includes/extenders/deprecated.functions.inc.php');
        if(method_exists($this->old,$method_name)) $error_type=1;
        else                                       $error_type=3;
        
        if(!isset($this->config['error_reporting'])||1<$this->config['error_reporting'])
    	{
    		if($error_type==1)
        	{
	        	$title = 'Call deprecated method';
	        	$msg = $this->htmlspecialchars("\$modx->{$method_name}() is deprecated function");
    		}
    		else
    		{
	        	$title = 'Call undefined method';
	        	$msg = $this->htmlspecialchars("\$modx->{$method_name}() is undefined function");
    		}
	    	$info = debug_backtrace();
	    	$m[] = $msg;
	        if(!empty($this->currentSnippet))          $m[] = 'Snippet - ' . $this->currentSnippet;
	        elseif(!empty($this->event->activePlugin)) $m[] = 'Plugin - '  . $this->event->activePlugin;
	    	$m[] = $this->decoded_request_uri;
	    	$m[] = str_replace('\\','/',$info[0]['file']) . '(line:' . $info[0]['line'] . ')';
	    	$msg = implode('<br />', $m);
	        $this->logEvent(0, $error_type, $msg, $title);
        }
        if(method_exists($this->old,$method_name))
        	return call_user_func_array(array($this->old,$method_name),$arguments);
    }

    /**
     * Loads an extension from the extenders folder.
     * You can load any extension creating a boot file:
     * MODX_MANAGER_PATH."includes/extenders/{$extname}.extenders.inc.php"
     * $extname - extension name in lowercase
     *
     * @return boolean
     */
    function loadExtension($extname) {

        $extname = trim(str_replace(array('..','/','\\'),'',strtolower($extname)));

        $filename = MODX_MANAGER_PATH."includes/extenders/{$extname}.extenders.inc.php";

        return is_file($filename) ? include $filename : false;
    }

    /**
     * Returns the current micro time
     *
     * @return float
     */
    function getMicroTime() {
        list ($usec, $sec)= explode(' ', microtime());
        return ((float) $usec + (float) $sec);
    }

    /**
     * Redirect
     *
     * @global string $base_url
     * @global string $site_url
     * @param string $url
     * @param int $count_attempts
     * @param type $type
     * @param type $responseCode
     * @return boolean
     */
    function sendRedirect($url, $count_attempts= 0, $type= '', $responseCode= '') {
        if (empty ($url)) {
            return false;
        } else {
            if ($count_attempts == 1) {
                // append the redirect count string to the url
                $currentNumberOfRedirects= isset ($_REQUEST['err']) ? $_REQUEST['err'] : 0;
                if ($currentNumberOfRedirects > 3) {
                    $this->messageQuit('Redirection attempt failed - please ensure the document you\'re trying to redirect to exists. <p>Redirection URL: <i>' . $url . '</i></p>');
                } else {
                    $currentNumberOfRedirects += 1;
                    if (strpos($url, "?") > 0) {
                        $url .= "&err=$currentNumberOfRedirects";
                    } else {
                        $url .= "?err=$currentNumberOfRedirects";
                    }
                }
            }
            if ($type == 'REDIRECT_REFRESH') {
                $header= 'Refresh: 0;URL=' . $url;
            }
            elseif ($type == 'REDIRECT_META') {
                $header= '<META HTTP-EQUIV="Refresh" CONTENT="0; URL=' . $url . '" />';
                echo $header;
                exit;
            }
            elseif ($type == 'REDIRECT_HEADER' || empty ($type)) {
                // check if url has /$base_url
                global $base_url, $site_url;
                if (substr($url, 0, strlen($base_url)) == $base_url) {
                    // append $site_url to make it work with Location:
                    $url= $site_url . substr($url, strlen($base_url));
                }
                if (strpos($url, "\n") === false) {
                    $header= 'Location: ' . $url;
                } else {
                    $this->messageQuit('No newline allowed in redirect url.');
                }
            }
            if ($responseCode && (strpos($responseCode, '30') !== false)) {
                header($responseCode);
            }
            header($header);
            exit();
        }
    }

    /**
     * Forward to another page
     *
     * @param int $id
     * @param string $responseCode
     */
    function sendForward($id, $responseCode= '') {
        if ($this->forwards > 0) {
            $this->forwards= $this->forwards - 1;
            $this->documentIdentifier= $id;
            $this->documentMethod= 'id';
            $this->documentObject= $this->getDocumentObject('id', $id);
            if ($responseCode) {
                header($responseCode);
            }
            $this->prepareResponse();
            exit();
        } else {
            header('HTTP/1.0 500 Internal Server Error');
            die('<h1>ERROR: Too many forward attempts!</h1><p>The request could not be completed due to too many unsuccessful forward attempts.</p>');
        }
    }

    /**
     * Redirect to the error page, by calling sendForward(). This is called for example when the page was not found.
     */
    function sendErrorPage() {
        // invoke OnPageNotFound event
        $this->invokeEvent('OnPageNotFound');
           $url = $this->config['error_page'] ? $this->config['error_page'] : $this->config['site_start'];
           $this->sendForward($url, 'HTTP/1.0 404 Not Found');
        exit();
    }

    function sendUnauthorizedPage() {
        // invoke OnPageUnauthorized event
        $_REQUEST['refurl'] = $this->documentIdentifier;
        $this->invokeEvent('OnPageUnauthorized');
        if ($this->config['unauthorized_page']) {
            $unauthorizedPage= $this->config['unauthorized_page'];
        } elseif ($this->config['error_page']) {
            $unauthorizedPage= $this->config['error_page'];
        } else {
            $unauthorizedPage= $this->config['site_start'];
        }
        $this->sendForward($unauthorizedPage, 'HTTP/1.1 401 Unauthorized');
        exit();
    }
    
    /**
     * Get MODX settings including, but not limited to, the system_settings table
     */
    function getSettings() {
        $tbl_system_settings   = $this->getFullTableName('system_settings');
        $tbl_web_user_settings = $this->getFullTableName('web_user_settings');
        $tbl_user_settings     = $this->getFullTableName('user_settings');
        if (!is_array($this->config) || empty ($this->config)) {
            if ($included= file_exists(MODX_BASE_PATH . 'assets/cache/siteCache.idx.php')) {
                $included= include_once (MODX_BASE_PATH . 'assets/cache/siteCache.idx.php');
            }
            if (!$included || !is_array($this->config) || empty ($this->config)) {
                include_once(MODX_MANAGER_PATH . 'processors/cache_sync.class.processor.php');
                $cache = new synccache();
                $cache->setCachepath(MODX_BASE_PATH . "assets/cache/");
                $cache->setReport(false);
                $rebuilt = $cache->buildCache($this);
                $included = false;
                if($rebuilt && $included= file_exists(MODX_BASE_PATH . 'assets/cache/siteCache.idx.php')) {
                    $included= include MODX_BASE_PATH . 'assets/cache/siteCache.idx.php';
                }
                if(!$included) {
                    $result= $this->db->select('setting_name, setting_value', $tbl_system_settings);
                    while ($row= $this->db->getRow($result)) {
                        $this->config[$row['setting_name']]= $row['setting_value'];
                    }
                }
            }

            // added for backwards compatibility - garry FS#104
            $this->config['etomite_charset'] = & $this->config['modx_charset'];

            // store base_url and base_path inside config array
            $this->config['base_url']= MODX_BASE_URL;
            $this->config['base_path']= MODX_BASE_PATH;
            $this->config['site_url']= MODX_SITE_URL;
            $this->config['valid_hostnames']= MODX_SITE_HOSTNAMES;
            $this->config['site_manager_url']=MODX_MANAGER_URL;
            $this->config['site_manager_path']=MODX_MANAGER_PATH;

            // load user setting if user is logged in
            $usrSettings= array ();
            if ($id= $this->getLoginUserID()) {
                $usrType= $this->getLoginUserType();
                if (isset ($usrType) && $usrType == 'manager')
                    $usrType= 'mgr';

                if ($usrType == 'mgr' && $this->isBackend()) {
                    // invoke the OnBeforeManagerPageInit event, only if in backend
                    $this->invokeEvent("OnBeforeManagerPageInit");
                }

                if (isset ($_SESSION[$usrType . 'UsrConfigSet'])) {
                    $usrSettings= & $_SESSION[$usrType . 'UsrConfigSet'];
                } else {
                    if ($usrType == 'web')
                    {
                        $from = $tbl_web_user_settings;
                        $where = "webuser='{$id}'";
                    }
                    else
                    {
                        $from = $tbl_user_settings;
                    	$where = "user='{$id}'";
                    }
                    $result= $this->db->select('setting_name, setting_value', $from, $where);
                    while ($row= $this->db->getRow($result))
                        $usrSettings[$row['setting_name']]= $row['setting_value'];
                    if (isset ($usrType))
                        $_SESSION[$usrType . 'UsrConfigSet']= $usrSettings; // store user settings in session
                }
            }
            if ($this->isFrontend() && $mgrid= $this->getLoginUserID('mgr')) {
                $musrSettings= array ();
                if (isset ($_SESSION['mgrUsrConfigSet'])) {
                    $musrSettings= & $_SESSION['mgrUsrConfigSet'];
                } else {
                    if ($result= $this->db->select('setting_name, setting_value', $tbl_user_settings, "user='{$mgrid}'")) {
                        while ($row= $this->db->getRow($result)) {
                            $musrSettings[$row['setting_name']]= $row['setting_value'];
                        }
                        $_SESSION['mgrUsrConfigSet']= $musrSettings; // store user settings in session
                    }
                }
                if (!empty ($musrSettings)) {
                    $usrSettings= array_merge($musrSettings, $usrSettings);
                }
            }
            $this->error_reporting = $this->config['error_reporting'];
            $this->config= array_merge($this->config, $usrSettings);
            $this->config['filemanager_path'] = str_replace('[(base_path)]',MODX_BASE_PATH,$this->config['filemanager_path']);
            $this->config['rb_base_dir']      = str_replace('[(base_path)]',MODX_BASE_PATH,$this->config['rb_base_dir']);            
        }
    }

    /**
     * Get the method by which the current document/resource was requested
     *
     * @return string 'alias' (friendly url alias) or 'id'
     */
    function getDocumentMethod() {
        // function to test the query and find the retrieval method
        if (!empty ($_REQUEST['q'])) { //LANG
            return "alias";
        }
        elseif (isset ($_REQUEST['id'])) {
            return "id";
        } else {
            return "none";
        }
    }

    /**
     * Returns the document identifier of the current request
     *
     * @param string $method id and alias are allowed
     * @return int
     */
    function getDocumentIdentifier($method) {
        // function to test the query and find the retrieval method
        $docIdentifier= $this->config['site_start'];
        switch ($method) {
            case 'alias' :
                $docIdentifier= $this->db->escape($_REQUEST['q']);
                break;
            case 'id' :
                if (!is_numeric($_REQUEST['id'])) {
                    $this->sendErrorPage();
                } else {
                    $docIdentifier= intval($_REQUEST['id']);
                }
                break;
        }
        return $docIdentifier;
    }

    /**
     * Check for manager login session
     *
     * @return boolean
     */
    function checkSession() {
        if (isset ($_SESSION['mgrValidated'])) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Checks, if a the result is a preview
     *
     * @return boolean
     */
    function checkPreview() {
        if ($this->checkSession() == true) {
            if (isset ($_REQUEST['z']) && $_REQUEST['z'] == 'manprev') {
                return true;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    /**
     * check if site is offline
     *
     * @return boolean
     */
    function checkSiteStatus() {
        $siteStatus= $this->config['site_status'];
        if ($siteStatus == 1) {
            // site online
            return true;
        }
        elseif ($siteStatus == 0 && $this->checkSession()) {
            // site offline but launched via the manager
            return true;
        } else {
            // site is offline
            return false;
        }
    }

     /**
     * Create a 'clean' document identifier with path information, friendly URL suffix and prefix.
     *
     * @param string $qOrig
     * @return string
     */
    function cleanDocumentIdentifier($qOrig) {
        (!empty($qOrig)) or $qOrig = $this->config['site_start'];
        $q= $qOrig;
        /* First remove any / before or after */
        if ($q[strlen($q) - 1] == '/')
            $q= substr($q, 0, -1);
        if ($q[0] == '/')
            $q= substr($q, 1);
        /* Save path if any */
        /* FS#476 and FS#308: only return virtualDir if friendly paths are enabled */
        if ($this->config['use_alias_path'] == 1) {
            $this->virtualDir= dirname($q);
            $this->virtualDir= ($this->virtualDir == '.' ? '' : $this->virtualDir);
            $q= basename($q);
        } else {
            $this->virtualDir= '';
        }
        $q= str_replace($this->config['friendly_url_prefix'], "", $q);
        $q= str_replace($this->config['friendly_url_suffix'], "", $q);
        if (is_numeric($q) && !isset($this->documentListing[$q])) { /* we got an ID returned, check to make sure it's not an alias */
            /* FS#476 and FS#308: check that id is valid in terms of virtualDir structure */
            if ($this->config['use_alias_path'] == 1) {
                if ((($this->virtualDir != '' && !isset($this->documentListing[$this->virtualDir . '/' . $q])) || ($this->virtualDir == '' && !isset($this->documentListing[$q]))) && (($this->virtualDir != '' && isset($this->documentListing[$this->virtualDir]) && in_array($q, $this->getChildIds($this->documentListing[$this->virtualDir], 1))) || ($this->virtualDir == '' && in_array($q, $this->getChildIds(0, 1))))) {
                    $this->documentMethod= 'id';
                    return $q;
                } else { /* not a valid id in terms of virtualDir, treat as alias */
                    $this->documentMethod= 'alias';
                    return $q;
                }
            } else {
                $this->documentMethod= 'id';
                return $q;
            }
        } else { /* we didn't get an ID back, so instead we assume it's an alias */
            if ($this->config['friendly_alias_urls'] != 1) {
                $q= $qOrig;
            }
            $this->documentMethod= 'alias';
            return $q;
        }
    }

    /**
     * Check the cache for a specific document/resource
     *
     * @param int $id
     * @return string
     */
    function checkCache($id) {
        $tbl_document_groups= $this->getFullTableName("document_groups");
        if ($this->config['cache_type'] == 2) {
           $md5_hash = '';
           if (!empty($_GET)) {
	           // Sort GET parameters so that the order of parameters on the HTTP request don't affect the generated cache ID.
	           $params = $_GET;
	           ksort($params);
	           $md5_hash = '_' . md5(http_build_query($params));
           }
           $cacheFile= "assets/cache/docid_" . $id .$md5_hash. ".pageCache.php";
        }else{
           $cacheFile= "assets/cache/docid_" . $id . ".pageCache.php";
        }
        if (file_exists($cacheFile)) {
            $this->documentGenerated= 0;
            $flContent = file_get_contents($cacheFile, false);
            $flContent= substr($flContent, 37); // remove php header
            $a= explode("<!--__MODxCacheSpliter__-->", $flContent, 2);
            if (count($a) == 1)
                return $a[0]; // return only document content
            else {
                $docObj= unserialize($a[0]); // rebuild document object
                // check page security
                if ($docObj['privateweb'] && isset ($docObj['__MODxDocGroups__'])) {
                    $pass= false;
                    $usrGrps= $this->getUserDocGroups();
                    $docGrps= explode(",", $docObj['__MODxDocGroups__']);
                    // check is user has access to doc groups
                    if (is_array($usrGrps)) {
                        foreach ($usrGrps as $k => $v)
                            if (in_array($v, $docGrps)) {
                                $pass= true;
                                break;
                            }
                    }
                    // diplay error pages if user has no access to cached doc
                    if (!$pass) {
                        if ($this->config['unauthorized_page']) {
                            // check if file is not public
                            $secrs= $this->db->select('count(id)', $tbl_document_groups, "document='{$id}'", '', '1');
                                $seclimit= $this->db->getValue($secrs);
                        }
                        if ($seclimit > 0) {
                            // match found but not publicly accessible, send the visitor to the unauthorized_page
                            $this->sendUnauthorizedPage();
                            exit; // stop here
                        } else {
                            // no match found, send the visitor to the error_page
                            $this->sendErrorPage();
                            exit; // stop here
                        }
                    }
                }
				// Grab the Scripts
				if (isset($docObj['__MODxSJScripts__'])) $this->sjscripts = $docObj['__MODxSJScripts__'];
				if (isset($docObj['__MODxJScripts__']))  $this->jscripts = $docObj['__MODxJScripts__'];

				// Remove intermediate variables
                unset($docObj['__MODxDocGroups__'], $docObj['__MODxSJScripts__'], $docObj['__MODxJScripts__']);

                $this->documentObject= $docObj;
                return $a[1]; // return document content
            }
        } else {
            $this->documentGenerated= 1;
            return "";
        }
    }

    /**
     * Final processing and output of the document/resource.
     * 
     * - runs uncached snippets
     * - add javascript to <head>
     * - removes unused placeholders
     * - converts URL tags [~...~] to URLs
     *
     * @param boolean $noEvent Default: false
     */
    function outputContent($noEvent= false) {
        $this->documentOutput= $this->documentContent;

        if ($this->documentGenerated == 1 && $this->documentObject['cacheable'] == 1 && $this->documentObject['type'] == 'document' && $this->documentObject['published'] == 1) {
    		if (!empty($this->sjscripts)) $this->documentObject['__MODxSJScripts__'] = $this->sjscripts;
    		if (!empty($this->jscripts)) $this->documentObject['__MODxJScripts__'] = $this->jscripts;
        }

        // check for non-cached snippet output
        if (strpos($this->documentOutput, '[!') > -1) {
            $this->documentOutput= str_replace('[!', '[[', $this->documentOutput);
            $this->documentOutput= str_replace('!]', ']]', $this->documentOutput);

            // Parse document source
            $this->documentOutput= $this->parseDocumentSource($this->documentOutput);
    	}

    	// Moved from prepareResponse() by sirlancelot
    	// Insert Startup jscripts & CSS scripts into template - template must have a <head> tag
    	if ($js= $this->getRegisteredClientStartupScripts()) {
    		// change to just before closing </head>
    		// $this->documentContent = preg_replace("/(<head[^>]*>)/i", "\\1\n".$js, $this->documentContent);
    		$this->documentOutput= preg_replace("/(<\/head>)/i", $js . "\n\\1", $this->documentOutput);
    	}

    	// Insert jscripts & html block into template - template must have a </body> tag
    	if ($js= $this->getRegisteredClientScripts()) {
    		$this->documentOutput= preg_replace("/(<\/body>)/i", $js . "\n\\1", $this->documentOutput);
    	}
    	// End fix by sirlancelot

        // remove all unused placeholders
        if (strpos($this->documentOutput, '[+') > -1) {
            $matches= array ();
            preg_match_all('~\[\+(.*?)\+\]~s', $this->documentOutput, $matches);
            if ($matches[0])
                $this->documentOutput= str_replace($matches[0], '', $this->documentOutput);
        }

        $this->documentOutput= $this->rewriteUrls($this->documentOutput);

        // send out content-type and content-disposition headers
        if (IN_PARSER_MODE == "true") {
            $type= !empty ($this->contentTypes[$this->documentIdentifier]) ? $this->contentTypes[$this->documentIdentifier] : "text/html";
            header('Content-Type: ' . $type . '; charset=' . $this->config['modx_charset']);
//            if (($this->documentIdentifier == $this->config['error_page']) || $redirect_error)
//                header('HTTP/1.0 404 Not Found');
            if (!$this->checkPreview() && $this->documentObject['content_dispo'] == 1) {
                if ($this->documentObject['alias'])
                    $name= $this->documentObject['alias'];
                else {
                    // strip title of special characters
                    $name= $this->documentObject['pagetitle'];
                    $name= strip_tags($name);
                    $name= strtolower($name);
                    $name= preg_replace('/&.+?;/', '', $name); // kill entities
                    $name= preg_replace('/[^\.%a-z0-9 _-]/', '', $name);
                    $name= preg_replace('/\s+/', '-', $name);
                    $name= preg_replace('|-+|', '-', $name);
                    $name= trim($name, '-');
                }
                $header= 'Content-Disposition: attachment; filename=' . $name;
                header($header);
            }
        }

        $stats = $this->getTimerStats($this->tstart);
        
        $out =& $this->documentOutput;
        $out= str_replace("[^q^]", $stats['queries'] , $out);
        $out= str_replace("[^qt^]", $stats['queryTime'] , $out);
        $out= str_replace("[^p^]", $stats['phpTime'] , $out);
        $out= str_replace("[^t^]", $stats['totalTime'] , $out);
        $out= str_replace("[^s^]", $stats['source'] , $out);
        $out= str_replace("[^m^]", $stats['phpMemory'], $out);
        //$this->documentOutput= $out;

        // invoke OnWebPagePrerender event
        if (!$noEvent) {
            $this->invokeEvent('OnWebPagePrerender');
        }
        global $sanitize_seed;
        if(strpos($this->documentOutput, $sanitize_seed)!==false) {
            $this->documentOutput = str_replace($sanitize_seed, '', $this->documentOutput);
        }

        echo $this->documentOutput;

        if ($this->dumpSQL) echo $this->queryCode;
        if ($this->dumpSnippets) {
            $sc = "";
            $tt = 0;
            foreach ($this->snippetsTime as $s=>$t) {
                $sc .= "$s: ".$this->snippetsCount[$s]." (".sprintf("%2.2f ms", $t*1000).")<br>";
                $tt += $t;
            }
            echo "<fieldset><legend><b>Snippets</b> (".count($this->snippetsTime)." / ".sprintf("%2.2f ms", $tt*1000).")</legend>{$sc}</fieldset><br />";
            echo $this->snippetsCode;
        }
        if ($this->dumpPlugins) {
            $ps = "";
            $tc = 0;
            foreach ($this->pluginsTime as $s=>$t) {
                $ps .= "$s (".sprintf("%2.2f ms", $t*1000).")<br>";
                $tt += $t;
            }
            echo "<fieldset><legend><b>Plugins</b> (".count($this->pluginsTime)." / ".sprintf("%2.2f ms", $tt*1000).")</legend>{$ps}</fieldset><br />";
            echo $this->pluginsCode;
        }

        ob_end_flush();
    }

    function getTimerStats($tstart) {
        $stats = array();

        $stats['totalTime'] = ($this->getMicroTime() - $tstart);
        $stats['queryTime'] = $this->queryTime;
        $stats['phpTime'] = $stats['totalTime'] - $stats['queryTime'];

        $stats['queryTime'] = sprintf("%2.4f s", $stats['queryTime']);
        $stats['totalTime'] = sprintf("%2.4f s", $stats['totalTime']);
        $stats['phpTime'] = sprintf("%2.4f s", $stats['phpTime']);
        $stats['source'] = $this->documentGenerated == 1 ? "database" : "cache";
        $stats['queries'] = isset ($this->executedQueries) ? $this->executedQueries : 0;
        $stats['phpMemory'] = (memory_get_peak_usage(true) / 1024 / 1024) . " mb";

        return $stats;
    }
    
    /**
     * Checks the publish state of page
     */
    function checkPublishStatus() {
        $cacheRefreshTime= 0;
        @include $this->config["base_path"] . "assets/cache/sitePublishing.idx.php";
        $timeNow= time() + $this->config['server_offset_time'];
        if ($cacheRefreshTime <= $timeNow && $cacheRefreshTime != 0) {
            // now, check for documents that need publishing
            $this->db->update(
                array(
                    'published'   => 1,
                    'publishedon' => $timeNow,
                ), $this->getFullTableName('site_content'), "pub_date <= {$timeNow} AND pub_date!=0 AND published=0");

            // now, check for documents that need un-publishing
            $this->db->update(
                array(
                    'published'   => 0,
                    'publishedon' => 0,
                ), $this->getFullTableName('site_content'), "unpub_date <= {$timeNow} AND unpub_date!=0 AND published=1");

            // clear the cache
            $this->clearCache('full');
        }
    }

    /**
     * Final jobs.
     *
     * - cache page
     */
    function postProcess() {
        // if the current document was generated, cache it!
        if ($this->documentGenerated == 1 && $this->documentObject['cacheable'] == 1 && $this->documentObject['type'] == 'document' && $this->documentObject['published'] == 1) {
            $basepath= $this->config["base_path"] . "assets/cache";
            // invoke OnBeforeSaveWebPageCache event
            $this->invokeEvent("OnBeforeSaveWebPageCache");
            if ($this->config['cache_type'] == 2) {
                $md5_hash = '';
                if (!empty($_GET)) {
	                // Sort GET parameters so that the order of parameters on the HTTP request don't affect the generated cache ID.
	                $params = $_GET;
	                ksort($params);
	                $md5_hash = '_' . md5(http_build_query($params));
                }
                $pageCache = $md5_hash .".pageCache.php";
            }else{
                $pageCache = ".pageCache.php";
            }

            if ($fp= @ fopen($basepath . "/docid_" . $this->documentIdentifier . $pageCache, "w")) {
                // get and store document groups inside document object. Document groups will be used to check security on cache pages
                $rs = $this->db->select('document_group', $this->getFullTableName("document_groups"), "document='{$this->documentIdentifier}'");
                $docGroups= $this->db->getColumn("document_group", $rs);

				// Attach Document Groups and Scripts
				if (is_array($docGroups)) $this->documentObject['__MODxDocGroups__'] = implode(",", $docGroups);

                $docObjSerial= serialize($this->documentObject);
                $cacheContent= $docObjSerial . "<!--__MODxCacheSpliter__-->" . $this->documentContent;
                fputs($fp, "<?php die('Unauthorized access.'); ?>$cacheContent");
                fclose($fp);
            }
        }

        // Useful for example to external page counters/stats packages
        $this->invokeEvent('OnWebPageComplete');

        // end post processing
    }

    function getTagsFromContent($content,$left='[+',$right='+]') {
        $hash = explode($left,$content);
        foreach($hash as $i=>$v) {
          if(0<$i) $hash[$i] = $left.$v;
        }
        
        $i=0;
        $count = count($hash);
        $safecount = 0;
        $temp_hash = array();
        while(0<$count) {
            $open  = 1;
            $close = 0;
            $safecount++;
            if(1000<$safecount) break;
            while($close < $open && 0 < $count) {
                $safecount++;
                if(!isset($temp_hash[$i])) $temp_hash[$i] = '';
                if(1000<$safecount) break;
                $remain = array_shift($hash);
                $remain = explode($right,$remain);
                foreach($remain as $v)
            	{
            		if($close < $open)
                	{
                		$close++;
                		$temp_hash[$i] .= $v . $right;
            		}
            		else break;
                }
                $count = count($hash);
                if(0<$i && strpos($temp_hash[$i],$right)===false) $open++;
            }
            $i++;
        }
        $matches=array();
        $i = 0;
        foreach($temp_hash as $v) {
            if(strpos($v,$left)!==false) {
                $v = substr($v,0,strrpos($v,$right));
                $matches[0][$i] = $v . $right;
                $matches[1][$i] = substr($v,strlen($left));
                $i++;
            }
        }
        return $matches;
    }
    
    /**
     * Merge content fields and TVs
     *
     * @param string $template
     * @return string
     */
    function mergeDocumentContent($content) {
        if (strpos($content, '[*') === false)
			return $content;
		$replace = array();
		$matches = $this->getTagsFromContent($content, '[*', '*]');
		if ($matches) {
			for ($i = 0; $i < count($matches[1]); $i++) {
				if ($matches[1][$i]) {
					$key = $matches[1][$i];
					$key = substr($key, 0, 1) == '#' ? substr($key, 1) : $key; // remove # for QuickEdit format
					$value = $this->documentObject[$key];
					if (is_array($value)) {
						include_once MODX_MANAGER_PATH . 'includes/tmplvars.format.inc.php';
						include_once MODX_MANAGER_PATH . 'includes/tmplvars.commands.inc.php';
						$value = getTVDisplayFormat($value[0], $value[1], $value[2], $value[3], $value[4]);
					}
					$replace[$i] = $value;
				}
			}
			$content = str_replace($matches[0], $replace, $content);
		}
		return $content;
	}

	/**
     * Merge system settings
     *
     * @param string $template
     * @return string
     */
    function mergeSettingsContent($content) {
        if (strpos($content, '[(') === false)
			return $content;
		$replace = array();
		$matches = $this->getTagsFromContent($content, '[(', ')]');
		if ($matches) {
			for ($i = 0; $i < count($matches[1]); $i++) {
				if ($matches[1][$i] && array_key_exists($matches[1][$i], $this->config))
					$replace[$i] = $this->config[$matches[1][$i]];
			}

			$content = str_replace($matches[0], $replace, $content);
		}
		return $content;
	}

	/**
     * Merge chunks
     *
     * @param string $content
     * @return string
     */
    function mergeChunkContent($content) {
		if (strpos($content, '{{') === false)
			return $content;
		$replace = array();
		$matches = $this->getTagsFromContent($content, '{{', '}}');
		if ($matches) {
			for ($i = 0; $i < count($matches[1]); $i++) {
				if ($matches[1][$i]) {
					if (isset($this->chunkCache[$matches[1][$i]])) {
						$replace[$i] = $this->chunkCache[$matches[1][$i]];
					} else {
						$result = $this->db->select('snippet', $this->getFullTableName('site_htmlsnippets'), "name='".$this->db->escape($matches[1][$i])."'");
						if ($snippet = $this->db->getValue($result)) {
							$this->chunkCache[$matches[1][$i]] = $snippet;
							$replace[$i] = $snippet;
						} else {
							$this->chunkCache[$matches[1][$i]] = '';
							$replace[$i] = '';
						}
					}
				}
			}
			$content = str_replace($matches[0], $replace, $content);
			$content = $this->mergeSettingsContent($content);
		}
		return $content;
	}

    /**
     * Merge placeholder values
     *
     * @param string $content
     * @return string
     */
    function mergePlaceholderContent($content) {
		if (strpos($content, '[+') === false)
			return $content;
		$replace = array();
		$content = $this->mergeSettingsContent($content);
		$matches = $this->getTagsFromContent($content, '[+', '+]');
		if ($matches) {
			for ($i = 0; $i < count($matches[1]); $i++) {
				$v = '';
				$key = $matches[1][$i];
				if ($key && is_array($this->placeholders) && array_key_exists($key, $this->placeholders))
					$v = $this->placeholders[$key];
				if ($v === '')
					unset($matches[0][$i]); // here we'll leave empty placeholders for last.
				else
					$replace[$i] = $v;
			}
			$content = str_replace($matches[0], $replace, $content);
		}
		return $content;
	}

	/**
	 * Detect PHP error according to MODX error level
	 *
	 * @param integer $error PHP error level
	 * @return boolean Error detected
	 */

	function detectError($error) {
		$detected = FALSE;
		if ($this->config['error_reporting'] == 99 && $error)
			$detected = TRUE;
		elseif ($this->config['error_reporting'] == 2 && ($error & ~E_NOTICE))
			$detected = TRUE;
		elseif ($this->config['error_reporting'] == 1 && ($error & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT))
			$detected = TRUE;
		return $detected;
	}

    /**
     * Run a plugin
     *
     * @param string $pluginCode Code to run
     * @param array $params
     */
    function evalPlugin($pluginCode, $params) {
		$etomite = $modx = & $this;
		$modx->event->params = & $params; // store params inside event object
		if (is_array($params)) {
			extract($params, EXTR_SKIP);
		}
		ob_start();
		eval($pluginCode);
		$msg = ob_get_contents();
		ob_end_clean();
		if ((0 < $this->config['error_reporting']) && $msg && isset($php_errormsg)) {
			$error_info = error_get_last();
			if ($this->detectError($error_info['type'])) {
				$msg = ($msg === false) ? 'ob_get_contents() error' : $msg;
				$this->messageQuit('PHP Parse Error', '', true, $error_info['type'], $error_info['file'], 'Plugin', $error_info['message'], $error_info['line'], $msg);
				if ($this->isBackend()) {
					$this->event->alert('An error occurred while loading. Please see the event log for more information.<p>' . $msg . '</p>');
				}
			}
		} else {
			echo $msg;
		}
		unset($modx->event->params);
	}

    /**
     * Run a snippet
     *
     * @param string $snippet Code to run
     * @param array $params
     * @return string
     */
    function evalSnippet($snippet, $params) {
		$etomite = $modx = & $this;
		$modx->event->params = & $params; // store params inside event object
		if (is_array($params)) {
			extract($params, EXTR_SKIP);
		}
		ob_start();
		$snip = eval($snippet);
		$msg = ob_get_contents();
		ob_end_clean();
		if ((0 < $this->config['error_reporting']) && isset($php_errormsg)) {
			$error_info = error_get_last();
			if ($this->detectError($error_info['type'])) {
				$msg = ($msg === false) ? 'ob_get_contents() error' : $msg;
				$this->messageQuit('PHP Parse Error', '', true, $error_info['type'], $error_info['file'], 'Snippet', $error_info['message'], $error_info['line'], $msg);
				if ($this->isBackend()) {
					$this->event->alert('An error occurred while loading. Please see the event log for more information<p>' . $msg . $snip . '</p>');
				}
			}
		}
		unset($modx->event->params);
		$this->currentSnippet = '';
		if (is_array($snip) || is_object($snip)) {
			return $snip;
		} else {
			return $msg . $snip;
		}
	}

    /**
     * Run snippets as per the tags in $documentSource and replace the tags with the returned values.
     *
     * @param string $documentSource
     * @return string
     */
   function evalSnippets($documentSource) {
        if(strpos($documentSource,'[[')===false) return $documentSource;
        $etomite= & $this;
        
        $stack = $documentSource;
        unset($documentSource);
        
        
        $passes = $this->minParserPasses;
        
        for($i= 0; $i < $passes; $i++)
        {
            $stack=$this->mergeSettingsContent($stack);
            if($i == ($passes -1)) $bt = md5($stack);
            $pieces = array();
            $pieces = explode('[[', $stack);
            $stack = '';
            $loop_count = 0;
            
            foreach($pieces as $piece)
            {
                if($loop_count < 1)                 $result = $piece;
                elseif(strpos($piece,']]')===false) $result = '[[' . $piece;
                else                                $result = $this->_get_snip_result($piece);
                
                $stack .= $result;
                $loop_count++; // End of foreach loop
            }
            if($i == ($passes -1) && $i < ($this->maxParserPasses - 1))
            {
                if($bt != md5($stack)) $passes++;
            }
        }
        return $stack;
    }
    
    private function _get_snip_result($piece)
    {
        if ($this->dumpSnippets == 1) $sniptime = $this->getMicroTime();
        $snip_call        = $this->_split_snip_call($piece);
        $snip_name        = $snip_call['name'];
        $except_snip_call = $snip_call['except_snip_call'];
        
        $key = $snip_call['name'];
        
        $snippetObject = $this->_get_snip_properties($snip_call);
        
        $params   = array ();
        $this->currentSnippet = $snippetObject['name'];
        
        if(isset($snippetObject['properties'])) $params = $this->parseProperties($snippetObject['properties']);
        else                                    $params = '';
        // current params
        if(!empty($snip_call['params']))
        {
            $snip_call['params'] = ltrim($snip_call['params'], '?');
            
            $i = 0;
            $limit = 50;
            $params_stack = $snip_call['params'];
            while(!empty($params_stack) && $i < $limit)
            {
				if(strpos($params_stack,'=')!==false) list($pname,$params_stack) = explode('=',$params_stack,2);
				else {
					$pname=$params_stack;
					$params_stack = '';
				}
                $params_stack = trim($params_stack);
                $delim = substr($params_stack, 0, 1);
                $temp_params = array();
                switch($delim)
                {
                    case '`':
                    case '"':
                    case "'":
                        $params_stack = substr($params_stack,1);
                        list($pvalue,$params_stack) = explode($delim,$params_stack,2);
                        $params_stack = trim($params_stack);
                        if(substr($params_stack, 0, 2)==='//')
                        {
                            $params_stack = strstr($params_stack, "\n");
                        }
                        break;
                    default:
                        if(strpos($params_stack, '&')!==false)
                        {
                            list($pvalue,$params_stack) = explode('&',$params_stack,2);
                        }
                        else $pvalue = $params_stack;
                        $pvalue = trim($pvalue);
                        $delim = '';
                }
                if($delim !== "'")
                {
                    $pvalue = (strpos($pvalue,'[*')!==false) ? $this->mergeDocumentContent($pvalue) : $pvalue;
                    $pvalue = (strpos($pvalue,'[(')!==false) ? $this->mergeSettingsContent($pvalue) : $pvalue;
                    $pvalue = (strpos($pvalue,'{{')!==false) ? $this->mergeChunkContent($pvalue)    : $pvalue;
                    $pvalue = (strpos($pvalue,'[+')!==false) ? $this->mergePlaceholderContent($pvalue) : $pvalue;
                }
                
                $pname  = str_replace('&amp;', '', $pname);
                $pname  = trim($pname);
                $pname  = trim($pname,'&');
                $params[$pname] = $pvalue;
                $params_stack = trim($params_stack);
                if($params_stack!=='') $params_stack = '&' . ltrim($params_stack,'&');
                $i++;
            }
            unset($temp_params);
        }
        $value = $this->evalSnippet($snippetObject['content'], $params);
        
        if($this->dumpSnippets == 1)
        {
            $sniptime = $this->getMicroTime() - $sniptime;
            $this->snippetsCode .= '<fieldset><legend><b>' . $snippetObject['name'] . '</b> (' . sprintf('%2.2f ms', $sniptime*1000) . ')</legend>';
            if ($this->event->name) $this->snippetsCode .= 'Current Event  => ' . $this->event->name . '<br>';
            if ($this->event->activePlugin) $this->snippetsCode .= 'Current Plugin => ' . $this->event->activePlugin . '<br>';
            if (is_array($params)) foreach ($params as $k=>$v) $this->snippetsCode .=  $k . ' => ' . print_r($v, true) . '<br>';
            $this->snippetsCode .= '<textarea style="width:60%;height:200px">' . htmlentities($value,ENT_NOQUOTES,$this->config['modx_charset']) . '</textarea>';
            $this->snippetsCode .= '</fieldset><br />';
            $this->snippetsCount[$snippetObject['name']]++;
            $this->snippetsTime[$snippetObject['name']] += $sniptime;
        }
        return $value . $except_snip_call;
    }
    
    private function _split_snip_call($src)
    {
        list($call,$snip['except_snip_call']) = explode(']]', $src, 2);
        if(strpos($call, '?') !== false && strpos($call, "\n")!==false && strpos($call, '?') < strpos($call, "\n"))
        {
            list($name,$params) = explode('?',$call,2);
        }
        elseif(strpos($call, '?') !== false && strpos($call, "\n")!==false && strpos($call, "\n") < strpos($call, '?'))
        {
            list($name,$params) = explode("\n",$call,2);
        }
        elseif(strpos($call, '?') !== false)
        {
            list($name,$params) = explode('?',$call,2);
        }
        elseif((strpos($call, '&') !== false) && (strpos($call, '=') !== false) && (strpos($call, '?') === false))
        {
            list($name,$params) = explode('&',$call,2);
            $params = "&{$params}";
        }
        elseif(strpos($call, "\n") !== false)
        {
            list($name,$params) = explode("\n",$call,2);
        }
        else
        {
            $name   = $call;
            $params = '';
        }
        $snip['name']   = trim($name);
        $snip['params'] = $params;
        return $snip;
    }
    
    private function _get_snip_properties($snip_call)
    {
        $snip_name  = $snip_call['name'];
        
        if(isset($this->snippetCache[$snip_name]))
        {
            $snippetObject['name']    = $snip_name;
            $snippetObject['content'] = $this->snippetCache[$snip_name];
            if(isset($this->snippetCache[$snip_name . 'Props']))
            {
                $snippetObject['properties'] = $this->snippetCache[$snip_name . 'Props'];
            }
        }
        else
        {
            $tbl_snippets  = $this->getFullTableName('site_snippets');
            $esc_snip_name = $this->db->escape($snip_name);
            // get from db and store a copy inside cache
            $result= $this->db->select('name,snippet,properties',$tbl_snippets,"name='{$esc_snip_name}'");
                if($row = $this->db->getRow($result))
                {
                    $snippetObject['name']       = $row['name'];
                    $snippetObject['content']    = $this->snippetCache[$snip_name]           = $row['snippet'];
                    $snippetObject['properties'] = $this->snippetCache[$snip_name . 'Props'] = $row['properties'];
                } else {
                    $snippetObject['name']       = $snip_name;
                    $snippetObject['content']    = $this->snippetCache[$snip_name] = 'return false;';
                    $snippetObject['properties'] = '';
                }
        }
        return $snippetObject;
    }
    
    
    function toAlias($text) {
        $suff= $this->config['friendly_url_suffix'];
        return str_replace(array('.xml'.$suff,'.rss'.$suff,'.js'.$suff,'.css'.$suff),array('.xml','.rss','.js','.css'),$text);
    }
    
    function makeFriendlyURL($pre, $suff, $alias, $isfolder=0, $id=0) {
    	
        if ($id == $this->config['site_start'] && $this->config['seostrict']==='1') {return $this->config['base_url'];}
        $Alias = explode('/',$alias);
        $alias = array_pop($Alias);
        $dir = implode('/', $Alias);
        unset($Alias);
        if($this->config['make_folders']==='1' && $isfolder==1) $suff = '/';
        return ($dir != '' ? "$dir/" : '') . $pre . $alias . $suff;
    }
    
    /** 
     * Convert URL tags [~...~] to URLs
     *
     * @param string $documentSource
     * @return string
     */
    function rewriteUrls($documentSource) {
        // rewrite the urls
        if ($this->config['friendly_urls'] == 1) {
            $aliases= array ();
            /* foreach ($this->aliasListing as $item) {
                $aliases[$item['id']]= (strlen($item['path']) > 0 ? $item['path'] . '/' : '') . $item['alias'];
                $isfolder[$item['id']]= $item['isfolder'];
            } */
			foreach($this->documentListing as $key=>$val){
				$aliases[$val] = $key;
				$isfolder[$val] = $this->aliasListing[$val]['isfolder'];
            }
            $in= '!\[\~([0-9]+)\~\]!ise'; // Use preg_replace with /e to make it evaluate PHP
            $isfriendly= ($this->config['friendly_alias_urls'] == 1 ? 1 : 0);
            $pref= $this->config['friendly_url_prefix'];
            $suff= $this->config['friendly_url_suffix'];
            $thealias= '$aliases[\\1]';
            $thefolder= '$isfolder[\\1]';
            if ($this->config['seostrict']=='1'){
			
               $found_friendlyurl= "\$this->toAlias(\$this->makeFriendlyURL('$pref','$suff',$thealias,$thefolder,'\\1'))";
            }else{
               $found_friendlyurl= "\$this->makeFriendlyURL('$pref','$suff',$thealias,$thefolder,'\\1')";
            }
            $not_found_friendlyurl= "\$this->makeFriendlyURL('$pref','$suff','" . '\\1' . "')";
            $out= "({$isfriendly} && isset({$thealias}) ? {$found_friendlyurl} : {$not_found_friendlyurl})";
            $documentSource= preg_replace($in, $out, $documentSource);
			
        } else {
            $in= '!\[\~([0-9]+)\~\]!is';
            $out= "index.php?id=" . '\1';
            $documentSource= preg_replace($in, $out, $documentSource);
        }
		
        return $documentSource;
    }
	
	function sendStrictURI(){
        // FIX URLs
        if (empty($this->documentIdentifier) || $this->config['seostrict']=='0' || $this->config['friendly_urls']=='0')
         	return;
        if ($this->config['site_status'] == 0) return;
        
        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') ? 'https' : 'http';
        $len_base_url = strlen($this->config['base_url']);
        if(strpos($_SERVER['REQUEST_URI'],'?'))
        	list($url_path,$url_query_string) = explode('?', $_SERVER['REQUEST_URI'],2);
        else $url_path = $_SERVER['REQUEST_URI'];
		$url_path = $_GET['q'];//LANG
			
		
        if(substr($url_path,0,$len_base_url)===$this->config['base_url'])
        	$url_path = substr($url_path,$len_base_url);
			
        $strictURL =  $this->toAlias($this->makeUrl($this->documentIdentifier));
				
        if(substr($strictURL,0,$len_base_url)===$this->config['base_url'])
        	$strictURL = substr($strictURL,$len_base_url);
        $http_host = $_SERVER['HTTP_HOST'];
        $requestedURL = "{$scheme}://{$http_host}" . '/'.$_GET['q']; //LANG
		
        $site_url = $this->config['site_url'];
		
        if ($this->documentIdentifier == $this->config['site_start']){
            if ($requestedURL != $this->config['site_url']){
                // Force redirect of site start
                // $this->sendErrorPage();
                $qstring = isset($url_query_string) ? preg_replace("#(^|&)(q|id)=[^&]+#", '', $url_query_string) : ''; // Strip conflicting id/q from query string
                if ($qstring) $url = "{$site_url}?{$qstring}";
                else          $url = $site_url;
                if ($this->config['base_url'] != $_SERVER['REQUEST_URI']){	
	                if (empty($_POST)){
	                	if (('/?'.$qstring) != $_SERVER['REQUEST_URI']) {
	                		$this->sendRedirect($url,0,'REDIRECT_HEADER', 'HTTP/1.0 301 Moved Permanently');
	                		exit(0);
	                	}
	                }
	            }  
             }
        }elseif ($url_path != $strictURL && $this->documentIdentifier != $this->config['error_page']){
             // Force page redirect
        	//$strictURL = ltrim($strictURL,'/');
			
            if(!empty($url_query_string))
            	$qstring = preg_replace("#(^|&)(q|id)=[^&]+#", '', $url_query_string);  // Strip conflicting id/q from query string
            if ($qstring) $url = "{$site_url}{$strictURL}?{$qstring}";
            else          $url = "{$site_url}{$strictURL}";
            $this->sendRedirect($url,0,'REDIRECT_HEADER', 'HTTP/1.0 301 Moved Permanently');
            exit(0);
        }
        return;
    }

    /**
     * Get all db fields and TVs for a document/resource
     *
     * @param string $method
     * @param mixed $identifier
     * @return array
     */
    function getDocumentObject($method, $identifier, $isPrepareResponse=false) {
        $tblsc= $this->getFullTableName("site_content");
        $tbldg= $this->getFullTableName("document_groups");

        // allow alias to be full path
        if($method == 'alias') {
            $identifier = $this->cleanDocumentIdentifier($identifier);
            $method = $this->documentMethod;
        }
        if($method == 'alias' && $this->config['use_alias_path'] && array_key_exists($identifier, $this->documentListing)) {
            $method = 'id';
            $identifier = $this->documentListing[$identifier];
        }
        // get document groups for current user
        if ($docgrp= $this->getUserDocGroups())
            $docgrp= implode(",", $docgrp);
        // get document
        $access= ($this->isFrontend() ? "sc.privateweb=0" : "1='" . $_SESSION['mgrRole'] . "' OR sc.privatemgr=0") .
         (!$docgrp ? "" : " OR dg.document_group IN ($docgrp)");
        $result= $this->db->select(
			'sc.*',
			"{$tblsc} sc
				LEFT JOIN {$tbldg} dg ON dg.document = sc.id",
			"sc.{$method} = '{$identifier}' AND ({$access})",
			"",
			1);
        $rowCount= $this->db->getRecordCount($result);
        if ($rowCount < 1) {
            if ($this->config['unauthorized_page']) {
                // method may still be alias, while identifier is not full path alias, e.g. id not found above
                if ($method === 'alias') {
                    $secrs = $this->db->select('count(dg.id)', "{$tbldg} as dg, {$tblsc} as sc", "dg.document = sc.id AND sc.alias = '{$identifier}'", '', 1);
                } else {
                    $secrs = $this->db->select('count(id)', $tbldg, "document = '{$identifier}'", '', 1);
                }
                // check if file is not public
                    $seclimit= $this->db->getValue($secrs);
            }
            if ($seclimit > 0) {
                // match found but not publicly accessible, send the visitor to the unauthorized_page
                $this->sendUnauthorizedPage();
                exit; // stop here
            } else {
                $this->sendErrorPage();
                exit;
            }
        }

        # this is now the document :) #
        $documentObject= $this->db->getRow($result);
        if($isPrepareResponse==='prepareResponse') $this->documentObject = & $documentObject;
        $this->invokeEvent('OnLoadDocumentObject');
    	if ($documentObject['template']) {
        // load TVs and merge with document - Orig by Apodigm - Docvars
        $rs = $this->db->select(
			"tv.*, IF(tvc.value!='',tvc.value,tv.default_text) as value",
			$this->getFullTableName("site_tmplvars") . " tv 
				INNER JOIN " . $this->getFullTableName("site_tmplvar_templates")." tvtpl ON tvtpl.tmplvarid = tv.id 
				LEFT JOIN " . $this->getFullTableName("site_tmplvar_contentvalues")." tvc ON tvc.tmplvarid=tv.id AND tvc.contentid = '{$documentObject['id']}'",
			"tvtpl.templateid = '{$documentObject['template']}'"
			);
			$tmplvars = array();
            while ($row= $this->db->getRow($rs)) {
                $tmplvars[$row['name']]= array (
                    $row['name'],
                    $row['value'],
                    $row['display'],
                    $row['display_params'],
                    $row['type']
                );
            }
            $documentObject= array_merge($documentObject, $tmplvars);
		}
        return $documentObject;
    }

    /**
     * Parse a source string.
     *
     * Handles most MODX tags. Exceptions include:
     *   - uncached snippet tags [!...!]
     *   - URL tags [~...~]
     *
     * @param string $source
     * @return string
     */
    function parseDocumentSource($source) {
        // set the number of times we are to parse the document source
        $this->minParserPasses= empty ($this->minParserPasses) ? 2 : $this->minParserPasses;
        $this->maxParserPasses= empty ($this->maxParserPasses) ? 10 : $this->maxParserPasses;
        $passes= $this->minParserPasses;
        for ($i= 0; $i < $passes; $i++) {
            // get source length if this is the final pass
            if ($i == ($passes -1))
                $st= strlen($source);
            if ($this->dumpSnippets == 1) {
                $this->snippetsCode .= "<fieldset><legend><b style='color: #821517;'>PARSE PASS " . ($i +1) . "</b></legend><p>The following snippets (if any) were parsed during this pass.</p>";
            }

            // invoke OnParseDocument event
            $this->documentOutput= $source; // store source code so plugins can
            $this->invokeEvent("OnParseDocument"); // work on it via $modx->documentOutput
            $source= $this->documentOutput;
            
            $source = $this->mergeSettingsContent($source);
            
            // combine template and document variables
            $source= $this->mergeDocumentContent($source);
            // replace settings referenced in document
            $source= $this->mergeSettingsContent($source);
            // replace HTMLSnippets in document
            $source= $this->mergeChunkContent($source);
	    // insert META tags & keywords
            if(isset($this->config['show_meta']) && $this->config['show_meta']==1) {
                $source= $this->mergeDocumentMETATags($source);
            }
            // find and merge snippets
            $source= $this->evalSnippets($source);
            // find and replace Placeholders (must be parsed last) - Added by Raymond
            $source= $this->mergePlaceholderContent($source);
            
            $source = $this->mergeSettingsContent($source);
            
            if ($this->dumpSnippets == 1) {
                $this->snippetsCode .= "</fieldset><br />";
            }
            if ($i == ($passes -1) && $i < ($this->maxParserPasses - 1)) {
                // check if source length was changed
                $et= strlen($source);
                if ($st != $et)
                    $passes++; // if content change then increase passes because
            } // we have not yet reached maxParserPasses
        }
        return $source;
    }

    /**
     * Starts the parsing operations.
     * 
     * - connects to the db
     * - gets the settings (including system_settings)
     * - gets the document/resource identifier as in the query string
     * - finally calls prepareResponse()
     */
    function executeParser() {

        //error_reporting(0);
            set_error_handler(array (
                & $this,
                "phpError"
            ), E_ALL);

        $this->db->connect();

        // get the settings
        if (empty ($this->config)) {
            $this->getSettings();
        }

        // IIS friendly url fix
        if ($this->config['friendly_urls'] == 1 && strpos($_SERVER['SERVER_SOFTWARE'], 'Microsoft-IIS') !== false) {
            $url= $_SERVER['QUERY_STRING'];
            $err= substr($url, 0, 3);
            if ($err == '404' || $err == '405') {
                $k= array_keys($_GET);
                unset ($_GET[$k[0]]);
                unset ($_REQUEST[$k[0]]); // remove 404,405 entry
                $qp= parse_url(str_replace($this->config['site_url'], '', substr($url, 4)));
                $_SERVER['QUERY_STRING']= $qp['query'];
                if (!empty ($qp['query'])) {
                    parse_str($qp['query'], $qv);
                    foreach ($qv as $n => $v)
                        $_REQUEST[$n]= $_GET[$n]= $v;
                }
                $_SERVER['PHP_SELF']= $this->config['base_url'] . $qp['path'];
                $_REQUEST['q']= $_GET['q']= $qp['path'];
            }
        }

        // check site settings
        if (!$this->checkSiteStatus()) {
            header('HTTP/1.0 503 Service Unavailable');
            if (!$this->config['site_unavailable_page']) {
                // display offline message
                $this->documentContent= $this->config['site_unavailable_message'];
                $this->outputContent();
                exit; // stop processing here, as the site's offline
            } else {
                // setup offline page document settings
                $this->documentMethod= "id";
                $this->documentIdentifier= $this->config['site_unavailable_page'];
            }
        } else {
            // make sure the cache doesn't need updating
            $this->checkPublishStatus();

            // find out which document we need to display
            $this->documentMethod= $this->getDocumentMethod();
            $this->documentIdentifier= $this->getDocumentIdentifier($this->documentMethod);
        }

        if ($this->documentMethod == "none") {
            $this->documentMethod= "id"; // now we know the site_start, change the none method to id
        }

        if ($this->documentMethod == "alias") {
            $this->documentIdentifier= $this->cleanDocumentIdentifier($this->documentIdentifier);

            // Check use_alias_path and check if $this->virtualDir is set to anything, then parse the path
            if ($this->config['use_alias_path'] == 1) {
                $alias= (strlen($this->virtualDir) > 0 ? $this->virtualDir . '/' : '') . $this->documentIdentifier;
                if (isset($this->documentListing[$alias])) {
                    $this->documentIdentifier= $this->documentListing[$alias];
                } else {
					//@TODO: check new $alias;
                    $this->sendErrorPage();
                }
            } else {
                if (isset($this->documentListing[$this->documentIdentifier])) {
                    $this->documentIdentifier = $this->documentListing[$this->documentIdentifier];
				} else {
					$this->documentIdentifier = (int) $this->documentIdentifier;
				}
            }
            $this->documentMethod= 'id';
        }
		
		//$this->_fixURI();
        // invoke OnWebPageInit event
        $this->invokeEvent("OnWebPageInit");
        // invoke OnLogPageView event
        if ($this->config['track_visitors'] == 1) {
            $this->invokeEvent("OnLogPageHit");
        }
        if($this->config['seostrict']==='1') $this->sendStrictURI();
        $this->prepareResponse();
    }

    /**
     * The next step called at the end of executeParser()
     *
     * - checks cache
     * - checks if document/resource is deleted/unpublished
     * - checks if resource is a weblink and redirects if so
     * - gets template and parses it
     * - ensures that postProcess is called when PHP is finished
     */
    function prepareResponse() {
        // we now know the method and identifier, let's check the cache
        $this->documentContent= $this->checkCache($this->documentIdentifier);
        if ($this->documentContent != "") {
            // invoke OnLoadWebPageCache  event
            $this->invokeEvent("OnLoadWebPageCache");
        } else {

            // get document object
            $this->documentObject= $this->getDocumentObject($this->documentMethod, $this->documentIdentifier, 'prepareResponse');

            // write the documentName to the object
            $this->documentName= $this->documentObject['pagetitle'];

            // validation routines
            if ($this->documentObject['deleted'] == 1) {
                $this->sendErrorPage();
            }
            //  && !$this->checkPreview()
            if ($this->documentObject['published'] == 0) {

                // Can't view unpublished pages
                if (!$this->hasPermission('view_unpublished')) {
                    $this->sendErrorPage();
                } else {
                    // Inculde the necessary files to check document permissions
                    include_once (MODX_MANAGER_PATH . 'processors/user_documents_permissions.class.php');
                    $udperms= new udperms();
                    $udperms->user= $this->getLoginUserID();
                    $udperms->document= $this->documentIdentifier;
                    $udperms->role= $_SESSION['mgrRole'];
                    // Doesn't have access to this document
                    if (!$udperms->checkPermissions()) {
                        $this->sendErrorPage();
                    }

                }

            }

            // check whether it's a reference
            if ($this->documentObject['type'] == "reference") {
                if (is_numeric($this->documentObject['content'])) {
                    // if it's a bare document id
                    $this->documentObject['content']= $this->makeUrl($this->documentObject['content']);
                }
                elseif (strpos($this->documentObject['content'], '[~') !== false) {
                    // if it's an internal docid tag, process it
                    $this->documentObject['content']= $this->rewriteUrls($this->documentObject['content']);
                }
                $this->sendRedirect($this->documentObject['content'], 0, '', 'HTTP/1.0 301 Moved Permanently');
            }

            // check if we should not hit this document
            if ($this->documentObject['donthit'] == 1) {
                $this->config['track_visitors']= 0;
            }

            // get the template and start parsing!
            if (!$this->documentObject['template'])
                $this->documentContent= "[*content*]"; // use blank template
            else {
                $result= $this->db->select('content', $this->getFullTableName("site_templates"), "id = '{$this->documentObject['template']}'");
                $rowCount= $this->db->getRecordCount($result);
                if ($rowCount==1) {
                     $this->documentContent = $this->db->getValue($result);
                } else {
                    $this->messageQuit("Incorrect number of templates returned from database");
                }
            }

            // invoke OnLoadWebDocument event
            $this->invokeEvent("OnLoadWebDocument");

            // Parse document source
            $this->documentContent= $this->parseDocumentSource($this->documentContent);

            // setup <base> tag for friendly urls
            //			if($this->config['friendly_urls']==1 && $this->config['use_alias_path']==1) {
            //				$this->regClientStartupHTMLBlock('<base href="'.$this->config['site_url'].'" />');
            //			}
        }

		if($this->documentIdentifier==$this->config['error_page'] &&  $this->config['error_page']!=$this->config['site_start']){
			header('HTTP/1.0 404 Not Found');
		}
        register_shutdown_function(array (
            & $this,
            "postProcess"
        )); // tell PHP to call postProcess when it shuts down
        $this->outputContent();
        //$this->postProcess();
    }

    /**
     * Returns an array of all parent record IDs for the id passed.
     *
     * @param int $id Docid to get parents for.
     * @param int $height The maximum number of levels to go up, default 10.
     * @return array
     */
    function getParentIds($id, $height= 10) {
        $parents= array ();
        while ( $id && $height-- ) {
            $thisid = $id;
            $id = $this->aliasListing[$id]['parent'];
            if (!$id) break;
            $parents[$thisid] = $id;
        }
        return $parents;
    }

    /**
     * Returns an array of child IDs belonging to the specified parent.
     *
     * @param int $id The parent resource/document to start from
     * @param int $depth How many levels deep to search for children, default: 10
     * @param array $children Optional array of docids to merge with the result.
     * @return array Contains the document Listing (tree) like the sitemap
     */
    function getChildIds($id, $depth= 10, $children= array ()) {

        // Initialise a static array to index parents->children
        static $documentMap_cache = array();
        if (!count($documentMap_cache)) {
            foreach ($this->documentMap as $document) {
                foreach ($document as $p => $c) {
                    $documentMap_cache[$p][] = $c;
                }
            }
        }

        // Get all the children for this parent node
        if (isset($documentMap_cache[$id])) {
            $depth--;

            foreach ($documentMap_cache[$id] as $childId) {
                $pkey = (strlen($this->aliasListing[$childId]['path']) ? "{$this->aliasListing[$childId]['path']}/" : '') . $this->aliasListing[$childId]['alias'];
                if (!strlen($pkey)) $pkey = "{$childId}";
                    $children[$pkey] = $childId;

                if ($depth && isset($documentMap_cache[$childId])) {
                    $children += $this->getChildIds($childId, $depth);
                }
            }
        }
        return $children;
    }

    /**
     * Displays a javascript alert message in the web browser and quit
     *
     * @param string $msg Message to show
     * @param string $url URL to redirect to
     */
    function webAlertAndQuit($msg, $url= "") {
        global $modx_manager_charset;
        if (substr(strtolower($url), 0, 11) == "javascript:") {
            $fnc = substr($url, 11);
        } elseif ($url) {
            $fnc = "window.location.href='" . addslashes($url) . "';";
        } else {
            $fnc = "history.back(-1);";
        }
        echo "<html><head>
            <title>MODX :: Alert</title>
            <meta http-equiv=\"Content-Type\" content=\"text/html; charset={$modx_manager_charset};\">
            <script>
                function __alertQuit() {
                    alert('" . addslashes($msg) . "');
                    {$fnc}
                }
                window.setTimeout('__alertQuit();',100);
            </script>
            </head><body>
            <p>{$msg}</p>
            </body></html>";
            exit;
    }

    /**
     * Returns true if user has the currect permission
     *
     * @param string $pm Permission name
     * @return int
     */
    function hasPermission($pm) {
        $state= false;
        $pms= $_SESSION['mgrPermissions'];
        if ($pms)
            $state= ($pms[$pm] == 1);
        return $state;
    }

    /**
     * Add an a alert message to the system event log
     *
     * @param int $evtid Event ID
     * @param int $type Types: 1 = information, 2 = warning, 3 = error
     * @param string $msg Message to be logged
     * @param string $source source of the event (module, snippet name, etc.)
     *                       Default: Parser
     */
    function logEvent($evtid, $type, $msg, $source= 'Parser') {
        $msg= $this->db->escape($msg);
	if ($GLOBALS['database_connection_charset'] == 'utf8' && extension_loaded('mbstring')) {
		$esc_source = mb_substr($source, 0, 50 , "UTF-8");
	} else {
		$esc_source = substr($source, 0, 50);
	}
        $esc_source= $this->db->escape($esc_source);
	$LoginUserID = $this->getLoginUserID();
	if ($LoginUserID == '') $LoginUserID = 0;
        $evtid= intval($evtid);
		$type = intval($type);
		if ($type < 1) $type= 1; // Types: 1 = information, 2 = warning, 3 = error
		if (3 < $type) $type= 3;
	$this->db->insert(
		array(
			'eventid'     => $evtid,
			'type'        => $type,
			'createdon'   => time() + $this->config['server_offset_time'],
			'source'      => $esc_source,
			'description' => $msg,
			'user'        => $LoginUserID,
		), $this->getFullTableName('event_log'));
		if (isset($this->config['send_errormail']) && $this->config['send_errormail'] !== '0') {
			if ($this->config['send_errormail'] <= $type) {
				$this->sendmail(array(
						'subject' => 'MODX System Error on ' . $this->config['site_name'],
						'body' => 'Source: ' . $source . ' - The details of the error could be seen in the MODX system events log.',
						'type' => 'text')
				);
			}
		}
    }

    function sendmail($params=array(), $msg='')
    {
        if(isset($params) && is_string($params))
        {
            if(strpos($params,'=')===false)
            {
                if(strpos($params,'@')!==false) $p['to']      = $params;
                else                            $p['subject'] = $params;
            }
            else
            {
                $params_array = explode(',',$params);
                foreach($params_array as $k=>$v)
                {
                    $k = trim($k);
                    $v = trim($v);
                    $p[$k] = $v;
                }
            }
        }
        else
        {
            $p = $params;
            unset($params);
        }
        if(isset($p['sendto'])) $p['to'] = $p['sendto'];
        
        if(isset($p['to']) && preg_match('@^[0-9]+$@',$p['to']))
        {
            $userinfo = $this->getUserInfo($p['to']);
            $p['to'] = $userinfo['email'];
        }
        if(isset($p['from']) && preg_match('@^[0-9]+$@',$p['from']))
        {
            $userinfo = $this->getUserInfo($p['from']);
            $p['from']     = $userinfo['email'];
            $p['fromname'] = $userinfo['username'];
        }
        if($msg==='' && !isset($p['body']))
        {
            $p['body'] = $_SERVER['REQUEST_URI'] . "\n" . $_SERVER['HTTP_USER_AGENT'] . "\n" . $_SERVER['HTTP_REFERER'];
        }
        elseif(is_string($msg) && 0<strlen($msg)) $p['body'] = $msg;
        
        $this->loadExtension('MODxMailer');
        $sendto = (!isset($p['to']))   ? $this->config['emailsender']  : $p['to'];
        $sendto = explode(',',$sendto);
        foreach($sendto as $address)
        {
            list($name, $address) = $this->mail->address_split($address);
            $this->mail->AddAddress($address,$name);
        }
        if(isset($p['cc']))
        {
            $p['cc'] = explode(',',$p['cc']);
            foreach($p['cc'] as $address)
            {
                list($name, $address) = $this->mail->address_split($address);
                $this->mail->AddCC($address,$name);
            }
        }
        if(isset($p['bcc']))
        {
            $p['bcc'] = explode(',',$p['bcc']);
            foreach($p['bcc'] as $address)
            {
                list($name, $address) = $this->mail->address_split($address);
                $this->mail->AddBCC($address,$name);
            }
        }
        if(isset($p['from']) && strpos($p['from'],'<')!==false && substr($p['from'],-1)==='>')
            list($p['fromname'],$p['from']) = $this->mail->address_split($p['from']);
        $this->mail->From     = (!isset($p['from']))  ? $this->config['emailsender']  : $p['from'];
        $this->mail->FromName = (!isset($p['fromname'])) ? $this->config['site_name'] : $p['fromname'];
        $this->mail->Subject  = (!isset($p['subject']))  ? $this->config['emailsubject'] : $p['subject'];
        $this->mail->Body     = $p['body'];
        if (isset($p['type']) && $p['type'] == 'text') $this->mail->IsHTML(false);
        $rs = $this->mail->send();
        return $rs;
    }
    
    function rotate_log($target='event_log',$limit=3000, $trim=100)
    {
        if($limit < $trim) $trim = $limit;

        $table_name = $this->getFullTableName($target);
        $count = $this->db->getValue($this->db->select('COUNT(id)',$table_name));
        $over = $count - $limit;
        if(0 < $over)
        {
            $trim = ($over + $trim);
            $this->db->delete($table_name,'','',$trim);
        }
        $this->db->optimize($table_name);
    }
    
    /**
     * Returns true if we are currently in the manager backend
     *
     * @return boolean
     */
    function isBackend() {
		if(defined('IN_MANAGER_MODE') && IN_MANAGER_MODE == 'true')
		{
			return true;
		}
		else return false;
    }

    /**
     * Returns true if we are currently in the frontend
     *
     * @return boolean
     */
    function isFrontend() {
		if(defined('IN_MANAGER_MODE') && IN_MANAGER_MODE == 'true')
		{
			return false;
		}
		else return true;
    }

    /**
     * Gets all child documents of the specified document, including those which are unpublished or deleted.
     *
     * @param int $id The Document identifier to start with
     * @param string $sort Sort field
     *                     Default: menuindex
     * @param string $dir Sort direction, ASC and DESC is possible
     *                    Default: ASC
     * @param string $fields Default: id, pagetitle, description, parent, alias, menutitle
     * @return array
     */
    function getAllChildren($id= 0, $sort= 'menuindex', $dir= 'ASC', $fields= 'id, pagetitle, description, parent, alias, menutitle') {
        $tblsc= $this->getFullTableName("site_content");
        $tbldg= $this->getFullTableName("document_groups");
        // modify field names to use sc. table reference
        $fields= 'sc.' . implode(',sc.', array_filter(array_map('trim', explode(',', $fields))));
        $sort= 'sc.' . implode(',sc.', array_filter(array_map('trim', explode(',', $sort))));
        // get document groups for current user
        if ($docgrp= $this->getUserDocGroups())
            $docgrp= implode(",", $docgrp);
        // build query
        $access= ($this->isFrontend() ? "sc.privateweb=0" : "1='" . $_SESSION['mgrRole'] . "' OR sc.privatemgr=0") .
         (!$docgrp ? "" : " OR dg.document_group IN ($docgrp)");
        $result= $this->db->select(
			"DISTINCT {$fields}",
			"{$tblsc} sc
				LEFT JOIN {$tbldg} dg on dg.document = sc.id",
			"sc.parent = '{$id}' AND ({$access}) GROUP BY sc.id",
			"{$sort} {$dir}"
			);
        $resourceArray = $this->db->makeArray($result);
        return $resourceArray;
    }

    /**
     * Gets all active child documents of the specified document, i.e. those which published and not deleted.
     *
     * @param int $id The Document identifier to start with
     * @param string $sort Sort field
     *                     Default: menuindex
     * @param string $dir Sort direction, ASC and DESC is possible
     *                    Default: ASC
     * @param string $fields Default: id, pagetitle, description, parent, alias, menutitle
     * @return array
     */
    function getActiveChildren($id= 0, $sort= 'menuindex', $dir= 'ASC', $fields= 'id, pagetitle, description, parent, alias, menutitle') {
        $tblsc= $this->getFullTableName("site_content");
        $tbldg= $this->getFullTableName("document_groups");

        // modify field names to use sc. table reference
        $fields= 'sc.' . implode(',sc.', array_filter(array_map('trim', explode(',', $fields))));
        $sort= 'sc.' . implode(',sc.', array_filter(array_map('trim', explode(',', $sort))));
        // get document groups for current user
        if ($docgrp= $this->getUserDocGroups())
            $docgrp= implode(",", $docgrp);
        // build query
        $access= ($this->isFrontend() ? "sc.privateweb=0" : "1='" . $_SESSION['mgrRole'] . "' OR sc.privatemgr=0") .
         (!$docgrp ? "" : " OR dg.document_group IN ($docgrp)");
        $result= $this->db->select(
			"DISTINCT {$fields}",
			"{$tblsc} sc
				LEFT JOIN {$tbldg} dg on dg.document = sc.id",
			"sc.parent = '{$id}' AND sc.published=1 AND sc.deleted=0 AND ({$access}) GROUP BY sc.id",
			"{$sort} {$dir}"
			);
        $resourceArray = $this->db->makeArray($result);
        return $resourceArray;
    }
	
	/**
	 * getDocumentChildren
	 * @version 1.1.1 (2014-02-19)
	 * 
	 * @desc Returns the children of the selected document/folder as an associative array.
	 * 
	 * @param $parentid {integer} - The parent document identifier. Default: 0 (site root).
	 * @param $published {0; 1; 'all'} - Document publication status. Once the parameter equals 'all', the result will be returned regardless of whether the ducuments are published or they are not. Default: 1.
	 * @param $deleted {0; 1; 'all'} - Document removal status. Once the parameter equals 'all', the result will be returned regardless of whether the ducuments are deleted or they are not. Default: 0.
	 * @param $fields {comma separated string; '*'} - Comma separated list of document fields to get. Default: '*' (all fields).
	 * @param $where {string} - Where condition in SQL style. Should include a leading 'AND '. Default: ''.
	 * @param $sort {comma separated string} - Should be a comma-separated list of field names on which to sort. Default: 'menuindex'.
	 * @param $dir {'ASC'; 'DESC'} - Sort direction, ASC and DESC is possible. Default: 'ASC'.
	 * @param $limit {string} - Should be a valid SQL LIMIT clause without the 'LIMIT ' i.e. just include the numbers as a string. Default: Empty string (no limit).
	 * 
	 * @return {array; false} - Result array, or false.
	 */
    function getDocumentChildren($parentid = 0, $published = 1, $deleted = 0, $fields = '*', $where = '', $sort = 'menuindex', $dir = 'ASC', $limit = ''){
		$published = ($published !== 'all') ? 'AND sc.published = '.$published : '';
		$deleted = ($deleted !== 'all') ? 'AND sc.deleted = '.$deleted : '';
		
		if ($where != ''){
			$where = 'AND '.$where;
		}
		
		// modify field names to use sc. table reference
		$fields = 'sc.' . implode(',sc.', array_filter(array_map('trim', explode(',', $fields))));
		$sort = ($sort == '') ? '' : 'sc.' . implode(',sc.', array_filter(array_map('trim', explode(',', $sort))));
		
		// get document groups for current user
		if ($docgrp = $this->getUserDocGroups()){
			$docgrp = implode(',', $docgrp);
		}
		
		// build query
		$access = ($this->isFrontend() ? 'sc.privateweb=0' : '1="'.$_SESSION['mgrRole'].'" OR sc.privatemgr=0').(!$docgrp ? '' : ' OR dg.document_group IN ('.$docgrp.')');
		
		$tblsc = $this->getFullTableName('site_content');
		$tbldg = $this->getFullTableName('document_groups');
		
		$result= $this->db->select(
			"DISTINCT {$fields}",
			"{$tblsc} sc
				LEFT JOIN {$tbldg} dg on dg.document = sc.id",
			"sc.parent = '{$parentid}' {$published} {$deleted} {$where} AND ({$access}) GROUP BY sc.id",
			($sort ? "{$sort} {$dir}" : ""),
			$limit
			);
		
		$resourceArray = $this->db->makeArray($result);
		
		return $resourceArray;
	}
	
	/**
	 * getDocuments
	 * @version 1.1.1 (2013-02-19)
	 * 
	 * @desc Returns required documents (their fields).
	 * 
	 * @param $ids {array; comma separated string} - Documents Ids to get. @required
	 * @param $published {0; 1; 'all'} - Documents publication status. Once the parameter equals 'all', the result will be returned regardless of whether the documents are published or they are not. Default: 1.
	 * @param $deleted {0; 1; 'all'} - Documents removal status. Once the parameter equals 'all', the result will be returned regardless of whether the documents are deleted or they are not. Default: 0.
	 * @param $fields {comma separated string; '*'} - Documents fields to get. Default: '*'.
	 * @param $where {string} - SQL WHERE clause. Default: ''.
	 * @param $sort {comma separated string} - A comma-separated list of field names to sort by. Default: 'menuindex'.
	 * @param $dir {'ASC'; 'DESC'} - Sorting direction. Default: 'ASC'.
	 * @param $limit {string} - SQL LIMIT (without 'LIMIT '). An empty string means no limit. Default: ''.
	 * 
	 * @return {array; false} - Result array with documents, or false.
	 */
	function getDocuments($ids = array(), $published = 1, $deleted = 0, $fields = '*', $where = '', $sort = 'menuindex', $dir = 'ASC', $limit = ''){
		if(is_string($ids)){
			if(strpos($ids, ',') !== false){
				$ids = array_filter(array_map('intval', explode(',', $ids)));
			}else{
				$ids = array($ids);
			}
		}
		if (count($ids) == 0){
			return false;
		}else{
			// modify field names to use sc. table reference
			$fields = 'sc.'.implode(',sc.', array_filter(array_map('trim', explode(',', $fields))));
			$sort = ($sort == '') ? '' : 'sc.'.implode(',sc.', array_filter(array_map('trim', explode(',', $sort))));
			if ($where != ''){
				$where = 'AND '.$where;
			}
			
			$published = ($published !== 'all') ? "AND sc.published = '{$published}'" : '';
			$deleted = ($deleted !== 'all') ? "AND sc.deleted = '{$deleted}'" : '';
			
			// get document groups for current user
			if ($docgrp = $this->getUserDocGroups()){
				$docgrp = implode(',', $docgrp);
			}
			
			$access = ($this->isFrontend() ? 'sc.privateweb=0' : '1="'.$_SESSION['mgrRole'].'" OR sc.privatemgr=0').(!$docgrp ? '' : ' OR dg.document_group IN ('.$docgrp.')');
			
			$tblsc = $this->getFullTableName('site_content');
			$tbldg = $this->getFullTableName('document_groups');

			$result = $this->db->select(
				"DISTINCT {$fields}",
				"{$tblsc} sc
					LEFT JOIN {$tbldg} dg on dg.document = sc.id",
				"(sc.id IN (".implode(',', $ids).") {$published} {$deleted} {$where}) AND ({$access}) GROUP BY sc.id",
				($sort ? "{$sort} {$dir}" : ""),
				$limit
				);
			
			$resourceArray = $this->db->makeArray($result);
			
			return $resourceArray;
		}
	}
	
	/**
	 * getDocument
	 * @version 1.0.1 (2014-02-19)
	 * 
	 * @desc Returns required fields of a document.
	 * 
	 * @param $id {integer} - Id of a document which data has to be gained. @required
	 * @param $fields {comma separated string; '*'} - Comma separated list of document fields to get. Default: '*'.
	 * @param $published {0; 1; 'all'} - Document publication status. Once the parameter equals 'all', the result will be returned regardless of whether the ducuments are published or they are not. Default: false.
	 * @param $deleted {0; 1; 'all'} - Document removal status. Once the parameter equals 'all', the result will be returned regardless of whether the ducuments are deleted or they are not. Default: 0.
	 * 
	 * @return {array; false} - Result array with fields or false.
	 */
	function getDocument($id = 0, $fields = '*', $published = 1, $deleted = 0){
		if ($id == 0){
			return false;
		}else{
			$docs = $this->getDocuments(array($id), $published, $deleted, $fields, '', '', '', 1);
			
			if ($docs != false){
				return $docs[0];
			}else{
				return false;
			}
		}
	}
	
    /**
     * Returns the page information as database row, the type of result is
     * defined with the parameter $rowMode
     *
     * @param int $pageid The parent document identifier
     *                    Default: -1 (no result)
     * @param int $active Should we fetch only published and undeleted documents/resources?
     *                     1 = yes, 0 = no
     *                     Default: 1
     * @param string $fields List of fields
     *                       Default: id, pagetitle, description, alias
     * @return boolean|array
     */
    function getPageInfo($pageid= -1, $active= 1, $fields= 'id, pagetitle, description, alias') {
        if ($pageid == 0) {
            return false;
        } else {
            $tblsc= $this->getFullTableName("site_content");
            $tbldg= $this->getFullTableName("document_groups");
            $activeSql= $active == 1 ? "AND sc.published=1 AND sc.deleted=0" : "";
            // modify field names to use sc. table reference
            $fields= 'sc.' . implode(',sc.', array_filter(array_map('trim', explode(',', $fields))));
            // get document groups for current user
            if ($docgrp= $this->getUserDocGroups())
                $docgrp= implode(",", $docgrp);
            $access= ($this->isFrontend() ? "sc.privateweb=0" : "1='" . $_SESSION['mgrRole'] . "' OR sc.privatemgr=0") .
             (!$docgrp ? "" : " OR dg.document_group IN ($docgrp)");
            $result= $this->db->select(
                    $fields,
                    "{$tblsc} sc LEFT JOIN {$tbldg} dg on dg.document = sc.id",
                    "(sc.id='{$pageid}' {$activeSql}) AND ({$access})",
                    "",
                    1
                    );
            $pageInfo= $this->db->getRow($result);
            return $pageInfo;
        }
    }

    /**
     * Returns the parent document/resource of the given docid
     *
     * @param int $pid The parent docid. If -1, then fetch the current document/resource's parent
     *                 Default: -1
     * @param int $active Should we fetch only published and undeleted documents/resources?
     *                     1 = yes, 0 = no
     *                     Default: 1
     * @param string $fields List of fields
     *                       Default: id, pagetitle, description, alias
     * @return boolean|array
     */
    function getParent($pid= -1, $active= 1, $fields= 'id, pagetitle, description, alias, parent') {
        if ($pid == -1) {
            $pid= $this->documentObject['parent'];
            return ($pid == 0) ? false : $this->getPageInfo($pid, $active, $fields);
        } else
            if ($pid == 0) {
                return false;
            } else {
                // first get the child document
                $child= $this->getPageInfo($pid, $active, "parent");
                // now return the child's parent
                $pid= ($child['parent']) ? $child['parent'] : 0;
                return ($pid == 0) ? false : $this->getPageInfo($pid, $active, $fields);
            }
    }

    /**
     * Returns the id of the current snippet.
     *
     * @return int
     */
    function getSnippetId() {
        if ($this->currentSnippet) {
            $tbl= $this->getFullTableName("site_snippets");
            $rs = $this->db->select('id', $tbl, "name='" . $this->db->escape($this->currentSnippet) . "'", '', 1);
            if ($snippetId = $this->db->getValue($rs))
                return $snippetId;
        }
        return 0;
    }

    /**
     * Returns the name of the current snippet.
     *
     * @return string
     */
    function getSnippetName() {
        return $this->currentSnippet;
    }

    /**
     * Clear the cache of MODX.
     *
     * @return boolean 
     */
    function clearCache($type='', $report=false) {
		if ($type=='full') {
		include_once(MODX_MANAGER_PATH . 'processors/cache_sync.class.processor.php');
		$sync = new synccache();
		$sync->setCachepath(MODX_BASE_PATH . 'assets/cache/');
		$sync->setReport($report);
		$sync->emptyCache();
		} else {
			$files = glob(MODX_BASE_PATH . 'assets/cache/*');
			$deletedfiles = array();
			while ($file = array_shift($files)) {
				$name = basename($file);
				if (preg_match('/\.pageCache/',$name) && !in_array($name, $deletedfiles)) {
					$deletedfiles[] = $name;
					unlink($file);
					clearstatcache();
				}
			}
		}
    }

    /**
     * Create an URL for the given document identifier. The url prefix and
     * postfix are used, when friendly_url is active.
     *
     * @param int $id The document identifier
     * @param string $alias The alias name for the document
     *                      Default: Empty string
     * @param string $args The paramaters to add to the URL
     *                     Default: Empty string
     * @param string $scheme With full as valus, the site url configuration is
     *                       used
     *                       Default: Empty string
     * @return string
     */
    function makeUrl($id, $alias= '', $args= '', $scheme= '') {
        $url= '';
        $virtualDir= $this->config['virtual_dir'];
        $f_url_prefix = $this->config['friendly_url_prefix'];
        $f_url_suffix = $this->config['friendly_url_suffix'];
        if (!is_numeric($id)) {
            $this->messageQuit("`{$id}` is not numeric and may not be passed to makeUrl()");
        }
        if ($args !== '') {
            // add ? or & to $args if missing
            $args= ltrim($args, '?&');
            $_ = strpos($f_url_prefix, '?');
            if($this->config['friendly_urls'] === '1' && $_ === false) {
                $args= "?{$args}";
            }
            else $args= "&{$args}";
        }
        if ($id != $this->config['site_start']) {
            if ($this->config['friendly_urls'] == 1 && $alias != '') {
            } elseif ($this->config['friendly_urls'] == 1 && $alias == '') {
                $alias = $id;
                $alPath = '';
                if ($this->config['friendly_alias_urls'] == 1) {
                    $al = $this->aliasListing[$id];
                    if ($al['isfolder'] === 1 && $this->config['make_folders'] === '1')
                        $f_url_suffix = '/';
                    $alPath = !empty ($al['path']) ? $al['path'] . '/' : '';
                    if ($al && $al['alias'])
                        $alias = $al['alias'];
                }
                $alias = $alPath . $f_url_prefix . $alias . $f_url_suffix;
                $url = "{$alias}{$args}";
            } else {
                $url = "index.php?id={$id}{$args}";
            }
        } else {
            $url = $args;
        }
        $host= $this->config['base_url'];
        // check if scheme argument has been set
        if ($scheme != '') {
            // for backward compatibility - check if the desired scheme is different than the current scheme
            if (is_numeric($scheme) && $scheme != $_SERVER['HTTPS']) {
                $scheme= ($_SERVER['HTTPS'] ? 'http' : 'https');
            }

            // to-do: check to make sure that $site_url incudes the url :port (e.g. :8080)
            $host= $scheme == 'full' ? $this->config['site_url'] : $scheme . '://' . $_SERVER['HTTP_HOST'] . $host;
        }

        //fix strictUrl by Bumkaka
        if ($this->config['seostrict']=='1'){
           $url = $this->toAlias($url);
        }
        if ($this->config['xhtml_urls']) {
        	return preg_replace("/&(?!amp;)/","&amp;", $host . $virtualDir . $url);
        } else {
        	return $host . $virtualDir . $url;
        }
    }

    /**
     * Returns an entry from the config
     *
     * Note: most code accesses the config array directly and we will continue to support this.
     *
     * @return boolean|string
     */
    function getConfig($name= '') {
        if (!empty ($this->config[$name])) {
            return $this->config[$name];
        } else {
            return false;
        }
    }

    /**
     * Returns the MODX version information as version, branch, release date and full application name.
     *
     * @return array
     */
   
    function getVersionData($data=null) {
        $out=array();
        if(empty($this->version) || !is_array($this->version)){
            //include for compatibility modx version < 1.0.10
            include MODX_MANAGER_PATH . "includes/version.inc.php";
            $this->version=array();
            $this->version['version']= isset($modx_version) ? $modx_version : '';
            $this->version['branch']= isset($modx_branch) ? $modx_branch : '';
            $this->version['release_date']= isset($modx_release_date) ? $modx_release_date : '';
            $this->version['full_appname']= isset($modx_full_appname) ? $modx_full_appname : '';
            $this->version['new_version'] = isset($this->config['newversiontext']) ? $this->config['newversiontext'] : '';
        }
        return (!is_null($data) && is_array($this->version) && isset($this->version[$data])) ? $this->version[$data] : $this->version;
    }
    
    /**
     * Executes a snippet.
     *
     * @param string $snippetName
     * @param array $params Default: Empty array
     * @return string
     */
    function runSnippet($snippetName, $params= array ()) {
        if (isset ($this->snippetCache[$snippetName])) {
            $snippet= $this->snippetCache[$snippetName];
            $properties= $this->snippetCache[$snippetName . "Props"];
        } else { // not in cache so let's check the db
            $result= $this->db->select('name, snippet, properties', $this->getFullTableName("site_snippets"), "name='" . $this->db->escape($snippetName) . "'");
            if ($row= $this->db->getRow($result)) {
                $snippet= $this->snippetCache[$row['name']]= $row['snippet'];
                $properties= $this->snippetCache[$row['name'] . "Props"]= $row['properties'];
            } else {
                $snippet= $this->snippetCache[$snippetName]= "return false;";
                $properties= '';
            }
        }
        // load default params/properties
        $parameters= $this->parseProperties($properties);
        $parameters= array_merge($parameters, $params);
        // run snippet
        return $this->evalSnippet($snippet, $parameters);
    }

    /**
     * Returns the chunk content for the given chunk name
     * 
     * @param string $chunkName
     * @return boolean|string
     */
    function getChunk($chunkName) {
        return isset($this->chunkCache[$chunkName]) ? $this->chunkCache[$chunkName] : null;
    }
	
    /**
     * parseText
     * @version 1.0 (2013-10-17)
     * 
     * @desc Replaces placeholders in text with required values.
     * 
     * @param $chunk {string} - String to parse. @required
     * @param $chunkArr {array} - Array of values. Key ??? placeholder name, value ??? value. @required
     * @param $prefix {string} - Placeholders prefix. Default: '[+'.
     * @param $suffix {string} - Placeholders suffix. Default: '+]'.
     * 
     * @return {string} - Parsed text.
     */
	function parseText($chunk, $chunkArr, $prefix = '[+', $suffix = '+]'){
		if (!is_array($chunkArr)){
			return $chunk;
		}
		
		foreach ($chunkArr as $key => $value){
			$chunk = str_replace($prefix.$key.$suffix, $value, $chunk);
		}
		
		return $chunk;
	}
	
	/**
	 * parseChunk
	 * @version 1.1 (2013-10-17)
	 * 
	 * @desc Replaces placeholders in a chunk with required values.
	 * 
	 * @param $chunkName {string} - Name of chunk to parse. @required
	 * @param $chunkArr {array} - Array of values. Key ??? placeholder name, value ??? value. @required
	 * @param $prefix {string} - Placeholders prefix. Default: '{'.
	 * @param $suffix {string} - Placeholders suffix. Default: '}'.
	 * 
	 * @return {string; false} - Parsed chunk or false if $chunkArr is not array.
	 */
	function parseChunk($chunkName, $chunkArr, $prefix = '{', $suffix = '}'){
		//TODO: Wouldn't it be more practical to return the contents of a chunk instead of false?
		if (!is_array($chunkArr)){
			return false;
		}
		
		return $this->parseText($this->getChunk($chunkName), $chunkArr, $prefix, $suffix);
	}
    
    /**
     * Returns the timestamp in the date format defined in $this->config['datetime_format']
     *
     * @param int $timestamp Default: 0
     * @param string $mode Default: Empty string (adds the time as below). Can also be 'dateOnly' for no time or 'formatOnly' to get the datetime_format string.
     * @return string
     */
    function toDateFormat($timestamp = 0, $mode = '') {
        $timestamp = trim($timestamp);
        if($mode !== 'formatOnly' && empty($timestamp)) return '-';
        $timestamp = intval($timestamp);
        
        switch($this->config['datetime_format']) {
            case 'YYYY/mm/dd':
                $dateFormat = '%Y/%m/%d';
                break;
            case 'dd-mm-YYYY':
                $dateFormat = '%d-%m-%Y';
                break;
            case 'mm/dd/YYYY':
                $dateFormat = '%m/%d/%Y';
                break;
            /*
            case 'dd-mmm-YYYY':
                $dateFormat = '%e-%b-%Y';
                break;
            */
        }
        
        if (empty($mode)) {
            $strTime = strftime($dateFormat . " %H:%M:%S", $timestamp);
        } elseif ($mode == 'dateOnly') {
            $strTime = strftime($dateFormat, $timestamp);
        } elseif ($mode == 'formatOnly') {
        	$strTime = $dateFormat;
        }
        return $strTime;
    }

    /**
     * Make a timestamp from a string corresponding to the format in $this->config['datetime_format']
     *
     * @param string $str
     * @return string
     */
    function toTimeStamp($str) {
        $str = trim($str);
        if (empty($str)) {return '';}

        switch($this->config['datetime_format']) {
            case 'YYYY/mm/dd':
            	if (!preg_match('/^[0-9]{4}\/[0-9]{2}\/[0-9]{2}[0-9 :]*$/', $str)) {return '';}
                list ($Y, $m, $d, $H, $M, $S) = sscanf($str, '%4d/%2d/%2d %2d:%2d:%2d');
                break;
            case 'dd-mm-YYYY':
            	if (!preg_match('/^[0-9]{2}-[0-9]{2}-[0-9]{4}[0-9 :]*$/', $str)) {return '';}
                list ($d, $m, $Y, $H, $M, $S) = sscanf($str, '%2d-%2d-%4d %2d:%2d:%2d');
                break;
            case 'mm/dd/YYYY':
            	if (!preg_match('/^[0-9]{2}\/[0-9]{2}\/[0-9]{4}[0-9 :]*$/', $str)) {return '';}
                list ($m, $d, $Y, $H, $M, $S) = sscanf($str, '%2d/%2d/%4d %2d:%2d:%2d');
                break;
            /*
            case 'dd-mmm-YYYY':
            	if (!preg_match('/^[0-9]{2}-[0-9a-z]+-[0-9]{4}[0-9 :]*$/i', $str)) {return '';}
            	list ($m, $d, $Y, $H, $M, $S) = sscanf($str, '%2d-%3s-%4d %2d:%2d:%2d');
                break;
            */
        }
        if (!$H && !$M && !$S) {$H = 0; $M = 0; $S = 0;}
        $timeStamp = mktime($H, $M, $S, $m, $d, $Y);
        $timeStamp = intval($timeStamp);
        return $timeStamp;
    }

    /**
     * Get the TVs of a document's children. Returns an array where each element represents one child doc.
     *
     * Ignores deleted children. Gets all children - there is no where clause available.
     *
     * @param int $parentid The parent docid
     *                 Default: 0 (site root)
     * @param array $tvidnames. Which TVs to fetch - Can relate to the TV ids in the db (array elements should be numeric only)
     *                                               or the TV names (array elements should be names only)
     *                      Default: Empty array
     * @param int $published Whether published or unpublished documents are in the result
     *                      Default: 1
     * @param string $docsort How to sort the result array (field)
     *                      Default: menuindex
     * @param ASC $docsortdir How to sort the result array (direction)
     *                      Default: ASC
     * @param string $tvfields Fields to fetch from site_tmplvars, default '*'
     *                      Default: *
     * @param string $tvsort How to sort each element of the result array i.e. how to sort the TVs (field)
     *                      Default: rank
     * @param string  $tvsortdir How to sort each element of the result array i.e. how to sort the TVs (direction)
     *                      Default: ASC
     * @return boolean|array
     */
    function getDocumentChildrenTVars($parentid= 0, $tvidnames= array (), $published= 1, $docsort= "menuindex", $docsortdir= "ASC", $tvfields= "*", $tvsort= "rank", $tvsortdir= "ASC") {
        $docs= $this->getDocumentChildren($parentid, $published, 0, '*', '', $docsort, $docsortdir);
        if (!$docs)
            return false;
        else {
            $result= array ();
            // get user defined template variables
            $fields= ($tvfields == "") ? "tv.*" : 'tv.' . implode(',tv.', array_filter(array_map('trim', explode(',', $tvfields))));
            $tvsort= ($tvsort == "") ? "" : 'tv.' . implode(',tv.', array_filter(array_map('trim', explode(',', $tvsort))));
            if ($tvidnames == "*")
                $query= "tv.id<>0";
            else
                $query= (is_numeric($tvidnames[0]) ? "tv.id" : "tv.name") . " IN ('" . implode("','", $tvidnames) . "')";
            if ($docgrp= $this->getUserDocGroups())
                $docgrp= implode(",", $docgrp);

            $docCount= count($docs);
            for ($i= 0; $i < $docCount; $i++) {

                $docRow= $docs[$i];
                $docid= $docRow['id'];

                $rs = $this->db->select(
                    "{$fields}, IF(tvc.value!='',tvc.value,tv.default_text) as value ",
                    $this->getFullTableName('site_tmplvars') . " tv 
                        INNER JOIN " . $this->getFullTableName('site_tmplvar_templates')." tvtpl ON tvtpl.tmplvarid = tv.id
                        LEFT JOIN " . $this->getFullTableName('site_tmplvar_contentvalues')." tvc ON tvc.tmplvarid=tv.id AND tvc.contentid = '{$docid}'",
                    "{$query} AND tvtpl.templateid = '{$docRow['template']}'",
                    ($tvsort ? "{$tvsort} {$tvsortdir}" : "")
                    );
                $tvs = $this->db->makeArray($rs);

                // get default/built-in template variables
                ksort($docRow);
                foreach ($docRow as $key => $value) {
                    if ($tvidnames == "*" || in_array($key, $tvidnames))
                        array_push($tvs, array (
                            "name" => $key,
                            "value" => $value
                        ));
                }

                if (count($tvs))
                    array_push($result, $tvs);
            }
            return $result;
        }
    }
	
	/**
	 * getDocumentChildrenTVarOutput
	 * @version 1.1 (2014-02-19)
	 * 
	 * @desc Returns an array where each element represents one child doc and contains the result from getTemplateVarOutput().
	 * 
	 * @param $parentid {integer} - Id of parent document. Default: 0 (site root).
	 * @param $tvidnames {array; '*'} - Which TVs to fetch. In the form expected by getTemplateVarOutput(). Default: array().
	 * @param $published {0; 1; 'all'} - Document publication status. Once the parameter equals 'all', the result will be returned regardless of whether the ducuments are published or they are not. Default: 1.
	 * @param $sortBy {string} - How to sort the result array (field). Default: 'menuindex'.
	 * @param $sortDir {'ASC'; 'DESC'} - How to sort the result array (direction). Default: 'ASC'.
	 * @param $where {string} - SQL WHERE condition (use only document fields, not TV). Default: ''.
	 * @param $resultKey {string; false} - Field, which values are keys into result array. Use the ???false???, that result array keys just will be numbered. Default: 'id'.
	 * 
	 * @return {array; false} - Result array, or false.
	 */
	function getDocumentChildrenTVarOutput($parentid = 0, $tvidnames = array(), $published = 1, $sortBy = 'menuindex', $sortDir = 'ASC', $where = '', $resultKey = 'id'){
		$docs = $this->getDocumentChildren($parentid, $published, 0, 'id', $where, $sortBy, $sortDir);
		
		if (!$docs){
			return false;
		}else{
			$result = array();
			
			$unsetResultKey = false;
			
			if ($resultKey !== false){
				if (is_array($tvidnames)){
					if (count($tvidnames) != 0 && !in_array($resultKey, $tvidnames)){
						$tvidnames[] = $resultKey;
						$unsetResultKey = true;
					}
				}else if ($tvidnames != '*' && $tvidnames != $resultKey){
					$tvidnames = array($tvidnames, $resultKey);
					$unsetResultKey = true;
				}
			}
			
			for ($i = 0; $i < count($docs); $i++){
				$tvs = $this->getTemplateVarOutput($tvidnames, $docs[$i]['id'], $published);
				
				if ($tvs){
					if ($resultKey !== false && array_key_exists($resultKey, $tvs)){
						$result[$tvs[$resultKey]] = $tvs;
						
						if ($unsetResultKey){
							unset($result[$tvs[$resultKey]][$resultKey]);
						}
					}else{
						$result[] = $tvs;
					}
				}
			}
			
			return $result;
		}
	}
	
    /**
     * Modified by Raymond for TV - Orig Modified by Apodigm - DocVars
     * Returns a single site_content field or TV record from the db.
     *
     * If a site content field the result is an associative array of 'name' and 'value'.
     *
     * If a TV the result is an array representing a db row including the fields specified in $fields.
     *
     * @param string $idname Can be a TV id or name
     * @param string $fields Fields to fetch from site_tmplvars. Default: *
     * @param type $docid Docid. Defaults to empty string which indicates the current document.
     * @param int $published Whether published or unpublished documents are in the result
     *                        Default: 1
     * @return boolean
     */
    function getTemplateVar($idname= "", $fields= "*", $docid= "", $published= 1) {
        if ($idname == "") {
            return false;
        } else {
            $result= $this->getTemplateVars(array ($idname), $fields, $docid, $published, "", ""); //remove sorting for speed
            return ($result != false) ? $result[0] : false;
        }
    }
	
	/**
	 * getTemplateVars
	 * @version 1.0.1 (2014-02-19)
	 * 
	 * @desc Returns an array of site_content field fields and/or TV records from the db.
	 * Elements representing a site content field consist of an associative array of 'name' and 'value'.
	 * Elements representing a TV consist of an array representing a db row including the fields specified in $fields.
	 * 
	 * @param $idnames {array; '*'} - Which TVs to fetch. Can relate to the TV ids in the db (array elements should be numeric only) or the TV names (array elements should be names only). @required
	 * @param $fields {comma separated string; '*'} - Fields names in the TV table of MODx database. Default: '*'
	 * @param $docid {integer; ''} - Id of a document to get. Default: an empty string which indicates the current document.
	 * @param $published {0; 1; 'all'} - Document publication status. Once the parameter equals 'all', the result will be returned regardless of whether the ducuments are published or they are not. Default: 1.
	 * @param $sort {comma separated string} - Fields of the TV table to sort by. Default: 'rank'.
	 * @param $dir {'ASC'; 'DESC'} - How to sort the result array (direction). Default: 'ASC'.
	 * 
	 * @return {array; false} - Result array, or false.
	 */
	function getTemplateVars($idnames = array(), $fields = '*', $docid = '', $published = 1, $sort = 'rank', $dir = 'ASC'){
		if (($idnames != '*' && !is_array($idnames)) || count($idnames) == 0){
			return false;
		}else{
			
			// get document record
			if ($docid == ''){
				$docid = $this->documentIdentifier;
				$docRow = $this->documentObject;
			}else{
				$docRow = $this->getDocument($docid, '*', $published);
				
				if (!$docRow){
					return false;
				}
			}
			
			// get user defined template variables
			$fields = ($fields == '') ? 'tv.*' : 'tv.'.implode(',tv.', array_filter(array_map('trim', explode(',', $fields))));
			$sort = ($sort == '') ? '' : 'tv.'.implode(',tv.', array_filter(array_map('trim', explode(',', $sort))));
			
			if ($idnames == '*'){
				$query = 'tv.id<>0';
			}else{
				$query = (is_numeric($idnames[0]) ? 'tv.id' : 'tv.name')." IN ('".implode("','", $idnames)."')";
			}
			
			$rs = $this->db->select(
				"{$fields}, IF(tvc.value != '', tvc.value, tv.default_text) as value",
				$this->getFullTableName('site_tmplvars') . " tv
					INNER JOIN " . $this->getFullTableName('site_tmplvar_templates') . " tvtpl ON tvtpl.tmplvarid = tv.id
					LEFT JOIN " . $this->getFullTableName('site_tmplvar_contentvalues') . " tvc ON tvc.tmplvarid=tv.id AND tvc.contentid = '{$docid}'",
				"{$query} AND tvtpl.templateid = '{$docRow['template']}'",
				($sort ? "{$sort} {$dir}" : "")
				);
			
			$result = $this->db->makeArray($rs);
			
			// get default/built-in template variables
			ksort($docRow);
			
			foreach ($docRow as $key => $value){
				if ($idnames == '*' || in_array($key, $idnames)){
					array_push($result, array(
						'name' => $key,
						'value' => $value
					));
				}
			}
			
			return $result;
		}
	}
	
	/**
	 * getTemplateVarOutput
	 * @version 1.0.1 (2014-02-19)
	 * 
	 * @desc Returns an associative array containing TV rendered output values.
	 * 
	 * @param $idnames {array; '*'} - Which TVs to fetch - Can relate to the TV ids in the db (array elements should be numeric only) or the TV names (array elements should be names only). @required
	 * @param $docid {integer; ''} - Id of a document to get. Default: an empty string which indicates the current document.
	 * @param $published {0; 1; 'all'} - Document publication status. Once the parameter equals 'all', the result will be returned regardless of whether the ducuments are published or they are not. Default: 1.
	 * @param $sep {string} - Separator that is used while concatenating in getTVDisplayFormat(). Default: ''.
	 * 
	 * @return {array; false} - Result array, or false.
	 */
	function getTemplateVarOutput($idnames = array(), $docid = '', $published = 1, $sep = ''){
		if (count($idnames) == 0){
			return false;
		}else{
			$output = array();
			$vars = ($idnames == '*' || is_array($idnames)) ? $idnames : array($idnames);
			
			$docid = intval($docid) ? intval($docid) : $this->documentIdentifier;
			// remove sort for speed
			$result = $this->getTemplateVars($vars, '*', $docid, $published, '', '');
			
			if ($result == false){
				return false;
			}else{
				$baspath = MODX_MANAGER_PATH.'includes';
				include_once $baspath.'/tmplvars.format.inc.php';
				include_once $baspath.'/tmplvars.commands.inc.php';
				
				for ($i= 0; $i < count($result); $i++){
					$row = $result[$i];
					
					if (!isset($row['id']) or !$row['id']){
						$output[$row['name']] = $row['value'];
					}else{
						$output[$row['name']] = getTVDisplayFormat($row['name'], $row['value'], $row['display'], $row['display_params'], $row['type'], $docid, $sep);
					}
				}
				
				return $output;
			}
		}
	}
	
    /**
     * Returns the full table name based on db settings
     *
     * @param string $tbl Table name
     * @return string Table name with prefix
     */
    function getFullTableName($tbl) {
        return $this->db->config['dbase'] . ".`" . $this->db->config['table_prefix'] . $tbl . "`";
    }

    /**
     * Returns the placeholder value
     *
     * @param string $name Placeholder name
     * @return string Placeholder value
     */
    function getPlaceholder($name) {
        return isset($this->placeholders[$name]) ? $this->placeholders[$name] : null;
    }

    /**
     * Sets a value for a placeholder
     *
     * @param string $name The name of the placeholder
     * @param string $value The value of the placeholder
     */
    function setPlaceholder($name, $value) {
        $this->placeholders[$name]= $value;
    }

    /**
     * Set placeholders en masse via an array or object.
     *
     * @param object|array $subject
     * @param string $prefix
     */
    function toPlaceholders($subject, $prefix= '') {
        if (is_object($subject)) {
            $subject= get_object_vars($subject);
        }
        if (is_array($subject)) {
            foreach ($subject as $key => $value) {
                $this->toPlaceholder($key, $value, $prefix);
            }
        }
    }

    /**
     * For use by toPlaceholders(); For setting an array or object element as placeholder.
     *
     * @param string $key
     * @param object|array $value
     * @param string $prefix
     */
    function toPlaceholder($key, $value, $prefix= '') {
        if (is_array($value) || is_object($value)) {
            $this->toPlaceholders($value, "{$prefix}{$key}.");
        } else {
            $this->setPlaceholder("{$prefix}{$key}", $value);
        }
    }

    /**
     * Returns the manager relative URL/path with respect to the site root.
     *
     * @global string $base_url
     * @return string The complete URL to the manager folder
     */
    function getManagerPath() {
        return MODX_MANAGER_URL;
    }

   /**
     * Returns the cache relative URL/path with respect to the site root.
     *
     * @global string $base_url
     * @return string The complete URL to the cache folder
     */
    function getCachePath() {
        global $base_url;
        $pth= $base_url . 'assets/cache/';
        return $pth;
    }

    /**
     * Sends a message to a user's message box.
     *
     * @param string $type Type of the message
     * @param string $to The recipient of the message
     * @param string $from The sender of the message
     * @param string $subject The subject of the message
     * @param string $msg The message body
     * @param int $private Whether it is a private message, or not
     *                     Default : 0
     */
    function sendAlert($type, $to, $from, $subject, $msg, $private= 0) {
        $private= ($private) ? 1 : 0;
        if (!is_numeric($to)) {
            // Query for the To ID
            $rs = $this->db->select('id', $this->getFullTableName("manager_users"), "username='{$to}'");
            $to = $this->db->getValue($rs);
        }
        if (!is_numeric($from)) {
            // Query for the From ID
            $rs = $this->db->select('id', $this->getFullTableName("manager_users"), "username='{$from}'");
            $from = $this->db->getValue($rs);
        }
        // insert a new message into user_messages
        $this->db->insert(
            array(
                'type'        => $type,
                'subject'     => $subject,
                'message'     => $msg,
                'sender'      => $from,
                'recipient'   => $to,
                'private'     => $private,
                'postdate'    => time() + $this->config['server_offset_time'],
                'messageread' => 0,
            ), $this->getFullTableName('user_messages'));
    }

    /**
     * Returns current user id.
     *
     * @param string $context. Default is an empty string which indicates the method should automatically pick 'web (frontend) or 'mgr' (backend)
     * @return string
     */
    function getLoginUserID($context= '') {
        if ($context && isset ($_SESSION[$context . 'Validated'])) {
            return $_SESSION[$context . 'InternalKey'];
        }
        elseif ($this->isFrontend() && isset ($_SESSION['webValidated'])) {
            return $_SESSION['webInternalKey'];
        }
        elseif ($this->isBackend() && isset ($_SESSION['mgrValidated'])) {
            return $_SESSION['mgrInternalKey'];
        }
    }

    /**
     * Returns current user name
     *
     * @param string $context. Default is an empty string which indicates the method should automatically pick 'web (frontend) or 'mgr' (backend)
     * @return string
     */
    function getLoginUserName($context= '') {
        if (!empty($context) && isset ($_SESSION[$context . 'Validated'])) {
            return $_SESSION[$context . 'Shortname'];
        }
        elseif ($this->isFrontend() && isset ($_SESSION['webValidated'])) {
            return $_SESSION['webShortname'];
        }
        elseif ($this->isBackend() && isset ($_SESSION['mgrValidated'])) {
            return $_SESSION['mgrShortname'];
        }
    }

   /**
     * Returns current login user type - web or manager
     *
     * @return string
     */
    function getLoginUserType() {
        if ($this->isFrontend() && isset ($_SESSION['webValidated'])) {
            return 'web';
        }
        elseif ($this->isBackend() && isset ($_SESSION['mgrValidated'])) {
            return 'manager';
        } else {
            return '';
        }
    }

    /**
     * Returns a user info record for the given manager user
     *
     * @param int $uid
     * @return boolean|string
     */
    function getUserInfo($uid) {
        $rs = $this->db->select(
            'mu.username, mu.password, mua.*',
            $this->getFullTableName("manager_users") . " mu
                INNER JOIN " . $this->getFullTableName("user_attributes") . " mua ON mua.internalkey=mu.id",
            "mu.id = '{$uid}'"
            );
        if ($row = $this->db->getRow($rs)) {
            if (!isset($row['usertype']) or !$row["usertype"])
                $row["usertype"]= "manager";
            return $row;
        }
    }

    /**
     * Returns a record for the web user
     *
     * @param int $uid
     * @return boolean|string
     */
    function getWebUserInfo($uid) {
        $rs = $this->db->select(
            'wu.username, wu.password, wua.*',
            $this->getFullTableName("web_users") . " wu
                INNER JOIN " . $this->getFullTableName("web_user_attributes") . " wua ON wua.internalkey=wu.id",
            "wu.id='{$uid}'"
            );
        if ($row = $this->db->getRow($rs)) {
            if (!isset($row['usertype']) or !$row["usertype"])
                $row["usertype"]= "web";
            return $row;
        }
    }

    /**
     * Returns an array of document groups that current user is assigned to.
     * This function will first return the web user doc groups when running from
     * frontend otherwise it will return manager user's docgroup.
     *
     * @param boolean $resolveIds Set to true to return the document group names
     *                            Default: false
     * @return string|array
     */
    function getUserDocGroups($resolveIds= false) {
        if ($this->isFrontend() && isset ($_SESSION['webDocgroups']) && isset ($_SESSION['webValidated'])) {
            $dg= $_SESSION['webDocgroups'];
            $dgn= isset ($_SESSION['webDocgrpNames']) ? $_SESSION['webDocgrpNames'] : false;
        } else
            if ($this->isBackend() && isset ($_SESSION['mgrDocgroups']) && isset ($_SESSION['mgrValidated'])) {
                $dg= $_SESSION['mgrDocgroups'];
                $dgn= isset ($_SESSION['mgrDocgrpNames']) ? $_SESSION['mgrDocgrpNames'] : false;
            } else {
                $dg= '';
            }
        if (!$resolveIds)
            return $dg;
        else
            if (is_array($dgn))
                return $dgn;
            else
                if (is_array($dg)) {
                    // resolve ids to names
                    $ds = $this->db->select('name', $this->getFullTableName("documentgroup_names"), "id IN (".implode(",", $dg).")");
                    while ($row= $this->db->getRow($ds))
                        $dgn[count($dgn)]= $row['name'];
                    // cache docgroup names to session
                    if ($this->isFrontend())
                        $_SESSION['webDocgrpNames']= $dgn;
                    else
                        $_SESSION['mgrDocgrpNames']= $dgn;
                    return $dgn;
                }
    }

    /**
     * Change current web user's password
     *
     * @todo Make password length configurable, allow rules for passwords and translation of messages
     * @param string $oldPwd
     * @param string $newPwd
     * @return string|boolean Returns true if successful, oterhwise return error
     *                        message
     */
     function changeWebUserPassword($oldPwd, $newPwd) {
        $rt= false;
        if ($_SESSION["webValidated"] == 1) {
            $tbl= $this->getFullTableName("web_users");
            $ds = $this->db->select('id, username, password', $tbl, "id='".$this->getLoginUserID()."'");
            if ($row= $this->db->getRow($ds)) {
                if ($row["password"] == md5($oldPwd)) {
                    if (strlen($newPwd) < 6) {
                        return "Password is too short!";
                    }
                    elseif ($newPwd == "") {
                        return "You didn't specify a password for this user!";
                    } else {
                        $this->db->update(
                            array(
                                'password' => $this->db->escape($newPwd),
                            ), $tbl, "id='".$this->getLoginUserID()."'");
                        // invoke OnWebChangePassword event
                        $this->invokeEvent("OnWebChangePassword", array (
                            "userid" => $row["id"],
                            "username" => $row["username"],
                            "userpassword" => $newPwd
                        ));
                        return true;
                    }
                } else {
                    return "Incorrect password.";
                }
            }
        }
    }

    /**
     * Returns true if the current web user is a member the specified groups
     *
     * @param array $groupNames
     * @return boolean
     */
    function isMemberOfWebGroup($groupNames= array ()) {
        if (!is_array($groupNames))
            return false;
        // check cache
        $grpNames= isset ($_SESSION['webUserGroupNames']) ? $_SESSION['webUserGroupNames'] : false;
        if (!is_array($grpNames)) {
            $rs = $this->db->select(
                'wgn.name',
                $this->getFullTableName("webgroup_names")." wgn
                    INNER JOIN ".$this->getFullTableName("web_groups")." wg ON wg.webgroup=wgn.id AND wg.webuser='" . $this->getLoginUserID() . "'"
                );
            $grpNames= $this->db->getColumn("name", $rs);
            // save to cache
            $_SESSION['webUserGroupNames']= $grpNames;
        }
        foreach ($groupNames as $k => $v)
            if (in_array(trim($v), $grpNames))
                return true;
        return false;
    }

    /**
     * Registers Client-side CSS scripts - these scripts are loaded at inside
     * the <head> tag
     *
     * @param string $src
     * @param string $media Default: Empty string
     */
    function regClientCSS($src, $media='') {
        if (empty($src) || isset ($this->loadedjscripts[$src]))
            return '';
        $nextpos= max(array_merge(array(0),array_keys($this->sjscripts)))+1;
        $this->loadedjscripts[$src]['startup']= true;
        $this->loadedjscripts[$src]['version']= '0';
        $this->loadedjscripts[$src]['pos']= $nextpos;
        if (strpos(strtolower($src), "<style") !== false || strpos(strtolower($src), "<link") !== false) {
            $this->sjscripts[$nextpos]= $src;
        } else {
            $this->sjscripts[$nextpos]= "\t" . '<link rel="stylesheet" type="text/css" href="'.$src.'" '.($media ? 'media="'.$media.'" ' : '').'/>';
        }
    }

    /**
     * Registers Startup Client-side JavaScript - these scripts are loaded at inside the <head> tag
     *
     * @param string $src
     * @param array $options Default: 'name'=>'', 'version'=>'0', 'plaintext'=>false
     */
    function regClientStartupScript($src, $options= array('name'=>'', 'version'=>'0', 'plaintext'=>false)) {
        $this->regClientScript($src, $options, true);
    }

    /**
     * Registers Client-side JavaScript these scripts are loaded at the end of the page unless $startup is true
     *
     * @param string $src
     * @param array $options Default: 'name'=>'', 'version'=>'0', 'plaintext'=>false
     * @param boolean $startup Default: false
     * @return string
     */
    function regClientScript($src, $options= array('name'=>'', 'version'=>'0', 'plaintext'=>false), $startup= false) {
        if (empty($src))
            return ''; // nothing to register
        if (!is_array($options)) {
            if (is_bool($options))  // backward compatibility with old plaintext parameter
                $options=array('plaintext'=>$options);
            elseif (is_string($options)) // Also allow script name as 2nd param
                $options=array('name'=>$options);
            else
                $options=array();
        }
        $name= isset($options['name']) ? strtolower($options['name']) : '';
        $version= isset($options['version']) ? $options['version'] : '0';
        $plaintext= isset($options['plaintext']) ? $options['plaintext'] : false;
        $key= !empty($name) ? $name : $src;
        unset($overwritepos); // probably unnecessary--just making sure

        $useThisVer= true;
        if (isset($this->loadedjscripts[$key])) { // a matching script was found
            // if existing script is a startup script, make sure the candidate is also a startup script
            if ($this->loadedjscripts[$key]['startup'])
                $startup= true;

            if (empty($name)) {
                $useThisVer= false; // if the match was based on identical source code, no need to replace the old one
            } else {
                $useThisVer = version_compare($this->loadedjscripts[$key]['version'], $version, '<');
            }

            if ($useThisVer) {
                if ($startup==true && $this->loadedjscripts[$key]['startup']==false) {
                    // remove old script from the bottom of the page (new one will be at the top)
                    unset($this->jscripts[$this->loadedjscripts[$key]['pos']]);
                } else {
                    // overwrite the old script (the position may be important for dependent scripts)
                    $overwritepos= $this->loadedjscripts[$key]['pos'];
                }
            } else { // Use the original version
                if ($startup==true && $this->loadedjscripts[$key]['startup']==false) {
                    // need to move the exisiting script to the head
                    $version= $this->loadedjscripts[$key][$version];
                    $src= $this->jscripts[$this->loadedjscripts[$key]['pos']];
                    unset($this->jscripts[$this->loadedjscripts[$key]['pos']]);
                } else {
                    return ''; // the script is already in the right place
                }
            }
        }

        if ($useThisVer && $plaintext!=true && (strpos(strtolower($src), "<script") === false))
            $src= "\t" . '<script type="text/javascript" src="' . $src . '"></script>';
        if ($startup) {
            $pos= isset($overwritepos) ? $overwritepos : max(array_merge(array(0),array_keys($this->sjscripts)))+1;
            $this->sjscripts[$pos]= $src;
        } else {
            $pos= isset($overwritepos) ? $overwritepos : max(array_merge(array(0),array_keys($this->jscripts)))+1;
            $this->jscripts[$pos]= $src;
        }
        $this->loadedjscripts[$key]['version']= $version;
        $this->loadedjscripts[$key]['startup']= $startup;
        $this->loadedjscripts[$key]['pos']= $pos;
    }

    /**
     * Returns all registered JavaScripts
     *
     * @return string
     */
    function regClientStartupHTMLBlock($html) {
        $this->regClientScript($html, true, true);
    }

    /**
     * Returns all registered startup scripts
     *
     * @return string
     */
    function regClientHTMLBlock($html) {
        $this->regClientScript($html, true);
    }

   /**
     * Remove unwanted html tags and snippet, settings and tags
     *
     * @param string $html
     * @param string $allowed Default: Empty string
     * @return string
     */
    function stripTags($html, $allowed= "") {
        $t= strip_tags($html, $allowed);
        $t= preg_replace('~\[\*(.*?)\*\]~', "", $t); //tv
        $t= preg_replace('~\[\[(.*?)\]\]~', "", $t); //snippet
        $t= preg_replace('~\[\!(.*?)\!\]~', "", $t); //snippet
        $t= preg_replace('~\[\((.*?)\)\]~', "", $t); //settings
        $t= preg_replace('~\[\+(.*?)\+\]~', "", $t); //placeholders
        $t= preg_replace('~{{(.*?)}}~', "", $t); //chunks
        return $t;
    }

   /**
     * Add an event listner to a plugin - only for use within the current execution cycle
     *
     * @param string $evtName
     * @param string $pluginName
     * @return boolean|int
     */
    function addEventListener($evtName, $pluginName) {
	    if (!$evtName || !$pluginName)
		    return false;
	    if (!array_key_exists($evtName,$this->pluginEvent))
		    $this->pluginEvent[$evtName] = array();
	    return array_push($this->pluginEvent[$evtName], $pluginName); // return array count
    }

    /**
     * Remove event listner - only for use within the current execution cycle
     *
     * @param string $evtName
     * @return boolean
     */
    function removeEventListener($evtName) {
        if (!$evtName)
            return false;
        unset ($this->pluginEvent[$evtName]);
    }

    /**
     * Remove all event listners - only for use within the current execution cycle
     */
    function removeAllEventListener() {
        unset ($this->pluginEvent);
        $this->pluginEvent= array ();
    }

    /**
     * Invoke an event.
     *
     * @param string $evtName
     * @param array $extParams Parameters available to plugins. Each array key will be the PHP variable name, and the array value will be the variable value.
     * @return boolean|array
     */
    function invokeEvent($evtName, $extParams= array ()) {
        if (!$evtName)
            return false;
        if (!isset ($this->pluginEvent[$evtName]))
            return false;
        $el= $this->pluginEvent[$evtName];
        $results= array ();
        $numEvents= count($el);
        if ($numEvents > 0)
            for ($i= 0; $i < $numEvents; $i++) { // start for loop
                if ($this->dumpPlugins == 1) $eventtime = $this->getMicroTime();
                $pluginName= $el[$i];
                $pluginName = stripslashes($pluginName);
                // reset event object
                $e= & $this->Event;
                $e->_resetEventObject();
                $e->name= $evtName;
                $e->activePlugin= $pluginName;

                // get plugin code
                if (isset ($this->pluginCache[$pluginName])) {
                    $pluginCode= $this->pluginCache[$pluginName];
                    $pluginProperties= isset($this->pluginCache[$pluginName . "Props"]) ? $this->pluginCache[$pluginName . "Props"] : '';
                } else {
                    $result = $this->db->select('name, plugincode, properties', $this->getFullTableName("site_plugins"), "name='{$pluginName}' AND disabled=0");
                    if ($row= $this->db->getRow($result)) {
                        $pluginCode= $this->pluginCache[$row['name']]= $row['plugincode'];
                        $pluginProperties= $this->pluginCache[$row['name'] . "Props"]= $row['properties'];
                    } else {
                        $pluginCode= $this->pluginCache[$pluginName]= "return false;";
                        $pluginProperties= '';
                    }
                }

                // load default params/properties
                $parameter= $this->parseProperties($pluginProperties);
                if (!empty ($extParams))
                    $parameter= array_merge($parameter, $extParams);

                // eval plugin
                $this->evalPlugin($pluginCode, $parameter);
                if ($this->dumpPlugins == 1) {
                    $eventtime = $this->getMicroTime() - $eventtime;
                    $this->pluginsCode .= '<fieldset><legend><b>' . $evtName . ' / ' . $pluginName . '</b> ('.sprintf('%2.2f ms', $eventtime*1000).')</legend>';
                    foreach ($parameter as $k=>$v) $this->pluginsCode .= $k . ' => ' . print_r($v, true) . '<br>';
                    $this->pluginsCode .= '</fieldset><br />';
                    $this->pluginsTime["$evtName / $pluginName"] += $eventtime;
                }
                if ($e->_output != "")
                    $results[]= $e->_output;
                if ($e->_propagate != true)
                    break;
            }
        $e->activePlugin= "";
        return $results;
    }

    /**
     * Parses a resource property string and returns the result as an array
     *
     * @param string $propertyString
     * @return array Associative array in the form property name => property value
     */
    function parseProperties($propertyString) {
        $parameter= array ();
        if (!empty ($propertyString)) {
            $tmpParams= explode("&", $propertyString);
            for ($x= 0; $x < count($tmpParams); $x++) {
                if (strpos($tmpParams[$x], '=', 0)) {
                    $pTmp= explode("=", $tmpParams[$x]);
                    $pvTmp= explode(";", trim($pTmp[1]));
                    if ($pvTmp[1] == 'list' && $pvTmp[3] != "")
                        $parameter[trim($pTmp[0])]= $pvTmp[3]; //list default
                    else
                        if ($pvTmp[1] != 'list' && $pvTmp[2] != "")
                            $parameter[trim($pTmp[0])]= $pvTmp[2];
                }
            }
        }
        return $parameter;
    }

    /***************************************************************************************/
    /* End of API functions								       */
    /***************************************************************************************/

    /**
     * PHP error handler set by http://www.php.net/manual/en/function.set-error-handler.php
     *
     * Checks the PHP error and calls messageQuit() unless:
     *  - error_reporting() returns 0, or
     *  - the PHP error level is 0, or
     *  - the PHP error level is 8 (E_NOTICE) and stopOnNotice is false
     *
     * @param int $nr The PHP error level as per http://www.php.net/manual/en/errorfunc.constants.php
     * @param string $text Error message
     * @param string $file File where the error was detected
     * @param string $line Line number within $file
     * @return boolean
     */
    function phpError($nr, $text, $file, $line) {
        if (error_reporting() == 0 || $nr == 0) {
            return true;
        }
        if($this->stopOnNotice == false)
        {
            switch($nr)
            {
                case E_NOTICE:
                    if($this->error_reporting <= 2) return true;
                    break;
                case E_STRICT:
                case E_DEPRECATED:
                    if($this->error_reporting <= 1) return true;
                    break;
                default:
                    if($this->error_reporting === 0) return true;
            }
        }
        if (is_readable($file)) {
            $source= file($file);
            $source= $this->htmlspecialchars($source[$line -1]);
        } else {
            $source= "";
        } //Error $nr in $file at $line: <div><code>$source</code></div>
        $this->messageQuit("PHP Parse Error", '', true, $nr, $file, $source, $text, $line);
    }

	function messageQuit($msg= 'unspecified error', $query= '', $is_error= true, $nr= '', $file= '', $source= '', $text= '', $line= '', $output='') {
		
	    $version= isset ($GLOBALS['modx_version']) ? $GLOBALS['modx_version'] : '';
		$release_date= isset ($GLOBALS['release_date']) ? $GLOBALS['release_date'] : '';
	    $request_uri = "http://".$_SERVER['HTTP_HOST'].($_SERVER["SERVER_PORT"]==80?"":(":".$_SERVER["SERVER_PORT"])).$_SERVER['REQUEST_URI'];
	    $request_uri = $this->htmlspecialchars($request_uri, ENT_QUOTES, $this->config['modx_charset']);
	    $ua          = $this->htmlspecialchars($_SERVER['HTTP_USER_AGENT'], ENT_QUOTES, $this->config['modx_charset']);
	    $referer     = $this->htmlspecialchars($_SERVER['HTTP_REFERER'], ENT_QUOTES, $this->config['modx_charset']);
	    if ($is_error) {
	        $str = '<h3 style="color:red">&laquo; MODX Parse Error &raquo;</h3>
	                <table border="0" cellpadding="1" cellspacing="0">
	                <tr><td colspan="2">MODX encountered the following error while attempting to parse the requested resource:</td></tr>
	                <tr><td colspan="2"><b style="color:red;">&laquo; ' . $msg . ' &raquo;</b></td></tr>';
	    } else {
	        $str = '<h3 style="color:#003399">&laquo; MODX Debug/ stop message &raquo;</h3>
	                <table border="0" cellpadding="1" cellspacing="0">
	                <tr><td colspan="2">The MODX parser recieved the following debug/ stop message:</td></tr>
	                <tr><td colspan="2"><b style="color:#003399;">&laquo; ' . $msg . ' &raquo;</b></td></tr>';
	    }
	
	    if (!empty ($query)) {
	        $str .= '<tr><td colspan="2"><div style="font-weight:bold;border:1px solid #ccc;padding:8px;color:#333;background-color:#ffffcd;">SQL &gt; <span id="sqlHolder">' . $query . '</span></div>
	                </td></tr>';
	    }
	
	    $errortype= array (
	        E_ERROR             => "ERROR",
	        E_WARNING           => "WARNING",
	        E_PARSE             => "PARSING ERROR",
	        E_NOTICE            => "NOTICE",
	        E_CORE_ERROR        => "CORE ERROR",
	        E_CORE_WARNING      => "CORE WARNING",
	        E_COMPILE_ERROR     => "COMPILE ERROR",
	        E_COMPILE_WARNING   => "COMPILE WARNING",
	        E_USER_ERROR        => "USER ERROR",
	        E_USER_WARNING      => "USER WARNING",
	        E_USER_NOTICE       => "USER NOTICE",
	        E_STRICT            => "STRICT NOTICE",
	        E_RECOVERABLE_ERROR => "RECOVERABLE ERROR",
	        E_DEPRECATED        => "DEPRECATED",
	        E_USER_DEPRECATED   => "USER DEPRECATED"
	    );
	
		if(!empty($nr) || !empty($file))
		{
			$str .= '<tr><td colspan="2"><b>PHP error debug</b></td></tr>';
			if ($text != '')
			{
				$str .= '<tr><td colspan="2"><div style="font-weight:bold;border:1px solid #ccc;padding:8px;color:#333;background-color:#ffffcd;">Error : ' . $text . '</div></td></tr>';
			}
			if($output!='')
			{
				$str .= '<tr><td colspan="2"><div style="font-weight:bold;border:1px solid #ccc;padding:8px;color:#333;background-color:#ffffcd;">' . $output . '</div></td></tr>';
			}
			$str .= '<tr><td valign="top">ErrorType[num] : </td>';
			$str .= '<td>' . $errortype [$nr] . "[{$nr}]</td>";
			$str .= '</tr>';
			$str .= "<tr><td>File : </td><td>{$file}</td></tr>";
			$str .= "<tr><td>Line : </td><td>{$line}</td></tr>";
		}
	    
	    if ($source != '')
	    {
	        $str .= "<tr><td>Source : </td><td>{$source}</td></tr>";
	    }
	
	    $str .= '<tr><td colspan="2"><b>Basic info</b></td></tr>';
	
	    $str .= '<tr><td valign="top" style="white-space:nowrap;">REQUEST_URI : </td>';
	    $str .= "<td>{$request_uri}</td>";
	    $str .= '</tr>';
	    
	    if(isset($_GET['a']))      $action = $_GET['a'];
	    elseif(isset($_POST['a'])) $action = $_POST['a'];
	    if(isset($action) && !empty($action))
	    {
	    	include_once(MODX_MANAGER_PATH . 'includes/actionlist.inc.php');
	    	global $action_list;
	    	if(isset($action_list[$action])) $actionName = " - {$action_list[$action]}";
	    	else $actionName = '';
			$str .= '<tr><td valign="top">Manager action : </td>';
			$str .= "<td>{$action}{$actionName}</td>";
			$str .= '</tr>';
	    }
	    
	    if(preg_match('@^[0-9]+@',$this->documentIdentifier))
	    {
	    	$resource  = $this->getDocumentObject('id',$this->documentIdentifier);
	    	$url = $this->makeUrl($this->documentIdentifier,'','','full');
	    	$link = '<a href="' . $url . '" target="_blank">' . $resource['pagetitle'] . '</a>';
			$str .= '<tr><td valign="top">Resource : </td>';
			$str .= '<td>[' . $this->documentIdentifier . ']' . $link . '</td></tr>';
	    }
	
	    if(!empty($this->currentSnippet))
	    {
	        $str .= "<tr><td>Current Snippet : </td>";
	        $str .= '<td>' . $this->currentSnippet . '</td></tr>';
	    }
	
	    if(!empty($this->event->activePlugin))
	    {
	        $str .= "<tr><td>Current Plugin : </td>";
	        $str .= '<td>' . $this->event->activePlugin . '(' . $this->event->name . ')' . '</td></tr>';
	    }
	
	    $str .= "<tr><td>Referer : </td><td>{$referer}</td></tr>";
	    $str .= "<tr><td>User Agent : </td><td>{$ua}</td></tr>";
	
	    $str .= "<tr><td>IP : </td>";
	    $str .= '<td>' . $_SERVER['REMOTE_ADDR'] . '</td>';
	    $str .= '</tr>';
	
	    $str .= '<tr><td colspan="2"><b>Benchmarks</b></td></tr>';
	
	    $str .= "<tr><td>MySQL : </td>";
	    $str .= '<td>[^qt^] ([^q^] Requests)</td>';
	    $str .= '</tr>';
	
	    $str .= "<tr><td>PHP : </td>";
	    $str .= '<td>[^p^]</td>';
	    $str .= '</tr>';
	
	    $str .= "<tr><td>Total : </td>";
	    $str .= '<td>[^t^]</td>';
	    $str .= '</tr>';
	
	    $str .= "<tr><td>Memory : </td>";
	    $str .= '<td>[^m^]</td>';
	    $str .= '</tr>';
	    
	    $str .= "</table>\n";
	
	    $totalTime= ($this->getMicroTime() - $this->tstart);
	
		$mem = memory_get_peak_usage(true);
		$total_mem = $mem - $this->mstart;
		$total_mem = ($total_mem / 1024 / 1024) . ' mb';
		
	    $queryTime= $this->queryTime;
	    $phpTime= $totalTime - $queryTime;
	    $queries= isset ($this->executedQueries) ? $this->executedQueries : 0;
	    $queryTime= sprintf("%2.4f s", $queryTime);
	    $totalTime= sprintf("%2.4f s", $totalTime);
	    $phpTime= sprintf("%2.4f s", $phpTime);
	
	    $str= str_replace('[^q^]', $queries, $str);
	    $str= str_replace('[^qt^]',$queryTime, $str);
	    $str= str_replace('[^p^]', $phpTime, $str);
	    $str= str_replace('[^t^]', $totalTime, $str);
	    $str= str_replace('[^m^]', $total_mem, $str);
	
	    if(isset($php_errormsg) && !empty($php_errormsg)) $str = "<b>{$php_errormsg}</b><br />\n{$str}";
		$str .= '<br />' . $this->get_backtrace(debug_backtrace()) . "\n";
		
	    // Log error
	    if(!empty($this->currentSnippet)) $source = 'Snippet - ' . $this->currentSnippet;
	    elseif(!empty($this->event->activePlugin)) $source = 'Plugin - ' . $this->event->activePlugin;
	    elseif($source!=='') $source = 'Parser - ' . $source;
	    elseif($query!=='')  $source = 'SQL Query';
	    else             $source = 'Parser';
	    if(isset($actionName) && !empty($actionName)) $source .= $actionName;
	    switch($nr)
	    {
	    	case E_DEPRECATED :
	    	case E_USER_DEPRECATED :
	    	case E_STRICT :
	    	case E_NOTICE :
	    	case E_USER_NOTICE :
	    		$error_level = 2;
	    		break;
	    	default:
	    		$error_level = 3;
	    }
	    $this->logEvent(0, $error_level, $str,$source);
	
        if($error_level === 2 && $this->error_reporting!=='99') return true;
        if($this->error_reporting==='99' && !isset($_SESSION['mgrValidated'])) return true;
    
	    // Set 500 response header
	    if($error_level !== 2) header('HTTP/1.1 500 Internal Server Error');
	
	    // Display error
	    if (isset($_SESSION['mgrValidated']))
	    {
	        echo '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd"><html><head><title>MODX Content Manager ' . $version . ' &raquo; ' . $release_date . '</title>
	             <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	             <link rel="stylesheet" type="text/css" href="' . $this->config['site_manager_url'] . 'media/style/' . $this->config['manager_theme'] . '/style.css" />
	             <style type="text/css">body { padding:10px; } td {font:inherit;}</style>
	             </head><body>
	             ' . $str . '</body></html>';
	    
	    }
	    else  echo 'Error';
	    ob_end_flush();
		exit;
	}
	
	function get_backtrace($backtrace) {
	
		$str = "<p><b>Backtrace</b></p>\n";
		$str  .= '<table>';
		$backtrace = array_reverse($backtrace);
		foreach ($backtrace as $key => $val)
		{
			$key++;
			if(substr($val['function'],0,11)==='messageQuit') break;
			elseif(substr($val['function'],0,8)==='phpError') break;
			$path = str_replace('\\','/',$val['file']);
			if(strpos($path,MODX_BASE_PATH)===0) $path = substr($path,strlen(MODX_BASE_PATH));
			switch($val['type'])
			{
				case '->':
				case '::':
					$functionName = $val['function'] = $val['class'] . $val['type'] . $val['function'];
					break;
				default:
					$functionName = $val['function'];
			}
			$str .= "<tr><td valign=\"top\">{$key}</td>";
			$str .= "<td>{$functionName}()<br />{$path} on line {$val['line']}</td>";
		}
		$str .= '</table>';
		return $str;
	}

    function getRegisteredClientScripts() {
        return implode("\n", $this->jscripts);
    }

    function getRegisteredClientStartupScripts() {
        return implode("\n", $this->sjscripts);
    }
    
	/**
	 * Format alias to be URL-safe. Strip invalid characters.
	 *
	 * @param string $alias Alias to be formatted
	 * @return string Safe alias
	 */
    function stripAlias($alias) {
        // let add-ons overwrite the default behavior
        $results = $this->invokeEvent('OnStripAlias', array ('alias'=>$alias));
        if (!empty($results)) {
            // if multiple plugins are registered, only the last one is used
            return end($results);
        } else {
            // default behavior: strip invalid characters and replace spaces with dashes.
            $alias = strip_tags($alias); // strip HTML
            $alias = preg_replace('/[^\.A-Za-z0-9 _-]/', '', $alias); // strip non-alphanumeric characters
            $alias = preg_replace('/\s+/', '-', $alias); // convert white-space to dash
            $alias = preg_replace('/-+/', '-', $alias);  // convert multiple dashes to one
            $alias = trim($alias, '-'); // trim excess
            return $alias;
        }
    }
    
	function nicesize($size) {
		$sizes = array('Tb'=>1099511627776, 'Gb'=>1073741824, 'Mb'=>1048576, 'Kb'=>1024, 'b'=>1);
		$precisions = count($sizes)-1;
		foreach ($sizes as $unit=>$bytes) {
			if ($size>=$bytes)
				return number_format($size/$bytes, $precisions).' '.$unit;
			$precisions--;
		}
		return '0 b';
	}

	function getIdFromAlias($alias)
	{
		$children = array();

		$tbl_site_content = $this->getFullTableName('site_content');
		if($this->config['use_alias_path']==1)
		{
			if(strpos($alias,'/')!==false) $_a = explode('/', $alias);
			else                           $_a[] = $alias;
			$id= 0;
			
			foreach($_a as $alias)
			{
				if($id===false) break;
				$alias = $this->db->escape($alias);
				$rs  = $this->db->select('id', $tbl_site_content, "deleted=0 and parent='{$id}' and alias='{$alias}'");
				if($this->db->getRecordCount($rs)==0) $rs  = $this->db->select('id', $tbl_site_content, "deleted=0 and parent='{$id}' and id='{$alias}'");
				$id = $this->db->getValue($rs);
				if (!$id) $id = false;
			}
		}
		else
		{
			$rs = $this->db->select('id', $tbl_site_content, "deleted=0 and alias='{$alias}'", 'parent, menuindex');
			$id = $this->db->getValue($rs);
			if (!$id) $id = false;
		}
		return $id;
	}

	// php compat
	function htmlspecialchars($str, $flags = ENT_COMPAT)
	{
		$this->loadExtension('PHPCOMPAT');
		return $this->phpcompat->htmlspecialchars($str, $flags);
	}
    // End of class.

}

/**
 * System Event Class
 */
class SystemEvent {
    var $name;
    var $_propagate;
    var $_output;
    var $activated;
    var $activePlugin;

    /**
     * @param string $name Name of the event
     */
    function SystemEvent($name= "") {
        $this->_resetEventObject();
        $this->name= $name;
    }

    /**
     * Display a message to the user
     *
     * @global array $SystemAlertMsgQueque
     * @param string $msg The message
     */
    function alert($msg) {
        global $SystemAlertMsgQueque;
        if ($msg == "")
            return;
        if (is_array($SystemAlertMsgQueque)) {
            $title = '';
            if ($this->name && $this->activePlugin) {
                $title= "<div><b>" . $this->activePlugin . "</b> - <span style='color:maroon;'>" . $this->name . "</span></div>";
            }
            $SystemAlertMsgQueque[]= "$title<div style='margin-left:10px;margin-top:3px;'>$msg</div>";
        }
    }

    /**
     * Output
     * 
     * @param string $msg 
     */
    function output($msg) {
        $this->_output .= $msg;
    }

    /** 
     * Stop event propogation
     */
    function stopPropagation() {
        $this->_propagate= false;
    }

    function _resetEventObject() {
        unset ($this->returnedValues);
        $this->name= "";
        $this->_output= "";
        $this->_propagate= true;
        $this->activated= false;
    }
}
