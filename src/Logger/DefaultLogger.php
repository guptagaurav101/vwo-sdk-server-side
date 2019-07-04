<?php
namespace src\Logger;
use Monolog\Logger as Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;
/**
 * 
 */
class DefaultLogger implements LoggerInterface
{
    var $logger;
    public function __construct($minLevel = Logger::INFO, $stream = "",$settings='')
    {
        $formatter = new LineFormatter("[%datetime%] %channel%.%level_name%: %message%\n");
        if(!empty($stream)){
            $streamHandler = new StreamHandler($stream, $minLevel);
        }else{
            $streamHandler = new StreamHandler("php://stdout}", $minLevel);

        }
        $streamHandler->setFormatter($formatter);
        $this->logger = new Logger('VWO-SDK');
        $this->logger->pushHandler($streamHandler);
    }

	public function addLog($msg,$level=Logger::INFO){

        $x=$this->logger->addRecord($level,$msg);

	}
}


