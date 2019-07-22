<?php
namespace vwo\Utils;
/**
 *
 *
 */
interface UserProfileInterface {
    public function lookup($userId,$campaignName);
    public function save($campaignInfo);
}