<?php
namespace vwo\Utils;
/***
 *
 * All the common function will be invoked from commoin  class
 *
 * Class Common
 * @package vwo\Utils
 */
class Common {

    /***
     *
     * basic creation of log message from constants.php
     *
     * @param $message
     * @param $params
     * @param $className
     * @return mixed
     */

    public static function makelogMesaage($message,$params,$className){
        $params['{file}']=$className;
        $response = str_replace(array_keys($params), array_values($params), $message);
        return $response;

    }

}