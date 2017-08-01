<?php

use Phalcon\Mvc\View;

use Phalcon\Validation;
use Phalcon\Validation\Validator\Email;

class AcluserController extends BaseAdminController
{
    public $tableName = "acl_user";

    public function indexAction()
    {

    }

    public function registerAction($template = null){
        if(empty($template)){
            $template = __DIR__."/../views/acluser/register";
        }
        $this->view->pick($template);

        if ($this->request->isPost()) {
            if (!$this->security->checkToken()) {
                return $this->dispatcher->forward(["controller" => "index", "action" => "show404"]);
            }
            if(empty($email)){
                $this->flashSession->error("邮箱不能为空");
                header("location:/admin/register");
                exit();
            }
            if(empty($password)){
                $this->flashSession->error("密码不能为空");
                header("location:/admin/register");
                exit();
            }
            if(empty($validate)){
                $this->flashSession->error("验证码不能为空");
                header("location:/admin/register");
                exit();
            }
            $email = $this->getPost("email");
            $password = $this->getPost("password");
            $validate = $this->getPost("validate");

            $salt = uniqid();
            if($this->redis->get("validate-$email") == $validate){
                $this->insert("acl_user", array(
                    "username" => $email,
                    "email" => $email,
                    "password" => sha1($password.$salt),
                    "salt" => $salt,
                ));
                header("location:/admin/dashboard");
                exit();
            }
        }
    }

    public function loginAction($template = null){
        if(empty($template)){
            $template = __DIR__."/../views/acluser/login";
        }
        $this->view->pick($template);
        $redirect_url = $this->getPost("redirect_url", "/admin/dashboard");
        if($this->request->isPost()){

            // check csrf attack
            if (!$this->security->checkToken()) {
                return $this->dispatcher->forward(["controller" => "index", "action" => "show404"]);
            }

            $email = $this->getPost("email");
            $password = $this->getPost("password");

            $keep = $this->getPost("keep");

            $sql = "select * from acl_user where username = '$email' limit 1";
            $user = $this->fetchOne($sql);

            if(empty($user)){
                // 用户不存在
                $this->flashSession->error("用户不存在");
                header("location:/login");exit();
            }
            else if($user["password"] != sha1($password.$user["salt"])){
                // 密码不正确
                $this->flashSession->error("密码不正确");
                header("location:/login");exit();
            }
            else if(empty($user["is_valid"])){
                // 密码不正确
                $this->flashSession->error("用户被锁定");
                header("location:/login");exit();
            }
            else{
                $this->session->set("user", $user);
                if(empty($user["token"])){
                    $token = $this->guid();
                    $this->update("acl_user", array("token" => $token), "id = ".$user["id"]);
                }
                else{
                    $token = $user["token"];
                }
                if($keep == "on"){
                    $this->cookies->set("token", $token, time() + 30 * 86400);
                }
                header("location:$redirect_url");
            }
        }
        else{
            $this->view->setVars(array("redirect_url" => $redirect_url));
        }
    }


    public function logoutAction(){
        $this->session->set("user", null);
        $this->cookies->set("token", null);
        header("location:/login");
    }

    public function profileAction(){
        echo "profileAction";
    }

    public function editProfileAction($template = null){
        $user = $this->getLoginUser();

        if(empty($template)){
            $template = __DIR__."/../views/acluser/editprofile";
        }
        $this->view->pick($template);
        if($this->request->isPost()){
            // check csrf attack
            if (!$this->security->checkToken()) {
                return $this->dispatcher->forward(["controller" => "index", "action" => "show404"]);
            }

            if(empty($user)){
                $this->flashSession->error("用户不存在。");
                header("location:{$this->controller}/list");exit();
            }
            $username = $this->getPost("post-username");
            $name = $this->getPost("post-name");
            $phone = $this->getPost("post-phone");
            $email = $this->getPost("post-email");
            $password = $this->getPost("post-password");

            if(!empty($password) && ((strlen($password) < 6) || (strlen($password) > 32))){
                $this->flashSession->error("密码长度为6到32个字符");
                header("location:");exit();
            }
            $params = array(
                "username" => $username,
                "name" => $name,
                "phone" => empty($phone)?null : $phone,
                "email" => empty($email)?null : $email,
                "password" => sha1($password.$user["salt"]),
            );
            if(empty($password)){
                unset($params["password"]);
            }
            $this->update("acl_user", $params, "id = ".$user["id"]);
            $this->flashSession->success("修改成功");
            header("location: /".$this->controller."/list");
        }
        else{
            $fields = [
                "username" => ['label' => '用户名', 'readonly' => true],
                "name" => ['label' => '姓名'],
                "phone" => ['label' => '手机号'],
                "email" => ['label' => 'email'],
                "password" => ['label' => '密码'],
            ];

            $sql = "select * from $this->tableName where id = {$user["id"]}";
            $record = $this->fetchOne($sql);
            unset($record["password"]);
            if(empty($record)){
                throw new Exception("table not exist");
            }

            foreach($fields as $key => &$field){
                $field["data"] = $record[$key];
            }

            $this->view->setVars([
                "controller" => $this->controller,
                "action" => $this->action,
                "sidebarTemplate" => $this->sidebarTemplate(),
                "headerTemplate" => $this->headerTemplate(),
                "footerTemplate" => $this->footerTemplate(),
                "title" => '修改个人信息',
                "fields" => $fields,
                "data" => $user,
            ]);
        }
    }

