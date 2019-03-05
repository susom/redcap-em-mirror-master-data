<?php
namespace Stanford\MirrorMasterDataModule;

include "../../redcap_connect.php";

class MirrorMasterDataModule extends \ExternalModules\AbstractExternalModule
{


    private $config_fields = array();

    private $project_id;
    private $record_id;
    private $instrument;
    private $event_id;
    private $redcap_event_name;  //only set if longitudinal


    /**
     * Rearrange the config settings to be by child project
     * and then the config properties -> value
     *
     * @return array
     */
    function setupConfig()
    {
        //get the config defintion
        $config = $this->getConfig();

        //Apparently, there is now a getSubSetting method that just gets each subsetting for us
        $config_fields = $this->getSubSettings('child-projects');
        //$this->emLog($config_fields, "==========this is the SUBSETTINGS FROM method");

        return $config_fields;

    }

    /**
     * Migrate data for child project specified in $config parameter
     * @param $config
     * @return bool
     */
    function handleChildProject($config)
    {
        //get record_id
        $pk_field = \REDCap::getRecordIdField();
        $record_id = $this->record_id;
        $page = $this->instrument;
        $event_id = $this->event_id;
        $event_name = $this->redcap_event_name;

        //0. CHECK if in right EVENT
        $trigger_event = $config['master-event-name'];
        //$this->emLog("Trigger event is $trigger_event EVENT ID is  $event_id and EVNET NAME is $event_name");
        if ((!empty($trigger_event)) && ($trigger_event != $event_id)) {
            return false;
        }

        //0. CHECK if in the right instrument
        $trigger_form = $config['trigger-form'];
        if ((!empty($trigger_form)) && ($trigger_form != $page)) {
            return false;
        }
        //$this->emLog("Found trigger form ". $trigger_form);

        //1. CHECK if migration has already been completed
        $parent_fields[] = $config['parent-field-for-child-id'];
        $parent_fields[] = $config['migration-timestamp'];
        $child_field_clobber = $config['child-field-clobber'];

        $results = \REDCap::getData('json', $record_id, $parent_fields);
        $results = json_decode($results, true);
        $newData = current($results);

        //check if timestamp and child_id are set (both? just one?)
        //if exists, then don't do anything since already migrated
        $migration_timestamp = $newData[$config['migration-timestamp']];
        $parent_field_for_child_id = $newData[$config['parent-field-for-child-id']];

        //get  child pid and get data
        $child_pid = $config['child-project-id'];

        //migration_timestamp already has a value and Child_field_clobber is not set
        if (!empty($migration_timestamp && ($child_field_clobber!='1')) ) { //|| (isset($migration_timestamp))) {
            //xTODO: add to logging?
            $existing_msg = "No data migration: Clobber not turned on and migration already completed for record "
                . $record_id . " to child project " . $child_pid;
            $this->emLog($existing_msg);

            //reset the migration timestamp and child_id
            $log_data = array();
            $log_data[$config['parent-field-for-child-id']] = '';
            $log_data[$config['migration-timestamp']] = date('Y-m-d H:i:s');

            //UPDATE PARENT LOG
            $this->updateNoteInParent($record_id, $pk_field, $config, $existing_msg, $log_data);

            return false;
        }
        $this->emLog("Doing data migration: Clobber: ".$child_field_clobber. " and ".
            " Migration timestamp:  ".$migration_timestamp . "  record: "
            . $record_id . " to child project " . $child_pid);

        //2. CHECK IF LOGIC IS TRIGGERED
        $mirror_logic = $config['mirror-logic'];
        // validation
        if (! $this->testLogic($mirror_logic, $record_id)) {
            $this->emLog($mirror_logic, "DEBUG", "LOGIC validated to false so skipping");
            return false;
        }

        //3. INTERSECT FIELDS:  Get fields present in screening project that are also present in the main project
        //3A. Restrict fields to specified forms?
        //todo: UI for config should have dropdown for child forms (only available for current project (parent))

        $arr_fields = $this->getIntersectFields($child_pid, $this->project_id, $config['fields-to-migrate'],  $config['include-only-form-child'],
            $config['include-only-form-parent'], $config['exclude-fields'],$config['include-only-fields']);
        //$this->emDebug($arr_fields, "INTERSECTION is of count ".count($arr_fields));

        if (count($arr_fields)<1) {
            //Log msg in Notes field
            $msg = "There were no intersect fields between the parent (" . $this->project_id .
                ") and child projects (" . $child_pid . ").";
            //$this->emLog($msg);
            //reset the migration timestamp and child_id
            $log_data = array();
            $log_data[$config['parent-field-for-child-id']] = '';
            $log_data[$config['migration-timestamp']] = date('Y-m-d H:i:s');
            $this->updateNoteInParent($record_id, $pk_field, $config, $msg, $log_data);
            return false;
        }

        //4. RETRIEVE INTERSECT DATA FROM PARENT

        //modules method doesn't seem to have option for select list?
        //$data = $this->getData($this->project_id, $record_id);
        $results = \REDCap::getData('json', $record_id, $arr_fields, $config['master-event-name']);
        $results = json_decode($results, true);
        $newData = current($results);

        //$this->emDebug($newData, "INTERSECT DATA");

        //get primary key for TARGET project
        $child_pk = self::getPrimaryKey($child_pid);

        //5.5 Determine the ID in the CHILD project.
        $child_id = $this->getNextIDInTarget($record_id, $config, $child_pid);

        //5.6 check if this child id already exists?  IF yes, check clobber. maybe not necesssary, since overwrite will handle it.
        // actually go ahead and do it to handle cases where they only want one initial creation.
        //todo: this event name is only for TARGET form.  not for logging variable
        $child_event_name = $config['child-event-name'];

        $results = \REDCap::getData($child_pid, 'json', $child_id, null, $config['child-event-name']);
        $results = json_decode($results, true);
        $target_results = current($results);

        //$this->emDebug(empty($target_results),$target_results, "TARGET DATA for child id $child_id in pid $child_pid with event $child_event_name");

        if ( (!empty($target_results))  && ($child_field_clobber!='1'))  {
            //Log no migration
            $msg = "Error creating record in TARGET project. ";
            $msg .= "Target ID, $child_id, already exists (count = ". count($target_results). ")  in $child_pid and clobber is set to false ($child_field_clobber).";
            $this->emDebug($msg);
        } else {
            //$this->emDebug("PROCEED: Target $child_id does not exists (" . count($target_results) . ") in $child_pid or clobber true ($child_field_clobber).");

            //5. SET UP CHILD PROJECT TO SAVE DATA
            //TODO: logging variable needs to be done separately because of events
            //GET logging variables from target project
            $child_field_for_parent_id = $config['child-field-for-parent-id'];


            //add additional fields to be added to the SaveData call
            //set the new ID
            $newData[$child_pk] = $child_id;
            if (!empty($child_event_name)) {
                $newData['redcap_event_name'] = ($child_event_name);
            }

            //enter logging field for child-field-for-parent-id
            if (!empty($child_field_for_parent_id)) {
                $newData[$child_field_for_parent_id] = $this->record_id;
            }

            //$this->emLog($newData, "SAVING THIS TO CHILD DATA");

            //6. UPDATE CHILD: Upload the data to child project
            $result = \REDCap::saveData(
                $child_pid,
                'json',
                json_encode(array($newData)),
                (($child_field_clobber == '1') ? 'overwrite' : 'normal'));

            // Check for upload errors
            $parent_data = array();

            if (!empty($result['errors'])) {
                $msg = "Error creating record in CHILD project " . $child_pid . " - ask administrator to review logs: " . print_r($result['errors'], true);

                $this->emError($msg);
                $this->emError($result, "DEBUG", "CHILD ERROR");
            } else {
                $msg = "Successfully migrated.";
                if (isset($config['parent-field-for-child-id'])) {
                    $parent_data[$config['parent-field-for-child-id']] = $child_id;
                }
                if (isset($config['migration-timestamp'])) {
                    $parent_data[$config['migration-timestamp']] = date('Y-m-d H:i:s');
                }
            }
        }

        //$this->emLog($parent_data, "DEBUG", "PASSING IN THIS DATA");
        //7. UPDATE PARENT
        $this->updateNoteInParent($record_id, $pk_field, $config, $msg, $parent_data);

        return true;
    }

