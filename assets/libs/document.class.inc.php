<?php
/***************************************************************
  Name: Docmanager
  Description: Class for editing/creating/duplicating/deleting documents
  Version 0.5.3
  Author: ur001
  e-mail: ur001@mail.ru

  Example of use:
	require_once('assets/libs/document.class.inc.php');
	$doc = new Document();
	$doc->Set('parent',$folder);
	$doc->Set('alias','post'.time());
	$doc->Set('content','document content');
	$doc->Set('template','GuestBookComments');
	$doc->Set('tvComment','post to comment');
	$doc->Save();

  Area of use:
	guestbooks, blogs, forums, frontend manager modules

  TODO:
	* document_groups

  Important:
	2) Not to be used just for receiving TV values or deleting docs. Use  
	   $modx->getTemplateVars(); and $modx->db->delete(); instead.

***************************************************************/
class Document{
	public $fields;	// doc fields array
	public $tvs;		// TV array
	
	public $tvNames;	// TV names array
	public $oldTVs;	// TV values array
	public $isNew;		// true - new doc, false - existing doc

	/***********************************************
	  Initializing class
	  $id   - existing doc id or 0 for new doc
	  $fields - comma delimited field list
	************************************************/	
	function Document($id=0,$fields="*"){
		global $modx;
		$this->isNew = $id==0;
		if(!$this->isNew){
			$this->fields = $modx->getPageInfo($id,0,$fields);
			$this->fields['id']=$id;
		}
		else
			$this->fields = array(
				'pagetitle'	=> 'New document',
				'alias'		=> '',
				'parent'	=> 0, 
				'createdon' => time(),
				'createdby' => '0',
				'editedon' 	=> '0',
				'editedby' 	=> '0',
				'published' => '1',
				'deleted' 	=> '0',
				'hidemenu' 	=> '1',
				'template' 	=> '0',
				'content' 	=> ''
			);
      $this->oldTVs=$this->fillOldTVValues();
	}
	
	/***********************************************
	  Saving/Updating document
	************************************************/	
	function Save($clearcache=1){
		global $modx;
		$tablename=$modx->getFullTableName('site_content');
		$fields = $modx->db->escape($this->fields);
		if($this->isNew){
			$this->fields['id']=$modx->db->insert($fields, $tablename);
			$this->isNew = false;
		} else {
			$id=$this->fields['id'];
			$modx->db->update($fields, $tablename, "id='{$id}'");
		}
		if(is_array($this->tvs)) $this->saveTVs();
    if ($clearcache == 1) {
      $modx->clearCache('full');
    }
	}


	/***********************************************
	  Receiving doc values ot TV
	  $field - doc value or TV with 'tv' prefix
	  Result: doc value, TV or null
	************************************************/	
	function Get($field){ 
		switch(1){
			case substr($field,0,2)=='tv': return $this->GetTV(substr($field,2));
			default: return isset($this->fields[$field]) ? $this->fields[$field] : null; 
		}
	}
	
	/***********************************************
	  Setting doc or TV value
	  $field - doc or TV (with prefix 'tv') name
	  $value - value
	  Result: true or false
	************************************************/	
	function Set($field, $value){
		switch(1){
			case substr($field,0,2)=='tv':		return $this->SetTV(substr($field,2), $value);
			case $field=='template':		return $this->SetTemplate($value);
			default: $this->fields[$field]=$value;	return true;
		}
	}
	
	
	/***********************************************
	  Receiving TV 
	  $name - TV name
	************************************************/
	function GetTV($tv){
		if(!is_array($this->tvs)){
			if($this->isNew) return null;
			$this->tvs=array();
		}
		// Look in the values created by Set() function
		if(isset($this->tvs[$tv])) return $this->tvs[$tv];
		// Look in the TVs already defined for the document
		// Call fillOldTVValues() if not yet retrieved
		if(!is_array($this->oldTVs)){
			if($this->isNew) return null;
			$this->oldTVs=$this->fillOldTVValues();
		}
		if(isset($this->oldTVs[$tv])) return $this->oldTVs[$tv];
		return null;
	}
	
	/***********************************************
	  Setting TV value
	************************************************/
	function SetTV($tv,$value){
		if(!is_array($this->tvs)) $this->tvs=array();
		$this->tvs[$tv]=$value;
	}

	/***********************************************
	  Setting doc template
	  $tpl - template name or id
	************************************************/		
	function SetTemplate($tpl){	
		global $modx;
		// Retrieve id of template if name is given
		if(!is_numeric($tpl)) {
			$tpl = $modx->db->getValue($modx->db->select('id', $modx->getFullTableName('site_templates'), "templatename='{$tpl}'", '', 1));
			if(empty($tpl)) return false;
		}
		
		$this->fields['template']=$tpl; 
		return true;
	}

	/************************************************************
	  Deleting doc with TVs
	*************************************************************/
	function Delete(){
		if($this->isNew) return;
		global $modx;
		$id=$this->fields['id'];
		$modx->db->delete($modx->getFullTableName('site_content'),"id='{$id}'");
		$modx->db->delete($modx->getFullTableName('site_tmplvar_contentvalues'),"contentid='{$id}'");
		$this->isNew=true;
	}
	