    public function changePasswordAction(){
        echo "changePasswordAction";
    }

    public function findPasswordAction(){
        echo "findPasswordAction";
    }

    public function registerSendValidateAction(){
        // 数据有效性验证
        $validation = new Phalcon\Validation();
        $validation->add('email', new Email(array('message' => '电子邮箱格式不正确')));
        $this->checkRequest($validation, $this->request->getPost());

        $expire = 600;   //超时时间10分钟

        $email = $this->getPost("email");

        // 检测用户是否存在
        $sql = "SELECT * FROM acl_user where email = '$email'";
        $user = $this->fetchOne($sql);
        if($user){
            $this->returnError('此用户已存在!');
        }

        if($this->redis->exists("validate-$email")){
            // 如果redis中存在验证码
            $validate = $this->redis->get("validate-$email");
            // 检测验证码发送周期
            if($this->redis->ttl("validate-$email") > $expire - 10){
                $this->returnInfo('至少需要15秒才能重新发送验证码。');
            }
            else{
                $this->redis->setex("validate-$email", $expire, $validate);
            }
        }
        else{
            // 如果redis中不存在，那么创建验证码和时间戳
            $validate = mt_rand(1000, 9999);
            $this->redis->setex("validate-$email", $expire, $validate);
        }
        // 发送邮件
        $result = $this->sendValidateMail($email, $validate);

        $this->returnSuccess($result);
    }

    public function addAction(){
        if(empty($template)){
            $template = __DIR__."/../views/acluser/add";
        }
        $this->view->pick($template);
        if($this->request->isPost()){
            // check csrf attack
            if (!$this->security->checkToken()) {
                return $this->dispatcher->forward(["controller" => "index", "action" => "show404"]);
            }
            $id = $this->getPost("id");

            $username = $this->getPost("post-username");
            $name = $this->getPost("post-name");
            $phone = $this->getPost("post-phone");
            $email = $this->getPost("post-email");
            $password = $this->getPost("post-password");
            $is_valid = $this->getPost("post-is_valid");
            $is_super_admin = $this->getPost("post-is_super_admin");

            if(!empty($password) && ((strlen($password) < 6) || (strlen($password) > 32))){
                $this->flashSession->error("密码长度为6到32个字符");
                header("location:");exit();
            }
            $salt = uniqid();
            $params = array(
                "username" => $username,
                "name" => $name,
                "salt" => $salt,
                "phone" => empty($phone)?null : $phone,
                "email" => empty($email)?null : $email,
                "password" => sha1($password.$salt),
                "is_valid" => empty($is_valid)?0:1,
                "is_super_admin" => empty($is_super_admin)?0:1,
            );
            if(empty($password)){
                unset($params["password"]);
            }
            $this->insert("acl_user", $params);
            $user_id = $this->lastInsertId();
            foreach($_POST['post-checkbox'] as $group_id)
            {
                $this->insert("acl_group_has_user", array("group_id" => $group_id, "user_id" => $user_id));
            }
            $this->flashSession->success("修改成功");
            header("location: /".$this->controller."/list");
        }
        else{
            $fields = [
                "username" => ['label' => '用户名'],
                "name" => ['label' => '姓名'],
                "phone" => ['label' => '手机号'],
                "email" => ['label' => 'email'],
                "password" => ['label' => '密码'],
                "is_valid" => ['label' => '是否有效', 'type' => 'boolean'],
                "is_super_admin" => ['label' => '超级管理员', 'type' => 'boolean'],
            ];

            $sql = "select a.*, 0 as granted from acl_group as a";
            $records = $this->fetchAll($sql);

            $this->view->setVars([
                "controller" => $this->controller,
                "action" => $this->action,
                "sidebarTemplate" => $this->sidebarTemplate(),
                "headerTemplate" => $this->headerTemplate(),
                "footerTemplate" => $this->footerTemplate(),
                "fields" => $fields,
                "title" => "添加新用户",
                "data" => $records,
            ]);
        }
    }

