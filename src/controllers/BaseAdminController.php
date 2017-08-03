<?php

ini_set('display_errors',1);
error_reporting(E_ALL || ~E_NOTICE);
class BaseAdminController extends ControllerBase
{
    public $tableName = "";

    public function beforeExecuteRoute($dispatcher)
    {
        parent::beforeExecuteRoute($dispatcher);
    }

    public function indexAction(){
        echo "admin index";exit();
    }

    public function dashboardAction($template = null){
        if(empty($template)){
            $template = __DIR__."/../views/template/dashboard";
        }
        $this->view->pick($template);
        $this->view->setVars(array(
            "sidebarTemplate" => $this->sidebarTemplate(),
            "headerTemplate" => $this->headerTemplate(),
            "footerTemplate" => $this->footerTemplate(),
            "title" => "我的主页",
        ));
    }

    public function listAction($search_fields = [], $fields = [], $sqlCount = null, $sqlMain = null, $template = null){
        if(empty($template)){
            $template = __DIR__."/../views/template/list";
        }
        $perPage = $this->getQuery("per_page", 25);
        $page = $this->getQuery("page", 1);
        $sortBy = $this->getQuery("sort_by", "id");
        $sortOrder = $this->getQuery("sort_order", "desc");

        $recordCount = 0;
        $pageCount = 0;

        $where = "";
        $searchParam = "";
        foreach($search_fields as $key => &$field){
            switch($field['type']){
                case 'datetime_range':
                    $field['datetime_begin'] = $this->getQuery("search_{$key}_begin", $field['datetime_begin']);
                    $field['datetime_end'] = $this->getQuery("search_{$key}_end", $field['datetime_end']);
                    $searchParam .= "&search_{$key}_begin=".$field['datetime_begin'];
                    $searchParam .= "&search_{$key}_end=".$field['datetime_end'];
                    if(!empty($field['datetime_begin'])){
                        $where .= " and $key > '{$field['datetime_begin']}'";
                    }
                    if(!empty($field['datetime_end'])){
                        $where .= " and $key < '{$field['datetime_end']}'";
                    }
                    break;
                case 'select':
                    $field['data'] = $this->getQuery('search_'.$key, $field['data']);
                    $searchParam .= '&search_'.$key."=".$field['data'];
                    if(isset($field['data'])){
                        if($field['data'] == "0"){
                            $where .= " and $key = 0";
                        }
                        else if($field['data'] == "1"){
                            $where .= " and $key = 1";
                        }
                        else{
                            // select both
                        }
                    }
                    break;
                default:
                    $field['data'] = $this->getQuery('search_'.$key, $field['data']);
                    $searchParam .= '&search_'.$key."=".$field['data'];
                    if(!empty($field['data'])){
                        $where .= " and $key like '%{$field["data"]}%'";
                    }
            }
        }
        if(empty($sqlCount)){
            $sqlCount = "select count(*) from $this->tableName where 1 ";
        }

        if(empty($sqlMain)){
            $sqlMain = "select * from $this->tableName where 1 ";
        }

        $sql = $sqlCount." ".$where;
        $recordCount = $this->fetchColumn($sql);

        $pageCount = ceil($recordCount / $perPage);
        $sql = "$sqlMain $where order by $sortBy $sortOrder limit $perPage offset ".(($page - 1) * $perPage);
        $records = $this->fetchAll($sql);

        $this->redis->setex($this->controller."-".$this->action."-sql", 7200, "$sqlMain $where order by $sortBy $sortOrder");

        if(empty($fields)){
            $database_name = $this->database->dbname;
            $records = $this->fetchAll("select COLUMN_NAME from information_schema.COLUMNS where table_name = '$this->tableName' and table_schema = '$database_name'");
            if(empty($records)){
                throw new Exception("table not exist");
            }

            foreach($records as $field){
                $fields[] = [];
            }
        }

        $this->view->pick($template);
        $this->view->setVars(array(
            "current" => $this,
            "perPage" => $perPage,
            "page" => $page,
            "pageCount" => $pageCount,
            "sortBy" => $sortBy,
            "sortOrder" => $sortOrder,
            "recordCount" => $recordCount,
            "searchFields" => $search_fields,
            "searchParam" => $searchParam,
            "fields" => $fields,
            "list" => $records,
            "title" => "列表",
        ));
    }

