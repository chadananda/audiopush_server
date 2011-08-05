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
function mp3service_xmlrpc_submit($authtoken, $uuid, $text, $voice = 'Crystal16') {

	if (!_mp3service_authenticate($authtoken)) {
		watchdog('mp3service', 
			t('Submit with invalid auth token (@token) rejected', array('@token' => $authtoken)));
		return xmlrpc_error(MP3SERVICE_ERROR_ACCESS, t('Access denied: your authentication token is unknown.'));
	}

	if (_mp3service_exists($uuid)) {
		watchdog('mp3service', 
			t('Submit with duplicate uuid (@uuid) rejected', array('@uuid' => $uuid)));
		return xmlrpc_error(MP3SERVICE_ERROR_DUPE, t('Submitted UUID already exists.'));
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
	
	$ret = db_query("INSERT INTO {mp3service} (uuid,text,statustime,state,voice) VALUES ('%s','%s',NOW(),%d, '%s')", 
		array($uuid, $text, MP3SERVICE_STATE_PENDING, $voice));

	watchdog('debug', t('Job @uuid is now in the PENDING state', array('@uuid' => $uuid)));

	return array(
		'state' => MP3SERVICE_STATE_PENDING, 
		'desc' => t('Job @uuid is now in the PENDING state', array('@uuid' => $uuid))
	);
}

/**
 * Website uses this to poll for conversion completion.  
 * This function is exposed as an XMLRPC method.
 * @param token being offered as authentication
 * @param uuid identifying the job being submitted
 * @return job state and if complete, the contents of the converted file
 */
function mp3service_xmlrpc_check($authtoken, $uuid) {

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
		

  // Right now this breaks badly
/*	if (_mp3service_count_minion_jobs($sessid) > 0) {
    watchdog('mp3service', t('Minon @m requested another job, but is already busy with @jobs', array('@m' => $sessid, '@jobs' => implode(', ', _mp3service_get_minion_jobs($sessid)))));
	  return xmlrpc_error(MP3SERVICE_ERROR_CONFLICT, t('This minion is already working on a job and cannot request another'));	
	}
*/
	$ret = db_query("SELECT uuid,text,voice FROM {mp3service} WHERE state=%d LIMIT 1", array(MP3SERVICE_STATE_PENDING));		
	if (db_affected_rows() > 0) {
		$job = db_fetch_object($ret);
		$ret = db_query(
			"UPDATE {mp3service} SET state=%d,statustime=NOW(),assigned_to='%s' WHERE uuid='%s'",
				array(MP3SERVICE_STATE_ASSIGNED, $sessid, $job->uuid));	
		return array('jobuuid' => $job->uuid, 'jobtext' => $job->text, 'voice' => $job->voice);O2DO2C

	}	
	else {
		// queue is empty right now
		return array('jobuuid' => 0, 'jobtext' => t('Job queue is empty'));
	}
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
function mp3service_xmlrpc_submitjob($authtoken, $uuid, $sessid, $mp3data) {

/* watchdog('debug', "called with $authtoken, $uuid, $sessid, $mp3data"); */

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
			_mp3service_error(t("Unable to write mp3 output file", 
				array('@sessid' => $sessid, '@uuid' => $uuid)));
			return false;
		}
	
		// success
		$ret = db_query(
			"UPDATE {mp3service} SET state=%d,statustime=NOW() WHERE uuid='%s'",
				array(MP3SERVICE_STATE_COMPLETE, $uuid));
				
		return true;
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