    public function editAction($template = null){
        if(empty($template)){
            $template = __DIR__."/../views/acluser/edit";
        }
        $this->view->pick($template);
        if($this->request->isPost()){
            // check csrf attack
            if (!$this->security->checkToken()) {
                return $this->dispatcher->forward(["controller" => "index", "action" => "show404"]);
            }
            $id = $this->getPost("id");

            $sql = "select * from acl_user where id = $id";
            $user = $this->fetchOne($sql);
            if(empty($user)){
                $this->flashSession->error("用户不存在。");
                header("location:{$this->controller}/list");exit();
            }
            $username = $this->getPost("post-username");
            $name = $this->getPost("post-name");
            $phone = $this->getPost("post-phone");
            $email = $this->getPost("post-email");
            $password = $this->getPost("post-password");
            $is_valid = $this->getPost("post-is_valid");
            $is_super_admin = $this->getPost("post-is_super_admin");

            if(!empty($password) && ((strlen($password) < 6) || (strlen($password) > 32))){
                $this->flashSession->error("密码长度为6到32个字符");
                header("location:");exit();
            }
            $params = array(
                "username" => $username,
                "name" => $name,
                "phone" => empty($phone)?null : $phone,
                "email" => empty($email)?null : $email,
                "password" => sha1($password.$user["salt"]),
                "is_valid" => empty($is_valid)?0:1,
                "is_super_admin" => empty($is_super_admin)?0:1,
            );
            if(empty($password)){
                unset($params["password"]);
            }
            $this->update("acl_user", $params, "id = $id");

            $sql = "delete from acl_group_has_user where user_id = $id";
            $this->execute($sql);
            foreach($_POST['post-checkbox'] as $group_id)
            {
                $this->insert("acl_group_has_user", array("group_id" => $group_id, "user_id" => $id));
            }
            $this->flashSession->success("修改成功");
            header("location: /".$this->controller."/list");
        }
        else{
            $id = $this->getQuery("id");
            $sql = "select a.*, case when b.user_id is null then 0 else 1 end as granted from acl_group as a left join (select * from acl_group_has_user where user_id = $id) as b on a.id = b.group_id ";
            $records = $this->fetchAll($sql);

            $fields = [
                "username" => ['label' => '用户名'],
                "name" => ['label' => '姓名'],
                "phone" => ['label' => '手机号'],
                "email" => ['label' => 'email'],
                "password" => ['label' => '密码'],
                "is_valid" => ['label' => '是否有效', 'type' => 'boolean'],
                "is_super_admin" => ['label' => '超级管理员', 'type' => 'boolean'],
            ];

            $sql = "select * from $this->tableName where id = $id";
            $record = $this->fetchOne($sql);
            unset($record["password"]);
            if(empty($record)){
                throw new Exception("table not exist");
            }

            foreach($fields as $key => &$field){
                $field["data"] = $record[$key];
            }

            $this->view->setVars([
                "controller" => $this->controller,
                "action" => $this->action,
                "sidebarTemplate" => $this->sidebarTemplate(),
                "headerTemplate" => $this->headerTemplate(),
                "footerTemplate" => $this->footerTemplate(),
                "id" => $id,
                "fields" => $fields,
                "data" => $records,
            ]);
        }
    }

    public function listAction(){
        $search_fields = [
            'id'=> ['label' => 'id'],
            "username" => ['label' => '用户名'],
            "name" => ['label' => '姓名'],
            "group_name" => ['label' => '用户组'],
            "phone" => ['label' => '手机号'],
            "email" => ['label' => 'email'],
        ];

        $fields = [
            "id" => ['label' => 'id'],
            "username" => ['label' => '用户名'],
            "name" => ['label' => '姓名'],
            "group_name" => ['label' => '用户组'],
            "phone" => ['label' => '手机号'],
            "email" => ['label' => 'email'],
            "is_valid" => ['label' => '是否有效', 'type' => 'boolean'],
            "is_super_admin" => ['label' => '超级管理员', 'type' => 'boolean'],
            "token" => ['label' => 'token'],
            "created_at" => ['label' => '创建时间'],
            "action" => [
                'label' => '操作',
                'type' => 'actions',
                'actions' => [
                    'show' => ['label' => "显示"],
                    'edit' => ['label' => '编辑'],
                    'remove' => ['label' => '删除']
                ]
            ],
        ];

        $sqlCount = "select count(*) from acl_user where 1";
        $sqlMain = "select * from (select a.*, GROUP_CONCAT(c.name) as group_name from acl_user as a left join acl_group_has_user as b on a.id = b.user_id left join acl_group as c on b.group_id = c.id group by a.id) as a where 1";

        parent::listAction($search_fields, $fields, $sqlCount, $sqlMain);
    }
}