    function getNextIDInTarget($record_id, $config, $child_pid) {
        //$this->emDebug($config);

        $child_id = null;
        $child_id_select = $config['child-id-select'];
        //get primary key for TARGET project
        $child_pk = self::getPrimaryKey($child_pid);

        switch ($child_id_select) {
            case 'child-id-like-parent':
                $child_id = $this->record_id;
                //$this->emDebug($child_id,  "CHILD ID: USING PARENT ID: ".$child_id);
                break;
            case 'child-id-parent-specified':
                $child_id_parent_specified_field = $config['child-id-parent-specified-field'];

                //get data from parent for the value in this field
                $results = \REDCap::getData('json', $record_id, $child_id_parent_specified_field, $config['master-event-name']);
                $results = json_decode($results, true);
                $existing_target_data = current($results);

                $child_id = $existing_target_data[$child_id_parent_specified_field];
                $this->emDebug($existing_target_data,$child_id_parent_specified_field, $child_id,  "PARENT SPECIFIED CHILD ID: ".$child_id);
                break;
            case 'child-id-create-new':
                $child_id_prefix = $config['child-id-prefix'];
                $child_id_padding = $config['child-id-padding'];

                $event_id = self::getEventIdFromName($child_pid, $config['child-event-name']);
                //get next id from child project
                $next_id = $this->getNextID($child_pid,$event_id, $child_id_prefix, $child_id_padding);
                $child_id = $next_id;
                break;
            default:
                $event_id = self::getEventIdFromName($child_pid, $config['child-event-name']);
                $next_id = $this->getNextID($child_pid, $event_id);
                $child_id = $next_id;
        }
        return $child_id;
    }

