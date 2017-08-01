<?php
/**
 * Created by PhpStorm.
 * User: thewbb-laptop
 * Date: 17-7-5
 * Time: 下午10:07
 */
class Database{
    public function fetchAll($sql, $cacheTime = null){
        if(empty($cacheTime)){
            return $this->dbQuery->fetchAll($sql, Phalcon\Db::FETCH_ASSOC);
        }
        else{
            if($this->redis->exists("sql:$sql")){
                // 如果redis中存在缓存内容
                return json_decode($this->redis->get("sql:$sql"), true);
            }
            else{
                // 如果redis中不存在
                $value = json_encode($this->dbQuery->fetchAll($sql, Phalcon\Db::FETCH_ASSOC), true);
                $this->redis->setex("sql:$sql", $cacheTime, $value);
                return $value;
            }
        }
    }

    public function fetchOne($sql, $cacheTime = null){
        if(empty($cacheTime)){
            return $this->dbQuery->fetchOne($sql, Phalcon\Db::FETCH_ASSOC);
        }
        else{
            if($this->redis->exists("sql:$sql")){
                // 如果redis中存在缓存内容
                return json_decode($this->redis->get("sql:$sql"), true);
            }
            else{
                // 如果redis中不存在
                $value = json_encode($this->dbQuery->fetchOne($sql, Phalcon\Db::FETCH_ASSOC), true);
                $this->redis->setex("sql:$sql", $cacheTime, $value);
                return $value;
            }
        }
    }

    public function fetchColumn($sql, $cacheTime = null){
        if(empty($cacheTime)){
            return $this->dbQuery->fetchColumn($sql);
        }
        else{
            if($this->redis->exists("sql:$sql")){
                // 如果redis中存在缓存内容
                return $this->redis->get("sql:$sql");
            }
            else{
                // 如果redis中不存在
                $value = $this->dbQuery->fetchColumn($sql);
                $this->redis->setex("sql:$sql", $cacheTime, $value);
                return $value;
            }
        }
    }

    public function execute($sql){
        $sth = $this->dbExecute->prepare($sql);
        $sth->execute();
        return $sth->rowCount();
    }

    function update($table, $data, $where){
        $database_name = $this->database->dbname;
        $records = $this->fetchAll("select COLUMN_NAME from information_schema.COLUMNS where table_name = '$table' and table_schema = '$database_name'");
        if(empty($records)){
            throw new Exception("table not exist");
        }

        // 筛选出数据表中存在的字段
        $fields = array();
        foreach ($data as $key => $value) {
            $index = array_search($key, array_column($records, 'COLUMN_NAME'));
            if(is_numeric($index)){
                $fields[$key] = $value;
            }
        }

        // 拼接sql字符串
        $strFields = "";
        foreach ($fields as $key => $value) {
            $strFields .= "$key = :$key,";
        }
        if(empty($strFields)){
            throw new Exception("error fields");
        }

        if(empty($where)){
            throw new Exception("where must not be null");
        }

        $strFields = substr($strFields, 0, -1);
        $sth = $this->dbExecute->prepare("update $table set $strFields where $where");

        $params = array();
        foreach ($fields as $key => $value) {
            $params[":".$key] = $value;
        }
        // 执行sql
        $sth->execute($params);
        return $sth->rowCount();
    }

    function insert($table, $data, $ignore = false){
        $database_name = $this->database->dbname;
        $records = $this->fetchAll("select COLUMN_NAME from information_schema.COLUMNS where table_name = '$table' and table_schema = '$database_name'");
        if(empty($records)){
            throw new Exception("table not exist");
        }

        // 筛选出数据表中存在的字段
        $fields = array();
        foreach ($data as $key => $value) {
            $index = array_search($key, array_column($records, 'COLUMN_NAME'));
            if(is_numeric($index)){
                $fields[$key] = $value;
            }
        }

        // 拼接sql字符串
        $strFields = "";
        $strValues = "";
        foreach ($fields as $key => $value) {
            $strFields .= "$key,";
            $strValues .= ":$key,";
        }
        if(empty($strFields)){
            throw new Exception("error fields");
        }

        $strIgnore = "";
        if($ignore == true){
            $strIgnore = "ignore";
        }

        $strFields = substr($strFields, 0, -1);
        $strValues = substr($strValues, 0, -1);
        $sth = $this->dbExecute->prepare("insert $strIgnore into $table($strFields) values($strValues)");

        $params = array();
        foreach ($fields as $key => $value) {
            $params[":".$key] = $value;
        }
        // 执行sql
        $sth->execute($params);
        return $this->dbExecute->lastInsertId();
    }

    public function begin(){
        $this->dbExecute->begin();
    }

    public function commit(){
        $this->dbExecute->commit();
    }

    public function rollback(){
        $this->dbExecute->rollback();
    }

    public function lastInsertId(){
        return $this->dbExecute->lastInsertId();
    }

    public function affectedRows(){
        return $this->dbExecute->affectedRows();
    }
}

