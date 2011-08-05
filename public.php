<?php

/*******************************************************************************
 * XMLRPC METHODS
*******************************************************************************/

/**
 * Allows website to submit text for conversion. Exposed as XMLRPC.
 * @param token being offered as authentication
 * @param uuid identifying the job being submitted
 * @param text string representing the content to be converted
 */
function mp3service_xmlrpc_submitnode($authtoken, $uuid, $text, $voice = 'Crystal16') {

	if (!_mp3service_authenticate($authtoken)) {
		watchdog('mp3service', 
			t('Submit with invalid auth token (@token) rejected', array('@token' => $authtoken)));
		return xmlrpc_error(MP3SERVICE_ERROR_ACCESS, t('Access denied: your authentication token is unknown.'));
	}

  
	if (_mp3service_exists($uuid, $text)) {
		watchdog('mp3service', 
			t('Submit with duplicate uuid (@uuid) rejected', array('@uuid' => $uuid)));
		return xmlrpc_error(MP3SERVICE_ERROR_DUPE, t('Node already submitted.'));
	}
	
	if (!_mp3service_valid_voice($voice)) {
    $oldvoice = $voice;
    $voice = variable_get('mp3service_default_voice', 'Crystal16');
    watchdog('mp3service', t("Requested voice @oldvoice unknown, using default @voice instead"), array('@oldvoice' => $oldvoice, '@voice' => $voice));
	} 
	
	if (strlen($text) > _mp3service_max_article_len()) {
		watchdog('mp3service', 
			t('Article @uuid is too long (@len): rejected', array('@uuid' => $uuid, '@len' => strlen($text))));
		return xmlrpc_error(MP3SERVICE_ERROR_LENGTH, t('Submitted text length too long, must be less than @len characters.', array('@len' => _mp3service_max_article_len())));	
	}
	
  // delete existing uuid record in case an old one exists as this is a new request
  db_query("DELETE FROM {mp3service} WHERE uuid='%s'", $uuid);
  db_query("INSERT INTO {mp3service} (uuid, text, statustime, state, voice) VALUES ('%s', '%s', %d, %d, '%s')", 
		array($uuid, $text, time(), MP3SERVICE_STATE_PENDING, $voice));

	watchdog('debug', t('Job @uuid is now in the PENDING state', array('@uuid' => $uuid))); 
	return array(
		'state' => MP3SERVICE_STATE_PENDING, 
		'desc' => t('Job @uuid is now in the PENDING state', array('@uuid' => $uuid))
	);
}


/**
 * Each time the podcast file is rebuild, its URL is submitted for promotion. 
 * This function is exposed as an XMLRPC method.
 * @param authtoken being offered as authentication
 * @param podcast_url the URL to promote
 * @return job state and if complete, the contents of the converted file
 */
function mp3service_xmlrpc_submitpodcast($authtoken, $podcast_url) {
	if (!_mp3service_authenticate($authtoken)) {
		watchdog('mp3service', 
			t('Submit with invalid auth token (@token) rejected', array('@token' => $authtoken)));
		return xmlrpc_error(MP3SERVICE_ERROR_ACCESS, t('Access denied: your authentication token is unknown.'));
	}


 // let's implement this later

 // save to task list
 // provide an initial task list page with a list of promotion tasks and ability to mark
 //  a url ad promoted

 // after promoting a url, an email notification should go to the account owner
 // telling them that we have promoted their podcasts

} 


/**
 * Website uses this to poll for conversion completion.  
 * This function is exposed as an XMLRPC method.
 * @param token being offered as authentication
 * @param uuid identifying the job being submitted
 * @return job state and if complete, the contents of the converted file
 */
function mp3service_xmlrpc_checknode($authtoken, $uuid) {

	if (!_mp3service_authenticate($authtoken)) {
		watchdog('mp3service', 
			t('Check with invalid auth token (@token) rejected', array('@token' => $authtoken)));
		return xmlrpc_error(MP3SERVICE_ERROR_ACCESS, t('Access denied: your authentication token is unknown.'));
	}
	
	if (!_mp3service_exists($uuid)) {
		return xmlrpc_error(MP3SERVICE_ERROR_DNE, t('Requested UUID does not exist.'));
	}
	
	$state = _mp3service_get_state($uuid);
	if ($state != MP3SERVICE_STATE_COMPLETE) {
  	watchdog('debug', 'check not complete returning state ' . $state);
		return array('state' => $state);
	}	
	
	$file = _mp3service_get_file($uuid);
	$mp3data = file_get_contents($file);
	
	watchdog('debug', 'check returning state ' . $state . ' and file ' . $file);

	return array('state' => $state, 'file' => base64_encode($mp3data));
}

/**
 * Conversion processes use this to request new work.  Exposed as XMLRPC.
 * @param token being offered as authentication
 * @param sessid to identify this connection
 */