    function getIntersectFields($child_pid, $parent_pid, $fields_to_migrate, $include_from_child_form, $include_from_parent_form,
                                $exclude, $include_only) {

        //branching logic reset does not clear out old values - force clear here
        switch ($fields_to_migrate) {
            case 'migrate-intersect':
                $include_from_child_form = null;
                $include_from_parent_form = null;
                $include_only = null;
                break;
            case 'migrate-intersect-specified':
                $include_from_child_form = null;
                $include_from_parent_form = null;
                break;
            case 'migrate-child-form':
                $include_from_parent_form = null;
                $include_only = null;
                break;
            case 'migrate-parent-form':
                $include_from_child_form = null;
                $include_only = null;
                break;
        }
        // redcap: prep is deprecated and now use db_escape (which calls db_real_escape_string)
        //if either child or parent comes up empty, return and log to user cause of error
        if (empty(intval($child_pid)) || empty($parent_pid)) {
            $this->emError("Either child and parent pids was missing.". intval($child_pid) . " and " . $parent_pid);
            return false;
        }

        //todo: ADD support for multiple child forms and multiple parent forms
        $sql_child_form = ((empty(db_escape($include_from_child_form))) ? "" : " and a.form_name = '".db_escape($include_from_child_form)."'");
        $sql_parent_form = ((empty(db_escape($include_from_parent_form))) ? "" : " and b.form_name = '".db_escape($include_from_parent_form)."'");

        $sql = "select field_name from redcap_metadata a where a.project_id = " . intval($child_pid) . $sql_child_form .
            " and field_name in (select b.field_name from redcap_metadata b where b.project_id = " . $parent_pid .$sql_parent_form .  ");";
        $q = db_query($sql);
        //$this->emDebug($sql);

        $arr_fields = array();
        while ($row = db_fetch_assoc($q)) $arr_fields[] = $row['field_name'];

        //if (!empty($exclude)) {
        if (count($exclude) >1) {
            $arr_fields = array_diff($arr_fields, $exclude);
            //$this->emDebug($arr_fields, 'EXCLUDED arr_fields:');
        } else if (count($include_only) > 1) {
            //giving precedence to exclude / include if both are entered
            $arr_fields = array_intersect($arr_fields, $include_only);
            //$this->emDebug($include_only, $arr_fields, 'INCLUDED FIELDS arr_fields:');
        }

        return $arr_fields;

    }

