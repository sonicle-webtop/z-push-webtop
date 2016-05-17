<?php
/***********************************************
* File      :   sqlstatemachine.php
* Project   :   Z-Push
* Descr     :   This class handles state requests;
*               Each Import/Export mechanism can
*               store its own state information,
*               which is stored through the
*               state machine.
*
* Created   :   25.08.2013
*
* Copyright 2007 - 2016 Zarafa Deutschland GmbH
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation with the following additional
* term according to sec. 7:
*
* According to sec. 7 of the GNU Affero General Public License, version 3,
* the terms of the AGPL are supplemented with the following terms:
*
* "Zarafa" is a registered trademark of Zarafa B.V.
* "Z-Push" is a registered trademark of Zarafa Deutschland GmbH
* The licensing of the Program under the AGPL does not imply a trademark license.
* Therefore any rights, title and interest in our trademarks remain entirely with us.
*
* However, if you propagate an unmodified version of the Program you are
* allowed to use the term "Z-Push" to indicate that you distribute the Program.
* Furthermore you may use our trademarks where it is necessary to indicate
* the intended purpose of a product or service provided you use it in accordance
* with honest practices in industrial or commercial matters.
* If you want to propagate modified versions of the Program under the name "Z-Push",
* you may only do so if you have a written permission by Zarafa Deutschland GmbH
* (to acquire a permission please contact Zarafa at trademark@zarafa.com).
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
* Consult LICENSE file for details
************************************************/

//include the SqlStateMachine's own config file
require_once("backend/sqlstatemachine/config.php");

class SqlStateMachine implements IStateMachine {
    const SUPPORTED_STATE_VERSION = IStateMachine::STATEVERSION_02;
    const VERSION = "version";

    const UNKNOWNDATABASE = 1049;
    const CREATETABLE_SETTINGS = "CREATE TABLE IF NOT EXISTS settings (key_name VARCHAR(50) NOT NULL, key_value VARCHAR(50) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (key_name));";
    const CREATETABLE_USERS = "CREATE TABLE IF NOT EXISTS users (username VARCHAR(50) NOT NULL, device_id VARCHAR(50) NOT NULL, PRIMARY KEY (username, device_id));";
    const CREATETABLE_STATES = "CREATE TABLE IF NOT EXISTS states (id_state INTEGER AUTO_INCREMENT, device_id VARCHAR(50) NOT NULL, uuid VARCHAR(50) NULL, state_type VARCHAR(50), counter INTEGER, state_data MEDIUMBLOB, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id_state));";
    const CREATEINDEX_STATES = "CREATE UNIQUE INDEX idx_states_unique ON states (device_id, uuid, state_type, counter);";

    private $dbh;
    private $options;
    private $dsn;
    private $stateHashStatement;

    /**
     * Constructor
     *
     * Performs some basic checks and initializes the state directory.
     *
     * @access public
     * @throws FatalException
     * @throws FatalMisconfigurationException
     */
    public function __construct() {
        ZLog::Write(LOGLEVEL_DEBUG, "SqlStateMachine(): init");

        if (!trim(STATE_SQL_SERVER) || !trim(STATE_SQL_PORT) || !trim(STATE_SQL_DATABASE) || !trim(STATE_SQL_USER)) {
            throw new FatalMisconfigurationException("SqlStateMachine(): missing configuration for the state sql. Check STATE_SQL_* values in the configuration.");
        }

        $this->options = array();
        if (trim(STATE_SQL_OPTIONS)) {
            $this->options = unserialize(STATE_SQL_OPTIONS);
        }

        $this->dsn = sprintf("%s:host=%s;port=%s;dbname=%s", STATE_SQL_ENGINE, STATE_SQL_SERVER, STATE_SQL_PORT, STATE_SQL_DATABASE);

        // check if the database and necessary tables exist and try to create them if necessary.
        try {
            $this->checkDbAndTables();
        }
        catch(PDOException $ex) {
            throw new FatalException(sprintf("SqlStateMachine(): not possible to connect to the state database: %s", $ex->getMessage()));
        }
    }

