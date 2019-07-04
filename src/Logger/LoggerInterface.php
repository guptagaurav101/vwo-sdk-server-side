<?php
namespace src\Logger;
interface LoggerInterface{
	public function addLog($msg,$level);
}