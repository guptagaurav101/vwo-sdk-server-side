<?php
namespace vwo;
use \Exception as Exception;
use Ramsey\Uuid\Provider\Node\FallbackNodeProvider;
use vwo\Utils\Connection as Connection;
use vwo\Utils\Validations as Validations;
use vwo\Utils\Constants as Constants;
use vwo\Logger\DefaultLogger as DefaultLogger;
use Monolog\Logger as Logger;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\Exception\UnsatisfiedDependencyException;

/***
 * VWO class for clients to connect the sdk
 *
 * Class VWO
 * @package vwo
 */
Class VWO{
    var $uuid_seed='https://vwo.com';
    var $settings='';
    var $connection;
    var $_logger;
    var $_userProfileObj;
    var $development_mode;


    /**
     * VWO constructor for the VWO sdk.
     * @param $config
     * @return object
     */
    function __construct($config){
        if(!is_array($config)){
            $this->addLog(Logger::ERROR,Constants::ERROR_MESSAGE['INVALID_CONFIGURATION']);
            return (object)[];
        }
        // is settings and logger files are provided then set the values to the object
        $settings=isset($config['settings'])?$config['settings']:'';
        $logger=isset($config['logger'])?$config['logger']:null;
        // dev mode enable wont send tracking hits to the servers
        $this->development_mode=(isset($config['development_mode']) && $config['development_mode']== 1)?1:0;
        if($logger== null){
            $this->_logger= new DefaultLogger(Logger::DEBUG,'/var/log/php_errors.log'); //stdout
        }else{
            $this->_logger=$logger;
            $this->addLog(Logger::DEBUG,Constants::DEBUG_MESSAGES['CUSTOM_LOGGER_USED']);
        }
        // user profile service
        if(isset($config['userProfileService']) and is_object($config['userProfileService'])){
            $this->_userProfileObj=clone($config['userProfileService']);
        }else{
            $this->_userProfileObj='';
        }

        // inital logging started for each new object
        $this->addLog(Logger::DEBUG,Constants::DEBUG_MESSAGES['SET_DEVELOPMENT_MODE'],['{devmode}'=>$this->development_mode]);

        $res=Validations::checkSettingSchema($settings);
        if($res) {
            $this->addLog(Logger::DEBUG,Constants::DEBUG_MESSAGES['VALID_CONFIGURATION']);
            $this->settings=$settings;
            $this->makeRanges();
        }else{
            $this->addLog(Logger::ERROR,Constants::ERROR_MESSAGE['SETTINGS_FILE_CORRUPTED']);
            return [];
        }

        $this->connection = new Connection();
        $this->addLog(Logger::DEBUG,Constants::DEBUG_MESSAGES['SDK_INITIALIZED']);
    }
    private function name(){
        return get_class($this);
    }

    /***
     *
     * method to get the settings from the server
     *
     * @param $account_id
     * @param $sdk_key
     * @return bool|mixed
     */
    public static function getSettings($account_id,$sdk_key){
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
    public function track($campaign_name='',$customerHash='',$goal_name=''){
        try{
            if(empty($campaign_name)||empty($customerHash)||empty($goal_name)){
                $this->addLog(Logger::ERROR,Constants::ERROR_MESSAGE['TRACK_API_MISSING_PARAMS']);
                return FALSE;
            }
            $campaign=$this->validateCampaignName($campaign_name);
            if($campaign!==null){
                $bucketInfo=BucketService::getBucket($customerHash,$campaign,$this);
                $goalId=$this->getGoalId($campaign['goals'],$goal_name);
                if($goalId &&  isset($bucketInfo['id']) &&  $bucketInfo['id']>0) {
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
                        $this->addLog(Logger::ERROR,Constants::DEBUG_MESSAGES['IMPRESSION_FOR_TRACK_GOAL'],['{properties}'=>json_decode($parameters)]);
                        return true;
                    }
                    $this->addLog(Logger::ERROR,Constants::ERROR_MESSAGE['IMPRESSION_FAILED'],['{endPoint}'=>'trackGoal']);
                    $this->addLog(Logger::ERROR,Constants::ERROR_MESSAGE['TRACK_API_GOAL_NOT_FOUND'],['{campaignTestKey}'=>$campaign_name,'{userId}'=>$customerHash]);

                }else{
                    $this->addLog(Logger::ERROR,Constants::ERROR_MESSAGE['TRACK_API_GOAL_NOT_FOUND'],['{campaignTestKey}'=>$campaign_name,'{userId}'=>$customerHash]);
                }
            }
        }catch(Exception $e){
            $this->addLog(Logger::ERROR, $e->getMessage());
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
                $response = $this->connection->get(Constants::TRACK_URL, $parameters);
            }
            if( isset($response['status'])  && $response['status'] == 'success'){
                $this->addLog(Logger::ERROR,Constants::DEBUG_MESSAGES['IMPRESSION_FOR_TRACK_USER'],['{properties}'=>json_decode($parameters)]);
                return true;
            }
            $this->addLog(Logger::ERROR,Constants::ERROR_MESSAGE['IMPRESSION_FAILED'],['{endPoint}'=>'addvistior']);

        }catch(Exception $e){
            $this->addLog(Logger::ERROR, $e->getMessage());

        }
        return False;
    }

    /**
     * @param $campaignName
     * @param $customerHash
     * @return null
     */
    public function activate($campaignName,$customerHash){
        return $this->getVariation($campaignName,$customerHash,1);
    }

    /**
     * @param $campaignName
     * @param $customerHash
     * @param int $addVisitor
     * @return null| bucketname
     */
    public function getVariation($campaignName,$customerHash,$addVisitor=0){
        $bucketInfo=null;
        try{
            // if campai
            $campaign=$this->validateCampaignName($campaignName);
            if($campaign!==null){

                try{
                    if(!empty($this->_userProfileObj)){
                        $variationInfo=$this->_userProfileObj->lookup($customerHash,$campaignName);
                        if(isset($variationInfo[$campaignName]['variationName']) && is_string($variationInfo[$campaignName]['variationName'])&& !empty($variationInfo[$campaignName]['variationName']) ){
                            $this->addLog(Logger::INFO,Constants::INFO_MESSAGES['LOOKING_UP_USER_PROFILE_SERVICE']);
                            $bucketInfo=BucketService::getBucketVariationId($campaign,$variationInfo[$campaignName]['variationName']);
                        }else{
                            $this->addLog(Logger::ERROR,Constants::ERROR_MESSAGE['LOOK_UP_USER_PROFILE_SERVICE_FAILED']);

                        }
                    }else{
                        $this->addLog(Logger::DEBUG,Constants::DEBUG_MESSAGES['NO_USER_PROFILE_SERVICE_LOOKUP']);
                    }
                }catch (Exception $e) {
                    $this->addLog(Logger::ERROR,$e->getMessage());
                }

                // do murmur operations and get Variation for the customer
                if($bucketInfo==null){
                    $bucketInfo=BucketService::getBucket($customerHash,$campaign,$this);
                    $this->addLog(Logger::DEBUG,Constants::DEBUG_MESSAGES['NO_STORED_VARIATION'],['{userId}'=>$customerHash,'{campaignTestKey}'=>$campaignName]);
                    try{
                        if(!empty($this->_userProfileObj)){
                            $campaignInfo=$this->getUserProfileSaveData($campaignName,$bucketInfo,$customerHash);
                            $this->_userProfileObj->save($campaignInfo);
                        }else{
                            $this->addLog(Logger::DEBUG,Constants::DEBUG_MESSAGES['NO_USER_PROFILE_SERVICE_SAVE']);
                        }
                    }catch (Exception $e){
                        $this->addLog(Logger::ERROR, $e->getMessage());

                    }
                }else{
                    $this->addLog(Logger::DEBUG,Constants::DEBUG_MESSAGES['GETTING_STORED_VARIATION'],['{userId}'=>$customerHash,'{variationName}'=>$bucketInfo['name'],'{campaignTestKey}'=>$campaignName]);
                }
                if($addVisitor){
                    $this->addVisitor($campaign,$customerHash,$bucketInfo['id']);
                }
                return $bucketInfo['name'];
            }
        }catch(Exception $e){
            $this->addLog(Logger::ERROR,$e->getMessage());
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
            $uuid5_seed = Uuid::uuid5(Uuid::NAMESPACE_URL, $this->uuid_seed);
            $uuid5_seed_accountId = Uuid::uuid5($uuid5_seed, $accountId);
            $uuid5 = Uuid::uuid5($uuid5_seed_accountId, $userid);
            $uuid= strtoupper(str_replace('-','',$uuid5->toString()));
            $this->addLog(Logger::DEBUG,Constants::DEBUG_MESSAGES['UUID_FOR_USER'],['{userid}'=>$userid,'{accountId}'=>$accountId,'{desiredUuid}'=>$uuid]);
            return $uuid;

        } catch (UnsatisfiedDependencyException $e) {
            // Some dependency was ot met. Either the method cannot be called on a
            // 32-bit system, or it can, but it relies on Moontoast\Math to be present.
            $this->addLog(Logger::ERROR,'UnsatisfiedDependencyException : '.$e->getMessage());

        }catch (Exception $e) {
            $this->addLog(Logger::ERROR, $e->getMessage());

        }
        return '';

    }

    private function getUserProfileSaveData($campaignName,$bucketInfo,$customerHash){
        return[
            'userId'=>$customerHash,
            $campaignName=>['variationName'=>$bucketInfo['name']],
        ];

    }
    public function addLog($level,$message,$params=[],$classname=''){
        try{
            if(empty($classname)){
                $classname=$this->name();
            }
            $message=Validations::makelogMesaage($message,$params,$classname);
            $this->_logger->addLog($message,$level);
        }catch (Exception $e){
            error_log($e->getMessage());
        }

    }


}