    public function showAction($fields = [], $template = null){
        if(empty($template)){
            $template = __DIR__."/../views/template/show";
        }
        $this->view->pick($template);
        $id = $this->getQuery("id");
        $sql = "select * from $this->tableName where id = $id";
        $record = $this->fetchOne($sql);
        if(empty($fields)){
            foreach($record as $key => $value){
                $fields[$key] = ['label' => $key, 'data' => $value];
            }
        }
        else{
            foreach($fields as $key => &$value){
                $value["data"] = $record[$key];
            }
        }

        $this->view->setVars(array(
            "current" => $this,
            "fields" => $fields,
            "title" => "详情",
        ));
    }

    public function addAction($fields = [], $template = null){
        if(empty($template)){
            $template = __DIR__."/../views/template/add";
        }
        $this->view->pick($template);
        if($this->request->isPost()){
            // check csrf attack
            if (!$this->security->checkToken()) {
                return $this->dispatcher->forward(["controller" => "index", "action" => "show404"]);
            }

            $params = [];
            foreach($fields as $key => $field){
                $param = $this->getPost("post-".$key);
                if(!empty($param)){
                    $params[$key] = $param;
                }
            }

            $this->insert($this->tableName, $params);
            $this->flashSession->success("添加成功！");
            header("location: /".$this->controller."/list");
        }
        else{
            $database_name = $this->database->dbname;
            $records = $this->fetchAll("select * from information_schema.COLUMNS where table_name = '$this->tableName' and table_schema = '$database_name'");
            if(empty($records)){
                throw new Exception("table not exist");
            }

            $this->view->setVars(array(
                "current" => $this,
                "fields" => $fields,
                "title" => "添加",
            ));
        }
    }

    public function editAction($fields = [], $template = null){
        if(empty($template)){
            $template = __DIR__."/../views/template/edit";
        }
        $this->view->pick($template);

        if(empty($fields)){
            $database_name = $this->database->dbname;
            $sql = "select COLUMN_NAME from information_schema.COLUMNS where table_name = '$this->tableName' and table_schema = '$database_name'";
            $records = $this->fetchAll($sql);
            foreach($records as $record){
                $fields[$record["COLUMN_NAME"]] = ['label' => $record["COLUMN_NAME"]];
            }
        }

        if($this->request->isPost()){
            // check csrf attack
            if (!$this->security->checkToken()) {
                return $this->dispatcher->forward(["controller" => "index", "action" => "show404"]);
            }
            $id = $this->getPost("id");
            $params = [];

            foreach($fields as $key => $field){
                $param = $this->getPost("post-".$key);
                if(isset($param)){
                    if(empty($param)){
                        $params[$key] = null;
                    }
                    else{
                        $params[$key] = $param;
                    }
                }
            }
            foreach($fields as $k => $v){
                if($v["type"] == "boolean"){
                    if($params[$k] == "on"){
                        $params[$k] = 1;
                    }
                    else{
                        $params[$k] = 0;
                    }
                }
            }

            $this->update($this->tableName, $params, "id = $id");
            $this->flashSession->success("修改成功！");
            header("location: /".$this->controller."/list");
        }
        else{
            $id = $this->getQuery("id");
            $sql = "select * from $this->tableName where id = $id";
            $record = $this->fetchOne($sql);
            if(empty($record)){
                throw new Exception("table not exist");
            }

            foreach($fields as $key => &$field){
                $field["data"] = $record[$key];
            }

            $this->view->setVars(array(
                "current" => $this,
                "id" => $id,
                "fields" => $fields,
                "title" => "修改",
            ));
        }
    }

    public function removeAction(){
        $this->getLoginUser();
        $id = $this->getQuery("id");
        $sql = "delete from $this->tableName where id = $id";
        $rowCount = $this->execute($sql);
        $this->flashSession->success("删除成功！$rowCount 条");
        header("location: /".$this->controller."/list");
    }