	/************************************************************
	  Duplicatig doc with TVs
	*************************************************************/
	function Duplicate(){
		if($this->isNew) return;
		$all_tvs=$this->fillOldTVValues();
		foreach($all_tvs as $tv=>$value)
			if(!isset($this->tvs[$tv])) $this->tvs[$tv]=$value;
		$this->oldTVs=array();
		$this->isNew=true;
		unset($this->fields['id']);
	}
	
	/************************************************************
	  Saving TV values, maintenance function. Only $tvNames values are saved, 
        If a TV exists in oldTVs, then updating, else inserting
	*************************************************************/
	function saveTVs(){
		global $modx;
		if(!is_array($this->tvNames))$this->fillTVNames();
		//if(!is_array($this->oldTVs) && !$this->isNew)
    if(!$this->isNew)
			$this->oldTVs=$this->fillOldTVValues();
		else 
			$this->oldTVs = array();
			
		$tvc = $modx->getFullTableName('site_tmplvar_contentvalues');
		foreach($this->tvs as $tv=>$value)
		if(isset($this->tvNames[$tv])){
			$fields = array(
				'tmplvarid' => $this->tvNames[$tv],
				'contentid' => $this->fields['id'],
				'value'     => $value,
				);
			$fields = $modx->db->escape($fields);
			if(isset($this->oldTVs[$tv])){
				if($this->oldTVs[$tv]==$this->tvNames[$tv]) continue;
				$modx->db->update($fields, $tvc, "tmplvarid='{$fields['tmplvarid']}' AND contentid='{$fields['contentid']}'");
			}
			else
				$modx->db->insert($fields, $tvc);
		}
	}
	
	/************************************************************
	  Filling TV array ($oldTVs), maintenance function. 
	  Differs from $modx->getTemplateVars
	*************************************************************/
	function fillOldTVValues(){
		global $modx;
    if (($this->isNew) && (!$this->fields['id'] || $this->fields['id'] == '')) {
      return array();
    }
		$tvc = $modx->getFullTableName('site_tmplvar_contentvalues');
		$tvs = $modx->getFullTableName('site_tmplvars');
		$result = $modx->db->select(
			'tvs.name as name, tvc.value as value',
			$modx->getFullTableName('site_tmplvar_contentvalues')." tvc
				INNER JOIN ".$modx->getFullTableName('site_tmplvars')." tvs  ON tvs.id=tvc.tmplvarid WHERE tvc.contentid =".$this->fields['id'].""
			);
		$TVs = array();
		while ($row = $modx->db->getRow($result)) $TVs[$row['name']] = $row['value'];
		return $TVs;
	}
	
	/************************************************************
	  Fillin TV names array ($tvNames)), maintenance function. 
	*************************************************************/	
	function fillTVNames(){
		global $modx;
		$this->tvNames = array();
		$result = $modx->db->select('id, name', $modx->getFullTableName('site_tmplvars'));
		while ($row = $modx->db->getRow($result)) $this->tvNames[$row['name']] = $row['id'];
	}

  function setAlias ($alias = '') {
    $iso = array("????"=>"a", "????"=>"b", "????"=>"v", "????"=>"g", "????"=>"d", "????"=>"e",
        "?????"=>"jo", "????"=>"zh", "????"=>"z", "????"=>"i", "?????"=>"jj", "????"=>"k", "????"=>"l",
        "????"=>"m", "????"=>"n", "????"=>"o", "????"=>"p", "????"=>"r", "????"=>"s", "?????"=>"t", "????"=>"u",
        "?????"=>"f", "?????"=>"kh", "?????"=>"c", "?????"=>"ch", "?????"=>"sh", "?????"=>"shh", "?????"=>"y",
        "????"=>"eh", "????"=>"yu", "????"=>"ya", "????"=>"a", "?????"=>"b", "?????"=>"v", "?????"=>"g",
        "?????"=>"d", "?????"=>"e", "????"=>"jo", "?????"=>"zh", "?????"=>"z", "????"=>"i", "?????"=>"jj",
        "????"=>"k", "?????"=>"l", "????"=>"m", "????"=>"n", "????"=>"o", "????"=>"p", "?? "=>"r", "????"=>"s",
        "????"=>"t", "????"=>"u", "????"=>"f", "????"=>"kh", "????"=>"c", "????"=>"ch", "????"=>"sh",
        "????"=>"shh", "????"=>"y", "????"=>"eh", "????"=>"yu", "????"=>"ya", " "=>"-", "."=>"-",
        ","=>"-", "_"=>"-", "+"=>"", ":"=>"", ";"=>"", "!"=>"", "?"=>"", "/"=>"", "\\"=>"");
    
    if ($alias == '') {
      if (!isset($this->fields['id']) 
          || $this->fields['id'] == 0 
          || !isset($this->fields['pagetitle']) 
          || $this->fields['pagetitle'] == '') {
        return;
      } else {
        
        $title = mb_convert_encoding($this->fields['pagetitle'], 'cp-1251', 'UTF-8');
        $alias = strtr($title, $iso);
        $alias2 = $this->fields['id'];// .'-'. $alias;
      }
    } else {
      $alias = strtr($alias, $iso);
      
    }
    
    $this->Set('alias', $alias2);
    $this->Save();
    return $alias;
}
  
}
?>