    /**
     * Bubble up status to user via the timestamp and notes field in the parent form
     * in config file as 'migration-notes'
     * @param $record_id : record_id of current record
     * @param $pk_field : the first field in parent project (primary key)
     * @param $config : config fields for migration module
     * @param $msg : Message to enter into Notes field
     * @param $parent_data : If child migration successful, data about migration to child (else leave as null)
     * @return bool : return fail/pass status of save data
     */
    function updateNoteInParent($record_id, $pk_field, $config, $msg, $parent_data = array()) {
        //$this->emLog($parent_data, "DEBUG", "RECEIVED THIS DATA");
        $parent_data[$pk_field] = $record_id;
        if (isset($config['migration-notes'])) {
            $parent_data[$config['migration-notes']] = $msg;
        }

        if (!empty($config['master-event-name'])) {
            //assuming that current event is the right event
            $master_event = $this->redcap_event_name; // \REDCap::getEventNames(true, false, $config['master-event-name']);
            //$this->emLog("Event name from REDCap::getEventNames : $master_event / EVENT name from this->redcap_event_name: ".$this->redcap_event_name);

            $parent_data['redcap_event_name'] = $master_event; //$config['master-event-name'];
        }

        //$this->emLog($parent_data, "Saving Parent Data");
        $result = \REDCap::saveData(
            $this->project_id,
            'json',
            json_encode(array($parent_data)),
            'overwrite');

        // Check for upload errors
        if (!empty($result['errors'])) {
            $msg = "Error creating record in PARENT project ".$this->project_id. " - ask administrator to review logs: " . json_encode($result);
            //$sr->updateFinalReviewNotes($msg);
            //todo: bubble up to user : should this be sent to logging?
            $this->emError($msg);
            $this->emError("RESULT OF PARENT: " .print_r($result, true));
            //logEvent($description, $changes_made="", $sql="", $record=null, $event_id=null, $project_id=null);
            \REDCap::logEvent("Mirror Master Data Module", $msg, NULL, $record_id, $config['master-event-name']);
            return false;
        }

    }
    /**
     * @param $logic
     * @param $record
     * @return bool|string
     */
    function testLogic($logic, $record) {

//      $this->emLog('Testing record '. $record . ' with ' . $logic, "DEBUG");
        //if blank logic, then return true;
        if (empty($logic)) {
            return true;
        }

//        $this->emLog('EVENT FOR  '. $record . ' is ' . $this->redcap_event_name, "DEBUG");
        if (\LogicTester::isValid($logic)) {

            // Append current event details
            if (\REDCap::isLongitudinal() && $this->redcap_event_name) {
                $logic = \LogicTester::logicPrependEventName($logic, $this->redcap_event_name);
                $this->emLog(__FUNCTION__ . ": logic updated with selected event as " . $logic, "INFO");
            }

            if (\LogicTester::evaluateLogicSingleRecord($logic, $record)) {
                $result = true;
            } else {
                $result = false;
                return false;
            }
        } else {
            $result = "Invalid Syntax";
            $this->emError($result, "DEBUG", "LOGIC : INVALID SYNTAX");
        }
        return $result;
    }

    static function getPrimaryKey($target_project_pid) {
        //need to find pk in target_project_pid
        $target_dict = \REDCap::getDataDictionary($target_project_pid,'array');

        reset($target_dict);
        $pk = key($target_dict);

        return $pk;

    }

