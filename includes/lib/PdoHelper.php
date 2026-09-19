<?php
/**
 * PDO 封装类
 * 统一 MySQL / SQLite 的连接、预处理查询与错误处理。
 */

// Small PDO wrapper with MySQL and SQLite support.
namespace lib;

class PdoHelper
{
    private $sqlPrefix = 'pre_';
    private $db;
    private $fetchStyle = \PDO::FETCH_ASSOC;
    private $prefix;
    private $errorInfo;
    private $isMysql = false;

    public function __construct($dsn, $user = '', $pass = '', $dbqz = '')
    {
        $this->prefix = $dbqz ? $dbqz . '_' : '';

        if (is_string($dsn) && strpos($dsn, ':') === false) {
            $file = $dsn;
            $dir = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            try {
                $this->db = new \PDO('sqlite:' . $file);
            } catch (\Exception $e) {
                error_log('SQLite connection failed: ' . $e->getMessage());
                throw new \RuntimeException('数据库连接失败');
            }
            $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
            $this->db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            $this->db->exec('PRAGMA foreign_keys = ON;');
            return;
        }

        $this->isMysql = (stripos($dsn, 'mysql:') === 0);
        try {
            $this->db = new \PDO($dsn, $user, $pass, array(
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_SILENT,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ));
        } catch (\Exception $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            throw new \RuntimeException('数据库连接失败');
        }

        if ($this->isMysql) {
            $this->db->exec('SET NAMES utf8mb4');
            $this->db->exec("SET sql_mode = ''");
        } else {
            $this->db->exec('PRAGMA foreign_keys = ON;');
        }
    }

    public function isMysql()
    {
        return $this->isMysql;
    }

    public function setFetchStyle($_style)
    {
        $this->fetchStyle = $_style;
    }

    private function nowExpr($token)
    {
        if ($this->isMysql) {
            return $token; // MySQL 原生 NOW()/CURDATE()/CURTIME()
        }
        if ($token === 'CURDATE()') {
            return "date('now')";
        }
        if ($token === 'CURTIME()') {
            return "time('now')";
        }
        return "datetime('now')";
    }

    private function dealPrefix($_sql)
    {
        return str_replace($this->sqlPrefix, $this->prefix, $_sql);
    }

    private function _where($conditions)
    {
        $result = array('_where' => ' ', '_bindParams' => array());
        if (is_array($conditions) && !empty($conditions)) {
            $fields = array();
            $sql = null;
            $join = array();
            if (isset($conditions[0]) && $sql = $conditions[0]) {
                unset($conditions[0]);
            }
            foreach ($conditions as $key => $condition) {
                if (substr($key, 0, 1) != ':') {
                    unset($conditions[$key]);
                    $conditions[':' . $key] = $condition;
                }
                $join[] = '`' . $key . '` = :' . $key;
            }
            if (!$sql) {
                $sql = join(' AND ', $join);
            }
            $result['_where'] = ' WHERE ' . $sql;
            $result['_bindParams'] = $conditions;
        } elseif (!empty($conditions)) {
            $result['_where'] = ' WHERE ' . $conditions;
        }
        return $result;
    }

    private function _select($table, $fields = '*', $where = array(), $sort = null, $limit = null)
    {
        $sort = !empty($sort) ? ' ORDER BY ' . $sort : '';
        $fields = !empty($fields) ? $fields : '*';
        if (is_array($fields)) {
            $fields = implode(',', $fields);
        }
        $conditions = $this->_where($where);
        $sql = ' FROM pre_' . $table . $conditions['_where'];
        if (is_array($limit)) {
            $limit = ' LIMIT ' . $limit[0] . ',' . $limit[1];
        } elseif (!empty($limit)) {
            $limit = ' LIMIT ' . $limit;
        } else {
            $limit = '';
        }
        return array('sql' => 'SELECT ' . $fields . $sql . $sort . $limit, 'bind' => $conditions['_bindParams']);
    }

    public function find($table, $fields = '*', $where = array(), $sort = null, $limit = null)
    {
        $sql_arr = $this->_select($table, $fields, $where, $sort, $limit);
        return $this->getRow($sql_arr['sql'], $sql_arr['bind']);
    }

    public function findAll($table, $fields = '*', $where = array(), $sort = null, $limit = null)
    {
        $sql_arr = $this->_select($table, $fields, $where, $sort, $limit);
        return $this->getAll($sql_arr['sql'], $sql_arr['bind']);
    }

    public function findColumn($table, $fields, $where = array(), $sort = null)
    {
        $sql_arr = $this->_select($table, $fields, $where, $sort, 1);
        return $this->getColumn($sql_arr['sql'], $sql_arr['bind']);
    }

