<?php
namespace vwo;
use Monolog\Logger;
use vwo\Utils\Constants;
use vwo\Utils\murmur as murmur;

Class BucketService{

    private static $SEED = 1;
    private static $MAX_VALUE=0x100000000;
    private static $MAX_RANGE=10000;
    private static $MAX_CAMPAIGN_TRAFFIC=100;
    private static $CLASSNAME='vwo\BucketService';

    public static function getmurmurHash_Int($str){
        return $hash=Murmur::hash3_int($str, self::$SEED);
    }

    public static function getBucketVal($str,$maxPercent){
        $code=self::getmurmurHash_Int($str);

        $range = $code / self::$MAX_VALUE;

        if ($range < 0) {

            $range += (10000/(self::$MAX_RANGE));
        }
        return $range;
    }

    public static function isUserPartofCampaign($bucketVal,$percentTraffic){

        if( floor($bucketVal * self::$MAX_CAMPAIGN_TRAFFIC ) > $percentTraffic){
            return FALSE;
        }
        return TRUE;
    }
    private static function getRangeForVariations($range,$multiplier){

        return  intval(floor(($range*self::$MAX_RANGE)+1)*$multiplier);
    }

    public static function getLimit($weight){
        return floor($weight * self::$MAX_RANGE/100);
    }

    public static function getBucketVariationId($campaign,$variationName){
        foreach ( $campaign['variations'] as $variation ) {
            if($variation['name']==$variationName){
                return ['name'=>$variation['name'],'id'=>$variation['id']];
            }
        }
        return null;

    }
    public static function getMultiplier($traffic){
        return self::$MAX_CAMPAIGN_TRAFFIC/($traffic);

    }

    public static function getBucket($userid,$campaign){

        // if bucketing to be done
        $bucketVal= self::getBucketVal($userid,self::$MAX_CAMPAIGN_TRAFFIC);
        if(!self::isUserPartofCampaign($bucketVal,$campaign['percentTraffic'])){
            VWO::addLog(Logger::ERROR,Constants::DEBUG_MESSAGES['USER_NOT_PART_OF_CAMPAIGN'],['{userId}'=>$userid,'{method}'=>'getBucket','{campaignTestKey}'=>$campaign['key']],self::$CLASSNAME);
            return null;
        }
        $multiplier=self::getMultiplier($campaign['percentTraffic']);
        $rangeForVariations=self::getRangeForVariations($bucketVal,$multiplier);
        foreach ( $campaign['variations'] as $variation ) {
            if($variation['max_range']>=$rangeForVariations && $rangeForVariations>=$variation['min_range']){
                VWO::addLog(Logger::ERROR,Constants::INFO_MESSAGES['GOT_VARIATION_FOR_USER'],['{variationName}'=>$variation['name'],'{userId}'=>$userid,'{method}'=>'getBucket','{campaignTestKey}'=>$campaign['key']],self::$CLASSNAME);
                return $variatInfo=['name'=>$variation['name'],'id'=>$variation['id']];
            }
        }
        VWO::$_logger->addLog(Logger::INFO,Constants::INFO_MESSAGES['NO_VARIATION_ALLOCATED'],['{userId}'=>$userid,'{campaignTestKey}'=>$campaign['key']],self::$CLASSNAME);
        return null;
    }
}