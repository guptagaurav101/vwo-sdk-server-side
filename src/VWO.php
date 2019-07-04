<?php
namespace src;
use \Exception as Exception;
use Ramsey\Uuid\Provider\Node\FallbackNodeProvider;
use src\Utils\Connection as Connection;
use src\Utils\Validations as Validations;
use src\Utils\Constants as Constants;
use src\Logger\DefaultLogger as DefaultLogger;
use Monolog\Logger as Logger;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\Exception\UnsatisfiedDependencyException;

/***
 * Class VWO
 * @package src
 */
Class VWO{
    var $uuid_seed='https://vwo.com';
    var $settings='';
    var $connection;
    var $_logger;
    var $development_mode;


    /**
     * VWO constructor.
     * @param $settings
     * @param LoggerInterface|null $logger
     */
    function __construct($settings,LoggerInterface $logger= null){

        $settings=isset($config['settings'])?$config['settings']:'';
        $logger=isset($config['logger'])?$config['logger']:null;
        $this->development_mode=(isset($config['development_mode']) && $config['development_mode']== 1)?1:0;
        if($logger== null){
            $this->_logger= new DefaultLogger(Logger::INFO,'/var/log/php_errors.log'); //stdout
        }else{
            $this->_logger=$logger;
        }
        $res=Validations::checkSettingSchema($settings);

        if($res) {
            $this->settings=$settings;
            $this->makeRanges();
        }else{
            // logger log
            return [];
        }
        $this->_logger->addLog("========Start logging for VWO Client side sdk for account_id : ".$this->settings['accountId']." ======");
        $this->connection = new Connection();
    }

    /***
     * @param $account_id
     * @param $sdk_key
     * @return bool|mixed
     */
    public static function fetchsettings($account_id,$sdk_key){
        try{
            $connection = new Connection();
            $params = array(
                'a' => $account_id,
                'i' => $sdk_key,
                'r' => time()/10,
                'platform' => 'server-app',
                'api-version' => 2
            );
            return $settings = $connection->get(Constants::SETTINGS_URL, $params);
        }catch(Exception $e){
            return FALSE;
        }
        return FALSE;

    }

    /**
     *
     */
    private function makeRanges()
    {
        if (isset($this->settings['campaigns']) && count($this->settings['campaigns'])) {

            foreach ($this->settings['campaigns'] as $key => $campaign) {
                $offset = 0;
                foreach ($campaign['variations'] as $vkey => $variation) {
                    $limit = BucketService::getLimit($variation['weight']);
                    $max_range = $offset + $limit;
                    $this->settings['campaigns'][$key]['variations'][$vkey]['min_range'] = $offset + 1;
                    $this->settings['campaigns'][$key]['variations'][$vkey]['max_range'] = $max_range;
                    $offset = $max_range;
                }
            }
        }else{
            throw new ExceptionaddLog('unable to fetch campaign data from settings in makeRanges function');
        }
    }
    public function trackGoal($campaign_name,$customerHash,$goal_name){
        try{
            $campaign=$this->validateCampaignName($campaign_name);
            if($campaign!==null){
                $bucketInfo=BucketService::getBucket($customerHash,$campaign);
                $goalId=$this->getGoalId($campaign['goals'],$goal_name);
                if($goalId) {
                    $parameters = array(
                        'account_id' => $this->settings['accountId'],
                        'experiment_id' => $campaign['id'],
                        'ap' => 'server',
                        'uId' => $customerHash,
                        'combination' => $bucketInfo['id'], // variation id
                        'random' => rand(0, 1),
                        'sId' => time(),
                        'u' => $this->getUUId5($customerHash, $this->settings['accountId']),
                        'goal_id' => $goalId
                    );
                    if($this->development_mode){
                        $response['status']='success';
                    }else {
                        $response = $this->connection->get(Constants::GOAL_URL, $parameters);
                    }
                    if( isset($response['status'])  && $response['status'] == 'success'){
                        return true;
                    }
                    $this->_logger->addLog('trackGoal api response is false ',Logger::ERROR);

                }else{
                    $this->_logger->addLog('goal id is missing ',Logger::ERROR);
                }

            }
        }catch(Exception $e){
            $this->_logger->addLog($e->getMessage(),Logger::ERROR);
        }
        return false;
    }

    /**
     * @param $goals
     * @param $goal_name
     * @return int
     */
    private function getGoalId($goals,$goalIdentifier){
        if(count($goals)){
            foreach ($goals as $goal){
                if($goal['identifier']===$goalIdentifier){
                    return $goal['id'];
                }
            }
        }
        return 0;
    }


    /***
     * @param $campaign
     * @param $customerHash
     * @return mixed
     */
    private function addVisitor($campaign,$customerHash,$varientId){
        try{
            $parameters=array(
                'account_id'=>$this->settings['accountId'],
                'experiment_id'=>$campaign['id'],
                'ap'=>'server',
                'uId'=>$customerHash,
                'combination'=>$varientId, // variation id
                'random'=>rand(0,1),
                'sId'=>time(),
                'u'=>$this->getUUId5($customerHash,$this->settings['accountId']),
                'ed'=>'{“p”:“server”}',
            );
            if($this->development_mode){
                $response['status']='success';
            }else {
                $response = $this->connection->get(Constants::TRACK_URL. $parameters);
            }
            if( isset($response['status'])  && $response['status'] == 'success'){
                return true;
            }

        }catch(Exception $e){
            $this->_logger->addLog($e->getMessage(),Logger::ERROR);
        }
        return False;
    }

    /**
     * @param $campaignName
     * @param $customerHash
     * @return null
     */
    public function activate($campaignName,$customerHash){
        return $this->getVariant($campaignName,$customerHash,1);
    }

    /**
     * @param $campaignName
     * @param $customerHash
     * @param int $addVisitor
     * @return null| bucketname
     */
    public function getVariant($campaignName,$customerHash,$addVisitor=0){
        $bucketName=null;
        try{
            // if campai
            $campaign=$this->validateCampaignName($campaignName);
            if($campaign!==null){
                // do murmur operations and get Variation for the customer
                $bucketInfo=BucketService::getBucket($customerHash,$campaign);
                if($addVisitor){
                    $this->addVisitor($campaign,$customerHash,$bucketInfo['id']);
                }
                return $bucketInfo['name'];
            }
        }catch(Exception $e){
            $this->_logger->addLog($e->getMessage(),Logger::ERROR);
        }
        return null;
    }

    /**
     * @param $campaignName
     * @return null
     */
    private function validateCampaignName($campaignName){
        if(isset($this->settings['campaigns']) and count($this->settings['campaigns'])) {
            foreach ($this->settings['campaigns'] as $campaign) {
                if(isset($campaign['status']) && $campaign['status'] !=='RUNNING'){
                    continue;
                }
                if ($campaignName === $campaign['key']) {
                    return $campaign;
                }
            }
        }
        return null ;
    }

    /**
     * @param $name
     * @return string
     */
    private function getUUId5($userid,$accountId){
        try {
            $uuid5_seed = Uuid::uuid5(Uuid::NAMESPACE_DNS, $this->uuid_seed);
            $uuid5_seed_accountId = Uuid::uuid5($uuid5_seed, $accountId);
            $uuid5 = Uuid::uuid5($uuid5_seed_accountId, $userid);
            return strtoupper(str_replace('-','',$uuid5->toString()));

        } catch (UnsatisfiedDependencyException $e) {
            // Some dependency was not met. Either the method cannot be called on a
            // 32-bit system, or it can, but it relies on Moontoast\Math to be present.
            $this->_logger->addLog('UnsatisfiedDependencyException : '.$e->getMessage(),Logger::ERROR);

        }catch (Exception $e) {
            $this->_logger->addLog($e->getMessage(),Logger::ERROR);

        }
        return '';

    }


}