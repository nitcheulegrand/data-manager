<?php

use DBManager\DB;

define("DBMERGE_API_ROOT", "/dbmerge");
define("DBMERGE_API_VERSION", "1.0");

// Gestion des routes
$route = explode("?", $_SERVER['REQUEST_URI'])[0];

// Treatments
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Method: GET, POST, PUT, PATCH, DELETE, OPTION, HEAD");

$error = null;
preg_match('/\/(?P<apiRoot>[a-zA-Z0-9]+)\/(?P<action>[a-zA-Z0-9]+)(\/(?P<id>[a-zA-Z0-9]+)|\/|)/', $route, $matches);
if (!$matches) {
    $error = new stdClass();
    $error->status=404;
    $error->api_version = DBMERGE_API_VERSION;
    $error->message = "Unknow route !";
} else {
    // Verifications
    if ("/".$matches["apiRoot"]!=DBMERGE_API_ROOT) {
        $error = new stdClass();
        $error->status=404;
        $error->api_version = DBMERGE_API_VERSION;
        $error->message = "Unknow route !";
    } elseif (!isset($matches["action"])) {
        $error = new stdClass();
        $error->status=403;
        $error->api_version = DBMERGE_API_VERSION;
        $error->message = "Bad route! The action is unknow.";
    }
    if (!$error) {
        $hasModel = false;
        try {
            $db1 = new DB("testfusion", "root", "Legrand1234$");
            $db2 = new DB("testfusion2", "root", "Legrand1234$");
        } catch(Exception $ex) {
            $error = new stdClass();
            $error->status=500;
            $error->api_version = DBMERGE_API_VERSION;
            $error->message = "Failed to connect to one of the databases.";
            $error->exception = $ex;
        }
        if (!$error) {
            switch ($matches['action']) {
                case 'merge':
                    // Synchronisation des structures
                    $response = $db1->merge($db2);
                break;

                case 'db1':
                    // Affichage de la structure de la base de données 1
                    $response = json_decode($db1->__toString());
                break;
                
                case 'db2':
                    // Affichage de la structure de la base de données 2
                    $response = json_decode($db2->__toString());
                break;
                
                case 'test':
                    // Affichage de la structure de la base de données 2
                    $response = "";
                    $db1->getTables()[0]->getRelations();
                break;
                
                case 'tests':
                    // Affichage de la structure de la base de données 2
                    $response = $db1->getTableByName("message")->getTableCreationScript();
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