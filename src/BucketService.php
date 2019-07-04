<?php
namespace src;
use src\Utils\murmur as murmur;

Class BucketService{

    private static $SEED = 1;
    private static $MAX_VALUE=0x100000000;
    private static $MAX_RANGE=10000;
    private static $MAX_CAMPAIGN_TRAFFIC=100;

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
    private static function getRangeForVariations($range){

        return  intval(floor($range * self::$MAX_RANGE));
    }

    public static function getLimit($weight){
        return floor($weight * self::$MAX_RANGE/100);
    }

    public static function getBucket($str,$campaign){
        // if bucketing to be done
        $bucketVal= self::getBucketVal($str,self::$MAX_CAMPAIGN_TRAFFIC);
        if(!self::isUserPartofCampaign($bucketVal,$campaign['percentTraffic'])){
            return null;

        }
         $rangeForVariations=self::getRangeForVariations($bucketVal);
        foreach ( $campaign['variations'] as $variation ) {
            if($variation['max_range']>=$rangeForVariations && $rangeForVariations>=$variation['min_range']){
                return $variatInfo=['name'=>$variation['name'],'id'=>$variation['id']];
            }
        }
        return null;
    }
}