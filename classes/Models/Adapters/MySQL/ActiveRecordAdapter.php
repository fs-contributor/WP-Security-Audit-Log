<?php

class WSAL_Adapters_MySQL_ActiveRecord implements WSAL_Adapters_ActiveRecordInterface {
    
    protected $connection;

    /**
     * Contains the table name
     * @var string
     */
    protected $_table;

    /**
     * Contains primary key column name, override as required.
     * @var string
     */
    protected $_idkey = '';

    public function __construct($conn)
    {
        $this->connection = $conn;
    }

    public function GetModel()
    {
        return new WSAL_Models_ActiveRecord(); 
    }
    
    /**
     * @return string Returns table name.
     */
    public function GetTable()
    {
        $_wpdb = $this->connection;
        return $_wpdb->base_prefix . $this->_table;
    }
    
    /**
     * Used for WordPress prefix
     * @return string Returns table name of WordPress.
     */
    public function GetWPTable()
    {
        global $wpdb;
        return $wpdb->base_prefix . $this->_table;
    }

    /**
     * @return string SQL table options (constraints, foreign keys, indexes etc).
     */
    protected function GetTableOptions()
    {
        return '    PRIMARY KEY  (' . $this->_idkey . ')';
    }
    
    /**
     * @return array Returns this records' columns.
     */
    public function GetColumns()
    {
        $model = $this->GetModel();
        
        if(!isset($this->_column_cache)){
            $this->_column_cache = array();
            foreach(array_keys(get_object_vars($model)) as $col)
                if(trim($col) && $col[0] != '_')
                    $this->_column_cache[] = $col;
        }
        return $this->_column_cache;
    }
    
    /**
     * @deprecated
     * @return boolean Returns whether table structure is installed or not.
     */
    public function IsInstalled(){
        //global $wpdb;
        $_wpdb = $this->connection;
        $sql = 'SHOW TABLES LIKE "' . $this->GetTable() . '"';
        return strtolower($_wpdb->get_var($sql)) == strtolower($this->GetTable());
    }
    
    /**
     * Install this ActiveRecord structure into DB.
     */
    public function Install(){
        $_wpdb = $this->connection;
        $_wpdb->query($this->_GetInstallQuery());
    }
    
     /**
     * Install this ActiveRecord structure into DB WordPress.
     */
    public function InstallOriginal(){
        global $wpdb;
        $wpdb->query($this->_GetInstallQuery(true));
    }

    /**
     * Remove this ActiveRecord structure into DB.
     */
    public function Uninstall()
    {
        //global $wpdb;
        $_wpdb = $this->connection;
        $_wpdb->query($this->_GetUninstallQuery());
    }
    
    /**
     * Save an active record to DB.
     * @return integer|boolean Either the number of modified/inserted rows or false on failure.
     */
    public function Save($activeRecord)
    {
        //global $wpdb;
        $_wpdb = $this->connection; 
        $copy = $activeRecord;
        $data = array();
        $format = array();
        foreach ($this->GetColumns() as $key) {

            $val = $copy->$key;
            $deffmt = '%s';
            if (is_int($copy->$key)) {
              $deffmt = '%d';
            }
            if (is_float($copy->$key)) {
                $deffmt = '%f';
            }
            if (is_array($copy->$key) || is_object($copy->$key)) {
                $data[$key] = WSAL_Helpers_DataHelper::JsonEncode($val);
            } else {
                $data[$key] = $val;
            }
            $format[] = $deffmt;
        }
        $result = $_wpdb->replace($this->GetTable(), $data, $format);
            
        if ($result !== false) {
            if ($_wpdb->insert_id) {
                $copy->setId($_wpdb->insert_id);
            }
        }
        return $result;
    }
    
    /**
     * Load record from DB.
     * @param string $cond (Optional) Load condition.
     * @param array $args (Optional) Load condition arguments.
     */
    public function Load($cond = '%d', $args = array(1))
    {
        //global $wpdb;
        $_wpdb = $this->connection;
        
        $sql = $_wpdb->prepare('SELECT * FROM '.$this->GetTable().' WHERE '. $cond, $args);
        $data = $_wpdb->get_row($sql, ARRAY_A);

        return $data;
    }

