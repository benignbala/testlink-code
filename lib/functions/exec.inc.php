<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/ 
 *
 * Filename $RCSfile: exec.inc.php,v $
 *
 * @version $Revision: 1.42 $
 * @modified $Date: 2008/05/18 16:56:09 $ $Author: franciscom $
 *
 * @author Martin Havlat
 *
 * Functions for execution feature (add test results) 
 *
 * 20080504 - franciscom - removed deprecated functions
 *
 * 20080427 - franciscom
 * 20051119  - scs - added fix for 227
 * 20060311 - kl - some modifications to SQL queries dealing with 1.7
 *                 builds table in order to comply with new 1.7 schema
 *
 * 20060528 - franciscom - adding management of bulk update
 * 20060916 - franciscom - added write_execution_bug()
 *                               get_bugs_for_exec()
 *
 * 20070105 - franciscom - interface changes write_execution()
 * 20070222 - franciscom - BUGID 645 createResultsMenu()
 * 20070617 - franciscom - BUGID     insert_id() problems for Postgres and Oracle?
 *
**/
require_once('common.php');


/**
 * Function just grabs number of builds
 *
 * @param numeric test plan ID
 * @return integer Count of Builds
 * 20060311 - kl - adjusted SQL for 1.7 schema
 */  
function buildsNumber(&$db,$tpID=0)
{
	$sql = "SELECT count(*) AS num_builds FROM builds WHERE builds.testplan_id = " . $tpID;
	$buildCount=0;
	if ($tpID)
	{
		$result = $db->exec_query($sql);
		if ($result)
		{
			$myrow = $db->fetch_array($result);
			$buildCount = $myrow['num_builds'];
		}
	}
	return $buildCount;
}


/** Building the dropdown box of results filter */
// 20070222 - franciscom - BUGID 645 
function createResultsMenu()
{
  $resultsCfg=config_get('results');
  $map_verbose_status_code=$resultsCfg['status_code'];
  $tc_status_verbose_labels = $resultsCfg['status_label'];
  $tc_status_for_ui = $resultsCfg['status_label_for_exec_ui'];
  
  // Fixed values, that has to be added always
  $my_all= isset($tc_status_verbose_labels['all'])?$tc_status_verbose_labels['all']:'';
  $menu_data[$map_verbose_status_code['all']] = $my_all;
	$menu_data[$map_verbose_status_code['not_run']] = lang_get($tc_status_verbose_labels['not_run']);
	
	// loop over tc_status_for_ui, because these are the statuses
	// user can assign while executing test cases
	//
	foreach($tc_status_for_ui as $verbose_status => $status_label)
	{
	   $code=$map_verbose_status_code[$verbose_status];
	   $menu_data[$code]=lang_get($status_label); 
  }

	return $menu_data;
}//end results function
	
	
/*
  function: write_execution

  args :
  
  returns: 

  rev :
       20070105 - franciscom - added $tproject_id
*/
function write_execution(&$db,$user_id, $exec_data,$tproject_id,$tplan_id,$build_id,$map_last_exec)
{
	$map_tc_status = config_get('tc_status');
	$bugInterfaceOn = config_get('bugInterfaceOn');
	$db_now = $db->db_now();
	$cfield_mgr=New cfield_mgr($db);
  $cf_prefix=$cfield_mgr->get_name_prefix();
	$len_cfp=strlen($cf_prefix);
  $cf_nodeid_pos=4;
	
	// --------------------------------------------------------------------------------------
	$ENABLED=1;
  $cf_map= $cfield_mgr->get_linked_cfields_at_execution($tproject_id,$ENABLED,'testcase');
  $has_custom_fields=is_null($cf_map) ? 0 : 1;
  // --------------------------------------------------------------------------------------

	// --------------------------------------------------------------
	// extract custom fields id.
	$map_nodeid_array_cfnames=null;
  foreach($exec_data as $input_name => $value)
  {
      if( strncmp($input_name,$cf_prefix,$len_cfp) == 0 )
      {
        $dummy=explode('_',$input_name);
        $map_nodeid_array_cfnames[$dummy[$cf_nodeid_pos]][]=$input_name;
      } 
  }
  // --------------------------------------------------------------
	
	// is a bulk save ???
  if( isset($exec_data['do_bulk_save']) )
  {
      // create structure to use common algoritm
      $item2loop= $exec_data['status'];
  }	
	else
	{
	    $item2loop= $exec_data['save_results'];
	}

	foreach ( $item2loop as $tcversion_id => $val)
	{
	  $tcase_id=$exec_data['tc_version'][$tcversion_id];
		$current_status = $exec_data['status'][$tcversion_id];
		$has_been_executed = ($current_status != $map_tc_status['not_run'] ? TRUE : FALSE);
		if($has_been_executed)
		{ 
			$my_notes = $db->prepare_string(trim($exec_data['notes'][$tcversion_id]));		
			$sql = "INSERT INTO executions ".
				     "(build_id,tester_id,status,testplan_id,tcversion_id,execution_ts,notes)".
				     " VALUES ( {$build_id}, {$user_id}, '{$exec_data['status'][$tcversion_id]}',".
				     "{$tplan_id}, {$tcversion_id},{$db_now},'{$my_notes}')";
			$db->exec_query($sql);  	
			
			// 20070617 - franciscom - BUGID : at least for Postgres DBMS table name is needed. 
			//    
			$execution_id=$db->insert_id('executions');
			
      if( $has_custom_fields )
      {
        // test useful when doing bulk update, because some type of custom fields
        // like checkbox can not exist on exec_data
        //
        $hash_cf=null;
        if( isset($map_nodeid_array_cfnames[$tcase_id]) )
        { 
          foreach($map_nodeid_array_cfnames[$tcase_id] as $cf_v)
          {
             $hash_cf[$cf_v]=$exec_data[$cf_v];
          }  
			  }                                     
		    // 20070105 - custom field management
		    $cfield_mgr->execution_values_to_db($hash_cf,$tcversion_id, $execution_id, $tplan_id,$cf_map);
			}                                     
		}
	}
}

