<?php

use DBManager\DB;

define("API_ROOT", "/api");
define("API_VERSION", "1.0");

// Gestion des routes
$route = explode("?", $_SERVER['REQUEST_URI'])[0];

// Treatments
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Method: GET, POST, PUT, PATCH, DELETE, OPTION, HEAD");

$error = null;
preg_match('/\/(?P<apiRoot>[a-zA-Z0-9]+)\/(?P<model>[a-zA-Z0-9]+)(\/(?P<id>[a-zA-Z0-9]+)|\/|)/', $route, $matches);
if (!$matches) {
    $error = new stdClass();
    $error->status=404;
    $error->api_version = API_VERSION;
    $error->message = "Unknow route !";
} else {
    // Verifications
    if ("/".$matches["apiRoot"]!=API_ROOT) {
        $error = new stdClass();
        $error->status=404;
        $error->api_version = API_VERSION;
        $error->message = "Unknow route !";
    } elseif (!isset($matches["model"])) {
        $error = new stdClass();
        $error->status=403;
        $error->api_version = API_VERSION;
        $error->message = "Bad route! The model is unknow.";
    }
    if (!$error) {
        $hasModel = false;
        $db = new DB("testfusion", "root", "Legrand1234$");
        foreach ($db->getTables() as $model) {
            if ($model->getName()==$matches["model"]) {
                $hasModel = true;
                $table = $model;
                break;
            }
        }
        if (!$hasModel) {
            $error = new stdClass();
            $error->status=402;
            $error->api_version = API_VERSION;
            $error->message = "Bad route! The given model '{$matches["model"]}' does not exist.";
        }
        if (!$error) {
            switch ($_SERVER['REQUEST_METHOD']) {
                case 'GET':
                    if (!isset($matches["id"])) {
                        // Get All
                        $response = $table->getAllData();
                    }
                    else {
                        // Get One
                        $id = $matches["id"];
                        $response = $table->getOneData($id);
                    }
                    break;

                case 'POST':
                    $jsonData = file_get_contents("php://input");
                    $data = json_decode(($jsonData=="") ? json_encode($_POST) : $jsonData);
                    $response = $table->saveOneData($data);
                    break;
                
                default:
                    $response = "";
                    break;
            }
            http_response_code(200);
            header("Content-Type: application/json");
            print(json_encode($response));
            exit();
        }
    }
}
if ($error) {
    http_response_code($error->status);
    header("Content-Type: application/json");
    print(json_encode($error));
    exit(); 
}