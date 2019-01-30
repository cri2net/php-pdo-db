<?php

namespace cri2net\php_pdo_db;

use \Exception;
use \PDO;
use cri2net\php_singleton\Singleton;

class PDO_DB
{
    use Singleton;

    private static $pdo = null;
    private static $params = null;

    private function __construct()
    {
        $this->init();
    }

    public static function initSettings(array $params)
    {
        self::$params = $params;
    }

    private function init()
    {
        if (self::$pdo == null) {

            $defaults = [
                'type'     => 'mysql',
                'charset'  => 'utf8',
                'host'     => ((defined('DB_HOST')) ? DB_HOST : 'localhost'),
                'user'     => ((defined('DB_USER')) ? DB_USER : 'root'),
                'name'     => ((defined('DB_NAME')) ? DB_NAME : ''),
                'password' => ((defined('DB_PASSWORD')) ? DB_PASSWORD : ''),
            ];
            if (self::$params !== null) {
                $defaults = array_merge($defaults, self::$params);
            }

            self::initSettings($defaults);

            try {
    
                $dsn = "{$defaults['type']}:host={$defaults['host']};dbname={$defaults['name']}";
                if ($defaults['type'] === 'mysql') {
                    $dsn .= ";charset={$defaults['charset']}";
                }
    
                self::$pdo = new PDO(
                    $dsn,
                    $defaults['user'],
                    $defaults['password'],
                    [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$defaults['charset']}"
                    ]
                );
            } catch (Exception $e) {
                throw new Exception($e->getMessage(), $e->getCode());
            }
        }
    }

    public static function getPDO()
    {
        $instance = self::getInstance();
        return $instance::$pdo;
    }

    public static function getParams($key = null)
    {
        if ($key === null) {
            return self::$params;
        }

        if (isset(self::$params[$key])) {
            return self::$params[$key];
        }

        return null;
    }

    public static function getEscapeCharacter()
    {
        switch (self::getParams('type')) {
            case 'pgsql':
                return '"';
            
            case 'mysql':
            default:
                return '`';
        }
    }

    /**
     * Preform SQL insert operation. 
     * @param array $data - associated array of data, key name should be as field name in the DB table.
     * @param string $table - name of the table, where data should be inserted.
     * @param boolean $ignore - use INSERT or INSERT IGNORE statement. OPTIONAL
     *
     * @return integer Last insert ID
     */
    public static function insert(array $data, $table, $ignore = false)
    {
        $pdo = self::getPDO();
        $e_char = self::getEscapeCharacter();

        if (!empty($data)) {

            $keys = [];
            $str_values = '';

            foreach ($data as $key => $value) {

                $keys[] = $e_char . $key . $e_char;

                if ($value === null) {
                    $str_values .= "NULL, ";
                } else {
                    $value = $pdo->quote($value);
                    $str_values .= "$value, ";
                }
            }

            $str_values = trim($str_values, ', ');
            $str_keys = implode(', ', $keys);

            $ignore_keyword = ($ignore && (self::getParams('type') === 'mysql')) ? 'IGNORE' : '';
            self::query("INSERT $ignore_keyword INTO {$e_char}$table{$e_char} ($str_keys) VALUES ($str_values)");
            
            return self::lastInsertID();
        }

        return 0;
    }
    
    /**
     * Preform SQL update operation. 
     * @param array $data - associated array of data, key name should be as field name in the DB table.
     * @param string $table - name of the table, where record should be updated.
     * @param string $primary - name of the column for where clause.
     * @param string $id - value of the primary for where clause.
     */
    public static function update(array $data, $table, $id, $primary = 'id')
    {
        $e_char = self::getEscapeCharacter();

        if (!empty($data)) {
            $str = self::arrayToString($data);

            switch (self::getParams('type')) {
                case 'pgsql':
                    $stm = self::prepare("UPDATE {$e_char}$table{$e_char} SET $str WHERE {$e_char}$primary{$e_char}=?", [$id]);
                    break;
                
                case 'mysql':
                default:
                    $stm = self::prepare("UPDATE {$e_char}$table{$e_char} SET $str WHERE {$e_char}$primary{$e_char}=? LIMIT 1", [$id]);
            }

            return $stm->rowCount();
        }
    }
    
    /**
     * Preform SQL update operation. 
     * @param array $data - associated array of data, key name should be as field name in the DB table.
     * @param string $table - name of the table, where records should be updated.
     * @param string $where - SQL where clause.
     */
    public static function updateWithWhere(array $data, $table, $where)
    {
        $e_char = self::getEscapeCharacter();

        if (!empty($data)) {
            $str = self::arrayToString($data);
            $stm = self::query("UPDATE {$e_char}$table{$e_char} SET $str WHERE $where");

            return $stm->rowCount();
        }
    }
    
    /**
     *  Return last inserted id.
     */
    public static function lastInsertID()
    {
        try {
            $pdo = self::getPDO();
            return $pdo->lastInsertId();
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     *
     * Return concatenateed string from given array. Method are using in the insert and updated methods.
     * @param array $data - associated array of data.
     * @return string
     */
    private static function arrayToString(array $data)
    {
        $pdo = self::getPDO();
        $str = '';
        $e_char = self::getEscapeCharacter();

        foreach ($data as $key => $value) {

            if ($value === null) {
                $str .= "{$e_char}$key{$e_char} = NULL, ";
            } else {
                $value = $pdo->quote($value);
                $str .= "{$e_char}$key{$e_char} = $value, ";
            }
        }

        return trim($str, ', ');
    }

    /**
     *  Почти что псевдоним table_list
     */
    public static function first()
    {
        $instance = self::getInstance();
        $args = func_get_args();

        // LIMIT стоит 4-м аргументом, перед ним два необязательных. Если он не указан, ставим ему '1'
        if (!isset($args[1])) {
            $args[1] = null;
        }
        if (!isset($args[2])) {
            $args[2] = null;
        }
        if (!isset($args[3])) {
            $args[3] = '1';
        }

        $result = call_user_func_array([$instance, 'table_list'], $args);
        if (count($result) == 0) {
            return null;
        }
        return $result[0];
    }

    public static function table_list($table, $where = null, $order = null, $limit = null)
    {
        $e_char = self::getEscapeCharacter();
        $query = "SELECT * FROM {$e_char}$table{$e_char}";

        if ($where != null) {
            $query .= " WHERE $where";
        }
        if ($order != null) {
            $query .= " ORDER BY $order";
        }
        if ($limit != null) {
            $query .= " LIMIT $limit";
        }

        $stm = self::query($query);
        return $stm->fetchAll();
    }
    
    public static function row_by_id($table, $id, $primary = 'id')
    {
        $e_char = self::getEscapeCharacter();

        $stm = self::prepare("SELECT * FROM {$e_char}$table{$e_char} WHERE {$e_char}$primary{$e_char}=? LIMIT 1", [$id]);
        $record = $stm->fetch();
        
        if ($record === false) {
            return null;
        }

        return $record;
    }
    
    public static function prepare($query, array $input_parameters = [])
    {
        $pdo = self::getPDO();
        if (empty($input_parameters)) {
            return $pdo->prepare($query);
        }

        $stm = $pdo->prepare($query);
        $stm->execute($input_parameters);
        return $stm;
    }

    public static function query($query)
    {
        $pdo = self::getPDO();
        return $pdo->query($query);
    }

    public static function del_id($table, $id, $is_virtual = false, $del_column = 'is_del', $primary = 'id')
    {
        $e_char = self::getEscapeCharacter();

        $query = $is_virtual
            ? "UPDATE $table SET {$e_char}$del_column{$e_char}=1 WHERE {$e_char}$primary{$e_char}=?"
            : "DELETE FROM $table WHERE {$e_char}$primary{$e_char}=?";

        if (self::getParams('type') === 'mysql') {
            $query .= ' LIMIT 1';
        }
        
        $stm = self::prepare($query, [$id]);
        return $stm->rowCount();
    }

    public static function rebuild_pos($table, $where = null, $order = null, $column = 'pos')
    {
        $e_char = self::getEscapeCharacter();
        $qOrder = ($order == null) ? "{$e_char}$column{$e_char}, id" : $order;
        $qWhere = ($where == null) ? '' : "WHERE $where";

        $stm = self::query("SELECT * FROM {$e_char}$table{$e_char} $qWhere ORDER BY $qOrder");
        $arr = $stm->fetchAll();

        $query = "UPDATE {$e_char}$table{$e_char} SET {$e_char}$column{$e_char}=? WHERE {$e_char}id{$e_char}=?";
        if (self::getParams('type') === 'mysql') {
            $query .= ' LIMIT 1';
        }

        $stm = self::prepare($query);
        for ($i=0; $i < count($arr); $i++) {
            $stm->execute(array($i+1, $arr[$i]['id']));
        }
    }
    
    public static function change_pos_from_to($table, $where, $posFrom, $posTo, $column = 'pos', $primary = 'id')
    {
        $e_char = self::getEscapeCharacter();
        $posFrom = (int)$posFrom;
        $posTo = (int)$posTo;

        if ($posFrom == $posTo || $posFrom == 0 || $posTo == 0) {
            return false;
        }

        $qW = ($where == null) ? '' : "$where AND";
        
        $stm = self::query("SELECT {$e_char}$primary{$e_char} FROM {$e_char}$table{$e_char} WHERE $qW {$e_char}$column{$e_char}=$posFrom LIMIT 1");
        $id = $stm->fetchColumn();
        
        if ($id === false) {
            return false;
        }
        
        if ($posFrom > $posTo) {
            self::query("UPDATE {$e_char}$table{$e_char} SET {$e_char}$column{$e_char} = {$e_char}$column{$e_char} + 1 WHERE $qW {$e_char}$column{$e_char} >= $posTo AND {$e_char}$column{$e_char} < $posFrom");
        } else {
            self::query("UPDATE {$e_char}$table{$e_char} SET {$e_char}$column{$e_char} = {$e_char}$column{$e_char} - 1 WHERE $qW {$e_char}$column{$e_char} > $posFrom AND {$e_char}$column{$e_char} <= $posTo");
        }
            
        self::prepare("UPDATE {$e_char}$table{$e_char} SET {$e_char}$column{$e_char}=$posTo WHERE {$e_char}$primary{$e_char}=?", [$id]);
        return true;
    }

    public static function change_pos($table, $where, $id, $dir, $order = null, $column = 'pos', $primary = 'id')
    {
        $e_char = self::getEscapeCharacter();
        self::rebuild_pos($table, $where, $order);
        $qWhere = ($where == null) ? '' : "AND $where";
        
        switch ($dir) {
            case 'dup':
                self::prepare("UPDATE {$e_char}$table{$e_char} SET {$e_char}$column{$e_char}=0 WHERE {$e_char}$primary{$e_char}=?", [$id]);
                self::rebuild_pos($table, $where, "{$e_char}$column{$e_char}");
                break;

            case 'ddown':
                $pos = self::max_pos($table, $where) + 1;
                self::prepare("UPDATE {$e_char}$table{$e_char} SET {$e_char}$column{$e_char}='$pos' WHERE {$e_char}$primary{$e_char}=?", [$id]);
                self::rebuild_pos($table, $where, "{$e_char}$column{$e_char}");
                break;

            case 'up':
                $item1 = self::row_by_id($table, $id);
                $pos1 = $item1['pos'];
                $pos2 = $pos1 - 1;
                $stm = self::query("SELECT * FROM {$e_char}$table{$e_char} WHERE {$e_char}$column{$e_char}='$pos2' $qWhere LIMIT 1");

                $item2 = $stm->fetch();
                
                if ($item2 !== false) {
                    self::prepare("UPDATE {$e_char}$table{$e_char} SET {$e_char}$column{$e_char}=? WHERE {$e_char}$primary{$e_char}=?", [$pos2, $item1[$primary]]);
                    self::prepare("UPDATE {$e_char}$table{$e_char} SET {$e_char}$column{$e_char}=? WHERE {$e_char}$primary{$e_char}=?", [$pos1, $item2[$primary]]);
                }
                break;
            
            case 'down':
                $item1 = self::row_by_id($table, $id);
                
                $pos1 = $item1['pos'];
                $pos2 = $pos1 + 1;

                $stm = self::query("SELECT * FROM {$e_char}$table{$e_char} WHERE {$e_char}$column{$e_char}='$pos2' $qWhere LIMIT 1");
                $item2 = $stm->fetch();
                
                if ($item2 !== false) {
                    self::prepare("UPDATE {$e_char}$table{$e_char} SET {$e_char}$column{$e_char}=? WHERE {$e_char}$primary{$e_char}=?", [$pos2, $item1[$primary]]);
                    self::prepare("UPDATE {$e_char}$table{$e_char} SET {$e_char}$column{$e_char}=? WHERE {$e_char}$primary{$e_char}=?", [$pos1, $item2[$primary]]);
                }
                break;
        }
    }
    
    public static function max_pos($table, $where = null, $column = 'pos')
    {
        $e_char = self::getEscapeCharacter();
        $qWhere = ($where == null) ? '' : "WHERE $where";
        $result = self::query("SELECT MAX({$e_char}$column{$e_char}) FROM {$e_char}$table{$e_char} $qWhere");

        return (int)$result->fetchColumn();
    }
    
    public static function reset_pos($table, $order = 'id', $column = 'pos')
    {
        $e_char = self::getEscapeCharacter();
        $result = self::query("SELECT * FROM {$e_char}$table{$e_char} ORDER BY $order");
        $arr = $result->fetchAll();

        for ($i=0; $i < count($arr); $i++) {
            self::prepare("UPDATE {$e_char}$table{$e_char} SET {$e_char}$column{$e_char}=? WHERE id=?", [++$c, $arr[$i]['id']]);
        }
    }
}