    /**
     * @param $pid
     * @param $event_id  Pass NULL or '' if CLASSICAL
     * @param string $prefix
     * @param bool $padding
     * @return bool|int|string
     */
    public function getNextId($pid, $event_id, $prefix = '', $padding = false) {

        $thisProj = new \Project($pid);
        $id_field = $thisProj->table_pk;

        //If Classical no event or null is passed
        if (($event_id == '') OR ($event_id ==null)) {
            $event_id = $this->getFirstEventId($pid);
        }

        $this->emLog("PK for $pid is $id_field looking for event: ".$event_id . " in pid: " .$pid.
            " with prefix: $prefix and padding: $padding");

        $q = \REDCap::getData($pid,'array',NULL,array($id_field), $event_id);
        //$this->emLog($q, "Found records in project $pid using $id_field");

        $i = 1;
        do {
            // Make a padded number
            if ($padding) {
                // make sure we haven't exceeded padding, pad of 2 means
                //$max = 10^$padding;
                $max = 10**$padding;
                if ($i >= $max) {
                    $this->emLog("Error - $i exceeds max of $max permitted by padding of $padding characters");
                    return false;
                }
                $id = str_pad($i, $padding, "0", STR_PAD_LEFT);
                //$this->emLog("Padded to $padding for $i is $id");
            } else {
                $id = $i;
            }

            // Add the prefix
            $id = $prefix . $id;
            //$this->emLog("Prefixed id for $i is $id for event_id $event_id and idfield $id_field");

            $i++;
        } while (!empty($q[$id][$event_id][$id_field]));

        $this->emLog("Next ID in project $pid for field $id_field is $id");
        return $id;
    }

    /**
     * Returns the event id given a project id and event name
     * This was needed because the existing plugin function only operates in the context of the current project.
     * @param $project_id
     * @param $event_name
     * @return int|null     Returns the event_id or null if not found
     */
    public static function getEventIdFromName($project_id, $event_name) {
        if (empty($event_name)) return NULL;
        $thisProj = new \Project($project_id,false);
        $thisProj->loadEventsForms();
        $event_id_names = $thisProj->getUniqueEventNames();
        $event_names_id = array_flip($event_id_names);
        return isset($event_names_id[$event_name]) ? $event_names_id[$event_name] : NULL;
    }

    /**
     * Hook method which gets called at save
     *
     * @param $project_id
     * @param null $record
     * @param $instrument
     * @param $event_id
     * @param null $group_id
     * @param null $survey_hash
     * @param null $response_id
     * @param int $repeat_instance
     */
    function hook_save_record($project_id, $record = NULL, $instrument, $event_id, $group_id = NULL, $survey_hash = NULL, $response_id = NULL, $repeat_instance = 1)
    {

        $this->project_id = $project_id;
        $this->record_id = $record;
        $this->instrument = $instrument;
        $this->event_id = $event_id;
        $this->redcap_event_name = \REDCap::getEventNames(true, false, $event_id);

        $this->emLog("PROJECTID: ".$project_id . " RECORD: " . $record . " EVENT_ID: ". $event_id . " INSTRUMENT: " . $instrument . " REDCAP_EVENT_NAME " . $this->redcap_event_name);
        $this->config_fields = $this->setupConfig();


        $subsettings = $this->getSubSettings('child-projects');

        //iterate over each of the child records
        //foreach ($this->config_fields as $key => $value) {
        foreach ($subsettings as $key => $value) {
            //$this->emLog("PROJECTID: ".$project_id ." : Dealing with child: $key", $value);
            $this->handleChildProject($value);
        }
    }

    /**
     *
     * emLogging integration
     *
     */
    function emLog() {
        $emLogger = \ExternalModules\ExternalModules::getModuleInstance('em_logger');
        $emLogger->emLog($this->PREFIX, func_get_args(), "INFO");
    }

    function emDebug() {
        // Check if debug enabled
        if ($this->getSystemSetting('enable-system-debug-logging') || $this->getProjectSetting('enable-project-debug-logging')) {
            $emLogger = \ExternalModules\ExternalModules::getModuleInstance('em_logger');
            $emLogger->emLog($this->PREFIX, func_get_args(), "DEBUG");
        }
    }

    function emError() {
        $emLogger = \ExternalModules\ExternalModules::getModuleInstance('em_logger');
        $emLogger->emLog($this->PREFIX, func_get_args(), "ERROR");
    }
}