    public function exportAction($fields = []){
        $sql = $this->redis->get($this->controller."-list-sql");
        $records = $this->fetchAll($sql);


        // Create new PHPExcel object
        $objPHPExcel = new PHPExcel();
        $sheet = $objPHPExcel->setActiveSheetIndex(0);

        if(empty($fields)){
            if(empty($records)){
                // 返回空excel
                header('Content-Type: application/vnd.ms-excel');
                header('Content-Disposition: attachment;filename="export_'.$this->controller."_".date("YmdHis").'.xls"');
                header('Cache-Control: max-age=0');
                $objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel5');
                $objWriter->save('php://output');
                exit;
            }

            // 为空时，默认输出sql语句的查询字段名
            foreach($records[0] as $key => $value){
                $fields[$key] = ['label' => $key];
            }
        }

        $column = "A";
        foreach($fields as $key => $value){
            $sheet->setCellValue($column++.'1', $value['label']);
        }

        for($i = 0; $i < count($records); $i++){
            $column = "A";
            foreach($fields as $key => $value){
                $sheet->setCellValue($column++.($i+2), $records[$i][$key]);
            }
        }

        // Redirect output to a client’s web browser (Excel5)
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment;filename="export_'.$this->controller."_".date("YmdHis").'.xls"');
        header('Cache-Control: max-age=0');

        $objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel5');
        $objWriter->save('php://output');
        exit;
    }

    public function batchAction(){
        echo "batchAction";exit();
    }

    public function headerTemplate($params = [], $template = null){
        if(empty($template)){
            $template = __DIR__."/../views/template/header";
        }
        $user = $this->getLoginUser();
        $params["user"] = $user;

        $view = new \Phalcon\Mvc\View\Simple();
        return $view->render($template, $params);
    }

    public function footerTemplate($params = [], $template = null){
        if(empty($template)){
            $template = __DIR__."/../views/template/footer";
        }
        $view = new \Phalcon\Mvc\View\Simple();
        return $view->render($template, $params);
    }

    public function sidebarTemplate($params = [], $template = null){
        if(empty($template)){
            $template = __DIR__."/../views/template/sidebar";
        }

        $sql = "select id, name from acl_permission_classification order by order_id";
        $groups = $this->fetchAll($sql);

        $user = $this->getLoginUser();
        if($user["is_super_admin"]){
            foreach($groups as &$group){
                $sql = "select *
                    from acl_permission
                    where group_id = {$group["id"]}
                    and display = 1
                    order by order_id";
                $permissions = $this->fetchAll($sql);
                $group["permissions"] = $permissions;
            }
        }
        else{
            foreach($groups as &$group){
                $sql = "select a.*
                    from acl_permission as a
                    left join acl_group_has_permission as b on a.id = b.permission_id
                    left join acl_group_has_user as c on b.group_id = c.group_id
                    where a.group_id = {$group["id"]}
                    and a.display = 1
                    and c.user_id = {$user["id"]}
                    group by a.id
                    order by a.order_id";
                $permissions = $this->fetchAll($sql);
                $group["permissions"] = $permissions;
            }

            for($i = count($groups) - 1; $i >= 0; $i--){
                if(empty($groups[$i]["permissions"])){

                    unset($groups[$i]);
                }
            }
        }

        $params["groups"] = $groups;
        $params["user"] = $user;

        $view = new \Phalcon\Mvc\View\Simple();

        return $view->render($template, $params);
    }

    public function getType($type){
        switch($type){
            // 数字类型
            case "int":
            case "tinyint":
            case "smallint":
            case "mediumint":
            case "bigint":
            case "decimal":
            case "float":
            case "double":
                return "num";
                break;
            // 字符类型
            case "char":
            case "varchar":
            case "tinytext":
            case "text":
            case "mediumtext":
            case "longtext":
                return "string";
                break;
            // 二进制类型
            case "bit":
            case "binary":
            case "varbinary":
            case "tinyblob":
            case "mediumblob":
            case "blob":
            case "longblob":
                return "binary";
                break;
//            // 枚举(单选)
//            case "enum":
//            // 集合(多选)
//            case "set":
//            // 时间类型
//            case "year":
//            case "date":
//            case "datetime":
//            case "time":
//            case "timestamp":
//
            default:
                return $type;
        }
    }
}