    public function insert($table, $data, $replace = false)
    {
        $keys = array();
        $marks = array();
        $values = array();
        foreach ($data as $k => $v) {
            $keys[] = '`' . $k . '`';
            if ($v === 'NOW()' || $v === 'CURDATE()' || $v === 'CURTIME()') {
                $marks[] = $this->nowExpr($v);
            } elseif ($v === null || $v === false) {
                $marks[] = 'NULL';
            } else {
                $values[':' . $k] = $v;
                $marks[] = ':' . $k;
            }
        }
        $rowCount = $this->exec(($replace ? 'REPLACE' : 'INSERT') . ' INTO pre_' . $table . ' (' . implode(', ', $keys) . ') VALUES (' . implode(', ', $marks) . ')', $values);
        if ($rowCount) {
            return $this->lastInsertId();
        }
        return false;
    }

    public function update($table, $data, $where)
    {
        if (is_array($data) && !empty($data)) {
            $values = array();
            $setstr = array();
            foreach ($data as $k => $v) {
                if ($v === 'NOW()' || $v === 'CURDATE()' || $v === 'CURTIME()') {
                    $setstr[] = '`' . $k . '` = ' . $this->nowExpr($v);
                } elseif ($v === null || $v === false) {
                    $setstr[] = '`' . $k . '` = NULL';
                } else {
                    $values[':M_UPDATE_' . $k] = $v;
                    $setstr[] = '`' . $k . '` = :M_UPDATE_' . $k;
                }
            }
            $update = implode(', ', $setstr);
        } elseif (!empty($data)) {
            $update = $data;
        } else {
            return false;
        }
        $conditions = $this->_where($where);
        $rowCount = $this->exec('UPDATE pre_' . $table . ' SET ' . $update . $conditions['_where'], $conditions['_bindParams'] + $values);
        return $rowCount;
    }

    public function delete($table, $where)
    {
        $conditions = $this->_where($where);
        $rowCount = $this->exec('DELETE FROM pre_' . $table . $conditions['_where'], $conditions['_bindParams']);
        return $rowCount;
    }

    public function count($table, $where)
    {
        $conditions = $this->_where($where);
        return $this->getColumn('SELECT COUNT(*) FROM pre_' . $table . $conditions['_where'], $conditions['_bindParams']);
    }

    public function exec($_sql, $_array = null)
    {
        $_sql = $this->dealPrefix($_sql);
        if (is_array($_array)) {
            $stmt = $this->db->prepare($_sql);
            if ($stmt) {
                $result = $stmt->execute($_array);
                if ($result !== false) {
                    return $stmt->rowCount();
                }
                $this->errorInfo = $stmt->errorInfo();
                return false;
            }
            $this->errorInfo = $this->db->errorInfo();
            return false;
        }
        $result = $this->db->exec($_sql);
        if ($result !== false) {
            return $result;
        }
        $this->errorInfo = $this->db->errorInfo();
        return false;
    }

    public function query($_sql, $_array = null)
    {
        $_sql = $this->dealPrefix($_sql);
        if (is_array($_array)) {
            $stmt = $this->db->prepare($_sql);
            if ($stmt && $stmt->execute($_array)) {
                return $stmt;
            }
            $this->errorInfo = $stmt ? $stmt->errorInfo() : $this->db->errorInfo();
            return false;
        }
        if ($stmt = $this->db->query($_sql)) {
            return $stmt;
        }
        $this->errorInfo = $this->db->errorInfo();
        return false;
    }

    public function getRow($_sql, $_array = null)
    {
        $stmt = $this->query($_sql, $_array);
        return $stmt ? $stmt->fetch($this->fetchStyle) : false;
    }

    public function getAll($_sql, $_array = null)
    {
        $stmt = $this->query($_sql, $_array);
        return $stmt ? $stmt->fetchAll($this->fetchStyle) : false;
    }

    public function getColumn($_sql, $_array = null)
    {
        $stmt = $this->query($_sql, $_array);
        return $stmt ? $stmt->fetchColumn() : false;
    }

    public function lastInsertId()
    {
        return $this->db->lastInsertId();
    }

    public function error()
    {
        $error = $this->errorInfo;
        return $error ? '[' . $error[1] . ']' . $error[2] : null;
    }

    public function beginTransaction()
    {
        return $this->db->beginTransaction();
    }

    public function commit()
    {
        return $this->db->commit();
    }

    public function rollBack()
    {
        return $this->db->rollBack();
    }

    public function __get($name)
    {
        return $this->$name;
    }

    public function __destruct()
    {
        $this->db = null;
    }
}
