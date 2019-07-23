<?php
namespace vwo;
use \Exception as Exception;
use vwo\Logger\LoggerInterface;
use vwo\Utils\Connection as Connection;
use vwo\Utils\UserProfileInterface;
use vwo\Utils\Validations as Validations;
use vwo\Utils\Constants as Constants;
use vwo\Utils\Common as Common;
use vwo\Logger\DefaultLogger as DefaultLogger;
use vwo\Logger\Loggers as Loggers;
use Monolog\Logger as Logger;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\Exception\UnsatisfiedDependencyException;

/***
 * VWO sdk class
 *
 * Class VWO
 *
 * It helps in making client object and use the sdk
 * @package vwo
 * @author Gaurav Gupta
 * @version 1.0
 * @copyright never
 */
Class VWO
{
    /**
     * @var string to save uuid basic seed
     */
    var $uuidSeed=Constants::UUID_SEED;
    /**
     * @var mixed|string to save settings
     */
    var $settings='';
    /**
     * @var Connection to save connection object for curl requests
     */
    var $connection;
    /**
     * @var mixed|null|LoggerInterface
     * to save loggerinterface object
     */
    static $_logger;
    /**
     * @var string to save userprofile interface object
     */
    var $_userProfileObj;
    /**
     * @var int to save if dev mode is enabled or not
     */
    var $developmentMode;


    /**
     * VWO constructor for the VWO sdk.
     *
     * @param  $config
     * @return object
     */
    function __construct($config)
    {
        if (!is_array($config)) {
            return (object)[];
        }
        // is settings and logger files are provided then set the values to the object
        $settings = isset($config['settings'])?$config['settings']:'';
        $logger=isset($config['logger'])?$config['logger']:null;

        // dev mode enable wont send tracking hits to the servers
        $this->developmentMode=(isset($config['developmentMode']) && $config['developmentMode']== 1)?1:0;

        if ($logger== null) {
            self::$_logger = new DefaultLogger(Logger::DEBUG, '/var/log/php_errors.log'); //stdout
        } elseif($logger instanceof LoggerInterface) {
            self::$_logger=$logger;
           self::addLog(Logger::DEBUG, Constants::DEBUG_MESSAGES['CUSTOM_LOGGER_USED']);
        }

        // user profile service
        if(isset($config['userProfileService']) and ($config['userProfileService'] instanceof UserProfileInterface)) {
            $this->_userProfileObj=clone($config['userProfileService']);
        }else{
            $this->_userProfileObj='';
        }

        // initial logging started for each new object
       self::addLog(Logger::DEBUG, Constants::DEBUG_MESSAGES['SET_DEVELOPMENT_MODE'], ['{devmode}'=>$this->developmentMode]);

        $res=Validations::checkSettingSchema($settings);
        if($res) {
           self::addLog(Logger::DEBUG, Constants::DEBUG_MESSAGES['VALID_CONFIGURATION']);
            $this->settings=$settings;
            $this->makeRanges();
        }else{
           self::addLog(Logger::ERROR, Constants::ERROR_MESSAGE['SETTINGS_FILE_CORRUPTED']);
            return [];
        }

        $this->connection = new Connection();
       self::addLog(Logger::DEBUG, Constants::DEBUG_MESSAGES['SDK_INITIALIZED']);
    }
    static function name()
    {
        return 'vwo\VWO';
    }

    /***
     * method to get the settings from the server
     *
     * @param  $accountId
     * @param  $sdk_key
     * @return bool|mixed
     */
    public static function getSettings($accountId,$sdkKey)
    {
        try{
            $connection = new Connection();
            $params = array(
                'a' => $accountId,
                'i' => $sdkKey,
                'r' => time()/10,
                'platform' => 'server',
                'api-version' => 2
            );
            return $settings = $connection->get(Constants::SETTINGS_URL, $params);
        }catch(Exception $e){
            return FALSE;
        }
        return FALSE;

    }

    /**
     * set the ranges of all the campaigns
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


    /***
     *
     * API for track the user goals and revenue
     *
     * @param string $campaignKey
     * @param string $userId
     * @param string $goalName
     * @param string $revenue
     * @return bool
     */
    public function track($campaignKey='',$userId='',$goalName='',$revenue='')
    {
        try{
            if(empty($campaignKey)||empty($userId)||empty($goalName)) {
               self::addLog(Logger::ERROR, Constants::ERROR_MESSAGE['TRACK_API_MISSING_PARAMS']);
                return FALSE;
            }
            $campaign=$this->validateCampaignName($campaignKey);
            if($campaign!==null) {
                $bucketInfo=BucketService::getBucket($userId, $campaign);
                $goalId=$this->getGoalId($campaign['goals'], $goalName);
                if($goalId &&  isset($bucketInfo['id']) &&  $bucketInfo['id']>0) {
                    if($this->developmentMode) {
                        $response['status']='success';
                    }else {
                        $parameters = array(
                            'account_id' => $this->settings['accountId'],
                            'experiment_id' => $campaign['id'],
                            'ap' => 'server',
                            'uId' => $userId,
                            'combination' => $bucketInfo['id'],
                            'random' => rand(0, 1),
                            'sId' => time(),
                            'u' => $this->getUUId5($userId, $this->settings['accountId']),
                            'goal_id' => $goalId,
                            'r'=>is_string($revenue) || is_float($revenue) || is_int($revenue)?$revenue:''
                        );
                        $response = $this->connection->get(Constants::GOAL_URL, $parameters);
                    }
                    if(isset($response['status'])  && $response['status'] == 'success') {
                       self::addLog(Logger::ERROR, Constants::DEBUG_MESSAGES['IMPRESSION_FOR_TRACK_GOAL'], ['{properties}'=>json_encode($parameters)]);
                        return TRUE;
                    }
                   self::addLog(Logger::ERROR, Constants::ERROR_MESSAGE['IMPRESSION_FAILED'], ['{endPoint}'=>'trackGoal']);
                   self::addLog(Logger::ERROR, Constants::ERROR_MESSAGE['TRACK_API_GOAL_NOT_FOUND'], ['{campaignTestKey}'=>$campaignKey,'{userId}'=>$userId]);

                }else{
                   self::addLog(Logger::ERROR, Constants::ERROR_MESSAGE['TRACK_API_GOAL_NOT_FOUND'], ['{campaignTestKey}'=>$campaignKey,'{userId}'=>$userId]);
                }
            }
        }catch(Exception $e){
           self::addLog(Logger::ERROR, $e->getMessage());
        }
        return FALSE;
    }

    /**
     *
     * To fetch the goal id using goals array and goal identifier
     *
     * @param  $goals
     * @param  $goalIdentifier
     * @return int
     */
    private function getGoalId($goals,$goalIdentifier)
    {
        if(count($goals)) {
            foreach ($goals as $goal){
                if($goal['identifier']===$goalIdentifier) {
                    return $goal['id'];
                }
            }
        }
        return 0;
    }


    /***
     *
     * API to send add visitor hit to vwo
     *
     * @param  $campaign
     * @param  $customerHash
     * @return mixed
     */
    private function addVisitor($campaign,$userId,$varientId)
    {
        try{
            if($this->developmentMode) {
                $response['status']='success';
            }else {
                $parameters=array(
                    'account_id'=>$this->settings['accountId'],
                    'experiment_id'=>$campaign['id'],
                    'ap'=>'server',
                    'uId'=>$userId,
                    'combination'=>$varientId, // variation id
                    'random'=>rand(0, 1),
                    'sId'=>time(),
                    'u'=>$this->getUUId5($userId, $this->settings['accountId']),
                    'ed'=>'{“p”:“server”}',
                );

                $response = $this->connection->get(Constants::TRACK_URL, $parameters);
            }
            if(isset($response['status'])  && $response['status'] == 'success') {
               self::addLog(Logger::ERROR, Constants::DEBUG_MESSAGES['IMPRESSION_FOR_TRACK_USER'], ['{properties}'=>json_encode($parameters)]);
                return TRUE;
            }
           self::addLog(Logger::ERROR, Constants::ERROR_MESSAGE['IMPRESSION_FAILED'], ['{endPoint}'=>'addvistior']);

        }catch(Exception $e){
           self::addLog(Logger::ERROR, $e->getMessage());

        }
        return FALSE;
    }

    /**
     *
     * to send variation name along with api hit to send add visitor hit
     *
     * @param  $campaignKey
     * @param  $userId
     * @return string|null
     */
    public function activate($campaignKey,$userId)
    {
        return $this->getVariation($campaignKey, $userId, 1);
    }

    /**
     *
     * fetch the variation name
     *
     * @param  $campaignKey
     * @param  $customerHash
     * @param  int $addVisitor
     * @return null| bucketname
     */
    public function getVariation($campaignKey,$userId,$addVisitor=0)
    {
        $bucketInfo=null;
        try{
            // if campai
            $campaign=$this->validateCampaignName($campaignKey);
            if($campaign!==null) {

                try{
                    if(!empty($this->_userProfileObj)) {
                        $variationInfo=$this->_userProfileObj->lookup($userId, $campaignKey);
                        if(isset($variationInfo[$campaignKey]['variationName']) && is_string($variationInfo[$campaignKey]['variationName'])&& !empty($variationInfo[$campaignKey]['variationName']) ) {
                           self::addLog(Logger::INFO, Constants::INFO_MESSAGES['LOOKING_UP_USER_PROFILE_SERVICE']);
                            $bucketInfo=BucketService::getBucketVariationId($campaign, $variationInfo[$campaignKey]['variationName']);
                        }else{
                           self::addLog(Logger::ERROR, Constants::ERROR_MESSAGE['LOOK_UP_USER_PROFILE_SERVICE_FAILED']);

                        }
                    }else{
                       self::addLog(Logger::DEBUG, Constants::DEBUG_MESSAGES['NO_USER_PROFILE_SERVICE_LOOKUP']);
                    }
                }catch (Exception $e) {
                   self::addLog(Logger::ERROR, $e->getMessage());
                }

                // do murmur operations and get Variation for the customer
                if($bucketInfo==null) {
                    $bucketInfo=BucketService::getBucket($userId, $campaign);
                   self::addLog(Logger::DEBUG, Constants::DEBUG_MESSAGES['NO_STORED_VARIATION'], ['{userId}'=>$userId,'{campaignTestKey}'=>$campaignKey]);
                    try{
                        if(!empty($this->_userProfileObj)) {
                            $campaignInfo=$this->getUserProfileSaveData($campaignKey, $bucketInfo, $userId);
                            $this->_userProfileObj->save($campaignInfo);
                        }else{
                           self::addLog(Logger::DEBUG, Constants::DEBUG_MESSAGES['NO_USER_PROFILE_SERVICE_SAVE']);
                        }
                    }catch (Exception $e){
                       self::addLog(Logger::ERROR, $e->getMessage());

                    }
                }else{
                   self::addLog(Logger::DEBUG, Constants::DEBUG_MESSAGES['GETTING_STORED_VARIATION'], ['{userId}'=>$userId,'{variationName}'=>$bucketInfo['name'],'{campaignTestKey}'=>$campaignKey]);
                }
                if($addVisitor) {
                    $this->addVisitor($campaign, $userId, $bucketInfo['id']);
                }
                return $bucketInfo['name'];
            }
        }catch(Exception $e){
           self::addLog(Logger::ERROR, $e->getMessage());
        }
        return null;
    }

    /**
     *
     * function to check if the campaignkey exists in campign array from settings
     *
     *
     * @param  $campaignKey
     * @return null
     */
    private function validateCampaignName($campaignKey)
    {
        if(isset($this->settings['campaigns']) and count($this->settings['campaigns'])) {
            foreach ($this->settings['campaigns'] as $campaign) {
                if(isset($campaign['status']) && $campaign['status'] !=='RUNNING') {
                    continue;
                }
                if ($campaignKey === $campaign['key']) {
                    return $campaign;
                }
            }
        }
        return null ;
    }

    /**
     *
     * create a uuid
     *
     * @param  $name
     * @return string
     */
    private function getUUId5($userId,$accountId)
    {
        try {
            $uuid5_seed = Uuid::uuid5(Uuid::NAMESPACE_URL, $this->uuidSeed);
            $uuid5_seed_accountId = Uuid::uuid5($uuid5_seed, $accountId);
            $uuid5 = Uuid::uuid5($uuid5_seed_accountId, $userId);
            $uuid= strtoupper(str_replace('-', '', $uuid5->toString()));
           self::addLog(Logger::DEBUG, Constants::DEBUG_MESSAGES['UUID_FOR_USER'], ['{userid}'=>$userId,'{accountId}'=>$accountId,'{desiredUuid}'=>$uuid]);
            return $uuid;

        } catch (UnsatisfiedDependencyException $e) {
            // Some dependency was ot met. Either the method cannot be called on a
            // 32-bit system, or it can, but it relies on Moontoast\Math to be present.
           self::addLog(Logger::ERROR, 'UnsatisfiedDependencyException : '.$e->getMessage());

        }catch (Exception $e) {
           self::addLog(Logger::ERROR, $e->getMessage());

        }
        return '';

    }

    private function getUserProfileSaveData($campaignKey,$bucketInfo,$customerHash)
    {
        return[
            'userId'=>$customerHash,
            $campaignKey=>['variationName'=>$bucketInfo['name']],
        ];

    }

    /**
     *
     * function to addlog to the default/ custom logger
     *
     * @param  $level
     * @param  $message
     * @param  array   $params
     * @param  string  $classname
     * @return  bool
     */
    static function addLog($level,$message,$params=[],$classname='')
    {
        try{
            if (empty($classname)) {
                $classname=self::name();
            }
            $message=Common::makelogMesaage($message, $params, $classname);
            self::$_logger->addLog($message, $level);
        }catch (Exception $e){
            error_log($e->getMessage());
        }
        return TRUE;

    }

}