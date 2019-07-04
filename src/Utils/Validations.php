<?php
namespace src\Utils;
use JsonSchema\SchemaStorage;
use JsonSchema\Validator as Validator;
use JsonSchema\Constraints\Factory;
use JsonSchema\Constraints\Constraint;
/**
 *
 *
 */
class Validations {
static  $jsonSchemaObject = [
                            "type"=> "array",
                            "properties"    => [
                                    "sdkKey"    => ["type"=> "string"],
                                    "version"   => ["type"=> "number"],
                                    "accountId" => ["type"=> "number"],
                                    "campaigns" =>[
                                        'type'      =>'array',
                                        "goals"     => [ "type"=> "array",
                                            "identifier"=> ["type"=> "string"],
                                            "type"      => ["type"=> "string"],
                                            "id"        => ["type"=> "number"],
                                        ],
                                        "variations"=> [
                                            "type"=> "array",
                                            "name"=> ["type"=> "string"],
                                            "weight"=> ["type"=> "number"],
                                            "id"=> ["type"=> "number"],],
                                        "percentTraffic"=> ["type"=> "number"],
                                        "key"   => ["type"=> "string"],
                                        "status"=> ["type"=> "string"],
                                    ],
                            ],



                        ];

    public static function checkSettingSchema($request){
        $schemaStorage = new SchemaStorage();
        $schemaStorage->addSchema('file://mySchema', self::$jsonSchemaObject);
        $jsonValidator = new Validator( new Factory($schemaStorage));
        $jsonValidator->validate($request, self::$jsonSchemaObject,Constraint::CHECK_MODE_VALIDATE_SCHEMA);
        if ($jsonValidator->isValid()) {
            return True;
        } else {
            foreach ($jsonValidator->getErrors() as $error) {
                echo sprintf("[%s] %s\n", $error['property'], $error['message']);
            }
        }
        return FALSE;

    }

}