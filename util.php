<?php
/**
 * Utility functions for internal use on the mp3 conversion service 
 */

/**
 * Remove an existing key.
 * @param token to register
 * @param string description for admin's benefit
 */
function _mp3service_deauthorize($authtoken) {
 db_query("DELETE FROM {mp3service_access} WHERE token='%s'", array($authtoken));	
}

/**
 * Register a token as authentic.
 * @param token to register
 * @param string description for admin's benefit
 */
function _mp3service_authorize($authtoken, $desc) {
	if (_mp3service_authenticate($authtoken)) return FALSE; 
	db_query("INSERT INTO {mp3service_access} (id, token, desc) VALUES (0, '%s','%s')", array($authtoken, $desc));	
	return TRUE; 
}

/**
 * Verify that an authentication token is valid.
 * @param string being offered as authentication token
 * @return boolean describing if token is authentic.
 */
function _mp3service_authenticate($token) { 
 return (bool) db_result(db_query("SELECT count(id) FROM {mp3service_access} WHERE token='%s'", array($token))); 
}

/**
 * Duplicate check against job uuid.
 * @param uuid to check if it already exists
 * @return boolean describing if token is authentic.
 */
function _mp3service_exists($uuid, $text='') {
 if ($text) return (bool) db_result(db_query("SELECT count(uuid) FROM {mp3service} WHERE uuid='%s' AND hash='%s'", array($uuid, md5($text))));
  else return (bool) db_result(db_query("SELECT count(uuid) FROM {mp3service} WHERE uuid='%s'", array($uuid)));
}

/**
 * Check a voice to see if the service knows about it
 */
function _mp3service_valid_voice($voice) {
  return in_array($voice, array_keys(_mp3service_voices()));
}

function _mp3service_voices() {
  return array(
    'Crystal16' => t("Crystal"),
    'Mike16' => t("Mike"),
    'Julia16' => t("Julia"),    
  );
}

/**
 * Grab the state of a job in the queue.
 * @param uuid to identify the record we want
 * @return state of that record
 */
function _mp3service_get_state($uuid) {  
  return db_result(db_query("SELECT state FROM {mp3service} WHERE uuid='%s'", array($uuid)));
}

function _mp3service_state_name($stateid) {
	$mp3states = array(
		MP3SERVICE_STATE_CREATED => t('created'),
		MP3SERVICE_STATE_PENDING => t('pending'),
		MP3SERVICE_STATE_ASSIGNED => t('assigned'),
		MP3SERVICE_STATE_COMPLETE => t('complete'),
		MP3SERVICE_STATE_ERROR => t('error'),
		MP3SERVIE_STATE_OVERRIDE => t('override'),
	);

	return $mp3states[$stateid];
}

/**
 * Retrieve the file associated with a record.
 * @param uuid the identifier for the record in question
 * @return path to the requested file.
 */
function _mp3service_get_file($uuid) {
	$dirpath = file_directory_path() . DIRECTORY_SEPARATOR . variable_get('mp3service_filesdir', 'mp3service');
	file_check_directory($dirpath, FILE_CREATE_DIRECTORY);

 // if (!fileexists()) watchdog('debug', 'Failed to create mp3 directory: '+$dirpath);

	$path =  $dirpath . DIRECTORY_SEPARATOR . $uuid . '.mp3';
	return $path;
}

/**
 * Handle error situations gracefully
 * @param desc for watchdog's benefit
 */
function _mp3service_error($desc) {
	db_query("UPDATE {mp3service} SET state=%d, statustime=%d WHERE uuid='%s'",
		array(MP3SERVICE_STATE_ERROR, time(), $uuid));
}

/**
 * Purge no longer needed rows out of the database
 * @param uuids list of ids to purge
 */
function _mp3service_purge($uuids) {

  // TODO un-stub this
  return;

	db_query("DELETE FROM {mp3service} WHERE uuid IN ('%s')",
		array(implode("','", $uuids)));
	watchdog('mp3service', t('Purged @n jobs: @uuids', array('@n' => count($uuids), '@uuids' => array(implode("','", $uuids)))));
	
	foreach ($uuids as $uuid) {
		if (file_exists(mp3service_get_file($uuid))) { 
		  unlink(mp3service_get_file($uuid)); 
		}
	}
}

/**
 * Reassign a set of jobs to another process.
 * @param uuids list of ids to push back to PENDING state
 */
function _mp3service_reassign($uuids, $reason) {
	db_query("UPDATE {mp3service} SET state=%d, statustime=%d WHERE uuid IN ('%s')",
		array(MP3SERVICE_STATE_PENDING, time(), implode("','", $uuids)));
	watchdog('mp3service', t('Reassigned @n @reason jobs: @list', array('@n' => count($uuids), '@reason' => $reason, '@list' => implode(',', $uuids))));
}

/**
 * Return maximum acceptable article length
 */
function _mp3service_max_article_len() {
  return variable_get('mp3service_max_article_len', 80000);
}

/**
 * Find out which jobs a minion has at a given time.
 */
function _mp3service_get_minion_jobs($sessid) {
  $ret = db_query("SELECT uuid FROM {mp3service} WHERE assigned_to='%s'", array($sessid));
  while ($uuid = db_fetch_object($ret)) $uuids[] = $uuid->uuid; 
  return $uuids;
}

function _mp3service_count_minion_jobs($sessid) {
 return db_result(db_query("SELECT count(*) FROM {mp3service} WHERE assigned_to='%s'", array($sessid))); 
}

/**
 *  util function for dumping variables to logs.  Yes I know about var_dump.
 */
if (!function_exists('sprint_r')) {
  function sprint_r($var) {
    ob_start();
    print_r($var);
    $ret = ob_get_contents();
    ob_end_clean();
    return $ret;
  }
}
