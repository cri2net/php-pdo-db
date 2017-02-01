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

            try {
                
                self::$pdo = new PDO(
                    "{$defaults['type']}:host={$defaults['host']};dbname={$defaults['name']};charset={$defaults['charset']}",
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
        if (!empty($data)) {
            $str = self::arrayToString($data);
            if ($ignore) {
                self::query("INSERT IGNORE INTO `$table` SET $str");
            } else {
                self::query("INSERT INTO `$table` SET $str");
            }
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
        if (!empty($data)) {
            $str = self::arrayToString($data);
            $stm = self::prepare("UPDATE `$table` SET $str WHERE `$primary`=? LIMIT 1", [$id]);

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
        if (!empty($data)) {
            $str = self::arrayToString($data);
            $stm = self::query("UPDATE `$table` SET $str WHERE $where");

            return $stm->rowCount();
        }
    }
    
    /**
     *  Return last inserted id.
     */
    public static function lastInsertID()
    {
        $pdo = self::getPDO();
        return $pdo->lastInsertId();
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

        foreach ($data as $key => $value) {

            if ($value === null) {
                $str .= "`$key` = NULL, ";
            } else {
                $value = $pdo->quote($value);
                $str .= "`$key` = $value, ";
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
        $query = "SELECT * FROM `$table`";
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
        $stm = self::prepare("SELECT * FROM `$table` WHERE `$primary`=? LIMIT 1", [$id]);
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
        $query = $is_virtual
            ? "UPDATE $table SET `$del_column`=1 WHERE `$primary`=? LIMIT 1"
            : "DELETE FROM $table WHERE `$primary`=? LIMIT 1";
        
        $stm = self::prepare($query, [$id]);
        return $stm->rowCount();
    }

    public static function rebuild_pos($table, $where = null, $order = null, $column = 'pos')
    {
        $qOrder = ($order == null) ? "`$column` ASC, id ASC" : $order;
        $qWhere = ($where == null) ? '' : "WHERE $where";

        $stm = self::query("SELECT * FROM $table $qWhere ORDER BY $qOrder");
        $arr = $stm->fetchAll();

        $stm = self::prepare("UPDATE $table SET `$column`=? WHERE `id`=? LIMIT 1");
        for ($i=0; $i < count($arr); $i++) {
            $stm->execute(array($i+1, $arr[$i]['id']));
        }
    }
    
    public static function change_pos_from_to($table, $where, $posFrom, $posTo, $column = 'pos', $primary = 'id')
    {
        $posFrom = (int)$posFrom;
        $posTo = (int)$posTo;
        if ($posFrom == $posTo || $posFrom == 0 || $posTo == 0) {
            return false;
        }

        $qW = ($where == null) ? '' : "$where AND";
        
        $stm = self::query("SELECT `$primary` FROM $table WHERE $qW `$column`=$posFrom LIMIT 1");
        $id = $stm->fetchColumn();
        
        if ($id === false) {
            return false;
        }
        
        if ($posFrom > $posTo) {
            self::query("UPDATE $table SET `$column` = `$column` + 1 WHERE $qW `$column` >= $posTo AND `$column` < $posFrom");
        } else {
            self::query("UPDATE $table SET `$column` = `$column` - 1 WHERE $qW `$column` > $posFrom AND `$column` <= $posTo");
        }
            
        self::prepare("UPDATE $table SET `$column`=$posTo WHERE `$primary`=? LIMIT 1", [$id]);
        return true;
    }

    public static function change_pos($table, $where, $id, $dir, $order = null, $column = 'pos', $primary = 'id')
    {
        self::rebuild_pos($table, $where, $order);
        $qWhere = ($where == null) ? '' : "AND $where";
        
        switch ($dir) {
            case 'dup':
                self::prepare("UPDATE `$table` SET `$column`=0 WHERE `$primary`=?", [$id]);
                self::rebuild_pos($table, $where, "`$column` ASC");
                break;

            case 'ddown':
                $pos = self::max_pos("$table", $where) + 1;
                self::prepare("UPDATE `$table` SET `$column`='$pos' WHERE `$primary`=?", [$id]);
                self::rebuild_pos($table, $where, "`$column` ASC");
                break;

            case 'up':
                $item1 = self::row_by_id("$table", $id);
                $pos1 = $item1['pos'];
                $pos2 = $pos1 - 1;
                $stm = self::query("SELECT * FROM `$table` WHERE `$column`='$pos2' $qWhere LIMIT 1");

                $item2 = $stm->fetch();
                
                if ($item2 !== false) {
                    self::prepare("UPDATE $table SET `$column`=? WHERE `$primary`=? LIMIT 1", [$pos2, $item1[$primary]]);
                    self::prepare("UPDATE $table SET `$column`=? WHERE `$primary`=? LIMIT 1", [$pos1, $item2[$primary]]);
                }
                break;
            
            case 'down':
                $item1 = self::row_by_id("$table", $id);
                
                $pos1 = $item1['pos'];
                $pos2 = $pos1 + 1;

                $stm = self::query("SELECT * FROM `$table` WHERE `$column`='$pos2' $qWhere LIMIT 1");
                $item2 = $stm->fetch();
                
                if ($item2 !== false) {
                    self::prepare("UPDATE $table SET `$column`=? WHERE `$primary`=? LIMIT 1", [$pos2, $item1[$primary]]);
                    self::prepare("UPDATE $table SET `$column`=? WHERE `$primary`=? LIMIT 1", [$pos1, $item2[$primary]]);
                }
                break;
        }
    }
    
    public static function max_pos($table, $where = null, $column = 'pos')
    {
        $qWhere = ($where == null) ? '' : "WHERE $where";
        $result = self::query("SELECT MAX($column) FROM $table $qWhere");
        return (int)$result->fetchColumn();
    }
    
    public static function reset_pos($table, $order = 'id ASC', $column = 'pos')
    {
        $result = self::query("SELECT * FROM `$table` ORDER BY $order");
        $arr = $result->fetchAll();

        for ($i=0; $i < count($arr); $i++) {
            self::prepare("UPDATE `$table` SET `$column`=? WHERE id=? LIMIT 1", [++$c, $arr[$i]['id']]);
        }
    }
}