    public function LoadArray($cond, $args = array())
    {
        //global $wpdb;
        $_wpdb = $this->connection;
        $result = array();
        $sql = $_wpdb->prepare('SELECT * FROM '.$this->GetTable().' WHERE '. $cond, $args);
        foreach ($_wpdb->get_results($sql, ARRAY_A) as $data) { 
            $result[] = $this->getModel()->LoadData($data);
        }
        return $result;
    }
    
    /**
     * Delete DB record.
     * @return int|boolean Either the amount of deleted rows or False on error.
     */
    public function Delete($activeRecord)
    {
        //global $wpdb;
        $_wpdb = $this->connection;
        $result = $_wpdb->delete(
            $this->GetTable(),
            $activeRecord->getId()
        );
        return $result;
    }
    
    /**
     * Delete records in DB matching a query.
     * @param string $query Full SQL query.
     * @param array $args (Optional) Query arguments.
     */
    public function DeleteQuery($query, $args = array())
    {
        $_wpdb = $this->connection;
        $sql = count($args) ? $_wpdb->prepare($query, $args) : $query;
        $result = $_wpdb->query($sql);
        return $result;
    }
    
    /**
     * Load multiple records from DB.
     * @param string $cond (Optional) Load condition (eg: 'some_id = %d' ).
     * @param array $args (Optional) Load condition arguments (rg: array(45) ).
     * @return self[] List of loaded records.
     */
    public function LoadMulti($cond, $args = array())
    {
        //global $wpdb;
        $_wpdb = $this->connection;
        $result = array();
        $sql = (!is_array($args) || !count($args)) // do we really need to prepare() or not?
            ? ($cond)
            : $_wpdb->prepare($cond, $args)
        ;
        foreach ($_wpdb->get_results($sql, ARRAY_A) as $data) {
            $result[] = $this->getModel()->LoadData($data);
        }
        return $result;

    }
    
    /**
     * Load multiple records from DB and call a callback for each record.
     * This function is very memory-efficient, it doesn't load records in bulk.
     * @param callable $callback The callback to invoke.
     * @param string $cond (Optional) Load condition.
     * @param array $args (Optional) Load condition arguments.
     */
    public function LoadAndCallForEach($callback, $cond = '%d', $args = array(1))
    {
        //global $wpdb;
        $_wpdb = $this->connection;
        $class = get_called_class();
        $sql = $_wpdb->prepare('SELECT * FROM ' . $this->GetTable() . ' WHERE '.$cond, $args);
        foreach ($_wpdb->get_results($sql, ARRAY_A) as $data) {
            call_user_func($callback, new $class($data));
        }
    }
    
    /**
     * Count records in the DB matching a condition.
     * If no parameters are given, this counts the number of records in the DB table.
     * @param string $cond (Optional) Query condition.
     * @param array $args (Optional) Condition arguments.
     * @return int Number of matching records.
     */
    public function Count($cond = '%d', $args = array(1))
    {
        //global $wpdb;
        $_wpdb = $this->connection;
        $class = get_called_class();
        $sql = $_wpdb->prepare('SELECT COUNT(*) FROM ' . $this->GetTable() . ' WHERE ' . $cond, $args);
        return (int)$_wpdb->get_var($sql);
    }
    
    /**
     * Count records in the DB matching a query.
     * @param string $query Full SQL query.
     * @param array $args (Optional) Query arguments.
     * @return int Number of matching records.
     */
    public function CountQuery($query, $args = array())
    {
        //global $wpdb;
        $_wpdb = $this->connection;
        $sql = count($args) ? $_wpdb->prepare($query, $args) : $query;
        return (int)$_wpdb->get_var($sql);
    }
    
    /**
     * Similar to LoadMulti but allows the use of a full SQL query.
     * @param string $query Full SQL query.
     * @param array $args (Optional) Query arguments.
     * @return self[] List of loaded records.
     */
    public function LoadMultiQuery($query, $args = array())
    {
        //global $wpdb;
        $_wpdb = $this->connection;
        $class = get_called_class();
        $result = array();
        $sql = count($args) ? $_wpdb->prepare($query, $args) :  $query;
        foreach ($_wpdb->get_results($sql, ARRAY_A) as $data) {
            $result[] = $this->getModel()->LoadData($data);
        }
        return $result;
    }