    /**
     * Returns an existing PDO instance or creates new if necessary.
     *
     * @param boolean $throwFatalException   if true (default) a FatalException is thrown in case of a PDOException, if false the PDOException.
     *
     * @access public
     * @return PDO
     * @throws FatalException
     */
    public function getDbh($throwFatalException = true) {
        if (!isset($this->dbh) || $this->dbh == null) {
            try {
                $this->dbh = new PDO($this->dsn, STATE_SQL_USER, STATE_SQL_PASSWORD, $this->options);
            }
            catch(PDOException $ex) {
                if ($throwFatalException) {
                    throw new FatalException(sprintf("SqlStateMachine()->getDbh(): not possible to connect to the state database: %s", $ex->getMessage()));
                }
                else {
                    throw $ex;
                }
            }
        }
        return $this->dbh;
    }

    /**
     * Gets a hash value indicating the latest dataset of the named
     * state with a specified key and counter.
     * If the state is changed between two calls of this method
     * the returned hash should be different.
     *
     * @param string    $devid              the device id
     * @param string    $type               the state type
     * @param string    $key                (opt)
     * @param string    $counter            (opt)
     *
     * @access public
     * @return string
     * @throws StateNotFoundException, StateInvalidException
     */
    public function GetStateHash($devid, $type, $key = null, $counter = false) {
        $hash = null;
        $record = null;
        try {
            $sth = $this->getStateHashStatement($key);
            $params = $this->getParams($devid, $type, $key, $counter);
            $sth->execute($params);

            $record = $sth->fetch(PDO::FETCH_ASSOC);
            if (!$record) {
                $this->clearConnection($this->dbh, $sth, $record);
                throw new StateNotFoundException("SqlStateMachine->GetStateHash(): Could not locate state");
            }
            else {
                // datetime->format("U") returns EPOCH
                $datetime = new DateTime($record["updated_at"]);
                $hash = $datetime->format("U");
            }
        }
        catch(PDOException $ex) {
            $this->clearConnection($this->dbh, $sth, $record);
            throw new StateNotFoundException(sprintf("SqlStateMachine->GetStateHash(): Could not locate state: %s", $ex->getMessage()));
        }

        return $hash;
    }

    /**
     * Gets a state for a specified key and counter.
     * This method should call IStateMachine->CleanStates()
     * to remove older states (same key, previous counters).
     *
     * @param string    $devid              the device id
     * @param string    $type               the state type
     * @param string    $key                (opt)
     * @param string    $counter            (opt)
     * @param string    $cleanstates        (opt)
     *
     * @access public
     * @return mixed
     * @throws StateNotFoundException, StateInvalidException, UnavailableException
     */
    public function GetState($devid, $type, $key = null, $counter = false, $cleanstates = true) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->GetState(): devid:'%s' type:'%s' key:'%s' counter:'%s'", $devid, $type, ($key == null ? 'null' : $key), Utils::PrintAsString($counter), Utils::PrintAsString($cleanstates)));
        if ($counter && $cleanstates)
            $this->CleanStates($devid, $type, $key, $counter);

        $sql = "SELECT state_data FROM states WHERE device_id = :devid AND state_type = :type AND uuid ". (($key == null) ? " IS " : " = ") . ":key AND counter = :counter";
        $params = $this->getParams($devid, $type, $key, $counter);

        $data = null;
        $sth = null;
        $record = null;
        try {
            $sth = $this->getDbh()->prepare($sql);
            $sth->execute($params);

            $record = $sth->fetch(PDO::FETCH_ASSOC);
            if (!$record) {
                $this->clearConnection($this->dbh, $sth, $record);
                // throw an exception on all other states, but not FAILSAVE as it's most of the times not there by default
                if ($type !== IStateMachine::FAILSAVE) {
                    throw new StateNotFoundException("SqlStateMachine->GetState(): Could not locate state");
                }
            }
            else {
                if (is_string($record["state_data"])) {
                    // MySQL-PDO returns a string for LOB objects
                    $data = unserialize($record["state_data"]);
                }
                else {
                    $data = unserialize(stream_get_contents($record["state_data"]));
                }
            }
        }
        catch(PDOException $ex) {
            $this->clearConnection($this->dbh, $sth, $record);
            throw new StateNotFoundException(sprintf("SqlStateMachine->GetState(): Could not locate state: %s", $ex->getMessage()));
        }

