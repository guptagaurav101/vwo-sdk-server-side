<?php
namespace vwo\Utils;
/**
 *
 *
 */
class Common {

    public static function makelogMesaage($message,$params,$className){
        $params['{file}']=$className;
        $response = str_replace(array_keys($params), array_values($params), $message);
        return $response;

    }

}