    /**
     * @return string Must return SQL for creating table.
     */
    protected function _GetInstallQuery($prefix = false)
    {
        $_wpdb = $this->connection;
        
        $class = get_class($this);
        $copy = new $class($this->connection);
        $table_name = ($prefix) ? $this->GetWPTable() : $this->GetTable();
        $sql = 'CREATE TABLE IF NOT EXISTS ' . $table_name . ' (' . PHP_EOL;
        
        foreach ($this->GetColumns() as $key) {
            $sql .= '    ';
            switch (true) {
                case $key == $copy->_idkey:
                    $sql .= $key . ' BIGINT NOT NULL AUTO_INCREMENT,' . PHP_EOL;
                    break;
                case is_integer($copy->$key):
                    $sql .= $key . ' BIGINT NOT NULL,' . PHP_EOL;
                    break;
                case is_float($copy->$key):
                    $sql .= $key . ' DOUBLE NOT NULL,' . PHP_EOL;
                    break;
                case is_string($copy->$key):
                    $maxlength = $key . '_maxlength';
                    if (property_exists($class, $maxlength)) {
                        $sql .= $key . ' VARCHAR(' . intval($class::$$maxlength) . ') NOT NULL,' . PHP_EOL;
                    } else {
                        $sql .= $key . ' TEXT NOT NULL,' . PHP_EOL;
                    }
                    break;
                case is_bool($copy->$key):
                    $sql .= $key . ' BIT NOT NULL,' . PHP_EOL;
                    break;
                case is_array($copy->$key):
                case is_object($copy->$key):
                    $sql .= $key . ' LONGTEXT NOT NULL,' . PHP_EOL;
                    break;
            }
        }
        
        $sql .= $this->GetTableOptions() . PHP_EOL;
        
        $sql .= ')';
        
        if (! empty($_wpdb->charset)) {
            $sql .= ' DEFAULT CHARACTER SET ' . $_wpdb->charset;
        }
        
        return $sql;
        
    }

    /**
     * @return string Must return SQL for removing table (at a minimum, it should be ` 'DROP TABLE ' . $this->_table `).
     */
    protected function _GetUninstallQuery(){
        return  'DROP TABLE ' . $this->GetTable();
    }