        return $data;
    }

    /**
     * Writes ta state to for a key and counter.
     *
     * @param mixed     $state
     * @param string    $devid              the device id
     * @param string    $type               the state type
     * @param string    $key                (opt)
     * @param int       $counter            (opt)
     *
     * @access public
     * @return boolean
     * @throws StateInvalidException, UnavailableException
     */
    public function SetState($state, $devid, $type, $key = null, $counter = false) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->SetState(): devid:'%s' type:'%s' key:'%s' counter:'%s'", $devid, $type, ($key == null ? 'null' : $key), Utils::PrintAsString($counter)));

        $sql = "SELECT device_id FROM states WHERE device_id = :devid AND state_type = :type AND uuid ". (($key == null) ? " IS " : " = ") . ":key AND counter = :counter";
        $params = $this->getParams($devid, $type, $key, $counter);

        $sth = null;
        $record = null;
        $bytes = 0;

        try {
            $sth = $this->getDbh()->prepare($sql);
            $sth->execute($params);

            $record = $sth->fetch(PDO::FETCH_ASSOC);
            if (!$record) {
                // New record
                $sql = "INSERT INTO states (device_id, state_type, uuid, counter, state_data, created_at, updated_at) VALUES (:devid, :type, :key, :counter, :data, :created_at, :updated_at)";

                $sth = $this->getDbh()->prepare($sql);
                $sth->bindValue(":created_at", $this->getNow(), PDO::PARAM_STR);
            }
            else {
                // Existing record, we update it
                $sql = "UPDATE states SET state_data = :data, updated_at = :updated_at WHERE device_id = :devid AND state_type = :type AND uuid " . (($key == null) ? " IS " : " = ") .":key AND counter = :counter";

                $sth = $this->getDbh()->prepare($sql);
            }

            $sth->bindParam(":devid", $devid, PDO::PARAM_STR);
            $sth->bindParam(":type", $type, PDO::PARAM_STR);
            $sth->bindParam(":key", $key, PDO::PARAM_STR);
            $sth->bindValue(":counter", ($counter === false ? 0 : $counter), PDO::PARAM_INT);
            $sth->bindValue(":data", serialize($state), PDO::PARAM_LOB);
            $sth->bindValue(":updated_at", $this->getNow(), PDO::PARAM_STR);

            if (!$sth->execute() ) {
                $errInfo = $sth->errorInfo();
                $this->clearConnection($this->dbh, $sth);
                throw new UnavailableException(sprintf("SqlStateMachine->SetState(): Could not write state: %s", isset($errInfo[2]) ? $errInfo[2] : 'unknown'));
            }
            else {
                $bytes = strlen(serialize($state));
            }
        }
        catch(PDOException $ex) {
            $this->clearConnection($this->dbh, $sth);
            throw new UnavailableException(sprintf("SqlStateMachine->SetState(): Could not write state: %s", $ex->getMessage()));
        }

        return $bytes;
    }

    /**
     * Cleans up all older states.
     * If called with a $counter, all states previous state counter can be removed.
     * If called without $counter, all keys (independently from the counter) can be removed.
     *
     * @param string    $devid              the device id
     * @param string    $type               the state type
     * @param string    $key
     * @param string    $counter            (opt)
     *
     * @access public
     * @return
     * @throws StateInvalidException
     */
    public function CleanStates($devid, $type, $key = null, $counter = false) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->CleanStates(): devid:'%s' type:'%s' key:'%s' counter:'%s'", $devid, $type, ($key == null ? 'null' : $key), Utils::PrintAsString($counter)));


        if ($counter === false) {
            // Remove all the states. Counter are -1 or > 0, then deleting >= -1 deletes all
            $sql = "DELETE FROM states WHERE device_id = :devid AND state_type = :type AND uuid = :key AND counter >= :counter";
        }
        else {
            $sql = "DELETE FROM states WHERE device_id = :devid AND state_type = :type AND uuid = :key AND counter < :counter";
        }
        $params = $this->getParams($devid, $type, $key, $counter);

        $sth = null;
        try {
            $sth = $this->getDbh()->prepare($sql);
            $sth->execute($params);

        }
        catch(PDOException $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("SqlStateMachine->CleanStates(): Error deleting states: %s", $ex->getMessage()));
        }
    }

    /**
     * Links a user to a device.
     *
     * @param string    $username
     * @param string    $devid
     *
     * @access public
     * @return boolean     indicating if the user was added or not (existed already)
     */
    public function LinkUserDevice($username, $devid) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->LinkUserDevice(): '%s', '%s'", $username, $devid));

        $sth = null;
        $record = null;
        $changed = false;
        try {
            $sql = "SELECT username FROM users WHERE username = :username AND device_id = :devid";
            $params = array(":username" => $username, ":devid" => $devid);

            $sth = $this->getDbh()->prepare($sql);
            $sth->execute($params);

            $record = $sth->fetch(PDO::FETCH_ASSOC);
            if ($record) {
                ZLog::Write(LOGLEVEL_DEBUG, "SqlStateMachine->LinkUserDevice(): nothing changed");
            }
            else {
                $sth = null;
                $sql = "INSERT INTO users (username, device_id) VALUES (:username, :devid)";
                $sth = $this->getDbh()->prepare($sql);
                if ($sth->execute($params)) {
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->LinkUserDevice(): Linked user-device: '%s' '%s'", $username, $devid));
                    $changed = true;
                }
                else {
                    ZLog::Write(LOGLEVEL_ERROR, "SqlStateMachine->LinkUserDevice(): Unable to link user-device");
                }
            }
        }
        catch(PDOException $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("SqlStateMachine->LinkUserDevice(): Error linking user-device: %s", $ex->getMessage()));
        }

        return $changed;
    }

   /**
     * Unlinks a device from a user.
     *
     * @param string    $username
     * @param string    $devid
     *
     * @access public
     * @return boolean
     */
    public function UnLinkUserDevice($username, $devid) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->UnLinkUserDevice(): '%s', '%s'", $username, $devid));

        $sth = null;
        $changed = false;
        try {
            $sql = "DELETE FROM users WHERE username = :username AND device_id = :devid";
            $params = array(":username" => $username, ":devid" => $devid);

            $sth = $this->getDbh()->prepare($sql);
            if ($sth->execute($params)) {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->UnLinkUserDevice(): Unlinked user-device: '%s' '%s'", $username, $devid));
                $changed = true;
            }
            else {
                ZLog::Write(LOGLEVEL_DEBUG, "SqlStateMachine->UnLinkUserDevice(): nothing changed");
            }
        }
        catch(PDOException $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("SqlStateMachine->UnLinkUserDevice(): Error unlinking user-device: %s", $ex->getMessage()));
        }

        return $changed;
    }

    /**
     * Get all UserDevice mapping.
     *
     * @access public
     * @return array
     */
    public function GetAllUserDevice() {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->GetAllUserDevice(): '%s'", $username));

        $sth = null;
        $record = null;
        $out = array();
        try {
            $sql = "SELECT device_id, username FROM users ORDER BY username";
            $sth = $this->getDbh()->prepare($sql);
            $sth->execute();

            while ($record = $sth->fetch(PDO::FETCH_ASSOC)) {
                if (!array_key_exists($record["username"], $out)) {
                    $out[$record["username"]] = array();
                }
                $out[$record["username"]][] = $record["device_id"];
            }
        }
        catch(PDOException $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("SqlStateMachine->GetAllUserDevice(): Error listing devices: %s", $ex->getMessage()));
        }

        return $out;
    }

    /**
     * Returns an array with all device ids for a user.
     * If no user is set, all device ids should be returned.
     *
     * @param string    $username   (opt)
     *
     * @access public
     * @return array
     */
    public function GetAllDevices($username = false) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->GetAllDevices(): '%s'", $username));

        $sth = null;
        $record = null;
        $out = array();
        try {
            if  ($username === false) {
                $sql = "SELECT DISTINCT(device_id) FROM users ORDER BY device_id";
                $params = array();
            }
            else {
                $sql = "SELECT device_id FROM users WHERE username = :username ORDER BY device_id";
                $params = array(":username" => $username);
            }
            $sth = $this->getDbh()->prepare($sql);
            $sth->execute($params);

            while ($record = $sth->fetch(PDO::FETCH_ASSOC)) {
                $out[] = $record["device_id"];
            }
        }
        catch(PDOException $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("SqlStateMachine->GetAllDevices(): Error listing devices: %s", $ex->getMessage()));
        }

        return $out;
    }

    /**
     * Returns the current version of the state files.
     *
     * @access public
     * @return int
     */
    public function GetStateVersion() {
        ZLog::Write(LOGLEVEL_DEBUG, "SqlStateMachine->GetStateVersion().");

        $sth = null;
        $record = null;
        $version = IStateMachine::STATEVERSION_01;
        try {
            $sql = "SELECT key_value FROM settings WHERE key_name = :key_name";
            $params = array(":key_name" => self::VERSION);

            $sth = $this->getDbh()->prepare($sql);
            $sth->execute($params);

            $record = $sth->fetch(PDO::FETCH_ASSOC);
            if ($record) {
                $version = $record["key_value"];
            }
            else {
                $this->SetStateVersion(self::SUPPORTED_STATE_VERSION);
                $version = self::SUPPORTED_STATE_VERSION;
            }
        }
        catch(PDOException $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("SqlStateMachine->GetStateVersion(): Error getting state version: %s", $ex->getMessage()));
        }

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->GetStateVersion(): supporting version '%d'", $version));
        return $version;
    }

    /**
     * Sets the current version of the state files.
     *
     * @param int       $version            the new supported version
     *
     * @access public
     * @return boolean
     */
    public function SetStateVersion($version) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->SetStateVersion(): '%s'", $version));

        $sth = null;
        $record = null;
        $status = false;
        try {
            $sql = "SELECT key_value FROM settings WHERE key_name = :key_name";
            $params = array(":key_name" => self::VERSION);

            $sth = $this->getDbh()->prepare($sql);
            $sth->execute($params);

            $record = $sth->fetch(PDO::FETCH_ASSOC);
            if ($record) {
                $sth = null;
                $sql = "UPDATE settings SET key_value = :value, updated_at = :updated_at WHERE key_name = :key_name";
                $params[":value"] = $version;
                $params[":updated_at"] = $this->getNow();

                $sth = $this->getDbh()->prepare($sql);
                if ($sth->execute($params)) {
                    $status = true;
                }
            }
            else {
                $sth = null;
                $sql = "INSERT INTO settings (key_name, key_value, created_at, updated_at) VALUES (:key_name, :value, :created_at, :updated_at)";
                $params[":value"] = $version;
                $params[":updated_at"] = $params[":created_at"] = $this->getNow();

                $sth = $this->getDbh()->prepare($sql);
                if ($sth->execute($params)) {
                    $status = true;
                }
            }
        }
        catch(PDOException $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("SqlStateMachine->SetStateVersion(): Error saving state version: %s", $ex->getMessage()));
        }

        return $status;
    }

    /**
     * Returns all available states for a device id.
     *
     * @param string    $devid              the device id
     *
     * @access public
     * @return array(mixed)
     */
    public function GetAllStatesForDevice($devid) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->GetAllStatesForDevice(): '%s'", $devid));

        $sth = null;
        $record = null;
        $out = array();
        try {
            $sql = "SELECT state_type, uuid, counter FROM states WHERE device_id = :devid ORDER BY id_state";
            $params = array(":devid" => $devid);

            $sth = $this->getDbh()->prepare($sql);
            $sth->execute($params);

            while ($record = $sth->fetch(PDO::FETCH_ASSOC)) {
                $state = array('type' => false, 'counter' => false, 'uuid' => false);
                if ($record["state_type"] !== null && strlen($record["state_type"]) > 0) {
                    $state["type"] = $record["state_type"];
                }
                else {
                    if ($record["counter"] !== null && is_numeric($record["counter"])) {
                        $state["type"] = "";
                    }
                }
                if ($record["counter"] !== null && strlen($record["counter"]) > 0) {
                    $state["counter"] = $record["counter"];
                }
                if ($record["uuid"] !== null && strlen($record["uuid"]) > 0) {
                    $state["uuid"] = $record["uuid"];
                }
                $out[] = $state;
            }
        }
        catch(PDOException $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("SqlStateMachine->GetAllStatesForDevice(): Error listing states: %s", $ex->getMessage()));
        }

        return $out;
    }

    /**----------------------------------------------------------------------------------------------------------
     * Private SqlStateMachine stuff
     */

    /**
     * Return a string with the datetime NOW.
     *
     * @return string
     * @access private
     */
    private function getNow() {
        $now = new DateTime("NOW");
        return $now->format("Y-m-d H:i:s");
    }

    /**
     * Return an array with the params for the PDO query.
     *
     * @params string $devid
     * @params string $type
     * @params string $key
     * @params string $counter
     * @return array
     * @access private
     */
    private function getParams($devid, $type, $key, $counter) {
        return array(":devid" => $devid, ":type" => $type, ":key" => $key, ":counter" => ($counter === false ? 0 : $counter) );
    }

    /**
     * Free PDO resources.
     *
     * @params PDOConnection $dbh
     * @params PDOStatement $sth
     * @params PDORecord $record
     * @access private
     */
    private function clearConnection(&$dbh, &$sth = null, &$record = null) {
        if ($record != null) {
            $record = null;
        }
        if ($sth != null) {
            $sth = null;
        }
        if ($dbh != null) {
            $dbh = null;
        }
    }

    /**
     * Prepares PDOStatement which will be used to get the state hash.
     *
     * @param string    $key                state uuid

     * @access private
     * @return PDOStatement
     */
    private function getStateHashStatement($key) {
        if (!isset($this->stateHashStatement) || $this->stateHashStatement == null) {
            $sql = "SELECT updated_at FROM states WHERE device_id = :devid AND state_type = :type AND uuid ". (($key == null) ? " IS " : " = ") . ":key AND counter = :counter";
            $this->stateHashStatement = $this->getDbh()->prepare($sql);
        }
        return $this->stateHashStatement;
    }
    /**
     * Check if the database and necessary tables exist.
     *
     * @access private
     * @return boolean
     * @throws UnavailableException
     */
    private function checkDbAndTables() {
        ZLog::Write(LOGLEVEL_DEBUG, "SqlStateMachine->checkDbAndTables(): Checking if database and tables are available.");
        try {
            $sqlStmt = sprintf("SHOW TABLES FROM %s", STATE_SQL_DATABASE);
            $sth = $this->getDbh(false)->prepare($sqlStmt);
            $sth->execute();
            if ($sth->rowCount() != 3) {
                $this->createTables();
            }
            ZLog::Write(LOGLEVEL_DEBUG, "SqlStateMachine->checkDbAndTables(): Database and tables exist.");
            return true;
        }
        catch (PDOException $ex) {
            ZLog::Write(LOGLEVEL_INFO, sprintf("SqlStateMachine->checkDbAndTables(): error checking the database (%s): %s", $ex->getCode(), $ex->getMessage()));
            // try to create the database if it doesn't exist
            if ($ex->getCode() == self::UNKNOWNDATABASE) {
                $this->createDB();
            }
            else {
                throw new UnavailableException(sprintf("SqlStateMachine->checkDbAndTables(): PDOException (%s): %s", $ex->getCode(), $ex->getMessage()));
            }
        }

        // try to connect to the db again and do the create tables calls
        $this->createTables();
    }

    /**
     * Create the states database.
     *
     * @access private
     * @return boolean
     * @throws UnavailableException
     */
    private function createDB() {
        ZLog::Write(LOGLEVEL_INFO, sprintf("SqlStateMachine->createDB(): database '%s' is not available, trying to create it.", STATE_SQL_DATABASE));
        $dsn = sprintf("%s:host=%s;port=%s", STATE_SQL_ENGINE, STATE_SQL_SERVER, STATE_SQL_PORT);
        try {
            $dbh = new PDO($dsn, STATE_SQL_USER, STATE_SQL_PASSWORD, $this->options);
            $sqlStmt = sprintf("CREATE DATABASE %s", STATE_SQL_DATABASE);
            $sth = $dbh->prepare($sqlStmt);
            $sth->execute();
            ZLog::Write(LOGLEVEL_DEBUG, "SqlStateMachine->createDB(): Database created succesfully.");
            $this->createTables();
            $this->clearConnection($dbh);
            return true;
        }
        catch (PDOException $ex) {
            throw new UnavailableException(sprintf("SqlStateMachine->createDB(): PDOException (%s): %s", $ex->getCode(), $ex->getMessage()));
        }
    }

    /**
     * Create the tables in the database.
     *
     * @access private
     * @return boolean
     * @throws UnavailableException
     */
    private function createTables() {
        ZLog::Write(LOGLEVEL_INFO, "SqlStateMachine->createTables(): tables are not available, trying to create them.");
        try {
            $sqlStmt = self::CREATETABLE_SETTINGS . self::CREATETABLE_USERS . self::CREATETABLE_STATES . self::CREATEINDEX_STATES;
            $sth = $this->getDbh()->prepare($sqlStmt);
            $sth->execute();
            ZLog::Write(LOGLEVEL_DEBUG, "SqlStateMachine->createTables(): tables created succesfully.");
            return true;
        }
        catch (PDOException $ex) {
            throw new UnavailableException(sprintf("SqlStateMachine->createTables(): PDOException (%s): %s", $ex->getCode(), $ex->getMessage()));
        }
    }

    /**
     * Checks if state tables have data. This is only used by the migrate-filestates-to-db script.
     *
     * @access public
     * @return boolean
     * @throws UnavailableException
     */
    public function DoTablesHaveData() {
        try {
            $dataSettings = $dataStates = $dataUsers = false;
            $sqlStmt = "SELECT key_name FROM settings LIMIT 1;";
            $sth = $this->getDbh()->prepare($sqlStmt);
            $sth->execute();
            if ($sth->rowCount() > 0) {
                print("There is data in settings table." . PHP_EOL);
                $dataSettings = true;
            }
            else {
                print("There is no data in settings table." . PHP_EOL);
            }

            $sqlStmt = "SELECT id_state FROM states LIMIT 1;";
            $sth = $this->getDbh()->prepare($sqlStmt);
            $sth->execute();
            if ($sth->rowCount() > 0) {
                print("There is data in states table." . PHP_EOL);
                $dataStates = true;
            }
            else {
                print("There is no data in states table." . PHP_EOL);
            }

            $sqlStmt = "SELECT username FROM users LIMIT 1;";
            $sth = $this->getDbh()->prepare($sqlStmt);
            $sth->execute();
            if ($sth->rowCount() > 0) {
                print("There is data in users table." . PHP_EOL);
                $dataUsers = true;
            }
            else {
                print("There is no data in users table." . PHP_EOL);
            }
            return ($dataSettings || $dataStates || $dataUsers);
        }
        catch (PDOException $ex) {
            throw new UnavailableException(sprintf("SqlStateMachine->DoTablesHaveData(): PDOException (%s): %s", $ex->getCode(), $ex->getMessage()));
        }
        return false;
    }
}