function mp3service_xmlrpc_requestjob($authtoken, $sessid) {  

	if (!_mp3service_authenticate($authtoken)) {
		watchdog('mp3service', t('Requestjob with invalid auth token (@token) rejected', array('@token' => $authtoken)));
		return xmlrpc_error(MP3SERVICE_ERROR_ACCESS, t('Access denied: your authentication token is unknown.'));
	}

  variable_set('mp3service_last_minion_call', time());

  // reset state of minion jobs to pending if not completed within 10 minutes
  db_query('UPDATE {mp3service} SET state=%d, statustime=%d WHERE state=%d AND statustime<%d',
   array(MP3SERVICE_STATE_PENDING, time(), MP3SERVICE_STATE_ASSIGNED, strtotime('10 minutes ago')));
		
  /*
  // Right now this breaks badly
if (_mp3service_count_minion_jobs($sessid) > 0) {
    watchdog('mp3service', t('Minon @m requested another job, but is already busy with @jobs', array('@m' => $sessid, '@jobs' => implode(', ', _mp3service_get_minion_jobs($sessid)))));
	  return xmlrpc_error(MP3SERVICE_ERROR_CONFLICT, t('This minion is already working on a job and cannot request another'));	
	} */

  // pick the oldest from the list 	
  $ret = db_query('SELECT * FROM {mp3service} WHERE state=%d ORDER BY statustime ASC LIMIT 1', array(MP3SERVICE_STATE_PENDING)); 
  if ($row = db_fetch_object($ret)) { 
		db_query("UPDATE {mp3service} SET state=%d, statustime=%d, assigned_to='%s' WHERE uuid='%s'",
			 array(MP3SERVICE_STATE_ASSIGNED, time(), $sessid, $row->uuid));	
		return array('jobuuid' => $row->uuid, 'jobtext' => $row->text, 'voice' => $row->voice);
	}	
	else return array('jobuuid' => 0, 'jobtext' => t('Job queue is empty')); 
}

 

/**
 * Conversion processes use this to return conversion results.  This function is
 * exposed as an XMLRPC method.
 * @param token being offered as authentication
 * @param uuid identifying the job being submitted
 * @param sessid identifying the converter doing the job
 * @param mp3data mp3file in base64 encoding
 * @return boolean representing upload success or failure
 */
function mp3service_xmlrpc_submitnodejob($authtoken, $uuid, $sessid, $mp3data) { 

	if (!_mp3service_authenticate($authtoken)) {
		watchdog('mp3service', t('Requestjob with invalid auth token (@token) rejected', array('@token' => $authtoken)));
		return xmlrpc_error(MP3SERVICE_ERROR_ACCESS, t('Access denied: your authentication token is unknown.'));
	}
	
	$ret = db_query("SELECT state FROM {mp3service} WHERE uuid='%s' AND assigned_to='%s' AND state=%d",
		array($uuid, $sessid, MP3SERVICE_STATE_ASSIGNED));	
	if (db_affected_rows() > 0) {
		$mp3file = _mp3service_get_file($uuid);
    $ret = file_put_contents($mp3file, base64_decode($mp3data));
    watchdog('debug', 'wrote ' . $ret . ' bytes to output file');

		if (!$ret) {
			_mp3service_error(t("Unable to write mp3 output file", array('@sessid' => $sessid, '@uuid' => $uuid)));
			return FALSE;
		}
	
		// success
    db_query("UPDATE {mp3service} SET state=%d, statustime=%d WHERE uuid='%s'",
				array(MP3SERVICE_STATE_COMPLETE, time(), $uuid));
				
		return TRUE;
	}	

	watchdog('mp3service', 
		t("Converter with session id (@sessid) attempted to submit non-assigned or completed job (@uuid)", array('@sessid' => $sessid, '@uuid' => $uuid)));
			
	return false;
}

/*******************************************************************************
 * END XMLRPC METHODS
*******************************************************************************/


/** Fake Methods for testing the client against **/

function mp3service_fakerpc_submit($authtoken, $uuid, $text, $voice = 'Crystal16') {
  watchdog('debug', "fake submit args: $authtoken, $uuid, $text, $voice");

	return array(
		'state' => MP3SERVICE_STATE_PENDING, 
		'desc' => t('Job @uuid is now in the PENDING state', array('@uuid' => $uuid))
	);  
}

function mp3service_fakerpc_check($authtoken, $uuid) {
  watchdog('debug', "fake check args: $authtoken, $uuid");
  return array('state' => 2, 'file' => base64_encode('Yoursphere Media'));
}

function mp3service_fakerpc_requestJob($authtoken, $sessid) {
  return array('jobuuid' => 'fakefake', 'jobtext' => 'Yoursphere Media', 'voice' => 'Crystal16');
}

function mp3service_fakerpc_submitJob($authtoken, $uuid, $sessid, $mp3data) {
  return true;
}