/*
  function: write_execution_bug

  args :
  
  returns: 

*/
function write_execution_bug(&$db,$exec_id, $bug_id,$just_delete=false)
{
	// Instead of Check if record exists before inserting, do delete + insert
	$prep_bug_id = $db->prepare_string($bug_id);
	
	$sql = "DELETE FROM execution_bugs " .
	       "WHERE execution_id={$exec_id} " .
	       "AND bug_id='" . $prep_bug_id ."'";
	$result = $db->exec_query($sql);
	
	if(!$just_delete)
	{
    	$sql = "INSERT INTO execution_bugs " .
    	      "(execution_id,bug_id) " .
    	      "VALUES({$exec_id},'" . $prep_bug_id . "')";
    	$result = $db->exec_query($sql);  	     
	}
	return $result ? 1 : 0;
}

// 20060916 - franciscom
function get_bugs_for_exec(&$db,&$bug_interface,$execution_id)
{
  $bug_list=array();
	$sql = "SELECT execution_id,bug_id,builds.name AS build_name " .
	       "FROM execution_bugs,executions,builds ".
	       "WHERE execution_id={$execution_id} " .
	       "AND   execution_id=executions.id " .
	       "AND   executions.build_id=builds.id " .
	       "ORDER BY builds.name,bug_id";
	$map = $db->get_recordset($sql);
	if( !is_null($map) )
  {  	
    	foreach($map as $elem)
    	{
    		$bug_list[$elem['bug_id']]['link_to_bts'] = $bug_interface->buildViewBugLink($elem['bug_id'],GET_BUG_SUMMARY);
    		$bug_list[$elem['bug_id']]['build_name'] = $elem['build_name'];
    	}
  }
  return($bug_list);
}


// 20060916 - franciscom
function get_execution(&$db,$execution_id)
{
	$sql = "SELECT * " .
	       "FROM executions ".
	       "WHERE id={$execution_id} ";
	       
	$map = $db->get_recordset($sql);
  return($map);
}




/*
  function: delete_execution

  args :
  
  returns: 

  rev :
       
*/
function delete_execution(&$db,$exec_id)
{
  $sql=array();
  
  // delete bugs
  $sql[]="DELETE FROM execution_bugs WHERE execution_id = {$exec_id}";

 
  // delete custom field values
  $sql[]="DELETE FROM cfield_execution_values WHERE execution_id = {$exec_id}";
 
  // delete execution 
  $sql[]="DELETE FROM executions WHERE id = {$exec_id}";

  foreach ($sql as $the_stm)
  {
  		$result = $db->exec_query($the_stm);
  }

}
?>