    /**
     * Function used in WSAL reporting extension
     */
    public function GetReporting($_siteId, $_userId, $_roleName, $_alertCode, $_startTimestamp, $_endTimestamp)
    {
        global $wpdb;
        $tableUsers = $wpdb->users;
        $_wpdb = $this->connection;
        // tables
        $meta = new WSAL_Adapters_MySQL_Meta($this->connection);
        $tableMeta = $meta->GetTable(); // metadata
        $occurrence = new WSAL_Adapters_MySQL_Occurrence($this->connection);
        $tableOcc = $occurrence->GetTable(); // occurrences

        $user_names = '0';
        if (!empty($_userId) && $_userId != "null") {
            $sql = 'SELECT user_login FROM '.$tableUsers.' WHERE find_in_set(ID, @userId) > 0';
            $wpdb->query("SET @userId = $_userId");
            $result = $wpdb->get_results($sql, ARRAY_A);
            $aUsers = array();
            foreach ($result as $item) {
                $aUsers[] = '"'.$item['user_login'].'"';
            }
            $user_names = implode(', ', $aUsers);
        }
        
        $sql = "SELECT DISTINCT
            occ.id, 
            occ.alert_id, 
            occ.site_id, 
            occ.created_on,
            replace(replace(replace((
                SELECT t1.value FROM $tableMeta AS t1 WHERE t1.name = 'CurrentUserRoles' AND t1.occurrence_id = occ.id), '[', ''), ']', ''), '\\'', '') AS roles,
            (SELECT replace(t2.value, '\"','') FROM $tableMeta as t2 WHERE t2.name = 'ClientIP' AND t2.occurrence_id = occ.id) AS ip,
            (SELECT replace(t3.value, '\"', '') FROM $tableMeta as t3 WHERE t3.name = 'UserAgent' AND t3.occurrence_id = occ.id) AS ua,
            COALESCE(
                (SELECT replace(t4.value, '\"', '') FROM $tableMeta as t4 WHERE t4.name = 'Username' AND t4.occurrence_id = occ.id),
                (SELECT replace(t5.value, '\"', '') FROM $tableMeta as t5 WHERE t5.name = 'CurrentUserID' AND t5.occurrence_id = occ.id)
            ) as user_id
            FROM $tableOcc AS occ
            JOIN $tableMeta AS meta ON meta.occurrence_id = occ.id
            WHERE
                (@siteId is NULL OR find_in_set(occ.site_id, @siteId) > 0)
                AND (@userId is NULL OR (
                    (meta.name = 'CurrentUserID' AND find_in_set(meta.value, @userId) > 0)
                OR (meta.name = 'Username' AND replace(meta.value, '\"', '') IN ($user_names))  
                ))
                AND (@roleName is NULL OR (meta.name = 'CurrentUserRoles'
                AND replace(replace(replace(meta.value, ']', ''), '[', ''), '\\'', '') REGEXP @roleName
                ))
                AND (@alertCode is NULL OR find_in_set(occ.alert_id, @alertCode) > 0)
                AND (@startTimestamp is NULL OR occ.created_on >= @startTimestamp)
                AND (@endTimestamp is NULL OR occ.created_on <= @endTimestamp)
            ORDER BY
                site_id, created_on DESC
        ";
        $_wpdb->query("SET @siteId = $_siteId");
        $_wpdb->query("SET @userId = $_userId");
        $_wpdb->query("SET @roleName = $_roleName");
        $_wpdb->query("SET @alertCode = $_alertCode");
        $_wpdb->query("SET @startTimestamp = $_startTimestamp");
        $_wpdb->query("SET @endTimestamp = $_endTimestamp");
        $results = $_wpdb->get_results($sql);

        foreach ($results as $row) {
            $sql = "SELECT t6.ID FROM $tableUsers AS t6 WHERE t6.user_login = \"$row->user_id\"";
            $userId = $wpdb->get_var($sql);
            if ($userId == null) {
                $sql = "SELECT t4.ID FROM $tableUsers AS t4 WHERE t4.ID = \"$row->user_id\"";
                $userId = $wpdb->get_var($sql);
            }
            $row->user_id = $userId;
        }
        return $results;
        /*
        $query = <<<query
SELECT DISTINCT
    occ.id,
    occ.alert_id,
    occ.site_id,
    occ.created_on,
    replace(replace(replace(replace((select t1.value from $tableMeta as t1 where t1.name = 'CurrentUserRoles' and t1.occurrence_id = occ.id), '[', ''), ']', ''), '"', ''), '\\'', '') as roles,
    (select replace(t2.value, '"','') from $tableMeta as t2 where t2.name = 'ClientIP' and t2.occurrence_id = occ.id) as ip,
    (select replace(t3.value, '"', '') from $tableMeta as t3 where t3.name = 'UserAgent' and t3.occurrence_id = occ.id) as ua,

    COALESCE(
        (select t6.ID from $tableUsers as t6 where t6.user_login = (select replace(t7.value, '"', '') from $tableMeta as t7 where t7.name = 'Username' and t7.occurrence_id = occ.id)),
        (select t4.ID from $tableUsers as t4 where t4.ID = (select t5.value from $tableMeta as t5 where t5.name = 'CurrentUserID' and t5.occurrence_id = occ.id))
    ) as user_id
FROM
    $tableOcc as occ
JOIN
    $tableMeta as meta on meta.occurrence_id = occ.id
WHERE
    (@siteId is null or find_in_set(occ.site_id, @siteId) > 0)
    and (@userId is null or (
            (meta.name = 'CurrentUserID' and find_in_set(meta.value, @userId) > 0)
         or (meta.name = 'Username' and replace(meta.value, '"', '') in (select user_login from $tableUsers where find_in_set(ID, @userId) > 0))
    ))
    and (@roleName is null or (meta.name = 'CurrentUserRoles'
        and replace(replace(replace(replace(meta.value, '"', ''), ']', ''), '[', ''), '\\'', '') REGEXP @roleName
    ))
    and (@alertCode is null or find_in_set(occ.alert_id, @alertCode) > 0)
    and (@startTimestamp is null or occ.created_on >= @startTimestamp)
    and (@endTimestamp is null or occ.created_on <= @endTimestamp)
order by
    site_id, created_on DESC;
query;
        //#! Set variables first
        $_wpdb->query("SET @siteId = $_siteId");
        $_wpdb->query("SET @userId = $_userId");
        $_wpdb->query("SET @roleName = $_roleName");
        $_wpdb->query("SET @alertCode = $_alertCode");
        $_wpdb->query("SET @startTimestamp = $_startTimestamp");
        $_wpdb->query("SET @endTimestamp = $_endTimestamp");

        //#! Then run query
        return $_wpdb->get_results($query);
         */
    }

}
